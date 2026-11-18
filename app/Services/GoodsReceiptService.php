<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\GoodsReceipt;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StatusHistory;
use RuntimeException;

/**
 * Barang masuk / penerimaan barang (FR-06).
 *
 * Alur satu transaksi database:
 *   1. Periksa supplier (harus AKTIF — FR-11), sparepart, PO (bila ada).
 *   2. Buat nomor dokumen otomatis unik (GR-YYYYMM-####).
 *   3. Simpan header + item penerimaan.
 *   4. Catat pergerakan MASUK lewat StockLedger (stok bertambah + ledger
 *      dalam transaksi yang sama).
 *   5. Hanya penerimaan bertipe "pembelian" yang memperbarui tanggal
 *      pembelian terakhir & harga beli rata-rata (FR-06).
 *   6. (Fase 6) Penerimaan terkait PO memperbarui akumulasi diterima PO
 *      dan status penerimaannya — beserta riwayat statusnya.
 *
 * Bila langkah mana pun gagal, seluruh transaksi dibatalkan: stok TIDAK
 * berubah dan dokumen tidak tersimpan (FR-06 "stok bertambah hanya setelah
 * penyimpanan berhasil").
 */
final class GoodsReceiptService
{
    public const INVOICE_MAX = 50;
    public const NOTE_MAX = 500;
    public const REASON_MAX = 500;
    public const QTY_MAX = 9999999999.99;
    public const PRICE_MAX = 9999999999999.99;

    /**
     * @param array<string,mixed> $data Input form (tanggal, sparepart, qty, harga, supplier, invoice, ...)
     * @param array<string,mixed>|null $attachment Entri $_FILES['attachment'] (opsional)
     * @return int ID penerimaan
     *
     * @throws RuntimeException bila validasi/aturan bisnis dilanggar
     */
    public static function create(array $data, ?int $actorId, ?array $attachment = null): int
    {
        $clean = self::normalize($data);

        // 1. Supplier harus ada & AKTIF (FR-11: nonaktif tidak untuk transaksi baru).
        $supplier = SupplierService::ensureUsableForTransaction($clean['supplier_id']);

        // 2. Sparepart harus ada & aktif (sparepart nonaktif tidak menerima stok baru).
        $sparepart = Sparepart::find($clean['sparepart_id']);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        if ((int) $sparepart['is_active'] !== 1) {
            throw new RuntimeException(
                'Sparepart "' . (string) $sparepart['name'] . '" nonaktif dan tidak dapat menerima stok baru.'
            );
        }

        // 3. PO (bila diisi) harus sah; bila kosong → alasan wajib (Aturan Bisnis 10).
        $poItem = null;
        if ($clean['purchase_order_id'] !== null) {
            $poItem = self::resolvePo($clean['purchase_order_id'], $clean['supplier_id'], $clean['sparepart_id'], $clean['quantity']);
        } elseif ($clean['without_po_reason'] === null) {
            throw new RuntimeException('Penerimaan tanpa PO wajib menyertakan alasan.');
        }

        // Simpan bukti (di luar web root) SEBELUM transaksi DB; berkas dibuang bila transaksi gagal.
        $stored = AttachmentService::store($attachment, 'receipts');
        $attachmentPath = $stored['path'] ?? null;
        $attachmentName = $stored['name'] ?? null;

        try {
            $receiptId = Database::transaction(static function () use ($clean, $actorId, $poItem, $attachmentPath, $attachmentName, $sparepart, $supplier): int {
                $number = DocumentNumberService::next(DocumentNumberService::PREFIX_RECEIPT, $clean['receipt_date']);

                $receiptId = GoodsReceipt::insert([
                    'receipt_number'     => $number,
                    'receipt_date'       => $clean['receipt_date'],
                    'receipt_type'       => $clean['receipt_type'],
                    'supplier_id'        => $clean['supplier_id'],
                    'purchase_order_id'  => $clean['purchase_order_id'],
                    'without_po_reason'  => $clean['without_po_reason'],
                    'invoice_number'     => $clean['invoice_number'],
                    'note'               => $clean['note'],
                    'attachment_path'    => $attachmentPath,
                    'attachment_name'    => $attachmentName,
                ], (int) $actorId);

                GoodsReceipt::insertItem(
                    $receiptId,
                    $clean['sparepart_id'],
                    $clean['quantity'],
                    $clean['unit_price'],
                    $poItem !== null ? (int) $poItem['id'] : null
                );

                // Stok bertambah + ledger, dalam transaksi yang sama.
                $movement = StockLedger::apply(
                    $clean['sparepart_id'],
                    StockMovement::TYPE_IN,
                    $clean['quantity'],
                    $clean['receipt_date'],
                    'GOODS_RECEIPT',
                    $receiptId,
                    $number,
                    $clean['note'],
                    $actorId
                );

                // Hanya penerimaan bertipe pembelian yang memperbarui info pembelian.
                if ($clean['receipt_type'] === GoodsReceipt::TYPE_PURCHASE) {
                    Sparepart::applyPurchase(
                        $clean['sparepart_id'],
                        $clean['receipt_date'],
                        $clean['quantity'],
                        $clean['unit_price'],
                        $movement['stock_before'],
                        $movement['sparepart']['avg_purchase_price'] ?? null,
                        $actorId
                    );
                }

                if ($poItem !== null) {
                    self::applyPoReceipt(
                        (int) $poItem['id'],
                        $clean['quantity'],
                        $clean['sparepart_id'],
                        $actorId,
                        $number
                    );
                }

                AuditService::log(
                    'GOODS_RECEIPT_CREATE',
                    'goods_receipts',
                    (string) $receiptId,
                    sprintf(
                        'Penerimaan %s: %s %s x %s dari %s (stok %s → %s)',
                        $number,
                        $clean['quantity'],
                        (string) $sparepart['unit'],
                        $clean['sparepart_code'],
                        $supplier['name'],
                        StockLedger::formatQty($movement['stock_before']),
                        StockLedger::formatQty($movement['stock_after'])
                    ),
                    [
                        'stock' => $movement['stock_before'],
                    ],
                    [
                        'stock'            => $movement['stock_after'],
                        'receipt_number'   => $number,
                        'receipt_type'     => $clean['receipt_type'],
                        'quantity'         => $clean['quantity'],
                        'unit_price'       => $clean['unit_price'],
                        'supplier_id'      => $clean['supplier_id'],
                        'sparepart_id'     => $clean['sparepart_id'],
                        'purchase_order_id' => $clean['purchase_order_id'],
                        'has_attachment'   => $attachmentPath !== null,
                    ]
                );

                return $receiptId;
            });
        } catch (\Throwable $e) {
            // Jangan tinggalkan berkas yatim bila transaksi gagal.
            AttachmentService::delete($attachmentPath);
            throw $e;
        }

        return $receiptId;
    }

    /**
     * Validasi masukan + normalisasi. Nilai yang dikembalikan sudah bersih.
     *
     * @param array<string,mixed> $data
     * @return array{receipt_date:string, receipt_type:string, supplier_id:int, sparepart_id:int,
     *               sparepart_code:string, quantity:float, unit_price:float, invoice_number:string|null,
     *               note:string|null, without_po_reason:string|null, purchase_order_id:int|null}
     */
    public static function normalize(array $data): array
    {
        $date = MasterRules::parseDate($data['receipt_date'] ?? null);
        if ($date === null) {
            throw new RuntimeException('Tanggal penerimaan tidak valid.');
        }
        if ($date < '2000-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
            throw new RuntimeException('Tanggal penerimaan tidak masuk akal (periksa tahunnya).');
        }

        $type = trim((string) ($data['receipt_type'] ?? ''));
        if ($type === '') {
            $type = GoodsReceipt::TYPE_PURCHASE;
        }
        if (!in_array($type, GoodsReceipt::TYPES, true)) {
            throw new RuntimeException('Jenis penerimaan tidak dikenal.');
        }

        $supplierId = (int) ($data['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new RuntimeException('Supplier wajib dipilih.');
        }

        $sparepartId = (int) ($data['sparepart_id'] ?? 0);
        if ($sparepartId <= 0) {
            throw new RuntimeException('Sparepart wajib dipilih.');
        }
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }

        $quantity = MasterRules::parseDecimal($data['quantity'] ?? null);
        if ($quantity === null || $quantity <= 0) {
            throw new RuntimeException('Jumlah diterima harus lebih dari 0.');
        }
        if ($quantity > self::QTY_MAX) {
            throw new RuntimeException('Jumlah diterima terlalu besar.');
        }

        $unitPrice = MasterRules::parseDecimal($data['unit_price'] ?? null) ?? 0.0;
        if ($unitPrice < 0) {
            throw new RuntimeException('Harga beli per unit tidak boleh negatif.');
        }
        if ($unitPrice > self::PRICE_MAX) {
            throw new RuntimeException('Harga beli per unit terlalu besar.');
        }

        $invoice = MasterRules::text($data['invoice_number'] ?? '');
        if (mb_strlen($invoice) > self::INVOICE_MAX) {
            throw new RuntimeException('Nomor invoice maksimal ' . self::INVOICE_MAX . ' karakter.');
        }

        $note = MasterRules::text($data['note'] ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan maksimal ' . self::NOTE_MAX . ' karakter.');
        }

        $reason = MasterRules::text($data['without_po_reason'] ?? '');
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan tanpa PO maksimal ' . self::REASON_MAX . ' karakter.');
        }

        $poId = (int) ($data['purchase_order_id'] ?? 0);

        return [
            'receipt_date'       => $date,
            'receipt_type'       => $type,
            'supplier_id'        => $supplierId,
            'sparepart_id'       => $sparepartId,
            'sparepart_code'     => (string) $sparepart['code'],
            'quantity'           => round($quantity, 2),
            'unit_price'         => round($unitPrice, 2),
            'invoice_number'     => $invoice === '' ? null : $invoice,
            'note'               => $note === '' ? null : $note,
            'without_po_reason'  => $reason === '' ? null : $reason,
            'purchase_order_id'  => $poId > 0 ? $poId : null,
        ];
    }

    /**
     * Pastikan PO sah untuk penerimaan ini (Aturan Bisnis 11 & 12).
     *
     * Fase 6: penerimaan hanya boleh menyangkut PO yang SUDAH DISETUJUI
     * (disetujui / diproses admin / diterima sebagian). PO draf, menunggu
     * persetujuan, ditolak, dibatalkan, atau yang sudah selesai diterima
     * ditolak — dan jumlah diterima tidak pernah boleh melebihi sisa PO
     * (ASSUMPTIONS C6/C8: kelebihan selalu ditolak, tanpa pengecualian).
     *
     * @return array<string,mixed> baris purchase_order_items yang cocok
     */
    private static function resolvePo(int $poId, int $supplierId, int $sparepartId, float $quantity): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT id, po_number, supplier_id, status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if ($po === false) {
            throw new RuntimeException('Purchase Order tidak ditemukan.');
        }
        $openStatuses = ['DISETUJUI', 'DIPROSES_ADMIN', 'SEBAGIAN_DITERIMA'];
        if (!in_array((string) $po['status'], $openStatuses, true)) {
            throw new RuntimeException(sprintf(
                'Purchase Order %s berstatus "%s" — penerimaan hanya dapat dicatat untuk PO yang sudah disetujui.',
                (string) $po['po_number'],
                ReportService::label('po', (string) $po['status'])
            ));
        }
        if ((int) $po['supplier_id'] !== $supplierId) {
            throw new RuntimeException('Supplier penerimaan berbeda dengan supplier Purchase Order.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, quantity, received_qty FROM purchase_order_items
              WHERE po_id = ? AND sparepart_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$poId, $sparepartId]);
        $item = $stmt->fetch();
        if ($item === false) {
            throw new RuntimeException('Sparepart ini tidak termasuk dalam item Purchase Order.');
        }

        $remaining = round((float) $item['quantity'] - (float) $item['received_qty'], 2);
        if ($quantity > $remaining) {
            throw new RuntimeException(sprintf(
                'Jumlah penerimaan (%s) melebihi sisa kuantitas PO (%s).',
                StockLedger::formatQty($quantity),
                StockLedger::formatQty($remaining)
            ));
        }

        return $item;
    }

    /**
     * Perbarui akumulasi penerimaan PO + statusnya (penerimaan parsial, Aturan 11-12).
     *
     * Perubahan status akibat penerimaan ikut tercatat pada riwayat status
     * (aktor, waktu, status lama → baru, rujukan nomor penerimaan).
     */
    private static function applyPoReceipt(
        int $poItemId,
        float $quantity,
        int $sparepartId,
        ?int $actorId,
        string $receiptNumber
    ): void {
        $pdo = Database::pdo();

        $pdo->prepare('UPDATE purchase_order_items SET received_qty = received_qty + ? WHERE id = ?')
            ->execute([$quantity, $poItemId]);

        $stmt = $pdo->prepare('SELECT po_id FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $poId = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT SUM(CASE WHEN received_qty >= quantity THEN 1 ELSE 0 END) AS selesai,
                    SUM(CASE WHEN received_qty > 0 THEN 1 ELSE 0 END) AS sebagian,
                    COUNT(*) AS total
               FROM purchase_order_items WHERE po_id = ?'
        );
        $stmt->execute([$poId]);
        $agg = $stmt->fetch() ?: ['selesai' => 0, 'sebagian' => 0, 'total' => 0];

        if ((int) $agg['total'] > 0 && (int) $agg['selesai'] === (int) $agg['total']) {
            $status = 'SELESAI_DITERIMA';
        } elseif ((int) $agg['sebagian'] > 0) {
            $status = 'SEBAGIAN_DITERIMA';
        } else {
            $status = null;
        }

        if ($status === null) {
            return;
        }

        // Kunci header PO agar status lama yang dibaca untuk riwayat statis
        // sepanjang transaksi penerimaan ini.
        $stmt = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if ($po === false) {
            return;
        }

        // Penerimaan hanya dicatat untuk PO terbuka (sudah disetujui);
        // status DRAFT/DITOLAK/DIBATALKAN/SELESAI tidak disentuh di sini.
        $openStatuses = ['DISETUJUI', 'DIPROSES_ADMIN', 'SEBAGIAN_DITERIMA'];
        $oldStatus = (string) $po['status'];
        if (!in_array($oldStatus, $openStatuses, true) || $oldStatus === $status) {
            return;
        }

        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')
            ->execute([$status, $poId]);

        $actorName = null;
        if ($actorId !== null) {
            $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
            $nameStmt->execute([$actorId]);
            $name = $nameStmt->fetchColumn();
            $actorName = $name === false ? null : (string) $name;
        }

        StatusHistory::record(
            StatusHistory::TYPE_PO,
            $poId,
            $oldStatus,
            $status,
            $actorId,
            $actorName,
            'Penerimaan barang ' . $receiptNumber
        );
    }
}
