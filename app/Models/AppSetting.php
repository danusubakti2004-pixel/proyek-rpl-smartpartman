<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Pengaturan aplikasi sederhana (key–value) pada tabel `app_settings`.
 *
 * Dipakai untuk identitas bengkel pada dokumen cetak dan parameter laporan
 * (fast/slow moving). Tabel sudah ada sejak Fase 1; belum pernah dipakai.
 */
final class AppSetting extends Model
{
    /** @return array<string,string> key → value */
    public static function all(): array
    {
        $rows = self::fetchAll('SELECT setting_key, setting_value FROM app_settings');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', [$key]);
        return $value === null ? $default : (string) $value;
    }

    public static function put(string $key, ?string $value, ?int $actorId): void
    {
        self::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $value, $actorId]
        );
    }

    /** @param array<string,string|null> $values */
    public static function putMany(array $values, ?int $actorId): void
    {
        foreach ($values as $key => $value) {
            self::put((string) $key, $value === null ? null : (string) $value, $actorId);
        }
    }

    public static function forget(string $key): void
    {
        self::execute('DELETE FROM app_settings WHERE setting_key = ?', [$key]);
    }
}
