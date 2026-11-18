<?php
declare(strict_types=1);

use App\Services\SupplierService;

/**
 * Pengujian Fase 3 — Supplier (FR-11).
 *
 * Yang diuji:
 *  - CRUD + audit;
 *  - kode unik & dinormalkan; email tidak valid ditolak; batas panjang;
 *  - aktif/nonaktif dan aturan "supplier nonaktif tidak boleh dipakai
 *    untuk transaksi baru" (ensureUsableForTransaction, dipakai Fase 4);
 *  - supplier tidak pernah dihapus permanen.
 */

function test_master_supplier_crud_dan_audit(): void
{
    $code = t_uniq('SUP');
    $id = SupplierService::create([
        'code'           => $code,
        'name'           => 'PT Supplier Uji ' . $code,
        'address'        => 'Jl. Uji No. 1',
        'phone'          => '021-1234567',
        'email'          => 'kontak@contoh.test',
        'contact_person' => 'Budi Uji',
        'lead_time_days' => '3',
    ], null);
    t_true($id > 0, 'Supplier baru berhasil dibuat');

    $row = SupplierService::find($id);
    t_true($row !== null, 'Supplier dapat dibaca kembali');
    t_eq($code, (string) $row['code'], 'Kode supplier tersimpan sesuai');
    t_eq('Budi Uji', (string) $row['contact_person'], 'Contact person tersimpan');
    t_eq(3, (int) $row['lead_time_days'], 'Lead time tersimpan sebagai angka');
    t_eq(1, (int) $row['is_active'], 'Supplier baru otomatis aktif');
    t_true(t_latest_audit('SUPPLIER_CREATE', $id) !== null, 'Pembuatan supplier tercatat di Audit Log');

    SupplierService::update($id, [
        'code'           => $code,
        'name'           => 'PT Supplier Diubah ' . $code,
        'address'        => 'Jl. Uji No. 2',
        'phone'          => '021-7654321',
        'email'          => 'baru@contoh.test',
        'contact_person' => 'Siti Uji',
        'lead_time_days' => '5',
    ], null);
    $updated = SupplierService::find($id);
    t_eq('PT Supplier Diubah ' . $code, (string) $updated['name'], 'Nama supplier berhasil diubah');
    t_eq(5, (int) $updated['lead_time_days'], 'Lead time berhasil diubah');
    t_true(t_latest_audit('SUPPLIER_UPDATE', $id) !== null, 'Perubahan supplier tercatat di Audit Log');
}

function test_master_supplier_validasi(): void
{
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $id = SupplierService::create(['code' => 'sup-' . strtolower($suffix), 'name' => 'PT Huruf Kecil ' . $suffix], null);
    t_eq('SUP-' . $suffix, (string) SupplierService::find($id)['code'], 'Kode supplier dinormalkan menjadi huruf besar');

    t_throws(
        static fn () => SupplierService::create(['code' => 'SUP-' . $suffix, 'name' => 'Duplikat ' . $suffix], null),
        RuntimeException::class,
        'Kode supplier duplikat ditolak'
    );
    t_throws(
        static fn () => SupplierService::create(['code' => t_uniq('SUP'), 'name' => ''], null),
        RuntimeException::class,
        'Nama supplier kosong ditolak'
    );
    t_throws(
        static fn () => SupplierService::create(
            ['code' => str_repeat('S', SupplierService::CODE_MAX + 1), 'name' => 'Kode Panjang'],
            null
        ),
        RuntimeException::class,
        'Kode supplier melebihi batas ditolak'
    );
    t_throws(
        static fn () => SupplierService::create(
            ['code' => t_uniq('SUP'), 'name' => str_repeat('N', SupplierService::NAME_MAX + 1)],
            null
        ),
        RuntimeException::class,
        'Nama supplier melebihi batas ditolak'
    );
    t_throws(
        static fn () => SupplierService::create(
            ['code' => t_uniq('SUP'), 'name' => 'PT Email Salah', 'email' => 'bukan-email'],
            null
        ),
        RuntimeException::class,
        'Email supplier tidak valid ditolak'
    );
    t_throws(
        static fn () => SupplierService::create(
            ['code' => t_uniq('SUP'), 'name' => 'PT Lead Time Aneh', 'lead_time_days' => '9999'],
            null
        ),
        RuntimeException::class,
        'Lead time tidak masuk akal ditolak'
    );

    $kosong = SupplierService::create(
        ['code' => t_uniq('SUP'), 'name' => 'PT Tanpa Kontak', 'address' => '   ', 'phone' => '', 'email' => ''],
        null
    );
    $row = SupplierService::find($kosong);
    t_true($row['address'] === null && $row['phone'] === null && $row['email'] === null,
        'Kolom opsional yang kosong disimpan sebagai NULL (bukan string kosong)');
}

function test_master_supplier_aktif_nonaktif_dan_transaksi(): void
{
    $id = t_make_supplier('Supplier Uji Nonaktif');

    SupplierService::ensureUsableForTransaction($id);
    t_true(true, 'Supplier aktif boleh dipakai untuk transaksi');

    SupplierService::setActive($id, false, null);
    t_eq(0, (int) SupplierService::find($id)['is_active'], 'Supplier berhasil dinonaktifkan');
    t_true(t_latest_audit('SUPPLIER_DEACTIVATE', $id) !== null, 'Penonaktifan supplier tercatat di Audit Log');

    t_throws(
        static fn () => SupplierService::ensureUsableForTransaction($id),
        RuntimeException::class,
        'Supplier nonaktif DITOLAK untuk transaksi baru'
    );

    $aktifIds = array_map(static fn (array $row): int => (int) $row['id'], SupplierService::options(true));
    t_true(!in_array($id, $aktifIds, true), 'Supplier nonaktif tidak muncul pada daftar pilihan aktif');

    SupplierService::setActive($id, true, null);
    SupplierService::ensureUsableForTransaction($id);
    t_true(true, 'Setelah diaktifkan kembali supplier boleh dipakai untuk transaksi');
}

function test_master_supplier_tidak_dihapus_dan_terhitung(): void
{
    t_true(
        !method_exists(SupplierService::class, 'delete'),
        'SupplierService tidak memiliki method delete (supplier tidak dihapus permanen)'
    );

    $categoryId = t_make_category('Kategori untuk Supplier');
    $supplierId = t_make_supplier('Supplier Uji Terpakai');
    $locationId = t_make_location('Rak untuk Supplier');
    $sparepartId = t_make_sparepart($categoryId, ['supplier_id' => $supplierId, 'location_id' => $locationId]);

    t_eq(1, App\Models\Supplier::sparepartCount($supplierId), 'Supplier terpakai menghitung jumlah sparepart');

    SupplierService::setActive($supplierId, false, null);
    t_true(SupplierService::find($supplierId) !== null, 'Supplier yang dinonaktifkan tetap ada di database');
    t_eq(
        $supplierId,
        (int) App\Models\Sparepart::find($sparepartId)['supplier_id'],
        'Sparepart lama tetap menunjuk supplier aslinya'
    );
    SupplierService::setActive($supplierId, true, null);
}
