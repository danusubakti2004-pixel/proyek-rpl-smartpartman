<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ReorderRequest;
use App\Models\Sparepart;
use App\Models\StatusHistory;
use RuntimeException;

/**
 * Permintaan reorder (FR-15, Fase 6).
 *
 * Alur:
 *   1. Partman melihat kebutuhan (stok di bawah minimum) lalu membuat
 *      permintaan. Sistem memberi saran jumlah: prediksi AI bila sudah ada
 *      (Fase 7), selain itu aturan sederhana stok-minimum (catat di
 *      docs/ASSUMPTIONS.md).
 *   2. Partman boleh mengubah jumlah saran dengan ALASAN WAJIB.
 *   3. Kepala Bengkel menyetujui/menolak; penolakan wajib beralasan.
 *   4. Setiap perubahan status dicatat: aktor, waktu, status sebelumnya →
 *      berikutnya, dan alasan (purchase_status_history, append-only).
 *
 * ATURAN KUNCI: persetujuan reorder TIDAK menambah stok. Stok hanya berubah
 * lewat penerimaan barang (GoodsReceiptService) setelah PO dibuat & diterima.
 *
 * Pada tahap ini permintaan langsung berstatus MENUNGGU_PERSETUJUAN saat
 * dibuat (status DRAFT di skema tidak dipakai — lihat ASSUMPTIONS).
 */
final class ReorderRequestService
{
    public const QTY_MAX = 9999999999.99;
    public const REASON_MAX = 500;

    /**
     * Buat permintaan reorder (Partman).
     *
     * @param array<string,mixed> $data sparepart_id, requested_qty, supplier_id?, change_reason?
     * @return int ID permintaan
     *
     * @throws RuntimeException bila validasi/aturan bisnis dilanggar
     */
    public static function create(array $data, int $actorId): int
    {
        $sparepartId = (int) ($data['sparepart_id'] ?? 0);
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        if ((int) $sparepart['is_active'] !== 1) {
            throw new RuntimeException('Sparepart nonaktif tidak dapat diminta reorder.');
        }

        $requested = self::parseQty($data['requested_qty'] ?? null, 'Jumlah permintaan');
        $suggestion = self::suggestion($sparepartId);
        $suggested = $suggestion['suggested_qty'];

        $reason = MasterRules::text((string) ($data['change_reason'] ?? ''));
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan perubahan maksimal ' . self::REASON_MAX . ' karakter.');
        }
        // Mengubah jumlah dari saran sistem wajib beralasan (FR-15).
        // Bila tidak ada saran (stok belum di bawah minimum / tanpa prediksi),
        // permintaan dianggap murni manual dan alasan tidak diwajibkan.
        if ($suggested !== null && abs($requested - $suggested) > 0.005 && $reason === '') {
            throw new RuntimeException(
                'Jumlah permintaan berbeda dari saran sistem (' .
                StockLedger::formatQty($suggested) . ') — alasan perubahan wajib diisi.'
            );
        }

        $supplierId = (int) ($data['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            SupplierService::ensureUsableForTransaction($supplierId);
        } else {
            $supplierId = 0;
        }

        return Database::transaction(static function () use (
            $sparepartId, $sparepart, $requested, $suggested, $suggestion, $reason, $supplierId, $actorId
        ): int {
            $number = DocumentNumberService::next(DocumentNumberService::PREFIX_REORDER);

            $reorderId = ReorderRequest::insert([
                'request_number' => $number,
                'sparepart_id'   => $sparepartId,
                'suggested_qty'  => $suggested,
                'requested_qty'  => $requested,
                'change_reason'  => $reason === '' ? null : $reason,
                'supplier_id'    => $supplierId > 0 ? $supplierId : null,
                'prediction_id'  => $suggestion['prediction_id'],
            ], $actorId);

            StatusHistory::record(
                StatusHistory::TYPE_REORDER,
                $reorderId,
                null,
                ReorderRequest::STATUS_PENDING,
                $actorId,
                self::actorName($actorId)
            );

            AuditService::log(
                'REORDER_CREATE',
                'reorder_requests',
                (string) $reorderId,
                sprintf(
                    'Permintaan reorder %s: %s %s (saran: %s)',
                    $number,
                    StockLedger::formatQty($requested),
                    (string) $sparepart['unit'],
                    $suggested !== null ? StockLedger::formatQty($suggested) : 'manual'
                ),
                null,
                [
                    'request_number' => $number,
                    'sparepart_id'   => $sparepartId,
                    'suggested_qty'  => $suggested,
                    'requested_qty'  => $requested,
                    'change_reason'  => $reason === '' ? null : $reason,
                    'status'         => ReorderRequest::STATUS_PENDING,
                ]
            );

            return $reorderId;
        });
    }

    /**
     * Ubah jumlah yang diajukan (Partman, selama masih menunggu persetujuan).
     *
     * @param array<string,mixed> $data requested_qty, change_reason?
     *
     * @throws RuntimeException
     */
    public static function updateQty(int $reorderId, array $data, int $actorId): void
    {
        $requested = self::parseQty($data['requested_qty'] ?? null, 'Jumlah permintaan');

        $reason = MasterRules::text((string) ($data['change_reason'] ?? ''));
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan perubahan maksimal ' . self::REASON_MAX . ' karakter.');
        }

        Database::transaction(static function () use ($reorderId, $requested, $reason, $actorId): void {
            $row = ReorderRequest::lockForUpdate($reorderId);
            if ($row === null) {
                throw new RuntimeException('Permintaan reorder tidak ditemukan.');
            }
            if ((string) $row['status'] !== ReorderRequest::STATUS_PENDING) {
                throw new RuntimeException('Permintaan yang sudah diputus tidak dapat diubah jumlahnya.');
            }
            if ((int) $row['created_by'] !== $actorId) {
                throw new RuntimeException('Hanya pengaju permintaan yang dapat mengubahnya.');
            }

            $old = (float) $row['requested_qty'];
            $suggested = $row['suggested_qty'] !== null ? (float) $row['suggested_qty'] : null;

            // Mengubah jumlah dari saran sistem (atau mengubah kembali jumlah
            // yang sudah pernah diubah) wajib beralasan.
            $reference = $suggested ?? $old;
            if (abs($requested - $reference) > 0.005 && $reason === '') {
                throw new RuntimeException(
                    'Jumlah permintaan berbeda dari saran/ajuan sebelumnya (' .
                    StockLedger::formatQty($reference) . ') — alasan perubahan wajib diisi.'
                );
            }

            ReorderRequest::updateRequested($reorderId, $requested, $reason === '' ? null : $reason);

            AuditService::log(
                'REORDER_UPDATE_QTY',
                'reorder_requests',
                (string) $reorderId,
                sprintf(
                    'Jumlah reorder %s diubah: %s → %s%s',
                    (string) $row['request_number'],
                    StockLedger::formatQty($old),
                    StockLedger::formatQty($requested),
                    $reason !== '' ? ' — alasan: ' . $reason : ''
                ),
                ['requested_qty' => $old],
                ['requested_qty' => $requested, 'change_reason' => $reason === '' ? null : $reason]
            );
        });
    }

    /**
     * Setujui/tolak permintaan (Kepala Bengkel).
     *
     * Penolakan wajib beralasan. TIDAK menambah stok apa pun.
     *
     * @throws RuntimeException
     */
    public static function review(int $reorderId, bool $approve, string $note, int $reviewerId): void
    {
        $note = MasterRules::text($note);
        if (mb_strlen($note) > self::REASON_MAX) {
            throw new RuntimeException('Catatan peninjauan maksimal ' . self::REASON_MAX . ' karakter.');
        }
        if (!$approve && $note === '') {
            throw new RuntimeException('Penolakan permintaan reorder wajib menyertakan alasan.');
        }

        Database::transaction(static function () use ($reorderId, $approve, $note, $reviewerId): void {
            $row = ReorderRequest::lockForUpdate($reorderId);
            if ($row === null) {
                throw new RuntimeException('Permintaan reorder tidak ditemukan.');
            }

            $oldStatus = (string) $row['status'];
            if ($oldStatus !== ReorderRequest::STATUS_PENDING) {
                throw new RuntimeException(
                    'Hanya permintaan berstatus "Menunggu persetujuan" yang dapat ditinjau.'
                );
            }

            $newStatus = $approve ? ReorderRequest::STATUS_APPROVED : ReorderRequest::STATUS_REJECTED;
            ReorderRequest::markReviewed($reorderId, $newStatus, $reviewerId, $note === '' ? null : $note);

            StatusHistory::record(
                StatusHistory::TYPE_REORDER,
                $reorderId,
                $oldStatus,
                $newStatus,
                $reviewerId,
                self::actorName($reviewerId),
                $note === '' ? null : $note
            );

            AuditService::log(
                $approve ? 'REORDER_APPROVE' : 'REORDER_REJECT',
                'reorder_requests',
                (string) $reorderId,
                sprintf(
                    'Permintaan reorder %s %s%s',
                    (string) $row['request_number'],
                    $approve ? 'disetujui' : 'ditolak',
                    $note !== '' ? ' — alasan: ' . $note : ''
                ),
                ['status' => $oldStatus],
                ['status' => $newStatus, 'review_note' => $note === '' ? null : $note]
            );
        });
    }

    /**
     * Saran jumlah reorder untuk satu sparepart.
     *
     * Urutan: (1) prediksi AI terbaru bila ada (Fase 7 mengisinya; hanya
     * prediksi segar + data memadai yang dipakai); (2) aturan stok-minimum —
     * sisa kekurangan sampai mencapai stok minimum. Bila stok sudah >=
     * minimum, tidak ada saran (Partman tetap boleh mengajukan manual dengan
     * alasan tersendiri di formulir).
     *
     * Sumber 'ai' selalu menyertakan prediction_id agar permintaan reorder
     * tetap dapat ditelusuri ke analisis asalnya.
     *
     * @return array{suggested_qty:float|null, source:string, prediction_id:?int}
     */
    public static function suggestion(int $sparepartId): array
    {
        $pdo = Database::pdo();

        // (1) Prediksi AI terbaru yang masih segar dan datanya memadai.
        // Prediksi lebih tua dari PREDICTION_MAX_AGE_DAYS diabaikan agar saran
        // tidak memakai stok lama; angka NULL (data tidak cukup) juga diabaikan.
        $stmt = $pdo->prepare(
            'SELECT id, recommended_qty FROM ai_predictions
              WHERE sparepart_id = ?
                AND recommended_qty IS NOT NULL
                AND recommended_qty > 0
                AND data_sufficiency IN (\'cukup\', \'terbatas\')
                AND processed_at >= DATE_SUB(NOW(), INTERVAL ' . PredictionService::PREDICTION_MAX_AGE_DAYS . ' DAY)
           ORDER BY processed_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$sparepartId]);
        $prediction = $stmt->fetch();
        if ($prediction !== false && (float) $prediction['recommended_qty'] > 0) {
            return [
                'suggested_qty'  => round((float) $prediction['recommended_qty'], 2),
                'source'         => 'ai',
                'prediction_id'  => (int) $prediction['id'],
            ];
        }

        // (2) Aturan stok-minimum.
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            return ['suggested_qty' => null, 'source' => 'tidak_ada', 'prediction_id' => null];
        }

        $min = (float) $sparepart['min_stock'];
        $stock = (float) $sparepart['stock'];
        if ($min <= 0 || $stock >= $min) {
            return ['suggested_qty' => null, 'source' => 'tidak_ada', 'prediction_id' => null];
        }

        return [
            'suggested_qty' => (float) (int) ceil($min - $stock),
            'source'        => 'aturan_minimum',
            'prediction_id' => null,
        ];
    }

    private static function parseQty(mixed $value, string $label): float
    {
        $qty = MasterRules::parseDecimal($value);
        if ($qty === null || $qty <= 0) {
            throw new RuntimeException($label . ' harus lebih dari 0.');
        }
        if ($qty > self::QTY_MAX) {
            throw new RuntimeException($label . ' terlalu besar.');
        }
        return round($qty, 2);
    }

    private static function actorName(int $actorId): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT full_name FROM users WHERE id = ?');
        $stmt->execute([$actorId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }
}
