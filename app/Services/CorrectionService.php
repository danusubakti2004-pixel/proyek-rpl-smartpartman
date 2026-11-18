<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use RuntimeException;

/**
 * Koreksi transaksi (PRD §7.4, §9 aturan 8).
 *
 * Prinsip:
 *  - Transaksi yang sudah tersimpan TIDAK PERNAH dihapus atau diubah.
 *  - Koreksi dilakukan dengan PERGERAKAN PEMBALIK baru bertipe KOREKSI yang
 *    menunjuk baris ledger asal (reversal_of_id).
 *  - Wajib: alasan, pengguna pelaksana, waktu, dan referensi transaksi asal.
 *  - Audit Log mencatat nilai sebelum/sesudah.
 *  - Koreksi boleh sebagian: total yang dibalik tidak boleh melebihi jumlah
 *    transaksi asal. Pemeriksaan dilakukan pada baris sparepart yang dikunci
 *    di dalam transaksi sehingga dua koreksi bersamaan tidak saling menimpa.
 */
final class CorrectionService
{
    public const REASON_MIN = 3;
    public const REASON_MAX = 500;
    public const QTY_MAX = 9999999999.99;

    /** Pergerakan yang boleh dikoreksi: MASUK & KELUAR (lihat docs/ASSUMPTIONS.md B35). */
    public static function isCorrectable(array $movement): bool
    {
        return in_array((string) ($movement['movement_type'] ?? ''), StockMovement::CORRECTABLE_TYPES, true);
    }

    /**
     * Koreksi sebuah pergerakan stok.
     *
     * @param array<string,mixed> $data quantity (jumlah dibalik), reason (wajib)
     * @return array{movement_id:int, correction_number:string, quantity:float,
     *               stock_before:float, stock_after:float, original_id:int,
     *               original_type:string, original_reference:string|null}
     */
    public static function correct(int $movementId, array $data, ?int $actorId): array
    {
        $movement = StockMovement::find($movementId);
        if ($movement === null) {
            throw new RuntimeException('Transaksi asal tidak ditemukan.');
        }
        if (!self::isCorrectable($movement)) {
            throw new RuntimeException(
                'Pergerakan bertipe ' . (string) $movement['movement_type']
                . ' tidak dapat dikoreksi lewat transaksi pembalik. Gunakan stock opname untuk menyesuaikan stok.'
            );
        }

        $reason = MasterRules::text($data['reason'] ?? '');
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw new RuntimeException('Alasan koreksi wajib diisi (minimal ' . self::REASON_MIN . ' karakter).');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan koreksi maksimal ' . self::REASON_MAX . ' karakter.');
        }

        $quantity = MasterRules::parseDecimal($data['quantity'] ?? null);
        if ($quantity === null || $quantity <= 0) {
            throw new RuntimeException('Jumlah yang dikoreksi harus lebih dari 0.');
        }
        if ($quantity > self::QTY_MAX) {
            throw new RuntimeException('Jumlah koreksi terlalu besar.');
        }
        $quantity = round($quantity, 2);

        $sparepartId = (int) $movement['sparepart_id'];

        return Database::transaction(static function () use ($movementId, $sparepartId, $quantity, $reason, $actorId): array {
            // Kunci baris sparepart: men-serialisasi koreksi pada item yang sama.
            $locked = Sparepart::lockForUpdate($sparepartId);
            if ($locked === null) {
                throw new RuntimeException('Sparepart tidak ditemukan.');
            }

            // Baca ulang pergerakan asal DI DALAM transaksi (setelah terkunci).
            $fresh = StockMovement::find($movementId);
            if ($fresh === null) {
                throw new RuntimeException('Transaksi asal tidak ditemukan.');
            }

            $remaining = StockLedger::remainingReversible($fresh);
            if ($remaining <= 0) {
                throw new RuntimeException('Transaksi ini sudah dibalik seluruhnya dan tidak dapat dikoreksi lagi.');
            }
            if ($quantity > $remaining) {
                throw new RuntimeException(sprintf(
                    'Jumlah koreksi (%s) melebihi sisa yang dapat dibalik (%s).',
                    StockLedger::formatQty($quantity),
                    StockLedger::formatQty($remaining)
                ));
            }

            $originalType = (string) $fresh['movement_type'];
            // MASUK dibalik dengan mengurangi stok; KELUAR dibalik dengan menambah stok.
            $signed = $originalType === StockMovement::TYPE_IN ? -$quantity : $quantity;

            $result = StockLedger::apply(
                $sparepartId,
                StockMovement::TYPE_CORRECTION,
                $signed,
                date('Y-m-d'),
                'CORRECTION',
                $movementId,
                (string) ($fresh['reference_number'] ?? ''),
                $reason,
                $actorId,
                $movementId
            );

            AuditService::log(
                'TRANSACTION_CORRECTION',
                'stock_movements',
                (string) $result['movement_id'],
                sprintf(
                    'Koreksi transaksi #%d (%s %s %s, dokumen %s): %s %s — stok %s → %s. Alasan: %s',
                    $movementId,
                    $originalType,
                    StockLedger::formatQty(abs((float) $fresh['quantity'])),
                    (string) $locked['unit'],
                    (string) ($fresh['reference_number'] ?? '-'),
                    $signed > 0 ? 'menambah' : 'mengurangi',
                    StockLedger::formatQty($quantity),
                    StockLedger::formatQty($result['stock_before']),
                    StockLedger::formatQty($result['stock_after']),
                    $reason
                ),
                [
                    'movement_id'      => $movementId,
                    'movement_type'    => $originalType,
                    'quantity'         => (float) $fresh['quantity'],
                    'stock_before'     => (float) $fresh['stock_before'],
                    'stock_after'      => (float) $fresh['stock_after'],
                    'reference_number' => $fresh['reference_number'],
                    'stock_current'    => $result['stock_before'],
                ],
                [
                    'correction_movement_id' => $result['movement_id'],
                    'movement_type'          => StockMovement::TYPE_CORRECTION,
                    'quantity'               => $signed,
                    'reason'                 => $reason,
                    'reversal_of_id'         => $movementId,
                    'stock_after'            => $result['stock_after'],
                ]
            );

            return [
                'movement_id'         => $result['movement_id'],
                'correction_number'   => (string) ($fresh['reference_number'] ?? ''),
                'quantity'            => $quantity,
                'stock_before'        => $result['stock_before'],
                'stock_after'         => $result['stock_after'],
                'sparepart_id'        => $sparepartId,
                'original_id'         => $movementId,
                'original_type'       => $originalType,
                'original_reference'  => $fresh['reference_number'] !== null ? (string) $fresh['reference_number'] : null,
            ];
        });
    }
}
