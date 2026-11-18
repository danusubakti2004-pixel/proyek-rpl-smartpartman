<?php
declare(strict_types=1);

use App\Core\View;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\CategoryService;
use App\Services\MasterImportService;
use App\Services\SparepartService;
use App\Services\StockInitialService;
use App\Services\StorageLocationService;
use App\Services\SupplierService;

/**
 * Pengujian Fase 3 — render tampilan (smoke test).
 *
 * Memastikan seluruh view modul master data dapat dirender tanpa galat PHP,
 * memuat penanda penting, dan tidak pernah membocorkan tag PHP mentah.
 */

/** @param array<string,mixed> $data */
function t_render_view(string $template, array $data, string $label): string
{
    try {
        $html = View::render($template, $data, null);
    } catch (Throwable $e) {
        t_true(false, "View {$label} gagal dirender: " . $e->getMessage());
        return '';
    }
    t_true($html !== '', "View {$label} berhasil dirender");
    t_true(!str_contains($html, '<?php'), "View {$label} tidak membocorkan tag PHP");
    return $html;
}

function test_master_view_kategori_dan_supplier_dan_lokasi(): void
{
    $categoryId = t_make_category('Kategori Tampilan');
    $supplierId = t_make_supplier('Supplier Tampilan');
    $locationId = t_make_location('Rak Tampilan');

    $categories = CategoryService::all();
    $html = t_render_view('master/categories/index', [
        'canManage' => true, 'categories' => $categories, 'filters' => ['search' => '', 'status' => ''],
    ], 'master/categories/index');
    t_contains('Kategori Sparepart', $html, 'Daftar kategori menampilkan judul');
    t_contains('Tambah Kategori', $html, 'Daftar kategori menampilkan tombol tambah untuk Partman');
    t_contains((string) CategoryService::find($categoryId)['code'], $html, 'Daftar kategori menampilkan kode kategori');

    $htmlViewer = t_render_view('master/categories/index', [
        'canManage' => false, 'categories' => $categories, 'filters' => ['search' => '', 'status' => ''],
    ], 'master/categories/index (hanya lihat)');
    t_true(!str_contains($htmlViewer, 'Tambah Kategori'), 'Tombol tambah disembunyikan dari role yang hanya boleh melihat');

    $html = t_render_view('master/categories/form', [
        'mode' => 'edit', 'row' => CategoryService::find($categoryId), 'old' => [],
    ], 'master/categories/form');
    t_contains('Ubah Kategori', $html, 'Form kategori (ubah) menampilkan judul yang tepat');

    $html = t_render_view('master/suppliers/index', [
        'canManage' => true, 'suppliers' => SupplierService::all(), 'filters' => ['search' => '', 'status' => ''],
    ], 'master/suppliers/index');
    t_contains('Supplier', $html, 'Daftar supplier menampilkan judul');
    t_contains((string) SupplierService::find($supplierId)['code'], $html, 'Daftar supplier menampilkan kode supplier');

    $html = t_render_view('master/suppliers/form', [
        'mode' => 'create', 'row' => null, 'old' => [],
    ], 'master/suppliers/form');
    t_contains('Contact Person', $html, 'Form supplier memuat kolom contact person');

    $html = t_render_view('master/locations/index', [
        'canManage' => true, 'locations' => StorageLocationService::all(), 'filters' => ['search' => '', 'status' => ''],
    ], 'master/locations/index');
    t_contains('Lokasi Penyimpanan', $html, 'Daftar lokasi menampilkan judul');
    t_contains((string) StorageLocationService::find($locationId)['code'], $html, 'Daftar lokasi menampilkan kode lokasi');

    $html = t_render_view('master/locations/form', [
        'mode' => 'create', 'row' => null, 'old' => [],
    ], 'master/locations/form');
    t_contains('RAK-A1-T2', $html, 'Form lokasi memberi contoh penamaan rak/tingkat');
}

function test_master_view_sparepart(): void
{
    $categoryId = t_make_category('Kategori Tampilan Sparepart');
    $supplierId = t_make_supplier('Supplier Tampilan Sparepart');
    $locationId = t_make_location('Rak Tampilan Sparepart');
    $sparepartId = t_make_sparepart($categoryId, [
        'stock' => 3, 'min_stock' => 5, 'supplier_id' => $supplierId, 'location_id' => $locationId,
    ]);
    $sparepart = Sparepart::find($sparepartId);
    t_true($sparepart !== null, 'Data sparepart tampilan tersedia');

    $html = t_render_view('master/spareparts/index', [
        'canManage'    => true,
        'spareparts'   => SparepartService::all(['category_id' => $categoryId]),
        'filters'      => ['search' => '', 'category_id' => (string) $categoryId, 'supplier_id' => '', 'location_id' => '', 'stock_status' => '', 'active' => ''],
        'stats'        => SparepartService::countByStatus(),
        'categories'   => CategoryService::options(false),
        'suppliers'    => SupplierService::options(false),
        'locations'    => StorageLocationService::options(false),
    ], 'master/spareparts/index');
    t_contains('Master Sparepart', $html, 'Daftar sparepart menampilkan judul');
    t_contains((string) $sparepart['code'], $html, 'Daftar sparepart menampilkan kode sparepart');
    t_contains('Kritis', $html, 'Daftar sparepart menampilkan status stok kritis');

    $html = t_render_view('master/spareparts/form', [
        'mode' => 'create', 'row' => null, 'old' => [],
        'categories' => CategoryService::options(true),
        'suppliers'  => SupplierService::options(true),
        'locations'  => StorageLocationService::options(true),
    ], 'master/spareparts/form (tambah)');
    t_contains('Stok Awal', $html, 'Form tambah sparepart menawarkan input stok awal');
    t_contains('dicatat dua kali', $html, 'Form menjelaskan stok awal hanya sekali');

    $html = t_render_view('master/spareparts/form', [
        'mode' => 'edit', 'row' => $sparepart, 'old' => [],
        'categories' => CategoryService::options(true),
        'suppliers'  => SupplierService::options(true),
        'locations'  => StorageLocationService::options(true),
    ], 'master/spareparts/form (ubah)');
    t_true(!str_contains($html, 'name="initial_stock"'), 'Form ubah TIDAK memuat input stok (stok hanya lewat ledger)');

    $html = t_render_view('master/spareparts/show', [
        'canManage' => true,
        'row'       => SparepartService::find($sparepartId),
        'status'    => SparepartService::stockStatusOf($sparepart),
        'movements' => StockMovement::latestFor($sparepartId, 20),
        'initial'   => StockInitialService::initialFor($sparepartId),
        'purchases' => [],
    ], 'master/spareparts/show');
    t_contains('Kartu Stok', $html, 'Detail sparepart menampilkan kartu stok');
    t_contains('append-only', $html, 'Detail sparepart menjelaskan ledger append-only');
    t_contains('Input Stok Awal', $html, 'Detail sparepart menawarkan input stok awal untuk Partman');
    t_contains('Harga Beli Terakhir', $html, 'Detail sparepart membedakan harga beli terakhir');
    t_contains('Harga Beli Rata-rata', $html, 'Detail sparepart menampilkan harga beli rata-rata');
    t_contains('Riwayat Harga Beli', $html, 'Detail sparepart menyediakan riwayat harga beli');
    t_contains('Belum ada pembelian tercatat', $html, 'Riwayat harga kosong dijelaskan');

    $html = t_render_view('master/spareparts/show', [
        'canManage' => true,
        'row'       => SparepartService::find($sparepartId),
        'status'    => SparepartService::stockStatusOf($sparepart),
        'movements' => StockMovement::latestFor($sparepartId, 20),
        'initial'   => StockInitialService::initialFor($sparepartId),
        'purchases' => [[
            'receipt_number' => 'GR-202610-0099',
            'receipt_date'   => '2026-10-18',
            'invoice_number' => 'INV-UJI-0099',
            'quantity'       => 5,
            'unit_price'     => 20000,
            'supplier_name'  => 'Supplier Uji',
        ]],
    ], 'master/spareparts/show');
    t_contains('GR-202610-0099', $html, 'Riwayat harga menampilkan nomor dokumen');
    t_contains('INV-UJI-0099', $html, 'Riwayat harga menampilkan nomor invoice');
    t_contains('Supplier Uji', $html, 'Riwayat harga menampilkan supplier');
    t_contains('20.000', $html, 'Riwayat harga menampilkan harga per unit');

    $html = t_render_view('master/spareparts/stock_initial', [
        'row' => $sparepart, 'initial' => null, 'old' => [],
    ], 'master/spareparts/stock_initial (belum ada)');
    t_contains('Catat Stok Awal', $html, 'Form stok awal menyediakan tombol simpan');
    t_contains('STOK_AWAL', $html, 'Form stok awal menjelaskan jenis pergerakan');

    $initial = StockInitialService::initialFor($sparepartId);
    if ($initial === null) {
        StockInitialService::apply($sparepartId, 3, '18/10/2026', 'Catatan uji', null, 'MANUAL');
        $initial = StockInitialService::initialFor($sparepartId);
    }
    $html = t_render_view('master/spareparts/stock_initial', [
        'row' => Sparepart::find($sparepartId), 'initial' => $initial, 'old' => [],
    ], 'master/spareparts/stock_initial (sudah ada)');
    t_contains('sudah pernah dicatat', $html, 'Form stok awal menolak pencatatan kedua');
    t_true(!str_contains($html, 'name="quantity"'), 'Form stok awal tidak lagi menyediakan input setelah tercatat');
}

function test_master_view_import(): void
{
    $html = t_render_view('master/import/index', [
        'types'    => [
            MasterImportService::TYPE_SPAREPART => [
                'title'   => MasterImportService::titleOf(MasterImportService::TYPE_SPAREPART),
                'headers' => MasterImportService::headers(MasterImportService::TYPE_SPAREPART),
                'sample'  => MasterImportService::sampleRow(MasterImportService::TYPE_SPAREPART),
            ],
            MasterImportService::TYPE_STOCK_INITIAL => [
                'title'   => MasterImportService::titleOf(MasterImportService::TYPE_STOCK_INITIAL),
                'headers' => MasterImportService::headers(MasterImportService::TYPE_STOCK_INITIAL),
                'sample'  => MasterImportService::sampleRow(MasterImportService::TYPE_STOCK_INITIAL),
            ],
        ],
        'maxRows'  => MasterImportService::MAX_ROWS,
        'maxBytes' => '2 MB',
        'zipReady' => true,
    ], 'master/import/index');
    t_contains('Impor Data', $html, 'Halaman impor menampilkan judul');
    t_contains('Unduh Template CSV', $html, 'Halaman impor menyediakan template CSV');
    t_contains('Unduh Template XLSX', $html, 'Halaman impor menyediakan template XLSX');
    t_contains('ditolak', $html, 'Halaman impor menjelaskan kode duplikat ditolak');
    t_contains('name="berkas"', $html, 'Halaman impor menyediakan input berkas');

    $categoryId = t_make_category('Filter Tampilan Impor');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $kode = t_uniq('IMP');

    $preview = MasterImportService::preview(
        MasterImportService::TYPE_SPAREPART,
        MasterImportService::headers(MasterImportService::TYPE_SPAREPART),
        [
            [$kode, 'Sparepart Pratinjau', $categoryCode, '', '', 'pcs', '1000', '2000', '4', '1', '', ''],
            [t_uniq('IMP'), 'Baris Salah', 'KATEGORI-TIDAK-ADA', '', '', 'pcs', 'x', '2000', '1', '1', '', ''],
        ],
        'tampilan.csv',
        'csv'
    );

    $html = t_render_view('master/import/preview', [
        'preview' => $preview, 'token' => 'token-uji',
    ], 'master/import/preview');
    t_contains('Pratinjau Impor', $html, 'Halaman pratinjau menampilkan judul');
    t_contains('tidak ditemukan', $html, 'Halaman pratinjau menampilkan alasan kegagalan baris');
    t_contains('dinonaktifkan', $html, 'Halaman pratinjau menjelaskan simpan dinonaktifkan saat ada galat');
    t_true(!str_contains($html, 'Simpan Semua'), 'Tombol simpan tidak tampil saat ada baris bermasalah');

    $previewOk = MasterImportService::preview(
        MasterImportService::TYPE_SPAREPART,
        MasterImportService::headers(MasterImportService::TYPE_SPAREPART),
        [[$kode, 'Sparepart Pratinjau', $categoryCode, '', '', 'pcs', '1000', '2000', '4', '1', '', '']],
        'tampilan-ok.csv',
        'csv'
    );
    $htmlOk = t_render_view('master/import/preview', [
        'preview' => $previewOk, 'token' => 'token-uji',
    ], 'master/import/preview (semua valid)');
    t_contains('Simpan Semua', $htmlOk, 'Tombol simpan tampil saat seluruh baris valid');
    t_contains('satu transaksi', $htmlOk, 'Pratinjau menjelaskan penyimpanan satu transaksi');
}

function test_master_view_layout_menu(): void
{
    $html = t_render_view('dashboard/index', [
        'title' => 'Dashboard',
        'stats' => ['sparepart_total' => 0, 'sparepart_kritis' => 0, 'sparepart_habis' => 0],
    ], 'dashboard/index (tanpa layout)');
    t_true($html !== '', 'Dashboard dapat dirender');

    // Layout utama: menu Fase 3–6 muncul dan versi tercatat.
    $adminUsername = 'f9view_' . strtolower(t_uniq('U'));
    $adminId = t_make_user($adminUsername, 'admin', t_password());
    $_SESSION['auth_user'] = ['id' => $adminId, 'username' => $adminUsername, 'role' => 'admin'];
    $layout = View::render('dashboard/index', ['title' => 'Dashboard'], 'layouts/app');
    t_logout();
    t_contains('Sparepart', $layout, 'Menu sidebar memuat tautan Sparepart');
    t_contains('Kategori', $layout, 'Menu sidebar memuat tautan Kategori');
    t_contains('Barang Masuk', $layout, 'Menu sidebar memuat tautan Barang Masuk');
    t_contains('Barang Keluar', $layout, 'Menu sidebar memuat tautan Barang Keluar');
    t_contains('Stock Opname', $layout, 'Menu sidebar memuat tautan Stock Opname');
    t_contains('Riwayat Stok', $layout, 'Menu sidebar memuat tautan Riwayat Stok');
    t_contains('Laporan', $layout, 'Menu sidebar memuat tautan Laporan (Fase 5)');
    t_contains('Reorder', $layout, 'Menu sidebar memuat tautan Reorder (Fase 6)');
    t_contains('Pembelian / PO', $layout, 'Menu sidebar memuat tautan Pembelian/PO (Fase 6)');
    t_contains('Prediksi Stok', $layout, 'Menu sidebar memuat tautan Prediksi Stok (Fase 7)');
    t_contains('Chatbot', $layout, 'Menu sidebar memuat tautan Chatbot (Fase 8)');
    t_contains('Backup &amp; Restore', $layout, 'Menu sidebar memuat tautan Backup (Fase 9)');
    t_contains('Fase 9', $layout, 'Sidebar menandai versi Fase 9');
    t_contains('href="' . url('sparepart') . '"', $layout, 'Tautan sidebar memakai basis URL aplikasi');
}
