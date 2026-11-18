<?php
declare(strict_types=1);

/**
 * CLI: menjalankan migrasi database SmartPartMan.
 *
 * Pemakaian:
 *   php database\migrate.php            → jalankan migrasi tertunda
 *   php database\migrate.php --status   → lihat status migrasi
 *
 * CLI saja; tidak bisa diakses dari browser.
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

$service = new MigrationService($config['db'], BASE_PATH . '/database/migrations');
$mode = $argv[1] ?? 'run';

if ($mode === '--status') {
    echo "Database : {$config['db']['name']}\n";
    echo "File     : " . count($service->files()) . "\n";
    echo "Berjalan : " . count($service->applied()) . "\n";
    echo "Tertunda : " . count($service->pending()) . "\n";
    foreach ($service->pending() as $file) {
        echo "  - {$file}\n";
    }
    exit(0);
}

echo "Menjalankan migrasi database '{$config['db']['name']}'...\n";
try {
    $executed = $service->run(static function (string $line): void {
        echo $line . "\n";
    });
    echo count($executed) > 0
        ? 'Selesai: ' . count($executed) . ' migrasi dijalankan.' . "\n"
        : 'Selesai: tidak ada migrasi baru.' . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
