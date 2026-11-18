<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Stock opname (FR-10, tabel stock_opnames + stock_opname_items).
 *
 * Prinsip: stok SISTEM hanya dibaca (snapshot) saat sesi dibuat; stok
 * sparepart tidak pernah berubah sebelum sesi dikonfirmasi. Perubahan
 * hanya terjadi lewat pergerakan PENYESUAIAN_OPNAME di StockOpnameService.
 */
final class StockOpname extends Model
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CONFIRMED = 'CONFIRMED';

    /** @var string[] */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CONFIRMED];

    public static function insert(string $number, string $date, ?string $note, int $actorId): int
    {
        self::execute(
            'INSERT INTO stock_opnames (opname_number, opname_date, status, note, started_by)
             VALUES (?, ?, ?, ?, ?)',
            [$number, $date, self::STATUS_OPEN, $note, $actorId]
        );
        return self::lastId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(
            'SELECT o.*, su.full_name AS started_by_name, su.username AS started_by_username,
                    cu.full_name AS confirmed_by_name, cu.username AS confirmed_by_username,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id) AS item_count,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL) AS counted_count,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL AND i.difference <> 0) AS diff_count
               FROM stock_opnames o
               JOIN users su ON su.id = o.started_by
          LEFT JOIN users cu ON cu.id = o.confirmed_by
              WHERE o.id = ?',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $opnameId): array
    {
        return self::fetchAll(
            'SELECT i.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    s.stock AS current_stock, s.min_stock,
                    u.full_name AS counted_by_name
               FROM stock_opname_items i
               JOIN spareparts s ON s.id = i.sparepart_id
          LEFT JOIN users u ON u.id = i.counted_by
              WHERE i.opname_id = ?
           ORDER BY s.name ASC',
            [$opnameId]
        );
    }

    /**
     * Simpan hasil hitung fisik satu item (stok SISTEM tidak berubah).
     *
     * @param float|null $physical null = belum dihitung (dikosongkan kembali)
     */
    public static function updateItem(
        int $opnameId,
        int $sparepartId,
        ?float $physical,
        ?float $difference,
        ?string $reason,
        int $actorId
    ): void {
        self::execute(
            'UPDATE stock_opname_items
                SET physical_stock = ?, difference = ?, reason = ?, counted_by = ?, counted_at = ?
              WHERE opname_id = ? AND sparepart_id = ?',
            [$physical, $difference, $reason, $actorId, date('Y-m-d H:i:s'), $opnameId, $sparepartId]
        );
    }

    /** @param array<int,int> $sparepartIds */
    public static function insertItems(int $opnameId, array $spareparts): void
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO stock_opname_items (opname_id, sparepart_id, system_stock) VALUES (?, ?, ?)'
        );
        foreach ($spareparts as $row) {
            $stmt->execute([$opnameId, (int) $row['id'], (float) $row['stock']]);
        }
    }

    public static function markConfirmed(int $opnameId, int $actorId): void
    {
        self::execute(
            'UPDATE stock_opnames SET status = ?, confirmed_by = ?, confirmed_at = ? WHERE id = ?',
            [self::STATUS_CONFIRMED, $actorId, date('Y-m-d H:i:s'), $opnameId]
        );
    }

    /** Apakah masih ada sesi opname yang belum dikonfirmasi? */
    public static function hasOpenSession(): bool
    {
        return self::scalar(
            'SELECT id FROM stock_opnames WHERE status = ? LIMIT 1',
            [self::STATUS_OPEN]
        ) !== null;
    }

    /**
     * @param array{search?:string, status?:string|null, date_from?:string|null, date_to?:string|null} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (o.opname_number LIKE ? OR o.note LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }

        $from = self::normalizeDate($filters['date_from'] ?? null);
        if ($from !== null) {
            $where .= ' AND o.opname_date >= ?';
            $params[] = $from;
        }

        $to = self::normalizeDate($filters['date_to'] ?? null);
        if ($to !== null) {
            $where .= ' AND o.opname_date <= ?';
            $params[] = $to;
        }

        $total = (int) self::scalar('SELECT COUNT(*) FROM stock_opnames o' . $where, $params);

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            'SELECT o.*, su.full_name AS started_by_name, cu.full_name AS confirmed_by_name,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id) AS item_count,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL AND i.difference <> 0) AS diff_count
               FROM stock_opnames o
               JOIN users su ON su.id = o.started_by
          LEFT JOIN users cu ON cu.id = o.confirmed_by' . $where . '
           ORDER BY o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Terima YYYY-MM-DD atau dd/mm/yyyy; kembalikan YYYY-MM-DD atau null. */
    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) === 1) {
            return $value;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }
}
