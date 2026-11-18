<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Barang masuk / penerimaan barang (FR-06, tabel goods_receipts + items).
 *
 * Model ini hanya baca/tulis baris. Aturan bisnis (penomoran, wajib alasan
 * bila tanpa PO, penambahan stok lewat ledger, pembaruan tanggal pembelian)
 * berada di App\Services\GoodsReceiptService.
 */
final class GoodsReceipt extends Model
{
    public const TYPE_PURCHASE = 'pembelian';
    public const TYPE_OTHER = 'lainnya';

    /** @var string[] */
    public const TYPES = [self::TYPE_PURCHASE, self::TYPE_OTHER];

    /**
     * @param array{receipt_number:string, receipt_date:string, receipt_type:string, supplier_id:int,
     *              purchase_order_id?:int|null, without_po_reason?:string|null, invoice_number?:string|null,
     *              note?:string|null, attachment_path?:string|null, attachment_name?:string|null} $data
     */
    public static function insert(array $data, int $actorId): int
    {
        self::execute(
            'INSERT INTO goods_receipts
                (receipt_number, receipt_date, receipt_type, supplier_id, purchase_order_id,
                 without_po_reason, invoice_number, note, attachment_path, attachment_name, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['receipt_number'],
                $data['receipt_date'],
                $data['receipt_type'],
                (int) $data['supplier_id'],
                $data['purchase_order_id'] ?? null,
                $data['without_po_reason'] ?? null,
                $data['invoice_number'] ?? null,
                $data['note'] ?? null,
                $data['attachment_path'] ?? null,
                $data['attachment_name'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    public static function insertItem(int $receiptId, int $sparepartId, float $quantity, float $unitPrice, ?int $poItemId = null): int
    {
        self::execute(
            'INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price, po_item_id)
             VALUES (?, ?, ?, ?, ?)',
            [$receiptId, $sparepartId, $quantity, $unitPrice, $poItemId]
        );
        return self::lastId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::selectHeader() . ' WHERE r.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $receiptId): array
    {
        return self::fetchAll(
            'SELECT i.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    s.purchase_price, s.avg_purchase_price, s.stock AS sparepart_stock
               FROM goods_receipt_items i
               JOIN spareparts s ON s.id = i.sparepart_id
              WHERE i.receipt_id = ?
           ORDER BY i.id ASC',
            [$receiptId]
        );
    }

    /**
     * @param array{search?:string, supplier_id?:int|null, receipt_type?:string|null,
     *              date_from?:string|null, date_to?:string|null} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilter($filters);

        $total = (int) self::scalar(
            'SELECT COUNT(*) FROM goods_receipts r
               JOIN suppliers sup ON sup.id = r.supplier_id' . $where,
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

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private static function buildFilter(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (r.receipt_number LIKE ? OR r.invoice_number LIKE ? OR sup.name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $where .= ' AND r.supplier_id = ?';
            $params[] = $supplierId;
        }

        $type = trim((string) ($filters['receipt_type'] ?? ''));
        if (in_array($type, self::TYPES, true)) {
            $where .= ' AND r.receipt_type = ?';
            $params[] = $type;
        }

        $from = self::normalizeDate($filters['date_from'] ?? null);
        if ($from !== null) {
            $where .= ' AND r.receipt_date >= ?';
            $params[] = $from;
        }

        $to = self::normalizeDate($filters['date_to'] ?? null);
        if ($to !== null) {
            $where .= ' AND r.receipt_date <= ?';
            $params[] = $to;
        }

        return [$where, $params];
    }

    private static function selectHeader(): string
    {
        return 'SELECT r.id, r.receipt_number, r.receipt_date, r.receipt_type, r.supplier_id,
                       r.purchase_order_id, r.without_po_reason, r.invoice_number, r.note,
                       r.attachment_path, r.attachment_name, r.recorded_by, r.created_at,
                       sup.code AS supplier_code, sup.name AS supplier_name, sup.is_active AS supplier_active,
                       u.full_name AS recorded_by_name, u.username AS recorded_by_username,
                       CASE WHEN r.purchase_order_id IS NOT NULL THEN 1 ELSE 0 END AS has_po,
                       (SELECT po.po_number FROM purchase_orders po WHERE po.id = r.purchase_order_id) AS po_number,
                       (SELECT COALESCE(SUM(i.quantity), 0) FROM goods_receipt_items i WHERE i.receipt_id = r.id) AS total_quantity,
                       (SELECT i.sparepart_id FROM goods_receipt_items i WHERE i.receipt_id = r.id ORDER BY i.id ASC LIMIT 1) AS sparepart_id,
                       (SELECT i.unit_price FROM goods_receipt_items i WHERE i.receipt_id = r.id ORDER BY i.id ASC LIMIT 1) AS unit_price,
                       (SELECT s.code FROM goods_receipt_items i JOIN spareparts s ON s.id = i.sparepart_id
                         WHERE i.receipt_id = r.id ORDER BY i.id ASC LIMIT 1) AS sparepart_code,
                       (SELECT s.name FROM goods_receipt_items i JOIN spareparts s ON s.id = i.sparepart_id
                         WHERE i.receipt_id = r.id ORDER BY i.id ASC LIMIT 1) AS sparepart_name
                  FROM goods_receipts r
                  JOIN suppliers sup ON sup.id = r.supplier_id
             LEFT JOIN users u ON u.id = r.recorded_by';
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
