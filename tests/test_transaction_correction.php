<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\CorrectionService;
use App\Services\StockInitialService;
use App\Services\StockLedger;

/**
 * Pengujian Fase 4 — koreksi transaksi (PRD §7.4, §9 aturan 8).
 *
 * Yang diuji:
 *  - transaksi lama TIDAK PERNAH dihapus/diubah — koreksi membuat pergerakan
 *    pembalik BARU (KOREKSI) yang menunjuk transaksi asal;
 *  - wajib: alasan, pengguna, waktu, dan referensi transaksi asal;
 *  - Audit Log mencatat nilai sebelum/sesudah;
 *  - koreksi boleh sebagian; total pembalikan tidak boleh melebihi aslinya;
 *  - koreksi tidak boleh membuat stok negatif;
 *  - pergerakan STOK_AWAL/PENYESUAIAN_OPNAME/KOREKSI tidak dapat dikoreksi
 *    dengan cara ini (memakai stock opname).
 */

/** Baris ledger milik satusparepart uji. @return array<int,array<string,mixed>> */
function t_correction_ledger(int $sparepartId): array
{
    $stmt = Database::pdo()->prepare('SELECT * FROM stock_movements WHERE sparepart_id = ? ORDER BY id ASC');
    $stmt->execute([$sparepartId]);
    return $stmt->fetchAll();
}

/** @return array<string,int> */
function t_correction_fixture(float $stock = 10.0): array
{
    $categoryId = t_make_category('Kategori Koreksi');
    $actorId = t_make_user('uji_koreksi_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs']);

    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-01', 'Stok awal uji koreksi', $actorId, 'MANUAL');
    }

    return ['sparepart' => $sparepartId, 'actor' => $actorId, 'category' => $categoryId];
}

/** Buat pergerakan uji langsung lewat ledger (di dalam transaksi). */
function t_correction_movement(int $sparepartId, string $type, float $signedQty, int $actorId, string $referenceNumber): int
{
    $result = Database::transaction(static fn (): array => StockLedger::apply(
        $sparepartId,
        $type,
        $signedQty,
        '2026-10-10',
        'MANUAL',
        null,
        $referenceNumber,
        'Pergerakan uji koreksi',
        $actorId
    ));
    return $result['movement_id'];
}

function test_transaction_correction_membalik_masuk_tanpa_menghapus_asal(): void
{
    $f = t_correction_fixture(10);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 5, $f['actor'], 'GR-UJI-0001');
    t_eq(15.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok 10 → 15 setelah pergerakan MASUK');

    $hasil = CorrectionService::correct($masukId, [
        'quantity' => 2,
        'reason'   => 'Salah input jumlah penerimaan',
    ], $f['actor']);

    t_eq(13.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok berkurang 15 → 13 (pembalikan MASUK)');
    t_eq($masukId, $hasil['original_id'], 'Hasil koreksi menunjuk transaksi asal');
    t_eq(2.0, $hasil['quantity'], 'Dua unit dibalik');

    $correction = StockMovement::find($hasil['movement_id']);
    t_eq(StockMovement::TYPE_CORRECTION, (string) $correction['movement_type'], 'Pergerakan baru bertipe KOREKSI');
    t_eq(-2.0, (float) $correction['quantity'], 'Kuantitas koreksi bertanda negatif (mengurangi)');
    t_eq($masukId, (int) $correction['reversal_of_id'], 'Koreksi menautkan baris ledger asal (reversal_of_id)');
    t_eq('CORRECTION', (string) $correction['reference_type'], 'Jenis referensi = CORRECTION');
    t_eq('GR-UJI-0001', (string) $correction['reference_number'], 'Nomor dokumen asal dibawa ke baris koreksi');
    t_eq('Salah input jumlah penerimaan', (string) $correction['note'], 'Alasan koreksi tersimpan di ledger');
    t_eq($f['actor'], (int) $correction['user_id'], 'Pengguna pelaksana koreksi tercatat');
    t_true($correction['created_at'] !== null, 'Waktu koreksi tercatat');
    t_eq(13.0, (float) $correction['stock_after'], 'Baris koreksi mencatat stok sesudah');

    // Transaksi asal UTUH dan tidak dihapus.
    $original = StockMovement::find($masukId);
    t_true($original !== null, 'Transaksi asal MASIH ADA setelah dikoreksi');
    t_eq(5.0, (float) $original['quantity'], 'Kuantitas transaksi asal tidak diubah');
    t_eq(10.0, (float) $original['stock_before'], 'Stok sebelum transaksi asal tidak diubah');
    t_eq(15.0, (float) $original['stock_after'], 'Stok sesudah transaksi asal tidak diubah');
    t_eq(3, count(t_correction_ledger($f['sparepart'])), 'Ledger berisi 3 baris (stok awal, MASUK, KOREKSI) — tidak ada penghapusan');
    t_eq(3.0, StockLedger::remainingReversible($original), 'Sisa yang masih dapat dibalik = 5 - 2 = 3');

    $audit = t_latest_audit('TRANSACTION_CORRECTION');
    t_true($audit !== null, 'Audit Log mencatat TRANSACTION_CORRECTION');
    t_contains((string) $masukId, (string) $audit['old_values'], 'Audit Log menyimpan referensi transaksi asal (nilai sebelum)');
    t_contains('KOREKSI', (string) $audit['new_values'], 'Audit Log menyimpan hasil koreksi (nilai sesudah)');
    t_contains('Salah input', (string) $audit['description'], 'Audit Log memuat alasan koreksi');
}

function test_transaction_correction_membalik_keluar_menambah_stok(): void
{
    $f = t_correction_fixture(10);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 5, $f['actor'], 'GR-UJI-0002');
    $keluarId = t_correction_movement($f['sparepart'], StockMovement::TYPE_OUT, -4, $f['actor'], 'GI-UJI-0001');
    t_eq(11.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok setelah MASUK 5 dan KELUAR 4 = 11');

    $hasil = CorrectionService::correct($keluarId, [
        'quantity' => 4,
        'reason'   => 'Pengeluaran dibatalkan karena SPK batal',
    ], $f['actor']);

    t_eq(15.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Pembalikan KELUAR menambah stok (11 → 15)');

    $correction = StockMovement::find($hasil['movement_id']);
    t_eq(4.0, (float) $correction['quantity'], 'Kuantitas koreksi bertanda positif (menambah)');
    t_eq($keluarId, (int) $correction['reversal_of_id'], 'Menunjuk pergerakan KELUAR asal');
    t_eq($keluarId, (int) $hasil['original_id'], 'Hasil menunjuk pergerakan asal yang benar');
    t_true(StockMovement::find($masukId) !== null, 'Pergerakan lain tidak ikut terpengaruh');

    t_throws(
        static fn () => CorrectionService::correct($keluarId, ['quantity' => 1, 'reason' => 'Koreksi lagi'], $f['actor']),
        RuntimeException::class,
        'Koreksi ulang setelah dibalik penuh DITOLAK'
    );
}

function test_transaction_correction_sebagian_dan_pembatasan(): void
{
    $f = t_correction_fixture(0);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 5, $f['actor'], 'GR-UJI-0003');

    CorrectionService::correct($masukId, ['quantity' => 2, 'reason' => 'Pembalikan bagian pertama'], $f['actor']);
    t_eq(3.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Sisa stok setelah pembalikan sebagian = 3');
    t_eq(3.0, StockLedger::remainingReversible(StockMovement::find($masukId)), 'Sisa dapat dibalik = 3');

    t_throws(
        static fn () => CorrectionService::correct($masukId, ['quantity' => 4, 'reason' => 'Melebihi sisa'], $f['actor']),
        RuntimeException::class,
        'Koreksi melebihi sisa yang dapat dibalik DITOLAK'
    );

    CorrectionService::correct($masukId, ['quantity' => 3, 'reason' => 'Pembalikan bagian kedua'], $f['actor']);
    t_eq(0.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Setelah dibalik penuh stok kembali ke 0');
    t_eq(0.0, StockLedger::remainingReversible(StockMovement::find($masukId)), 'Tidak ada sisa yang dapat dibalik');

    t_throws(
        static fn () => CorrectionService::correct($masukId, ['quantity' => 1, 'reason' => 'Sudah habis'], $f['actor']),
        RuntimeException::class,
        'Koreksi lanjutan setelah dibalik seluruhnya DITOLAK'
    );

    t_eq(2, count(StockMovement::reversalsOf($masukId)), 'Kedua pergerakan pembalik tertaut ke transaksi asal');
    $total = array_sum(array_map(
        static fn (array $row): float => (float) $row['quantity'],
        StockMovement::reversalsOf($masukId)
    ));
    t_eq(-5.0, $total, 'Total pembalikan = -5 (tidak melebihi transaksi asal)');
    t_true(StockMovement::find($masukId) !== null, 'Transaksi asal tetap ada setelah dibalik seluruhnya');
}

function test_transaction_correction_tidak_boleh_membuat_stok_negatif(): void
{
    $f = t_correction_fixture(5);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 5, $f['actor'], 'GR-UJI-0004');
    t_correction_movement($f['sparepart'], StockMovement::TYPE_OUT, -9, $f['actor'], 'GI-UJI-0002');
    t_eq(1.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tersisa 1 setelah pengeluaran besar');

    $sebelum = count(t_correction_ledger($f['sparepart']));

    t_throws(
        static fn () => CorrectionService::correct($masukId, ['quantity' => 5, 'reason' => 'Balik semua penerimaan'], $f['actor']),
        RuntimeException::class,
        'Koreksi yang akan membuat stok negatif DITOLAK'
    );

    t_eq(1.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tetap 1 (stok negatif tidak mungkin)');
    t_eq($sebelum, count(t_correction_ledger($f['sparepart'])), 'Tidak ada baris koreksi tertinggal dari percobaan yang gagal');
    t_eq(5.0, StockLedger::remainingReversible(StockMovement::find($masukId)), 'Sisa pembalikan tetap utuh setelah kegagalan');
}

function test_transaction_correction_jenis_tidak_dapat_dikoreksi(): void
{
    $f = t_correction_fixture(1);

    $awal = StockMovement::initialFor($f['sparepart']);
    t_true($awal !== null, 'Pergerakan stok awal tersedia untuk uji');
    t_throws(
        static fn () => CorrectionService::correct((int) $awal['id'], ['quantity' => 1, 'reason' => 'Coba koreksi stok awal'], $f['actor']),
        RuntimeException::class,
        'Pergerakan STOK_AWAL TIDAK dapat dikoreksi lewat transaksi pembalik'
    );

    $pesan = '';
    try {
        CorrectionService::correct((int) $awal['id'], ['quantity' => 1, 'reason' => 'Uji pesan penolakan'], $f['actor']);
    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
    }
    t_contains('opname', $pesan, 'Pesan penolakan mengarahkan pengguna ke stock opname');

    $opnameId = t_correction_movement($f['sparepart'], StockMovement::TYPE_OPNAME, 2, $f['actor'], 'SO-UJI-0001');
    t_throws(
        static fn () => CorrectionService::correct($opnameId, ['quantity' => 1, 'reason' => 'Coba koreksi penyesuaian'], $f['actor']),
        RuntimeException::class,
        'Pergerakan PENYESUAIAN_OPNAME TIDAK dapat dikoreksi lewat transaksi pembalik'
    );

    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 4, $f['actor'], 'GR-UJI-0005');
    $koreksi = CorrectionService::correct($masukId, ['quantity' => 1, 'reason' => 'Koreksi untuk uji'], $f['actor']);
    t_throws(
        static fn () => CorrectionService::correct((int) $koreksi['movement_id'], ['quantity' => 1, 'reason' => 'Koreksi atas koreksi'], $f['actor']),
        RuntimeException::class,
        'Pergerakan KOREKSI tidak dapat dikoreksi lagi'
    );

    t_throws(
        static fn () => CorrectionService::correct(99999999, ['quantity' => 1, 'reason' => 'Tidak ada'], $f['actor']),
        RuntimeException::class,
        'Transaksi asal yang tidak ditemukan DITOLAK'
    );

    t_true(CorrectionService::isCorrectable(StockMovement::find($masukId)), 'MASUK dikenali sebagai dapat dikoreksi');
    t_true(!CorrectionService::isCorrectable((array) $awal), 'STOK_AWAL dikenali sebagai tidak dapat dikoreksi');
}

function test_transaction_correction_validasi_masukan(): void
{
    $f = t_correction_fixture(0);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 10, $f['actor'], 'GR-UJI-0006');

    $kasus = [
        'Alasan kosong ditolak'          => ['quantity' => 1, 'reason' => ''],
        'Alasan terlalu pendek ditolak'  => ['quantity' => 1, 'reason' => 'ok'],
        'Alasan terlalu panjang ditolak' => ['quantity' => 1, 'reason' => str_repeat('R', CorrectionService::REASON_MAX + 1)],
        'Jumlah 0 ditolak'               => ['quantity' => 0, 'reason' => 'Alasan uji'],
        'Jumlah negatif ditolak'         => ['quantity' => -2, 'reason' => 'Alasan uji'],
        'Jumlah melebihi batas ditolak'  => ['quantity' => CorrectionService::QTY_MAX + 1, 'reason' => 'Alasan uji'],
        'Jumlah kosong ditolak'          => ['quantity' => '', 'reason' => 'Alasan uji'],
    ];

    foreach ($kasus as $label => $data) {
        t_throws(
            static fn () => CorrectionService::correct($masukId, $data, $f['actor']),
            RuntimeException::class,
            $label
        );
    }

    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah semua masukan tidak sah ditolak');
    t_eq(1, count(t_correction_ledger($f['sparepart'])), 'Tidak ada baris koreksi yang tertinggal');
}

function test_transaction_correction_daftar_dan_riwayat(): void
{
    $f = t_correction_fixture(0);
    $masukId = t_correction_movement($f['sparepart'], StockMovement::TYPE_IN, 6, $f['actor'], 'GR-UJI-0007');
    $hasil = CorrectionService::correct($masukId, ['quantity' => 2, 'reason' => 'Koreksi untuk uji daftar'], $f['actor']);

    $correctable = StockMovement::correctable(200);
    $correctableIds = array_map(static fn (array $r): int => (int) $r['id'], $correctable);
    t_true(in_array($masukId, $correctableIds, true), 'Pergerakan yang masih bersisa muncul di daftar dapat dikoreksi');
    t_true(!in_array((int) $hasil['movement_id'], $correctableIds, true), 'Baris KOREKSI tidak muncul di daftar dapat dikoreksi');

    $koreksiRows = StockMovement::filtered(['movement_type' => StockMovement::TYPE_CORRECTION], 200);
    $koreksiIds = array_map(static fn (array $r): int => (int) $r['id'], $koreksiRows);
    t_true(in_array((int) $hasil['movement_id'], $koreksiIds, true), 'Riwayat dapat difilter per jenis KOREKSI');

    $bySparepart = StockMovement::filtered(['sparepart_id' => $f['sparepart']], 200);
    t_eq(2, count($bySparepart), 'Riwayat per sparepart memuat MASUK dan KOREKSI');

    $totals = StockMovement::totals(['sparepart_id' => $f['sparepart']]);
    t_eq(6.0, $totals['masuk'], 'Ringkasan total masuk = 6');
    t_eq(2.0, $totals['keluar'], 'Pembalikan dihitung sebagai pengurang (total keluar = 2)');
    t_eq(4.0, $totals['menyesuaikan'], 'Selisih bersih pergerakan = 4');

    $count = StockMovement::countFiltered(['reference_type' => 'CORRECTION']);
    t_true($count >= 1, 'Riwayat dapat dihitung dengan filter jenis referensi koreksi');
}
