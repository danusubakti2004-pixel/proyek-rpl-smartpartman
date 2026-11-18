<?php
declare(strict_types=1);

/**
 * CLI: penyegaran prediksi stok berkala (pengaman otomatisasi).
 *
 * Prediksi sudah dipicu otomatis setelah setiap perubahan stok. Skrip ini
 * adalah jaring pengaman bila pemicu tersebut terlewat (mis. proses web
 * berhenti sebelum shutdown), dijalankan oleh penjadwal sistem (Task
 * Scheduler/cron) beberapa kali sehari.
 *
 * Pemakaian:
 *   php database\prediction_scheduler.php
 *
 * Tidak mengubah stok, tidak membuat Purchase Order. Hanya menghitung ulang
 * rekomendasi memakai algoritma yang sudah ada (PredictionService).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

require __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\PredictionAutomation;

Env::load(__DIR__ . '/../.env');
$config = require __DIR__ . '/../config/config.php';
Database::configure($config['db']);
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Jakarta'));

try {
    $result = PredictionAutomation::refreshAllActive(200);
} catch (Throwable $e) {
    fwrite(STDERR, "Prediksi otomatis gagal dijalankan.\n");
    exit(1);
}

printf(
    "[%s] %s (diproses: %d, gagal: %d)\n",
    date('Y-m-d H:i:s'),
    (string) ($result['message'] ?? ''),
    (int) ($result['processed'] ?? 0),
    (int) ($result['failed'] ?? 0)
);

exit(($result['status'] ?? 'error') === 'error' ? 1 : 0);
