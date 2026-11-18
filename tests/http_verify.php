<?php
declare(strict_types=1);

/**
 * Verifikasi HTTP SmartPartMan — TERPISAH DARI DATA PRODUKSI.
 *
 * Cara kerja:
 *  1. Menyalin `.env` ke `.env.httpbak`, lalu mengganti DB_NAME ke DB uji
 *     (DB_TEST_NAME). Semua permintaan HTTP selama pengujian memakai DB uji.
 *  2. Menjalankan migrasi pada DB uji + membuat akun HTTP khusus.
 *  3. Menguji rute, RBAC, CRUD transaksi, unggah bukti, opname, koreksi.
 *  4. SELALU memulihkan `.env` (try/finally + shutdown handler), termasuk bila
 *     pengujian gagal di tengah.
 *
 * PENTING: jalankan saat tidak ada pengguna lain memakai aplikasi (jeda hanya
 * beberapa detik). Jangan pernah mengarahkan berkas ini ke DB produksi.
 *
 * Pemakaian: php tests\http_verify.php [--keep-data]
 */

$base = dirname(__DIR__);
require $base . '/app/autoload.php';
require_once __DIR__ . '/gemini_stub.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\MigrationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk CLI.\n");
}

const HOST = 'http://localhost';
const APPBASE = '/smartpartman/smartpartman';

$envFile = $base . '/.env';
$backupFile = $base . '/.env.httpbak';
$keepData = in_array('--keep-data', $argv, true);

$lulus = 0;
$gagal = 0;
$failures = [];
$jar = null;

function ok(bool $cond, string $msg): void
{
    global $lulus, $gagal, $failures;
    if ($cond) {
        $lulus++;
        echo "  [OK] {$msg}\n";
    } else {
        $gagal++;
        $failures[] = $msg;
        echo "  [GAGAL] {$msg}\n";
    }
}

function eq(mixed $expected, mixed $actual, string $msg): void
{
    $cond = is_float($expected) || is_float($actual)
        ? abs((float) $expected - (float) $actual) < 0.005
        : $expected === $actual;
    ok($cond, $msg . ' (diharapkan ' . var_export($expected, true) . ', aktual ' . var_export($actual, true) . ')');
}

function jar_new(string $label): void
{
    global $jar;
    $jar = tempnam(sys_get_temp_dir(), 'spmjar_');
    echo "\n== Sesi: {$label} ==\n";
}

function req(string $method, string $path, ?array $post = null, bool $multipart = false): array
{
    global $jar;
    $ch = curl_init(HOST . APPBASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => (string) $jar,
        CURLOPT_COOKIEFILE     => (string) $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? ($post ?? []) : http_build_query($post ?? []));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL gagal: ' . curl_error($ch));
    }
    $info = curl_getinfo($ch);
    curl_close($ch);
    $hs = (int) ($info['header_size'] ?? 0);
    $head = substr((string) $raw, 0, $hs);
    $body = substr((string) $raw, $hs);
    $location = null;
    foreach (preg_split("/\r\n/", trim($head)) ?: [] as $line) {
        if (stripos($line, 'location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }

    return [
        'status'   => (int) ($info['http_code'] ?? 0),
        'body'     => $body,
        'head'     => $head,
        'location' => $location,
        'ctype'    => (string) ($info['content_type'] ?? ''),
        'bytes'    => strlen($body),
    ];
}

function get(string $path): array
{
    return req('GET', $path);
}

function get_abs(string $path): array
{
    global $jar;
    $ch = curl_init(HOST . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => (string) $jar,
        CURLOPT_COOKIEFILE     => (string) $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $hs = (int) ($info['header_size'] ?? 0);

    return [
        'status' => (int) ($info['http_code'] ?? 0),
        'body'   => substr((string) $raw, $hs),
        'bytes'  => strlen(substr((string) $raw, $hs)),
        'ctype'  => (string) ($info['content_type'] ?? ''),
        'head'   => substr((string) $raw, 0, $hs),
    ];
}

function post(string $path, array $data): array
{
    return req('POST', $path, $data);
}

function post_multipart(string $path, array $data): array
{
    return req('POST', $path, $data, true);
}

function follow(?string $location): array
{
    return $location === null
        ? ['status' => 0, 'body' => '', 'bytes' => 0, 'ctype' => '', 'head' => '']
        : get_abs($location);
}

function token(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/** Ambil token CSRF dari konfigurasi JSON Floating AI Assistant. */
function widget_token(string $html): string
{
    if (preg_match('#<script type="application/json" id="spAiConfig">(.*?)</script>#s', $html, $m) !== 1) {
        return '';
    }
    $data = json_decode($m[1], true);
    return is_array($data) ? (string) ($data['token'] ?? '') : '';
}

function login(string $username, string $password): bool
{
    $page = get('/login');
    $res = post('/login', ['_token' => token($page['body']), 'username' => $username, 'password' => $password]);
    return $res['status'] === 302 && get('/transaksi/masuk')['status'] === 200;
}

function scalar(string $sql, array $params = []): mixed
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function rows(string $sql, array $params = []): array
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function stock(int $id): float
{
    return (float) scalar('SELECT stock FROM spareparts WHERE id = ?', [$id]);
}

function extract_id(?string $location, string $segment): int
{
    if ($location !== null && preg_match('#' . preg_quote($segment, '#') . '/(\d+)#', $location, $m) === 1) {
        return (int) $m[1];
    }
    return 0;
}

function random_password(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// ------------------------------------------------------------------
// 0. Persiapan & pengamanan
// ------------------------------------------------------------------

if (!is_file($envFile)) {
    fwrite(STDERR, "Berkas .env tidak ditemukan.\n");
    exit(1);
}

// Sisa proses sebelumnya yang terhenti: pulihkan dulu.
if (is_file($backupFile)) {
    if (!copy($backupFile, $envFile)) {
        fwrite(STDERR, "Gagal memulihkan .env dari backup.\n");
        exit(1);
    }
    unlink($backupFile);
    echo "[INFO] .env dipulihkan dari backup pengujian sebelumnya.\n";
}

$original = file_get_contents($envFile);
if ($original === false) {
    fwrite(STDERR, "Tidak dapat membaca .env.\n");
    exit(1);
}

Env::load($envFile);
$config = require $base . '/config/config.php';
$prodName = (string) $config['db']['name'];
$testName = (string) $config['db']['test_name'];

if ($testName === '' || !str_ends_with($testName, '_test')) {
    fwrite(STDERR, "Nama DB uji tidak aman ({$testName}). Harus berakhiran '_test'.\n");
    exit(1);
}
if ($testName === $prodName) {
    fwrite(STDERR, "DB uji sama dengan DB produksi — dibatalkan.\n");
    exit(1);
}

$swapped = preg_replace('/^DB_NAME=.*$/m', 'DB_NAME=' . $testName, $original, 1, $count);
if ($swapped === null || $count !== 1) {
    fwrite(STDERR, "Baris DB_NAME tidak ditemukan di .env.\n");
    exit(1);
}

if (file_put_contents($backupFile, $original, LOCK_EX) === false
    || file_put_contents($envFile, $swapped, LOCK_EX) === false) {
    fwrite(STDERR, "Gagal menukar .env ke DB uji.\n");
    exit(1);
}

$restored = false;
$restore = static function () use (&$restored, $envFile, $backupFile, $original): void {
    if ($restored) {
        return;
    }
    $restored = true;
    if (is_file($backupFile)) {
        file_put_contents($envFile, $original, LOCK_EX);
        unlink($backupFile);
    }
};
register_shutdown_function($restore);

echo "SmartPartMan — Verifikasi HTTP (DB UJI)\n";
echo "DB uji: {$testName} | DB produksi TIDAK disentuh\n";
echo "Penanda: HTTPV-" . strtoupper(bin2hex(random_bytes(3))) . "\n";
flush();

// Muat ulang konfigurasi setelah .env ditukar.
Env::load($envFile);
$config = require $base . '/config/config.php';
$db = $config['db'];
if ((string) $db['name'] !== $testName) {
    $restore();
    fwrite(STDERR, "Gagal mengarahkan aplikasi ke DB uji.\n");
    exit(1);
}

// Pastikan DB uji ada.
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int) $db['port'], $db['charset'] ?? 'utf8mb4'),
    (string) $db['user'],
    (string) $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec(
    'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $testName) . '`'
    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);

$migrator = new MigrationService($db, $base . '/database/migrations');
$migrator->run();

Database::configure($db);
$pdo = Database::pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $testName) {
    $restore();
    fwrite(STDERR, "Koneksi tidak berada di DB uji.\n");
    exit(1);
}

// DB uji bersifat sekali pakai: bersihkan sesi opname OPEN yang tertinggal
// agar pengujian opname dapat dimulai (tidak menyentuh DB produksi).
$pdo->exec("DELETE FROM stock_opname_items WHERE opname_id IN (SELECT id FROM stock_opnames WHERE status = 'OPEN')");
$pdo->exec("DELETE FROM stock_opnames WHERE status = 'OPEN'");

// Akun HTTP khusus (password acak, tidak pernah dicetak).
$roleIds = [];
foreach ($pdo->query('SELECT id, code FROM roles') as $row) {
    $roleIds[(string) $row['code']] = (int) $row['id'];
}
$accounts = [];
foreach ([
    'http_partman' => 'partman',
    'http_kepala'  => 'kepala_bengkel',
    'http_admin'   => 'admin',
] as $username => $roleCode) {
    if (!isset($roleIds[$roleCode])) {
        throw new RuntimeException('Role tidak tersedia: ' . $roleCode);
    }
    $password = random_password();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $st = $pdo->prepare(
        'INSERT INTO users (username, full_name, password_hash, role_id, is_active)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_id = VALUES(role_id),
                                 is_active = 1, failed_attempts = 0, locked_until = NULL'
    );
    $st->execute([$username, 'Akun HTTP ' . $roleCode, $hash, $roleIds[$roleCode]]);
    $accounts[$username] = $password;
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true);
$pngPath = tempnam(sys_get_temp_dir(), 'spmhttpv_') . '.png';
file_put_contents($pngPath, $png);

$uploadedPath = null;

// Fase 8 — snapshot pengaturan chatbot agar selalu dipulihkan (nonaktif, tanpa key).
$chatbotSnapshot = $pdo->query('SELECT * FROM chatbot_settings WHERE id = 1')->fetch() ?: null;
$chatProviderSnapshot = [];
try {
    $chatProviderSnapshot = $pdo->query('SELECT * FROM chatbot_providers')->fetchAll() ?: [];
} catch (Throwable) {
    $chatProviderSnapshot = [];
}
$chatUsageMaxId = (int) scalar('SELECT COALESCE(MAX(id), 0) FROM chatbot_usage_logs');
$geminiStub = null;

// Fase 9 — snapshot pengaturan, metadata backup, dan berkas agar DB uji bersih.
$appSettingsSnapshot = $pdo->query('SELECT * FROM app_settings')->fetchAll() ?: [];
$backupMaxId = (int) scalar('SELECT COALESCE(MAX(id), 0) FROM backups');
$backupFilesBefore = [];
foreach (glob($base . '/storage/backups/*') ?: [] as $backupFileItem) {
    $backupFilesBefore[basename($backupFileItem)] = true;
}
$logoFilesBefore = [];
$logoRoot = $base . '/storage/documents/logos';
if (is_dir($logoRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($logoRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $logoFileItem) {
        if ($logoFileItem->isFile()) {
            $logoFilesBefore[$logoFileItem->getPathname()] = true;
        }
    }
}

try {
    // --------------------------------------------------------------
    // 1. Tanpa login
    // --------------------------------------------------------------
    jar_new('tanpa login');
    foreach ([
        '/transaksi/masuk', '/transaksi/keluar', '/transaksi/opname', '/transaksi/riwayat', '/transaksi/koreksi',
        '/dashboard', '/laporan', '/laporan/stok', '/laporan/stok/cetak', '/laporan/stok/pdf',
        '/laporan/stok/excel', '/pengaturan', '/pengaturan/chatbot', '/chatbot', '/reorder', '/pembelian',
        '/pengaturan/backup', '/pengaturan/backup/1/unduh', '/audit-log', '/audit-log/export',
    ] as $path) {
        $r = get($path);
        eq(302, $r['status'], "Tanpa login GET {$path} = 302");
        ok($r['location'] !== null && str_contains($r['location'], '/login'), "Tanpa login GET {$path} diarahkan ke /login");
    }

    // --------------------------------------------------------------
    // 2. Partman
    // --------------------------------------------------------------
    jar_new('Partman');
    $loginPage = get('/login');
    eq(200, $loginPage['status'], 'Halaman login = 200');
    $loginRes = post('/login', [
        '_token'   => token($loginPage['body']),
        'username' => 'http_partman',
        'password' => $accounts['http_partman'],
    ]);
    eq(302, $loginRes['status'], 'Login Partman = 302');

    foreach ([
        '/transaksi/masuk'         => 'Barang Masuk',
        '/transaksi/masuk/tambah'  => 'Catat',
        '/transaksi/keluar'        => 'Barang Keluar',
        '/transaksi/keluar/tambah' => 'Catat',
        '/transaksi/opname'        => 'Stock Opname',
        '/transaksi/opname/mulai'  => 'Stock Opname',
        '/transaksi/riwayat'       => 'Riwayat',
        '/transaksi/koreksi'       => 'Koreksi',
    ] as $path => $needle) {
        $r = get($path);
        eq(200, $r['status'], "Partman GET {$path} = 200");
        ok(str_contains($r['body'], $needle), "Partman GET {$path} memuat '{$needle}'");
    }

    // Fixture master via HTTP.
    $catForm = get('/kategori/tambah');
    post('/kategori', [
        '_token' => token($catForm['body']),
        'code'   => "HTTPV-KAT-{$suffix}",
        'name'   => "Kategori HTTP Uji {$suffix}",
    ]);
    $categoryId = (int) scalar('SELECT id FROM categories WHERE code = ?', ["HTTPV-KAT-{$suffix}"]);
    ok($categoryId > 0, 'Kategori dibuat lewat HTTP');

    $supForm = get('/supplier/tambah');
    post('/supplier', [
        '_token' => token($supForm['body']),
        'code'   => "HTTPV-SUP-{$suffix}",
        'name'   => "Supplier HTTP Uji {$suffix}",
    ]);
    $supplierId = (int) scalar('SELECT id FROM suppliers WHERE code = ?', ["HTTPV-SUP-{$suffix}"]);
    ok($supplierId > 0, 'Supplier dibuat lewat HTTP');

    $spForm = get('/sparepart/tambah');
    post('/sparepart', [
        '_token'             => token($spForm['body']),
        'code'               => "HTTPV-SP-{$suffix}",
        'name'               => "Sparepart HTTP Uji {$suffix}",
        'category_id'        => $categoryId,
        'supplier_id'        => $supplierId,
        'unit'               => 'pcs',
        'purchase_price'     => '15000',
        'selling_price'      => '20000',
        'min_stock'          => '5',
        'initial_stock'      => '10',
        'initial_stock_date' => '2026-10-18',
        'initial_stock_note' => 'Stok awal uji HTTP terpisah',
    ]);
    $sparepartId = (int) scalar('SELECT id FROM spareparts WHERE code = ?', ["HTTPV-SP-{$suffix}"]);
    ok($sparepartId > 0, 'Sparepart dibuat lewat HTTP');
    eq(10.0, stock($sparepartId), 'Stok awal 10 tercatat lewat ledger');

    // Barang masuk + bukti.
    $masukForm = get('/transaksi/masuk/tambah');
    $r = post_multipart('/transaksi/masuk', [
        '_token'            => token($masukForm['body']),
        'receipt_date'      => '2026-10-18',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => (string) $supplierId,
        'sparepart_id'      => (string) $sparepartId,
        'quantity'          => '5',
        'unit_price'        => '20000',
        'invoice_number'    => "INV-HTTPV-{$suffix}",
        'without_po_reason' => 'Penerimaan uji HTTP tanpa PO',
        'attachment'        => new CURLFile($pngPath, 'image/png', 'Bukti HTTPV.png'),
    ]);
    eq(302, $r['status'], 'POST /transaksi/masuk = 302');
    $receiptId = extract_id($r['location'], '/transaksi/masuk');
    ok($receiptId > 0, 'Redirect ke detail penerimaan');
    $receipt = rows('SELECT * FROM goods_receipts WHERE id = ?', [$receiptId])[0] ?? null;
    ok($receipt !== null, 'Dokumen penerimaan tersimpan');
    ok(
        $receipt !== null && preg_match('/^GR-\d{6}-\d{4}$/', (string) $receipt['receipt_number']) === 1,
        'Nomor penerimaan berformat GR-YYYYMM-####'
    );
    eq(15.0, stock($sparepartId), 'Stok 10 → 15 setelah penerimaan');
    $uploadedPath = $receipt !== null && $receipt['attachment_path'] !== null
        ? $base . '/storage/private_uploads/' . $receipt['attachment_path']
        : null;
    ok($uploadedPath !== null && is_file($uploadedPath), 'Bukti tersimpan di luar web root');

    $bukti = get('/transaksi/masuk/' . $receiptId . '/bukti');
    eq(200, $bukti['status'], 'Unduh bukti ber-otorisasi = 200');
    eq(strlen($png), $bukti['bytes'], 'Isi bukti identik dengan yang diunggah');
    ok(str_contains(strtolower($bukti['ctype'] . $bukti['head']), 'image/png'), 'Content-Type bukti = image/png');

    if ($receipt !== null && $receipt['attachment_path'] !== null) {
        $direct = req('GET', '/storage/private_uploads/' . $receipt['attachment_path']);
        ok(in_array($direct['status'], [403, 404], true), 'Bukti tidak dapat diakses langsung (' . $direct['status'] . ')');
    }

    // Barang keluar.
    $keluarForm = get('/transaksi/keluar/tambah');
    $r = post('/transaksi/keluar', [
        '_token'       => token($keluarForm['body']),
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => (string) $sparepartId,
        'quantity'     => '4',
        'plate_number' => 'b 4321 qa',
    ]);
    eq(302, $r['status'], 'POST /transaksi/keluar = 302');
    $issueId = extract_id($r['location'], '/transaksi/keluar');
    eq(11.0, stock($sparepartId), 'Stok 15 → 11 setelah pengeluaran');
    $issue = rows('SELECT * FROM goods_issues WHERE id = ?', [$issueId])[0] ?? null;
    ok($issue !== null && preg_match('/^GI-\d{6}-\d{4}$/', (string) $issue['issue_number']) === 1, 'Nomor pengeluaran berformat GI-YYYYMM-####');
    eq('B 4321 QA', (string) ($issue['plate_number'] ?? ''), 'Nomor polisi diseragamkan huruf besar');

    // Fixture PO (modul pembuatan PO = Fase 6; di sini cukup dibaca/cetak).
    $partmanId = (int) scalar('SELECT id FROM users WHERE username = ?', ['http_partman']);
    $poNumber = "HTTPV-PO-{$suffix}";
    $pdo->prepare(
        'INSERT INTO purchase_orders
            (po_number, supplier_id, status, payment_status, total_amount, note, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$poNumber, $supplierId, 'MENUNGGU_PERSETUJUAN', 'BELUM_DIBAYAR', 30000, 'PO uji HTTP', $partmanId, '2026-10-18 09:00:00']);
    $poId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$poId, $sparepartId, 2, 15000, 0]);
    ok($poId > 0, 'PO uji HTTP tersimpan untuk uji cetak');

    // --------------------------------------------------------------
    // 2b. Fase 5 — dashboard, laporan, cetak, PDF, Excel
    // --------------------------------------------------------------
    $dash = get('/dashboard');
    eq(200, $dash['status'], 'Partman GET /dashboard = 200');
    ok(str_contains($dash['body'], 'Total jenis sparepart'), 'Dashboard Partman memuat ringkasan stok');
    ok(str_contains($dash['body'], 'Rekomendasi reorder'), 'Dashboard Partman memuat bagian reorder');
    ok(str_contains($dash['body'], 'Rekomendasi Prediksi Persediaan'), 'Dashboard Partman memuat bagian Rekomendasi Prediksi Persediaan');
    ok(
        str_contains($dash['body'], 'Pemesanan sparepart tetap dilakukan secara manual'),
        'Dashboard Partman memuat disclaimer prediksi (rekomendasi, bukan pemesanan otomatis)'
    );

    $laporan = get('/laporan');
    eq(200, $laporan['status'], 'Partman GET /laporan = 200');
    ok(str_contains($laporan['body'], 'Laporan'), 'Halaman daftar laporan tampil');

    $search = urlencode("HTTPV-SP-{$suffix}");
    $report = get('/laporan/stok?search=' . $search);
    eq(200, $report['status'], 'Partman GET /laporan/stok = 200');
    ok(str_contains($report['body'], "HTTPV-SP-{$suffix}"), 'Laporan stok memuat sparepart uji');

    $reportPrint = get('/laporan/stok/cetak?search=' . $search);
    eq(200, $reportPrint['status'], 'Partman GET /laporan/stok/cetak = 200');
    ok(str_contains($reportPrint['body'], 'Laporan Stok Keseluruhan'), 'Halaman cetak memuat judul laporan');
    ok(str_contains($reportPrint['body'], "HTTPV-SP-{$suffix}"), 'Halaman cetak memuat data uji');

    $reportPdf = get('/laporan/stok/pdf?search=' . $search);
    eq(200, $reportPdf['status'], 'Partman GET /laporan/stok/pdf = 200');
    ok(str_contains(strtolower($reportPdf['ctype']), 'application/pdf'), 'PDF laporan ber-Content-Type application/pdf');
    ok(str_starts_with($reportPdf['body'], '%PDF-1.4'), 'PDF laporan memiliki header PDF 1.4');
    ok(str_contains($reportPdf['body'], '%%EOF'), 'PDF laporan lengkap sampai EOF');

    $reportExcel = get('/laporan/stok/excel?search=' . $search);
    eq(200, $reportExcel['status'], 'Partman GET /laporan/stok/excel = 200');
    ok(str_starts_with($reportExcel['body'], "PK\x03\x04"), 'Excel laporan berformat ZIP/XLSX');

    $poPrint = get('/laporan/po/' . $poId . '/cetak');
    eq(200, $poPrint['status'], 'Partman GET /laporan/po/{id}/cetak = 200');
    ok(str_contains($poPrint['body'], 'PURCHASE ORDER'), 'Dokumen PO memuat judul');
    ok(str_contains($poPrint['body'], $poNumber), 'Dokumen PO memuat nomor PO');

    $poPdf = get('/laporan/po/' . $poId . '/pdf');
    eq(200, $poPdf['status'], 'Partman GET /laporan/po/{id}/pdf = 200');
    ok(str_starts_with($poPdf['body'], '%PDF-1.4'), 'PDF PO memiliki header PDF 1.4');

    if ($receiptId > 0) {
        $grPrint = get('/transaksi/masuk/' . $receiptId . '/cetak');
        eq(200, $grPrint['status'], 'Partman GET /transaksi/masuk/{id}/cetak = 200');
        ok(str_contains($grPrint['body'], 'BUKTI BARANG MASUK'), 'Bukti barang masuk memuat judul');
        ok(str_contains($grPrint['body'], (string) $receipt['receipt_number']), 'Bukti barang masuk memuat nomor dokumen');
    }
    if ($issueId > 0) {
        $giPrint = get('/transaksi/keluar/' . $issueId . '/cetak');
        eq(200, $giPrint['status'], 'Partman GET /transaksi/keluar/{id}/cetak = 200');
        ok(str_contains($giPrint['body'], 'BUKTI BARANG KELUAR'), 'Bukti barang keluar memuat judul');
        ok(str_contains($giPrint['body'], (string) $issue['issue_number']), 'Bukti barang keluar memuat nomor dokumen');
    }

    eq(403, get('/pengaturan')['status'], 'Partman GET /pengaturan = 403');
    eq(403, post('/pengaturan', ['_token' => 'x'])['status'], 'Partman POST /pengaturan = 403');

    // Melebihi stok → ditolak.
    $keluarForm2 = get('/transaksi/keluar/tambah');
    $reject = post('/transaksi/keluar', [
        '_token'       => token($keluarForm2['body']),
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'penjualan',
        'sparepart_id' => (string) $sparepartId,
        'quantity'     => '999',
    ]);
    eq(302, $reject['status'], 'Pengeluaran melebihi stok ditolak');
    ok(str_contains(follow($reject['location'])['body'], 'Stok tidak mencukupi'), 'Pesan "Stok tidak mencukupi" tampil');
    eq(11.0, stock($sparepartId), 'Stok tetap 11 setelah penolakan');
    eq(
        1,
        (int) scalar('SELECT COUNT(*) FROM goods_issue_items WHERE sparepart_id = ?', [$sparepartId]),
        'Tidak ada item pengeluaran tersimpan dari percobaan yang gagal'
    );

    // CSRF.
    eq(403, post('/transaksi/keluar', [
        '_token' => 'palsu', 'issue_date' => '2026-10-18', 'usage_type' => 'service',
        'sparepart_id' => (string) $sparepartId, 'quantity' => '1',
    ])['status'], 'POST tanpa token CSRF sah = 403');

    // Riwayat.
    $riwayat = get('/transaksi/riwayat');
    eq(200, $riwayat['status'], 'Riwayat = 200');
    ok(str_contains($riwayat['body'], (string) $receipt['receipt_number']), 'Riwayat memuat nomor penerimaan');
    ok(str_contains($riwayat['body'], (string) $issue['issue_number']), 'Riwayat memuat nomor pengeluaran');

    // Opname.
    $opnameId = 0;
    $mulaiForm = get('/transaksi/opname/mulai');
    $r = post('/transaksi/opname', [
        '_token'      => token($mulaiForm['body']),
        'opname_date' => '2026-10-18',
        'note'        => 'Opname uji HTTP terpisah',
    ]);
    eq(302, $r['status'], 'Mulai opname = 302');
    $opnameId = extract_id($r['location'], '/transaksi/opname');
    ok($opnameId > 0, 'Sesi opname dibuat');
    eq(11.0, stock($sparepartId), 'Stok tidak berubah saat opname dimulai');

    $opnamePage = get('/transaksi/opname/' . $opnameId);
    eq(200, $opnamePage['status'], 'Halaman opname = 200');
    $r = post('/transaksi/opname/' . $opnameId . '/simpan', [
        '_token'                     => token($opnamePage['body']),
        'physical[' . $sparepartId . ']' => '9',
        'reason[' . $sparepartId . ']'   => 'Selisih uji HTTP terpisah',
    ]);
    eq(302, $r['status'], 'Simpan hasil hitung = 302');
    eq(11.0, stock($sparepartId), 'Stok MASIH 11 sebelum konfirmasi');

    $confirmPage = get('/transaksi/opname/' . $opnameId);
    post('/transaksi/opname/' . $opnameId . '/konfirmasi', ['_token' => token($confirmPage['body'])]);
    eq(9.0, stock($sparepartId), 'Stok menjadi 9 setelah konfirmasi');

    $adj = rows(
        "SELECT * FROM stock_movements WHERE sparepart_id = ? AND movement_type = 'PENYESUAIAN_OPNAME' ORDER BY id DESC LIMIT 1",
        [$sparepartId]
    )[0] ?? null;
    ok($adj !== null, 'Pergerakan PENYESUAIAN_OPNAME tercatat');
    eq(-2.0, (float) ($adj['quantity'] ?? 0), 'Penyesuaian opname = -2');

    // Koreksi.
    $masukMovement = rows(
        "SELECT * FROM stock_movements WHERE sparepart_id = ? AND movement_type = 'MASUK' ORDER BY id LIMIT 1",
        [$sparepartId]
    )[0] ?? null;
    ok($masukMovement !== null, 'Pergerakan MASUK tersedia');
    if ($masukMovement !== null) {
        $movementId = (int) $masukMovement['id'];
        $koreksiForm = get('/transaksi/koreksi/' . $movementId);
        eq(200, $koreksiForm['status'], 'Form koreksi = 200');
        $r = post('/transaksi/koreksi/' . $movementId, [
            '_token'   => token($koreksiForm['body']),
            'quantity' => '5',
            'reason'   => 'Pembatalan uji HTTP terpisah',
        ]);
        eq(302, $r['status'], 'POST koreksi = 302');
        eq(4.0, stock($sparepartId), 'Stok 9 → 4 setelah koreksi MASUK 5');

        $asli = rows('SELECT quantity, stock_after FROM stock_movements WHERE id = ?', [$movementId])[0] ?? null;
        eq(5.0, (float) ($asli['quantity'] ?? 0), 'Transaksi asal tidak berubah');
        $koreksi = rows(
            "SELECT * FROM stock_movements WHERE reversal_of_id = ? AND movement_type = 'KOREKSI'",
            [$movementId]
        )[0] ?? null;
        ok($koreksi !== null, 'Baris KOREKSI tertaut ke transaksi asal');

        $koreksiForm2 = get('/transaksi/koreksi/' . $movementId);
        post('/transaksi/koreksi/' . $movementId, [
            '_token' => token($koreksiForm2['body']), 'quantity' => '1', 'reason' => 'Koreksi ulang',
        ]);
        eq(4.0, stock($sparepartId), 'Koreksi ulang setelah dibalik penuh ditolak, stok tetap');
    }

    // Audit log.
    $activities = array_column(rows(
        "SELECT DISTINCT activity FROM audit_logs
          WHERE activity IN ('GOODS_RECEIPT_CREATE','GOODS_ISSUE_CREATE','STOCK_OPNAME_START','STOCK_OPNAME_INPUT',
                             'STOCK_OPNAME_CONFIRM','TRANSACTION_CORRECTION')"
    ), 'activity');
    foreach ([
        'GOODS_RECEIPT_CREATE', 'GOODS_ISSUE_CREATE', 'STOCK_OPNAME_START',
        'STOCK_OPNAME_INPUT', 'STOCK_OPNAME_CONFIRM', 'TRANSACTION_CORRECTION',
    ] as $need) {
        ok(in_array($need, $activities, true), "Audit Log memuat {$need}");
    }

    // --------------------------------------------------------------
    // 3. Kepala Bengkel
    // --------------------------------------------------------------
    jar_new('Kepala Bengkel');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel berhasil');
    foreach ([
        '/transaksi/masuk', '/transaksi/keluar', '/transaksi/opname', '/transaksi/riwayat',
        '/transaksi/masuk/' . $receiptId, '/transaksi/keluar/' . $issueId,
    ] as $path) {
        eq(200, get($path)['status'], "Kepala Bengkel GET {$path} = 200");
    }
    $detailKb = get('/transaksi/masuk/' . $receiptId);
    ok(!str_contains($detailKb['body'], 'Catat Penerimaan'), 'Tombol pengubah tidak dirender untuk Kepala Bengkel');
    foreach (['/transaksi/masuk/tambah', '/transaksi/keluar/tambah', '/transaksi/opname/mulai', '/transaksi/koreksi'] as $path) {
        eq(403, get($path)['status'], "Kepala Bengkel GET {$path} = 403");
    }
    foreach ([
        '/transaksi/masuk', '/transaksi/keluar', '/transaksi/opname',
        '/transaksi/opname/' . $opnameId . '/simpan', '/transaksi/opname/' . $opnameId . '/konfirmasi',
        '/transaksi/koreksi/1',
    ] as $path) {
        eq(403, post($path, ['_token' => 'x', 'quantity' => '1'])['status'], "Kepala Bengkel POST {$path} = 403");
    }

    // Fase 5 — dashboard & laporan untuk Kepala Bengkel.
    $dashKb = get('/dashboard');
    eq(200, $dashKb['status'], 'Kepala Bengkel GET /dashboard = 200');
    ok(str_contains($dashKb['body'], 'Nilai persediaan'), 'Dashboard Kepala Bengkel memuat nilai persediaan');
    ok(
        str_contains($dashKb['body'], 'Prediksi stok (AI)') || str_contains($dashKb['body'], 'Prediksi AI'),
        'Dashboard Kepala Bengkel memuat bagian Prediksi AI (Fase 7)'
    );
    ok(
        str_contains($dashKb['body'], 'Rekomendasi Prediksi Persediaan'),
        'Dashboard Kepala Bengkel memuat bagian Rekomendasi Prediksi Persediaan'
    );
    foreach (['/laporan', '/laporan/stok', '/laporan/ai'] as $path) {
        eq(200, get($path)['status'], "Kepala Bengkel GET {$path} = 200");
    }
    $kbPdf = get('/laporan/stok/pdf?search=' . $search);
    eq(200, $kbPdf['status'], 'Kepala Bengkel GET /laporan/stok/pdf = 200');
    ok(str_starts_with($kbPdf['body'], '%PDF-1.4'), 'PDF laporan untuk Kepala Bengkel valid');
    eq(403, get('/pengaturan')['status'], 'Kepala Bengkel GET /pengaturan = 403');
    eq(403, post('/pengaturan', ['_token' => 'x'])['status'], 'Kepala Bengkel POST /pengaturan = 403');

    // --------------------------------------------------------------
    // 4. Admin
    // --------------------------------------------------------------
    jar_new('Admin');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin berhasil');
    foreach (['/transaksi/masuk', '/transaksi/keluar', '/transaksi/opname', '/transaksi/riwayat'] as $path) {
        eq(200, get($path)['status'], "Admin GET {$path} = 200");
    }
    foreach (['/transaksi/masuk/tambah', '/transaksi/keluar/tambah', '/transaksi/opname/mulai', '/transaksi/koreksi'] as $path) {
        eq(403, get($path)['status'], "Admin GET {$path} = 403");
    }
    foreach (['/transaksi/masuk', '/transaksi/keluar', '/transaksi/opname'] as $path) {
        eq(403, post($path, ['_token' => 'x', 'quantity' => '1'])['status'], "Admin POST {$path} = 403");
    }

    // Fase 5 — dashboard, laporan, dan pengaturan (khusus Admin).
    $dashAd = get('/dashboard');
    eq(200, $dashAd['status'], 'Admin GET /dashboard = 200');
    ok(str_contains($dashAd['body'], 'Total pengguna'), 'Dashboard Admin memuat total pengguna');
    ok(str_contains($dashAd['body'], 'Status sistem'), 'Dashboard Admin memuat status sistem');
    ok(str_contains($dashAd['body'], 'Buka Chatbot'), 'Dashboard Admin memuat tautan chatbot aktif');
    ok(
        str_contains($dashAd['body'], 'Rekomendasi Prediksi Persediaan'),
        'Dashboard Admin memuat bagian Rekomendasi Prediksi Persediaan'
    );
    foreach (['/laporan', '/laporan/stok', '/laporan/pembelian', '/laporan/ai'] as $path) {
        eq(200, get($path)['status'], "Admin GET {$path} = 200");
    }

    $setForm = get('/pengaturan');
    eq(200, $setForm['status'], 'Admin GET /pengaturan = 200');
    ok(str_contains($setForm['body'], 'Pengaturan Aplikasi'), 'Halaman pengaturan tampil');
    ok(str_contains($setForm['body'], 'name="workshop_name"'), 'Form memuat field nama bengkel');

    // Simpan nilai lama untuk dipulihkan setelah pengujian.
    $numericDefaults = ['fast_moving_threshold' => '10', 'slow_moving_threshold' => '2', 'moving_period_days' => '30'];
    $settingKeys = [
        'workshop_name', 'workshop_address', 'workshop_phone', 'workshop_email', 'workshop_note',
        'fast_moving_threshold', 'slow_moving_threshold', 'moving_period_days',
    ];
    $oldSettings = [];
    foreach ($settingKeys as $key) {
        $value = scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]);
        $value = $value === null ? '' : (string) $value;
        if ($value === '' && isset($numericDefaults[$key])) {
            $value = $numericDefaults[$key];
        }
        if ($key === 'workshop_name' && $value === '') {
            $value = 'SmartPartMan';
        }
        $oldSettings[$key] = $value;
    }

    $newSettings = [
        'workshop_name'         => 'Bengkel HTTP ' . $suffix,
        'workshop_address'      => 'Jl. Uji HTTP No. 1',
        'workshop_phone'        => '021-0000000',
        'workshop_email'        => 'bengkel-http@example.test',
        'workshop_note'         => 'Diubah oleh verifikasi HTTP.',
        'fast_moving_threshold' => '11',
        'slow_moving_threshold' => '3',
        'moving_period_days'    => '45',
    ];
    $saved = post('/pengaturan', ['_token' => token($setForm['body'])] + $newSettings);
    eq(302, $saved['status'], 'Admin POST /pengaturan nilai valid = 302');
    eq(
        $newSettings['workshop_name'],
        (string) scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', ['workshop_name']),
        'Nama bengkel tersimpan'
    );
    eq(
        $newSettings['moving_period_days'],
        (string) scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', ['moving_period_days']),
        'Periode analisis tersimpan'
    );
    ok(
        (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'SETTINGS_UPDATE'") > 0,
        'Perubahan pengaturan tercatat di Audit Log'
    );

    // Nilai tidak valid harus ditolak (slow >= fast).
    $badForm = get('/pengaturan');
    $bad = post('/pengaturan', [
        '_token'                => token($badForm['body']),
        'workshop_name'         => 'Bengkel HTTP ' . $suffix,
        'workshop_address'      => '',
        'workshop_phone'        => '',
        'workshop_email'        => '',
        'workshop_note'         => '',
        'fast_moving_threshold' => '3',
        'slow_moving_threshold' => '9',
        'moving_period_days'    => '45',
    ]);
    eq(302, $bad['status'], 'Admin POST /pengaturan tidak valid = 302 (kembali ke form)');
    eq(
        $newSettings['fast_moving_threshold'],
        (string) scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', ['fast_moving_threshold']),
        'Nilai fast moving lama tidak berubah saat input ditolak'
    );

    // Pulihkan pengaturan semula.
    $restoreForm = get('/pengaturan');
    post('/pengaturan', ['_token' => token($restoreForm['body'])] + $oldSettings);
    eq(
        $oldSettings['workshop_name'],
        (string) scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', ['workshop_name']),
        'Pengaturan semula dipulihkan setelah pengujian'
    );

    // --------------------------------------------------------------
    // 5. Fase 6 — Reorder & Purchase Order
    // --------------------------------------------------------------
    // Rute tulis Fase 6 dijaga peran: reorder/PO hanya Partman yang membuat,
    // Kepala Bengkel yang menyetujui/menolak, Admin yang memproses & membayar.
    jar_new('Fase 6 — tanpa login');
    foreach (['/reorder', '/pembelian'] as $path) {
        eq(302, get($path)['status'], "Tanpa login GET {$path} = 302");
    }

    jar_new('Fase 6 — Partman');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (Fase 6) berhasil');
    foreach (['/reorder', '/reorder/tambah', '/pembelian', '/pembelian/tambah'] as $path) {
        eq(200, get($path)['status'], "Partman GET {$path} = 200");
    }

    // Saran jumlah dari sistem (JSON) untuk sparepart di bawah minimum.
    $f6Saran = get('/reorder/saran?sparepart_id=' . $sparepartId);
    eq(200, $f6Saran['status'], 'Partman GET /reorder/saran = 200');
    ok(str_contains($f6Saran['body'], 'suggested_qty'), 'Saran jumlah mengembalikan JSON suggested_qty');

    // Partman membuat permintaan (jumlah beda dari saran wajib beralasan).
    $f6Stock = stock($sparepartId); // stok 4, minimum 5 → saran sistem 1
    $f6Form = get('/reorder/tambah');
    $f6R = post('/reorder', [
        '_token'        => token($f6Form['body']),
        'sparepart_id'  => (string) $sparepartId,
        'requested_qty' => '3',
        'supplier_id'   => (string) $supplierId,
        'change_reason' => 'Servis mendadak butuh cepat',
    ]);
    eq(302, $f6R['status'], 'Partman POST /reorder = 302');
    $f6Rq = extract_id($f6R['location'], '/reorder');
    ok($f6Rq > 0, 'Redirect ke detail permintaan reorder');
    ok(
        str_starts_with((string) scalar('SELECT request_number FROM reorder_requests WHERE id = ?', [$f6Rq]), 'RQ-'),
        'Nomor permintaan berformat RQ-YYYYMM-####'
    );
    eq(
        'MENUNGGU_PERSETUJUAN',
        (string) scalar('SELECT status FROM reorder_requests WHERE id = ?', [$f6Rq]),
        'Permintaan langsung berstatus Menunggu persetujuan'
    );
    eq($f6Stock, stock($sparepartId), 'Pembuatan reorder TIDAK mengubah stok');
    eq(200, get('/reorder/' . $f6Rq)['status'], 'Partman GET detail reorder = 200');

    // Permintaan kedua untuk menguji penolakan.
    $f6Form = get('/reorder/tambah');
    $f6R = post('/reorder', [
        '_token'        => token($f6Form['body']),
        'sparepart_id'  => (string) $sparepartId,
        'requested_qty' => '9',
        'change_reason' => 'Uji penolakan Kepala Bengkel',
    ]);
    $f6Rq2 = extract_id($f6R['location'], '/reorder');
    ok($f6Rq2 > 0 && $f6Rq2 !== $f6Rq, 'Permintaan kedua tersimpan terpisah');

    // Partman tidak boleh memutuskan sendiri permintaannya.
    eq(403, post('/reorder/' . $f6Rq . '/setujui', ['_token' => 'x'])['status'], 'Partman POST setujui reorder = 403');
    eq(403, post('/reorder/' . $f6Rq . '/tolak', ['_token' => 'x'])['status'], 'Partman POST tolak reorder = 403');

    jar_new('Fase 6 — Kepala Bengkel');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel (Fase 6) berhasil');
    foreach (['/reorder', '/reorder/' . $f6Rq, '/pembelian'] as $path) {
        eq(200, get($path)['status'], "Kepala Bengkel GET {$path} = 200");
    }
    foreach (['/reorder/tambah', '/pembelian/tambah'] as $path) {
        eq(403, get($path)['status'], "Kepala Bengkel GET {$path} = 403 (khusus Partman)");
    }
    eq(403, post('/reorder', ['_token' => 'x', 'sparepart_id' => '1', 'requested_qty' => '1'])['status'], 'Kepala Bengkel POST /reorder = 403');

    // Penolakan tanpa alasan ditolak sistem; dengan alasan diterima.
    $f6Show = get('/reorder/' . $f6Rq2);
    post('/reorder/' . $f6Rq2 . '/tolak', ['_token' => token($f6Show['body']), 'review_note' => '  ']);
    eq(
        'MENUNGGU_PERSETUJUAN',
        (string) scalar('SELECT status FROM reorder_requests WHERE id = ?', [$f6Rq2]),
        'Penolakan tanpa alasan tidak mengubah status'
    );
    $f6Show = get('/reorder/' . $f6Rq2);
    eq(302, post('/reorder/' . $f6Rq2 . '/tolak', [
        '_token' => token($f6Show['body']), 'review_note' => 'Stok penyangga masih aman',
    ])['status'], 'Kepala Bengkel POST tolak reorder beralasan = 302');
    eq('DITOLAK', (string) scalar('SELECT status FROM reorder_requests WHERE id = ?', [$f6Rq2]), 'Reorder ditolak dengan alasan tersimpan');

    // Persetujuan TIDAK menambah stok; riwayat status tercatat.
    $f6Show = get('/reorder/' . $f6Rq);
    eq(302, post('/reorder/' . $f6Rq . '/setujui', [
        '_token' => token($f6Show['body']), 'review_note' => '',
    ])['status'], 'Kepala Bengkel POST setujui reorder = 302');
    eq('DISETUJUI', (string) scalar('SELECT status FROM reorder_requests WHERE id = ?', [$f6Rq]), 'Reorder disetujui');
    eq($f6Stock, stock($sparepartId), 'Persetujuan reorder TIDAK mengubah stok');
    ok(
        (int) scalar(
            'SELECT COUNT(*) FROM purchase_status_history WHERE document_type = ? AND document_id = ?',
            ['REORDER', $f6Rq]
        ) >= 2,
        'Riwayat status reorder tercatat (buat + persetujuan)'
    );

    jar_new('Fase 6 — Partman membuat PO');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (PO) berhasil');
    $f6Form = get('/pembelian/tambah?reorder=' . $f6Rq);
    eq(200, $f6Form['status'], 'Partman GET /pembelian/tambah?reorder = 200 (pra-isi dari reorder)');
    $f6R = post('/pembelian', [
        '_token'             => token($f6Form['body']),
        'supplier_id'        => (string) $supplierId,
        'note'               => 'PO uji HTTP Fase 6',
        'reorder_request_id' => (string) $f6Rq,
        'item_sparepart_id'  => [(string) $sparepartId],
        'item_quantity'      => ['3'],
        'item_unit_price'    => ['12000'],
    ]);
    eq(302, $f6R['status'], 'Partman POST /pembelian = 302');
    $f6Po = extract_id($f6R['location'], '/pembelian');
    ok($f6Po > 0, 'Redirect ke detail PO');
    ok(
        str_starts_with((string) scalar('SELECT po_number FROM purchase_orders WHERE id = ?', [$f6Po]), 'PO-'),
        'Nomor PO berformat PO-YYYYMM-####'
    );
    eq('DRAFT', (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]), 'PO tersimpan sebagai Draf');
    eq(36000.0, (float) scalar('SELECT total_amount FROM purchase_orders WHERE id = ?', [$f6Po]), 'Total PO dihitung sistem (3 × 12.000)');
    eq($f6Stock, stock($sparepartId), 'Pembuatan PO TIDAK mengubah stok');

    // Ajukan ke Kepala Bengkel; Partman tidak bisa menyetujui/memproses/membayar.
    $f6Show = get('/pembelian/' . $f6Po);
    eq(302, post('/pembelian/' . $f6Po . '/ajukan', ['_token' => token($f6Show['body'])])['status'], 'Partman POST ajukan PO = 302');
    eq('MENUNGGU_PERSETUJUAN', (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]), 'PO diajukan ke Kepala Bengkel');
    foreach (['setujui', 'tolak', 'proses', 'pembayaran'] as $f6Action) {
        eq(
            403,
            post('/pembelian/' . $f6Po . '/' . $f6Action, ['_token' => 'x'])['status'],
            "Partman POST pembelian/{$f6Po}/{$f6Action} = 403"
        );
    }

    jar_new('Fase 6 — Kepala Bengkel menyetujui PO');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel (PO) berhasil');
    $f6Show = get('/pembelian/' . $f6Po);
    eq(302, post('/pembelian/' . $f6Po . '/setujui', ['_token' => token($f6Show['body'])])['status'], 'Kepala Bengkel POST setujui PO = 302');
    eq('DISETUJUI', (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]), 'PO disetujui Kepala Bengkel');
    eq($f6Stock, stock($sparepartId), 'Persetujuan PO TIDAK mengubah stok');
    eq(403, post('/pembelian/' . $f6Po . '/proses', ['_token' => 'x'])['status'], 'Kepala Bengkel POST proses PO = 403 (khusus Admin)');
    eq(403, post('/pembelian/' . $f6Po . '/pembayaran', ['_token' => 'x'])['status'], 'Kepala Bengkel POST pembayaran PO = 403 (khusus Admin)');

    jar_new('Fase 6 — Admin memproses & membayar');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (Fase 6) berhasil');
    foreach (['/reorder/tambah', '/pembelian/tambah'] as $path) {
        eq(403, get($path)['status'], "Admin GET {$path} = 403 (khusus Partman)");
    }
    eq(403, post('/reorder', ['_token' => 'x', 'sparepart_id' => '1', 'requested_qty' => '1'])['status'], 'Admin POST /reorder = 403');
    eq(403, post('/pembelian', ['_token' => 'x', 'supplier_id' => '1'])['status'], 'Admin POST /pembelian = 403');
    // Admin TIDAK boleh mengubah transaksi gudang (Fase 4 tetap berlaku).
    eq(403, post('/transaksi/masuk', ['_token' => 'x', 'quantity' => '1'])['status'], 'Admin POST /transaksi/masuk = 403');

    eq(200, get('/pembelian/' . $f6Po)['status'], 'Admin GET detail PO = 200');
    $f6Show = get('/pembelian/' . $f6Po);
    eq(302, post('/pembelian/' . $f6Po . '/proses', ['_token' => token($f6Show['body'])])['status'], 'Admin POST proses PO = 302');
    eq('DIPROSES_ADMIN', (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]), 'Admin memproses PO disetujui');
    eq($f6Stock, stock($sparepartId), 'Proses administrasi TIDAK mengubah stok');

    // Status pembayaran terpisah dari persetujuan + riwayatnya.
    $f6Show = get('/pembelian/' . $f6Po);
    eq(302, post('/pembelian/' . $f6Po . '/pembayaran', [
        '_token'        => token($f6Show['body']),
        'payment_status' => 'DIBAYAR_SEBAGIAN',
        'payment_note'   => 'DP 50% via transfer',
    ])['status'], 'Admin POST pembayaran = 302');
    eq(
        'DIBAYAR_SEBAGIAN',
        (string) scalar('SELECT payment_status FROM purchase_orders WHERE id = ?', [$f6Po]),
        'Status pembayaran Dibayar Sebagian'
    );
    ok(
        (int) scalar('SELECT COUNT(*) FROM purchase_payment_history WHERE purchase_order_id = ?', [$f6Po]) >= 1,
        'Riwayat pembayaran tercatat'
    );

    jar_new('Fase 6 — Partman mencatat penerimaan');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (penerimaan) berhasil');
    $f6Masuk = get('/transaksi/masuk/tambah');
    eq(200, $f6Masuk['status'], 'Form barang masuk memuat pemilih PO');
    ok(str_contains($f6Masuk['body'], 'purchase_order_id'), 'Form memuat pemilih PO terbuka');

    // Penerimaan parsial 2 dari 3.
    $f6R = post_multipart('/transaksi/masuk', [
        '_token'            => token($f6Masuk['body']),
        'receipt_date'      => '2026-10-19',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => (string) $supplierId,
        'sparepart_id'      => (string) $sparepartId,
        'quantity'          => '2',
        'unit_price'        => '12000',
        'purchase_order_id' => (string) $f6Po,
    ]);
    eq(302, $f6R['status'], 'Partman POST penerimaan parsial = 302');
    eq($f6Stock + 2, stock($sparepartId), 'Stok naik 2 saat penerimaan parsial (hanya saat penerimaan)');
    eq(
        'SEBAGIAN_DITERIMA',
        (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]),
        'PO berstatus Sebagian Diterima'
    );
    eq(
        1.0,
        (float) scalar('SELECT quantity - received_qty FROM purchase_order_items WHERE po_id = ?', [$f6Po]),
        'Sisa PO = 3 − 2 = 1'
    );

    // Kelebihan penerimaan di atas sisa SELALU ditolak.
    $f6Masuk = get('/transaksi/masuk/tambah');
    post_multipart('/transaksi/masuk', [
        '_token'            => token($f6Masuk['body']),
        'receipt_date'      => '2026-10-19',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => (string) $supplierId,
        'sparepart_id'      => (string) $sparepartId,
        'quantity'          => '2',
        'unit_price'        => '12000',
        'purchase_order_id' => (string) $f6Po,
    ]);
    eq($f6Stock + 2, stock($sparepartId), 'Penerimaan melebihi sisa ditolak, stok tidak berubah');
    eq(
        'SEBAGIAN_DITERIMA',
        (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]),
        'PO tetap Sebagian Diterima setelah penerimaan berlebih ditolak'
    );

    // Penerimaan sisa menutup PO.
    $f6Masuk = get('/transaksi/masuk/tambah');
    eq(302, post_multipart('/transaksi/masuk', [
        '_token'            => token($f6Masuk['body']),
        'receipt_date'      => '2026-10-19',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => (string) $supplierId,
        'sparepart_id'      => (string) $sparepartId,
        'quantity'          => '1',
        'unit_price'        => '12000',
        'purchase_order_id' => (string) $f6Po,
    ])['status'], 'Partman POST penerimaan sisa = 302');
    eq('SELESAI_DITERIMA', (string) scalar('SELECT status FROM purchase_orders WHERE id = ?', [$f6Po]), 'PO berstatus Selesai Diterima');
    eq($f6Stock + 3, stock($sparepartId), 'Stok penuh setelah seluruh PO diterima');

    // Riwayat status PO lengkap: buat → menunggu → disetujui → diproses →
    // sebagian → selesai.
    eq(
        6,
        (int) scalar(
            'SELECT COUNT(*) FROM purchase_status_history WHERE document_type = ? AND document_id = ?',
            ['PO', $f6Po]
        ),
        'Riwayat status PO lengkap enam transisi'
    );

    // Cetak PO (cetak & PDF) tersedia untuk yang berwenang melihat.
    $f6Print = get('/laporan/po/' . $f6Po . '/cetak');
    eq(200, $f6Print['status'], 'Cetak PO = 200');
    $f6Pdf = get('/laporan/po/' . $f6Po . '/pdf');
    eq(200, $f6Pdf['status'], 'PDF PO = 200');
    ok(str_starts_with($f6Pdf['body'], '%PDF-1.4'), 'PDF PO valid');

    // --------------------------------------------------------------
    // 6. Fase 7 — Prediksi stok (AI): HTTP + integrasi Python-PHP
    // --------------------------------------------------------------
    // Deteksi interpreter Python agar uji integrasi deterministik (tidak
    // bergantung pada PATH milik proses Apache) dan tulis ke .env uji.
    // Berkas .env asli tetap dipulihkan di blok finally.
    $pythonExe = '';
    $probePipes = [];
    $probe = @proc_open(
        ['py', '-3.12', '-c', 'import sys;print(sys.executable)'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $probePipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (is_resource($probe)) {
        $pythonExe = trim((string) stream_get_contents($probePipes[1]));
        foreach ([1, 2] as $pi) {
            if (isset($probePipes[$pi]) && is_resource($probePipes[$pi])) {
                @stream_set_blocking($probePipes[$pi], false);
                @stream_get_contents($probePipes[$pi]);
                @fclose($probePipes[$pi]);
            }
        }
        @proc_close($probe);
    }
    $pythonAvailable = $pythonExe !== '' && is_file($pythonExe);
    if ($pythonAvailable) {
        $envNow = (string) file_get_contents($envFile);
        $envNow = (string) preg_replace('/^PYTHON_PATH=.*$/m', 'PYTHON_PATH=' . $pythonExe, $envNow);
        $envNow = (string) preg_replace('/^PYTHON_ARGS=.*$/m', 'PYTHON_ARGS=', $envNow);
        file_put_contents($envFile, $envNow, LOCK_EX);
        Env::load($envFile);
    }
    echo '  [INFO] Interpreter Python uji HTTP: '
        . ($pythonAvailable ? $pythonExe : 'tidak ditemukan (uji jalur kegagalan aman)') . "\n";

    jar_new('Fase 7 — tanpa login');
    foreach (['/prediksi', '/prediksi/' . $sparepartId] as $path) {
        eq(302, get($path)['status'], "Tanpa login GET {$path} = 302");
    }

    jar_new('Fase 7 — Partman');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (Fase 7) berhasil');

    $predIndex = get('/prediksi');
    eq(200, $predIndex['status'], 'Partman GET /prediksi = 200');
    ok(str_contains($predIndex['body'], 'Prediksi Stok'), 'Halaman prediksi memuat judul');
    ok(str_contains($predIndex['body'], 'Moving Average'), 'Halaman prediksi menyebut Moving Average');
    ok(str_contains($predIndex['body'], 'Linear Regression'), 'Halaman prediksi menyebut Linear Regression');

    $predDetail = get('/prediksi/' . $sparepartId);
    eq(200, $predDetail['status'], 'Partman GET /prediksi/{id} = 200');
    ok(str_contains($predDetail['body'], 'Jalankan Prediksi'), 'Partman melihat tombol menjalankan analisis');
    ok(str_contains($predDetail['body'], 'name="period_days"'), 'Formulir analisis memuat pilihan periode');

    // Tanpa token CSRF → ditolak (tidak menjalankan proses apa pun).
    eq(
        403,
        post('/prediksi/' . $sparepartId . '/jalankan', ['_token' => 'palsu'])['status'],
        'POST jalankan tanpa token CSRF sah = 403'
    );

    // Jalankan analisis nyata: HTTP → PHP → proc_open Python lokal → JSON → DB.
    $predStockBefore = stock($sparepartId);
    $predForm = get('/prediksi/' . $sparepartId);
    $predRun = post('/prediksi/' . $sparepartId . '/jalankan', [
        '_token'       => token($predForm['body']),
        'period_days'  => '30',
        'history_days' => '60',
    ]);
    eq(302, $predRun['status'], 'Partman POST /prediksi/{id}/jalankan = 302');
    eq($predStockBefore, stock($sparepartId), 'Menjalankan prediksi TIDAK mengubah stok (hanya rekomendasi)');

    $predRow = rows(
        'SELECT * FROM ai_predictions WHERE sparepart_id = ? ORDER BY id DESC LIMIT 1',
        [$sparepartId]
    )[0] ?? null;
    $predFlash = follow($predRun['location'])['body'];

    if ($pythonAvailable) {
        ok($predRow !== null, 'Analisis menyimpan baris prediksi (integrasi Python-PHP via HTTP)');
        ok(str_contains($predFlash, 'Prediksi 30 hari selesai'), 'Notifikasi sukses analisis tampil');
        if ($predRow !== null) {
            ok(
                in_array((string) $predRow['data_sufficiency'], ['cukup', 'terbatas', 'tidak_cukup'], true),
                'Indikator kecukupan data tersimpan & bernilai sah'
            );
            eq(30, (int) $predRow['period_days'], 'Periode prediksi 30 hari tersimpan');
            ok(str_contains((string) $predRow['details'], 'cli_proc_open'), 'Details mencatat metode integrasi CLI lokal');
            ok(str_contains((string) $predRow['details'], 'lokal-only'), 'Details menegaskan integrasi lokal (tanpa jaringan)');
            ok(
                (int) scalar(
                    "SELECT COUNT(*) FROM audit_logs
                      WHERE activity = 'PREDIKSI_DIBUAT'
                        AND entity_type = 'ai_predictions'
                        AND entity_id = ?",
                    [(string) $predRow['id']]
                ) > 0,
                'Audit PREDIKSI_DIBUAT tercatat untuk analisis HTTP'
            );
        }
    } else {
        ok($predRow === null, 'Tanpa interpreter Python: tidak ada baris prediksi karangan (gagal terkendali)');
        ok(str_contains($predFlash, 'Prediksi gagal'), 'Kegagalan Python dijelaskan ke pengguna (transaksi tetap aman)');
    }

    // C1.4/C1.5 — override lead time manual oleh Partman (alasan wajib + audit).
    if ($pythonAvailable && $predRow !== null) {
        $overrideReason = 'Pengadaan khusus sesuai instruksi pemilik bengkel';
        $overrideForm = get('/prediksi/' . $sparepartId);
        $overrideRun = post('/prediksi/' . $sparepartId . '/jalankan', [
            '_token'                   => token($overrideForm['body']),
            'period_days'              => '30',
            'history_days'             => '60',
            'lead_time_override_days'  => '12',
            'lead_time_reason'         => $overrideReason,
        ]);
        eq(302, $overrideRun['status'], 'Partman POST jalankan dengan override lead time = 302');
        $overrideFlash = follow($overrideRun['location'])['body'];
        ok(str_contains($overrideFlash, 'override manual 12 hari'), 'Notifikasi menyebut override lead time');

        $overrideRow = rows(
            'SELECT * FROM ai_predictions WHERE sparepart_id = ? ORDER BY id DESC LIMIT 1',
            [$sparepartId]
        )[0] ?? null;
        ok(
            $overrideRow !== null && (int) $overrideRow['id'] !== (int) $predRow['id'],
            'Override menghasilkan baris prediksi baru'
        );
        if ($overrideRow !== null) {
            $overrideDetails = json_decode((string) $overrideRow['details'], true);
            $overrideLt = is_array($overrideDetails) && is_array($overrideDetails['lead_time'] ?? null)
                ? $overrideDetails['lead_time']
                : [];
            eq('manual_override', (string) ($overrideLt['source'] ?? ''), 'Sumber lead time tersimpan = manual_override');
            eq(12, (int) ($overrideLt['days'] ?? 0), 'Lead time override 12 hari tersimpan');
            eq($overrideReason, (string) ($overrideLt['reason'] ?? ''), 'Alasan override tersimpan di details');
            ok(
                (int) scalar(
                    "SELECT COUNT(*) FROM audit_logs
                      WHERE activity = 'LEAD_TIME_OVERRIDE'
                        AND entity_type = 'ai_predictions'
                        AND entity_id = ?",
                    [(string) $overrideRow['id']]
                ) > 0,
                'Audit LEAD_TIME_OVERRIDE tercatat untuk override HTTP'
            );
        }

        // Alasan wajib: override TANPA alasan ditolak & tidak menulis baris.
        $countBeforeNoReason = (int) scalar(
            'SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ?',
            [$sparepartId]
        );
        $noReasonForm = get('/prediksi/' . $sparepartId);
        $noReasonRun = post('/prediksi/' . $sparepartId . '/jalankan', [
            '_token'                  => token($noReasonForm['body']),
            'period_days'             => '30',
            'history_days'            => '60',
            'lead_time_override_days' => '12',
            'lead_time_reason'        => '',
        ]);
        eq(302, $noReasonRun['status'], 'Override tanpa alasan diarahkan kembali (302)');
        ok(
            str_contains(follow($noReasonRun['location'])['body'], 'Alasan override'),
            'Pesan "alasan override wajib" ditampilkan'
        );
        eq(
            $countBeforeNoReason,
            (int) scalar('SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ?', [$sparepartId]),
            'Override tanpa alasan TIDAK menulis baris prediksi'
        );
    }

    // Halaman detail tetap dapat dibuka (menampilkan hasil atau keadaan awal).
    $predAfter = get('/prediksi/' . $sparepartId);
    eq(200, $predAfter['status'], 'Detail prediksi tetap dapat dibuka setelah analisis');
    ok(
        str_contains($predAfter['body'], 'Hasil analisis') || str_contains($predAfter['body'], 'Belum ada prediksi'),
        'Detail menampilkan hasil analisis atau keadaan awal'
    );
    if ($predRow !== null) {
        ok(str_contains($predAfter['body'], 'Riwayat analisis'), 'Detail menampilkan riwayat analisis');
    }

    // Prediksi TIDAK boleh memblokir transaksi inventory.
    $predMasukForm = get('/transaksi/masuk/tambah');
    eq(200, $predMasukForm['status'], 'Transaksi barang masuk tetap dapat dibuka setelah prediksi');

    jar_new('Fase 7 — Kepala Bengkel');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel (Fase 7) berhasil');
    foreach (['/prediksi', '/prediksi/' . $sparepartId] as $path) {
        eq(200, get($path)['status'], "Kepala Bengkel GET {$path} = 200");
    }
    $kbPred = get('/prediksi/' . $sparepartId);
    ok(!str_contains($kbPred['body'], 'name="period_days"'), 'Kepala Bengkel TIDAK melihat formulir menjalankan analisis');
    eq(
        403,
        post('/prediksi/' . $sparepartId . '/jalankan', ['_token' => 'x'])['status'],
        'Kepala Bengkel POST /prediksi/{id}/jalankan = 403'
    );

    jar_new('Fase 7 — Admin');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (Fase 7) berhasil');
    foreach (['/prediksi', '/prediksi/' . $sparepartId] as $path) {
        eq(200, get($path)['status'], "Admin GET {$path} = 200");
    }
    $adPred = get('/prediksi/' . $sparepartId);
    ok(!str_contains($adPred['body'], 'name="period_days"'), 'Admin TIDAK melihat formulir menjalankan analisis');
    eq(
        403,
        post('/prediksi/' . $sparepartId . '/jalankan', ['_token' => 'x'])['status'],
        'Admin POST /prediksi/{id}/jalankan = 403'
    );

    // Laporan AI (Fase 7) memuat data analisis bila tersedia.
    jar_new('Fase 7 — laporan AI');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (laporan AI) berhasil');
    $laporanAi = get('/laporan/ai');
    eq(200, $laporanAi['status'], 'GET /laporan/ai = 200');
    ok(str_contains($laporanAi['body'], 'Rekomendasi AI'), 'Laporan AI menampilkan judulnya');
    if ($predRow !== null && (float) ($predRow['recommended_qty'] ?? 0) > 0) {
        ok(
            str_contains($laporanAi['body'], "HTTPV-SP-{$suffix}"),
            'Laporan AI memuat sparepart yang dianalisis'
        );
    } else {
        // Data uji HTTP belum cukup untuk rekomendasi → laporan tidak mengarang.
        ok(
            str_contains($laporanAi['body'], 'Status modul prediksi')
            || str_contains($laporanAi['body'], 'Belum pernah dijalankan'),
            'Laporan AI menjelaskan status prediksi tanpa mengarang rekomendasi'
        );
    }
    // ==============================================================
    // 8. Fase 8 — Chatbot Gemini (stub lokal; tidak memakai API key asli)
    // ==============================================================
    jar_new('Fase 8 — tanpa login');
    eq(302, post('/chatbot/kirim', ['_token' => 'x', 'question' => 'Stok?'])['status'], 'Tanpa login POST /chatbot/kirim = 302');
    eq(302, post('/chatbot/bersihkan', ['_token' => 'x'])['status'], 'Tanpa login POST /chatbot/bersihkan = 302');
    eq(302, post('/chatbot/api', ['_token' => 'x', 'question' => 'Stok?'])['status'], 'Tanpa login POST /chatbot/api = 302');
    eq(302, post('/chatbot/api/clear', ['_token' => 'x'])['status'], 'Tanpa login POST /chatbot/api/clear = 302');
    $anonLogin = get('/login');
    eq(200, $anonLogin['status'], 'GET /login (tanpa sesi) = 200');
    ok(!str_contains($anonLogin['body'], 'spAiWidget'), 'Widget TIDAK tampil di halaman login');

    $geminiStub = t_gemini_stub_start();
    file_put_contents(
        $envFile,
        "\nGEMINI_API_URL={$geminiStub['base']}\nGEMINI_TIMEOUT=5\n",
        FILE_APPEND | LOCK_EX
    );
    $stubCount = static fn (): int => count(t_gemini_stub_requests($geminiStub));
    $chatKey = 'TESTKEY-HTTP-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
    $invalidKey = 'INVALID-HTTP-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
    $unavailableKey = 'UNAVAILABLE-HTTP-ABCDEFGHIJKLMNOPQRSTUVWXYZ12';
    $chatTag = 'HTTPV-CHAT-' . $suffix;

    $chatSave = static function (array $overrides) use ($pdo): array {
        $page = get('/pengaturan/chatbot');
        $row = $pdo->query('SELECT system_prompt, topic_limits FROM chatbot_settings WHERE id = 1')->fetch() ?: [];
        return post('/pengaturan/chatbot', array_merge([
            '_token'              => token($page['body']),
            'model'               => 'gemini-3.5-flash-lite',
            'system_prompt'       => (string) ($row['system_prompt'] ?? ''),
            'topic_limits'        => (string) ($row['topic_limits'] ?? ''),
            'answer_style'        => 'ringkas',
            'usage_limit_daily'   => '',
            'usage_limit_monthly' => '',
        ], $overrides));
    };
    $chatSend = static function (string $question): array {
        $page = get('/chatbot');
        $res = post('/chatbot/kirim', ['_token' => token($page['body']), 'question' => $question]);
        return ['status' => $res['status'], 'page' => follow($res['location'])];
    };
    $widgetAsk = static function (string $question): array {
        $page = get('/dashboard');
        $res = post('/chatbot/api', ['_token' => widget_token($page['body']), 'question' => $question]);
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : ['ok' => false, 'answer' => '', 'status' => '', 'messages' => []];
    };
    $widgetClear = static function (): array {
        $page = get('/dashboard');
        $res = post('/chatbot/api/clear', ['_token' => widget_token($page['body'])]);
        $data = json_decode($res['body'], true);
        return is_array($data) ? $data : ['ok' => false];
    };
    $testConn = static function (string $model, string $key = ''): array {
        $page = get('/pengaturan/chatbot');
        $res = post('/pengaturan/chatbot/uji', [
            '_token' => token($page['body']),
            'model'  => $model,
            'api_key_test' => $key,
        ]);
        return ['status' => $res['status'], 'page' => follow($res['location'])];
    };

    // Partman — chatbot default nonaktif; pengaturan chatbot khusus Admin.
    jar_new('Fase 8 — Partman');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (Fase 8) berhasil');
    $partChat = get('/chatbot');
    eq(200, $partChat['status'], 'Partman GET /chatbot = 200');
    ok(str_contains($partChat['body'], 'Chatbot SmartPartMan'), 'Halaman chatbot memuat judul');
    ok(str_contains($partChat['body'], 'dinonaktifkan'), 'Chatbot default nonaktif dijelaskan');
    ok(!str_contains($partChat['body'], 'name="question"'), 'Form chat tidak tampil saat chatbot nonaktif');
    eq(403, get('/pengaturan/chatbot')['status'], 'Partman GET /pengaturan/chatbot = 403');
    eq(403, post('/pengaturan/chatbot', ['_token' => 'x'])['status'], 'Partman POST /pengaturan/chatbot = 403');
    eq(403, post('/pengaturan/chatbot/uji', ['_token' => 'x'])['status'], 'Partman POST /pengaturan/chatbot/uji = 403');
    $beforeStub = $stubCount();
    $disabledSend = $chatSend('Stok apa yang kritis?');
    eq(302, $disabledSend['status'], 'POST /chatbot/kirim saat nonaktif = 302');
    ok(str_contains($disabledSend['page']['body'], 'dinonaktifkan'), 'Pesan chatbot nonaktif tampil setelah kirim');
    eq($beforeStub, $stubCount(), 'Chatbot nonaktif TIDAK memanggil API Gemini');

    // Admin — simpan API key terenkripsi, uji koneksi, dan chat.
    jar_new('Fase 8 — Admin');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (Fase 8) berhasil');
    $adminSettings = get('/pengaturan/chatbot');
    eq(200, $adminSettings['status'], 'Admin GET /pengaturan/chatbot = 200');
    ok(str_contains($adminSettings['body'], 'Pengaturan AI Chatbot'), 'Halaman pengaturan chatbot tampil');
    ok(str_contains($adminSettings['body'], 'Uji Koneksi'), 'Tombol uji koneksi tampil');
    ok(str_contains($adminSettings['body'], 'tidak dikirim ke browser'), 'Peringatan API key tidak dikirim ke browser');
    ok(!str_contains($adminSettings['body'], $chatKey), 'Halaman pengaturan tidak memuat API key uji');
    $appSettings = get('/pengaturan');
    ok(str_contains($appSettings['body'], 'Chatbot Gemini'), 'Pengaturan aplikasi menautkan chatbot');
    ok(str_contains($appSettings['body'], 'Pengaturan Chatbot'), 'Pengaturan aplikasi menautkan pengaturan chatbot');

    eq(403, post('/pengaturan/chatbot', ['_token' => 'salah'])['status'], 'Simpan pengaturan dengan CSRF salah = 403');
    $chatAuditBefore = (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'CHATBOT_SETTINGS_UPDATE'");
    $saveKey = $chatSave([
        'is_enabled' => '1',
        'api_key'    => $chatKey,
    ]);
    eq(302, $saveKey['status'], 'Admin menyimpan API key = 302');
    $saveKeyPage = follow($saveKey['location']);
    ok(str_contains($saveKeyPage['body'], 'Pengaturan chatbot disimpan'), 'Konfirmasi simpan pengaturan tampil');
    $chatRow = rows('SELECT * FROM chatbot_settings WHERE id = 1')[0] ?? [];
    eq(1, (int) ($chatRow['is_enabled'] ?? 0), 'Pengaturan chatbot aktif tersimpan');
    eq('gemini-3.5-flash-lite', (string) ($chatRow['model'] ?? ''), 'Model default tersimpan');
    ok(str_starts_with((string) ($chatRow['api_key_encrypted'] ?? ''), 'v1:'), 'API key tersimpan terenkripsi v1');
    ok(!str_contains((string) ($chatRow['api_key_encrypted'] ?? ''), $chatKey), 'Plaintext API key tidak ada di database');
    eq('****1234', (string) ($chatRow['api_key_masked'] ?? ''), 'API key hanya disamarkan 4 karakter');
    eq($chatAuditBefore + 1, (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'CHATBOT_SETTINGS_UPDATE'"), 'Perubahan pengaturan chatbot masuk Audit Log');
    $configPage = get('/pengaturan/chatbot');
    ok(str_contains($configPage['body'], '****1234'), 'API key tersamarkan tampil di pengaturan');
    ok(!str_contains($configPage['body'], $chatKey), 'Plaintext API key tidak tampil di pengaturan');

    // Uji koneksi: key tersimpan (stub).
    $beforeStub = $stubCount();
    $conn = $testConn('gemini-3.5-flash-lite');
    eq(302, $conn['status'], 'Admin POST /pengaturan/chatbot/uji = 302');
    ok(str_contains($conn['page']['body'], 'Koneksi ke Gemini berhasil'), 'Uji koneksi dengan key tersimpan berhasil');
    ok(!str_contains($conn['page']['body'], $chatKey), 'Hasil uji koneksi tidak membocorkan API key');
    eq($beforeStub + 1, $stubCount(), 'Uji koneksi memanggil stub satu kali');
    $stubRequests = t_gemini_stub_requests($geminiStub);
    $lastConn = end($stubRequests) ?: [];
    eq('GET', (string) ($lastConn['method'] ?? ''), 'Uji koneksi memakai GET');
    ok(str_contains((string) ($lastConn['path'] ?? ''), '/models/gemini-3.5-flash-lite'), 'Uji koneksi memeriksa model yang dipilih');

    // Uji koneksi: key tidak valid (tidak disimpan).
    $connInvalid = $testConn('gemini-3.5-flash-lite', $invalidKey);
    ok(str_contains($connInvalid['page']['body'], 'Uji koneksi gagal'), 'Uji koneksi dengan key tidak valid gagal');
    ok(str_contains($connInvalid['page']['body'], 'API_KEY_INVALID'), 'Kode API_KEY_INVALID ditampilkan pada uji koneksi');
    ok(!str_contains($connInvalid['page']['body'], $invalidKey), 'Uji koneksi tidak membocorkan API key tidak valid');
    eq(
        (string) ($chatRow['api_key_encrypted'] ?? ''),
        (string) (rows('SELECT api_key_encrypted FROM chatbot_settings WHERE id = 1')[0]['api_key_encrypted'] ?? ''),
        'Uji koneksi tidak mengubah API key tersimpan'
    );

    // Chat sukses + escaping XSS (stub mengembalikan payload HTML).
    $beforeStub = $stubCount();
    $adminChat = get('/chatbot');
    eq(200, $adminChat['status'], 'Admin GET /chatbot = 200');
    ok(str_contains($adminChat['body'], 'name="question"'), 'Form chat tampil saat chatbot aktif');
    ok(!str_contains($adminChat['body'], $chatKey), 'Halaman chat tidak membocorkan API key');
    $xssQuestion = "<script>alert('xss')</script> Bagaimana cara mencatat barang masuk {$chatTag}?";
    $xssSend = $chatSend($xssQuestion);
    eq(302, $xssSend['status'], 'Admin POST /chatbot/kirim = 302');
    ok(str_contains($xssSend['page']['body'], 'Jawaban uji.'), 'Jawaban Gemini tampil di halaman chat');
    ok(str_contains($xssSend['page']['body'], '&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;'), 'Jawaban AI di-escape (anti-XSS)');
    ok(!str_contains($xssSend['page']['body'], "alert('xss')"), 'Payload XSS tidak tampil mentah');
    ok(!str_contains($xssSend['page']['body'], $chatKey), 'Halaman chat tidak membocorkan API key setelah jawaban');
    eq($beforeStub + 1, $stubCount(), 'Chat sukses memanggil Gemini satu kali');
    $stubRequests = t_gemini_stub_requests($geminiStub);
    $lastChat = end($stubRequests) ?: [];
    $adminSystem = (string) (($lastChat['body'] ?? [])['system_instruction'] ?? '');
    ok(str_contains($adminSystem, 'statistik_pengguna'), 'Konteks Admin dikirim ke Gemini');
    ok(str_contains($adminSystem, 'aktivitas_aplikasi'), 'Konteks aktivitas Admin dikirim ke Gemini');
    ok(!str_contains($adminSystem, $chatKey), 'Prompt Gemini tidak memuat API key');
    $log = rows('SELECT * FROM chatbot_usage_logs ORDER BY id DESC LIMIT 1')[0] ?? [];
    eq('success', (string) ($log['status'] ?? ''), 'Chat sukses tercatat di log');
    ok(!str_contains((string) ($log['question_preview'] ?? ''), 'Jawaban uji'), 'Log tidak menyimpan jawaban AI');
    ok(!str_contains((string) ($log['question_preview'] ?? ''), $chatKey), 'Log tidak menyimpan API key');

    // Riwayat percakapan benar-benar dikirim ke model pada permintaan berikutnya.
    $beforeStub = $stubCount();
    $historySend = $chatSend('Bagaimana cara mencatat barang masuk ' . $chatTag . '?');
    eq(302, $historySend['status'], 'POST /chatbot/kirim lanjutan = 302');
    eq($beforeStub + 1, $stubCount(), 'Pertanyaan lanjutan memanggil model satu kali');
    $stubRequests = t_gemini_stub_requests($geminiStub);
    $historyRequest = end($stubRequests) ?: [];
    $historyPayload = is_array($historyRequest['body'] ?? null) ? $historyRequest['body'] : [];
    $historyInput = (string) ($historyPayload['input'] ?? '');
    ok(str_contains($historyInput, 'RIWAYAT PERCAKAPAN SEBELUMNYA'),
        'Input model memuat bagian riwayat percakapan');
    ok(str_contains($historyInput, "Bagaimana cara mencatat barang masuk"),
        'Input model memuat pertanyaan pengguna sebelumnya');
    ok(str_contains($historyInput, 'Jawaban uji'),
        'Input model memuat jawaban asisten sebelumnya');
    ok(str_contains($historyInput, 'PERTANYAAN TERBARU PENGGUNA'),
        'Input model membedakan pertanyaan terbaru dari riwayat');
    ok(strpos($historyInput, 'RIWAYAT PERCAKAPAN SEBELUMNYA') < strpos($historyInput, 'PERTANYAAN TERBARU PENGGUNA'),
        'Riwayat dikirim sebelum pertanyaan terbaru');

    // Off-topic dan SQL tidak memanggil API.
    $beforeStub = $stubCount();
    $offTopic = $chatSend('Resep rendang enak?');
    ok(str_contains($offTopic['page']['body'], 'hanya dapat membantu'), 'Pertanyaan di luar topik ditolak');
    eq($beforeStub, $stubCount(), 'Pertanyaan di luar topik tidak memanggil API');
    $sqlSend = $chatSend('SELECT * FROM users');
    ok(str_contains($sqlSend['page']['body'], 'tidak menjalankan query SQL'), 'Permintaan SQL ditolak');
    eq($beforeStub, $stubCount(), 'Permintaan SQL tidak memanggil API');

    // API key tidak valid saat chat.
    $saveInvalid = $chatSave(['is_enabled' => '1', 'api_key' => $invalidKey]);
    eq(302, $saveInvalid['status'], 'Admin menyimpan API key tidak valid untuk uji = 302');
    follow($saveInvalid['location']);
    $beforeStub = $stubCount();
    $invalidChat = $chatSend('Bagaimana cara mencatat barang masuk ' . $chatTag . '?');
    ok(str_contains($invalidChat['page']['body'], 'API key Gemini tidak valid'), 'Chat dengan API key tidak valid menampilkan pesan aman');
    ok(!str_contains($invalidChat['page']['body'], $invalidKey), 'Pesan error chat tidak membocorkan API key');
    eq($beforeStub + 1, $stubCount(), 'Chat dengan API key tidak valid tetap memanggil API satu kali');
    $log = rows('SELECT * FROM chatbot_usage_logs ORDER BY id DESC LIMIT 1')[0] ?? [];
    eq('failed', (string) ($log['status'] ?? ''), 'Kegagalan API dicatat sebagai failed');
    ok(str_contains((string) ($log['error_message'] ?? ''), 'API_KEY_INVALID'), 'Kode error API tercatat di log');

    // API tidak tersedia (HTTP 500).
    $saveDown = $chatSave(['is_enabled' => '1', 'api_key' => $unavailableKey]);
    follow($saveDown['location']);
    $beforeStub = $stubCount();
    $downChat = $chatSend('Jelaskan prosedur stock opname ' . $chatTag);
    ok(str_contains($downChat['page']['body'], 'sedang tidak dapat dihubungi'), 'API tidak tersedia menghasilkan pesan aman');
    eq($beforeStub + 1, $stubCount(), 'API tidak tersedia tetap dihitung satu panggilan');

    // Batas pemakaian harian.
    $saveLimit = $chatSave(['is_enabled' => '1', 'api_key' => $chatKey, 'usage_limit_daily' => '1']);
    follow($saveLimit['location']);
    $widgetClear();
    $pdo->exec('DELETE FROM chatbot_usage_logs');
    $beforeStub = $stubCount();
    $firstLimit = $chatSend('Bagaimana cara mencatat barang masuk ' . $chatTag . '?');
    ok(str_contains($firstLimit['page']['body'], 'Jawaban uji Gemini'), 'Pemakaian pertama dalam batas berhasil');
    eq($beforeStub + 1, $stubCount(), 'Pemakaian pertama memanggil API');
    $secondLimit = $chatSend('Bagaimana cara mencatat barang keluar ' . $chatTag . '?');
    ok(str_contains($secondLimit['page']['body'], 'Batas penggunaan chatbot telah tercapai'), 'Pemakaian kedua melebihi batas ditolak');
    eq($beforeStub + 1, $stubCount(), 'Batas harian mencegah panggilan API tambahan');

    // API key kosong.
    $saveNoKey = $chatSave(['is_enabled' => '1', 'remove_api_key' => '1']);
    follow($saveNoKey['location']);
    $beforeStub = $stubCount();
    $noKeyChat = $chatSend('Bagaimana cara mencatat barang masuk ' . $chatTag . '?');
    ok(str_contains($noKeyChat['page']['body'], 'API key Gemini belum diatur'), 'Chat tanpa API key menampilkan pesan konfigurasi');
    eq($beforeStub, $stubCount(), 'Chat tanpa API key tidak memanggil API');

    // Database-first: supplier, rak, dan riwayat stok dijawab tanpa API key.
    $supplierCount = (int) scalar('SELECT COUNT(*) FROM suppliers');
    $beforeStub = $stubCount();
    $supplierChat = $chatSend('Ada berapa supplier?');
    ok(str_contains($supplierChat['page']['body'], number_format($supplierCount, 0, ',', '.') . ' supplier terdaftar'),
        'Chat menjawab jumlah supplier dari database tanpa API key');
    $rackCount = (int) scalar('SELECT COUNT(*) FROM storage_locations');
    $rackChat = $chatSend('Ada berapa rak?');
    ok(str_contains($rackChat['page']['body'], number_format($rackCount, 0, ',', '.') . ' lokasi/rak terdaftar'),
        'Chat menjawab jumlah rak/lokasi dari database tanpa API key');
    $historyChat = $chatSend('Tampilkan riwayat stok.');
    ok(str_contains($historyChat['page']['body'], 'Riwayat pergerakan stok')
        || str_contains($historyChat['page']['body'], 'Belum ada riwayat pergerakan stok'),
        'Chat menjawab riwayat stok dari ledger');
    $userCount = (int) scalar('SELECT COUNT(*) FROM users');
    $userChat = $chatSend('Ada berapa pengguna?');
    ok(str_contains($userChat['page']['body'], number_format($userCount, 0, ',', '.') . ' orang'),
        'Chat menjawab jumlah pengguna dari database tanpa API key');
    $adminFull = (string) scalar("SELECT full_name FROM users WHERE username = 'http_admin'");
    $partFull = (string) scalar("SELECT full_name FROM users WHERE username = 'http_partman'");
    $loginChat = $chatSend('Kapan ' . $adminFull . ' terakhir kali login?');
    ok(str_contains($loginChat['page']['body'], 'terakhir kali login pada'),
        'Chat menjawab login terakhir pengguna dari users.last_login_at');
    ok(!str_contains($loginChat['page']['body'], ' has id '),
        'Jawaban login tidak menampilkan ID internal');
    $followUser = $chatSend('Bagaimana dengan ' . $partFull . '?');
    ok(str_contains($followUser['page']['body'], $partFull)
        && str_contains($followUser['page']['body'], 'terakhir kali login pada'),
        'Chat memakai konteks sesi untuk login terakhir pengguna lain');
    eq($beforeStub, $stubCount(), 'Pertanyaan data lokal tidak memanggil API Gemini');

    // Percakapan berantai lewat endpoint widget: konteks harus bertahan antar
    // request HTTP dalam sesi yang sama (Skenario A-D).
    $bulanId = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $monthFrom = date('Y-m-01');
    $monthTo = date('Y-m-t');
    $monthName = $bulanId[(int) date('n')] . ' ' . date('Y');
    $receiptDocs = (int) scalar('SELECT COUNT(*) FROM goods_receipts');
    $issueDocs = (int) scalar('SELECT COUNT(*) FROM goods_issues');
    $monthDocs = (int) scalar('SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN ? AND ?', [$monthFrom, $monthTo]);
    $menipisCount = 0;
    foreach (rows('SELECT stock, min_stock FROM spareparts WHERE is_active = 1') as $stockRow) {
        if (\App\Models\Sparepart::stockStatusOf($stockRow) === 'menipis') {
            $menipisCount++;
        }
    }

    $widgetClear();
    $a1 = $widgetAsk('Berapa total barang masuk?');
    ok(str_contains((string) ($a1['answer'] ?? ''), number_format($receiptDocs, 0, ',', '.') . ' dokumen penerimaan'),
        'HTTP A1: total barang masuk dari database');
    $a2 = $widgetAsk('Berapa total masuknya bulan ini?');
    ok(str_contains((string) ($a2['answer'] ?? ''), 'bulan ini')
        && str_contains((string) ($a2['answer'] ?? ''), $monthFrom . ' s.d. ' . $monthTo),
        'HTTP A2: kata "masuknya" tetap pada barang masuk dan periode bulan ini');
    if ($monthDocs > 0) {
        ok(str_contains((string) ($a2['answer'] ?? ''), number_format($monthDocs, 0, ',', '.') . ' dokumen'),
            'HTTP A2: jumlah dokumen bulan ini sesuai database');
    }
    $a3 = $widgetAsk('Sebutkan rincian barangnya.');
    ok(str_contains((string) ($a3['answer'] ?? ''), 'Barang masuk pada ' . $monthName)
        || str_contains((string) ($a3['answer'] ?? ''), 'Tidak ada transaksi barang masuk pada ' . $monthName),
        'HTTP A3: rincian memakai periode yang sama');
    $a4 = $widgetAsk('Siapa yang mencatat barang masuk tersebut?');
    ok(str_contains((string) ($a4['answer'] ?? ''), $monthName)
        && (str_contains((string) ($a4['answer'] ?? ''), 'Pengguna yang melakukan transaksi')
            || str_contains((string) ($a4['answer'] ?? ''), 'Tidak ada transaksi barang masuk')),
        'HTTP A4: pertanyaan pelaku tetap pada topik dan periode yang sama');
    $a5 = $widgetAsk('Kapan transaksi terakhir?');
    ok(str_contains((string) ($a5['answer'] ?? ''), 'barang masuk')
        && str_contains((string) ($a5['answer'] ?? ''), 'tanggal '),
        'HTTP A5: transaksi terakhir tetap pada modul barang masuk');

    $widgetClear();
    $b1 = $widgetAsk('Berapa total barang keluar?');
    ok(str_contains((string) ($b1['answer'] ?? ''), number_format($issueDocs, 0, ',', '.') . ' dokumen pengeluaran'),
        'HTTP B1: total barang keluar dari database');
    $b2 = $widgetAsk('Barang apa saja yang keluar?');
    ok(str_contains((string) ($b2['answer'] ?? ''), 'Barang keluar pada'),
        'HTTP B2: daftar barang keluar tetap pada topik');
    $b3 = $widgetAsk('Berapa jumlah masing-masing barang?');
    ok(str_contains((string) ($b3['answer'] ?? ''), 'jumlah per barang')
        && str_contains((string) ($b3['answer'] ?? ''), 'Total keseluruhan barang keluar'),
        'HTTP B3: rekap jumlah per barang');
    $b4 = $widgetAsk('Siapa yang melakukan transaksi?');
    $issueRecorder = (string) scalar(
        'SELECT u.full_name FROM goods_issues gi JOIN users u ON u.id = gi.recorded_by ORDER BY gi.id DESC LIMIT 1'
    );
    ok(str_contains((string) ($b4['answer'] ?? ''), 'Pengguna yang melakukan transaksi')
        && $issueRecorder !== ''
        && str_contains((string) ($b4['answer'] ?? ''), $issueRecorder),
        'HTTP B4: pelaku transaksi barang keluar dari data');

    $widgetClear();
    $c1 = $widgetAsk('Berapa jumlah sparepart yang stoknya menipis?');
    ok(str_contains((string) ($c1['answer'] ?? ''), number_format($menipisCount, 0, ',', '.') . ' sparepart'),
        'HTTP C1: jumlah stok menipis sesuai database');
    $c2 = $widgetAsk('Sebutkan barangnya.');
    ok(str_contains((string) ($c2['answer'] ?? ''), 'status menipis'),
        'HTTP C2: daftar barang berstatus menipis');
    $c3 = $widgetAsk('Berapa stok masing-masing?');
    ok(str_contains((string) ($c3['answer'] ?? ''), 'stok ') && str_contains((string) ($c3['answer'] ?? ''), 'minimum '),
        'HTTP C3: stok dan minimum per barang');
    $c4 = $widgetAsk('Mana yang harus segera dipesan?');
    ok(stripos((string) ($c4['answer'] ?? ''), 'reorder') !== false,
        'HTTP C4: rekomendasi reorder');

    $widgetClear();
    $d1 = $widgetAsk('Berapa total barang masuk?');
    ok(str_contains((string) ($d1['answer'] ?? ''), 'dokumen penerimaan'), 'HTTP D1: topik awal barang masuk');
    $d2 = $widgetAsk('Sekarang berapa total barang keluar?');
    ok(str_contains((string) ($d2['answer'] ?? ''), 'dokumen pengeluaran'), 'HTTP D2: berpindah ke barang keluar');
    $d3 = $widgetAsk('Bagaimana rincian barang keluar?');
    ok(str_contains((string) ($d3['answer'] ?? ''), 'Barang keluar pada'), 'HTTP D3: rincian barang keluar');
    $d4 = $widgetAsk('Kembali ke pembahasan barang masuk, berapa jumlahnya?');
    ok(str_contains((string) ($d4['answer'] ?? ''), number_format($receiptDocs, 0, ',', '.') . ' dokumen penerimaan'),
        'HTTP D4: kembali ke barang masuk dengan angka benar');
    $d5 = $widgetAsk('Apakah ada yang belum selesai?');
    ok(str_contains((string) ($d5['answer'] ?? ''), 'Maksud Anda'),
        'HTTP: pertanyaan ambigu meminta klarifikasi');
    eq($beforeStub, $stubCount(), 'Percakapan berantai data lokal tidak memanggil API Gemini');
    $widgetClear();

    // ------------------------------------------------------------------
    // Pemisahan Penyesuaian Stok vs Stock Opname lewat endpoint widget.
    // API key sedang kosong, jadi seluruh jawaban wajib berasal dari DB.
    // ------------------------------------------------------------------
    $featurePart = (int) scalar('SELECT id FROM spareparts ORDER BY id LIMIT 1');
    $adjHttp1 = 'ADJ-' . $suffix . '-1';
    $adjHttp2 = 'ADJ-' . $suffix . '-2';
    $soHttp = 'SO-' . $suffix . '-1';
    $soHttpId = 0;
    try {
        $insAdj = $pdo->prepare(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after,
                 reference_type, reference_number, note, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $httpAdminId = (int) scalar("SELECT id FROM users WHERE username = 'http_admin'");
        $insAdj->execute([$featurePart, 'PENYESUAIAN', 3, date('Y-m-d'), 10, 13,
            'STOCK_ADJUSTMENT', $adjHttp1, 'Uji HTTP penambahan', $httpAdminId]);
        $insAdj->execute([$featurePart, 'PENYESUAIAN', -1, date('Y-m-d'), 13, 12,
            'STOCK_ADJUSTMENT', $adjHttp2, 'Uji HTTP pengurangan', $httpAdminId]);

        $pdo->prepare(
            'INSERT INTO stock_opnames (opname_number, opname_date, status, note, started_by,
                                        confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$soHttp, date('Y-m-d'), 'CONFIRMED', 'Uji HTTP opname',
            $httpAdminId, $httpAdminId, date('Y-m-d') . ' 11:00:00']);
        $soHttpId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO stock_opname_items
                (opname_id, sparepart_id, system_stock, physical_stock, difference, reason, counted_by, counted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$soHttpId, $featurePart, 10, 12, 2, 'Selisih uji HTTP',
            $httpAdminId, date('Y-m-d') . ' 10:00:00']);

        $adjDocsHttp = (int) scalar(
            "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id)))
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        );
        $adjAddHttp = (float) scalar(
            "SELECT COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0)
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        );
        $adjReduceHttp = (float) scalar(
            "SELECT COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0)
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        );
        $soSessionsHttp = (int) scalar('SELECT COUNT(*) FROM stock_opnames');
        $soDiffHttp = (int) scalar(
            'SELECT COUNT(*) FROM stock_opname_items
              WHERE physical_stock IS NOT NULL AND difference <> 0'
        );
        $fmtQtyHttp = static function (float $value): string {
            $out = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
            return $out === '' ? '0' : $out;
        };
        $beforeStub = $stubCount();

        $qa1 = $widgetAsk('Ada berapa penyesuaian stok?');
        ok(str_contains((string) ($qa1['answer'] ?? ''), number_format($adjDocsHttp, 0, ',', '.') . ' dokumen penyesuaian stok'),
            'HTTP PS1: jumlah penyesuaian dari stock_movements PENYESUAIAN');
        $qa2 = $widgetAsk('Tampilkan semua dokumen penyesuaian stok.');
        ok(str_contains((string) ($qa2['answer'] ?? ''), $adjHttp1)
            && str_contains((string) ($qa2['answer'] ?? ''), $adjHttp2),
            'HTTP PS2: daftar dokumen ADJ ditampilkan');
        ok(!str_contains((string) ($qa2['answer'] ?? ''), $soHttp),
            'HTTP PS2: daftar ADJ tidak mencampur dokumen SO');
        $qa3 = $widgetAsk('Apa penyesuaian stok terakhir?');
        ok(str_contains((string) ($qa3['answer'] ?? ''), $adjHttp2)
            || str_contains((string) ($qa3['answer'] ?? ''), $adjHttp1),
            'HTTP PS3: penyesuaian terakhir dari data ADJ');
        $qa4 = $widgetAsk('Siapa yang melakukan penyesuaian stok?');
        ok(str_contains((string) ($qa4['answer'] ?? ''), (string) scalar(
            "SELECT full_name FROM users WHERE username = 'http_admin'"
        )), 'HTTP PS4: pelaku penyesuaian dari stock_movements.user_id');
        $qa5 = $widgetAsk('Berapa total penambahan stok?');
        ok(str_contains((string) ($qa5['answer'] ?? ''), $fmtQtyHttp($adjAddHttp) . ' unit'),
            'HTTP PS5: total penambahan sesuai SUM quantity positif');
        $qa6 = $widgetAsk('Berapa total pengurangan stok?');
        ok(str_contains((string) ($qa6['answer'] ?? ''), $fmtQtyHttp($adjReduceHttp) . ' unit'),
            'HTTP PS6: total pengurangan sesuai SUM quantity negatif');

        $qb1 = $widgetAsk('Ada berapa stock opname?');
        ok(str_contains((string) ($qb1['answer'] ?? ''), number_format($soSessionsHttp, 0, ',', '.') . ' sesi stock opname'),
            'HTTP SO1: jumlah sesi dari stock_opnames');
        ok(str_contains((string) ($qb1['answer'] ?? ''), $soHttp),
            'HTTP SO1: sesi SO uji ditampilkan');
        ok(!str_contains((string) ($qb1['answer'] ?? ''), $adjHttp2),
            'HTTP SO1: daftar opname tidak mencampur dokumen ADJ');
        $qb2 = $widgetAsk('Tampilkan riwayat stock opname.');
        ok(str_contains((string) ($qb2['answer'] ?? ''), $soHttp)
            && str_contains((string) ($qb2['answer'] ?? ''), 'Item diperiksa'),
            'HTTP SO2: riwayat opname dan item diperiksa');
        $qb3 = $widgetAsk('Kapan stock opname terakhir?');
        ok(str_contains((string) ($qb3['answer'] ?? ''), $soHttp),
            'HTTP SO3: opname terakhir dari tanggal sesi');
        $qb4 = $widgetAsk('Berapa selisih stok fisik dan sistem?');
        ok(str_contains((string) ($qb4['answer'] ?? ''), 'Total item berselisih: ' . number_format($soDiffHttp, 0, ',', '.')),
            'HTTP SO4: item berselisih dari stock_opname_items');
        ok(str_contains((string) ($qb4['answer'] ?? ''), 'Total selisih absolut yang perlu dikoreksi'),
            'HTTP SO4: total selisih absolut disertakan');

        $qcmp1 = $widgetAsk('Apakah penyesuaian stok sama dengan stock opname?');
        ok(str_contains((string) ($qcmp1['answer'] ?? ''), 'dua fitur yang berbeda')
            && str_contains((string) ($qcmp1['answer'] ?? ''), 'stock_movements')
            && str_contains((string) ($qcmp1['answer'] ?? ''), 'stock_opnames'),
            'HTTP Banding 1: kedua fitur dibedakan beserta sumber datanya');
        $qcmp2 = $widgetAsk('Apa perbedaan kedua fitur tersebut?');
        ok(str_contains((string) ($qcmp2['answer'] ?? ''), 'dua fitur yang berbeda'),
            'HTTP Banding 2: pertanyaan perbedaan dijawab dari definisi modul');
        $qamb = $widgetAsk('Berapa koreksi stok?');
        ok(str_contains((string) ($qamb['answer'] ?? ''), 'perlu memastikan modul'),
            'HTTP: istilah ambigu meminta klarifikasi');
        eq($beforeStub, $stubCount(), 'HTTP: 12 pertanyaan pemisahan modul tidak memanggil API');
    } finally {
        if ($soHttpId > 0) {
            $pdo->prepare('DELETE FROM stock_opname_items WHERE opname_id = ?')->execute([$soHttpId]);
            $pdo->prepare('DELETE FROM stock_opnames WHERE id = ?')->execute([$soHttpId]);
        }
        $pdo->prepare('DELETE FROM stock_movements WHERE reference_number IN (?, ?)')->execute([$adjHttp1, $adjHttp2]);
        $widgetClear();
    }


    // Aktifkan lagi dengan key untuk pengujian role Partman/Kepala Bengkel.
    $saveActive = $chatSave(['is_enabled' => '1', 'api_key' => $chatKey]);
    follow($saveActive['location']);

    jar_new('Fase 8 — Partman chat aktif');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (chat aktif) berhasil');
    $partActive = get('/chatbot');
    eq(200, $partActive['status'], 'Partman GET /chatbot saat aktif = 200');
    ok(!str_contains($partActive['body'], '****1234'), 'Partman tidak melihat API key tersamarkan');
    ok(!str_contains($partActive['body'], $chatKey), 'Partman tidak melihat API key uji');
    $beforeStub = $stubCount();
    $partOff = $chatSend('Resep rendang enak?');
    ok(str_contains($partOff['page']['body'], 'hanya dapat membantu'), 'Partman ditolak untuk topik luar aplikasi');
    $partRole = $chatSend('Berapa jumlah pengguna?');
    ok(str_contains($partRole['page']['body'], 'hanya tersedia untuk Admin'), 'Partman ditolak untuk data pengguna');
    $adminFullName = (string) scalar("SELECT full_name FROM users WHERE username = 'http_admin'");
    $partLogin = $chatSend('Kapan ' . $adminFullName . ' terakhir login?');
    ok(str_contains($partLogin['page']['body'], 'hanya tersedia untuk Admin'),
        'Partman ditolak untuk login terakhir pengguna');
    $partSql = $chatSend('SELECT * FROM users');
    ok(str_contains($partSql['page']['body'], 'tidak menjalankan query SQL'), 'Partman ditolak untuk permintaan SQL');
    eq($beforeStub, $stubCount(), 'Guard Partman tidak memanggil API');
    $partOk = $chatSend('Bagaimana cara mencatat barang masuk ' . $chatTag . '?');
    ok(str_contains($partOk['page']['body'], 'Jawaban uji Gemini'), 'Partman mendapat jawaban dari Gemini (stub)');
    eq($beforeStub + 1, $stubCount(), 'Chat Partman memanggil API satu kali');
    $stubRequests = t_gemini_stub_requests($geminiStub);
    $lastPart = end($stubRequests) ?: [];
    $partSystem = (string) (($lastPart['body'] ?? [])['system_instruction'] ?? '');
    ok(str_contains($partSystem, 'ringkasan_persediaan'), 'Konteks Partman dikirim ke Gemini');
    ok(!str_contains($partSystem, 'statistik_pengguna'), 'Konteks Partman tidak memuat statistik pengguna');

    // Kepala Bengkel — konteks laporan, tanpa data pengguna/audit.
    jar_new('Fase 8 — Kepala Bengkel');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel (Fase 8) berhasil');
    eq(200, get('/chatbot')['status'], 'Kepala Bengkel GET /chatbot = 200');
    eq(403, get('/pengaturan/chatbot')['status'], 'Kepala Bengkel GET /pengaturan/chatbot = 403');
    eq(403, post('/pengaturan/chatbot', ['_token' => 'x'])['status'], 'Kepala Bengkel POST /pengaturan/chatbot = 403');
    $beforeStub = $stubCount();
    $kbRole = $chatSend('Tampilkan audit log');
    ok(str_contains($kbRole['page']['body'], 'hanya tersedia untuk Admin'), 'Kepala Bengkel ditolak untuk audit log');
    eq($beforeStub, $stubCount(), 'Guard Kepala Bengkel tidak memanggil API');
    $kbOk = $chatSend('Jelaskan prosedur stock opname ' . $chatTag);
    ok(str_contains($kbOk['page']['body'], 'Jawaban uji Gemini'), 'Kepala Bengkel mendapat jawaban dari Gemini (stub)');
    eq($beforeStub + 1, $stubCount(), 'Chat Kepala Bengkel memanggil API satu kali');
    $stubRequests = t_gemini_stub_requests($geminiStub);
    $lastKb = end($stubRequests) ?: [];
    $kbSystem = (string) (($lastKb['body'] ?? [])['system_instruction'] ?? '');
    ok(
        str_contains($kbSystem, 'pemakaian_30_hari') || str_contains($kbSystem, 'bulan_ini'),
        'Konteks laporan Kepala Bengkel dikirim ke Gemini'
    );
    ok(!str_contains($kbSystem, 'statistik_pengguna'), 'Konteks Kepala Bengkel tidak memuat statistik pengguna');
    ok(!str_contains($kbSystem, 'aktivitas_aplikasi'), 'Konteks Kepala Bengkel tidak memuat audit aplikasi');

    // ==============================================================
    // 8b. Floating AI Assistant (revisi UI) — widget di layout + endpoint JSON
    // ==============================================================
    jar_new('Fase 8b — widget di seluruh halaman (Admin)');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (widget) berhasil');

    $dash = get('/dashboard');
    eq(200, $dash['status'], 'Admin GET /dashboard = 200');
    ok(str_contains($dash['body'], 'spAiWidget'), 'Widget tampil di dashboard');
    ok(str_contains($dash['body'], 'SmartPartMan AI'), 'Widget memuat nama asisten');
    ok(str_contains($dash['body'], 'spAiConfig'), 'Widget memuat konfigurasi JSON');
    ok(str_contains($dash['body'], 'js/chatbot-widget.js'), 'Widget memuat berkas JavaScript');
    ok(str_contains($dash['body'], 'sp-ai-chip'), 'Widget memuat contoh pertanyaan');
    ok(!str_contains($dash['body'], $chatKey), 'Widget tidak membocorkan API key');
    ok(str_contains(get('/sparepart')['body'], 'spAiWidget'), 'Widget tampil di halaman sparepart');
    ok(str_contains(get('/transaksi/masuk')['body'], 'spAiWidget'), 'Widget tampil di halaman transaksi');
    ok(str_contains(get('/laporan')['body'], 'spAiWidget'), 'Widget tampil di halaman laporan');

    jar_new('Fase 8b — RBAC & CSRF endpoint widget');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (widget RBAC) berhasil');
    eq(403, post('/chatbot/api', ['_token' => 'salah', 'question' => 'Stok?'])['status'], 'POST /chatbot/api CSRF salah = 403');
    eq(403, post('/chatbot/api/clear', ['_token' => 'salah'])['status'], 'POST /chatbot/api/clear CSRF salah = 403');
    $partWidget = get('/dashboard');
    ok(str_contains($partWidget['body'], 'spAiWidget'), 'Widget tampil untuk Partman');

    jar_new('Fase 8b — widget menghormati role (Partman)');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (widget role) berhasil');
    $partWidget = get('/dashboard');
    $partGuard = post('/chatbot/api', [
        '_token'   => widget_token($partWidget['body']),
        'question' => 'Berapa jumlah pengguna?',
    ]);
    eq(200, $partGuard['status'], 'POST /chatbot/api (Partman) = 200');
    $partGuardPayload = json_decode($partGuard['body'], true);
    ok(is_array($partGuardPayload), 'Respons widget (Partman) berupa JSON valid');
    ok(($partGuardPayload['status'] ?? '') === 'role_scope', 'Widget menolak data pengguna untuk Partman');
    $beforeStub = $stubCount();
    post('/chatbot/api', ['_token' => widget_token($partWidget['body']), 'question' => 'Resep rendang?']);
    eq($beforeStub, $stubCount(), 'Widget Partman tidak memanggil API untuk topik terlarang');

    jar_new('Fase 8b — kirim & riwayat JSON (Admin)');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (widget kirim) berhasil');
    $widgetPage = get('/dashboard');
    eq(200, $widgetPage['status'], 'Admin GET /dashboard = 200');
    $widgetToken = widget_token($widgetPage['body']);
    ok($widgetToken !== '', 'Token widget berhasil diekstrak dari konfigurasi');
    $beforeStub = $stubCount();
    $widgetQuestion = 'Bagaimana cara mencatat barang masuk ' . $chatTag . '?';
    $apiSend = post('/chatbot/api', [
        '_token'   => $widgetToken,
        'question' => $widgetQuestion,
    ]);
    eq(200, $apiSend['status'], 'POST /chatbot/api = 200');
    ok(str_contains($apiSend['ctype'], 'application/json'), 'Respons endpoint ber-JSON');
    $payload = json_decode($apiSend['body'], true);
    ok(is_array($payload), 'Respons endpoint berupa JSON valid');
    eq(true, (bool) ($payload['ok'] ?? false), 'Endpoint mengembalikan ok=true');
    ok(str_contains((string) ($payload['answer'] ?? ''), 'Jawaban uji'), 'Endpoint mengembalikan jawaban Gemini');
    ok(is_array($payload['messages'] ?? null) && count($payload['messages']) >= 2, 'Endpoint mengembalikan riwayat percakapan');
    eq($beforeStub + 1, $stubCount(), 'Widget memanggil Gemini satu kali');
    $widgetLog = rows('SELECT * FROM chatbot_usage_logs ORDER BY id DESC LIMIT 1')[0] ?? [];
    ok(!str_contains((string) ($widgetLog['question_preview'] ?? ''), $chatKey), 'Log widget tidak menyimpan API key');

    // Riwayat tersimpan di sesi: tampil di halaman lain selama sesi yang sama.
    $afterSend = get('/sparepart');
    ok(str_contains($afterSend['body'], 'spAiWidget'), 'Widget tetap ada di halaman lain');
    ok(str_contains($afterSend['body'], $widgetQuestion), 'Riwayat percakapan tetap tampil di halaman lain');

    // Percakapan baru menghapus riwayat.
    $clearRes = post('/chatbot/api/clear', ['_token' => widget_token($afterSend['body'])]);
    eq(200, $clearRes['status'], 'POST /chatbot/api/clear = 200');
    $cleared = get('/dashboard');
    ok(!str_contains($cleared['body'], $widgetQuestion), 'Riwayat dibersihkan setelah percakapan baru');
    ok(str_contains($cleared['body'], 'Halo! Saya SmartPartMan AI'), 'Sambutan tampil kembali setelah percakapan baru');

    // Jawaban data langsung dari database: angka harus sama dengan Dashboard,
    // tanpa memanggil Gemini dan tanpa Markdown.
    $dataToken = widget_token($cleared['body']);
    $categoryCount = (int) scalar('SELECT COUNT(*) FROM categories');
    $receiptCount = (int) scalar('SELECT COUNT(*) FROM goods_receipts');
    $beforeData = $stubCount();
    $dataCategory = post('/chatbot/api', [
        '_token'   => $dataToken,
        'question' => 'Berapa jumlah kategori sparepart?',
    ]);
    $categoryPayload = json_decode($dataCategory['body'], true);
    ok(is_array($categoryPayload) && ($categoryPayload['status'] ?? '') === 'ok', 'Pertanyaan kategori dijawab sukses');
    ok(
        str_contains((string) ($categoryPayload['answer'] ?? ''), number_format($categoryCount, 0, ',', '.')),
        'Jawaban kategori memakai jumlah aktual database'
    );
    ok(
        !str_contains((string) ($categoryPayload['answer'] ?? ''), '**')
        && !str_contains((string) ($categoryPayload['answer'] ?? ''), '\\n'),
        'Jawaban data bebas Markdown dan escape literal'
    );

    $dataReceipt = post('/chatbot/api', [
        '_token'   => $dataToken,
        'question' => 'Berapa total transaksi barang masuk?',
    ]);
    $receiptPayload = json_decode($dataReceipt['body'], true);
    ok(
        is_array($receiptPayload)
        && str_contains((string) ($receiptPayload['answer'] ?? ''), number_format($receiptCount, 0, ',', '.')),
        'Jawaban barang masuk memakai jumlah dokumen aktual'
    );
    eq($beforeData, $stubCount(), 'Pertanyaan data tidak memanggil Gemini');

    // Multi-provider: setiap provider menyimpan kunci sendiri; pergantian
    // provider tidak menghapus kunci provider lain.
    $deepseekKey = 'DEEPSEEK-HTTP-ABCDEFGHIJKLMNOPQRST12';
    $openaiKey = 'OPENAI-HTTP-ABCDEFGHIJKLMNOPQRSTUV1234';
    $geminiEncryptedBeforeProviders = (string) (rows('SELECT api_key_encrypted FROM chatbot_settings WHERE id = 1')[0]['api_key_encrypted'] ?? '');
    $saveDeepseek = $chatSave([
        'provider' => 'deepseek',
        'model'    => 'deepseek-chat',
        'api_key'  => $deepseekKey,
    ]);
    eq(302, $saveDeepseek['status'], 'Admin menyimpan provider DeepSeek = 302');
    follow($saveDeepseek['location']);
    $activeRow = rows('SELECT provider FROM chatbot_settings WHERE id = 1')[0] ?? [];
    eq('deepseek', (string) ($activeRow['provider'] ?? ''), 'Provider aktif = DeepSeek');
    $deepseekRow = rows("SELECT * FROM chatbot_providers WHERE provider = 'deepseek'")[0] ?? [];
    ok(str_starts_with((string) ($deepseekRow['api_key_encrypted'] ?? ''), 'v1:'), 'Kunci DeepSeek tersimpan terenkripsi');
    ok(!str_contains((string) ($deepseekRow['api_key_encrypted'] ?? ''), $deepseekKey), 'Plaintext kunci DeepSeek tidak ada di database');
    $geminiKeyAfterDeepseek = (string) (rows('SELECT api_key_encrypted FROM chatbot_settings WHERE id = 1')[0]['api_key_encrypted'] ?? '');
    eq((string) ($geminiEncryptedBeforeProviders), $geminiKeyAfterDeepseek, 'Menyimpan DeepSeek tidak mengubah kunci Gemini');

    $saveOpenai = $chatSave([
        'provider' => 'openai',
        'model'    => 'gpt-4o-mini',
        'api_key'  => $openaiKey,
    ]);
    eq(302, $saveOpenai['status'], 'Admin menyimpan provider OpenAI = 302');
    follow($saveOpenai['location']);
    eq('openai', (string) (rows('SELECT provider FROM chatbot_settings WHERE id = 1')[0]['provider'] ?? ''), 'Provider aktif = OpenAI');
    $openaiRow = rows("SELECT * FROM chatbot_providers WHERE provider = 'openai'")[0] ?? [];
    ok(str_starts_with((string) ($openaiRow['api_key_encrypted'] ?? ''), 'v1:'), 'Kunci OpenAI tersimpan terenkripsi');
    eq(
        (string) ($deepseekRow['api_key_encrypted'] ?? ''),
        (string) (rows("SELECT api_key_encrypted FROM chatbot_providers WHERE provider = 'deepseek'")[0]['api_key_encrypted'] ?? ''),
        'Menyimpan OpenAI tidak mengubah kunci DeepSeek'
    );

    $backToGemini = $chatSave(['provider' => 'gemini']);
    follow($backToGemini['location']);
    eq('gemini', (string) (rows('SELECT provider FROM chatbot_settings WHERE id = 1')[0]['provider'] ?? ''), 'Kembali ke provider Gemini tanpa key baru');
    eq(
        $geminiEncryptedBeforeProviders,
        (string) (rows('SELECT api_key_encrypted FROM chatbot_settings WHERE id = 1')[0]['api_key_encrypted'] ?? ''),
        'Kunci Gemini lama dipertahankan setelah pergantian provider'
    );
    eq(2, (int) scalar('SELECT COUNT(*) FROM chatbot_providers'), 'Dua konfigurasi provider tambahan tetap tersimpan');

    $providerPage = get('/pengaturan/chatbot');
    eq(200, $providerPage['status'], 'Halaman pengaturan multi-provider = 200');
    ok(str_contains($providerPage['body'], 'DeepSeek') && str_contains($providerPage['body'], 'OpenAI'), 'Pilihan provider DeepSeek & OpenAI tampil');
    ok(str_contains($providerPage['body'], 'API Key Tersimpan'), 'Status API key tersimpan tampil');
    ok(!str_contains($providerPage['body'], $deepseekKey) && !str_contains($providerPage['body'], $openaiKey), 'Kunci DeepSeek & OpenAI tidak tampil di HTML');

    // Arahkan Base URL DeepSeek ke stub agar uji koneksi tidak memanggil internet.
    $pdo->prepare("UPDATE chatbot_providers SET api_base = ? WHERE provider = 'deepseek'")
        ->execute([$geminiStub['base']]);
    $providerTest = post('/pengaturan/chatbot/uji', [
        '_token'   => token($providerPage['body']),
        'provider' => 'deepseek',
        'model'    => 'deepseek-chat',
    ]);
    eq(302, $providerTest['status'], 'Uji koneksi DeepSeek = 302');
    $providerTestPage = follow($providerTest['location']);
    ok(str_contains($providerTestPage['body'], 'Koneksi ke DeepSeek berhasil'), 'Uji koneksi DeepSeek berhasil');
    ok(!str_contains($providerTestPage['body'], $deepseekKey), 'Halaman uji DeepSeek tidak membocorkan kunci');

    // Kembalikan pengaturan ke NONAKTIF & tanpa key.
    jar_new('Fase 8 — pemulihan');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (pemulihan) berhasil');
    $resetChat = $chatSave(['remove_api_key' => '1']);
    follow($resetChat['location']);
    $finalChatRow = rows('SELECT * FROM chatbot_settings WHERE id = 1')[0] ?? [];
    eq(0, (int) ($finalChatRow['is_enabled'] ?? 1), 'Pengaturan chatbot dikembalikan NONAKTIF');
    ok(($finalChatRow['api_key_encrypted'] ?? null) === null, 'API key uji dihapus dari database uji');

    // Tidak ada API key di log/audit.
    eq(
        0,
        (int) scalar(
            'SELECT COUNT(*) FROM chatbot_usage_logs WHERE question_preview LIKE ? OR error_message LIKE ?',
            ['%' . $chatKey . '%', '%' . $chatKey . '%']
        ),
        'Log penggunaan tidak memuat API key'
    );
    eq(
        0,
        (int) scalar(
            'SELECT COUNT(*) FROM audit_logs
              WHERE new_values LIKE ? OR old_values LIKE ? OR description LIKE ?',
            ['%' . $chatKey . '%', '%' . $chatKey . '%', '%' . $chatKey . '%']
        ),
        'Audit Log tidak memuat API key'
    );
    eq(
        0,
        (int) scalar('SELECT COUNT(*) FROM chatbot_settings WHERE api_key_encrypted LIKE ?', ['%' . $chatKey . '%']),
        'API key tidak tersimpan sebagai plaintext'
    );

    // ==============================================================
    // 9. Fase 9 — Backup & Restore, ekspor Audit Log, logo, header
    // ==============================================================
    jar_new('Fase 9 — Partman (RBAC)');
    ok(login('http_partman', $accounts['http_partman']), 'Login Partman (Fase 9) berhasil');
    eq(403, get('/pengaturan/whatsapp/penerima/1')['status'], 'Partman GET edit penerima WhatsApp = 403');
    eq(403, post('/pengaturan/whatsapp/penerima/1', ['_token' => 'x'])['status'], 'Partman POST edit penerima WhatsApp = 403');
    eq(403, get('/pengaturan/backup')['status'], 'Partman GET /pengaturan/backup = 403');
    eq(403, post('/pengaturan/backup', ['_token' => 'x'])['status'], 'Partman POST /pengaturan/backup = 403');
    eq(403, get('/pengaturan/backup/1/unduh')['status'], 'Partman GET unduh backup = 403');
    eq(403, post('/pengaturan/backup/1/restore', ['_token' => 'x'])['status'], 'Partman POST restore = 403');
    eq(403, get('/audit-log/export')['status'], 'Partman GET ekspor Audit Log = 403');

    jar_new('Fase 9 — Kepala Bengkel (RBAC)');
    ok(login('http_kepala', $accounts['http_kepala']), 'Login Kepala Bengkel (Fase 9) berhasil');
    eq(403, get('/pengaturan/whatsapp/penerima/1')['status'], 'Kepala Bengkel GET edit penerima WhatsApp = 403');
    eq(403, post('/pengaturan/whatsapp/penerima/1', ['_token' => 'x'])['status'], 'Kepala Bengkel POST edit penerima WhatsApp = 403');
    eq(403, get('/pengaturan/backup')['status'], 'Kepala Bengkel GET /pengaturan/backup = 403');
    eq(403, get('/audit-log/export')['status'], 'Kepala Bengkel GET ekspor Audit Log = 403');

    jar_new('Fase 9 — Admin');
    ok(login('http_admin', $accounts['http_admin']), 'Login Admin (Fase 9) berhasil');

    // --- Alur simpan nomor WhatsApp & OTP harus kembali ke halaman edit penerima. ---
    $waUserId = (int) scalar("SELECT id FROM users WHERE username = 'http_admin'");
    $waUserSnapshot = rows(
        'SELECT whatsapp_number, whatsapp_verified, whatsapp_pending_number, whatsapp_otp_hash,
                whatsapp_otp_expires_at, whatsapp_otp_attempts, whatsapp_otp_last_sent_at
           FROM users WHERE id = ?',
        [$waUserId]
    )[0] ?? [];
    $waPrefSnapshot = rows('SELECT is_enabled, preferences_json FROM whatsapp_user_preferences WHERE user_id = ?', [$waUserId])[0] ?? null;
    try {
        $waPage = get('/pengaturan/whatsapp/penerima/' . $waUserId);
        eq(200, $waPage['status'], 'Admin GET edit penerima WhatsApp = 200');
        ok(str_contains($waPage['body'], 'Edit Penerima Notifikasi WhatsApp'), 'Halaman edit penerima tampil');
        ok(str_contains($waPage['body'], 'Verifikasi Nomor WhatsApp (OTP)'), 'Bagian verifikasi OTP tersedia di halaman yang sama');

        // Navigasi kembali harus ke daftar penerima, bukan Gateway, termasuk saat URL dibuka langsung.
        $waEditUrl = '/pengaturan/whatsapp/penerima/' . $waUserId;
        $waListUrl = '/pengaturan/whatsapp/notifikasi';
        ok(str_contains($waPage['body'], 'Kembali ke Manajemen Notifikasi'), 'Tombol kembali berlabel Manajemen Notifikasi');
        ok(
            preg_match('#<a[^>]+href="[^"]*' . preg_quote($waListUrl, '#') . '"[^>]*>Kembali ke Manajemen Notifikasi</a>#', $waPage['body']) === 1,
            'Tombol kembali menuju route daftar penerima notifikasi'
        );
        ok(str_contains($waPage['body'], 'Pengaturan WhatsApp'), 'Breadcrumb memuat Pengaturan WhatsApp');
        ok(str_contains($waPage['body'], 'Manajemen Notifikasi'), 'Breadcrumb memuat Manajemen Notifikasi');
        ok(str_contains($waPage['body'], 'Edit Penerima'), 'Breadcrumb memuat Edit Penerima');

        // Menu OTP WhatsApp dihapus dari navigasi; fungsi verifikasi tetap di halaman edit.
        ok(!str_contains($waPage['body'], 'OTP WhatsApp'), 'Menu OTP WhatsApp tidak tampil di sidebar');
        ok(!str_contains($waPage['body'], '/pengaturan/whatsapp/otp"'), 'Tidak ada tautan navigasi ke halaman OTP');
        ok(str_contains($waPage['body'], 'WhatsApp Gateway'), 'Menu WhatsApp Gateway tetap tersedia');
        ok(str_contains($waPage['body'], 'Notifikasi WhatsApp'), 'Menu Notifikasi WhatsApp tetap tersedia');
        eq(200, get('/pengaturan/whatsapp/otp')['status'], 'Route OTP tetap tersedia meski menu dihapus');

        // Sidebar scroll: kunci per pengguna, target .sidebar-body, dan state disimpan di sessionStorage.
        ok(str_contains($waPage['body'], 'data-user-id="'), 'Body layout memuat identitas pengguna untuk kunci scroll');
        $appJs = get('/assets/js/app.js');
        eq(200, $appJs['status'], 'Aset app.js dapat dimuat');
        ok(str_contains($appJs['body'], 'smartpartman_sidebar_scroll:'), 'app.js menyimpan posisi scroll sidebar');
        ok(str_contains($appJs['body'], '#sidebarMenu .sidebar-body'), 'app.js memakai elemen scroll sidebar yang benar');
        ok(str_contains($appJs['body'], '#mobileMenu .sidebar-body'), 'app.js menangani sidebar mobile');

        $waListPage = get($waListUrl);
        eq(200, $waListPage['status'], 'Admin GET daftar penerima notifikasi = 200');
        ok(str_contains($waListPage['body'], 'Manajemen Penerima Notifikasi'), 'Daftar penerima tampil normal');
        ok(
            str_contains($waListPage['body'], 'href="' . APPBASE . $waEditUrl . '"'),
            'Daftar penerima menyediakan link Edit ke halaman edit'
        );

        $waDirect = get($waEditUrl);
        eq(200, $waDirect['status'], 'URL edit penerima dapat diakses langsung = 200');
        ok(
            preg_match('#<a[^>]+href="[^"]*' . preg_quote($waListUrl, '#') . '"[^>]*>Kembali ke Manajemen Notifikasi</a>#', $waDirect['body']) === 1,
            'Tombol kembali tetap benar saat URL edit dibuka langsung'
        );

        $waNewNumber = '628111222333';
        $waSave = post('/pengaturan/whatsapp/penerima/' . $waUserId, [
            '_token' => token($waPage['body']),
            'whatsapp_number' => $waNewNumber,
            'is_enabled' => '1',
            'types' => ['LOW_STOCK' => '1', 'DAILY_REPORT' => '1'],
        ]);
        eq(302, $waSave['status'], 'Admin menyimpan nomor WhatsApp = 302');
        ok(
            str_contains((string) $waSave['location'], '/pengaturan/whatsapp/penerima/' . $waUserId),
            'Redirect setelah simpan kembali ke halaman edit penerima (bukan Gateway)'
        );
        ok(
            !str_ends_with(rtrim((string) $waSave['location'], '/'), '/pengaturan/whatsapp'),
            'Redirect setelah simpan bukan ke WhatsApp Gateway'
        );

        $waAfterSave = follow((string) $waSave['location']);
        eq(200, $waAfterSave['status'], 'Halaman edit penerima termuat setelah simpan = 200');
        ok(str_contains($waAfterSave['body'], 'Preferensi penerima disimpan.'), 'Notifikasi berhasil simpan tampil');
        ok(str_contains($waAfterSave['body'], $waNewNumber), 'Nomor terbaru tampil setelah simpan');
        ok(str_contains($waAfterSave['body'], 'Belum Diverifikasi'), 'Status verifikasi reset setelah nomor berubah');

        eq($waNewNumber, (string) scalar('SELECT whatsapp_number FROM users WHERE id = ?', [$waUserId]), 'Nomor baru tersimpan di database');
        eq(0, (int) scalar('SELECT whatsapp_verified FROM users WHERE id = ?', [$waUserId]), 'Nomor baru belum terverifikasi di database');
        eq(null, scalar('SELECT whatsapp_pending_number FROM users WHERE id = ?', [$waUserId]), 'Simpan nomor tidak memicu pengiriman OTP');
        eq(null, scalar('SELECT whatsapp_otp_last_sent_at FROM users WHERE id = ?', [$waUserId]), 'Tidak ada OTP terkirim saat menyimpan');
        $waPrefsJson = json_decode((string) scalar('SELECT preferences_json FROM whatsapp_user_preferences WHERE user_id = ?', [$waUserId]), true);
        ok(is_array($waPrefsJson) && !empty($waPrefsJson['LOW_STOCK']) && empty($waPrefsJson['PO_SUBMIT']), 'Preferensi notifikasi tersimpan');

        // Refresh halaman (GET) tidak boleh mengirim atau membuat OTP baru.
        $waRefresh = get('/pengaturan/whatsapp/penerima/' . $waUserId);
        eq(200, $waRefresh['status'], 'Refresh halaman edit penerima = 200');
        eq(null, scalar('SELECT whatsapp_otp_last_sent_at FROM users WHERE id = ?', [$waUserId]), 'Refresh halaman tidak mengirim ulang OTP');
        eq(null, scalar('SELECT whatsapp_pending_number FROM users WHERE id = ?', [$waUserId]), 'Refresh halaman tidak membuat OTP pending');

        eq(200, get('/pengaturan/whatsapp')['status'], 'Halaman WhatsApp Gateway tetap dapat diakses = 200');
    } finally {
        if (is_array($waUserSnapshot) && $waUserSnapshot !== []) {
            $pdo->prepare(
                'UPDATE users SET whatsapp_number = ?, whatsapp_verified = ?, whatsapp_pending_number = ?,
                                  whatsapp_otp_hash = ?, whatsapp_otp_expires_at = ?, whatsapp_otp_attempts = ?,
                                  whatsapp_otp_last_sent_at = ? WHERE id = ?'
            )->execute([
                $waUserSnapshot['whatsapp_number'] ?? null,
                (int) ($waUserSnapshot['whatsapp_verified'] ?? 0),
                $waUserSnapshot['whatsapp_pending_number'] ?? null,
                $waUserSnapshot['whatsapp_otp_hash'] ?? null,
                $waUserSnapshot['whatsapp_otp_expires_at'] ?? null,
                (int) ($waUserSnapshot['whatsapp_otp_attempts'] ?? 0),
                $waUserSnapshot['whatsapp_otp_last_sent_at'] ?? null,
                $waUserId,
            ]);
        }
        if (is_array($waPrefSnapshot)) {
            $pdo->prepare(
                'INSERT INTO whatsapp_user_preferences (user_id, is_enabled, preferences_json) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), preferences_json = VALUES(preferences_json)'
            )->execute([$waUserId, (int) $waPrefSnapshot['is_enabled'], $waPrefSnapshot['preferences_json']]);
        } else {
            $pdo->prepare('DELETE FROM whatsapp_user_preferences WHERE user_id = ?')->execute([$waUserId]);
        }
    }

    $backupPage = get('/pengaturan/backup');
    eq(200, $backupPage['status'], 'Admin GET /pengaturan/backup = 200');
    ok(str_contains($backupPage['body'], 'Backup &amp; Restore Database'), 'Halaman backup memuat judul');
    ok(str_contains($backupPage['body'], $testName), 'Halaman backup menampilkan nama database uji');
    ok(str_contains($backupPage['body'], 'Khusus Admin'), 'Halaman backup menandai akses Admin');
    ok(str_contains($backupPage['body'], 'Buat Backup Sekarang'), 'Tombol buat backup tampil');
    ok(stripos($backupPage['head'], 'X-Content-Type-Options: nosniff') !== false, 'Header nosniff dikirim');
    ok(stripos($backupPage['head'], 'X-Frame-Options: SAMEORIGIN') !== false, 'Header anti-clickjacking dikirim');
    ok(stripos($backupPage['head'], 'Content-Security-Policy:') !== false, 'Header CSP dikirim');

    eq(403, post('/pengaturan/backup', ['_token' => 'salah'])['status'], 'Buat backup dengan CSRF salah = 403');

    $createBackup = post('/pengaturan/backup', ['_token' => token($backupPage['body'])]);
    eq(302, $createBackup['status'], 'Admin membuat backup = 302');
    $createBackupPage = follow($createBackup['location']);
    ok(str_contains($createBackupPage['body'], 'Backup berhasil dibuat'), 'Konfirmasi backup berhasil tampil');

    $backupRow = rows('SELECT * FROM backups ORDER BY id DESC LIMIT 1')[0] ?? [];
    $backupId = (int) ($backupRow['id'] ?? 0);
    ok($backupId > $backupMaxId, 'Metadata backup baru tersimpan');
    eq(64, strlen((string) ($backupRow['checksum_sha256'] ?? '')), 'Backup HTTP memiliki checksum SHA-256');
    eq($testName, (string) ($backupRow['database_name'] ?? ''), 'Backup HTTP mencatat database uji');
    $backupFilePath = $base . '/storage/backups/' . basename((string) ($backupRow['filepath'] ?? ''));
    ok(is_file($backupFilePath), 'Berkas backup HTTP ada di storage/backups');
    ok(!str_contains($createBackupPage['body'], $backupFilePath), 'Path internal backup tidak ditampilkan');
    eq(
        1,
        (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'BACKUP_CREATE' AND entity_id = ?", [(string) $backupId]),
        'Pembuatan backup masuk Audit Log'
    );

    $download = get('/pengaturan/backup/' . $backupId . '/unduh');
    eq(200, $download['status'], 'Admin mengunduh backup = 200');
    ok(str_contains($download['ctype'], 'application/sql'), 'Unduhan backup berjenis SQL');
    ok(
        str_starts_with($download['body'], '-- MariaDB dump') || str_starts_with($download['body'], '-- MySQL dump'),
        'Isi unduhan adalah dump database yang valid'
    );
    ok($download['bytes'] > 1000, 'Berkas backup tidak kosong');
    eq(
        1,
        (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'BACKUP_DOWNLOAD' AND entity_id = ?", [(string) $backupId]),
        'Unduhan backup masuk Audit Log'
    );

    // Restore ditolak bila password/konfirmasi salah; database TIDAK berubah.
    $restorePage = get('/pengaturan/backup');
    $badPassword = post('/pengaturan/backup/' . $backupId . '/restore', [
        '_token'       => token($restorePage['body']),
        'password'     => 'PasswordSalah#9',
        'confirmation' => 'PULIHKAN',
    ]);
    eq(302, $badPassword['status'], 'Restore dengan password salah = 302');
    $badPasswordPage = follow($badPassword['location']);
    ok(str_contains($badPasswordPage['body'], 'Password Admin salah'), 'Pesan password restore salah tampil');
    eq(null, scalar('SELECT restored_at FROM backups WHERE id = ?', [$backupId]), 'Restore password salah tidak mengubah database');

    $restorePage = get('/pengaturan/backup');
    $badConfirmation = post('/pengaturan/backup/' . $backupId . '/restore', [
        '_token'       => token($restorePage['body']),
        'password'     => $accounts['http_admin'],
        'confirmation' => 'salah',
    ]);
    eq(302, $badConfirmation['status'], 'Restore dengan konfirmasi salah = 302');
    $badConfirmationPage = follow($badConfirmation['location']);
    ok(str_contains($badConfirmationPage['body'], 'Ketik PULIHKAN'), 'Pesan konfirmasi restore tampil');
    eq(null, scalar('SELECT restored_at FROM backups WHERE id = ?', [$backupId]), 'Restore konfirmasi salah tidak mengubah database');

    $auditPage = get('/audit-log');
    eq(200, $auditPage['status'], 'Admin GET /audit-log = 200');
    ok(str_contains($auditPage['body'], 'Ekspor CSV'), 'Tombol ekspor Audit Log tampil');
    ok(str_contains($auditPage['body'], 'Jenis entitas'), 'Filter jenis entitas tampil');
    ok(str_contains($auditPage['body'], 'Kata kunci'), 'Filter kata kunci tampil');
    ok(str_contains($auditPage['body'], 'Backup'), 'Filter aktivitas memuat aksi backup');

    $csv = get('/audit-log/export?activity=BACKUP_CREATE');
    eq(200, $csv['status'], 'Admin mengekspor Audit Log = 200');
    ok(str_contains($csv['ctype'], 'text/csv'), 'Ekspor Audit Log berjenis CSV');
    ok(str_starts_with($csv['body'], "\xEF\xBB\xBF"), 'CSV Audit Log memakai BOM UTF-8');
    ok(str_contains($csv['body'], 'ID,Waktu,Pengguna,Role,Aktivitas'), 'CSV Audit Log memiliki header');
    ok(str_contains($csv['body'], 'BACKUP_CREATE'), 'CSV Audit Log memuat data backup');
    ok(
        (int) scalar("SELECT COUNT(*) FROM audit_logs WHERE activity = 'AUDIT_LOG_EXPORT'") >= 1,
        'Ekspor Audit Log dicatat di Audit Log'
    );

    // Logo bengkel: unggah, sajikan lewat route ber-otorisasi, tampil di dokumen.
    $settingsPage = get('/pengaturan');
    eq(200, $settingsPage['status'], 'Admin GET /pengaturan = 200');
    ok(str_contains($settingsPage['body'], 'Logo bengkel'), 'Form logo bengkel tampil');
    $settingsMap = [];
    foreach (rows('SELECT setting_key, setting_value FROM app_settings') as $setting) {
        $settingsMap[(string) $setting['setting_key']] = (string) ($setting['setting_value'] ?? '');
    }
    $settingsFields = [
        '_token'                => token($settingsPage['body']),
        'workshop_name'         => $settingsMap['workshop_name'] ?? 'Bengkel Uji',
        'workshop_address'      => $settingsMap['workshop_address'] ?? '',
        'workshop_phone'        => $settingsMap['workshop_phone'] ?? '',
        'workshop_email'        => $settingsMap['workshop_email'] ?? '',
        'workshop_note'         => $settingsMap['workshop_note'] ?? '',
        'fast_moving_threshold' => $settingsMap['fast_moving_threshold'] ?? '10',
        'slow_moving_threshold' => $settingsMap['slow_moving_threshold'] ?? '2',
        'moving_period_days'    => $settingsMap['moving_period_days'] ?? '30',
    ];
    $uploadLogo = post_multipart('/pengaturan', $settingsFields + [
        'workshop_logo' => new CURLFile($pngPath, 'image/png', 'logo-http.png'),
    ]);
    eq(302, $uploadLogo['status'], 'Admin mengunggah logo = 302');
    $uploadLogoPage = follow($uploadLogo['location']);
    ok(str_contains($uploadLogoPage['body'], 'Pengaturan disimpan'), 'Konfirmasi unggah logo tampil');
    $logoPath = (string) scalar("SELECT setting_value FROM app_settings WHERE setting_key = 'workshop_logo'");
    ok(str_starts_with($logoPath, 'logos/'), 'Path logo tersimpan di storage/documents');
    ok(str_contains($uploadLogoPage['body'], 'pengaturan/logo'), 'Pratinjau logo memakai route ber-otorisasi');

    $logoResponse = get('/pengaturan/logo');
    eq(200, $logoResponse['status'], 'Logo dapat diakses pengguna login = 200');
    ok(str_contains($logoResponse['ctype'], 'image/png'), 'Logo disajikan dengan MIME image/png');
    eq(strlen($png), $logoResponse['bytes'], 'Isi logo sesuai berkas yang diunggah');

    $documentWithLogo = get('/transaksi/masuk/' . $receiptId . '/cetak');
    eq(200, $documentWithLogo['status'], 'Dokumen penerimaan dengan logo dapat dicetak = 200');
    ok(str_contains($documentWithLogo['body'], 'class="doc-logo"'), 'Kop dokumen menampilkan logo bengkel');
    ok(str_contains($documentWithLogo['body'], 'pengaturan/logo'), 'Logo dokumen memakai route ber-otorisasi');

    $settingsPage2 = get('/pengaturan');
    $removeFields = $settingsFields;
    $removeFields['_token'] = token($settingsPage2['body']);
    $removeFields['remove_logo'] = '1';
    $removeLogo = post('/pengaturan', $removeFields);
    eq(302, $removeLogo['status'], 'Admin menghapus logo = 302');
    $removeLogoPage = follow($removeLogo['location']);
    ok(str_contains($removeLogoPage['body'], 'Pengaturan disimpan'), 'Konfirmasi hapus logo tampil');
    eq('', scalar("SELECT setting_value FROM app_settings WHERE setting_key = 'workshop_logo'"), 'Logo dihapus dari pengaturan');
    eq(404, get('/pengaturan/logo')['status'], 'Logo yang dihapus tidak lagi dapat diakses');
    $documentWithoutLogo = get('/transaksi/masuk/' . $receiptId . '/cetak');
    ok(!str_contains($documentWithoutLogo['body'], 'class="doc-logo"'), 'Dokumen tidak menampilkan logo setelah dihapus');
} catch (Throwable $e) {
    $gagal++;
    $failures[] = 'Eksepsi: ' . $e->getMessage();
    echo "\n[GAGAL] Eksepsi: " . $e->getMessage() . "\n";
} finally {
    // Pulihkan .env LEBIH DULU agar permintaan berikutnya kembali ke produksi.
    $restore();

    // Hentikan stub Gemini & pulihkan pengaturan chatbot DB uji.
    if (is_array($geminiStub)) {
        t_gemini_stub_cleanup($geminiStub);
    }
    if (is_array($chatbotSnapshot)) {
        $pdo->prepare(
            'UPDATE chatbot_settings
                SET is_enabled = 0, api_key_encrypted = NULL, api_key_masked = NULL,
                    model = ?, system_prompt = ?, topic_limits = ?, answer_style = ?,
                    usage_limit_daily = NULL, usage_limit_monthly = NULL,
                    provider = ?, api_base = NULL
              WHERE id = 1'
        )->execute([
            (string) ($chatbotSnapshot['model'] ?? 'gemini-3.5-flash-lite'),
            $chatbotSnapshot['system_prompt'] ?? null,
            $chatbotSnapshot['topic_limits'] ?? null,
            (string) ($chatbotSnapshot['answer_style'] ?? 'ringkas'),
            (string) ($chatbotSnapshot['provider'] ?? 'gemini'),
        ]);
    }
    try {
        $pdo->exec('DELETE FROM chatbot_providers');
        if ($chatProviderSnapshot !== []) {
            $insertProvider = $pdo->prepare(
                'INSERT INTO chatbot_providers (provider, api_key_encrypted, api_key_masked, model, api_base, updated_by)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            foreach ($chatProviderSnapshot as $providerSnapshotRow) {
                $insertProvider->execute([
                    $providerSnapshotRow['provider'] ?? null,
                    $providerSnapshotRow['api_key_encrypted'] ?? null,
                    $providerSnapshotRow['api_key_masked'] ?? null,
                    $providerSnapshotRow['model'] ?? '',
                    $providerSnapshotRow['api_base'] ?? null,
                ]);
            }
        }
    } catch (Throwable) {
        // Skema multi-provider belum ada; tidak ada yang perlu dipulihkan.
    }
    $pdo->prepare('DELETE FROM chatbot_usage_logs WHERE id > ?')->execute([$chatUsageMaxId]);

    // Fase 9 — bersihkan backup/logo uji dan pulihkan pengaturan aplikasi.
    foreach (rows('SELECT id, filepath FROM backups WHERE id > ?', [$backupMaxId]) as $backupRowItem) {
        $testBackupFile = $base . '/storage/backups/' . basename((string) ($backupRowItem['filepath'] ?? ''));
        if (is_file($testBackupFile)) {
            @unlink($testBackupFile);
        }
    }
    $pdo->prepare('DELETE FROM backups WHERE id > ?')->execute([$backupMaxId]);
    foreach (glob($base . '/storage/backups/*') ?: [] as $backupFileItem) {
        if (!isset($backupFilesBefore[basename($backupFileItem)]) && is_file($backupFileItem)) {
            @unlink($backupFileItem);
        }
    }

    if (is_array($appSettingsSnapshot)) {
        $pdo->exec('DELETE FROM app_settings');
        $insertSetting = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($appSettingsSnapshot as $settingRow) {
            $insertSetting->execute([
                (string) $settingRow['setting_key'],
                $settingRow['setting_value'],
                $settingRow['updated_by'],
                (string) $settingRow['updated_at'],
            ]);
        }
    }

    if (is_dir($logoRoot)) {
        $logoIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($logoRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($logoIterator as $logoFileItem) {
            if ($logoFileItem->isFile() && !isset($logoFilesBefore[$logoFileItem->getPathname()])) {
                @unlink($logoFileItem->getPathname());
            }
        }
    }

    if ($uploadedPath !== null && is_file($uploadedPath) && !$keepData) {
        @unlink($uploadedPath);
    }
    if (is_file($pngPath)) {
        @unlink($pngPath);
    }
    if ($jar !== null) {
        @unlink((string) $jar);
    }

    Env::load($envFile);
    $after = require $base . '/config/config.php';
    $active = (string) $after['db']['name'];
    ok($active === $prodName, ".env dipulihkan ke DB produksi ({$active})");
    ok(!is_file($backupFile), 'Berkas backup .env sudah dibersihkan');
}

echo "\n============================================================\n";
echo "HASIL HTTP (DB UJI): {$lulus} lulus, {$gagal} gagal\n";
if ($failures !== []) {
    echo "\nRincian kegagalan:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
}

exit($gagal === 0 ? 0 : 1);
