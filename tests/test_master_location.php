<?php
declare(strict_types=1);

use App\Services\StorageLocationService;

/**
 * Pengujian Fase 3 — Lokasi penyimpanan (FR-09).
 * Satu gudang, banyak rak/tingkat; lokasi dipakai master sparepart.
 */

function test_master_location_crud_dan_audit(): void
{
    $code = t_uniq('RAK');
    $id = StorageLocationService::create([
        'code'        => $code,
        'name'        => 'Gudang Utama - Rak Uji ' . $code,
        'description' => 'Tingkat 2',
    ], null);
    t_true($id > 0, 'Lokasi baru berhasil dibuat');

    $row = StorageLocationService::find($id);
    t_true($row !== null, 'Lokasi dapat dibaca kembali');
    t_eq($code, (string) $row['code'], 'Kode lokasi tersimpan sesuai');
    t_eq(1, (int) $row['is_active'], 'Lokasi baru otomatis aktif');
    t_true(t_latest_audit('LOCATION_CREATE', $id) !== null, 'Pembuatan lokasi tercatat di Audit Log');

    StorageLocationService::update($id, ['code' => $code, 'name' => 'Gudang Utama - Rak Diubah ' . $code], null);
    t_eq(
        'Gudang Utama - Rak Diubah ' . $code,
        (string) StorageLocationService::find($id)['name'],
        'Nama lokasi berhasil diubah'
    );
    t_true(t_latest_audit('LOCATION_UPDATE', $id) !== null, 'Perubahan lokasi tercatat di Audit Log');
}

function test_master_location_validasi_dan_keunikan(): void
{
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $id = StorageLocationService::create(['code' => 'rak-' . strtolower($suffix), 'name' => 'Rak ' . $suffix], null);
    t_eq('RAK-' . $suffix, (string) StorageLocationService::find($id)['code'], 'Kode lokasi dinormalkan menjadi huruf besar');

    t_throws(
        static fn () => StorageLocationService::create(['code' => 'RAK-' . $suffix, 'name' => 'Duplikat ' . $suffix], null),
        RuntimeException::class,
        'Kode lokasi duplikat ditolak'
    );
    t_throws(
        static fn () => StorageLocationService::create(['code' => '', 'name' => 'Tanpa Kode'], null),
        RuntimeException::class,
        'Kode lokasi kosong ditolak'
    );
    t_throws(
        static fn () => StorageLocationService::create(['code' => t_uniq('RAK'), 'name' => '  '], null),
        RuntimeException::class,
        'Nama lokasi kosong ditolak'
    );
    t_throws(
        static fn () => StorageLocationService::create(
            ['code' => str_repeat('R', StorageLocationService::CODE_MAX + 1), 'name' => 'Kode Panjang'],
            null
        ),
        RuntimeException::class,
        'Kode lokasi melebihi batas ditolak'
    );
}

function test_master_location_aktif_nonaktif(): void
{
    $id = t_make_location('Rak Uji Nonaktif');

    StorageLocationService::setActive($id, false, null);
    t_eq(0, (int) StorageLocationService::find($id)['is_active'], 'Lokasi berhasil dinonaktifkan');
    t_true(t_latest_audit('LOCATION_DEACTIVATE', $id) !== null, 'Penonaktifan lokasi tercatat di Audit Log');

    $aktifIds = array_map(static fn (array $row): int => (int) $row['id'], StorageLocationService::options(true));
    t_true(!in_array($id, $aktifIds, true), 'Lokasi nonaktif tidak muncul pada daftar pilihan aktif');

    t_throws(
        static fn () => StorageLocationService::setActive($id, false, null),
        RuntimeException::class,
        'Menonaktifkan lokasi yang sudah nonaktif ditolak'
    );

    $categoryId = t_make_category('Kategori untuk Lokasi');
    $sparepartId = t_make_sparepart($categoryId, ['location_id' => $id]);
    t_eq(1, App\Models\StorageLocation::sparepartCount($id), 'Lokasi menghitung sparepart yang tersimpan di sana');
    t_eq(
        $id,
        (int) App\Models\Sparepart::find($sparepartId)['location_id'],
        'Sparepart tetap menunjuk lokasi walau lokasinya nonaktif'
    );

    StorageLocationService::setActive($id, true, null);
    t_true(t_latest_audit('LOCATION_ACTIVATE', $id) !== null, 'Pengaktifan lokasi tercatat di Audit Log');
}
