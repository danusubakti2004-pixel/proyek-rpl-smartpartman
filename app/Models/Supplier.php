<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Supplier (FR-11). Supplier yang pernah dipakai TIDAK dihapus permanen —
 * hanya dinonaktifkan.
 */
final class Supplier extends Model
{
    /** @return array<int,array<string,mixed>> */
    public static function all(?string $search = null, ?string $status = null, bool $onlyActive = false): array
    {
        $sql = 'SELECT s.id, s.code, s.name, s.address, s.phone, s.email, s.contact_person,
                       s.lead_time_days, s.is_active, s.created_at, s.updated_at,
                       (SELECT COUNT(*) FROM spareparts sp WHERE sp.supplier_id = s.id) AS sparepart_count,
                       (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id = s.id) AS receipt_count
                  FROM suppliers s
                 WHERE 1 = 1';
        $params = [];

        $search = trim((string) $search);
        if ($search !== '') {
            $sql .= ' AND (s.code LIKE :q1 OR s.name LIKE :q2 OR s.contact_person LIKE :q3 OR s.phone LIKE :q4)';
            $like = '%' . $search . '%';
            $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        if ($onlyActive || $status === 'aktif') {
            $sql .= ' AND s.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $sql .= ' AND s.is_active = 0';
        }

        return self::fetchAll($sql . ' ORDER BY s.name ASC', $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function options(bool $onlyActive = true): array
    {
        $sql = 'SELECT id, code, name, is_active FROM suppliers';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        return self::fetchAll($sql . ' ORDER BY name ASC');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne('SELECT * FROM suppliers WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCodeOrName(string $value): ?array
    {
        return self::fetchOne(
            'SELECT * FROM suppliers WHERE code = ? OR name = ? ORDER BY is_active DESC, id ASC LIMIT 1',
            [$value, $value]
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM suppliers WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return self::scalar($sql . ' LIMIT 1', $params) !== null;
    }

    public static function sparepartCount(int $id): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM spareparts WHERE supplier_id = ?', [$id]);
    }

    public static function receiptCount(int $id): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM goods_receipts WHERE supplier_id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public static function insert(array $data, ?int $actorId): int
    {
        self::execute(
            'INSERT INTO suppliers (code, name, address, phone, email, contact_person, lead_time_days, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $data['code'],
                $data['name'],
                $data['address'] ?? null,
                $data['phone'] ?? null,
                $data['email'] ?? null,
                $data['contact_person'] ?? null,
                $data['lead_time_days'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $actorId): void
    {
        self::execute(
            'UPDATE suppliers
                SET code = ?, name = ?, address = ?, phone = ?, email = ?, contact_person = ?,
                    lead_time_days = ?, updated_by = ?
              WHERE id = ?',
            [
                $data['code'],
                $data['name'],
                $data['address'] ?? null,
                $data['phone'] ?? null,
                $data['email'] ?? null,
                $data['contact_person'] ?? null,
                $data['lead_time_days'] ?? null,
                $actorId,
                $id,
            ]
        );
    }

    public static function setActive(int $id, bool $active, ?int $actorId): void
    {
        self::execute(
            'UPDATE suppliers SET is_active = ?, updated_by = ? WHERE id = ?',
            [$active ? 1 : 0, $actorId, $id]
        );
    }
}
