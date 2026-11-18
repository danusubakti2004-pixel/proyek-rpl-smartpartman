<?php
declare(strict_types=1);

use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\CategoryService;
use App\Services\Import\CsvReader;
use App\Services\Import\Spreadsheet;
use App\Services\Import\XlsxWriter;
use App\Services\MasterImportService;

/**
 * Pengujian Fase 3 — Impor master data & stok awal (FR-04, FR-05).
 *
 * Cakupan: berkas valid, berkas tidak valid, kode duplikat (di dalam berkas
 * maupun di database), berkas rusak, berkas terlalu besar, berkas terlalu
 * banyak baris, template CSV/XLSX, dan sifat ATOMIK (satu baris salah =
 * tidak ada data tersimpan).
 */

/** Susun isi berkas CSV (pemisah titik koma seperti ekspor Excel Indonesia). */
function t_csv(array $rows, string $delimiter = ';'): string
{
    $lines = [];
    foreach ($rows as $row) {
        $cells = array_map(static function ($cell): string {
            $text = (string) $cell;
            return (str_contains($text, ',') || str_contains($text, ';') || str_contains($text, '"'))
                ? '"' . str_replace('"', '""', $text) . '"'
                : $text;
        }, $row);
        $lines[] = implode($delimiter, $cells);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/** Header kanonik impor sparepart. */
function t_import_headers(): array
{
    return ['Kode', 'Nama', 'Kategori', 'Brand', 'Model', 'Satuan', 'Harga Beli', 'Harga Jual', 'Stok', 'Stok Minimum', 'Lokasi', 'Supplier'];
}

/** Pastikan pemanggilan melempar exception dengan pesan yang memuat teks tertentu. */
function t_message_contains(callable $fn, string $needle, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        t_contains($needle, $e->getMessage(), $message);
        return;
    }
    t_true(false, $message . ' (tidak ada exception sama sekali)');
}

function test_master_import_csv_valid_sampai_komit(): void
{
    $categoryId = t_make_category('Filter');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $supplierId = t_make_supplier('PT Impor');
    $supplierCode = (string) App\Models\Supplier::find($supplierId)['code'];
    $locationId = t_make_location('Rak Impor');
    $locationCode = (string) App\Models\StorageLocation::find($locationId)['code'];

    $kode1 = t_uniq('IMP');
    $kode2 = t_uniq('IMP');

    $csv = t_csv([
        t_import_headers(),
        [$kode1, 'Filter Oli Impor', $categoryCode, 'Suzuki', 'Satria F150', 'pcs', '45.000,50', '60.000', '24', '5', $locationCode, $supplierCode],
        [$kode2, 'Busi Impor', $categoryCode, 'NGK', 'Beat', 'pcs', '15000', '22000', '0', '3', '', ''],
    ]);

    $path = t_temp_file('csv', $csv);
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path, 'csv', 'uji-valid.csv');
    @unlink($path);

    t_eq(2, (int) $preview['summary']['total'], 'Dua baris data terbaca');
    t_eq(2, (int) $preview['summary']['ok'], 'Kedua baris valid');
    t_eq(0, (int) $preview['summary']['error'], 'Tidak ada baris bermasalah');
    t_eq([], $preview['missing_required'], 'Tidak ada kolom wajib yang hilang');

    $result = MasterImportService::commit(MasterImportService::TYPE_SPAREPART, $preview['rows'], null, 'uji-valid.csv');
    t_eq(2, (int) $result['created'], 'Dua sparepart berhasil diimpor');
    t_eq(1, (int) $result['movements'], 'Hanya baris dengan stok > 0 yang membuat pergerakan stok');

    $sp1 = Sparepart::findByCode($kode1);
    t_true($sp1 !== null, 'Sparepart pertama tersimpan');
    t_eq(45000.5, (float) $sp1['purchase_price'], 'Angka "45.000,50" terbaca sebagai 45000,50');
    t_eq(24.0, (float) $sp1['stock'], 'Stok awal dari berkas masuk ke stok sparepart');
    t_eq((float) $locationId, (float) $sp1['location_id'], 'Lokasi dari berkas terhubung');
    t_eq((float) $supplierId, (float) $sp1['supplier_id'], 'Supplier dari berkas terhubung');
    t_true(StockMovement::hasInitial((int) $sp1['id']), 'Stok awal impor membentuk pergerakan STOK_AWAL');

    $sp2 = Sparepart::findByCode($kode2);
    t_true($sp2 !== null, 'Sparepart kedua tersimpan');
    t_eq(0.0, (float) $sp2['stock'], 'Stok 0 tidak membuat pergerakan stok');
    t_true(!StockMovement::hasInitial((int) $sp2['id']), 'Baris tanpa stok tidak punya pergerakan stok awal');

    t_true(t_latest_audit('IMPORT_MASTER') !== null, 'Impor master tercatat di Audit Log');
    t_true(t_latest_audit('SPAREPART_CREATE', (int) $sp1['id']) !== null, 'Setiap sparepart hasil impor juga tercatat di Audit Log');
}

function test_master_import_menolak_kode_duplikat(): void
{
    $categoryId = t_make_category('Filter Duplikat');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];

    $sudahAda = t_make_sparepart($categoryId);
    $kodeLama = (string) Sparepart::find($sudahAda)['code'];
    $kodeBaru = t_uniq('IMP');

    // 1) Kode sudah ada di database → ditolak, tidak menimpa.
    $path = t_temp_file('csv', t_csv([
        t_import_headers(),
        [$kodeLama, 'Mencoba Menimpa', $categoryCode, '', '', 'pcs', '1000', '2000', '0', '0', '', ''],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path, 'csv', 'duplikat-db.csv');
    @unlink($path);

    t_eq(1, (int) $preview['summary']['error'], 'Kode yang sudah ada di database dianggap bermasalah');
    t_contains('sudah ada di database', (string) $preview['rows'][0]['errors'][0], 'Alasan duplikat database dijelaskan');

    $namaSebelum = (string) Sparepart::find($sudahAda)['name'];
    t_message_contains(
        static fn () => MasterImportService::commit(MasterImportService::TYPE_SPAREPART, $preview['rows'], null, 'duplikat-db.csv'),
        'tidak ada data yang disimpan',
        'Komit dengan baris duplikat dibatalkan dan dijelaskan ke pengguna'
    );
    t_eq($namaSebelum, (string) Sparepart::find($sudahAda)['name'], 'Data lama TIDAK ditimpa');

    // 2) Kode duplikat di dalam berkas itu sendiri.
    $path2 = t_temp_file('csv', t_csv([
        t_import_headers(),
        [$kodeBaru, 'Pertama', $categoryCode, '', '', 'pcs', '1000', '2000', '0', '0', '', ''],
        [$kodeBaru, 'Kedua', $categoryCode, '', '', 'pcs', '1000', '2000', '0', '0', '', ''],
    ]));
    $preview2 = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path2, 'csv', 'duplikat-file.csv');
    @unlink($path2);

    t_eq(1, (int) $preview2['summary']['ok'], 'Baris pertama tetap valid');
    t_eq(1, (int) $preview2['summary']['error'], 'Baris kedua ditolak karena duplikat di dalam berkas');
    t_contains('duplikat di dalam berkas', (string) $preview2['rows'][1]['errors'][0], 'Alasan duplikat dalam berkas dijelaskan');
}

function test_master_import_atomik_satu_baris_salah(): void
{
    $categoryId = t_make_category('Filter Atomik');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $kodeValid = t_uniq('IMP');
    $kodeKategoriSalah = t_uniq('IMP');

    $path = t_temp_file('csv', t_csv([
        t_import_headers(),
        [$kodeValid, 'Baris Benar', $categoryCode, '', '', 'pcs', '1000', '2000', '5', '1', '', ''],
        [$kodeKategoriSalah, 'Baris Salah', 'KATEGORI-TIDAK-ADA', '', '', 'pcs', 'abc', '-1', 'abc', '1', '', ''],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path, 'csv', 'atomik.csv');
    @unlink($path);

    t_eq(1, (int) $preview['summary']['ok'], 'Satu baris valid');
    t_eq(1, (int) $preview['summary']['error'], 'Satu baris bermasalah');
    $alasan = implode(' ', $preview['rows'][1]['errors']);
    t_contains('Kategori "KATEGORI-TIDAK-ADA" tidak ditemukan', $alasan, 'Kategori tak dikenal dilaporkan per baris');
    t_contains('bukan angka', $alasan, 'Angka tidak valid dilaporkan per baris');
    t_contains('tidak boleh negatif', $alasan, 'Angka negatif dilaporkan per baris');

    $sebelum = (int) t_pdo()->query('SELECT COUNT(*) FROM spareparts')->fetchColumn();
    t_message_contains(
        static fn () => MasterImportService::commit(MasterImportService::TYPE_SPAREPART, $preview['rows'], null, 'atomik.csv'),
        'tidak ada data yang disimpan',
        'Komit dibatalkan bila ada satu baris bermasalah'
    );
    $sesudah = (int) t_pdo()->query('SELECT COUNT(*) FROM spareparts')->fetchColumn();
    t_eq($sebelum, $sesudah, 'Tidak ada sparepart tersimpan sebagian (atomik)');
    t_true(Sparepart::findByCode($kodeValid) === null, 'Baris yang tadinya valid pun tidak tersimpan');
}

function test_master_import_kolom_wajib_dan_alias_header(): void
{
    $categoryId = t_make_category('Filter Alias');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $kode = t_uniq('IMP');

    // Header tanpa kolom Kategori → ditolak sebelum menyentuh database.
    $path = t_temp_file('csv', t_csv([
        ['Kode', 'Nama', 'Stok'],
        [$kode, 'Tanpa Kategori', '5'],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path, 'csv', 'tanpa-kategori.csv');
    @unlink($path);

    t_true(in_array('Kategori', $preview['missing_required'], true), 'Kolom wajib yang hilang terdeteksi');
    t_eq(1, (int) $preview['summary']['error'], 'Semua baris dianggap gagal saat kolom wajib hilang');
    t_contains('Kolom wajib belum ada', (string) $preview['rows'][0]['errors'][0], 'Pesan kolom wajib hilang jelas');

    // Nama kolom alternatif (mis. dari sistem lain) tetap dikenali.
    $path2 = t_temp_file('csv', t_csv([
        ['kode_sparepart', 'nama_sparepart', 'kategori', 'stock', 'min_stock', 'harga_beli'],
        [$kode, 'Nama Alias', $categoryCode, '7', '2', '12000'],
    ]));
    $preview2 = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path2, 'csv', 'alias.csv');
    @unlink($path2);

    t_eq(1, (int) $preview2['summary']['ok'], 'Kolom dengan nama alternatif dikenali');
    t_eq([], $preview2['missing_required'], 'Tidak ada kolom wajib yang dilaporkan hilang');

    MasterImportService::commit(MasterImportService::TYPE_SPAREPART, $preview2['rows'], null, 'alias.csv');
    $row = Sparepart::findByCode($kode);
    t_true($row !== null, 'Baris dengan header alternatif tersimpan');
    t_eq(7.0, (float) $row['stock'], 'Kolom "stock" mengenali stok awal');
    t_eq(2.0, (float) $row['min_stock'], 'Kolom "min_stock" dikenali');
}

function test_master_import_stok_awal_dari_berkas(): void
{
    $categoryId = t_make_category('Kategori Impor Stok Awal');
    $sparepartId = t_make_sparepart($categoryId);
    $kode = (string) Sparepart::find($sparepartId)['code'];

    $path = t_temp_file('csv', t_csv([
        ['Kode', 'Jumlah', 'Tanggal', 'Catatan'],
        [$kode, '18', '18/10/2026', 'Hasil hitung fisik awal'],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_STOCK_INITIAL, $path, 'csv', 'stok-awal.csv');
    @unlink($path);

    t_eq(1, (int) $preview['summary']['ok'], 'Baris stok awal valid');
    t_eq($sparepartId, (int) $preview['rows'][0]['values']['sparepart_id'], 'Baris dikaitkan ke sparepart yang benar');

    $result = MasterImportService::commit(MasterImportService::TYPE_STOCK_INITIAL, $preview['rows'], null, 'stok-awal.csv');
    t_eq(1, (int) $result['created'], 'Satu pergerakan stok dibuat');
    t_eq(1, (int) $result['movements'], 'Ringkasan pergerakan stok sesuai');

    $initial = StockMovement::initialFor($sparepartId);
    t_true($initial !== null, 'Pergerakan STOK_AWAL terbentuk dari impor');
    t_eq('2026-10-18', (string) $initial['movement_date'], 'Tanggal dari berkas dipakai sebagai tanggal bisnis');
    t_eq('IMPORT', (string) $initial['reference_type'], 'Sumber pergerakan dicatat sebagai IMPORT');
    t_contains('stok-awal.csv', (string) $initial['note'], 'Catatan menyebut berkas sumber');
    t_eq(18.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok sparepart bertambah dari impor');
    t_true(t_latest_audit('IMPORT_STOCK_INITIAL') !== null, 'Impor stok awal tercatat di Audit Log');

    // Stok awal untuk item yang sama tidak boleh diimpor dua kali.
    $path2 = t_temp_file('csv', t_csv([
        ['Kode', 'Jumlah', 'Tanggal', 'Catatan'],
        [$kode, '5', '', 'Percobaan kedua'],
    ]));
    $preview2 = MasterImportService::previewFile(MasterImportService::TYPE_STOCK_INITIAL, $path2, 'csv', 'stok-awal-2.csv');
    @unlink($path2);

    t_eq(1, (int) $preview2['summary']['error'], 'Impor stok awal kedua ditolak');
    t_contains('sudah pernah dicatat', (string) $preview2['rows'][0]['errors'][0], 'Alasan stok awal ganda dijelaskan');

    // Kode sparepart tak dikenal.
    $path3 = t_temp_file('csv', t_csv([
        ['Kode', 'Jumlah'],
        ['TIDAK-ADA-' . strtoupper(bin2hex(random_bytes(3))), '5'],
    ]));
    $preview3 = MasterImportService::previewFile(MasterImportService::TYPE_STOCK_INITIAL, $path3, 'csv', 'stok-awal-3.csv');
    @unlink($path3);
    t_eq(1, (int) $preview3['summary']['error'], 'Kode sparepart tak dikenal ditolak');
    t_contains('tidak ditemukan', (string) $preview3['rows'][0]['errors'][0], 'Alasan kode tak dikenal dijelaskan');

    // Jumlah wajib & harus > 0 (memakai sparepart berbeda agar tidak
    // tertutup oleh pesan "sudah pernah dicatat").
    $sparepartKedua = t_make_sparepart($categoryId);
    $kodeKedua = (string) Sparepart::find($sparepartKedua)['code'];
    $path4 = t_temp_file('csv', t_csv([['Kode', 'Jumlah'], [$kodeKedua, '0']]));
    $preview4 = MasterImportService::previewFile(MasterImportService::TYPE_STOCK_INITIAL, $path4, 'csv', 'stok-awal-4.csv');
    @unlink($path4);
    t_contains('lebih dari 0', implode(' ', $preview4['rows'][0]['errors']), 'Jumlah 0 ditolak pada impor stok awal');

    $path5 = t_temp_file('csv', t_csv([['Kode', 'Jumlah'], [$kodeKedua, 'abc']]));
    $preview5 = MasterImportService::previewFile(MasterImportService::TYPE_STOCK_INITIAL, $path5, 'csv', 'stok-awal-5.csv');
    @unlink($path5);
    t_contains('bukan angka', implode(' ', $preview5['rows'][0]['errors']), 'Jumlah bukan angka ditolak pada impor stok awal');
}

function test_master_import_template_csv_dan_xlsx(): void
{
    // Template CSV: header sesuai definisi.
    $csv = MasterImportService::csvTemplate(MasterImportService::TYPE_SPAREPART);
    $path = t_temp_file('csv', $csv);
    $read = CsvReader::read($path, 500);
    @unlink($path);
    t_eq(MasterImportService::headers(MasterImportService::TYPE_SPAREPART), $read['headers'], 'Header template CSV sesuai definisi kolom');
    t_eq([], $read['rows'], 'Template CSV hanya berisi baris header (tanpa data contoh)');
    t_true(str_starts_with($csv, "\xEF\xBB\xBF"), 'Template CSV memakai BOM UTF-8 agar rapi di Excel');

    // Template XLSX: ditulis lalu dibaca kembali (round-trip).
    $rows = [
        MasterImportService::headers(MasterImportService::TYPE_STOCK_INITIAL),
        ['FLT-001', '24', '18/10/2026', 'Contoh "kutip" & <tanda>'],
    ];
    $binary = XlsxWriter::build($rows);
    t_true(str_starts_with($binary, "PK\x03\x04"), 'Berkas XLSX yang dibuat berformat arsip ZIP');

    $xlsxPath = t_temp_file('xlsx', $binary);
    $readXlsx = Spreadsheet::readFile($xlsxPath, 'xlsx');
    @unlink($xlsxPath);

    t_eq(MasterImportService::headers(MasterImportService::TYPE_STOCK_INITIAL), $readXlsx['headers'], 'Header XLSX terbaca kembali dengan urutan yang sama');
    t_eq('FLT-001', (string) $readXlsx['rows'][0][0], 'Sel teks XLSX terbaca kembali');
    t_eq('24', (string) $readXlsx['rows'][0][1], 'Sel angka XLSX terbaca kembali');
    t_eq('Contoh "kutip" & <tanda>', (string) $readXlsx['rows'][0][3], 'Karakter khusus pada sel XLSX tetap utuh');

    // Template XLSX resmi juga harus terbaca.
    $templatePath = t_temp_file('xlsx', MasterImportService::xlsxTemplate(MasterImportService::TYPE_SPAREPART));
    $templateRead = Spreadsheet::readFile($templatePath, 'xlsx');
    @unlink($templatePath);
    t_eq(
        MasterImportService::headers(MasterImportService::TYPE_SPAREPART),
        $templateRead['headers'],
        'Header template XLSX sesuai definisi kolom'
    );

    // Sparepart dari berkas XLSX sampai komit.
    $categoryId = t_make_category('Filter XLSX');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $kode = t_uniq('IMP');
    $importPath = t_temp_file('xlsx', XlsxWriter::build([
        MasterImportService::headers(MasterImportService::TYPE_SPAREPART),
        [$kode, 'Sparepart dari XLSX', $categoryCode, 'Honda', 'Vario', 'pcs', 25000, 31000, 6, 2, '', ''],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $importPath, 'xlsx', 'impor.xlsx');
    @unlink($importPath);

    t_eq(1, (int) $preview['summary']['ok'], 'Berkas XLSX valid dapat dipratinjau');
    MasterImportService::commit(MasterImportService::TYPE_SPAREPART, $preview['rows'], null, 'impor.xlsx');
    $row = Sparepart::findByCode($kode);
    t_true($row !== null, 'Sparepart dari berkas XLSX tersimpan');
    t_eq(6.0, (float) $row['stock'], 'Stok awal dari XLSX tercatat');
}

function test_master_import_menolak_berkas_rusak_besar_dan_panjang(): void
{
    // Berkas .xlsx yang isinya bukan arsip ZIP (mis. berkas CSV diganti nama).
    $palsu = t_temp_file('xlsx', "Kode;Nama\r\nABC;Palsu\r\n");
    t_message_contains(
        static fn () => Spreadsheet::readFile($palsu, 'xlsx'),
        'rusak',
        'Berkas .xlsx palsu/rusak ditolak dengan pesan jelas'
    );

    // Berkas .csv berisi data biner.
    $biner = t_temp_file('csv', "Kode;Nama\0\x01\x02\r\n");
    t_message_contains(
        static fn () => Spreadsheet::readUploaded([
            'error' => UPLOAD_ERR_OK, 'name' => 'data.csv', 'size' => filesize($biner), 'tmp_name' => $biner,
        ]),
        'biner',
        'Berkas CSV berisi data biner ditolak'
    );

    // Berkas terlalu besar (diatas 2 MB).
    t_message_contains(
        static fn () => Spreadsheet::readUploaded([
            'error' => UPLOAD_ERR_OK, 'name' => 'besar.csv', 'size' => Spreadsheet::MAX_BYTES + 1, 'tmp_name' => $biner,
        ]),
        'maksimal',
        'Berkas melebihi 2 MB ditolak'
    );

    // Berkas yang gagal diunggah (mis. melebihi batas server).
    t_message_contains(
        static fn () => Spreadsheet::readUploaded([
            'error' => UPLOAD_ERR_INI_SIZE, 'name' => 'besar.csv', 'size' => 10, 'tmp_name' => $biner,
        ]),
        'terlalu besar',
        'Kegagalan unggah karena ukuran dijelaskan'
    );

    // Tidak ada berkas sama sekali.
    t_message_contains(
        static fn () => Spreadsheet::readUploaded(['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'size' => 0, 'tmp_name' => '']),
        'Pilih berkas',
        'Unggahan tanpa berkas dijelaskan'
    );

    // Ekstensi tidak didukung.
    $txt = t_temp_file('txt', "Kode;Nama\r\n");
    t_message_contains(
        static fn () => Spreadsheet::readUploaded([
            'error' => UPLOAD_ERR_OK, 'name' => 'data.txt', 'size' => filesize($txt), 'tmp_name' => $txt,
        ]),
        'csv',
        'Ekstensi selain .csv/.xlsx ditolak'
    );
    @unlink($txt);

    // Terlalu banyak baris.
    $baris = [];
    $baris[] = ['Kode', 'Nama', 'Kategori'];
    for ($i = 1; $i <= Spreadsheet::MAX_ROWS + 1; $i++) {
        $baris[] = ['SP-' . $i, 'Nama ' . $i, 'Filter'];
    }
    $banyak = t_temp_file('csv', t_csv($baris));
    t_message_contains(
        static fn () => CsvReader::read($banyak, Spreadsheet::MAX_ROWS),
        'lebih dari ' . Spreadsheet::MAX_ROWS . ' baris',
        'Berkas dengan lebih dari 500 baris data ditolak'
    );
    @unlink($banyak);

    // Berkas kosong.
    $kosong = t_temp_file('csv', '');
    t_message_contains(
        static fn () => CsvReader::read($kosong, Spreadsheet::MAX_ROWS),
        'kosong',
        'Berkas CSV kosong ditolak'
    );
    @unlink($kosong);
    @unlink($palsu);
    @unlink($biner);
}

function test_master_import_pratinjau_disimpan_di_sesi(): void
{
    $categoryId = t_make_category('Filter Sesi Impor');
    $categoryCode = (string) App\Models\Category::find($categoryId)['code'];
    $kode = t_uniq('IMP');

    $path = t_temp_file('csv', t_csv([
        t_import_headers(),
        [$kode, 'Sparepart Sesi', $categoryCode, '', '', 'pcs', '1000', '2000', '3', '1', '', ''],
    ]));
    $preview = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path, 'csv', 'sesi.csv');
    @unlink($path);

    $token = bin2hex(random_bytes(16));
    MasterImportService::stash($token, $preview);

    $pulled = MasterImportService::pull($token);
    t_true($pulled !== null, 'Pratinjau dapat diambil kembali memakai token');
    t_eq($kode, (string) $pulled['rows'][0]['cells']['kode'], 'Isi pratinjau tersimpan utuh');

    t_eq(null, MasterImportService::pull('token-palsu-' . bin2hex(random_bytes(4))), 'Token yang salah tidak dapat mengambil pratinjau');

    MasterImportService::forget();
    t_eq(null, MasterImportService::pull($token), 'Pratinjau hilang setelah komit/dibatalkan (sekali pakai)');

    // Kategori nonaktif pada berkas tetap ditolak (FR-12).
    $nonaktifId = t_make_category('Filter Nonaktif', false);
    $nonaktifCode = (string) App\Models\Category::find($nonaktifId)['code'];
    $path2 = t_temp_file('csv', t_csv([
        t_import_headers(),
        [t_uniq('IMP'), 'Kategori Nonaktif', $nonaktifCode, '', '', 'pcs', '1000', '2000', '0', '0', '', ''],
    ]));
    $preview2 = MasterImportService::previewFile(MasterImportService::TYPE_SPAREPART, $path2, 'csv', 'nonaktif.csv');
    @unlink($path2);
    t_contains('nonaktif', (string) $preview2['rows'][0]['errors'][0], 'Kategori nonaktif pada impor ditolak');

    // Jenis impor tak dikenal.
    t_message_contains(
        static fn () => MasterImportService::preview(MasterImportService::TYPE_SPAREPART . '-salah', ['Kode'], [], 'salah.csv'),
        'tidak dikenal',
        'Jenis impor tak dikenal ditolak'
    );
    t_message_contains(
        static fn () => MasterImportService::headers('jenis-ngawur'),
        'tidak dikenal',
        'Permintaan kolom template untuk jenis tak dikenal ditolak'
    );
    // Konfirmasi kategori nonaktif tetap nonaktif (tidak diubah oleh pengujian).
    t_eq(0, (int) App\Models\Category::find($nonaktifId)['is_active'], 'Status kategori nonaktif tidak berubah');
    t_true(CategoryService::find($categoryId) !== null, 'Kategori pengujian masih ada');
}
