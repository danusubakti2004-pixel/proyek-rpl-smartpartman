<?php
declare(strict_types=1);

/**
 * Konfigurasi terpusat. Nilai diambil dari .env (Env), dengan default aman.
 * Jangan taruh rahasia (password, API key) langsung di file ini.
 */

use App\Core\Env;

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'SmartPartMan'),
        'env'      => Env::get('APP_ENV', 'local'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'key'      => Env::get('APP_KEY', ''), // kunci enkripsi (dihasilkan saat instalasi)
        'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost/smartpartman/smartpartman'), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
    ],

    'db' => [
        'host'      => Env::get('DB_HOST', '127.0.0.1'),
        'port'      => (int) Env::get('DB_PORT', '3306'),
        'name'      => Env::get('DB_NAME', 'smartpartman_v2'),
        'user'      => Env::get('DB_USER', 'root'),
        'pass'      => (string) Env::get('DB_PASS', ''),
        'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => 'utf8mb4_unicode_ci',
        // Database terpisah khusus pengujian (tidak pernah menyentuh data produksi).
        'test_name' => Env::get('DB_TEST_NAME', 'smartpartman_test'),
    ],

    'paths' => [
        'base'      => BASE_PATH,
        'storage'   => BASE_PATH . '/storage',
        'logs'      => BASE_PATH . '/storage/logs',
        'backups'   => BASE_PATH . '/storage/backups',
        'uploads'   => BASE_PATH . '/storage/private_uploads',
        'documents' => BASE_PATH . '/storage/documents',
    ],

    // Jalur binary untuk integrasi PHP → Python (AI Stock Prediction, Fase 7).
    'cli' => [
        'php'            => Env::get('PHP_CLI_PATH', 'C:\\xampp\\php\\php.exe'),
        'python'         => Env::get('PYTHON_PATH', 'py'),
        'python_args'    => Env::get('PYTHON_ARGS', '-3.12'),
        'predict_script' => BASE_PATH . '/python/prediction/forecast.py',
    ],

    // Backup/restore database (Fase 9). Berkas HANYA ditulis ke storage/backups
    // (di luar web root). Jalur binary tidak pernah berasal dari input pengguna.
    'backup' => [
        'dump'      => Env::get('MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'),
        'client'    => Env::get('MYSQL_CLIENT_PATH', 'C:\\xampp\\mysql\\bin\\mysql.exe'),
        'max_bytes' => (int) Env::get('BACKUP_MAX_MB', '100') * 1048576,
    ],

    // Chatbot Gemini (Fase 8). API key TIDAK wajib di .env: Admin dapat
    // menyimpannya terenkripsi melalui halaman Pengaturan Chatbot.
    // GEMINI_API_URL hanya untuk pengujian lokal (stub server) / gateway internal.
    'gemini' => [
        'api_key'  => (string) Env::get('GEMINI_API_KEY', ''),
        'api_base' => rtrim((string) Env::get('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
        'timeout'  => (float) Env::get('GEMINI_TIMEOUT', '25'),
    ],
];
