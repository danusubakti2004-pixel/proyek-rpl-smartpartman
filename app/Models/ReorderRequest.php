<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Permintaan reorder (FR-15, Fase 6) — satu baris per satu sparepart.
 *
 * Alur status: MENUNGGU_PERSETUJUAN → DISETUJUI | DITOLAK.
 * (Status DRAFT tersedia di skema tetapi tidak dipakai Fase 6: permintaan
 * langsung diajukan saat dibuat — catat di docs/ASSUMPTIONS.md.)
 *
 * Model ini hanya baca/tulis baris; aturan bisnis (wajib alasan, transisi
 * status, dsb.) berada di App\Services\ReorderRequestService.
 */
final class ReorderRequest extends Model
{
    public const STATUS_PENDING = 'MENUNGGU_PERSETUJUAN';
    public const STATUS_APPROVED = 'DISETUJUI';
    public const STATUS_REJECTED = 'DITOLAK';

    /** @var string[] */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /**
     * @param array{request_number:string, sparepart_id:int, suggested_qty:?float, requested_qty:float,
     *              change_reason?:string|null, supplier_id?:int|null, prediction_id?:int|null} $data
     */
    public static function insert(array $data, int $actorId): int
    {
        self::execute(
            'INSERT INTO reorder_requests
                (request_number, sparepart_id, suggested_qty, requested_qty, change_reason,
                 status, prediction_id, supplier_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['request_number'],
                (int) $data['sparepart_id'],
                $data['suggested_qty'] ?? null,
                (float) $data['requested_qty'],
                $data['change_reason'] ?? null,
                self::STATUS_PENDING,
                $data['prediction_id'] ?? null,
                $data['supplier_id'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE r.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE r.request_number = ?', [$number]);
    }

    public static function exists(int $id): bool
    {
        return self::scalar('SELECT id FROM reorder_requests WHERE id = ?', [$id]) !== null;
    }

    /** Kunci baris (SELECT ... FOR UPDATE) — hanya di dalam transaksi database. */
    public static function lockForUpdate(int $id): ?array
    {
        return self::fetchOne('SELECT * FROM reorder_requests WHERE id = ? FOR UPDATE', [$id]);
    }

    /** Perbarui jumlah yang diajukan + alasan perubahan (diajukan ulang oleh Partman). */
    public static function updateRequested(int $id, float $requestedQty, ?string $changeReason): void
    {
        self::execute(
            'UPDATE reorder_requests SET requested_qty = ?, change_reason = ? WHERE id = ?',
            [$requestedQty, $changeReason, $id]
        );
    }

    /** Catat hasil peninjauan Kepala Bengkel. */
    public static function markReviewed(
        int $id,
        string $status,
        int $reviewerId,
        ?string $reviewNote
    ): void {
        self::execute(
            'UPDATE reorder_requests
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
              WHERE id = ?',
            [$status, $reviewerId, $reviewNote, $id]
        );
    }

    /**
     * Daftar permintaan (untuk halaman daftar).
     *
     * @param array{status?:string, search?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilter($filters);

        $total = (int) self::scalar(
            'SELECT COUNT(*) FROM reorder_requests r
               JOIN spareparts s ON s.id = r.sparepart_id' . $where,
            $params
        );

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            self::selectHeader() . $where . ' ORDER BY r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Jumlah per status (untuk ringkasan halaman & notifikasi). @return array<string,int> */
    public static function countByStatus(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach (self::fetchAll('SELECT status, COUNT(*) AS total FROM reorder_requests GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }
        return $out;
    }

    /** PO yang dibuat dari permintaan reorder ini (bila ada). @return array<int,array<string,mixed>> */
    public static function purchaseOrders(int $reorderId): array
    {
        return self::fetchAll(
            'SELECT po.id, po.po_number, po.status, po.payment_status, po.total_amount, po.created_at
               FROM purchase_orders po
              WHERE po.reorder_request_id = ?
           ORDER BY po.id ASC',
            [$reorderId]
        );
    }

    /**
     * Sparepart AKTIF dengan stok di bawah minimum — panel "kebutuhan reorder"
     * agar Partman melihat kebutuhan sebelum membuat permintaan.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function needs(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return self::fetchAll(
            'SELECT s.id, s.code, s.name, s.unit, s.stock, s.min_stock,
                    (s.min_stock - s.stock) AS shortfall
               FROM spareparts s
              WHERE s.is_active = 1 AND s.min_stock > 0 AND s.stock < s.min_stock
           ORDER BY shortfall DESC, s.name ASC
              LIMIT ' . $limit
        );
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (r.request_number LIKE ? OR s.code LIKE ? OR s.name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }

    private static function selectHeader(): string
    {
        return 'SELECT r.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                       s.stock AS sparepart_stock, s.min_stock AS sparepart_min_stock,
                       s.is_active AS sparepart_active,
                       uc.full_name AS created_by_name, uc.username AS created_by_username,
                       ur.full_name AS reviewed_by_name, ur.username AS reviewed_by_username,
                       sup.name AS supplier_name, sup.code AS supplier_code
                  FROM reorder_requests r
             JOIN spareparts s ON s.id = r.sparepart_id
            LEFT JOIN users uc ON uc.id = r.created_by
            LEFT JOIN users ur ON ur.id = r.reviewed_by
            LEFT JOIN suppliers sup ON sup.id = r.supplier_id';
    }
}
