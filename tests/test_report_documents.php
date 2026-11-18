<?php
declare(strict_types=1);

use App\Core\View;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;

/**
 * Pengujian Fase 5 — dokumen cetak (PO, bukti barang masuk/keluar) dan
 * tautan "Cetak Bukti" pada halaman detail transaksi.
 *
 * Semua data berasal dari fixture Fase 5 (lihat test_report.php) sehingga
 * pengujian tidak bergantung pada isi database uji dari sesi sebelumnya.
 */

/** @return array{name:string,address:string,phone:string,email:string,note:string} */
function t_doc_identity(): array
{
    return [
        'name'    => 'Bengkel Uji Dokumen',
        'address' => 'Jl. Dokumen No. 5',
        'phone'   => '021-555-0505',
        'email'   => 'dokumen@example.test',
        'note'    => 'Dokumen uji Fase 5.',
    ];
}

function test_document_po_cetak(): void
{
    $f = t_f5_fixture();
    $po = PurchaseOrder::find((int) $f['po1']);
    t_true($po !== null, 'Data PO fixture ditemukan');
    if ($po === null) {
        return;
    }
    $items = PurchaseOrder::items((int) $f['po1']);
    t_eq(1, count($items), 'PO fixture memiliki satu item');
    t_eq($f['p'] . '-PO1', (string) $po['po_number'], 'Nomor PO sesuai fixture');
    t_eq($f['supplier_name'], (string) $po['supplier_name'], 'Nama supplier PO terbaca');
    t_eq('MENUNGGU_PERSETUJUAN', (string) $po['status'], 'Status PO terbaca');

    try {
        $html = View::render('documents/po', [
            'title'    => 'Purchase Order ' . (string) $po['po_number'],
            'po'       => $po,
            'items'    => $items,
            'identity' => t_doc_identity(),
            'orientation' => 'portrait',
            'backUrl'  => '#',
            'pdfUrl'   => '#',
        ], 'layouts/print');
    } catch (Throwable $e) {
        t_true(false, 'View cetak PO gagal dirender: ' . $e->getMessage());
        return;
    }

    t_true(!str_contains($html, '<?php'), 'Dokumen PO tidak membocorkan tag PHP');
    t_contains('PURCHASE ORDER', $html, 'Judul dokumen PO tampil');
    t_contains((string) $po['po_number'], $html, 'Nomor PO tampil pada dokumen');
    t_contains('Bengkel Uji Dokumen', $html, 'Kop bengkel tampil pada PO');
    t_contains('Kepada', $html, 'Bagian penerima PO tampil');
    t_contains((string) $po['supplier_name'], $html, 'Nama supplier tampil pada PO');
    t_contains('TOTAL', $html, 'Baris total PO tampil');
    t_contains('Rp 50.000', $html, 'Nilai total PO dihitung dari item');
    t_contains('Dibuat oleh', $html, 'Blok tanda tangan pembuat tampil');
    t_contains('Disetujui oleh', $html, 'Blok tanda tangan persetujuan tampil');
    t_contains('doc-table', $html, 'Tabel item memakai gaya dokumen cetak');
    t_contains('table', $html, 'Dokumen PO memuat tabel item');
}

function test_document_bukti_barang_masuk(): void
{
    $f = t_f5_fixture();
    $receipt = GoodsReceipt::find((int) $f['receipt']);
    t_true($receipt !== null, 'Data penerimaan fixture ditemukan');
    if ($receipt === null) {
        return;
    }
    $items = GoodsReceipt::items((int) $f['receipt']);
    t_eq(1, count($items), 'Penerimaan fixture memiliki satu item');
    t_eq(1, (int) $receipt['has_po'], 'Penerimaan tertaut ke PO');
    t_eq(2.0, (float) $receipt['total_quantity'], 'Total qty penerimaan sesuai item');

    try {
        $html = View::render('documents/receipt', [
            'title'    => 'Bukti Barang Masuk ' . (string) $receipt['receipt_number'],
            'row'      => $receipt,
            'items'    => $items,
            'movement' => null,
            'identity' => t_doc_identity(),
            'orientation' => 'portrait',
            'backUrl'  => '#',
            'pdfUrl'   => null,
        ], 'layouts/print');
    } catch (Throwable $e) {
        t_true(false, 'View cetak penerimaan gagal dirender: ' . $e->getMessage());
        return;
    }

    t_true(!str_contains($html, '<?php'), 'Bukti barang masuk tidak membocorkan tag PHP');
    t_contains('BUKTI BARANG MASUK', $html, 'Judul bukti barang masuk tampil');
    t_contains((string) $receipt['receipt_number'], $html, 'Nomor penerimaan tampil');
    t_contains('Bengkel Uji Dokumen', $html, 'Kop bengkel tampil pada bukti masuk');
    t_contains((string) $receipt['supplier_name'], $html, 'Supplier tampil pada bukti masuk');
    t_contains((string) $receipt['invoice_number'], $html, 'Nomor invoice tampil');
    t_contains('Pembelian', $html, 'Jenis penerimaan pembelian tampil');
    t_contains('TOTAL', $html, 'Total bukti masuk tampil');
    t_contains('Rp 20.000', $html, 'Subtotal bukti masuk dihitung dari qty x harga');
    t_contains('Diserahkan oleh (Supplier)', $html, 'Blok tanda tangan supplier tampil');
    t_contains('Diterima oleh (Partman)', $html, 'Blok tanda tangan penerima tampil');
    t_contains('doc-table', $html, 'Tabel item bukti masuk memakai gaya cetak');
}

function test_document_bukti_barang_keluar(): void
{
    $f = t_f5_fixture();
    $issue = GoodsIssue::find((int) $f['issue']);
    t_true($issue !== null, 'Data pengeluaran fixture ditemukan');
    if ($issue === null) {
        return;
    }
    $items = GoodsIssue::items((int) $f['issue']);
    t_eq(1, count($items), 'Pengeluaran fixture memiliki satu item');
    t_eq(1.0, (float) $issue['total_quantity'], 'Total qty pengeluaran sesuai item');

    try {
        $html = View::render('documents/issue', [
            'title'    => 'Bukti Barang Keluar ' . (string) $issue['issue_number'],
            'row'      => $issue,
            'items'    => $items,
            'movement' => null,
            'identity' => t_doc_identity(),
            'orientation' => 'portrait',
            'backUrl'  => '#',
            'pdfUrl'   => null,
        ], 'layouts/print');
    } catch (Throwable $e) {
        t_true(false, 'View cetak pengeluaran gagal dirender: ' . $e->getMessage());
        return;
    }

    t_true(!str_contains($html, '<?php'), 'Bukti barang keluar tidak membocorkan tag PHP');
    t_contains('BUKTI BARANG KELUAR', $html, 'Judul bukti barang keluar tampil');
    t_contains((string) $issue['issue_number'], $html, 'Nomor pengeluaran tampil');
    t_contains('Bengkel Uji Dokumen', $html, 'Kop bengkel tampil pada bukti keluar');
    t_contains('Servis', $html, 'Jenis pemakaian servis tampil');
    t_contains((string) $issue['spk_number'], $html, 'Nomor SPK tampil');
    t_contains((string) $issue['plate_number'], $html, 'Nomor polisi tampil');
    t_contains('Total qty', $html, 'Total qty pengeluaran tampil');
    t_contains('Penerima', $html, 'Blok tanda tangan penerima tampil');
    t_contains('Petugas Partman', $html, 'Blok tanda tangan Partman tampil');
    t_contains('doc-table', $html, 'Tabel item bukti keluar memakai gaya cetak');
}

function test_document_tautan_cetak_pada_detail_transaksi(): void
{
    $f = t_f5_fixture();

    $receipt = GoodsReceipt::find((int) $f['receipt']);
    $receiptItems = GoodsReceipt::items((int) $f['receipt']);
    $receiptHtml = View::render('transactions/receipts/show', [
        'title'         => 'Detail Penerimaan',
        'canManage'     => false,
        'row'           => $receipt,
        'items'         => $receiptItems,
        'movement'      => null,
        'hasEvidence'   => false,
        'attachmentUrl' => '',
    ], 'layouts/app');
    t_true(!str_contains($receiptHtml, '<?php'), 'Detail penerimaan tidak membocorkan tag PHP');
    t_contains('Cetak Bukti', $receiptHtml, 'Tautan cetak tersedia pada detail penerimaan');
    t_contains(
        '/transaksi/masuk/' . (int) $f['receipt'] . '/cetak',
        $receiptHtml,
        'Tautan cetak penerimaan menuju aksi cetak'
    );
    t_contains('table-responsive', $receiptHtml, 'Tabel item penerimaan responsif');

    $issue = GoodsIssue::find((int) $f['issue']);
    $issueItems = GoodsIssue::items((int) $f['issue']);
    $issueHtml = View::render('transactions/issues/show', [
        'title'     => 'Detail Pengeluaran',
        'canManage' => false,
        'row'       => $issue,
        'items'     => $issueItems,
        'movement'  => null,
    ], 'layouts/app');
    t_true(!str_contains($issueHtml, '<?php'), 'Detail pengeluaran tidak membocorkan tag PHP');
    t_contains('Cetak Bukti', $issueHtml, 'Tautan cetak tersedia pada detail pengeluaran');
    t_contains(
        '/transaksi/keluar/' . (int) $f['issue'] . '/cetak',
        $issueHtml,
        'Tautan cetak pengeluaran menuju aksi cetak'
    );
    t_contains('table-responsive', $issueHtml, 'Tabel item pengeluaran responsif');
}
