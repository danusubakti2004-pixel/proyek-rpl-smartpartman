<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Health Check (Fase 1) — memastikan PHP, ekstensi wajib, dan database sehat
 * TANPA membocorkan rahasia (password DB, APP_KEY, path internal).
 *
 * Dua tingkat keluaran:
 * - Dasar  : hanya status boolean/enum → aman disajikan ke publik & monitoring.
 * - Detail : diagnostik teknis (versi, migrasi tertunda, pesan error DB)
 *            → hanya diberikan setelah login sebagai Admin.
 *
 * Catatan desain: `status` mengukur kesiapan TEKNIS (PHP, ekstensi, DB,
 * migrasi). Status instalasi aplikasi dilaporkan terpisah lewat `installed`
 * (aplikasi yang belum di-install tetap bisa sehat secara infrastruktur).
 */
final class HealthService
{
    /** Versi PHP minimum yang didukung. */
    public const MIN_PHP_VERSION = '8.0.0';

    /** Ekstensi wajib agar aplikasi berjalan normal. */
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'];

    /**
     * Periksa kesehatan aplikasi.
     *
     * @param bool $detailed true = sertakan diagnostik teknis (khusus Admin).
     * @return array<string,mixed> Snapshot kesehatan.
     */
    public static function check(bool $detailed = false): array
    {
        $missingExtensions = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => !extension_loaded($ext)
        ));
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=') && $missingExtensions === [];

        $dbOk = false;
        $dbVersion = null;
        $dbError = null;

        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
            $row = Database::pdo()->query('SELECT VERSION() AS v')->fetch();
            $dbVersion = is_array($row) ? (string) ($row['v'] ?? '') : '';
        } catch (Throwable $e) {
            // Pesan PDO tidak memuat password; tetap hanya dibuka untuk Admin.
            $dbError = $e->getMessage();
        }

        $migrations = 'unknown';
        $pendingMigrations = null;
        if ($dbOk) {
            try {
                $service = new MigrationService(Database::config(), BASE_PATH . '/database/migrations');
                $pendingMigrations = count($service->pending());
                $migrations = $pendingMigrations === 0 ? 'ok' : 'pending';
            } catch (Throwable) {
                $migrations = 'unknown'; // tabel migrations belum ada / skema belum siap
            }
        }

        $healthy = $phpOk && $dbOk && $migrations === 'ok';

        $snapshot = [
            'status'     => $healthy ? 'ok' : 'degraded',
            'time'       => date('c'),
            'php'        => $phpOk ? 'ok' : 'error',
            'database'   => $dbOk ? 'ok' : 'error',
            'migrations' => $migrations,
            'installed'  => InstallService::isInstalled(),
        ];

        if (!$detailed) {
            return $snapshot;
        }

        return $snapshot + [
            'php_version'        => PHP_VERSION,
            'missing_extensions' => $missingExtensions,
            'database_version'   => $dbVersion,
            'pending_migrations' => $pendingMigrations,
            'database_error'     => $dbError,
            'timezone'           => date_default_timezone_get(),
        ];
    }
}
