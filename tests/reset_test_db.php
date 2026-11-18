<?php
declare(strict_types=1);

/**
 * Reset database UJI SmartPartMan (keputusan pemilik produk — Fase 7).
 *
 * Aturan keamanan:
 *   - HANYA untuk database yang namanya berakhiran `_test` (DB_TEST_NAME).
 *   - DITOLAK bila nama DB uji sama dengan DB produksi (DB_NAME).
 *   - Tidak pernah menjalankan DROP/CREATE terhadap database produksi.
 *   - Wajib konfirmasi eksplisit `--yes` (mencegah reset tak sengaja).
 *
 * Pemakaian:
 *   php tests\reset_test_db.php          → tampilkan target & minta --yes
 *   php tests\reset_test_db.php --yes    → hapus & buat ulang skema uji
 *
 * CLI saja; tidak bisa diakses lewat browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/autoload.php';

use App\Core\Env;
use App\Services\MigrationService;

Env::load(BASE_PATH . '/.env');
$config = require BASE_PATH . '/config/config.php';

$db = is_array($config['db'] ?? null) ? $config['db'] : [];
$production = (string) ($db['name'] ?? '');
$test = (string) ($db['test_name'] ?? '');

// ------------------------------------------------------------------
// Penjagaan keras: target WAJIB database uji yang berbeda dari produksi.
// ------------------------------------------------------------------
if ($production === '' || $test === '') {
    fwrite(STDERR, "DITOLAK: DB_NAME / DB_TEST_NAME tidak terbaca dari konfigurasi.\n");
    exit(1);
}
if ($test === $production) {
    fwrite(STDERR, "DITOLAK: DB uji sama dengan DB produksi ({$production}). Reset dibatalkan.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $test)) {
    fwrite(STDERR, "DITOLAK: nama DB uji harus berakhiran '_test' (target: {$test}).\n");
    exit(1);
}

$confirmed = in_array('--yes', $argv ?? [], true);
echo "Database produksi : {$production} (TIDAK disentuh)\n";
echo "Database uji      : {$test}\n";

if (!$confirmed) {
    echo "\nPerintah ini akan MENGHAPUS seluruh isi database uji '{$test}' lalu\n";
    echo "membangun ulang skema dari migrasi. Jalankan ulang dengan:\n";
    echo "  php tests\\reset_test_db.php --yes\n";
    exit(2);
}

// ------------------------------------------------------------------
// Koneksi ke server TANPA nama database (agar DROP tidak menyentuh DB lain).
// ------------------------------------------------------------------
$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    (string) $db['host'],
    (int) $db['port'],
    (string) ($db['charset'] ?? 'utf8mb4')
);
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $server = new PDO($dsn, (string) $db['user'], (string) $db['pass'], $options);

    // Pertahanan terakhir: pastikan target bukan produksi sebelum DROP.
    $current = (string) $server->query('SELECT DATABASE()')->fetchColumn();
    if ($current !== '' && $current === $production) {
        throw new RuntimeException('Koneksi berada pada DB produksi — reset dibatalkan.');
    }
    if ($test === $production) {
        throw new RuntimeException('Target reset adalah DB produksi — dibatalkan.');
    }

    // Nama sudah divalidasi regex ^[A-Za-z0-9_]+_test$ → aman di-backtick.
    $server->exec('DROP DATABASE IF EXISTS `' . $test . '`');
    echo "  [OK] Database uji lama dihapus.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL menghapus database uji: ' . $e->getMessage() . "\n");
    exit(1);
}

// ------------------------------------------------------------------
// Bangun ulang skema memakai MigrationService (DB uji saja).
// ------------------------------------------------------------------
try {
    $testConfig = $db;
    $testConfig['name'] = $test;
    $service = new MigrationService($testConfig, BASE_PATH . '/database/migrations');
    $executed = $service->run(static function (string $line): void {
        echo $line . "\n";
    });
    echo '  [OK] Reset selesai: ' . count($executed) . " migrasi dijalankan pada '{$test}'.\n";

    // Verifikasi ringkas: tabel inti harus ada.
    $check = new PDO(
        $dsn . ';dbname=' . $test,
        (string) $db['user'],
        (string) $db['pass'],
        $options
    );
    $core = (int) $check->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = " . $check->quote($test) . "
            AND table_name IN ('users','roles','spareparts','stock_movements','ai_predictions')"
    )->fetchColumn();
    if ($core < 5) {
        throw new RuntimeException('Tabel inti tidak lengkap setelah migrasi (' . $core . '/5).');
    }
    echo "  [OK] Tabel inti terverifikasi (5/5).\n";
    echo "\nSELESAI — database produksi '{$production}' tidak disentuh.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL membangun ulang skema uji: ' . $e->getMessage() . "\n");
    exit(1);
}
