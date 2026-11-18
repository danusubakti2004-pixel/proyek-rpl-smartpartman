<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Router;
use App\Core\View;
use App\Models\AiPrediction;
use App\Models\AppSetting;
use App\Models\GoodsIssue;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\Import\Spreadsheet;
use App\Services\Import\XlsxWriter;
use App\Services\PdfService;
use App\Services\ReportService;
use App\Services\SettingService;

/**
 * Pengujian Fase 5 — laporan, filter, ringkasan, ekspor, dan cetak.
 *
 * Seluruh data fixture memakai prefiks unik per eksekusi sehingga test
 * tidak bergantung pada isi database uji dari pengujian sebelumnya.
 */

/**
 * Fixture Fase 5: master, PO, penerimaan, pengeluaran, opname, ledger,
 * dan permintaan reorder. Tanggal sengaja dipisah agar filter dapat diuji.
 *
 * @return array<string,mixed>
 */
function t_f5_fixture(): array
{
    static $fixture = null;
    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = Database::pdo();
    $p = t_uniq('RPT');
    $actor = t_make_user('f5_' . strtolower($p), 'partman', t_password());
    $category = t_make_category('Kategori Fase 5');
    $supplier = t_make_supplier('Supplier Fase 5');

    $supplierRow = $pdo->prepare('SELECT code, name FROM suppliers WHERE id = ?');
    $supplierRow->execute([$supplier]);
    $supplierData = $supplierRow->fetch() ?: ['code' => '', 'name' => ''];

    $spA = t_make_sparepart($category, [
        'code' => $p . '-A', 'name' => 'Sparepart Kritis ' . $p,
        'stock' => 1, 'min_stock' => 3, 'purchase_price' => 10000,
        'selling_price' => 15000, 'supplier_id' => $supplier,
    ]);
    $spB = t_make_sparepart($category, [
        'code' => $p . '-B', 'name' => 'Sparepart Habis ' . $p,
        'stock' => 0, 'min_stock' => 0, 'purchase_price' => 5000,
        'supplier_id' => $supplier,
    ]);
    $spC = t_make_sparepart($category, [
        'code' => $p . '-C', 'name' => 'Sparepart Cepat ' . $p,
        'stock' => 20, 'min_stock' => 5, 'purchase_price' => 7000,
        'supplier_id' => $supplier,
    ]);
    $spD = t_make_sparepart($category, [
        'code' => $p . '-D', 'name' => 'Sparepart Nonaktif ' . $p,
        'stock' => 5, 'min_stock' => 2, 'purchase_price' => 3000,
        'supplier_id' => $supplier, 'is_active' => 0,
    ]);

    $pdo->prepare('UPDATE spareparts SET avg_purchase_price = ?, last_purchase_date = ? WHERE id = ?')
        ->execute([9000, '2024-02-11', $spA]);
    $pdo->prepare('UPDATE spareparts SET avg_purchase_price = ? WHERE id = ?')->execute([7000, $spC]);

    $insertPo = $pdo->prepare(
        'INSERT INTO purchase_orders
            (po_number, supplier_id, status, payment_status, total_amount, note, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertPo->execute([$p . '-PO1', $supplier, 'MENUNGGU_PERSETUJUAN', 'BELUM_DIBAYAR', 50000, 'PO uji satu', $actor, '2024-02-10 09:00:00']);
    $po1 = (int) $pdo->lastInsertId();
    $insertPo->execute([$p . '-PO2', $supplier, 'DISETUJUI', 'DIBAYAR_SEBAGIAN', 20000, 'PO uji dua', $actor, '2024-02-09 10:00:00']);
    $po2 = (int) $pdo->lastInsertId();

    $insertPoItem = $pdo->prepare(
        'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insertPoItem->execute([$po1, $spA, 5, 10000, 2]);
    $poItem1 = (int) $pdo->lastInsertId();
    $insertPoItem->execute([$po2, $spC, 2, 10000, 0]);

    $receiptNumber = $p . '-GR';
    $pdo->prepare(
        'INSERT INTO goods_receipts
            (receipt_number, receipt_date, receipt_type, supplier_id, purchase_order_id,
             invoice_number, note, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$receiptNumber, '2024-02-11', 'pembelian', $supplier, $po1, 'INV-' . $p, 'Penerimaan uji', $actor]);
    $receipt = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price, po_item_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$receipt, $spA, 2, 10000, $poItem1]);

    $issueNumber = $p . '-GI';
    $pdo->prepare(
        'INSERT INTO goods_issues
            (issue_number, issue_date, usage_type, spk_number, plate_number, note, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$issueNumber, '2024-02-12', 'service', 'SPK-' . $p, 'B 1234 ' . substr($p, -3), 'Pengeluaran uji', $actor]);
    $issue = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO goods_issue_items (issue_id, sparepart_id, quantity) VALUES (?, ?, ?)')
        ->execute([$issue, $spA, 1]);

    $opnameNumber = $p . '-SO';
    $pdo->prepare(
        'INSERT INTO stock_opnames (opname_number, opname_date, status, note, started_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$opnameNumber, '2024-02-13', 'OPEN', 'Opname uji', $actor]);
    $opname = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO stock_opname_items
            (opname_id, sparepart_id, system_stock, physical_stock, difference, reason, counted_by, counted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$opname, $spA, 1, 0, -1, 'Selisih uji', $actor, '2024-02-13 11:00:00']);

    $insertMovement = $pdo->prepare(
        'INSERT INTO stock_movements
            (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after,
             reference_type, reference_id, reference_number, note, user_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertMovement->execute([$spA, 'MASUK', 2, '2024-02-11', 0, 2, 'GOODS_RECEIPT', $receipt, $receiptNumber, 'Masuk uji', $actor, '2024-02-11 12:00:00']);
    $insertMovement->execute([$spA, 'KELUAR', -1, '2024-02-12', 2, 1, 'GOODS_ISSUE', $issue, $issueNumber, 'Keluar uji', $actor, '2024-02-12 12:00:00']);
    $insertMovement->execute([$spC, 'KELUAR', -12, '2024-02-12', 32, 20, null, null, 'UJI-' . $p, 'Pemakaian uji', $actor, '2024-02-12 13:00:00']);
    $today = date('Y-m-d');
    $insertMovement->execute([$spA, 'MASUK', 4, $today, 1, 5, null, null, 'HARI-' . $p, 'Masuk hari ini', $actor, date('Y-m-d H:i:s')]);
    $insertMovement->execute([$spA, 'KELUAR', -2, $today, 5, 3, null, null, 'HARI-' . $p, 'Keluar hari ini', $actor, date('Y-m-d H:i:s')]);
    $insertMovement->execute([$spC, 'KELUAR', -12, $today, 32, 20, null, null, 'HARI-' . $p, 'Pemakaian hari ini', $actor, date('Y-m-d H:i:s')]);

    $pdo->prepare(
        'INSERT INTO purchase_payment_history (purchase_order_id, status, note, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$po1, 'BELUM_DIBAYAR', 'Belum ada pembayaran', $actor, '2024-02-10 10:00:00']);
    $pdo->prepare(
        'INSERT INTO purchase_payment_history (purchase_order_id, status, note, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$po2, 'DIBAYAR_SEBAGIAN', 'DP diterima', $actor, '2024-02-09 11:00:00']);

    $pdo->prepare(
        'INSERT INTO reorder_requests
            (request_number, sparepart_id, suggested_qty, requested_qty, change_reason,
             status, supplier_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$p . '-RR', $spA, 5, 6, 'Kebutuhan bengkel', 'MENUNGGU_PERSETUJUAN', $supplier, $actor]);

    return $fixture = [
        'p' => $p,
        'actor' => $actor,
        'category' => $category,
        'supplier' => $supplier,
        'supplier_code' => (string) ($supplierData['code'] ?? ''),
        'supplier_name' => (string) ($supplierData['name'] ?? ''),
        'spA' => $spA, 'spB' => $spB, 'spC' => $spC, 'spD' => $spD,
        'po1' => $po1, 'po2' => $po2, 'poItem1' => $poItem1,
        'receipt' => $receipt, 'receipt_number' => $receiptNumber,
        'issue' => $issue, 'issue_number' => $issueNumber,
        'opname' => $opname, 'opname_number' => $opnameNumber,
    ];
}

/** @param array<int,array{label:string,value:string,raw?:mixed}> $summary */
function t_f5_summary(array $summary, string $label): mixed
{
    foreach ($summary as $item) {
        if (($item['label'] ?? '') === $label) {
            return $item['raw'] ?? $item['value'] ?? null;
        }
    }
    return null;
}

/** @param array<int,array<string,mixed>> $rows */
function t_f5_row(array $rows, string $key, string $value): ?array
{
    foreach ($rows as $row) {
        if ((string) ($row[$key] ?? '') === $value) {
            return $row;
        }
    }
    return null;
}

/**
 * Ganti sementara nilai app_settings; kembalikan nilai lama untuk restorasi.
 *
 * @param array<string,string> $values
 * @return array<string,string>
 */
function t_f5_set_settings(array $values): array
{
    $old = AppSetting::all();
    AppSetting::putMany($values, null);
    return $old;
}

/** @param array<string,string> $old */
function t_f5_restore_settings(array $old): void
{
    Database::pdo()->exec('DELETE FROM app_settings');
    if ($old !== []) {
        AppSetting::putMany($old, null);
    }
}

function test_report_registry_dan_metadata(): void
{
    $types = ReportService::TYPES;
    t_eq(12, count($types), 'Ada 12 jenis laporan Fase 5');
    t_eq($types, array_column(ReportService::allMeta(), 'key'), 'allMeta() mengembalikan semua jenis berurutan');
    t_eq(5000, ReportService::MAX_ROWS, 'Batas tampilan laporan 5.000 baris');

    foreach ($types as $type) {
        $meta = ReportService::meta($type);
        t_true(($meta['title'] ?? '') !== '', "Metadata {$type} memiliki judul");
        t_true(($meta['description'] ?? '') !== '', "Metadata {$type} memiliki deskripsi");
        t_true(($meta['columns'] ?? []) !== [], "Metadata {$type} memiliki kolom");
        t_true(in_array($meta['orientation'] ?? '', ['portrait', 'landscape'], true), "Orientasi {$type} valid");
        t_true(is_array($meta['filters'] ?? null), "Metadata {$type} memiliki definisi filter");
    }

    t_eq('Habis', ReportService::label('stock', 'habis'), 'Label status stok diterjemahkan');
    t_eq('Lunas', ReportService::label('payment', 'LUNAS'), 'Label pembayaran diterjemahkan');
    t_eq('danger', ReportService::badgeClass('stock', 'kritis'), 'Badge kritis berwarna danger');
    t_eq('-', ReportService::formatCell(['type' => 'money'], null), 'Sel kosong ditampilkan sebagai -');
    t_eq('1,50', ReportService::formatCell(['type' => 'decimal'], 1.5), 'Desimal memakai koma dan dua digit');
    t_eq('Rp 10.000', ReportService::formatCell(['type' => 'money'], 10000), 'Uang diformat Rupiah');

    t_throws(
        static fn () => ReportService::build('tidak_ada'),
        RuntimeException::class,
        'Jenis laporan tak dikenal ditolak'
    );
}

function test_report_semua_jenis_dapat_dibangun(): void
{
    $f = t_f5_fixture();
    $p = $f['p'];

    foreach (ReportService::TYPES as $type) {
        $search = match ($type) {
            'ai' => '',
            'supplier' => $f['supplier_code'],
            default => $p,
        };
        try {
            $report = ReportService::build($type, [
                'search' => $search,
                'date_from' => '2024-02-09',
                'date_to' => '2024-02-13',
            ]);
        } catch (Throwable $e) {
            t_true(false, "Laporan {$type} gagal dibangun: " . $e->getMessage());
            continue;
        }

        t_true(true, "Laporan {$type} berhasil dibangun");
        t_eq($type, $report['type'], "Jenis laporan {$type} tercatat");
        t_true(array_key_exists('rows', $report), "Laporan {$type} memiliki baris");
        t_true(array_key_exists('summary', $report), "Laporan {$type} memiliki ringkasan");
        t_true(array_key_exists('filter_text', $report), "Laporan {$type} memiliki teks filter");
        t_true(($report['filter_text'] ?? []) !== [], "Filter {$type} dijelaskan");

        if (is_array($report['chart'])) {
            $chart = $report['chart'];
            $labelCount = count($chart['labels'] ?? []);
            t_true($labelCount > 0, "Grafik {$type} memiliki label bila ada data");
            foreach (($chart['series'] ?? []) as $series) {
                t_eq($labelCount, count($series['values'] ?? []), "Panjang seri grafik {$type} sama dengan label");
            }
        } elseif ($type !== 'ai') {
            t_true(false, "Grafik {$type} seharusnya berupa array");
        }
    }
}

function test_report_stok_nilai_dan_filter_status(): void
{
    $f = t_f5_fixture();
    $report = ReportService::build('stok', ['search' => $f['p'] . '-A']);
    t_eq(1, count($report['rows']), 'Pencarian kode mengembalikan satu sparepart');
    $row = $report['rows'][0];
    t_eq(1.0, (float) $row['stock'], 'Stok sparepart sesuai database');
    t_eq('kritis', (string) $row['stock_status'], 'Status kritis dihitung dari stok < minimum');
    t_eq(10000.0, (float) $row['value_last'], 'Nilai harga terakhir = stok x harga terakhir');
    t_eq(9000.0, (float) $row['value_avg'], 'Nilai rata-rata = stok x harga rata-rata');
    t_eq(1.0, (float) t_f5_summary($report['summary'], 'Total stok'), 'Ringkasan total stok sesuai baris');
    t_eq(10000.0, (float) t_f5_summary($report['summary'], 'Nilai harga terakhir'), 'Ringkasan nilai harga terakhir');
    t_eq(9000.0, (float) t_f5_summary($report['summary'], 'Nilai rata-rata tertimbang'), 'Ringkasan nilai rata-rata');

    $notes = implode(' ', $report['notes']);
    t_contains('weighted average', $notes, 'Catatan menjelaskan metode nilai persediaan');
    t_contains('harga beli terakhir', $notes, 'Catatan menyebut fallback harga terakhir');

    $filtered = ReportService::build('stok', ['search' => $f['p'], 'status' => 'kritis']);
    $codes = array_column($filtered['rows'], 'code');
    t_true(in_array($f['p'] . '-A', $codes, true), 'Filter status kritis memuat sparepart kritis');
    t_true(!in_array($f['p'] . '-C', $codes, true), 'Filter status kritis mengecualikan sparepart aman');

    $inactive = ReportService::build('stok', ['search' => $f['p'], 'active' => 'nonaktif']);
    t_eq([$f['p'] . '-D'], array_column($inactive['rows'], 'code'), 'Filter status data nonaktif bekerja');

    // Tanggal tidak relevan untuk laporan stok dan harus diabaikan.
    $ignored = ReportService::build('stok', [
        'search' => $f['p'] . '-A', 'date_from' => '2099-01-01', 'date_to' => '2099-01-02',
    ]);
    t_eq(1, count($ignored['rows']), 'Laporan stok mengabaikan filter tanggal');
    t_eq(null, $ignored['filters']['date_from'], 'Filter tanggal tak relevan dinormalisasi menjadi null');
}

function test_report_filter_tanggal_pencarian_dan_status(): void
{
    $f = t_f5_fixture();
    $p = $f['p'];

    $masuk = ReportService::build('masuk', ['search' => $p, 'date_from' => '2024-02-11', 'date_to' => '2024-02-11']);
    t_eq(1, count($masuk['rows']), 'Filter tanggal barang masuk tepat satu hari');
    t_eq($f['receipt_number'], (string) $masuk['rows'][0]['receipt_number'], 'Dokumen masuk sesuai fixture');
    t_eq(2.0, (float) t_f5_summary($masuk['summary'], 'Total qty masuk'), 'Qty masuk dijumlahkan dari item');
    t_eq(1, (int) t_f5_summary($masuk['summary'], 'Dokumen penerimaan'), 'Jumlah dokumen masuk dihitung');

    $diLuar = ReportService::build('masuk', ['search' => $p, 'date_from' => '2024-02-12', 'date_to' => '2024-02-12']);
    t_eq(0, count($diLuar['rows']), 'Tanggal di luar data menghasilkan nol baris');

    $terbalik = ReportService::build('masuk', ['search' => $p, 'date_from' => '2024-02-12', 'date_to' => '2024-02-11']);
    t_eq('2024-02-11', (string) $terbalik['filters']['date_from'], 'Rentang tanggal terbalik ditukar otomatis');
    t_eq('2024-02-12', (string) $terbalik['filters']['date_to'], 'Rentang tanggal terbalik ditukar otomatis');
    t_eq(1, count($terbalik['rows']), 'Rentang tanggal terbalik tetap menemukan data');

    $invalid = ReportService::build('masuk', ['search' => $p, 'date_from' => '2024-02-31']);
    t_eq(null, $invalid['filters']['date_from'], 'Tanggal tidak valid diabaikan');

    $keluar = ReportService::build('keluar', ['search' => $p, 'usage' => 'service']);
    t_eq(1, count($keluar['rows']), 'Filter jenis pemakaian servis bekerja');
    t_eq(1.0, (float) t_f5_summary($keluar['summary'], 'Qty servis'), 'Qty servis dijumlahkan');
    $penjualan = ReportService::build('keluar', ['search' => $p, 'usage' => 'penjualan']);
    t_eq(0, count($penjualan['rows']), 'Filter jenis pemakaian penjualan tidak mengambil data servis');

    $minimum = ReportService::build('stok_minimum', ['search' => $p]);
    t_eq(2, count($minimum['rows']), 'Stok minimum hanya memuat sparepart aktif bermasalah');
    t_eq(1, (int) t_f5_summary($minimum['summary'], 'Habis'), 'Satu sparepart habis');
    t_eq(1, (int) t_f5_summary($minimum['summary'], 'Kritis'), 'Satu sparepart kritis');
    $habis = ReportService::build('stok_minimum', ['search' => $p, 'status' => 'habis']);
    t_eq([$p . '-B'], array_column($habis['rows'], 'code'), 'Filter status habis bekerja');

    $opname = ReportService::build('opname', ['search' => $p, 'status' => 'OPEN']);
    t_eq(1, count($opname['rows']), 'Laporan opname menemukan sesi OPEN');
    t_eq(1.0, (float) t_f5_summary($opname['summary'], 'Total selisih absolut'), 'Selisih absolut opname dihitung');
    $konfirmasi = ReportService::build('opname', ['search' => $p, 'status' => 'CONFIRMED']);
    t_eq(0, count($konfirmasi['rows']), 'Filter opname CONFIRMED tidak mengambil sesi OPEN');

    $riwayat = ReportService::build('riwayat', [
        'search' => $p . '-A', 'date_from' => '2024-02-10', 'date_to' => '2024-02-12', 'status' => 'MASUK',
    ]);
    t_eq(1, count($riwayat['rows']), 'Riwayat memfilter jenis dan tanggal');
    t_eq(2.0, (float) t_f5_summary($riwayat['summary'], 'Total qty masuk'), 'Ringkasan riwayat menjumlahkan qty masuk');

    $supplier = ReportService::build('supplier', [
        'search' => $f['supplier_code'], 'date_from' => '2024-02-01', 'date_to' => '2024-02-29',
    ]);
    t_eq(1, count($supplier['rows']), 'Laporan supplier memfilter supplier dan periode');
    t_eq(1, (int) $supplier['rows'][0]['receipts'], 'Jumlah dokumen pembelian per supplier dihitung');
    t_eq(2.0, (float) $supplier['rows'][0]['qty'], 'Qty pembelian per supplier dijumlahkan');

    $pembelian = ReportService::build('pembelian', [
        'search' => $p, 'status' => 'MENUNGGU_PERSETUJUAN', 'payment' => 'BELUM_DIBAYAR',
    ]);
    t_eq(1, count($pembelian['rows']), 'Laporan pembelian memfilter status PO dan pembayaran');
    t_eq($p . '-PO1', (string) $pembelian['rows'][0]['po_number'], 'PO yang sesuai filter ditemukan');

    $penerimaan = ReportService::build('penerimaan_po', ['search' => $p]);
    t_eq(1, count($penerimaan['rows']), 'Laporan penerimaan PO menautkan item PO');
    t_eq(5.0, (float) $penerimaan['rows'][0]['po_qty'], 'Qty PO terbaca');
    t_eq(3.0, (float) $penerimaan['rows'][0]['remaining'], 'Sisa qty PO dihitung dari qty diterima');

    $pembayaran = ReportService::build('pembayaran', ['search' => $p, 'status' => 'BELUM_DIBAYAR']);
    t_eq(1, count($pembayaran['rows']), 'Laporan pembayaran memfilter status');
    t_eq($p . '-PO1', (string) $pembayaran['rows'][0]['po_number'], 'PO belum dibayar ditemukan');

    $ai = ReportService::build('ai');
    $aiSummary = implode(' ', array_map(static fn (array $item): string => (string) $item['value'], $ai['summary']));
    $aiActive = AiPrediction::summary()['total'] > 0;
    t_contains(
        $aiActive ? 'Aktif' : 'Belum pernah dijalankan',
        $aiSummary,
        'Laporan AI menandai status modul prediksi sesuai isi tabel prediksi'
    );
    t_contains(
        'usulan',
        implode(' ', $ai['notes']),
        'Laporan AI menegaskan rekomendasi adalah usulan, bukan pesanan otomatis'
    );
    t_contains(
        'data lampau',
        implode(' ', $ai['notes']),
        'Laporan AI mencatat model hanya memakai data lampau'
    );
}

function test_report_fast_slow_mengikuti_pengaturan(): void
{
    $f = t_f5_fixture();
    $old = t_f5_set_settings([
        'fast_moving_threshold' => '10',
        'slow_moving_threshold' => '2',
        'moving_period_days' => '30',
    ]);

    try {
        $report = ReportService::build('fast_slow', [
            'search' => $f['p'], 'date_from' => '2024-02-10', 'date_to' => '2024-02-12',
        ]);
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[(string) $row['code']] = $row;
        }
        t_eq('slow', (string) ($rows[$f['p'] . '-A']['classification'] ?? ''), 'Pemakaian 1 qty masuk kategori lambat');
        t_eq('slow', (string) ($rows[$f['p'] . '-B']['classification'] ?? ''), 'Tanpa pemakaian masuk kategori lambat');
        t_eq('fast', (string) ($rows[$f['p'] . '-C']['classification'] ?? ''), 'Pemakaian 12 qty masuk kategori cepat');
        t_eq(1, (int) t_f5_summary($report['summary'], 'Cepat (fast moving)'), 'Ringkasan menghitung kategori cepat');
        t_eq(2, (int) t_f5_summary($report['summary'], 'Lambat (slow moving)'), 'Ringkasan menghitung kategori lambat');
        t_contains('cepat ≥ 10', implode(' ', $report['filter_text']), 'Filter menjelaskan ambang cepat');
        t_contains('lambat ≤ 2', implode(' ', $report['filter_text']), 'Filter menjelaskan ambang lambat');

        $fast = ReportService::build('fast_slow', [
            'search' => $f['p'], 'date_from' => '2024-02-10', 'date_to' => '2024-02-12', 'classification' => 'fast',
        ]);
        t_eq([$f['p'] . '-C'], array_column($fast['rows'], 'code'), 'Filter klasifikasi cepat bekerja');

        // Tanpa tanggal: filter harus menampilkan periode default, bukan "semua tanggal".
        $default = ReportService::build('fast_slow', ['search' => $f['p']]);
        $text = implode(' ', $default['filter_text']);
        t_true(!str_contains($text, 'semua tanggal'), 'Periode default fast/slow tidak dilaporkan sebagai semua tanggal');
        t_contains('Aturan: 30 hari', $text, 'Periode default 30 hari dicantumkan');
    } finally {
        t_f5_restore_settings($old);
    }

    $old2 = t_f5_set_settings([
        'fast_moving_threshold' => '5',
        'slow_moving_threshold' => '1',
        'moving_period_days' => '7',
    ]);
    try {
        $report = ReportService::build('fast_slow', [
            'search' => $f['p'], 'date_from' => '2024-02-10', 'date_to' => '2024-02-12',
        ]);
        $byCode = [];
        foreach ($report['rows'] as $row) {
            $byCode[(string) $row['code']] = $row['classification'];
        }
        t_eq('fast', (string) ($byCode[$f['p'] . '-C'] ?? ''), 'Ambang cepat baru diterapkan pada laporan');
        t_eq('slow', (string) ($byCode[$f['p'] . '-A'] ?? ''), 'Ambang lambat baru diterapkan pada laporan');
        t_contains('cepat ≥ 5', implode(' ', $report['filter_text']), 'Aturan baru tercantum pada filter');
    } finally {
        t_f5_restore_settings($old2);
    }
}

function test_report_ekspor_baris_dan_pdf_options(): void
{
    $f = t_f5_fixture();
    $report = ReportService::build('stok', ['search' => $f['p'] . '-A']);
    $export = ReportService::exportRows($report);

    t_eq((string) $report['meta']['title'], (string) $export[0][0], 'Baris pertama ekspor adalah judul laporan');
    $flat = implode("\n", array_map(static fn (array $row): string => implode(' | ', array_map('strval', $row)), $export));
    t_contains('Periode:', $flat, 'Ekspor memuat baris filter');
    t_contains('Nilai rata-rata tertimbang', $flat, 'Ekspor memuat ringkasan');
    t_contains('Kode', $flat, 'Ekspor memuat header kolom');
    t_contains($f['p'] . '-A', $flat, 'Ekspor memuat data');
    t_contains('weighted average', $flat, 'Ekspor memuat catatan metode');

    $pdf = ReportService::pdfOptions($report, [
        'name' => 'Bengkel Uji', 'address' => 'Jl. Uji', 'phone' => '021-1', 'email' => 'uji@example.test',
    ]);
    t_eq('Laporan Stok Keseluruhan', (string) $pdf['title'], 'Opsi PDF memakai judul laporan');
    t_eq('landscape', (string) $pdf['orientation'], 'Opsi PDF memakai orientasi laporan');
    t_eq(count($report['meta']['columns']), count($pdf['columns']), 'Opsi PDF memuat semua kolom');
    t_eq(1, count($pdf['rows']), 'Opsi PDF memuat baris laporan');
    t_contains('Bengkel Uji', (string) $pdf['footer_left'], 'Footer PDF memuat identitas bengkel');
    t_contains('Filter & catatan', (string) $pdf['notes_title'], 'Opsi PDF memberi judul catatan');
}

function test_report_pdf_struktur_dan_halaman(): void
{
    $identity = ['name' => 'Bengkel Uji', 'address' => 'Jl. Uji 1', 'phone' => '021-1', 'email' => 'uji@example.test'];
    $small = PdfService::report([
        'identity' => $identity,
        'title' => 'Laporan Uji',
        'subtitle' => 'Periode: 01/02/2024 – 28/02/2024',
        'summary' => [['label' => 'Jumlah', 'value' => '1']],
        'columns' => [
            ['label' => 'Kode', 'width' => 20],
            ['label' => 'Nama', 'width' => 50],
            ['label' => 'Qty', 'width' => 15, 'align' => 'right'],
        ],
        'rows' => [['SP-1', 'Sparepart contoh dengan nama panjang untuk uji pembungkusan teks', '10']],
        'notes' => ['Catatan uji'],
        'orientation' => 'portrait',
    ]);

    t_true(str_starts_with($small, '%PDF-1.4'), 'PDF laporan diawali header PDF 1.4');
    t_true(str_contains($small, '%%EOF'), 'PDF laporan diakhiri EOF');
    t_contains('/Type /Pages', $small, 'PDF memiliki objek Pages');
    t_contains('595.28 841.89', $small, 'PDF potret memakai ukuran A4');

    $rows = [];
    for ($i = 1; $i <= 180; $i++) {
        $rows[] = [sprintf('SP-%03d', $i), 'Nama sparepart ' . $i, (string) ($i * 2)];
    }
    $large = PdfService::report([
        'identity' => $identity,
        'title' => 'Laporan Banyak Baris',
        'columns' => [
            ['label' => 'Kode', 'width' => 20],
            ['label' => 'Nama', 'width' => 50],
            ['label' => 'Qty', 'width' => 15, 'align' => 'right'],
        ],
        'rows' => $rows,
        'orientation' => 'landscape',
    ]);
    preg_match('/\/Count (\d+)/', $large, $matches);
    t_true((int) ($matches[1] ?? 0) > 1, 'PDF laporan panjang memiliki lebih dari satu halaman');
    t_contains('841.89 595.28', $large, 'PDF lanskap memakai ukuran A4 lanskap');
    t_contains('lanjutan', $large, 'Judul halaman lanjutan tersedia untuk laporan panjang');
    preg_match('/startxref\n(\d+)\n%%EOF/', $large, $xref);
    t_eq('xref', substr($large, (int) ($xref[1] ?? 0), 4), 'Offset xref PDF menunjuk tabel xref yang benar');

    t_throws(
        static fn () => PdfService::report(['identity' => $identity, 'title' => 'Kosong', 'columns' => [], 'rows' => []]),
        RuntimeException::class,
        'PDF tanpa kolom ditolak'
    );
}

function test_report_excel_dapat_dibaca(): void
{
    $f = t_f5_fixture();
    $report = ReportService::build('stok', ['search' => $f['p'] . '-A']);
    $rows = ReportService::exportRows($report);
    $binary = XlsxWriter::build($rows, 'Stok Fase 5');

    t_true(str_starts_with($binary, "PK\x03\x04"), 'Berkas Excel laporan berformat ZIP');
    $path = t_temp_file('xlsx', $binary);
    try {
        $read = Spreadsheet::readFile($path, 'xlsx');
        t_eq('Laporan Stok Keseluruhan', (string) $read['headers'][0], 'Judul laporan terbaca sebagai header Excel');
        $flat = implode("\n", array_map(static fn (array $row): string => implode(' | ', array_map('strval', $row)), $read['rows']));
        t_contains('Laporan Stok Keseluruhan', implode(' | ', $read['headers']) . "\n" . $flat, 'Judul laporan terbaca dari Excel');
        t_contains('Kode', $flat, 'Header kolom terbaca dari Excel');
        t_contains($f['p'] . '-A', $flat, 'Data laporan terbaca dari Excel');
        t_contains('weighted average', $flat, 'Catatan metode terbaca dari Excel');
    } finally {
        @unlink($path);
    }

    $zip = new ZipArchive();
    $zipPath = t_temp_file('xlsx', $binary);
    t_eq(true, $zip->open($zipPath), 'Arsip XLSX dapat dibuka');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    t_contains('Stok Fase 5', $workbook, 'Nama sheet laporan tercatat di workbook');
    $zip->close();
    @unlink($zipPath);
}

function test_report_route_dan_hak_akses(): void
{
    $readRoutes = [
        'GET /laporan',
        'GET /laporan/{jenis}',
        'GET /laporan/{jenis}/cetak',
        'GET /laporan/{jenis}/pdf',
        'GET /laporan/{jenis}/excel',
        'GET /laporan/po/{id}/cetak',
        'GET /laporan/po/{id}/pdf',
        'GET /transaksi/masuk/{id}/cetak',
        'GET /transaksi/keluar/{id}/cetak',
    ];
    foreach ($readRoutes as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], App\Middleware\AuthMiddleware::class), "Route {$key} wajib login");
        t_true(
            !t_rbac_has_middleware($route['middleware'], App\Middleware\RoleMiddleware::class),
            "Route {$key} dapat diakses semua role"
        );
    }

    foreach (['GET /pengaturan', 'POST /pengaturan'] as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], App\Middleware\AuthMiddleware::class), "Route {$key} wajib login");
        t_true(
            t_rbac_has_middleware($route['middleware'], App\Middleware\RoleMiddleware::class, 'admin'),
            "Route {$key} hanya untuk Admin"
        );
    }

    $router = new Router();
    $register = require BASE_PATH . '/routes/web.php';
    $register($router);

    $poPrint = $router->match('GET', '/laporan/po/12/cetak');
    t_eq(
        [App\Controllers\ReportController::class, 'printPo'],
        $poPrint['route']['handler'] ?? null,
        'Rute cetak PO tidak tertukar dengan cetak laporan biasa'
    );
    t_eq(['id' => '12'], $poPrint['params'] ?? null, 'Parameter id PO terbaca');

    $pdf = $router->match('GET', '/laporan/stok/pdf');
    t_eq(
        [App\Controllers\ReportController::class, 'pdf'],
        $pdf['route']['handler'] ?? null,
        'Rute PDF laporan cocok dengan handler PDF'
    );

    $report = $router->match('GET', '/laporan/fast_slow');
    t_eq(
        [App\Controllers\ReportController::class, 'show'],
        $report['route']['handler'] ?? null,
        'Rute laporan biasa cocok dengan handler tampilan'
    );
}

function test_report_tampilan_dan_cetak(): void
{
    $f = t_f5_fixture();
    $report = ReportService::build('stok', ['search' => $f['p'] . '-A']);
    $options = ReportService::filterOptions();
    $identity = ['name' => 'Bengkel Uji', 'address' => 'Jl. Uji 1', 'phone' => '021-1', 'email' => 'uji@example.test', 'note' => ''];

    try {
        $html = View::render('reports/show', [
            'title' => $report['meta']['title'],
            'report' => $report,
            'categories' => $options['categories'],
            'suppliers' => $options['suppliers'],
            'identity' => $identity,
            'query' => 'search=' . urlencode($f['p'] . '-A'),
        ], 'layouts/app');
    } catch (Throwable $e) {
        t_true(false, 'View laporan gagal dirender: ' . $e->getMessage());
        $html = '';
    }

    t_true($html !== '', 'View laporan berhasil dirender');
    t_true(!str_contains($html, '<?php'), 'View laporan tidak membocorkan tag PHP');
    t_contains('Laporan Stok Keseluruhan', $html, 'Judul laporan tampil');
    t_contains('Filter aktif', $html, 'Filter aktif dijelaskan pada tampilan');
    t_contains('Nilai rata-rata tertimbang', $html, 'Ringkasan nilai tampil');
    t_contains('Cetak', $html, 'Tombol cetak tersedia');
    t_contains('/laporan/stok/pdf', $html, 'Tautan PDF tersedia');
    t_contains('/laporan/stok/excel', $html, 'Tautan Excel tersedia');
    t_contains('table-responsive', $html, 'Tabel laporan responsif di layar kecil');
    t_contains($f['p'] . '-A', $html, 'Data laporan tampil pada tabel');

    $ai = ReportService::build('ai');
    $aiActive = AiPrediction::summary()['total'] > 0;
    $aiHtml = View::render('reports/show', [
        'title' => $ai['meta']['title'],
        'report' => $ai,
        'categories' => [],
        'suppliers' => [],
        'identity' => $identity,
        'query' => '',
    ], 'layouts/app');
    t_contains(
        $aiActive ? 'Modul prediksi aktif' : 'Modul prediksi belum dijalankan',
        $aiHtml,
        'Tampilan laporan AI menjelaskan status modul prediksi sesuai data'
    );
    t_contains(
        $aiActive ? 'pipeline Python' : 'Jalankan analisis',
        $aiHtml,
        'Tampilan laporan AI memberi keterangan/arahan prediksi'
    );

    $print = View::render('reports/print', [
        'title' => $report['meta']['title'],
        'report' => $report,
        'identity' => $identity,
        'orientation' => 'landscape',
        'backUrl' => '#',
        'pdfUrl' => '/laporan/stok/pdf',
    ], 'layouts/print');
    t_true(!str_contains($print, '<?php'), 'View cetak tidak membocorkan tag PHP');
    t_contains('Bengkel Uji', $print, 'Kop cetak memakai identitas bengkel');
    t_contains('Laporan Stok Keseluruhan', $print, 'Judul laporan tercetak');
    t_contains('print-landscape-sheet', $print, 'Layout cetak menandai orientasi lanskap');
    t_contains('table', $print, 'Tabel laporan tercetak');
    t_contains('Catatan &amp; definisi', $print, 'Catatan definisi ikut tercetak');
    t_contains('window.print()', $print, 'Tombol cetak browser tersedia');
}
