<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\AuditService;
use App\Services\DocumentNumberService;
use App\Services\MasterRules;
use RuntimeException;

/**
 * Penyesuaian stok sparepart (tambah/kurang) oleh Partman.
 *
 * Perubahan stok tetap melalui StockLedger, dengan pergerakan bertipe
 * PENYESUAIAN (append-only). Tidak ada UPDATE langsung ke stok tanpa ledger.
 */
final class StockAdjustmentService
{
    public const REASON_MIN = 3;
    public const REASON_MAX = 500;
    public const QTY_MAX = 9999999999.99;
    public const NOTE_MAX = 500;

    /**
     * @param array<string,mixed> $data sparepart_id, adjustment_type (penambahan/pengurangan),
     *                                 quantity, adjustment_date, reason, note?
     * @return array{movement_id:int, adjustment_number:string, sparepart_id:int,
     *               adjustment_type:string, quantity:float, stock_before:float, stock_after:float}
     */
    public static function adjust(array $data, ?int $actorId): array
    {
        $sparepartId = (int) ($data['sparepart_id'] ?? 0);
        if ($sparepartId <= 0) {
            throw new RuntimeException('Sparepart wajib dipilih.');
        }
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        if ((int) $sparepart['is_active'] !== 1) {
            throw new RuntimeException('Sparepart nonaktif tidak dapat disesuaikan.');
        }

        $type = self::normalizeType($data['adjustment_type'] ?? $data['type'] ?? $data['jenis'] ?? $data['direction'] ?? null);
        if ($type === null) {
            throw new RuntimeException('Jenis penyesuaian tidak dikenal.');
        }

        $quantity = MasterRules::parseDecimal($data['quantity'] ?? $data['jumlah'] ?? null);
        if ($quantity === null || $quantity <= 0) {
            throw new RuntimeException('Jumlah penyesuaian harus lebih dari 0.');
        }
        if ($quantity > self::QTY_MAX) {
            throw new RuntimeException('Jumlah penyesuaian terlalu besar.');
        }
        // Jumlah penyesuaian wajib bilangan bulat.
        if (abs($quantity - round($quantity)) > 0.0000001) {
            throw new RuntimeException('Jumlah penyesuaian harus berupa bilangan bulat.');
        }
        $quantity = round($quantity, 2);

        $reason = MasterRules::text($data['reason'] ?? $data['alasan'] ?? '');
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw new RuntimeException('Alasan penyesuaian wajib diisi (minimal ' . self::REASON_MIN . ' karakter).');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RuntimeException('Alasan penyesuaian maksimal ' . self::REASON_MAX . ' karakter.');
        }

        $note = MasterRules::text($data['note'] ?? $data['catatan'] ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan maksimal ' . self::NOTE_MAX . ' karakter.');
        }

        $date = MasterRules::parseDate($data['adjustment_date'] ?? $data['date'] ?? $data['tanggal'] ?? null);
        if ($date === null) {
            throw new RuntimeException('Tanggal penyesuaian tidak valid.');
        }
        if ($date < '2000-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
            throw new RuntimeException('Tanggal penyesuaian tidak masuk akal.');
        }

        $fullNote = $reason;
        if ($note !== '') {
            $fullNote .= ' — ' . $note;
        }

        $signed = $type === 'PENAMBAHAN' ? $quantity : -$quantity;

        return Database::transaction(static function () use (
            $sparepartId, $sparepart, $type, $quantity, $signed, $date, $fullNote, $actorId
        ): array {
            $number = DocumentNumberService::next('ADJ', $date);

            $result = StockLedger::apply(
                $sparepartId,
                StockMovement::TYPE_ADJUSTMENT,
                $signed,
                $date,
                'STOCK_ADJUSTMENT',
                null,
                $number,
                $fullNote,
                $actorId
            );

            AuditService::log(
                'STOCK_ADJUSTMENT',
                'stock_movements',
                (string) $result['movement_id'],
                sprintf(
                    'Penyesuaian stok %s: %s %s x %s (stok %s → %s)',
                    $type === 'PENAMBAHAN' ? 'penambahan' : 'pengurangan',
                    $quantity,
                    (string) $sparepart['unit'],
                    (string) $sparepart['code'],
                    StockLedger::formatQty($result['stock_before']),
                    StockLedger::formatQty($result['stock_after'])
                ),
                [
                    'stock' => $result['stock_before'],
                    'adjustment_type' => $type,
                ],
                [
                    'stock' => $result['stock_after'],
                    'adjustment_number' => $number,
                    'adjustment_type' => $type,
                    'quantity' => $quantity,
                    'reason' => $fullNote,
                    'sparepart_id' => $sparepartId,
                ]
            );

            return [
                'movement_id' => $result['movement_id'],
                'adjustment_number' => $number,
                'sparepart_id' => $sparepartId,
                'adjustment_type' => $type,
                'quantity' => $quantity,
                'stock_before' => $result['stock_before'],
                'stock_after' => $result['stock_after'],
            ];
        });
    }

    private static function normalizeType(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if (in_array($value, ['penambahan', 'tambah', 'tambah_stok', 'in', 'masuk', '+', 'add', 'increase'], true)) {
            return 'PENAMBAHAN';
        }
        if (in_array($value, ['pengurangan', 'kurang', 'kurang_stok', 'out', 'keluar', '-', 'reduce', 'decrease'], true)) {
            return 'PENGURANGAN';
        }
        return null;
    }
}