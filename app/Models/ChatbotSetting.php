<?php
declare(strict_types=1);

namespace App\Models;

use Throwable;

/**
 * Pengaturan chatbot (Fase 8, diperluas multi-provider).
 *
 * Satu baris saja (id = 1) untuk pengaturan umum & provider Gemini. Kolom
 * `api_key_encrypted` HANYA berisi ciphertext; plaintext tidak pernah dibaca/
 * ditulis langsung dari kelas ini. Enkripsi & penyamaran ditangani
 * ChatbotSettingsService.
 *
 * Provider selain Gemini disimpan di tabel `chatbot_providers` (migrasi 019)
 * agar pergantian provider tidak pernah menghapus kunci provider lain.
 */
final class ChatbotSetting extends Model
{
    private static ?bool $providerSchema = null;
    private static ?bool $providerTable = null;

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return self::fetchOne('SELECT * FROM chatbot_settings WHERE id = 1');
    }

    /**
     * Pastikan baris id=1 ada (instalasi lama / DB uji yang belum di-seed).
     */
    public static function ensureRow(): void
    {
        self::execute(
            'INSERT INTO chatbot_settings (id, is_enabled, model, answer_style)
             VALUES (1, 0, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            ['gemini-3.5-flash-lite', 'ringkas']
        );
    }

    /**
     * Perbarui kolom yang diizinkan. Nilai sudah divalidasi & (untuk API key)
     * sudah dienkripsi oleh service.
     *
     * @param array<string,mixed> $values
     */
    public static function update(array $values, ?int $actorId): int
    {
        $allowed = [
            'is_enabled', 'provider', 'api_key_encrypted', 'api_key_masked', 'model',
            'api_base', 'system_prompt', 'topic_limits', 'answer_style',
            'usage_limit_daily', 'usage_limit_monthly',
        ];

        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            if (($column === 'provider' || $column === 'api_base') && !self::hasProviderSchema()) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return 0;
        }

        $sets[] = 'updated_by = ?';
        $params[] = $actorId;
        $params[] = 1;

        return self::execute(
            'UPDATE chatbot_settings SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    /** Apakah migrasi 019 (kolom provider & api_base) sudah diterapkan? */
    public static function hasProviderSchema(): bool
    {
        if (self::$providerSchema !== null) {
            return self::$providerSchema;
        }
        self::$providerSchema = self::columnExists('chatbot_settings', 'provider')
            && self::columnExists('chatbot_settings', 'api_base');

        return self::$providerSchema;
    }

    /** Apakah tabel chatbot_providers sudah tersedia? */
    public static function hasProviderTable(): bool
    {
        if (self::$providerTable !== null) {
            return self::$providerTable;
        }
        try {
            self::fetchOne('SELECT 1 FROM chatbot_providers LIMIT 1');
            self::$providerTable = true;
        } catch (Throwable) {
            self::$providerTable = false;
        }

        return self::$providerTable;
    }

    /**
     * Semua baris konfigurasi provider tambahan.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function providerRows(): array
    {
        if (!self::hasProviderTable()) {
            return [];
        }
        $rows = self::fetchAll('SELECT * FROM chatbot_providers');
        $result = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');
            if ($provider !== '') {
                $result[$provider] = $row;
            }
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public static function providerRow(string $provider): ?array
    {
        if (!self::hasProviderTable()) {
            return null;
        }
        return self::fetchOne('SELECT * FROM chatbot_providers WHERE provider = ?', [$provider]);
    }

    /**
     * Simpan/menimpa konfigurasi satu provider tambahan. Tidak menyentuh baris
     * provider lain maupun kolom Gemini di chatbot_settings.
     *
     * @param array<string,mixed> $values
     */
    public static function upsertProvider(string $provider, array $values, ?int $actorId): int
    {
        if (!self::hasProviderTable()) {
            return 0;
        }

        $allowed = ['api_key_encrypted', 'api_key_masked', 'model', 'api_base'];
        $columns = ['provider', 'updated_by'];
        $params = [$provider, $actorId];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $values)) {
                $columns[] = $column;
                $params[] = $values[$column];
            }
        }

        $updates = [];
        foreach ($columns as $column) {
            if ($column === 'provider') {
                continue;
            }
            $updates[] = $column . ' = VALUES(' . $column . ')';
        }

        return self::execute(
            'INSERT INTO chatbot_providers (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $params
        );
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            $row = self::fetchOne(
                'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            return (int) ($row['n'] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Samarkan API key: hanya 4 karakter terakhir yang ditampilkan. */
    public static function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $last4 = substr($key, -4);
        return '****' . $last4;
    }
}
