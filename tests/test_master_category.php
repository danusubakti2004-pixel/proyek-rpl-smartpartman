<?php
declare(strict_types=1);

use App\Services\CategoryService;

/**
 * Pengujian Fase 3 — Kategori sparepart (FR-12).
 *
 * Yang diuji:
 *  - CRUD + pencatatan Audit Log;
 *  - kode unik & dinormalkan (huruf besar);
 *  - batas panjang & input kosong ditolak (DB uji TIDAK memakai
 *    STRICT_TRANS_TABLES, jadi penjagaan harus eksplisit di PHP);
 *  - aktif/nonaktif: kategori nonaktif tidak dapat dipakai sparepart baru;
 *  - kategori TIDAK pernah dihapus permanen (tidak ada method delete).
 */

function test_master_category_crud_dan_audit(): void
{
    $code = t_uniq('KAT');
    $name = 'Kategori Uji ' . $code;

    $id = CategoryService::create(['code' => $code, 'name' => $name, 'description' => 'Dibuat pengujian'], null);
    t_true($id > 0, 'Kategori baru berhasil dibuat');

    $row = CategoryService::find($id);
    t_true($row !== null, 'Kategori dapat dibaca kembali');
    t_eq($code, (string) $row['code'], 'Kode kategori tersimpan sesuai');
    t_eq($name, (string) $row['name'], 'Nama kategori tersimpan sesuai');
    t_eq(1, (int) $row['is_active'], 'Kategori baru otomatis aktif');

    t_true(t_latest_audit('CATEGORY_CREATE', $id) !== null, 'Pembuatan kategori tercatat di Audit Log');

    CategoryService::update($id, ['code' => $code, 'name' => 'Kategori Diubah ' . $code], null);
    t_eq('Kategori Diubah ' . $code, (string) CategoryService::find($id)['name'], 'Nama kategori berhasil diubah');
    t_true(t_latest_audit('CATEGORY_UPDATE', $id) !== null, 'Perubahan kategori tercatat di Audit Log');
}

function test_master_category_kode_dinormalkan_dan_unik(): void
{
    $suffix = strtoupper(bin2hex(random_bytes(3)));

    $lower = 'kat-' . strtolower($suffix);
    $id = CategoryService::create(['code' => $lower, 'name' => 'Huruf Kecil ' . $suffix], null);
    $row = CategoryService::find($id);
    t_eq('KAT-' . $suffix, (string) $row['code'], 'Kode kategori dinormalkan menjadi huruf besar');

    t_throws(
        static fn () => CategoryService::create(['code' => 'KAT-' . $suffix, 'name' => 'Duplikat Kode ' . $suffix], null),
        RuntimeException::class,
        'Kode kategori duplikat ditolak (walau beda huruf besar/kecil)'
    );

    t_throws(
        static fn () => CategoryService::create(['code' => t_uniq('KAT'), 'name' => (string) $row['name']], null),
        RuntimeException::class,
        'Nama kategori duplikat ditolak'
    );

    // Kode duplikat milik kategori LAIN tetap boleh dipakai saat mengubah diri sendiri.
    CategoryService::update($id, ['code' => (string) $row['code'], 'name' => 'Nama Baru ' . $suffix], null);
    t_eq('Nama Baru ' . $suffix, (string) CategoryService::find($id)['name'], 'Ubah kategori dengan kodenya sendiri berhasil');
}

function test_master_category_batas_panjang_dan_kosong(): void
{
    t_throws(
        static fn () => CategoryService::create(['code' => '', 'name' => 'Tanpa Kode'], null),
        RuntimeException::class,
        'Kode kategori kosong ditolak'
    );
    t_throws(
        static fn () => CategoryService::create(['code' => t_uniq('KAT'), 'name' => '   '], null),
        RuntimeException::class,
        'Nama kategori kosong ditolak'
    );
    t_throws(
        static fn () => CategoryService::create(
            ['code' => str_repeat('A', CategoryService::CODE_MAX + 1), 'name' => 'Kode Terlalu Panjang'],
            null
        ),
        RuntimeException::class,
        'Kode kategori melebihi batas ditolak (tanpa pemotongan diam-diam)'
    );
    t_throws(
        static fn () => CategoryService::create(
            ['code' => t_uniq('KAT'), 'name' => str_repeat('N', CategoryService::NAME_MAX + 1)],
            null
        ),
        RuntimeException::class,
        'Nama kategori melebihi batas ditolak'
    );

    $code = t_uniq('KAT');
    $id = CategoryService::create(
        ['code' => '  ' . strtolower($code) . '  ', 'name' => '  Spasi Berlebih  ' . $code . '  '],
        null
    );
    t_eq($code, (string) CategoryService::find($id)['code'], 'Spasi berlebih pada kode dipangkas');
}

function test_master_category_aktif_nonaktif(): void
{
    $id = t_make_category('Kategori Uji Nonaktif');

    CategoryService::setActive($id, false, null);
    t_eq(0, (int) CategoryService::find($id)['is_active'], 'Kategori berhasil dinonaktifkan');
    t_true(t_latest_audit('CATEGORY_DEACTIVATE', $id) !== null, 'Penonaktifan kategori tercatat di Audit Log');

    t_throws(
        static fn () => CategoryService::resolveUsable($id),
        RuntimeException::class,
        'Kategori nonaktif ditolak saat dipakai untuk sparepart baru'
    );

    $aktifIds = array_map(static fn (array $row): int => (int) $row['id'], CategoryService::options(true));
    t_true(!in_array($id, $aktifIds, true), 'Kategori nonaktif tidak muncul pada daftar pilihan aktif');

    t_throws(
        static fn () => CategoryService::setActive($id, false, null),
        RuntimeException::class,
        'Menonaktifkan kategori yang sudah nonaktif ditolak (tanpa perubahan zonk)'
    );

    CategoryService::setActive($id, true, null);
    t_eq(1, (int) CategoryService::find($id)['is_active'], 'Kategori berhasil diaktifkan kembali');
    t_true(t_latest_audit('CATEGORY_ACTIVATE', $id) !== null, 'Pengaktifan kategori tercatat di Audit Log');
}

function test_master_category_tidak_pernah_dihapus(): void
{
    t_true(
        !method_exists(CategoryService::class, 'delete'),
        'CategoryService tidak memiliki method delete (kategori tidak dihapus permanen)'
    );
    t_true(
        !method_exists(App\Models\Category::class, 'delete'),
        'Model Category tidak memiliki method delete'
    );

    $id = t_make_category('Kategori Uji Terpakai');
    $sparepartId = t_make_sparepart($id);
    t_eq(1, App\Models\Category::sparepartCount($id), 'Kategori terpakai menghitung jumlah sparepart');

    CategoryService::setActive($id, false, null);
    $row = CategoryService::find($id);
    t_true($row !== null, 'Kategori yang dinonaktifkan tetap ada di database');
    t_eq(
        $id,
        (int) App\Models\Sparepart::find($sparepartId)['category_id'],
        'Sparepart lama tetap menunjuk kategori aslinya'
    );
    CategoryService::setActive($id, true, null);
}
