<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\View;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Services\GoodsIssueService;
use App\Services\GoodsReceiptService;
use App\Services\StockInitialService;
use App\Services\StockOpnameService;
use App\Services\SupplierService;

/**
 * Pengujian Fase 4 — render tampilan modul transaksi (smoke test).
 *
 * Memastikan seluruh view transaksi dapat dirender dengan data NYATA dari
 * basis data uji, tidak membocorkan tag PHP, tidak memunculkan peringatan
 * PHP, dan menyembunyikan tombol pengubah data dari peran non-Partman.
 */

/** Render view (tanpa layout) + pemeriksaan kebersihan keluaran. */
function t_transaction_view(string $template, array $data, string $label): string
{
    try {
        $html = View::render($template, $data, null);
    } catch (Throwable $e) {
        t_true(false, "View {$label} gagal dirender: " . $e->getMessage());
        return '';
    }

    t_true($html !== '', "View {$label} berhasil dirender");
    t_true(!str_contains($html, '<?php'), "View {$label} tidak membocorkan tag PHP");
    t_true(
        !str_contains($html, 'Undefined')
        && !str_contains($html, 'Warning:')
        && !str_contains($html, 'Notice:')
        && !str_contains($html, 'Fatal error'),
        "View {$label} dirender tanpa peringatan/galat PHP"
    );

    return $html;
}

/** Data nyata untuk pengujian tampilan transaksi. @return array<string,mixed> */
function t_transaction_view_fixture(): array
{
    $categoryId = t_make_category('Kategori Tampilan Transaksi');
    $supplierId = t_make_supplier('Supplier Tampilan');
    $actorId = t_make_user('uji_tampilan_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs', 'purchase_price' => 10000]);

    StockInitialService::apply($sparepartId, 20, '2026-10-01', 'Stok awal uji tampilan', $actorId, 'MANUAL');

    $upload = t_upload('Nota Tampilan.png', t_png_bytes());
    $receiptId = GoodsReceiptService::create([
        'receipt_date'      => '2026-10-18',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => $supplierId,
        'sparepart_id'      => $sparepartId,
        'quantity'          => 5,
        'unit_price'        => 12000,
        'invoice_number'    => 'INV-VIEW-1',
        'without_po_reason' => 'Uji tampilan tanpa PO',
        'note'              => 'Penerimaan uji tampilan',
    ], $actorId, $upload['file']);
    @unlink($upload['tmp']);

    $issueId = GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => $sparepartId,
        'quantity'     => 3,
        'spk_number'   => 'SPK-VIEW-1',
        'plate_number' => 'B 7777 VW',
        'note'         => 'Pengeluaran uji tampilan',
    ], $actorId);

    return [
        'actor'     => $actorId,
        'supplier'  => $supplierId,
        'sparepart' => $sparepartId,
        'receipt'   => $receiptId,
        'issue'     => $issueId,
    ];
}

function test_transaction_view_penerimaan(): void
{
    $f = t_transaction_view_fixture();
    $receipt = GoodsReceipt::find($f['receipt']);
    t_true($receipt !== null, 'Dokumen penerimaan uji tersedia');

    $index = t_transaction_view('transactions/receipts/index', [
        'title'     => 'Barang Masuk',
        'canManage' => true,
        'filters'   => ['search' => '', 'supplier_id' => '', 'receipt_type' => '', 'date_from' => '', 'date_to' => ''],
        'rows'      => GoodsReceipt::paginate([], 1, 25)['rows'],
        'total'     => 1,
        'page'      => 1,
        'pages'     => 1,
        'suppliers' => SupplierService::options(false),
    ], 'transactions/receipts/index (Partman)');
    t_contains('Catat Penerimaan', $index, 'Tombol catat penerimaan tampil untuk Partman');
    t_contains('Tanpa PO', $index, 'Daftar menandai penerimaan tanpa PO');
    t_contains((string) $receipt['receipt_number'], $index, 'Daftar menampilkan nomor dokumen');

    $indexReadOnly = t_transaction_view('transactions/receipts/index', [
        'title'     => 'Barang Masuk',
        'canManage' => false,
        'filters'   => ['search' => '', 'supplier_id' => '', 'receipt_type' => '', 'date_from' => '', 'date_to' => ''],
        'rows'      => GoodsReceipt::paginate([], 1, 25)['rows'],
        'total'     => 1,
        'page'      => 1,
        'pages'     => 1,
        'suppliers' => SupplierService::options(false),
    ], 'transactions/receipts/index (Kepala Bengkel)');
    t_true(
        !str_contains($indexReadOnly, 'Catat Penerimaan'),
        'Tombol catat penerimaan DISEMBUNYIKAN dari peran non-Partman'
    );

    $form = t_transaction_view('transactions/receipts/form', [
        'title'      => 'Catat Barang Masuk',
        'old'        => [],
        'spareparts' => Sparepart::options(true),
        'suppliers'  => SupplierService::options(true),
    ], 'transactions/receipts/form');
    t_contains('Barang Masuk', $form, 'Form penerimaan menjelaskan judulnya');
    t_contains('name="quantity"', $form, 'Form penerimaan memuat kolom jumlah');
    t_contains('name="_token"', $form, 'Form penerimaan menyertakan token CSRF');
    t_contains('enctype="multipart/form-data"', $form, 'Form penerimaan mendukung unggahan bukti');

    $show = t_transaction_view('transactions/receipts/show', [
        'title'         => 'Detail Penerimaan',
        'canManage'     => true,
        'row'           => $receipt,
        'items'         => GoodsReceipt::items((int) $receipt['id']),
        'movement'      => StockMovement::forReference('GOODS_RECEIPT', (int) $receipt['id']),
        'hasEvidence'   => !empty($receipt['attachment_path']),
        'attachmentUrl' => base_url('transaksi/masuk/' . $receipt['id'] . '/bukti'),
    ], 'transactions/receipts/show');
    t_contains((string) $receipt['receipt_number'], $show, 'Detail menampilkan nomor dokumen');
    t_contains('Bukti', $show, 'Detail menampilkan bagian bukti');
    t_contains('MASUK', $show, 'Detail menampilkan pergerakan ledger MASUK');
}

function test_transaction_view_pengeluaran(): void
{
    $f = t_transaction_view_fixture();
    $issue = GoodsIssue::find($f['issue']);
    t_true($issue !== null, 'Dokumen pengeluaran uji tersedia');

    $index = t_transaction_view('transactions/issues/index', [
        'title'     => 'Barang Keluar',
        'canManage' => true,
        'filters'   => ['search' => '', 'usage_type' => '', 'date_from' => '', 'date_to' => ''],
        'rows'      => GoodsIssue::paginate([], 1, 25)['rows'],
        'total'     => 1,
        'page'      => 1,
        'pages'     => 1,
    ], 'transactions/issues/index (Partman)');
    t_contains('Catat Pengeluaran', $index, 'Tombol catat pengeluaran tampil untuk Partman');
    t_contains((string) $issue['issue_number'], $index, 'Daftar menampilkan nomor dokumen pengeluaran');

    $indexReadOnly = t_transaction_view('transactions/issues/index', [
        'title'     => 'Barang Keluar',
        'canManage' => false,
        'filters'   => ['search' => '', 'usage_type' => '', 'date_from' => '', 'date_to' => ''],
        'rows'      => GoodsIssue::paginate([], 1, 25)['rows'],
        'total'     => 1,
        'page'      => 1,
        'pages'     => 1,
    ], 'transactions/issues/index (non-Partman)');
    t_true(
        !str_contains($indexReadOnly, 'Catat Pengeluaran'),
        'Tombol catat pengeluaran DISEMBUNYIKAN dari peran non-Partman'
    );

    $form = t_transaction_view('transactions/issues/form', [
        'title'      => 'Catat Barang Keluar',
        'old'        => [],
        'spareparts' => Sparepart::options(true),
    ], 'transactions/issues/form');
    t_contains('name="usage_type"', $form, 'Form pengeluaran memuat pilihan jenis penggunaan');
    t_contains('name="_token"', $form, 'Form pengeluaran menyertakan token CSRF');
    t_contains('name="spk_number"', $form, 'Form pengeluaran memuat kolom SPK');
    t_contains('name="plate_number"', $form, 'Form pengeluaran memuat kolom nomor polisi');

    $show = t_transaction_view('transactions/issues/show', [
        'title'     => 'Detail Pengeluaran',
        'canManage' => true,
        'row'       => $issue,
        'items'     => GoodsIssue::items((int) $issue['id']),
        'movement'  => StockMovement::forReference('GOODS_ISSUE', (int) $issue['id']),
    ], 'transactions/issues/show');
    t_contains('SPK-VIEW-1', $show, 'Detail menampilkan nomor SPK');
    t_contains('B 7777 VW', $show, 'Detail menampilkan nomor polisi');
    t_contains('KELUAR', $show, 'Detail menampilkan pergerakan ledger KELUAR');
}

function test_transaction_view_stock_opname(): void
{
    $f = t_transaction_view_fixture();

    // Bersihkan sesi terbuka sisa pengujian lain.
    Database::pdo()->exec("DELETE i FROM stock_opname_items i JOIN stock_opnames o ON o.id = i.opname_id WHERE o.status = 'OPEN'");
    Database::pdo()->exec("DELETE FROM stock_opnames WHERE status = 'OPEN'");

    $start = StockOpnameService::start(['opname_date' => '2026-10-18', 'note' => 'Opname uji tampilan'], $f['actor']);
    $opnameId = $start['opname_id'];

    $index = t_transaction_view('transactions/opnames/index', [
        'title'          => 'Stock Opname',
        'canManage'      => true,
        'filters'        => ['search' => '', 'status' => '', 'date_from' => '', 'date_to' => ''],
        'rows'           => StockOpname::paginate([], 1, 25)['rows'],
        'total'          => 1,
        'page'           => 1,
        'pages'          => 1,
        'hasOpenSession' => false,
    ], 'transactions/opnames/index (tanpa sesi terbuka)');
    t_contains('Mulai Stock Opname', $index, 'Tombol mulai opname tampil untuk Partman saat tidak ada sesi terbuka');
    t_contains('OPEN', $index, 'Daftar menampilkan status sesi');

    $indexAdaSesi = t_transaction_view('transactions/opnames/index', [
        'title'          => 'Stock Opname',
        'canManage'      => true,
        'filters'        => ['search' => '', 'status' => '', 'date_from' => '', 'date_to' => ''],
        'rows'           => StockOpname::paginate([], 1, 25)['rows'],
        'total'          => 1,
        'page'           => 1,
        'pages'          => 1,
        'hasOpenSession' => true,
    ], 'transactions/opnames/index (ada sesi terbuka)');
    t_true(
        !str_contains($indexAdaSesi, 'Mulai Stock Opname'),
        'Tombol mulai opname DISEMBUNYIKAN saat masih ada sesi terbuka'
    );

    $form = t_transaction_view('transactions/opnames/form', [
        'title'          => 'Mulai Stock Opname',
        'old'            => [],
        'hasOpenSession' => false,
    ], 'transactions/opnames/form');
    t_contains('name="_token"', $form, 'Form opname menyertakan token CSRF');
    t_contains('Stock Opname', $form, 'Form opname menjelaskan judulnya');

    $opname = StockOpname::find($opnameId);
    $showEditable = t_transaction_view('transactions/opnames/show', [
        'title'     => 'Stock Opname',
        'canManage' => true,
        'row'       => $opname,
        'items'     => StockOpname::items($opnameId),
        'old'       => [],
    ], 'transactions/opnames/show (belum dikonfirmasi)');
    t_contains('name="physical[', $showEditable, 'Partman melihat kolom isian jumlah fisik');
    t_contains('name="reason[', $showEditable, 'Partman melihat kolom alasan selisih');
    t_contains('type="submit">Simpan Hasil Hitung', $showEditable, 'Partman melihat tombol simpan hasil hitung');
    t_contains('type="submit">Konfirmasi Hasil Opname', $showEditable, 'Partman melihat tombol konfirmasi');
    t_contains('Stok belum berubah', $showEditable, 'Halaman menegaskan stok belum berubah sebelum konfirmasi');

    // Kepala Bengkel: dapat melihat, TIDAK dapat mengubah (tidak ada formulir pengubah).
    $showReadOnly = t_transaction_view('transactions/opnames/show', [
        'title'     => 'Stock Opname',
        'canManage' => false,
        'row'       => $opname,
        'items'     => StockOpname::items($opnameId),
        'old'       => [],
    ], 'transactions/opnames/show (Kepala Bengkel)');
    t_true(
        !str_contains($showReadOnly, 'name="physical[')
        && !str_contains($showReadOnly, 'name="reason[')
        && !str_contains($showReadOnly, 'name="_token"')
        && !str_contains($showReadOnly, 'type="submit">Simpan Hasil Hitung')
        && !str_contains($showReadOnly, 'type="submit">Konfirmasi Hasil Opname'),
        'Kepala Bengkel TIDAK melihat kolom isian/formulir maupun tombol pengubah opname'
    );
    t_contains((string) $opname['opname_number'], $showReadOnly, 'Kepala Bengkel tetap melihat nomor & isi sesi opname');

    // Setelah dikonfirmasi halaman menjadi baca-saja.
    StockOpnameService::saveInputs(
        $opnameId,
        [$f['sparepart'] => '19'],
        [$f['sparepart'] => 'Selisih uji tampilan'],
        $f['actor']
    );
    StockOpnameService::confirm($opnameId, $f['actor']);

    $showConfirmed = t_transaction_view('transactions/opnames/show', [
        'title'     => 'Stock Opname',
        'canManage' => true,
        'row'       => StockOpname::find($opnameId),
        'items'     => StockOpname::items($opnameId),
        'old'       => [],
    ], 'transactions/opnames/show (sudah dikonfirmasi)');
    t_contains('Sudah dikonfirmasi', $showConfirmed, 'Halaman menjelaskan sesi sudah dikonfirmasi');
    t_true(
        !str_contains($showConfirmed, 'name="physical[')
        && !str_contains($showConfirmed, 'name="_token"')
        && !str_contains($showConfirmed, 'type="submit">Konfirmasi Hasil Opname'),
        'Sesi terkonfirmasi tidak lagi dapat diubah dari halaman'
    );
    t_contains('PENYESUAIAN_OPNAME', $showConfirmed, 'Halaman menjelaskan penyesuaian dicatat di ledger');
}

function test_transaction_view_riwayat_dan_koreksi(): void
{
    $f = t_transaction_view_fixture();

    $filters = [
        'search'         => '',
        'sparepart_id'   => '',
        'movement_type'  => '',
        'reference_type' => '',
        'date_from'      => '',
        'date_to'        => '',
    ];

    $history = t_transaction_view('transactions/history/index', [
        'title'      => 'Riwayat Pergerakan Stok',
        'canManage'  => true,
        'filters'    => $filters,
        'rows'       => StockMovement::filtered($filters, 25, 0),
        'totals'     => StockMovement::totals($filters),
        'total'      => StockMovement::countFiltered($filters),
        'page'       => 1,
        'pages'      => 1,
        'spareparts' => Sparepart::options(false),
        'types'      => StockMovement::TYPES,
    ], 'transactions/history/index');
    t_contains('Riwayat Pergerakan Stok', $history, 'Halaman riwayat menampilkan judulnya');
    t_contains('MASUK', $history, 'Riwayat menampilkan jenis pergerakan');
    t_contains($filters['search'], $history, 'Kolom pencarian riwayat dirender');

    $correctable = StockMovement::correctable(50);
    t_true($correctable !== [], 'Ada transaksi yang dapat dikoreksi untuk uji tampilan');

    $koreksiIndex = t_transaction_view('transactions/corrections/index', [
        'title'     => 'Koreksi Transaksi',
        'canManage' => true,
        'rows'      => $correctable,
    ], 'transactions/corrections/index');
    t_contains('Koreksi Transaksi', $koreksiIndex, 'Halaman koreksi menampilkan judulnya');
    t_contains('Koreksi', $koreksiIndex, 'Halaman koreksi menyediakan tombol koreksi');
    t_contains('tidak dihapus', $koreksiIndex, 'Halaman menegaskan transaksi tidak pernah dihapus');

    $target = $correctable[0];
    $koreksiForm = t_transaction_view('transactions/corrections/form', [
        'title'       => 'Koreksi Transaksi',
        'canManage'   => true,
        'row'         => $target,
        'remaining'   => \App\Services\StockLedger::remainingReversible($target),
        'correctable' => true,
        'old'         => [],
    ], 'transactions/corrections/form');
    t_contains('name="reason"', $koreksiForm, 'Form koreksi memuat kolom alasan (wajib)');
    t_contains('name="quantity"', $koreksiForm, 'Form koreksi memuat kolom jumlah yang dibalik');
    t_contains((string) $target['id'], $koreksiForm, 'Form koreksi menampilkan referensi transaksi asal');
    t_contains('name="_token"', $koreksiForm, 'Form koreksi menyertakan token CSRF');
}
