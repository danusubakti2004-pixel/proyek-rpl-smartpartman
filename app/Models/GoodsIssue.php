<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Barang keluar / pengeluaran sparepart (FR-07, tabel goods_issues + items).
 *
 * Model hanya baca/tulis baris; aturan "qty tidak boleh melebihi stok
 * tersedia" ditangani GoodsIssueService lewat penguncian baris + ledger.
 */
final class GoodsIssue extends Model
{
    public const USAGE_SERVICE = 'service';
    public const USAGE_SALE = 'penjualan';
    public const USAGE_OTHER = 'lainnya';

    /** @var string[] */
    public const USAGE_TYPES = [self::USAGE_SERVICE, self::USAGE_SALE, self::USAGE_OTHER];

    /**
     * @param array{issue_number:string, issue_date:string, usage_type:string, spk_number?:string|null,
     *              plate_number?:string|null, note?:string|null} $data
     */
    public static function insert(array $data, int $actorId): int
    {
        self::execute(
            'INSERT INTO goods_issues
                (issue_number, issue_date, usage_type, spk_number, plate_number, note, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['issue_number'],
                $data['issue_date'],
                $data['usage_type'],
                $data['spk_number'] ?? null,
                $data['plate_number'] ?? null,
                $data['note'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    public static function insertItem(int $issueId, int $sparepartId, float $quantity): int
    {
        self::execute(
            'INSERT INTO goods_issue_items (issue_id, sparepart_id, quantity) VALUES (?, ?, ?)',
            [$issueId, $sparepartId, $quantity]
        );
        return self::lastId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE g.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $issueId): array
    {
        return self::fetchAll(
            'SELECT i.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit, s.stock AS sparepart_stock
               FROM goods_issue_items i
               JOIN spareparts s ON s.id = i.sparepart_id
              WHERE i.issue_id = ?
           ORDER BY i.id ASC',
            [$issueId]
        );
    }

    /**
     * @param array{search?:string, usage_type?:string|null, date_from?:string|null, date_to?:string|null} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilter($filters);

        $total = (int) self::scalar('SELECT COUNT(*) FROM goods_issues g' . $where, $params);

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            self::selectHeader() . $where . ' ORDER BY g.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (g.issue_number LIKE ? OR g.spk_number LIKE ? OR g.plate_number LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $usage = trim((string) ($filters['usage_type'] ?? ''));
        if (in_array($usage, self::USAGE_TYPES, true)) {
            $where .= ' AND g.usage_type = ?';
            $params[] = $usage;
        }

        $from = self::normalizeDate($filters['date_from'] ?? null);
        if ($from !== null) {
            $where .= ' AND g.issue_date >= ?';
            $params[] = $from;
        }

        $to = self::normalizeDate($filters['date_to'] ?? null);
        if ($to !== null) {
            $where .= ' AND g.issue_date <= ?';
            $params[] = $to;
        }

        return [$where, $params];
    }

    private static function selectHeader(): string
    {
        return 'SELECT g.id, g.issue_number, g.issue_date, g.usage_type, g.spk_number, g.plate_number,
                       g.note, g.recorded_by, g.created_at,
                       u.full_name AS recorded_by_name, u.username AS recorded_by_username,
                       (SELECT COALESCE(SUM(i.quantity), 0) FROM goods_issue_items i WHERE i.issue_id = g.id) AS total_quantity,
                       (SELECT i.sparepart_id FROM goods_issue_items i WHERE i.issue_id = g.id ORDER BY i.id ASC LIMIT 1) AS sparepart_id,
                       (SELECT s.code FROM goods_issue_items i JOIN spareparts s ON s.id = i.sparepart_id
                         WHERE i.issue_id = g.id ORDER BY i.id ASC LIMIT 1) AS sparepart_code,
                       (SELECT s.name FROM goods_issue_items i JOIN spareparts s ON s.id = i.sparepart_id
                         WHERE i.issue_id = g.id ORDER BY i.id ASC LIMIT 1) AS sparepart_name
                  FROM goods_issues g
             LEFT JOIN users u ON u.id = g.recorded_by';
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
