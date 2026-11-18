<?php
declare(strict_types=1);

use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\CategoryService;
use App\Services\SparepartService;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 3 — Master sparepart (FR-03).
 *
 * Yang diuji:
 *  - CRUD + audit, kode unik & dinormalkan, batas panjang, angka negatif;
 *  - kategori WAJIB & harus aktif untuk sparepart baru (FR-12);
 *  - stok TIDAK pernah berubah lewat ubah master;
 *  - stok awal opsional saat tambah → tercatat sebagai ledger STOK_AWAL;
 *  - filter (cari/kategori/supplier/lokasi/status stok/status data).
 */

function test_master_sparepart_crud_dan_audit(): void
{
    $categoryId = t_make_category('Kategori CRUD Sparepart');
    $supplierId = t_make_supplier('Supplier CRUD Sparepart');
    $locationId = t_make_location('Rak CRUD Sparepart');
    $code = t_uniq('SP');

    $id = SparepartService::create([
        'code'           => $code,
        'name'           => 'Filter Oli Uji ' . $code,
        'category_id'    => $categoryId,
        'brand'          => 'Suzuki',
        'vehicle_model'  => 'Satria F150',
        'unit'           => 'pcs',
        'purchase_price' => '45.000',
        'selling_price'  => '60.000',
        'min_stock'      => '5',
        'supplier_id'    => $supplierId,
        'location_id'    => $locationId,
    ], null);

    $row = SparepartService::find($id);
    t_true($row !== null, 'Sparepart baru berhasil dibuat');
    t_eq($code, (string) $row['code'], 'Kode sparepart tersimpan sesuai');
    t_eq(45000.0, (float) $row['purchase_price'], 'Harga beli "45.000" terbaca sebagai 45000');
    t_eq(60000.0, (float) $row['selling_price'], 'Harga jual "60.000" terbaca sebagai 60000');
    t_eq(5.0, (float) $row['min_stock'], 'Stok minimum tersimpan');
    t_eq(0.0, (float) $row['stock'], 'Sparepart baru dimulai dengan stok 0');
    t_eq($categoryId, (int) $row['category_id'], 'Kategori terhubung');
    t_eq($supplierId, (int) $row['supplier_id'], 'Supplier terhubung');
    t_eq($locationId, (int) $row['location_id'], 'Lokasi terhubung');
    t_true(t_latest_audit('SPAREPART_CREATE', $id) !== null, 'Pembuatan sparepart tercatat di Audit Log');

    SparepartService::update($id, [
        'code'           => $code,
        'name'           => 'Filter Oli Diubah ' . $code,
        'category_id'    => $categoryId,
        'brand'          => 'Suzuki',
        'vehicle_model'  => 'Satria F150',
        'unit'           => 'pcs',
        'purchase_price' => '48000',
        'selling_price'  => '62000',
        'min_stock'      => '7',
        'supplier_id'    => $supplierId,
        'location_id'    => $locationId,
    ], null);

    $updated = SparepartService::find($id);
    t_eq('Filter Oli Diubah ' . $code, (string) $updated['name'], 'Nama sparepart berhasil diubah');
    t_eq(7.0, (float) $updated['min_stock'], 'Stok minimum berhasil diubah');
    t_eq(0.0, (float) $updated['stock'], 'Stok TIDAK berubah lewat ubah master');
    t_true(t_latest_audit('SPAREPART_UPDATE', $id) !== null, 'Perubahan sparepart tercatat di Audit Log');
}

function test_master_sparepart_kode_unik_dan_validasi(): void
{
    $categoryId = t_make_category('Kategori Validasi Sparepart');
    $suffix = strtoupper(bin2hex(random_bytes(3)));

    $id = SparepartService::create([
        'code'        => 'sp-' . strtolower($suffix),
        'name'        => 'Sparepart Huruf Kecil ' . $suffix,
        'category_id' => $categoryId,
    ], null);
    t_eq('SP-' . $suffix, (string) SparepartService::find($id)['code'], 'Kode sparepart dinormalkan menjadi huruf besar');

    $base = ['name' => 'Duplikat ' . $suffix, 'category_id' => $categoryId];

    t_throws(
        static fn () => SparepartService::create(['code' => 'SP-' . $suffix] + $base, null),
        RuntimeException::class,
        'Kode sparepart duplikat ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(['code' => '', 'name' => 'Tanpa Kode', 'category_id' => $categoryId], null),
        RuntimeException::class,
        'Kode sparepart kosong ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(['code' => t_uniq('SP'), 'name' => '  ', 'category_id' => $categoryId], null),
        RuntimeException::class,
        'Nama sparepart kosong ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => str_repeat('X', SparepartService::CODE_MAX + 1), 'name' => 'Kode Panjang', 'category_id' => $categoryId],
            null
        ),
        RuntimeException::class,
        'Kode sparepart melebihi batas ditolak (tanpa pemotongan diam-diam)'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => str_repeat('N', SparepartService::NAME_MAX + 1), 'category_id' => $categoryId],
            null
        ),
        RuntimeException::class,
        'Nama sparepart melebihi batas ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Harga Negatif', 'category_id' => $categoryId, 'purchase_price' => '-1'],
            null
        ),
        RuntimeException::class,
        'Harga beli negatif ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Stok Min Negatif', 'category_id' => $categoryId, 'min_stock' => '-5'],
            null
        ),
        RuntimeException::class,
        'Stok minimum negatif ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Stok Awal Negatif', 'category_id' => $categoryId, 'initial_stock' => '-3'],
            null
        ),
        RuntimeException::class,
        'Stok awal negatif ditolak'
    );

    $idKosong = SparepartService::create([
        'code'        => t_uniq('SP'),
        'name'        => 'Satuan Default',
        'category_id' => $categoryId,
        'unit'        => '',
    ], null);
    t_eq('pcs', (string) SparepartService::find($idKosong)['unit'], 'Satuan kosong memakai nilai bawaan "pcs"');
}

function test_master_sparepart_kategori_wajib_dan_aktif(): void
{
    $categoryId = t_make_category('Kategori Nonaktif Sparepart');

    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Tanpa Kategori', 'category_id' => 0],
            null
        ),
        RuntimeException::class,
        'Sparepart tanpa kategori ditolak'
    );
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Kategori Tak Ada', 'category_id' => 999999],
            null
        ),
        RuntimeException::class,
        'Kategori tidak dikenal ditolak'
    );

    CategoryService::setActive($categoryId, false, null);
    t_throws(
        static fn () => SparepartService::create(
            ['code' => t_uniq('SP'), 'name' => 'Kategori Nonaktif', 'category_id' => $categoryId],
            null
        ),
        RuntimeException::class,
        'Kategori nonaktif TIDAK dapat dipakai untuk sparepart baru'
    );

    // Sparepart yang sudah memakai kategori itu tetap boleh diubah selama
    // kategorinya TIDAK diganti.
    $idLama = t_make_sparepart($categoryId);
    SparepartService::update($idLama, [
        'code'        => (string) Sparepart::find($idLama)['code'],
        'name'        => 'Tetap Kategori Nonaktif',
        'category_id' => $categoryId,
    ], null);
    t_eq(
        'Tetap Kategori Nonaktif',
        (string) Sparepart::find($idLama)['name'],
        'Ubah sparepart lama tetap boleh walau kategorinya nonaktif (tidak diganti)'
    );

    CategoryService::setActive($categoryId, true, null);
}

function test_master_sparepart_stok_awal_tercatat_ledger(): void
{
    $categoryId = t_make_category('Kategori Stok Awal Sparepart');
    $actorId = t_make_user('uji_aktor_sparepart', 'partman', t_password());
    $code = t_uniq('SP');

    $id = SparepartService::create([
        'code'               => $code,
        'name'               => 'Sparepart dengan Stok Awal ' . $code,
        'category_id'        => $categoryId,
        'unit'               => 'pcs',
        'initial_stock'      => '24',
        'initial_stock_date' => '18/10/2026',
        'initial_stock_note' => 'Hitung fisik awal',
    ], $actorId);

    $row = SparepartService::find($id);
    t_eq(24.0, (float) $row['stock'], 'Stok awal langsung menambah stok sparepart');

    $initial = StockInitialService::initialFor($id);
    t_true($initial !== null, 'Stok awal tercatat sebagai pergerakan stok');
    t_eq(StockMovement::TYPE_INITIAL, (string) $initial['movement_type'], 'Jenis pergerakan adalah STOK_AWAL');
    t_eq(24.0, (float) $initial['quantity'], 'Jumlah pergerakan sesuai');
    t_eq(0.0, (float) $initial['stock_before'], 'Stok sebelum tercatat 0');
    t_eq(24.0, (float) $initial['stock_after'], 'Stok sesudah tercatat 24');
    t_eq('2026-10-18', (string) $initial['movement_date'], 'Tanggal bisnis (dd/mm/yyyy) tersimpan benar');
    t_eq($actorId, (int) $initial['user_id'], 'Pengguna pencatat tercatat pada pergerakan');
    t_eq('Hitung fisik awal', (string) $initial['note'], 'Catatan tersimpan');
    t_true(t_latest_audit('STOCK_INITIAL_CREATE', $id) !== null, 'Pencatatan stok awal tercatat di Audit Log');
}

function test_master_sparepart_filter_dan_status_stok(): void
{
    $categoryId = t_make_category('Kategori Filter Sparepart');
    $supplierId = t_make_supplier('Supplier Filter Sparepart');
    $locationId = t_make_location('Rak Filter Sparepart');
    $marker = t_uniq('CARI');
    $code = t_uniq('SP');

    $idAman = t_make_sparepart($categoryId, [
        'code'         => $code,
        'name'         => 'Sparepart ' . $marker,
        'stock'        => 10,
        'min_stock'    => 2,
        'supplier_id'  => $supplierId,
        'location_id'  => $locationId,
    ]);
    $idKritis = t_make_sparepart($categoryId, ['stock' => 2, 'min_stock' => 5]);
    $idHabis = t_make_sparepart($categoryId, ['stock' => 0, 'min_stock' => 5, 'is_active' => 0]);

    t_eq(Sparepart::STATUS_AMAN, SparepartService::stockStatusOf(Sparepart::find($idAman)), 'Stok 10 > min 2 → aman');
    t_eq(Sparepart::STATUS_KRITIS, SparepartService::stockStatusOf(Sparepart::find($idKritis)), 'Stok 2 <= min 5 → kritis');
    t_eq(Sparepart::STATUS_HABIS, SparepartService::stockStatusOf(Sparepart::find($idHabis)), 'Stok 0 → habis');

    $hasilCari = array_column(SparepartService::all(['search' => $marker]), 'id');
    t_true(in_array($idAman, array_map('intval', $hasilCari), true), 'Filter pencarian menemukan sparepart');

    $hasilKategori = array_map('intval', array_column(SparepartService::all(['category_id' => $categoryId]), 'id'));
    t_true(in_array($idKritis, $hasilKategori, true), 'Filter kategori bekerja');
    t_true(in_array($idHabis, $hasilKategori, true), 'Filter kategori mencakup sparepart nonaktif');

    $hasilSupplier = array_map('intval', array_column(SparepartService::all(['supplier_id' => $supplierId]), 'id'));
    t_true(in_array($idAman, $hasilSupplier, true), 'Filter supplier bekerja');

    $hasilLokasi = array_map('intval', array_column(SparepartService::all(['location_id' => $locationId]), 'id'));
    t_true(in_array($idAman, $hasilLokasi, true), 'Filter lokasi bekerja');

    $hasilHabis = array_map('intval', array_column(SparepartService::all(['stock_status' => 'habis']), 'id'));
    t_true(in_array($idHabis, $hasilHabis, true), 'Filter status stok "habis" bekerja');
    t_true(!in_array($idAman, $hasilHabis, true), 'Filter status "habis" tidak memuat stok aman');

    $hasilKritis = array_map('intval', array_column(SparepartService::all(['stock_status' => 'kritis']), 'id'));
    t_true(in_array($idKritis, $hasilKritis, true), 'Filter status stok "kritis" bekerja');

    $hasilNonaktif = array_map('intval', array_column(SparepartService::all(['active' => 'nonaktif']), 'id'));
    t_true(in_array($idHabis, $hasilNonaktif, true), 'Filter status data "nonaktif" bekerja');
    t_true(!in_array($idAman, $hasilNonaktif, true), 'Filter "nonaktif" tidak memuat sparepart aktif');

    $hasilStatus = SparepartService::countByStatus();
    t_true($hasilStatus['total'] >= 3, 'Ringkasan status menghitung total sparepart');
    t_true($hasilStatus['habis'] >= 1 && $hasilStatus['kritis'] >= 1, 'Ringkasan status menghitung habis & kritis');
}

function test_master_sparepart_aktif_nonaktif_dan_import_lookup(): void
{
    $categoryId = t_make_category('Kategori Status Sparepart');
    $id = t_make_sparepart($categoryId);
    $code = (string) Sparepart::find($id)['code'];
    $name = (string) Sparepart::find($id)['name'];

    SparepartService::setActive($id, false, null);
    t_eq(0, (int) Sparepart::find($id)['is_active'], 'Sparepart berhasil dinonaktifkan');
    t_true(t_latest_audit('SPAREPART_DEACTIVATE', $id) !== null, 'Penonaktifan sparepart tercatat di Audit Log');

    t_throws(
        static fn () => SparepartService::setActive($id, false, null),
        RuntimeException::class,
        'Menonaktifkan sparepart yang sudah nonaktif ditolak'
    );

    SparepartService::setActive($id, true, null);
    t_eq(1, (int) Sparepart::find($id)['is_active'], 'Sparepart berhasil diaktifkan kembali');
    t_true(t_latest_audit('SPAREPART_ACTIVATE', $id) !== null, 'Pengaktifan sparepart tercatat di Audit Log');

    t_eq($id, (int) SparepartService::findForImport($code)['id'], 'Pencarian untuk impor menemukan sparepart lewat kode');
    t_eq($id, (int) SparepartService::findForImport($name)['id'], 'Pencarian untuk impor menemukan sparepart lewat nama');
    t_eq(null, SparepartService::findForImport('TIDAK-ADA-' . t_uniq('X')), 'Kode tak dikenal tidak ditemukan saat impor');

    t_true(!method_exists(SparepartService::class, 'delete'), 'SparepartService tidak memiliki method delete');
}
