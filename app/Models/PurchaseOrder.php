<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Purchase Order (FR-16).
 *
 * Pembuatan/persetujuan/proses/pembayaran PO ditangani
 * App\Services\PurchaseOrderService (transaksi + audit). Model ini hanya
 * membaca & menulis baris, termasuk item dan akumulasi penerimaan parsial
 * (received_qty) yang diperbarui bersamaan dengan penyimpanan penerimaan.
 *
 * PO TIDAK PERNAH dihapus permanen dari aplikasi: PO dengan histori ditutup
 * lewat status DIBATALKAN (dengan alasan), bukan hard-delete.
 */
final class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING = 'MENUNGGU_PERSETUJUAN';
    public const STATUS_APPROVED = 'DISETUJUI';
    public const STATUS_REJECTED = 'DITOLAK';
    public const STATUS_PROCESSED = 'DIPROSES_ADMIN';
    public const STATUS_PARTIAL = 'SEBAGIAN_DITERIMA';
    public const STATUS_COMPLETE = 'SELESAI_DITERIMA';
    public const STATUS_CANCELLED = 'DIBATALKAN';

    /** @var string[] */
    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_PROCESSED, self::STATUS_PARTIAL,
        self::STATUS_COMPLETE, self::STATUS_CANCELLED,
    ];

    public const PAYMENT_UNPAID = 'BELUM_DIBAYAR';
    public const PAYMENT_PARTIAL = 'DIBAYAR_SEBAGIAN';
    public const PAYMENT_PAID = 'LUNAS';

    /** @var string[] */
    public const PAYMENT_STATUSES = [self::PAYMENT_UNPAID, self::PAYMENT_PARTIAL, self::PAYMENT_PAID];

    /**
     * @param array{po_number:string, supplier_id:int, reorder_request_id?:int|null, note?:string|null,
     *              total_amount:float} $data
     */
    public static function insert(array $data, int $actorId): int
    {
        self::execute(
            'INSERT INTO purchase_orders
                (po_number, supplier_id, reorder_request_id, status, payment_status,
                 total_amount, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['po_number'],
                (int) $data['supplier_id'],
                $data['reorder_request_id'] ?? null,
                self::STATUS_DRAFT,
                self::PAYMENT_UNPAID,
                (float) $data['total_amount'],
                $data['note'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    public static function insertItem(int $poId, int $sparepartId, float $quantity, float $unitPrice): int
    {
        self::execute(
            'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
             VALUES (?, ?, ?, ?, 0)',
            [$poId, $sparepartId, $quantity, $unitPrice]
        );
        return self::lastId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE po.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE po.po_number = ?', [$number]);
    }

    /** Kunci baris PO (SELECT ... FOR UPDATE) — hanya di dalam transaksi database. */
    public static function lockForUpdate(int $id): ?array
    {
        return self::fetchOne('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE', [$id]);
    }

    public static function exists(int $id): bool
    {
        return self::scalar('SELECT id FROM purchase_orders WHERE id = ?', [$id]) !== null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $poId): array
    {
        return self::fetchAll(
            'SELECT i.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    (i.quantity * i.unit_price) AS subtotal,
                    (i.quantity - i.received_qty) AS remaining_qty
               FROM purchase_order_items i
               JOIN spareparts s ON s.id = i.sparepart_id
              WHERE i.po_id = ?
           ORDER BY i.id ASC',
            [$poId]
        );
    }

    /** Kunci baris item PO tertentu (untuk penerimaan parsial). */
    public static function lockItem(int $poId, int $sparepartId): ?array
    {
        return self::fetchOne(
            'SELECT * FROM purchase_order_items WHERE po_id = ? AND sparepart_id = ? LIMIT 1 FOR UPDATE',
            [$poId, $sparepartId]
        );
    }

    /** Hapus semua item PO — hanya untuk draft yang sedang diedit Partman. */
    public static function deleteItems(int $poId): void
    {
        self::execute('DELETE FROM purchase_order_items WHERE po_id = ?', [$poId]);
    }

    /** Perbarui header draft (supplier, catatan, total) — hanya saat DRAFT. */
    public static function updateDraft(int $id, int $supplierId, ?string $note, float $totalAmount): void
    {
        self::execute(
            'UPDATE purchase_orders SET supplier_id = ?, note = ?, total_amount = ? WHERE id = ?',
            [$supplierId, $note, $totalAmount, $id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function paymentHistory(int $poId): array
    {
        return self::fetchAll(
            'SELECT h.*, COALESCE(u.full_name, \'-\') AS changed_by_name
               FROM purchase_payment_history h
          LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.purchase_order_id = ?
           ORDER BY h.id DESC',
            [$poId]
        );
    }

    /** Riwayat penerimaan parsial yang terkait dengan PO ini. @return array<int,array<string,mixed>> */
    public static function receipts(int $poId): array
    {
        return self::fetchAll(
            'SELECT r.id, r.receipt_number, r.receipt_date, r.receipt_type, r.invoice_number,
                    u.full_name AS recorded_by_name,
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM goods_receipt_items i WHERE i.receipt_id = r.id) AS quantity
               FROM goods_receipts r
          LEFT JOIN users u ON u.id = r.recorded_by
              WHERE r.purchase_order_id = ?
           ORDER BY r.id ASC',
            [$poId]
        );
    }

    /**
     * PO yang masih dapat menerima barang: disetujui, diproses admin, atau
     * sudah diterima sebagian — beserta item + sisa kuantitasnya.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openForReceipt(): array
    {
        $rows = self::fetchAll(
            self::selectHeader() . "
              WHERE po.status IN ('" . self::STATUS_APPROVED . "', '" . self::STATUS_PROCESSED . "', '" . self::STATUS_PARTIAL . "')
           ORDER BY po.id ASC"
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['items'] = array_values(array_filter(
                self::items((int) $row['id']),
                static fn (array $item): bool => (float) $item['remaining_qty'] > 0
            ));
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['items'] !== []
        ));
    }

    /**
     * @param array{status?:string, payment?:string, supplier_id?:int|null, search?:string, created_by?:int, exclude_status?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilter($filters);

        $total = (int) self::scalar(
            'SELECT COUNT(*) FROM purchase_orders po
               JOIN suppliers s ON s.id = po.supplier_id' . $where,
            $params
        );

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            self::selectHeader() . $where . ' ORDER BY po.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Catat perubahan status pembayaran (riwayat, Aturan Bisnis 17). */
    public static function insertPaymentHistory(int $poId, string $status, ?string $note, int $actorId): int
    {
        self::execute(
            'INSERT INTO purchase_payment_history (purchase_order_id, status, note, changed_by)
             VALUES (?, ?, ?, ?)',
            [$poId, $status, $note, $actorId]
        );
        return self::lastId();
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND po.status = ?';
            $params[] = $status;
        }

        $excludeStatus = trim((string) ($filters['exclude_status'] ?? ''));
        if (in_array($excludeStatus, self::STATUSES, true)) {
            $where .= ' AND po.status <> ?';
            $params[] = $excludeStatus;
        }

        $createdBy = (int) ($filters['created_by'] ?? 0);
        if ($createdBy > 0) {
            $where .= ' AND po.created_by = ?';
            $params[] = $createdBy;
        }

        $payment = trim((string) ($filters['payment'] ?? ''));
        if (in_array($payment, self::PAYMENT_STATUSES, true)) {
            $where .= ' AND po.payment_status = ?';
            $params[] = $payment;
        }

        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $where .= ' AND po.supplier_id = ?';
            $params[] = $supplierId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (po.po_number LIKE ? OR s.name LIKE ? OR po.note LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }

    private static function selectHeader(): string
    {
        return 'SELECT po.*, s.code AS supplier_code, s.name AS supplier_name,
                       s.address AS supplier_address, s.phone AS supplier_phone,
                       s.email AS supplier_email, s.contact_person AS supplier_contact,
                       uc.full_name AS created_by_name, uc.username AS created_by_username,
                       ua.full_name AS approved_by_name, ua.username AS approved_by_username,
                       up.full_name AS processed_by_name,
                       ux.full_name AS cancelled_by_name,
                       r.request_number AS reorder_number,
                       (SELECT COUNT(*) FROM purchase_order_items oi WHERE oi.po_id = po.id) AS item_count,
                       (SELECT COALESCE(SUM(oi.quantity - oi.received_qty), 0) FROM purchase_order_items oi
                         WHERE oi.po_id = po.id) AS total_remaining
                  FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN users uc ON uc.id = po.created_by
        LEFT JOIN users ua ON ua.id = po.approved_by
        LEFT JOIN users up ON up.id = po.processed_by
        LEFT JOIN users ux ON ux.id = po.cancelled_by
        LEFT JOIN reorder_requests r ON r.id = po.reorder_request_id';
    }
}
