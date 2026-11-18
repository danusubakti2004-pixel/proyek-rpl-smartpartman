<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\DocumentNumberService;

/**
 * Pengujian Fase 4 — penomoran dokumen otomatis (FR-06, FR-07, FR-10).
 *
 * Yang diuji:
 *  - nomor dokumen dibuat otomatis dengan format <PREFIX>-<YYYYMM>-####;
 *  - prefix & periode punya counter terpisah;
 *  - penomoran WAJIB di dalam transaksi (mencegah nomor ganda saat dua
 *    pengguna menyimpan bersamaan);
 *  - counter ikut dibatalkan bila transaksi gagal → tidak ada nomor "bolong".
 */

function test_document_number_wajib_di_dalam_transaksi(): void
{
    t_throws(
        static fn () => DocumentNumberService::next('GR', '2026-10-18'),
        LogicException::class,
        'Penomoran di luar transaksi database DITOLAK'
    );
}

function test_document_number_format_dan_urutan(): void
{
    $first = Database::transaction(static fn () => DocumentNumberService::next('GR', '2026-10-18'));
    $second = Database::transaction(static fn () => DocumentNumberService::next('GR', '2026-10-18'));

    t_true(preg_match('/^GR-202610-\d{4}$/', $first) === 1, 'Format nomor penerimaan = GR-YYYYMM-####');
    t_true(preg_match('/^GR-202610-\d{4}$/', $second) === 1, 'Nomor kedua juga berformat benar');
    t_eq(
        (int) substr($first, -4) + 1,
        (int) substr($second, -4),
        'Nomor dokumen naik satu setiap transaksi'
    );

    $issue = Database::transaction(static fn () => DocumentNumberService::next('GI', '2026-10-18'));
    t_true(str_starts_with($issue, 'GI-202610-'), 'Prefix GI punya counter terpisah dari GR');

    $opname = Database::transaction(static fn () => DocumentNumberService::next('SO', '2026-10-18'));
    t_true(str_starts_with($opname, 'SO-202610-'), 'Prefix SO punya counter terpisah');

    $newPeriod = Database::transaction(static function (): string {
        // Periode acak jauh di masa depan → dipastikan belum pernah dipakai uji lain.
        return DocumentNumberService::next('GR', sprintf('%04d-%02d-01', random_int(2100, 9999), random_int(1, 12)));
    });
    t_eq('0001', substr($newPeriod, -4), 'Periode (bulan/tahun) baru selalu dimulai dari 0001');
    t_true(preg_match('/^GR-\d{6}-0001$/', $newPeriod) === 1, 'Format nomor periode baru tetap GR-YYYYMM-####');
}

function test_document_number_unik_dalam_satu_transaksi(): void
{
    $numbers = Database::transaction(static fn (): array => [
        DocumentNumberService::next('GR', '2026-12-01'),
        DocumentNumberService::next('GR', '2026-12-01'),
        DocumentNumberService::next('GR', '2026-12-01'),
    ]);

    t_eq(3, count($numbers), 'Tiga nomor diambil dalam satu transaksi');
    t_eq(3, count(array_unique($numbers)), 'Ketiga nomor berbeda (tidak ada duplikasi)');
}

function test_document_number_batal_transaksi_tidak_meninggalkan_lubang(): void
{
    $diambil = null;

    try {
        Database::transaction(static function () use (&$diambil): void {
            $diambil = DocumentNumberService::next('SO', '2026-10-18');
            throw new RuntimeException('uji pembatalan transaksi');
        });
    } catch (RuntimeException $e) {
        // Diharapkan: transaksi dibatalkan.
    }

    t_true(is_string($diambil), 'Nomor diambil di dalam transaksi yang kemudian dibatalkan');

    $lagi = Database::transaction(static fn () => DocumentNumberService::next('SO', '2026-10-18'));
    t_eq($diambil, $lagi, 'Counter ikut dibatalkan sehingga nomor yang gagal dapat dipakai ulang (tanpa duplikasi)');
}
