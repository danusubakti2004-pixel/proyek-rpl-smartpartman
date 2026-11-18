<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\GoodsIssue;
use App\Models\Sparepart;
use App\Models\StockMovement;
use RuntimeException;

/**
 * Barang keluar / pengeluaran sparepart (FR-07).
 *
 * Aturan penting (PRD §9 aturan 1 & 4, §7.1):
 *  - Nomor dokumen otomatis unik (GI-YYYYMM-####).
 *  - Jumlah keluar TIDAK boleh melebihi stok tersedia. Pemeriksaan dilakukan
 *    pada baris sparepart yang DIKUNCI (SELECT ... FOR UPDATE) di dalam
 *    transaksi, sehingga dua pengguna yang mengeluarkan item sama secara
 *    bersamaan tidak dapat membuat stok negatif.
 *  - Stok berkurang + ledger ditulis dalam satu transaksi; bila gagal,
 *    dokumen tidak tersimpan dan stok tidak berubah.
 */
final class GoodsIssueService
{
    public const SPK_MAX = 50;
    public const PLATE_MAX = 20;
    public const NOTE_MAX = 500;
    public const QTY_MAX = 9999999999.99;

    /**
     * @param array<string,mixed> $data
     * @return int ID pengeluaran
     */
    public static function create(array $data, ?int $actorId): int
    {
        $clean = self::normalize($data);

        $sparepart = Sparepart::find($clean['sparepart_id']);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        if ((int) $sparepart['is_active'] !== 1) {
            throw new RuntimeException(
                'Sparepart "' . (string) $sparepart['name'] . '" nonaktif dan tidak dapat dikeluarkan.'
            );
        }

        return Database::transaction(static function () use ($clean, $actorId, $sparepart): int {
            $number = DocumentNumberService::next(DocumentNumberService::PREFIX_ISSUE, $clean['issue_date']);

            $issueId = GoodsIssue::insert([
                'issue_number' => $number,
                'issue_date'   => $clean['issue_date'],
                'usage_type'   => $clean['usage_type'],
                'spk_number'   => $clean['spk_number'],
                'plate_number' => $clean['plate_number'],
                'note'         => $clean['note'],
            ], (int) $actorId);

            GoodsIssue::insertItem($issueId, $clean['sparepart_id'], $clean['quantity']);

            // Kunci baris + tolak bila stok tidak cukup (stok negatif tidak mungkin).
            $movement = StockLedger::apply(
                $clean['sparepart_id'],
                StockMovement::TYPE_OUT,
                -$clean['quantity'],
                $clean['issue_date'],
                'GOODS_ISSUE',
                $issueId,
                $number,
                $clean['note'],
                $actorId
            );

            AuditService::log(
                'GOODS_ISSUE_CREATE',
                'goods_issues',
                (string) $issueId,
                sprintf(
                    'Pengeluaran %s: %s %s %s (%s) — stok %s → %s',
                    $number,
                    $clean['quantity'],
                    (string) $sparepart['unit'],
                    $clean['sparepart_code'],
                    $clean['usage_type'],
                    StockLedger::formatQty($movement['stock_before']),
                    StockLedger::formatQty($movement['stock_after'])
                ),
                ['stock' => $movement['stock_before']],
                [
                    'stock'         => $movement['stock_after'],
                    'issue_number'  => $number,
                    'usage_type'    => $clean['usage_type'],
                    'quantity'      => $clean['quantity'],
                    'sparepart_id'  => $clean['sparepart_id'],
                    'spk_number'    => $clean['spk_number'],
                    'plate_number'  => $clean['plate_number'],
                ]
            );

            return $issueId;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array{issue_date:string, usage_type:string, sparepart_id:int, sparepart_code:string,
     *               quantity:float, spk_number:string|null, plate_number:string|null, note:string|null}
     */
    public static function normalize(array $data): array
    {
        $date = MasterRules::parseDate($data['issue_date'] ?? null);
        if ($date === null) {
            throw new RuntimeException('Tanggal pengeluaran tidak valid.');
        }
        if ($date < '2000-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
            throw new RuntimeException('Tanggal pengeluaran tidak masuk akal (periksa tahunnya).');
        }

        $usage = trim((string) ($data['usage_type'] ?? ''));
        if (!in_array($usage, GoodsIssue::USAGE_TYPES, true)) {
            throw new RuntimeException('Jenis penggunaan wajib dipilih (service, penjualan, atau lainnya).');
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
            throw new RuntimeException('Jumlah keluar harus lebih dari 0.');
        }
        if ($quantity > self::QTY_MAX) {
            throw new RuntimeException('Jumlah keluar terlalu besar.');
        }

        $spk = MasterRules::text($data['spk_number'] ?? '');
        if (mb_strlen($spk) > self::SPK_MAX) {
            throw new RuntimeException('Nomor SPK maksimal ' . self::SPK_MAX . ' karakter.');
        }

        $plate = mb_strtoupper(MasterRules::text($data['plate_number'] ?? ''), 'UTF-8');
        if (mb_strlen($plate) > self::PLATE_MAX) {
            throw new RuntimeException('Nomor polisi maksimal ' . self::PLATE_MAX . ' karakter.');
        }

        $note = MasterRules::text($data['note'] ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan maksimal ' . self::NOTE_MAX . ' karakter.');
        }

        return [
            'issue_date'     => $date,
            'usage_type'     => $usage,
            'sparepart_id'   => $sparepartId,
            'sparepart_code' => (string) $sparepart['code'],
            'quantity'       => round($quantity, 2),
            'spk_number'     => $spk === '' ? null : $spk,
            'plate_number'   => $plate === '' ? null : $plate,
            'note'           => $note === '' ? null : $note,
        ];
    }
}
