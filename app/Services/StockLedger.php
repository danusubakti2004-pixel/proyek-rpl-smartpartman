<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use LogicException;
use RuntimeException;

/**
 * Inti pengelolaan stok: SATU tempat yang boleh mengubah stok sparepart.
 *
 * Jaminan (PRD §9 aturan 1-4, §10.2, §13.1):
 *  1. Perubahan kuantitas + penulisan ledger terjadi dalam SATU transaksi
 *     database (metode ini menolak jalan di luar transaksi).
 *  2. Baris sparepart dikunci (SELECT ... FOR UPDATE) sebelum dihitung,
 *     sehingga dua pengguna yang menyimpan transaksi bersamaan tidak
 *     saling menimpa (race condition dicegah).
 *  3. Stok tidak pernah menjadi negatif: bila hasil hitung < 0 transaksi
 *     dibatalkan dan stok tetap seperti semula.
 *  4. Ledger bersifat append-only; tidak ada UPDATE/DELETE di kelas ini.
 */
final class StockLedger
{
    /**
     * Terapkan satu pergerakan stok.
     *
     * @param int         $sparepartId    Sparepart yang berubah
     * @param string      $type           MASUK | KELUAR | STOK_AWAL | PENYESUAIAN_OPNAME | KOREKSI
     * @param float       $signedQuantity Positif = menambah stok, negatif = mengurangi
     * @param string|null $businessDate   Tanggal bisnis (YYYY-MM-DD); null = hari ini
     * @param string      $referenceType  GOODS_RECEIPT | GOODS_ISSUE | OPNAME | MANUAL | CORRECTION
     * @param int|null    $referenceId    ID dokumen sumber
     * @param string|null $referenceNumber Nomor dokumen sumber
     * @param string|null $note           Catatan/alasan
     * @param int|null    $actorId        Pengguna yang mencatat
     * @param int|null    $reversalOfId   ID pergerakan yang dibalik (untuk KOREKSI)
     * @return array{movement_id:int, stock_before:float, stock_after:float, sparepart:array<string,mixed>}
     *
     * @throws LogicException  bila dipanggil di luar transaksi database
     * @throws RuntimeException bila sparepart tidak ada atau stok akan negatif
     */
    public static function apply(
        int $sparepartId,
        string $type,
        float $signedQuantity,
        ?string $businessDate,
        string $referenceType,
        ?int $referenceId,
        ?string $referenceNumber,
        ?string $note,
        ?int $actorId,
        ?int $reversalOfId = null
    ): array {
        if (!Database::pdo()->inTransaction()) {
            // Pertahanan mutlak: tidak ada perubahan stok tanpa transaksi.
            throw new LogicException('Perubahan stok wajib dijalankan di dalam transaksi database.');
        }

        if (!in_array($type, StockMovement::TYPES, true)) {
            throw new RuntimeException('Jenis pergerakan stok tidak dikenal: ' . $type);
        }

        $quantity = round($signedQuantity, 2);
        if ($quantity === 0.0) {
            throw new RuntimeException('Jumlah pergerakan stok tidak boleh nol.');
        }

        // Kunci baris sparepart sampai transaksi selesai (cegah race condition).
        $sparepart = Sparepart::lockForUpdate($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }

        $before = round((float) $sparepart['stock'], 2);
        $after = round($before + $quantity, 2);

        if ($after < 0) {
            throw new RuntimeException(sprintf(
                'Stok tidak mencukupi. Stok tersedia %s %s, diminta %s %s.',
                self::formatQty($before),
                $sparepart['unit'],
                self::formatQty(abs($quantity)),
                $sparepart['unit']
            ));
        }

        $movementId = StockMovement::insert([
            'sparepart_id'     => $sparepartId,
            'movement_type'    => $type,
            'quantity'         => $quantity,
            'movement_date'    => $businessDate ?? date('Y-m-d'),
            'stock_before'     => $before,
            'stock_after'      => $after,
            'reference_type'   => $referenceType,
            'reference_id'     => $referenceId,
            'reference_number' => $referenceNumber,
            'reversal_of_id'   => $reversalOfId,
            'note'             => $note,
        ], $actorId);

        // Stok sparepart diperbarui di transaksi yang SAMA dengan ledger.
        Sparepart::updateStock($sparepartId, $after, $actorId);

        // Prediksi stok adalah turunan data persediaan. Tandai bahwa sparepart
        // ini perlu dianalisis ulang HANYA setelah transaksi commit, sehingga
        // prediksi tidak pernah membaca stok yang belum tersimpan/batal.
        Database::afterCommit(static function () use ($sparepartId): void {
            PredictionAutomation::markDirty([$sparepartId], 'perubahan stok');
        });

        return [
            'movement_id' => $movementId,
            'stock_before' => $before,
            'stock_after'  => $after,
            'sparepart'    => $sparepart,
        ];
    }

    /**
     * Kuantitas yang masih boleh dibalik dari sebuah pergerakan (FR-07/§7.4).
     * Pergerakan hanya boleh dikoreksi bila jenisnya MASUK atau KELUAR.
     *
     * @param array<string,mixed> $movement
     */
    public static function remainingReversible(array $movement): float
    {
        $id = (int) ($movement['id'] ?? 0);
        if ($id <= 0) {
            return 0.0;
        }
        $original = abs(round((float) ($movement['quantity'] ?? 0), 2));
        $reversed = round(StockMovement::reversedTotal($id), 2);
        return max(0.0, round($original - $reversed, 2));
    }

    /** Format kuantitas agar pesan kesalahan mudah dibaca (tanpa nol di belakang koma). */
    public static function formatQty(float $value): string
    {
        $text = number_format(round($value, 2), 2, ',', '.');
        $text = rtrim(rtrim($text, '0'), ',');
        return $text === '' ? '0' : $text;
    }
}
