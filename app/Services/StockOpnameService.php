<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StockOpname;
use RuntimeException;

/**
 * Stock opname / perhitungan fisik (FR-10).
 *
 * Alur:
 *   1. Partman memulai sesi → sistem membuat nomor opname unik (SO-YYYYMM-####)
 *      dan menyimpan SNAPSHOT stok sistem seluruh sparepart aktif.
 *   2. Partman mengisi jumlah fisik per item. Selisih dihitung, dan alasan
 *      WAJIB diisi bila ada selisih (PRD §9 aturan 7).
 *   3. Stok BELUM berubah di tahap ini (PRD §9 aturan 6) — hanya tabel
 *      stock_opname_items yang diperbarui.
 *   4. Setelah Partman MENGONFIRMASI: satu pergerakan PENYESUAIAN_OPNAME
 *      per item berselisih dibuat dan stok diperbarui, semuanya dalam satu
 *      transaksi. Baris ledger lama tidak pernah diubah (Aturan 8).
 *
 * Catatan penting (docs/ASSUMPTIONS.md B33): penyesuaian dihitung dari stok
 * sistem TERBARU saat konfirmasi (bukan snapshot), sehingga hasil akhir opname
 * selalu sama dengan jumlah fisik yang dihitung. Bila stok sistem berubah
 * setelah snapshot, perubahan itu diberitahukan kepada pengguna.
 */
final class StockOpnameService
{
    public const NOTE_MAX = 500;
    public const REASON_MAX = 500;
    public const QTY_MAX = 9999999999.99;

    /**
     * Mulai sesi opname baru (snapshot stok sistem seluruh sparepart aktif).
     *
     * @param array<string,mixed> $data opname_date, note
     * @return array{opname_id:int, number:string, item_count:int}
     */
    public static function start(array $data, ?int $actorId): array
    {
        $date = MasterRules::parseDate($data['opname_date'] ?? null);
        if ($date === null) {
            throw new RuntimeException('Tanggal opname tidak valid.');
        }
        if ($date < '2000-01-01' || $date > date('Y-m-d', strtotime('+1 day'))) {
            throw new RuntimeException('Tanggal opname tidak masuk akal (periksa tahunnya).');
        }

        $note = MasterRules::text($data['note'] ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan sesi maksimal ' . self::NOTE_MAX . ' karakter.');
        }

        if (StockOpname::hasOpenSession()) {
            throw new RuntimeException(
                'Masih ada sesi stock opname yang belum dikonfirmasi. Konfirmasi sesi tersebut sebelum memulai yang baru.'
            );
        }

        $spareparts = Sparepart::all(['active' => 'aktif']);
        if ($spareparts === []) {
            throw new RuntimeException('Belum ada sparepart aktif yang dapat dihitung.');
        }

        return Database::transaction(static function () use ($date, $note, $actorId, $spareparts): array {
            $number = DocumentNumberService::next(DocumentNumberService::PREFIX_OPNAME, $date);

            $opnameId = StockOpname::insert($number, $date, $note === '' ? null : $note, (int) $actorId);
            StockOpname::insertItems($opnameId, $spareparts);

            AuditService::log(
                'STOCK_OPNAME_START',
                'stock_opnames',
                (string) $opnameId,
                sprintf('Memulai stock opname %s (%d item, snapshot stok sistem)', $number, count($spareparts)),
                null,
                ['opname_number' => $number, 'opname_date' => $date, 'item_count' => count($spareparts)]
            );

            return ['opname_id' => $opnameId, 'number' => $number, 'item_count' => count($spareparts)];
        });
    }

    /**
     * Simpan hasil hitung fisik (stok sistem TIDAK berubah).
     *
     * @param array<int|string,string|mixed> $inputs  sparepart_id => jumlah fisik (string kosong = belum dihitung)
     * @param array<int|string,string|mixed> $reasons sparepart_id => alasan selisih
     * @return array{counted:int, matched:int, differences:int, changed_since_snapshot:int}
     */
    public static function saveInputs(int $opnameId, array $inputs, array $reasons, ?int $actorId): array
    {
        $opname = self::requireOpen($opnameId);
        $items = StockOpname::items($opnameId);

        $counted = 0;
        $matched = 0;
        $differences = 0;
        $changedSinceSnapshot = 0;

        /** @var array<int,array{physical:float|null, difference:float|null, reason:string|null}> $prepared */
        $prepared = [];

        foreach ($items as $item) {
            $sparepartId = (int) $item['sparepart_id'];
            if (!array_key_exists($sparepartId, $inputs) && !array_key_exists((string) $sparepartId, $inputs)) {
                continue; // item tidak diikutkan pada formulir ini
            }

            $raw = $inputs[$sparepartId] ?? $inputs[(string) $sparepartId] ?? '';
            $physical = MasterRules::parseDecimal(is_string($raw) ? trim($raw) : $raw);

            if ($physical === null) {
                $prepared[$sparepartId] = ['physical' => null, 'difference' => null, 'reason' => null];
                continue;
            }

            if ($physical < 0) {
                throw new RuntimeException(
                    'Jumlah fisik ' . (string) $item['sparepart_code'] . ' tidak boleh negatif.'
                );
            }
            if ($physical > self::QTY_MAX) {
                throw new RuntimeException('Jumlah fisik ' . (string) $item['sparepart_code'] . ' terlalu besar.');
            }

            $system = round((float) $item['system_stock'], 2);
            $difference = round($physical - $system, 2);

            $reasonRaw = $reasons[$sparepartId] ?? $reasons[(string) $sparepartId] ?? '';
            $reason = MasterRules::text(is_string($reasonRaw) ? $reasonRaw : (string) $reasonRaw);
            if (mb_strlen($reason) > self::REASON_MAX) {
                throw new RuntimeException(
                    'Alasan selisih ' . (string) $item['sparepart_code'] . ' maksimal ' . self::REASON_MAX . ' karakter.'
                );
            }

            if ($difference !== 0.0 && $reason === '') {
                throw new RuntimeException(
                    'Alasan wajib diisi untuk selisih pada ' . (string) $item['sparepart_code']
                    . ' (sistem ' . StockLedger::formatQty($system) . ', fisik ' . StockLedger::formatQty($physical) . ').'
                );
            }

            $current = round((float) $item['current_stock'], 2);
            if ($current !== $system) {
                $changedSinceSnapshot++;
            }

            if ($difference === 0.0) {
                $matched++;
            } else {
                $differences++;
            }
            $counted++;

            $prepared[$sparepartId] = [
                'physical'   => round($physical, 2),
                'difference' => $difference,
                'reason'     => $reason === '' ? null : $reason,
            ];
        }

        if ($prepared === []) {
            throw new RuntimeException('Belum ada jumlah fisik yang diisi.');
        }

        Database::transaction(static function () use ($opnameId, $prepared, $actorId, $opname): void {
            foreach ($prepared as $sparepartId => $row) {
                StockOpname::updateItem(
                    $opnameId,
                    (int) $sparepartId,
                    $row['physical'],
                    $row['difference'],
                    $row['reason'],
                    (int) $actorId
                );
            }

            AuditService::log(
                'STOCK_OPNAME_INPUT',
                'stock_opnames',
                (string) $opnameId,
                sprintf('Menyimpan hasil hitung fisik %s (%d item)', (string) $opname['opname_number'], count($prepared)),
                null,
                ['items_saved' => count($prepared)]
            );
        });

        return [
            'counted'                 => $counted,
            'matched'                 => $matched,
            'differences'             => $differences,
            'changed_since_snapshot'  => $changedSinceSnapshot,
        ];
    }

    /**
     * Konfirmasi sesi: buat pergerakan penyesuaian & perbarui stok (FR-10).
     *
     * @return array{adjusted:int, matched:int, skipped:int, movements:int, changed_since_snapshot:int}
     */
    public static function confirm(int $opnameId, ?int $actorId): array
    {
        $opname = self::requireOpen($opnameId);
        $items = StockOpname::items($opnameId);

        $counted = array_values(array_filter($items, static fn (array $i): bool => $i['physical_stock'] !== null));
        if ($counted === []) {
            throw new RuntimeException('Belum ada item yang dihitung. Isi jumlah fisik minimal satu item sebelum konfirmasi.');
        }

        $sessionNote = trim((string) ($opname['note'] ?? ''));

        return Database::transaction(static function () use ($opnameId, $counted, $actorId, $opname, $sessionNote): array {
            $adjusted = 0;
            $matched = 0;
            $movements = 0;
            $changedSinceSnapshot = 0;
            $details = [];

            foreach ($counted as $item) {
                $sparepartId = (int) $item['sparepart_id'];
                $physical = round((float) $item['physical_stock'], 2);
                $snapshot = round((float) $item['system_stock'], 2);

                // Kunci baris & ambil stok sistem TERBARU (bisa berubah sejak snapshot).
                $locked = Sparepart::lockForUpdate($sparepartId);
                if ($locked === null) {
                    throw new RuntimeException('Sparepart tidak ditemukan saat konfirmasi opname.');
                }
                $current = round((float) $locked['stock'], 2);
                $delta = round($physical - $current, 2);

                if ($current !== $snapshot) {
                    $changedSinceSnapshot++;
                }

                // Selisih yang BENAR-BENAR diterapkan harus punya alasan (Aturan 7).
                $reason = trim((string) ($item['reason'] ?? ''));
                if ($reason === '') {
                    $reason = $sessionNote;
                }
                if ($delta !== 0.0 && $reason === '') {
                    throw new RuntimeException(
                        'Alasan wajib diisi untuk penyesuaian ' . (string) $item['sparepart_code']
                        . ' (stok sistem berubah menjadi ' . StockLedger::formatQty($current)
                        . ', fisik ' . StockLedger::formatQty($physical) . '). Simpan ulang hasil hitung beserta alasan.'
                    );
                }

                $note = $reason;
                if ($current !== $snapshot) {
                    $note = trim(
                        ($note !== '' ? $note . ' — ' : '')
                        . 'stok sistem saat snapshot ' . StockLedger::formatQty($snapshot)
                        . ', saat konfirmasi ' . StockLedger::formatQty($current)
                    );
                }

                if ($delta === 0.0) {
                    $matched++;
                    // Catat selisih aktual (0) agar laporan konsisten.
                    StockOpname::updateItem($opnameId, $sparepartId, $physical, 0.0, $item['reason'], (int) $actorId);
                    continue;
                }

                $movement = StockLedger::apply(
                    $sparepartId,
                    StockMovement::TYPE_OPNAME,
                    $delta,
                    (string) ($opname['opname_date'] ?? date('Y-m-d')),
                    'OPNAME',
                    $opnameId,
                    (string) $opname['opname_number'],
                    $note === '' ? null : $note,
                    $actorId
                );

                StockOpname::updateItem($opnameId, $sparepartId, $physical, $delta, $item['reason'], (int) $actorId);

                $adjusted++;
                $movements++;
                $details[] = [
                    'sparepart_id' => $sparepartId,
                    'code'         => (string) $item['sparepart_code'],
                    'stock_before' => $movement['stock_before'],
                    'stock_after'  => $movement['stock_after'],
                    'quantity'     => $delta,
                ];
            }

            StockOpname::markConfirmed($opnameId, (int) $actorId);

            AuditService::log(
                'STOCK_OPNAME_CONFIRM',
                'stock_opnames',
                (string) $opnameId,
                sprintf(
                    'Konfirmasi stock opname %s: %d item disesuaikan, %d item sudah sesuai',
                    (string) $opname['opname_number'],
                    $adjusted,
                    $matched
                ),
                ['status' => StockOpname::STATUS_OPEN],
                [
                    'status'                 => StockOpname::STATUS_CONFIRMED,
                    'opname_number'          => (string) $opname['opname_number'],
                    'adjusted'               => $adjusted,
                    'matched'                => $matched,
                    'changed_since_snapshot' => $changedSinceSnapshot,
                    'adjustments'            => $details,
                ]
            );

            return [
                'adjusted'                => $adjusted,
                'matched'                 => $matched,
                'skipped'                 => count(StockOpname::items($opnameId)) - count($counted),
                'movements'               => $movements,
                'changed_since_snapshot'  => $changedSinceSnapshot,
            ];
        });
    }

    /** @return array<string,mixed> */
    private static function requireOpen(int $opnameId): array
    {
        $opname = StockOpname::find($opnameId);
        if ($opname === null) {
            throw new RuntimeException('Sesi stock opname tidak ditemukan.');
        }
        if ((string) $opname['status'] !== StockOpname::STATUS_OPEN) {
            throw new RuntimeException(
                'Sesi ' . (string) $opname['opname_number'] . ' sudah dikonfirmasi dan tidak dapat diubah lagi.'
            );
        }
        return $opname;
    }
}
