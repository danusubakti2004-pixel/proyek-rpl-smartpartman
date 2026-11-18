<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ReorderRequest;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\Sparepart;
use App\Models\StatusHistory;
use RuntimeException;

/**
 * Purchase Order (FR-16, Aturan Bisnis 13-17, Fase 6).
 *
 * Alur status PO — TIGA status dipisah, bukan satu:
 *   a) Persetujuan : DRAFT → MENUNGGU_PERSETUJUAN → DISETUJUI | DITOLAK
 *                    (+ DIBATALKAN dengan alasan; tidak pernah dihapus)
 *   b) Penerimaan  : DISETUJUI/DIPROSES_ADMIN → SEBAGIAN_DITERIMA
 *                    → SELESAI_DITERIMA (lewat GoodsReceiptService)
 *   c) Pembayaran  : BELUM_DIBAYAR | DIBAYAR_SEBAGIAN | LUNAS (Admin)
 *
 * ATURAN KUNCI:
 *   - Persetujuan/aktivasi PO TIDAK menambah stok (stok hanya dari
 *     penerimaan barang — FR-16 ayat 9, Aturan 13).
 *   - Kuantitas PO tidak dapat diubah setelah disetujui; kelebihan
 *     penerimaan di atas sisa PO SELALU ditolak (ASSUMPTIONS C6/C8).
 *   - PO tidak pernah di-hard-delete; penutupan lewat DIBATALKAN + alasan.
 *   - Setiap perubahan status tercatat: aktor, waktu, status sebelumnya →
 *     berikutnya, alasan (purchase_status_history, append-only).
 */
final class PurchaseOrderService
{
    public const NOTE_MAX = 500;
    public const REASON_MAX = 500;
    public const MAX_ITEMS = 20;
    public const QTY_MAX = 9999999999.99;
    public const PRICE_MAX = 9999999999999.99;

    /**
     * Buat draft PO baru (Partman).
     *
     * @param array<string,mixed> $data supplier_id, note?, reorder_request_id?,
     *                                   items: list of {sparepart_id, quantity, unit_price}
     * @return int ID PO
     *
     * @throws RuntimeException
     */
    public static function create(array $data, int $actorId): int
    {
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        SupplierService::ensureUsableForTransaction($supplierId);

        $note = self::normalizeNote($data['note'] ?? '');
        $items = self::normalizeItems($data['items'] ?? null);
        $total = self::totalOf($items);

        $reorderId = (int) ($data['reorder_request_id'] ?? 0);
        $reorder = null;
        if ($reorderId > 0) {
            $reorder = self::ensureUsableReorder($reorderId);
            // Item PO harus sejalan dengan sparepart pada permintaan.
            $requestedId = (int) $reorder['sparepart_id'];
            $itemIds = array_map(static fn (array $item): int => $item['sparepart_id'], $items);
            if (!in_array($requestedId, $itemIds, true)) {
                throw new RuntimeException(
                    'PO dari permintaan reorder wajib memuat sparepart yang sama dengan permintaan.'
                );
            }
        }

        return Database::transaction(static function () use (
            $supplierId, $note, $items, $total, $reorderId, $actorId
        ): int {
            $number = DocumentNumberService::next(DocumentNumberService::PREFIX_PO);

            $poId = PurchaseOrder::insert([
                'po_number'          => $number,
                'supplier_id'        => $supplierId,
                'reorder_request_id' => $reorderId > 0 ? $reorderId : null,
                'note'               => $note,
                'total_amount'       => $total,
            ], $actorId);

            foreach ($items as $item) {
                PurchaseOrder::insertItem($poId, $item['sparepart_id'], $item['quantity'], $item['unit_price']);
            }

            StatusHistory::record(
                StatusHistory::TYPE_PO, $poId, null, PurchaseOrder::STATUS_DRAFT,
                $actorId, self::actorName($actorId)
            );

            AuditService::log(
                'PO_CREATE', 'purchase_orders', (string) $poId,
                sprintf('Draft PO %s dibuat: %d item, total %s', $number, count($items), rupiah($total)),
                null,
                [
                    'po_number'    => $number,
                    'supplier_id'  => $supplierId,
                    'item_count'   => count($items),
                    'total_amount' => $total,
                    'status'       => PurchaseOrder::STATUS_DRAFT,
                ]
            );

            return $poId;
        });
    }

    /**
     * Ubah draft PO (Partman, hanya selama masih DRAFT dan milik pengaju).
     *
     * @param array<string,mixed> $data sama dengan create()
     *
     * @throws RuntimeException
     */
    public static function updateDraft(int $poId, array $data, int $actorId): void
    {
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        SupplierService::ensureUsableForTransaction($supplierId);

        $note = self::normalizeNote($data['note'] ?? '');
        $items = self::normalizeItems($data['items'] ?? null);
        $total = self::totalOf($items);

        Database::transaction(static function () use ($poId, $supplierId, $note, $items, $total, $actorId): void {
            $po = PurchaseOrder::lockForUpdate($poId);
            if ($po === null) {
                throw new RuntimeException('Purchase Order tidak ditemukan.');
            }
            self::ensureDraft($po, $actorId);

            PurchaseOrder::deleteItems($poId);
            foreach ($items as $item) {
                PurchaseOrder::insertItem($poId, $item['sparepart_id'], $item['quantity'], $item['unit_price']);
            }
            PurchaseOrder::updateDraft($poId, $supplierId, $note, $total);

            AuditService::log(
                'PO_UPDATE_DRAFT', 'purchase_orders', (string) $poId,
                sprintf(
                    'Draft PO %s diubah: supplier #%d, %d item, total %s',
                    (string) $po['po_number'], $supplierId, count($items), rupiah($total)
                ),
                ['supplier_id' => (int) $po['supplier_id'], 'total_amount' => (float) $po['total_amount']],
                ['supplier_id' => $supplierId, 'total_amount' => $total]
            );
        });
    }

    /** Ajukan draft ke Kepala Bengkel (Partman). @throws RuntimeException */
    public static function submit(int $poId, int $actorId): void
    {
        self::transition($poId, $actorId, PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PENDING,
            'PO_SUBMIT',
            static function (array $po) use ($actorId): void {
                if ((int) $po['created_by'] !== $actorId) {
                    throw new RuntimeException('Hanya pembuat PO yang dapat mengajukannya.');
                }
            },
            static function (int $id): void {
                Database::pdo()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')
                    ->execute([PurchaseOrder::STATUS_PENDING, $id]);
            },
            null
        );
    }

    /** Setujui PO (Kepala Bengkel). TIDAK menambah stok. @throws RuntimeException */
    public static function approve(int $poId, int $actorId): void
    {
        self::transition($poId, $actorId, PurchaseOrder::STATUS_PENDING, PurchaseOrder::STATUS_APPROVED,
            'PO_APPROVE',
            static function (array $po): void {
                if ((string) $po['status'] !== PurchaseOrder::STATUS_PENDING) {
                    throw new RuntimeException('Hanya PO "Menunggu persetujuan" yang dapat disetujui.');
                }
            },
            static function (int $id) use ($actorId): void {
                $pdo = Database::pdo();
                $pdo->prepare(
                    'UPDATE purchase_orders SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?'
                )->execute([PurchaseOrder::STATUS_APPROVED, $actorId, $id]);
            },
            null
        );
    }

    /** Tolak PO (Kepala Bengkel) — alasan wajib. @throws RuntimeException */
    public static function reject(int $poId, string $reason, int $actorId): void
    {
        $reason = MasterRules::text($reason);
        if ($reason === '') {
            throw new RuntimeException('Penolakan PO wajib menyertakan alasan.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan penolakan maksimal ' . self::REASON_MAX . ' karakter.');
        }

        self::transition($poId, $actorId, PurchaseOrder::STATUS_PENDING, PurchaseOrder::STATUS_REJECTED,
            'PO_REJECT',
            static function (array $po): void {
                if ((string) $po['status'] !== PurchaseOrder::STATUS_PENDING) {
                    throw new RuntimeException('Hanya PO "Menunggu persetujuan" yang dapat ditolak.');
                }
            },
            static function (int $id) use ($reason): void {
                $pdo = Database::pdo();
                $pdo->prepare('UPDATE purchase_orders SET status = ?, rejected_reason = ? WHERE id = ?')
                    ->execute([PurchaseOrder::STATUS_REJECTED, $reason, $id]);
            },
            $reason
        );
    }

    /** Proses administrasi PO disetujui (Admin). @throws RuntimeException */
    public static function process(int $poId, int $actorId): void
    {
        self::transition($poId, $actorId, PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
            'PO_PROCESS',
            static function (array $po): void {
                if ((string) $po['status'] !== PurchaseOrder::STATUS_APPROVED) {
                    throw new RuntimeException('Hanya PO "Disetujui" yang dapat diproses administrasinya.');
                }
            },
            static function (int $id) use ($actorId): void {
                $pdo = Database::pdo();
                $pdo->prepare(
                    'UPDATE purchase_orders SET status = ?, processed_by = ?, processed_at = NOW() WHERE id = ?'
                )->execute([PurchaseOrder::STATUS_PROCESSED, $actorId, $id]);
            },
            null
        );
    }

    /**
     * Perbarui status pembayaran (Admin) + riwayatnya.
     *
     * Ini BUKAN modul akuntansi: hanya status + catatan teks; tidak ada
     * buku besar, kas/bank, atau rekonsiliasi (ASSUMPTIONS C5).
     *
     * @throws RuntimeException
     */
    public static function updatePayment(int $poId, string $status, string $note, int $actorId): void
    {
        $status = trim($status);
        if (!in_array($status, PurchaseOrder::PAYMENT_STATUSES, true)) {
            throw new RuntimeException('Status pembayaran tidak dikenal.');
        }
        $note = MasterRules::text($note);
        if (mb_strlen($note) > self::REASON_MAX) {
            throw new RuntimeException('Catatan pembayaran maksimal ' . self::REASON_MAX . ' karakter.');
        }

        Database::transaction(static function () use ($poId, $status, $note, $actorId): void {
            $po = PurchaseOrder::lockForUpdate($poId);
            if ($po === null) {
                throw new RuntimeException('Purchase Order tidak ditemukan.');
            }

            $payable = [
                PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
                PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_COMPLETE,
            ];
            if (!in_array((string) $po['status'], $payable, true)) {
                throw new RuntimeException(
                    'Status pembayaran hanya dapat diubah untuk PO yang sudah disetujui/diproses.'
                );
            }

            $old = (string) $po['payment_status'];
            if ($old === $status) {
                throw new RuntimeException('Status pembayaran tidak berubah.');
            }

            $pdo = Database::pdo();
            $pdo->prepare('UPDATE purchase_orders SET payment_status = ? WHERE id = ?')
                ->execute([$status, $poId]);

            PurchaseOrder::insertPaymentHistory($poId, $status, $note === '' ? null : $note, $actorId);

            AuditService::log(
                'PO_PAYMENT_UPDATE', 'purchase_orders', (string) $poId,
                sprintf(
                    'Pembayaran PO %s: %s → %s%s',
                    (string) $po['po_number'], $old, $status,
                    $note !== '' ? ' — catatan: ' . $note : ''
                ),
                ['payment_status' => $old],
                ['payment_status' => $status, 'note' => $note === '' ? null : $note]
            );
        });
    }

    /**
     * Batalkan PO (dengan alasan wajib) — pengganti hard-delete.
     *
     * Otorisasi (ASSUMPTIONS C7):
     *   - Partman (pembuat): selama DRAFT / MENUNGGU_PERSETUJUAN.
     *   - Admin: DISETUJUI / DIPROSES_ADMIN (belum ada barang diterima).
     *   - PO yang sudah mulai diterima (sebagian/penuh) TIDAK dapat dibatalkan.
     *
     * @throws RuntimeException
     */
    public static function cancel(int $poId, string $reason, int $actorId, bool $byAdmin): void
    {
        $reason = MasterRules::text($reason);
        if ($reason === '') {
            throw new RuntimeException('Pembatalan PO wajib menyertakan alasan.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan pembatalan maksimal ' . self::REASON_MAX . ' karakter.');
        }

        self::transition($poId, $actorId, null, PurchaseOrder::STATUS_CANCELLED,
            'PO_CANCEL',
            static function (array $po) use ($actorId, $byAdmin): void {
                $status = (string) $po['status'];

                if (in_array($status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PENDING], true)) {
                    if (!$byAdmin && (int) $po['created_by'] !== $actorId) {
                        throw new RuntimeException('Hanya pembuat PO atau Admin yang dapat membatalkannya.');
                    }
                    return;
                }
                if ($byAdmin && in_array($status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED], true)) {
                    // Admin membatalkan PO yang belum ada penerimaan barangnya.
                    return;
                }
                throw new RuntimeException(
                    'PO berstatus "' . ReportService::label('po', $status) . '" tidak dapat dibatalkan.'
                );
            },
            static function (int $id) use ($actorId, $reason): void {
                $pdo = Database::pdo();
                $pdo->prepare(
                    'UPDATE purchase_orders SET status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?'
                )->execute([PurchaseOrder::STATUS_CANCELLED, $actorId, $reason, $id]);
            },
            $reason
        );
    }

    /**
     * Rekomendasi supplier untuk satu sparepart — berdasar harga beli &
     * riwayat pembelian bila datanya tersedia.
     *
     * Keputusan akhir tetap di tangan Partman: rekomendasi hanya petunjuk.
     *
     * @return array<int,array<string,mixed>> peringkat tertinggi di awal
     */
    public static function supplierRecommendations(int $sparepartId, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $pdo = Database::pdo();

        // Riwayat pembelian per supplier (hanya penerimaan bertipe pembelian).
        $stmt = $pdo->prepare(
            'SELECT s.id, s.code, s.name, COUNT(*) AS times, MAX(gr.receipt_date) AS last_date,
                    (SELECT gri2.unit_price
                       FROM goods_receipt_items gri2
                       JOIN goods_receipts gr2 ON gr2.id = gri2.receipt_id
                      WHERE gri2.sparepart_id = ? AND gr2.supplier_id = s.id
                        AND gr2.receipt_type = \'pembelian\'
                   ORDER BY gr2.receipt_date DESC, gr2.id DESC LIMIT 1) AS last_price
               FROM goods_receipt_items gri
               JOIN goods_receipts gr ON gr.id = gri.receipt_id AND gr.receipt_type = \'pembelian\'
               JOIN suppliers s ON s.id = gr.supplier_id
              WHERE gri.sparepart_id = ? AND s.is_active = 1
           GROUP BY s.id, s.code, s.name
           ORDER BY times DESC, last_date DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$sparepartId, $sparepartId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'supplier_id' => (int) $row['id'],
                'code'        => (string) $row['code'],
                'name'        => (string) $row['name'],
                'times'       => (int) $row['times'],
                'last_date'   => (string) ($row['last_date'] ?? ''),
                'last_price'  => $row['last_price'] !== null ? (float) $row['last_price'] : null,
                'is_default'  => false,
            ];
        }

        // Supplier default sparepart (bila aktif & belum ada di riwayat).
        $sparepart = Sparepart::find($sparepartId);
        $defaultId = (int) ($sparepart['supplier_id'] ?? 0);
        $known = array_column($out, 'supplier_id');
        if ($defaultId > 0 && !in_array($defaultId, $known, true)) {
            $supplier = Supplier::find($defaultId);
            if ($supplier !== null && (int) $supplier['is_active'] === 1) {
                $out[] = [
                    'supplier_id' => $defaultId,
                    'code'        => (string) $supplier['code'],
                    'name'        => (string) $supplier['name'],
                    'times'       => 0,
                    'last_date'   => '',
                    'last_price'  => null,
                    'is_default'  => true,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Bantuan internal
    // ------------------------------------------------------------------

    /**
     * Mesin transisi status generik: kunci baris, validasi, tulis perubahan,
     * catat riwayat status + audit. Tidak pernah menyentuh stok.
     *
     * @param \Closure(array<string,mixed>):void $guard   validasi tambahan (sudah bertransaksi)
     * @param \Closure(int):void                 $write   pembaruan kolom status
     */
    private static function transition(
        int $poId,
        int $actorId,
        ?string $expectFrom,
        string $to,
        string $auditActivity,
        \Closure $guard,
        \Closure $write,
        ?string $reason
    ): void {
        Database::transaction(static function () use (
            $poId, $actorId, $expectFrom, $to, $auditActivity, $guard, $write, $reason
        ): void {
            $po = PurchaseOrder::lockForUpdate($poId);
            if ($po === null) {
                throw new RuntimeException('Purchase Order tidak ditemukan.');
            }

            $from = (string) $po['status'];
            if ($expectFrom !== null && $from !== $expectFrom) {
                throw new RuntimeException(
                    'Status PO "' . (string) $po['po_number'] . '" saat ini "' .
                    ReportService::label('po', $from) . '" — transisi ke "' .
                    ReportService::label('po', $to) . '" tidak diizinkan.'
                );
            }
            if ($from === $to) {
                throw new RuntimeException('Status PO tidak berubah.');
            }

            $guard($po);
            $write($poId);

            StatusHistory::record(
                StatusHistory::TYPE_PO, $poId, $from, $to,
                $actorId, self::actorName($actorId), $reason
            );

            AuditService::log(
                $auditActivity, 'purchase_orders', (string) $poId,
                sprintf(
                    'PO %s: %s → %s%s',
                    (string) $po['po_number'],
                    ReportService::label('po', $from),
                    ReportService::label('po', $to),
                    $reason !== '' && $reason !== null ? ' — alasan: ' . $reason : ''
                ),
                ['status' => $from],
                ['status' => $to, 'reason' => $reason]
            );
        });
    }

    /**
     * Validasi & normalisasi item PO dari input formulir.
     *
     * @param mixed $raw daftar item [{sparepart_id, quantity, unit_price}, ...]
     * @return array<int,array{sparepart_id:int,quantity:float,unit_price:float}>
     *
     * @throws RuntimeException
     */
    public static function normalizeItems(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException('PO wajib memuat minimal satu item.');
        }
        if (count($raw) > self::MAX_ITEMS) {
            throw new RuntimeException('Jumlah item PO maksimal ' . self::MAX_ITEMS . '.');
        }

        $items = [];
        $seen = [];
        foreach (array_values($raw) as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $sparepartId = (int) ($entry['sparepart_id'] ?? 0);

            $sparepart = Sparepart::find($sparepartId);
            if ($sparepart === null) {
                throw new RuntimeException('Sparepart tidak ditemukan.');
            }
            if ((int) $sparepart['is_active'] !== 1) {
                throw new RuntimeException('Sparepart "' . (string) $sparepart['name'] . '" nonaktif dan tidak dapat dimasukkan PO.');
            }
            if (isset($seen[$sparepartId])) {
                throw new RuntimeException(
                    'Sparepart "' . (string) $sparepart['code'] . '" terduplikasi dalam satu PO.'
                );
            }
            $seen[$sparepartId] = true;

            $qty = MasterRules::parseDecimal($entry['quantity'] ?? null);
            if ($qty === null || $qty <= 0) {
                throw new RuntimeException('Jumlah item "' . (string) $sparepart['code'] . '" harus lebih dari 0.');
            }
            if ($qty > self::QTY_MAX) {
                throw new RuntimeException('Jumlah item "' . (string) $sparepart['code'] . '" terlalu besar.');
            }

            $price = MasterRules::parseDecimal($entry['unit_price'] ?? null) ?? 0.0;
            if ($price < 0) {
                throw new RuntimeException('Harga beli "' . (string) $sparepart['code'] . '" tidak boleh negatif.');
            }
            if ($price > self::PRICE_MAX) {
                throw new RuntimeException('Harga beli "' . (string) $sparepart['code'] . '" terlalu besar.');
            }

            $items[] = [
                'sparepart_id' => $sparepartId,
                'quantity'     => round($qty, 2),
                'unit_price'   => round($price, 2),
            ];
        }

        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function totalOf(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += $item['quantity'] * $item['unit_price'];
        }
        return round($total, 2);
    }

    private static function normalizeNote(mixed $note): ?string
    {
        $note = MasterRules::text((string) $note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan maksimal ' . self::NOTE_MAX . ' karakter.');
        }
        return $note === '' ? null : $note;
    }

    /** @return array<string,mixed> permintaan yang sah dipakai sebagai sumber PO */
    private static function ensureUsableReorder(int $reorderId): array
    {
        $reorder = ReorderRequest::find($reorderId);
        if ($reorder === null) {
            throw new RuntimeException('Permintaan reorder tidak ditemukan.');
        }
        if ((string) $reorder['status'] !== ReorderRequest::STATUS_APPROVED) {
            throw new RuntimeException('Hanya permintaan reorder yang DISETUJUI yang dapat dijadikan PO.');
        }

        $stmt = Database::pdo()->prepare('SELECT id FROM purchase_orders WHERE reorder_request_id = ? LIMIT 1');
        $stmt->execute([$reorderId]);
        if ($stmt->fetch() !== false) {
            throw new RuntimeException('Permintaan reorder ini sudah pernah dijadikan PO.');
        }

        return $reorder;
    }

    /** @param array<string,mixed> $po */
    private static function ensureDraft(array $po, int $actorId): void
    {
        if ((string) $po['status'] !== PurchaseOrder::STATUS_DRAFT) {
            throw new RuntimeException('Hanya PO berstatus Draf yang dapat diubah/diajukan.');
        }
        if ((int) $po['created_by'] !== $actorId) {
            throw new RuntimeException('Hanya pembuat PO yang dapat mengubah/mengajukannya.');
        }
    }

    private static function actorName(int $actorId): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT full_name FROM users WHERE id = ?');
        $stmt->execute([$actorId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }
}
