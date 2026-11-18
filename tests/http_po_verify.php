<?php
declare(strict_types=1);

/**
 * Verifikasi HTTP ALUR PURCHASE ORDER (Skenario A-E) — DB UJI SAJA.
 *
 * Sama seperti http_verify.php: `.env` ditukar sementara ke DB_TEST_NAME,
 * lalu SELALU dipulihkan (try/finally + shutdown handler). DB produksi
 * tidak pernah disentuh.
 *
 * Skenario:
 *   A. Partman membuat draft (Draf) lalu mengajukan (Menunggu Persetujuan).
 *   B. Kepala Bengkel melihat pengajuan, menyetujui, dan menolak (alasan wajib).
 *   C. Admin membayar PO yang disetujui; PO belum disetujui tidak bisa dibayar.
 *   D. Admin menerima barang; stok hanya bertambah saat penerimaan.
 *   E. RBAC: manipulasi request langsung ditolak (403) dan status tidak berubah.
 *
 * Pemakaian: php tests\http_po_verify.php
 */

$base = dirname(__DIR__);
require $base . '/app/autoload.php';

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
$backupFile = $base . '/.env.http_po_bak';

$lulus = 0;
$gagal = 0;
$failures = [];

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
    $cond = (is_float($expected) || is_float($actual))
        ? abs((float) $expected - (float) $actual) < 0.005
        : $expected === $actual;
    ok($cond, $msg . ' (diharapkan ' . var_export($expected, true) . ', aktual ' . var_export($actual, true) . ')');
}

/** Satu cookie jar per sesi/role. */
$jars = [];
function session_as(string $role): void
{
    global $jars, $currentJar;
    if (!isset($jars[$role])) {
        $jars[$role] = tempnam(sys_get_temp_dir(), 'spmpo_');
    }
    $currentJar = $jars[$role];
}

function http(string $method, string $path, ?array $post = null): array
{
    global $currentJar;
    $ch = curl_init(HOST . APPBASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => (string) $currentJar,
        CURLOPT_COOKIEFILE     => (string) $currentJar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
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
    ];
}

function get(string $path): array
{
    return http('GET', $path);
}

function post(string $path, array $data): array
{
    return http('POST', $path, $data);
}

function token(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function id_from_location(?string $location): int
{
    if ($location !== null && preg_match('#/pembelian/(\d+)(?:\?|$)#', $location, $m) === 1) {
        return (int) $m[1];
    }
    return 0;
}

function login(string $role, string $username, string $password): bool
{
    session_as($role);
    $page = get('/login');
    $res = post('/login', [
        '_token'   => token($page['body']),
        'username' => $username,
        'password' => $password,
    ]);
    return $res['status'] === 302 && get('/dashboard')['status'] === 200;
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
if ($testName === '' || !str_ends_with($testName, '_test') || $testName === $prodName) {
    fwrite(STDERR, "DB uji tidak aman — dibatalkan.\n");
    exit(1);
}

$swapped = preg_replace('/^DB_NAME=.*$/m', 'DB_NAME=' . $testName, $original, 1, $count);
if ($swapped === null || $count !== 1) {
    fwrite(STDERR, "Baris DB_NAME tidak ditemukan.\n");
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

Env::load($envFile);
$config = require $base . '/config/config.php';
$db = $config['db'];
if ((string) $db['name'] !== $testName) {
    $restore();
    fwrite(STDERR, "Gagal mengarahkan aplikasi ke DB uji.\n");
    exit(1);
}

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
(new MigrationService($db, $base . '/database/migrations'))->run();

Database::configure($db);
$pdo = Database::pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $testName) {
    $restore();
    fwrite(STDERR, "Koneksi tidak berada di DB uji.\n");
    exit(1);
}

echo "SmartPartMan - Verifikasi HTTP PO (DB UJI)\n";
echo "DB uji: {$testName} | DB produksi TIDAK disentuh\n";
$marker = 'POHTTP-' . strtoupper(bin2hex(random_bytes(3)));
echo "Penanda: {$marker}\n";
flush();

try {
    $roleIds = [];
    foreach ($pdo->query('SELECT id, code FROM roles') as $row) {
        $roleIds[(string) $row['code']] = (int) $row['id'];
    }

    $accounts = [];
    foreach ([
        'partman'       => 'po_partman',
        'kepala_bengkel' => 'po_kepala',
        'admin'         => 'po_admin',
    ] as $role => $username) {
        $password = 'Po#' . bin2hex(random_bytes(8));
        $st = $pdo->prepare(
            'INSERT INTO users (username, full_name, password_hash, role_id, is_active)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_id = VALUES(role_id),
                                     is_active = 1, failed_attempts = 0, locked_until = NULL'
        );
        $st->execute([$username, 'Uji PO ' . $role, password_hash($password, PASSWORD_BCRYPT), $roleIds[$role]]);
        $uid = (int) $pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($username))->fetchColumn();
        $accounts[$role] = ['user' => $username, 'pass' => $password, 'id' => $uid];
    }

    // Fixture kategori, supplier, dan sparepart unik.
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (?, ?, 1)')
        ->execute([$marker . '-CAT', 'Kategori ' . $marker]);
    $categoryId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (?, ?, 1)')
        ->execute([$marker . '-SUP', 'Supplier ' . $marker]);
    $supplierId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO spareparts (code, name, category_id, unit, purchase_price, selling_price, stock, min_stock, supplier_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $marker . '-PART', 'Sparepart ' . $marker, $categoryId, 'pcs',
        15000, 20000, 0, 2, $supplierId,
    ]);
    $sparepartId = (int) $pdo->lastInsertId();

    $statusOf = static function (int $poId) use ($pdo): string {
        $st = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $st->execute([$poId]);
        return (string) ($st->fetchColumn() ?: '');
    };
    $paymentOf = static function (int $poId) use ($pdo): string {
        $st = $pdo->prepare('SELECT payment_status FROM purchase_orders WHERE id = ?');
        $st->execute([$poId]);
        return (string) ($st->fetchColumn() ?: '');
    };
    $stockOf = static function () use ($pdo, $sparepartId): float {
        $st = $pdo->prepare('SELECT stock FROM spareparts WHERE id = ?');
        $st->execute([$sparepartId]);
        return (float) $st->fetchColumn();
    };

    // ============================================================
    // LOGIN SEMUA ROLE
    // ============================================================
    echo "\n== Login role ==\n";
    ok(login('partman', $accounts['partman']['user'], $accounts['partman']['pass']), 'Login Partman berhasil');
    ok(login('kepala', $accounts['kepala_bengkel']['user'], $accounts['kepala_bengkel']['pass']), 'Login Kepala Bengkel berhasil');
    ok(login('admin', $accounts['admin']['user'], $accounts['admin']['pass']), 'Login Admin berhasil');

    // ============================================================
    // SKENARIO A — PARTMAN MEMBUAT & MENGAJUKAN PO
    // ============================================================
    echo "\n== Skenario A: Partman membuat & mengajukan PO ==\n";
    session_as('partman');
    $form = get('/pembelian/tambah');
    eq(200, $form['status'], 'Partman GET /pembelian/tambah = 200');
    $create = post('/pembelian', [
        '_token'            => token($form['body']),
        'supplier_id'       => $supplierId,
        'note'              => 'Pengajuan uji ' . $marker,
        'item_sparepart_id' => [$sparepartId],
        'item_quantity'     => [5],
        'item_unit_price'   => [15000],
    ]);
    eq(302, $create['status'], 'POST /pembelian (simpan draft) = 302');
    $poA = id_from_location($create['location']);
    ok($poA > 0, 'PO draft tersimpan dan diarahkan ke detail (id=' . $poA . ')');
    eq('DRAFT', $statusOf($poA), 'Status PO baru = DRAFT (Draf)');
    $poNumberA = (string) $pdo->query('SELECT po_number FROM purchase_orders WHERE id = ' . $poA)->fetchColumn();

    $listDraft = get('/pembelian?q=' . urlencode($poNumberA));
    ok(str_contains($listDraft['body'], '>Ajukan<'), 'Daftar PO menampilkan tombol "Ajukan" untuk draft milik Partman');

    $show = get('/pembelian/' . $poA);
    eq(200, $show['status'], 'Partman GET detail PO = 200');
    ok(str_contains($show['body'], 'Ajukan PO'), 'Tombol "Ajukan PO" tampil untuk Partman pembuat');
    ok(!str_contains($show['body'], 'Setujui PO'), 'Tombol "Setujui PO" TIDAK tampil untuk Partman');

    $submit = post('/pembelian/' . $poA . '/ajukan', ['_token' => token($show['body'])]);
    eq(302, $submit['status'], 'POST /pembelian/{id}/ajukan = 302');
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poA), 'Status setelah diajukan = MENUNGGU_PERSETUJUAN');

    $afterSubmit = get('/pembelian');
    ok(str_contains($afterSubmit['body'], 'Menunggu persetujuan'), 'Daftar PO menampilkan badge "Menunggu persetujuan"');
    ok(!str_contains($afterSubmit['body'], '>Draf<'), 'Tidak ada badge Draf untuk PO yang sudah diajukan');

    // PO kedua untuk uji penolakan.
    $form2 = get('/pembelian/tambah');
    $create2 = post('/pembelian', [
        '_token'            => token($form2['body']),
        'supplier_id'       => $supplierId,
        'note'              => 'Penolakan uji ' . $marker,
        'item_sparepart_id' => [$sparepartId],
        'item_quantity'     => [3],
        'item_unit_price'   => [15000],
    ]);
    $poB = id_from_location($create2['location']);
    $showB = get('/pembelian/' . $poB);
    post('/pembelian/' . $poB . '/ajukan', ['_token' => token($showB['body'])]);
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poB), 'PO kedua berhasil diajukan');

    // ============================================================
    // SKENARIO B — KEPALA BENGKEL
    // ============================================================
    echo "\n== Skenario B: Kepala Bengkel menyetujui & menolak ==\n";
    session_as('kepala');
    $list = get('/pembelian');
    eq(200, $list['status'], 'Kepala GET /pembelian = 200');
    $poNumberA = (string) $pdo->query('SELECT po_number FROM purchase_orders WHERE id = ' . $poA)->fetchColumn();
    ok(str_contains($list['body'], $poNumberA), 'PO yang diajukan Partman muncul di daftar Kepala Bengkel');
    $listPending = get('/pembelian?status=MENUNGGU_PERSETUJUAN&q=' . urlencode($poNumberA));
    ok(str_contains($listPending['body'], '>Setujui<') && str_contains($listPending['body'], 'Tolak'),
        'Daftar PO menampilkan tombol "Setujui" dan "Tolak" untuk Kepala Bengkel');

    $showA = get('/pembelian/' . $poA);
    eq(200, $showA['status'], 'Kepala GET detail PO = 200');
    ok(str_contains($showA['body'], 'Setujui PO') && str_contains($showA['body'], 'Tolak PO'),
        'Tombol "Setujui PO" dan "Tolak PO" tampil untuk Kepala Bengkel');
    ok(str_contains($showA['body'], 'Uji PO partman'), 'Detail memuat identitas (nama) pengaju');

    $approve = post('/pembelian/' . $poA . '/setujui', ['_token' => token($showA['body'])]);
    eq(302, $approve['status'], 'POST /pembelian/{id}/setujui = 302');
    eq('DISETUJUI', $statusOf($poA), 'Status setelah disetujui = DISETUJUI');
    $approvedBy = (int) $pdo->query('SELECT approved_by FROM purchase_orders WHERE id = ' . $poA)->fetchColumn();
    eq($accounts['kepala_bengkel']['id'], $approvedBy, 'Identitas Kepala Bengkel penyetuju tersimpan');
    $approvedAt = $pdo->query('SELECT approved_at FROM purchase_orders WHERE id = ' . $poA)->fetchColumn();
    ok(!empty($approvedAt), 'Tanggal/waktu persetujuan tersimpan');

    $showB = get('/pembelian/' . $poB);
    $reject = post('/pembelian/' . $poB . '/tolak', [
        '_token' => token($showB['body']),
        'reason' => 'Harga terlalu tinggi, ajukan ulang',
    ]);
    eq(302, $reject['status'], 'POST /pembelian/{id}/tolak = 302');
    eq('DITOLAK', $statusOf($poB), 'Status setelah ditolak = DITOLAK');
    $reason = (string) $pdo->query('SELECT rejected_reason FROM purchase_orders WHERE id = ' . $poB)->fetchColumn();
    ok(str_contains($reason, 'Harga terlalu tinggi'), 'Alasan penolakan tersimpan');
    $showBAfter = get('/pembelian/' . $poB);
    ok(str_contains($showBAfter['body'], 'Uji PO kepala_bengkel') && str_contains($showBAfter['body'], 'Harga terlalu tinggi'),
        'Detail menampilkan identitas penolak dan alasan penolakan');

    // Tolak tanpa alasan harus ditolak.
    session_as('partman');
    $form3 = get('/pembelian/tambah');
    $create3 = post('/pembelian', [
        '_token'            => token($form3['body']),
        'supplier_id'       => $supplierId,
        'note'              => 'Tanpa alasan ' . $marker,
        'item_sparepart_id' => [$sparepartId],
        'item_quantity'     => [1],
        'item_unit_price'   => [15000],
    ]);
    $poC = id_from_location($create3['location']);
    $showC = get('/pembelian/' . $poC);
    post('/pembelian/' . $poC . '/ajukan', ['_token' => token($showC['body'])]);

    session_as('kepala');
    $showC = get('/pembelian/' . $poC);
    $badReject = post('/pembelian/' . $poC . '/tolak', ['_token' => token($showC['body']), 'reason' => '']);
    eq(302, $badReject['status'], 'Tolak tanpa alasan = 302 (kembali dengan pesan)');
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poC), 'Tolak tanpa alasan tidak mengubah status');

    // ============================================================
    // SKENARIO C — ADMIN PEMBAYARAN
    // ============================================================
    echo "\n== Skenario C: Admin membayar PO disetujui ==\n";
    $stockAfterApproval = $stockOf();
    session_as('admin');
    $showAdmin = get('/pembelian/' . $poA);
    ok(str_contains($showAdmin['body'], 'Simpan Status Pembayaran'), 'Form pembayaran tampil untuk PO disetujui');
    $listAdmin = get('/pembelian?q=' . urlencode($poNumberA));
    ok(str_contains($listAdmin['body'], '>Bayar<') && str_contains($listAdmin['body'], '>Terima<'),
        'Daftar PO menampilkan tombol "Bayar" dan "Terima" untuk Admin');
    $pay = post('/pembelian/' . $poA . '/pembayaran', [
        '_token'         => token($showAdmin['body']),
        'payment_status' => 'LUNAS',
        'payment_note'   => 'Dibayar penuh via transfer',
    ]);
    eq(302, $pay['status'], 'POST /pembelian/{id}/pembayaran = 302');
    eq('LUNAS', $paymentOf($poA), 'Status pembayaran = LUNAS');
    eq('DISETUJUI', $statusOf($poA), 'Status persetujuan tetap terpisah (DISETUJUI)');
    eq($stockAfterApproval, $stockOf(), 'Persetujuan & pembayaran TIDAK menambah stok');

    // PO menunggu persetujuan tidak bisa dibayar.
    $showCAdmin = get('/pembelian/' . $poC);
    ok(!str_contains($showCAdmin['body'], 'Simpan Status Pembayaran'), 'Form pembayaran tidak tampil pada PO menunggu persetujuan');
    $badPay = post('/pembelian/' . $poC . '/pembayaran', [
        '_token'         => token($showCAdmin['body']),
        'payment_status' => 'LUNAS',
        'payment_note'   => 'Percobaan ilegal',
    ]);
    eq(302, $badPay['status'], 'POST bayar PO belum disetujui = 302 (ditolak)');
    eq('BELUM_DIBAYAR', $paymentOf($poC), 'Pembayaran PO belum disetujui tidak berubah');

    // ============================================================
    // SKENARIO D — ADMIN PENERIMAAN BARANG
    // ============================================================
    echo "\n== Skenario D: Admin menerima barang & stok bertambah ==\n";
    $stockBefore = $stockOf();
    $showAAdmin = get('/pembelian/' . $poA);
    ok(str_contains($showAAdmin['body'], 'Terima Barang dari PO'), 'Tombol penerimaan tampil untuk PO disetujui');
    $receive = post('/pembelian/' . $poA . '/terima-otomatis', ['_token' => token($showAAdmin['body'])]);
    eq(302, $receive['status'], 'POST /pembelian/{id}/terima-otomatis = 302');
    eq($stockBefore + 5.0, $stockOf(), 'Stok bertambah tepat 5 sesuai jumlah diterima');
    eq('SELESAI_DITERIMA', $statusOf($poA), 'Status PO = SELESAI_DITERIMA');
    $receivedQty = (float) $pdo->query(
        'SELECT received_qty FROM purchase_order_items WHERE po_id = ' . $poA
    )->fetchColumn();
    eq(5.0, $receivedQty, 'received_qty item = 5');
    $movementCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ' . $sparepartId
        . " AND movement_type = 'MASUK'"
    )->fetchColumn();
    ok($movementCount >= 1, 'Riwayat stok (MASUK) tercatat untuk penerimaan');

    // ============================================================
    // SKENARIO E — RBAC: REQUEST LANGSUNG
    // ============================================================
    echo "\n== Skenario E: RBAC request langsung ==\n";
    // Siapkan PO pending baru untuk uji manipulasi.
    session_as('partman');
    $formD = get('/pembelian/tambah');
    $createD = post('/pembelian', [
        '_token'            => token($formD['body']),
        'supplier_id'       => $supplierId,
        'note'              => 'RBAC ' . $marker,
        'item_sparepart_id' => [$sparepartId],
        'item_quantity'     => [2],
        'item_unit_price'   => [15000],
    ]);
    $poD = id_from_location($createD['location']);
    $showD = get('/pembelian/' . $poD);
    post('/pembelian/' . $poD . '/ajukan', ['_token' => token($showD['body'])]);
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poD), 'PO RBAC berstatus MENUNGGU_PERSETUJUAN');

    // Partman tidak boleh menyetujui/menolak.
    $showD = get('/pembelian/' . $poD);
    $r = post('/pembelian/' . $poD . '/setujui', ['_token' => token($showD['body'])]);
    eq(403, $r['status'], 'Partman POST setujui = 403');
    $r = post('/pembelian/' . $poD . '/tolak', ['_token' => token($showD['body']), 'reason' => 'x']);
    eq(403, $r['status'], 'Partman POST tolak = 403');
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poD), 'Status PO tidak berubah oleh request Partman');

    // Partman tidak boleh membayar/menerima.
    $r = post('/pembelian/' . $poD . '/pembayaran', ['_token' => token($showD['body']), 'payment_status' => 'LUNAS']);
    eq(403, $r['status'], 'Partman POST pembayaran = 403');
    $r = post('/pembelian/' . $poD . '/terima-otomatis', ['_token' => token($showD['body'])]);
    eq(403, $r['status'], 'Partman POST terima barang = 403');

    // Kepala tidak boleh membayar/menerima/menyetujui PO (hanya approve/reject).
    session_as('kepala');
    $showDK = get('/pembelian/' . $poD);
    $r = post('/pembelian/' . $poD . '/pembayaran', ['_token' => token($showDK['body']), 'payment_status' => 'LUNAS']);
    eq(403, $r['status'], 'Kepala Bengkel POST pembayaran = 403');
    $r = post('/pembelian/' . $poD . '/terima-otomatis', ['_token' => token($showDK['body'])]);
    eq(403, $r['status'], 'Kepala Bengkel POST terima barang = 403');

    // Admin tidak boleh menyetujui/menolak.
    session_as('admin');
    $showDA = get('/pembelian/' . $poD);
    $r = post('/pembelian/' . $poD . '/setujui', ['_token' => token($showDA['body'])]);
    eq(403, $r['status'], 'Admin POST setujui = 403');
    $r = post('/pembelian/' . $poD . '/tolak', ['_token' => token($showDA['body']), 'reason' => 'x']);
    eq(403, $r['status'], 'Admin POST tolak = 403');
    eq('MENUNGGU_PERSETUJUAN', $statusOf($poD), 'Status PO tetap tidak berubah');

    // Cakupan data: Partman hanya PO sendiri, Kepala hanya PO yang diajukan.
    $otherNumber = $marker . '-OTHER';
    $pdo->prepare(
        'INSERT INTO purchase_orders (po_number, supplier_id, status, payment_status, total_amount, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$otherNumber, $supplierId, 'DRAFT', 'BELUM_DIBAYAR', 15000, 'PO pengguna lain', $accounts['admin']['id']]);
    $otherPoId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
         VALUES (?, ?, ?, ?, 0)'
    )->execute([$otherPoId, $sparepartId, 1, 15000]);

    session_as('partman');
    $otherList = get('/pembelian?q=' . urlencode($otherNumber));
    ok(!str_contains($otherList['body'], 'pembelian/' . $otherPoId . '"'),
        'Partman tidak melihat PO milik pengguna lain di daftar');
    eq(403, get('/pembelian/' . $otherPoId)['status'], 'Partman GET detail PO milik orang lain = 403');
    eq(403, get('/laporan/po/' . $otherPoId . '/cetak')['status'], 'Partman cetak PO milik orang lain = 403');

    // Draf milik Partman tidak boleh dilihat Kepala Bengkel sebelum diajukan.
    $formE = get('/pembelian/tambah');
    $createE = post('/pembelian', [
        '_token'            => token($formE['body']),
        'supplier_id'       => $supplierId,
        'note'              => 'Draf belum diajukan ' . $marker,
        'item_sparepart_id' => [$sparepartId],
        'item_quantity'     => [1],
        'item_unit_price'   => [15000],
    ]);
    $poE = id_from_location($createE['location']);
    $poNumberE = (string) $pdo->query('SELECT po_number FROM purchase_orders WHERE id = ' . $poE)->fetchColumn();
    session_as('kepala');
    $kepalaList = get('/pembelian?q=' . urlencode($poNumberE));
    ok(!str_contains($kepalaList['body'], 'pembelian/' . $poE . '"'),
        'Kepala Bengkel tidak melihat draf yang belum diajukan di daftar');
    eq(403, get('/pembelian/' . $poE)['status'], 'Kepala Bengkel GET detail draf = 403');
    eq(403, get('/laporan/po/' . $poE . '/cetak')['status'], 'Kepala Bengkel cetak draf = 403');

    // Riwayat lengkap tercatat untuk PO A.
    $history = $pdo->query(
        "SELECT new_status FROM purchase_status_history WHERE document_type = 'PO' AND document_id = " . $poA
        . ' ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    $history = array_map('strval', $history);
    ok(in_array('DRAFT', $history, true) && in_array('MENUNGGU_PERSETUJUAN', $history, true)
        && in_array('DISETUJUI', $history, true) && in_array('SELESAI_DITERIMA', $history, true),
        'Riwayat persetujuan & penerimaan lengkap tercatat');
    $payHistory = (int) $pdo->query(
        'SELECT COUNT(*) FROM purchase_payment_history WHERE purchase_order_id = ' . $poA
    )->fetchColumn();
    ok($payHistory >= 1, 'Riwayat pembayaran tercatat');
} finally {
    $restore();
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "HASIL: {$lulus} lulus, {$gagal} gagal\n";
if ($gagal > 0) {
    echo "GAGAL:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
echo "Semua skenario PO LULUS.\n";
exit(0);
