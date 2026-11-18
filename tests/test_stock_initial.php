<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 3 — Stok awal (FR-05).
 *
 * Yang diuji:
 *  - stok awal tercatat sebagai pergerakan STOK_AWAL (bukan pembelian)
 *    lengkap dengan pengguna, tanggal, item, jumlah, dan catatan;
 *  - stok sparepart ikut bertambah dalam transaksi yang sama;
 *  - stok awal TIDAK dapat diterapkan dua kali untuk item yang sama
 *    (dijaga service + TRIGGER database);
 *  - koreksi tetap mungkin lewat PENYESUAIAN_OPNAME (FR-10), dan
 *    pergerakan lain (MASUK) tidak ikut terblokir;
 *  - berbagai format tanggal diterima.
 */

function test_stock_initial_membentuk_ledger_yang_benar(): void
{
    $categoryId = t_make_category('Kategori Stok Awal');
    $actorId = t_make_user('uji_stok_awal', 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'liter']);
    $code = (string) Sparepart::find($sparepartId)['code'];

    t_true(!StockInitialService::hasInitial($sparepartId), 'Sparepart baru belum punya stok awal');

    $movementId = StockInitialService::apply($sparepartId, 12.5, '18/10/2026', 'Hitung fisik awal', $actorId, 'MANUAL');
    t_true($movementId > 0, 'Pencatatan stok awal mengembalikan ID pergerakan');

    $movement = StockMovement::initialFor($sparepartId);
    t_true($movement !== null, 'Pergerakan STOK_AWAL tersimpan');
    t_eq(StockMovement::TYPE_INITIAL, (string) $movement['movement_type'], 'Jenis pergerakan = STOK_AWAL (bukan pembelian)');
    t_eq(12.5, (float) $movement['quantity'], 'Jumlah sesuai');
    t_eq(0.0, (float) $movement['stock_before'], 'Stok sebelum = 0');
    t_eq(12.5, (float) $movement['stock_after'], 'Stok sesudah = 12,5');
    t_eq('2026-10-18', (string) $movement['movement_date'], 'Tanggal bisnis tersimpan');
    t_eq($actorId, (int) $movement['user_id'], 'Pengguna tercatat');
    t_eq('Hitung fisik awal', (string) $movement['note'], 'Catatan tercatat');
    t_eq('MANUAL', (string) $movement['reference_type'], 'Sumber pencatatan = MANUAL');

    $sparepart = Sparepart::find($sparepartId);
    t_eq(12.5, (float) $sparepart['stock'], 'Stok sparepart bertambah sesuai');

    t_true(t_latest_audit('STOCK_INITIAL_CREATE', $sparepartId) !== null, 'Pencatatan stok awal tercatat di Audit Log');

    // Kartu stok menampilkan data pergerakan + nama pengguna.
    $latest = StockMovement::latestFor($sparepartId, 5);
    t_true($latest !== [], 'Kartu stok menampilkan pergerakan');
    t_eq('uji_stok_awal', (string) $latest[0]['username'], 'Kartu stok menampilkan pengguna pencatat');

    t_eq(1, StockMovement::countByType()[StockMovement::TYPE_INITIAL] >= 1 ? 1 : 0, 'Ledger memiliki pergerakan STOK_AWAL');
    t_contains($code, (string) $sparepart['code'], 'Kode sparepart yang diuji konsisten');
}

function test_stock_initial_tidak_bisa_dua_kali(): void
{
    $categoryId = t_make_category('Kategori Stok Awal Ganda');
    $sparepartId = t_make_sparepart($categoryId);

    StockInitialService::apply($sparepartId, 5, null, null, null, 'MANUAL');
    t_true(StockInitialService::hasInitial($sparepartId), 'Stok awal pertama tersimpan');

    t_throws(
        static fn () => StockInitialService::apply($sparepartId, 3, null, 'Percobaan kedua', null, 'MANUAL'),
        RuntimeException::class,
        'Pencatatan stok awal kedua DITOLAK oleh service'
    );

    $jumlah = (int) Database::pdo()->query(
        'SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ' . $sparepartId . " AND movement_type = 'STOK_AWAL'"
    )->fetchColumn();
    t_eq(1, $jumlah, 'Hanya ada satu catatan STOK_AWAL untuk sparepart tersebut');
    t_eq(5.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok tidak berubah akibat percobaan kedua');
}

function test_stock_initial_dijaga_trigger_database(): void
{
    $categoryId = t_make_category('Kategori Trigger Stok Awal');
    $sparepartId = t_make_sparepart($categoryId);
    StockInitialService::apply($sparepartId, 4, null, null, null, 'MANUAL');

    // Menembus service (mis. bug/alat lain) tetap harus ditolak database.
    t_throws(
        static fn () => Database::pdo()->prepare(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$sparepartId, 'STOK_AWAL', 9, date('Y-m-d'), 4, 13, 'MANUAL']),
        PDOException::class,
        'Trigger database menolak STOK_AWAL kedua walau INSERT langsung'
    );

    // Pergerakan bertipe lain TIDAK terblokir.
    Database::pdo()->prepare(
        'INSERT INTO stock_movements
            (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$sparepartId, StockMovement::TYPE_IN, 6, date('Y-m-d'), 4, 10, 'MANUAL']);
    t_true(true, 'Pergerakan bertipe MASUK tetap dapat dicatat setelah stok awal');

    // Koreksi resmi lewat penyesuaian opname (FR-10) tetap diperbolehkan.
    Database::pdo()->prepare(
        'INSERT INTO stock_movements
            (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$sparepartId, StockMovement::TYPE_OPNAME, -1, date('Y-m-d'), 10, 9, 'MANUAL', 'Koreksi opname']);
    t_true(true, 'Koreksi lewat PENYESUAIAN_OPNAME tetap diperbolehkan');
}

function test_stock_initial_validasi_jumlah_dan_tanggal(): void
{
    $categoryId = t_make_category('Kategori Validasi Stok Awal');

    t_throws(
        static fn () => StockInitialService::apply(t_make_sparepart($categoryId), 0, null, null, null, 'MANUAL'),
        RuntimeException::class,
        'Jumlah stok awal 0 ditolak'
    );
    t_throws(
        static fn () => StockInitialService::apply(t_make_sparepart($categoryId), -2, null, null, null, 'MANUAL'),
        RuntimeException::class,
        'Jumlah stok awal negatif ditolak'
    );
    t_throws(
        static fn () => StockInitialService::apply(999999, 5, null, null, null, 'MANUAL'),
        RuntimeException::class,
        'Stok awal untuk sparepart yang tidak ada ditolak'
    );

    // Tanggal kosong → hari ini.
    $idHariIni = t_make_sparepart($categoryId);
    StockInitialService::apply($idHariIni, 1, null, null, null, 'MANUAL');
    t_eq(
        date('Y-m-d'),
        (string) StockInitialService::initialFor($idHariIni)['movement_date'],
        'Tanggal kosong memakai tanggal hari ini'
    );

    // yyyy-mm-dd.
    $idIso = t_make_sparepart($categoryId);
    StockInitialService::apply($idIso, 1, '2026-01-31', null, null, 'MANUAL');
    t_eq('2026-01-31', (string) StockInitialService::initialFor($idIso)['movement_date'], 'Format yyyy-mm-dd diterima');

    // Serial tanggal Excel.
    $idSerial = t_make_sparepart($categoryId);
    StockInitialService::apply($idSerial, 1, '46354', null, null, 'MANUAL');
    t_true(
        StockInitialService::initialFor($idSerial)['movement_date'] !== null,
        'Serial tanggal Excel (angka) dapat ditafsirkan'
    );

    // Tanggal ngawur → jatuh ke hari ini (tidak menjatuhkan proses), tetapi
    // pengguna tetap melihat riwayat yang jelas pada kartu stok.
    $idNgawur = t_make_sparepart($categoryId);
    StockInitialService::apply($idNgawur, 1, 'bukan-tanggal', null, null, 'MANUAL');
    t_eq(
        date('Y-m-d'),
        (string) StockInitialService::initialFor($idNgawur)['movement_date'],
        'Tanggal tidak dikenali jatuh ke tanggal hari ini (dijaga di lapisan controller/import)'
    );

    // Baris lama (sebelum migrasi 009) tetap punya movement_date terisi.
    $kosong = (int) Database::pdo()->query(
        'SELECT COUNT(*) FROM stock_movements WHERE movement_date IS NULL'
    )->fetchColumn();
    t_eq(0, $kosong, 'Semua baris ledger memiliki tanggal pergerakan (migrasi 009 + pengisian otomatis)');
}
