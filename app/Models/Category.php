<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Kategori sparepart (FR-12). Kategori TIDAK pernah dihapus permanen —
 * hanya dinonaktifkan (is_active = 0) supaya riwayat transaksi tetap utuh.
 */
final class Category extends Model
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(?string $search = null, ?string $status = null, bool $onlyActive = false): array
    {
        $sql = 'SELECT c.id, c.code, c.name, c.description, c.is_active, c.created_at, c.updated_at,
                       (SELECT COUNT(*) FROM spareparts s WHERE s.category_id = c.id) AS sparepart_count
                  FROM categories c
                 WHERE 1 = 1';
        $params = [];

        $search = trim((string) $search);
        if ($search !== '') {
            $sql .= ' AND (c.code LIKE :q1 OR c.name LIKE :q2 OR c.description LIKE :q3)';
            $like = '%' . $search . '%';
            $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
        }
        if ($onlyActive || $status === 'aktif') {
            $sql .= ' AND c.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $sql .= ' AND c.is_active = 0';
        }

        return self::fetchAll($sql . ' ORDER BY c.name ASC', $params);
    }

    /** @return array<int,array<string,mixed>> Daftar ringkas untuk <select>. */
    public static function options(bool $onlyActive = true): array
    {
        $sql = 'SELECT id, code, name, is_active FROM categories';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        return self::fetchAll($sql . ' ORDER BY name ASC');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return self::fetchOne('SELECT * FROM categories WHERE code = ?', [$code]);
    }

    /**
     * Cari kategori dari kode ATAU nama (dipakai import FR-04).
     * @return array<string,mixed>|null
     */
    public static function findByCodeOrName(string $value): ?array
    {
        return self::fetchOne(
            'SELECT * FROM categories WHERE code = ? OR name = ? ORDER BY is_active DESC, id ASC LIMIT 1',
            [$value, $value]
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM categories WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return self::scalar($sql . ' LIMIT 1', $params) !== null;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM categories WHERE name = ?';
        $params = [$name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return self::scalar($sql . ' LIMIT 1', $params) !== null;
    }

    public static function sparepartCount(int $id): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM spareparts WHERE category_id = ?', [$id]);
    }

    /** @param array{code:string,name:string,description?:string|null} $data */
    public static function insert(array $data, ?int $actorId): int
    {
        self::execute(
            'INSERT INTO categories (code, name, description, is_active, created_by)
             VALUES (?, ?, ?, 1, ?)',
            [$data['code'], $data['name'], $data['description'] ?? null, $actorId]
        );
        return self::lastId();
    }

    /** @param array{code:string,name:string,description?:string|null} $data */
    public static function update(int $id, array $data, ?int $actorId): void
    {
        self::execute(
            'UPDATE categories SET code = ?, name = ?, description = ?, updated_by = ? WHERE id = ?',
            [$data['code'], $data['name'], $data['description'] ?? null, $actorId, $id]
        );
    }

    public static function setActive(int $id, bool $active, ?int $actorId): void
    {
        self::execute(
            'UPDATE categories SET is_active = ?, updated_by = ? WHERE id = ?',
            [$active ? 1 : 0, $actorId, $id]
        );
    }
}
