<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use RuntimeException;

/**
 * Stok awal (FR-05).
 *
 * Aturan:
 * - Dicatat sebagai pergerakan stok bertipe STOK_AWAL (BUKAN pembelian).
 * - Menyimpan tanggal pencatatan, pengguna, item, jumlah, dan catatan.
 * - Hanya boleh SEKALI per sparepart; koreksi berikutnya wajib lewat
 *   penyesuaian opname (FR-10). Dijaga trigger database (migrasi 008)
 *   + pemeriksaan di sini agar pesannya ramah.
 * - Stok sparepart & baris ledger ditulis dalam SATU transaksi.
 */
final class StockInitialService
{
    /**
     * Catat stok awal untuk sebuah sparepart.
     *
     * @param string|null $date Tanggal pencatatan (tanggal bisnis); kosong = hari ini.
     * @param string|null $note Catatan (opsional).
     * @param string $referenceType MANUAL (input) atau IMPORT (dari file).
     * @return int ID pergerakan stok yang dibuat
     */
    public static function apply(
        int $sparepartId,
        float $quantity,
        ?string $date = null,
        ?string $note = null,
        ?int $actorId = null,
        string $referenceType = 'MANUAL'
    ): int {
        if ($quantity <= 0) {
            throw new RuntimeException('Jumlah stok awal harus lebih dari 0.');
        }

        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        $existing = StockMovement::initialFor($sparepartId);
        if ($existing !== null) {
            throw new RuntimeException(sprintf(
                'Stok awal sparepart "%s" sudah pernah dicatat pada %s. Gunakan penyesuaian opname untuk koreksi.',
                (string) $sparepart['code'],
                tanggal_id((string) ($existing['movement_date'] ?? $existing['created_at']))
            ));
        }

        $movementDate = MasterRules::parseDate($date) ?? date('Y-m-d');
        $note = MasterRules::text($note) ?: null;

        return Database::transaction(static function () use (
            $sparepartId,
            $quantity,
            $movementDate,
            $note,
            $actorId,
            $referenceType,
            $sparepart
        ): int {
            // Stok & ledger lewat StockLedger: baris dikunci, satu transaksi.
            $movement = StockLedger::apply(
                $sparepartId,
                StockMovement::TYPE_INITIAL,
                $quantity,
                $movementDate,
                $referenceType,
                null,
                null,
                $note,
                $actorId
            );

            AuditService::log(
                'STOCK_INITIAL_CREATE',
                'spareparts',
                (string) $sparepartId,
                sprintf(
                    'Mencatat stok awal "%s" sejumlah %s %s pada %s',
                    (string) $sparepart['code'],
                    self::quantityLabel($quantity),
                    (string) $sparepart['unit'],
                    tanggal_id($movementDate)
                ),
                null,
                [
                    'sparepart'     => (string) $sparepart['code'],
                    'quantity'      => $quantity,
                    'stock_before'  => $movement['stock_before'],
                    'stock_after'   => $movement['stock_after'],
                    'movement_date' => $movementDate,
                    'note'          => $note,
                    'sumber'        => $referenceType,
                ]
            );

            return $movement['movement_id'];
        });
    }

    public static function hasInitial(int $sparepartId): bool
    {
        return StockMovement::hasInitial($sparepartId);
    }

    /** @return array<string,mixed>|null */
    public static function initialFor(int $sparepartId): ?array
    {
        return StockMovement::initialFor($sparepartId);
    }

    /** Tampilkan kuantitas tanpa ekor desimal yang tidak perlu (12.00 → 12). */
    private static function quantityLabel(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
    }
}
