<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Metadata berkas backup database (Fase 9).
 *
 * Tabel `backups` hanya menyimpan metadata; berkas SQL berada di
 * storage/backups (di luar web root) dan tidak pernah disajikan langsung.
 */
final class Backup extends Model
{
    /** @return array<int,array<string,mixed>> */
    public static function all(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return self::fetchAll(
            'SELECT b.*, c.username AS created_by_name, r.username AS restored_by_name
               FROM backups b
               LEFT JOIN users c ON c.id = b.created_by
               LEFT JOIN users r ON r.id = b.restored_by
              ORDER BY b.id DESC
              LIMIT ' . $limit
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(
            'SELECT b.*, c.username AS created_by_name, r.username AS restored_by_name
               FROM backups b
               LEFT JOIN users c ON c.id = b.created_by
               LEFT JOIN users r ON r.id = b.restored_by
              WHERE b.id = ?
              LIMIT 1',
            [$id]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        self::execute(
            'INSERT INTO backups
                (filename, filepath, filesize, checksum_sha256, database_name,
                 migration_count, status, kind, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) ($data['filename'] ?? ''),
                (string) ($data['filepath'] ?? ''),
                $data['filesize'] ?? null,
                $data['checksum_sha256'] ?? null,
                $data['database_name'] ?? null,
                $data['migration_count'] ?? null,
                (string) ($data['status'] ?? 'success'),
                (string) ($data['kind'] ?? 'manual'),
                $data['note'] ?? null,
                $data['created_by'] ?? null,
            ]
        );
        return self::lastId();
    }

    /**
     * Daftarkan kembali metadata yang hilang setelah database dipulihkan.
     * (Dump dibuat SEBELUM baris metadata ditulis, sehingga baris backup
     * tidak ikut tersimpan di dalam dump.)
     *
     * @param array<string,mixed> $row
     */
    public static function insertFromExisting(array $row, ?int $actorId = null): int
    {
        self::execute(
            'INSERT INTO backups
                (filename, filepath, filesize, checksum_sha256, database_name,
                 migration_count, status, kind, note, created_by, restored_at,
                 restored_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) ($row['filename'] ?? ''),
                (string) ($row['filepath'] ?? ''),
                $row['filesize'] ?? null,
                $row['checksum_sha256'] ?? null,
                $row['database_name'] ?? null,
                $row['migration_count'] ?? null,
                (string) ($row['status'] ?? 'success'),
                (string) ($row['kind'] ?? 'manual'),
                $row['note'] ?? null,
                $actorId ?? ($row['created_by'] ?? null),
                $row['restored_at'] ?? null,
                $row['restored_by'] ?? null,
                (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]
        );
        return self::lastId();
    }

    public static function markRestored(int $id, ?int $actorId): void
    {
        self::execute(
            'UPDATE backups SET status = ?, restored_at = NOW(), restored_by = ? WHERE id = ?',
            ['restored', $actorId, $id]
        );
    }

    public static function userExists(int $id): bool
    {
        return self::scalar('SELECT 1 FROM users WHERE id = ? LIMIT 1', [$id]) !== null;
    }
}
