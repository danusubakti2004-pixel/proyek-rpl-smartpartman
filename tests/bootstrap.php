<?php
declare(strict_types=1);

/**
 * Bootstrap pengujian SmartPartMan (tanpa framework eksternal).
 *
 * ATURAN: seluruh test HANYA memakai database uji (DB_TEST_NAME),
 * TIDAK PERNAH menyentuh database produksi.
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/autoload.php';

use App\Core\Database;
use App\Core\Env;

Env::load(BASE_PATH . '/.env');
$config = require BASE_PATH . '/config/config.php';

// Paksa test memakai database uji.
$testDbConfig = $config['db'];
$testDbConfig['name'] = $config['db']['test_name'];
Database::configure($testDbConfig);

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
$GLOBALS['t_failures'] = [];

function t_true(mixed $condition, string $message): void
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "    [OK] {$message}\n";
    } else {
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $message;
        echo "    [GAGAL] {$message}\n";
    }
}

function t_eq(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        $GLOBALS['t_pass']++;
        echo "    [OK] {$message}\n";
    } else {
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $message;
        echo "    [GAGAL] {$message} (diharapkan: " . var_export($expected, true)
            . ", aktual: " . var_export($actual, true) . ")\n";
    }
}

function t_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        $GLOBALS['t_pass']++;
        echo "    [OK] {$message}\n";
    } else {
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $message;
        echo "    [GAGAL] {$message} (tidak ditemukan: {$needle})\n";
    }
}

/** @param class-string<\Throwable> $exceptionClass */
function t_throws(callable $fn, string $exceptionClass, string $message): void
{
    try {
        $fn();
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $message;
        echo "    [GAGAL] {$message} (tidak ada exception, seharusnya {$exceptionClass})\n";
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            $GLOBALS['t_pass']++;
            echo "    [OK] {$message}\n";
        } else {
            $GLOBALS['t_fail']++;
            $GLOBALS['t_failures'][] = $message;
            echo "    [GAGAL] {$message} (exception salah jenis: " . get_class($e) . ")\n";
        }
    }
}

// ------------------------------------------------------------------
// Helper fixture (dipakai bersama oleh file test; awalan `t_` agar
// TIDAK ikut dikumpulkan runner sebagai fungsi test).
// ------------------------------------------------------------------

function t_pdo(): \PDO
{
    return Database::pdo();
}

/** ID role dari kode (mis. 'admin'). */
function t_role_id(string $code): int
{
    $stmt = Database::pdo()->prepare('SELECT id FROM roles WHERE code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new \RuntimeException('Role tidak ditemukan di DB uji: ' . $code);
    }
    return (int) $id;
}

/**
 * Siapkan pengguna fixture langsung di DB uji (tanpa lewat installer),
 * agar test tidak bergantung pada urutan file.
 */
function t_make_user(string $username, string $role, string $password, bool $active = true): int
{
    $pdo = Database::pdo();
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    $pdo->prepare(
        'INSERT INTO users (username, full_name, email, password_hash, role_id, is_active)
         VALUES (?, ?, NULL, ?, ?, ?)'
    )->execute([
        $username,
        'Uji ' . $username,
        password_hash($password, PASSWORD_DEFAULT),
        t_role_id($role),
        $active ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Bersihkan sesi login (Session::destroy() juga mengosongkan $_SESSION). */
function t_logout(): void
{
    \App\Core\Session::destroy();
    $_SESSION = [];
}

/** Password standar untuk akun fixture pengujian. */
function t_password(): string
{
    return 'Rahasia#2026';
}

/** Hash password pengguna (untuk verifikasi tanpa pernah menyimpannya plaintext). */
function t_user_hash(int $userId): string
{
    $stmt = Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (string) $stmt->fetchColumn();
}

/** Jumlah percobaan login (sukses/gagal, opsional) untuk username tertentu. */
function t_attempt_count(string $username, ?bool $success = null): int
{
    $sql = 'SELECT COUNT(*) FROM login_attempts WHERE username = ?';
    $params = [$username];
    if ($success !== null) {
        $sql .= ' AND success = ?';
        $params[] = $success ? 1 : 0;
    }
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Catatan audit terakhir untuk aktivitas (dan opsional entitas) tertentu. */
function t_latest_audit(string $activity, ?int $entityId = null): ?array
{
    $sql = 'SELECT * FROM audit_logs WHERE activity = ?';
    $params = [$activity];
    if ($entityId !== null) {
        $sql .= ' AND entity_id = ?';
        $params[] = (string) $entityId;
    }
    $stmt = Database::pdo()->prepare($sql . ' ORDER BY id DESC LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// ------------------------------------------------------------------
// Fixture data master (Fase 3). Ditulis langsung ke DB uji supaya
// file test tidak saling bergantung dan tetap idempoten saat diulang.
// ------------------------------------------------------------------

/** Kode unik per-eksekusi: KAT-1A2B3C. */
function t_uniq(string $prefix): string
{
    return strtoupper($prefix) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** Buat kategori uji; kembalikan ID-nya. */
function t_make_category(string $namePrefix = 'Kategori Uji', bool $active = true): int
{
    $pdo = Database::pdo();
    $code = t_uniq('KAT');
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (?, ?, ?)')
        ->execute([$code, $namePrefix . ' ' . $code, $active ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

/** Buat supplier uji; kembalikan ID-nya. */
function t_make_supplier(string $namePrefix = 'Supplier Uji', bool $active = true): int
{
    $pdo = Database::pdo();
    $code = t_uniq('SUP');
    $pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (?, ?, ?)')
        ->execute([$code, $namePrefix . ' ' . $code, $active ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

/** Buat lokasi penyimpanan uji; kembalikan ID-nya. */
function t_make_location(string $namePrefix = 'Rak Uji', bool $active = true): int
{
    $pdo = Database::pdo();
    $code = t_uniq('RAK');
    $pdo->prepare('INSERT INTO storage_locations (code, name, is_active) VALUES (?, ?, ?)')
        ->execute([$code, $namePrefix . ' ' . $code, $active ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

/**
 * Buat sparepart uji pada kategori tertentu.
 *
 * @param array<string,mixed> $overrides Kolom yang ingin ditimpa (stock, min_stock, supplier_id, location_id, ...)
 */
function t_make_sparepart(int $categoryId, array $overrides = []): int
{
    $pdo = Database::pdo();
    $data = $overrides + [
        'code'           => t_uniq('SP'),
        'name'           => 'Sparepart Uji ' . t_uniq('N'),
        'brand'          => 'Brand Uji',
        'vehicle_model'  => 'Model Uji',
        'unit'           => 'pcs',
        'purchase_price' => 10000,
        'selling_price'  => 15000,
        'stock'          => 0,
        'min_stock'      => 2,
        'is_active'      => 1,
    ];
    $pdo->prepare(
        'INSERT INTO spareparts
            (code, name, brand, vehicle_model, unit, purchase_price, selling_price,
             stock, min_stock, category_id, supplier_id, location_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $data['code'],
        $data['name'],
        $data['brand'],
        $data['vehicle_model'],
        $data['unit'],
        $data['purchase_price'],
        $data['selling_price'],
        $data['stock'],
        $data['min_stock'],
        $categoryId,
        $data['supplier_id'] ?? null,
        $data['location_id'] ?? null,
        $data['is_active'],
    ]);
    return (int) $pdo->lastInsertId();
}

/** Tulis berkas sementara untuk pengujian impor. @return string path */
function t_temp_file(string $suffix, string $content): string
{
    $dir = sys_get_temp_dir();
    $path = $dir . DIRECTORY_SEPARATOR . 'spm_' . bin2hex(random_bytes(5)) . '.' . $suffix;
    file_put_contents($path, $content);
    return $path;
}

/**
 * Kosongkan data transaksi (Fase 4) sebelum pengujian menghapus `users`.
 *
 * Beberapa tabel transaksi memakai ON DELETE RESTRICT ke `users`
 * (goods_receipts.recorded_by, goods_issues.recorded_by,
 * stock_opnames.started_by, purchase_orders.created_by), sehingga
 * `DELETE FROM users` gagal bila masih ada transaksi tersisa dari
 * pengujian sebelumnya. Penghapusan mengikuti arah foreign key
 * (baris anak lebih dahulu).
 *
 * `stock_movements` sengaja TIDAK dihapus: FK-nya ON DELETE SET NULL dan
 * ledger adalah riwayat permanen — stok sparepart harus tetap konsisten
 * dengan riwayat pergerakannya.
 */
function t_purge_transaction_data(): void
{
    $pdo = Database::pdo();
    foreach ([
        'goods_receipt_items',
        'goods_receipts',
        'goods_issue_items',
        'goods_issues',
        'stock_opname_items',
        'stock_opnames',
        'purchase_status_history',
        'purchase_payment_history',
        'purchase_order_items',
        'purchase_orders',
        'reorder_requests',
    ] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
}

// ------------------------------------------------------------------
// Fixture berkas unggahan (Fase 4). Dipakai bersama oleh pengujian
// transaksi sehingga tidak bergantung pada urutan berkas test.
// ------------------------------------------------------------------

/**
 * Buat berkas sementara + entri $_FILES tiruan.
 *
 * @param int|null $sizeOverride Ukuran yang dilaporkan (untuk menguji batas ukuran)
 * @return array{file:array<string,mixed>,tmp:string}
 */
function t_upload(string $name, string $content, ?int $error = null, ?int $sizeOverride = null): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spm_up_' . bin2hex(random_bytes(5));
    file_put_contents($tmp, $content);

    return [
        'file' => [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmp,
            'error'    => $error ?? UPLOAD_ERR_OK,
            'size'     => $sizeOverride ?? strlen($content),
        ],
        'tmp' => $tmp,
    ];
}

/** Bytes PNG 1×1 yang valid. */
function t_png_bytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
        true
    );
}

/** Bytes PDF minimal yang valid. */
function t_pdf_bytes(): string
{
    return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";
}

// Pastikan skema database uji sudah terbaru (idempoten) agar urutan file
// test tidak memengaruhi hasil. HANYA menyentuh DB uji (DB_TEST_NAME).
try {
    $migrator = new \App\Services\MigrationService($testDbConfig, BASE_PATH . '/database/migrations');
    $migrator->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "PERINGATAN: migrasi database uji gagal: {$e->getMessage()}\n");
}
