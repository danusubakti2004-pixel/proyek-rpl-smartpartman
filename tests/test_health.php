<?php
declare(strict_types=1);

use App\Core\Env;
use App\Services\HealthService;

/**
 * Uji Health Check (Fase 1, butir 11):
 * 1) status akurat pada database uji, 2) TIDAK membocorkan rahasia.
 *
 * Memakai database uji (di-set bootstrap.php) dan dijalankan setelah
 * test_database yang memastikan seluruh migrasi selesai.
 */

function test_health_snapshot_dasar_publik(): void
{
    $snapshot = HealthService::check(false);

    t_eq('ok', $snapshot['php'], 'PHP + ekstensi wajib sehat');
    t_eq('ok', $snapshot['database'], 'Koneksi database sehat');
    t_eq('ok', $snapshot['migrations'], 'Tidak ada migrasi tertunda');
    t_eq('ok', $snapshot['status'], 'Status keseluruhan "ok" saat sehat');
    t_true(array_key_exists('installed', $snapshot), 'Status instalasi dilaporkan');
    t_true(isset($snapshot['time']), 'Waktu pemeriksaan disertakan');

    // Snapshot publik hanya boleh memuat status — bukan diagnostik internal.
    foreach (['php_version', 'database_version', 'database_error', 'missing_extensions', 'pending_migrations'] as $internal) {
        t_true(!array_key_exists($internal, $snapshot), "Snapshot publik tanpa field internal '{$internal}'");
    }
}

function test_health_snapshot_detail_admin(): void
{
    $detail = HealthService::check(true);

    t_true(isset($detail['php_version']), 'Detail memuat versi PHP');
    t_eq([], $detail['missing_extensions'], 'Tidak ada ekstensi wajib yang hilang');
    t_eq(0, $detail['pending_migrations'], 'Jumlah migrasi tertunda = 0');
    t_eq(null, $detail['database_error'], 'Tanpa pesan error database');
    t_true(($detail['database_version'] ?? '') !== '', 'Versi server database tercatat (khusus Admin)');
}

function test_health_tidak_membocorkan_rahasia(): void
{
    $json = json_encode(HealthService::check(true), JSON_UNESCAPED_UNICODE);
    t_true(is_string($json), 'Snapshot detail dapat di-encode menjadi JSON');

    $payload = (string) $json;
    $pass = (string) Env::get('DB_PASS', '');
    $user = (string) Env::get('DB_USER', '');
    $key = (string) Env::get('APP_KEY', '');

    t_true($pass === '' || !str_contains($payload, $pass), 'Password database tidak ada di respons');
    t_true($user === '' || !str_contains($payload, $user), 'Nama pengguna database tidak ada di respons');
    t_true($key === '' || !str_contains($payload, $key), 'APP_KEY tidak ada di respons');
    t_true(stripos($payload, 'password') === false, 'Tidak ada kata "password" di respons');
    t_true(!str_contains($payload, str_replace('/', '\\', BASE_PATH)), 'Path absolut proyek tidak ada di respons');
}
