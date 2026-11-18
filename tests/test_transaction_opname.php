<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Services\GoodsIssueService;
use App\Services\StockInitialService;
use App\Services\StockOpnameService;

/**
 * Pengujian Fase 4 — stock opname / perhitungan fisik (FR-10).
 *
 * Yang diuji (PRD §9 aturan 6 & 7):
 *  - sesi opname membuat SNAPSHOT stok sistem dan TIDAK mengubah stok;
 *  - mengisi jumlah fisik juga TIDAK mengubah stok;
 *  - alasan WAJIB bila ada selisih;
 *  - stok baru berubah setelah Partman MENGONFIRMASI, lewat pergerakan
 *    PENYESUAIAN_OPNAME (bukan dengan mengubah baris lama);
 *  - hasil akhir opname selalu sama dengan jumlah fisik yang dihitung,
 *    walau stok sistem berubah setelah snapshot (docs/ASSUMPTIONS.md B33);
 *  - hanya satu sesi terbuka pada satu waktu; sesi terkonfirmasi tidak
 *    dapat diubah lagi.
 */

/** Hapus sesi opname yang masih terbuka (bersih-bersih antar pengujian). */
function t_opname_cleanup(): void
{
    $pdo = Database::pdo();
    $pdo->exec("DELETE i FROM stock_opname_items i JOIN stock_opnames o ON o.id = i.opname_id WHERE o.status = 'OPEN'");
    $pdo->exec("DELETE FROM stock_opnames WHERE status = 'OPEN'");
}

/** @return array<string,int> */
function t_opname_fixture(float $stock = 10.0): array
{
    $categoryId = t_make_category('Kategori Opname');
    $actorId = t_make_user('uji_opname_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs']);

    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-01', 'Stok awal uji opname', $actorId, 'MANUAL');
    }

    return ['sparepart' => $sparepartId, 'actor' => $actorId, 'category' => $categoryId];
}

/** Baris item opname untuk sparepart tertentu. @return array<string,mixed>|null */
function t_opname_item(int $opnameId, int $sparepartId): ?array
{
    foreach (StockOpname::items($opnameId) as $item) {
        if ((int) $item['sparepart_id'] === $sparepartId) {
            return $item;
        }
    }
    return null;
}

function test_transaction_opname_mulai_membuat_snapshot_tanpa_mengubah_stok(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(10);

    $start = StockOpnameService::start([
        'opname_date' => '2026-10-18',
        'note'        => 'Opname rutin bulan Oktober',
    ], $f['actor']);

    t_true($start['opname_id'] > 0, 'Sesi opname dibuat dan mengembalikan ID');
    t_true(
        preg_match('/^SO-\d{6}-\d{4}$/', $start['number']) === 1,
        'Nomor sesi otomatis berformat SO-YYYYMM-####'
    );
    t_true($start['item_count'] >= 1, 'Sesi menyimpan snapshot seluruh sparepart aktif');

    $opname = StockOpname::find($start['opname_id']);
    t_eq(StockOpname::STATUS_OPEN, (string) $opname['status'], 'Status awal sesi = OPEN (belum dikonfirmasi)');
    t_eq('2026-10-18', (string) $opname['opname_date'], 'Tanggal bisnis opname tersimpan');
    t_eq($f['actor'], (int) $opname['started_by'], 'Partman pemulai sesi tersimpan');

    $item = t_opname_item($start['opname_id'], $f['sparepart']);
    t_true($item !== null, 'Sparepart masuk sebagai item perhitungan');
    t_eq(10.0, (float) $item['system_stock'], 'Stok sistem di-snapshot pada saat sesi dibuat');
    t_eq(null, $item['physical_stock'], 'Jumlah fisik belum diisi');

    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok sistem TIDAK berubah saat sesi dimulai');

    t_true(t_latest_audit('STOCK_OPNAME_START', $start['opname_id']) !== null, 'Audit Log mencatat STOCK_OPNAME_START');

    t_true(StockOpname::hasOpenSession(), 'Sistem mengenali ada sesi terbuka');
    t_throws(
        static fn () => StockOpnameService::start(['opname_date' => '2026-10-18'], $f['actor']),
        RuntimeException::class,
        'Memulai sesi kedua saat masih ada sesi terbuka DITOLAK'
    );

    t_opname_cleanup();
}

function test_transaction_opname_isi_fisik_tidak_mengubah_stok(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(10);
    $start = StockOpnameService::start(['opname_date' => '2026-10-18'], $f['actor']);
    $opnameId = $start['opname_id'];

    // Cocok (fisik = sistem) → tidak butuh alasan.
    $hasil = StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '10'], [], $f['actor']);
    t_eq(1, $hasil['counted'], 'Satu item tercatat dihitung');
    t_eq(1, $hasil['matched'], 'Item dengan jumlah sama dihitung sebagai cocok');
    t_eq(0, $hasil['differences'], 'Tidak ada selisih');
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok TIDAK berubah setelah menyimpan hasil hitung');

    // Selisih TANPA alasan → ditolak.
    t_throws(
        static fn () => StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '7'], [], $f['actor']),
        RuntimeException::class,
        'Selisih tanpa alasan DITOLAK (PRD §9 aturan 7)'
    );
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tetap tidak berubah setelah penolakan');

    // Selisih DENGAN alasan → tersimpan, stok tetap belum berubah.
    $hasil = StockOpnameService::saveInputs(
        $opnameId,
        [$f['sparepart'] => '7'],
        [$f['sparepart'] => 'Barang rusak 3 unit'],
        $f['actor']
    );
    t_eq(1, $hasil['differences'], 'Selisih terdeteksi');
    $item = t_opname_item($opnameId, $f['sparepart']);
    t_eq(7.0, (float) $item['physical_stock'], 'Jumlah fisik tersimpan');
    t_eq(-3.0, (float) $item['difference'], 'Selisih dihitung (fisik - sistem)');
    t_eq('Barang rusak 3 unit', (string) $item['reason'], 'Alasan selisih tersimpan');
    t_eq($f['actor'], (int) $item['counted_by'], 'Penghitung tercatat');
    t_true($item['counted_at'] !== null, 'Waktu penghitungan tercatat');
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok MASIH belum berubah sebelum konfirmasi');

    // Validasi masukan.
    t_throws(
        static fn () => StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '-1'], [$f['sparepart'] => 'x'], $f['actor']),
        RuntimeException::class,
        'Jumlah fisik negatif DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::saveInputs(
            $opnameId,
            [$f['sparepart'] => '7'],
            [$f['sparepart'] => str_repeat('R', StockOpnameService::REASON_MAX + 1)],
            $f['actor']
        ),
        RuntimeException::class,
        'Alasan selisih yang terlalu panjang DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => StockOpnameService::QTY_MAX + 1], [$f['sparepart'] => 'x'], $f['actor']),
        RuntimeException::class,
        'Jumlah fisik yang tidak masuk akal DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::saveInputs($opnameId, [], [], $f['actor']),
        RuntimeException::class,
        'Menyimpan tanpa satu pun jumlah fisik DITOLAK'
    );

    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tetap 10 setelah semua penolakan');
    t_opname_cleanup();
}

function test_transaction_opname_konfirmasi_menyesuaikan_stok_dan_menutup_sesi(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(10);
    $start = StockOpnameService::start(['opname_date' => '2026-10-18'], $f['actor']);
    $opnameId = $start['opname_id'];

    StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '7'], [$f['sparepart'] => 'Barang rusak 3 unit'], $f['actor']);
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok belum berubah sebelum konfirmasi');

    $hasil = StockOpnameService::confirm($opnameId, $f['actor']);
    t_eq(1, $hasil['adjusted'], 'Satu item disesuaikan saat konfirmasi');
    t_eq(1, $hasil['movements'], 'Satu pergerakan penyesuaian dibuat');

    t_eq(7.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok akhir = jumlah fisik yang dihitung (10 → 7)');

    $movement = StockMovement::forReference('OPNAME', $opnameId);
    t_true($movement !== null, 'Pergerakan PENYESUAIAN_OPNAME tercatat');
    t_eq(StockMovement::TYPE_OPNAME, (string) $movement['movement_type'], 'Jenis pergerakan = PENYESUAIAN_OPNAME');
    t_eq(-3.0, (float) $movement['quantity'], 'Selisih diterapkan sebagai -3');
    t_eq(10.0, (float) $movement['stock_before'], 'Ledger mencatat stok sebelum penyesuaian');
    t_eq(7.0, (float) $movement['stock_after'], 'Ledger mencatat stok sesudah penyesuaian');
    t_eq($start['number'], (string) $movement['reference_number'], 'Ledger menautkan nomor sesi opname');
    t_contains('rusak', (string) $movement['note'], 'Alasan selisih ikut tercatat di ledger');

    $opname = StockOpname::find($opnameId);
    t_eq(StockOpname::STATUS_CONFIRMED, (string) $opname['status'], 'Status sesi menjadi CONFIRMED');
    t_eq($f['actor'], (int) $opname['confirmed_by'], 'Pengguna yang mengonfirmasi tercatat');
    t_true($opname['confirmed_at'] !== null, 'Waktu konfirmasi tercatat');

    $audit = t_latest_audit('STOCK_OPNAME_CONFIRM', $opnameId);
    t_true($audit !== null, 'Audit Log mencatat STOCK_OPNAME_CONFIRM');
    t_contains('OPEN', (string) $audit['old_values'], 'Audit Log menyimpan nilai sebelum (status OPEN)');

    t_true(!StockOpname::hasOpenSession(), 'Tidak ada sesi terbuka setelah konfirmasi');

    t_throws(
        static fn () => StockOpnameService::confirm($opnameId, $f['actor']),
        RuntimeException::class,
        'Konfirmasi ulang sesi yang sudah dikonfirmasi DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '1'], [$f['sparepart'] => 'coba lagi'], $f['actor']),
        RuntimeException::class,
        'Mengubah hasil hitung sesi terkonfirmasi DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::confirm(99999999, $f['actor']),
        RuntimeException::class,
        'Konfirmasi sesi yang tidak ada DITOLAK'
    );
}

function test_transaction_opname_konfirmasi_tanpa_item_dihitung_ditolak(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(4);
    $start = StockOpnameService::start(['opname_date' => '2026-10-18'], $f['actor']);

    t_throws(
        static fn () => StockOpnameService::confirm($start['opname_id'], $f['actor']),
        RuntimeException::class,
        'Konfirmasi tanpa satu pun item dihitung DITOLAK'
    );

    t_eq(StockOpname::STATUS_OPEN, (string) StockOpname::find($start['opname_id'])['status'], 'Sesi tetap terbuka setelah penolakan');
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah');
    t_opname_cleanup();
}

function test_transaction_opname_perubahan_stok_setelah_snapshot(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(10);
    $start = StockOpnameService::start(['opname_date' => '2026-10-18'], $f['actor']);
    $opnameId = $start['opname_id'];

    // Dihitung cocok (10 = 10) → tidak butuh alasan.
    StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '10'], [], $f['actor']);
    t_eq(0, (int) t_opname_item($opnameId, $f['sparepart'])['difference'], 'Selisih nol terhadap snapshot');

    // Stok sistem berubah SETELAH snapshot (pengeluaran 5).
    GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 5,
    ], $f['actor']);
    t_eq(5.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok sistem berubah menjadi 5 setelah snapshot');

    // Konfirmasi harus MENOLAK karena selisih aktual (+5) tanpa alasan.
    t_throws(
        static fn () => StockOpnameService::confirm($opnameId, $f['actor']),
        RuntimeException::class,
        'Konfirmasi dengan selisih aktual tanpa alasan DITOLAK'
    );
    t_eq(5.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah penolakan konfirmasi');
    t_eq(StockOpname::STATUS_OPEN, (string) StockOpname::find($opnameId)['status'], 'Sesi tetap terbuka agar dapat dilengkapi');

    // Partman melengkapi alasan lalu mengonfirmasi.
    StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '10'], [$f['sparepart'] => 'Selisih karena transaksi belum dicatat'], $f['actor']);
    $hasil = StockOpnameService::confirm($opnameId, $f['actor']);

    t_eq(1, $hasil['changed_since_snapshot'], 'Sistem memberi tahu stok berubah sejak snapshot');
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Hasil akhir opname = jumlah fisik (10), bukan snapshot');

    $movement = StockMovement::forReference('OPNAME', $opnameId);
    t_eq(5.0, (float) $movement['quantity'], 'Penyesuaian dihitung terhadap stok TERBARU (5 → 10 = +5)');
    t_contains('snapshot', (string) $movement['note'], 'Ledger menjelaskan stok saat snapshot vs saat konfirmasi');
}

function test_transaction_opname_catatan_sesi_menjadi_alasan_cadangan(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(6);
    $start = StockOpnameService::start([
        'opname_date' => '2026-10-18',
        'note'        => 'Opname mendadak area gudang',
    ], $f['actor']);
    $opnameId = $start['opname_id'];

    StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '6'], [], $f['actor']);

    // Stok berubah setelah snapshot tanpa alasan per item → catatan sesi dipakai.
    GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'lainnya',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 2,
    ], $f['actor']);

    $hasil = StockOpnameService::confirm($opnameId, $f['actor']);
    t_eq(1, $hasil['adjusted'], 'Penyesuaian tetap dapat dikonfirmasi memakai catatan sesi');

    $movement = StockMovement::forReference('OPNAME', $opnameId);
    t_contains('Opname mendadak', (string) $movement['note'], 'Catatan sesi dipakai sebagai alasan cadangan');
    t_eq(6.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok akhir = jumlah fisik');
}

function test_transaction_opname_validasi_dan_daftar(): void
{
    t_opname_cleanup();
    $f = t_opname_fixture(5);

    t_throws(
        static fn () => StockOpnameService::start(['opname_date' => '31/02/2026'], $f['actor']),
        RuntimeException::class,
        'Tanggal opname tidak valid DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::start(['opname_date' => '2099-01-01'], $f['actor']),
        RuntimeException::class,
        'Tanggal opname jauh ke depan DITOLAK'
    );
    t_throws(
        static fn () => StockOpnameService::start([
            'opname_date' => '2026-10-18',
            'note'        => str_repeat('X', StockOpnameService::NOTE_MAX + 1),
        ], $f['actor']),
        RuntimeException::class,
        'Catatan sesi yang terlalu panjang DITOLAK'
    );

    $start = StockOpnameService::start(['opname_date' => '2026-10-18', 'note' => 'Uji daftar'], $f['actor']);
    $opnameId = $start['opname_id'];
    StockOpnameService::saveInputs($opnameId, [$f['sparepart'] => '3'], [$f['sparepart'] => 'Selisih uji'], $f['actor']);
    StockOpnameService::confirm($opnameId, $f['actor']);

    $openOnly = StockOpname::paginate(['status' => StockOpname::STATUS_OPEN], 1, 5);
    $openIds = array_map(static fn (array $r): int => (int) $r['id'], $openOnly['rows']);
    t_true(!in_array($opnameId, $openIds, true), 'Filter status OPEN tidak memuat sesi yang sudah dikonfirmasi');

    $confirmed = StockOpname::paginate(['status' => StockOpname::STATUS_CONFIRMED], 1, 25);
    $confirmedIds = array_map(static fn (array $r): int => (int) $r['id'], $confirmed['rows']);
    t_true(in_array($opnameId, $confirmedIds, true), 'Filter status CONFIRMED memuat sesi yang dikonfirmasi');

    $bySearch = StockOpname::paginate(['search' => 'Uji daftar'], 1, 25);
    $searchIds = array_map(static fn (array $r): int => (int) $r['id'], $bySearch['rows']);
    t_true(in_array($opnameId, $searchIds, true), 'Pencarian memakai nomor/catatan sesi');

    $detail = StockOpname::find($opnameId);
    t_eq(1, (int) $detail['counted_count'], 'Detail sesi melaporkan jumlah item yang dihitung');
    t_eq(1, (int) $detail['diff_count'], 'Detail sesi melaporkan jumlah item berselisih');
    t_eq($f['actor'], (int) $detail['confirmed_by'], 'Detail sesi menampilkan pengonfirmasi');
    t_eq(3.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok akhir sesuai hasil hitung fisik');
}
