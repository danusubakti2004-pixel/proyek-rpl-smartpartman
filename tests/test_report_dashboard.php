<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\View;
use App\Models\AiPrediction;
use App\Models\Analytics;
use App\Models\Sparepart;
use App\Services\DashboardService;
use App\Services\NotificationService;
use App\Services\SettingService;

/**
 * Pengujian Fase 5 — dashboard per role, angka vs query database,
 * notifikasi, dan tampilan responsif.
 */

function t_dash_scalar(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function test_dashboard_partman_angka_dan_tampilan(): void
{
    $f = t_f5_fixture();
    $d = DashboardService::partman();

    t_eq(['summary', 'stock_stats', 'alerts', 'chart', 'latest', 'ai', 'daily', 'prediction'], array_keys($d), 'Dashboard Partman memiliki bagian lengkap');

    $types = (int) t_dash_scalar('SELECT COUNT(*) FROM spareparts WHERE is_active = 1');
    $stock = (float) t_dash_scalar('SELECT COALESCE(SUM(stock), 0) FROM spareparts WHERE is_active = 1');
    t_eq($types, (int) $d['summary']['types'], 'Jumlah jenis sparepart sesuai query database');
    t_eq($stock, (float) $d['summary']['stock'], 'Total stok sesuai query database');

    $stats = $d['stock_stats'];
    t_eq($types, (int) $stats['total'], 'Total status stok sama dengan jumlah sparepart aktif');
    t_eq(
        (int) $stats['habis'] + (int) $stats['kritis'] + (int) $stats['menipis'] + (int) $stats['aman'] + (int) $stats['tanpa_minimum'],
        (int) $stats['total'],
        'Jumlah semua kategori status stok konsisten'
    );

    $masuk = (float) t_dash_scalar(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'MASUK' THEN quantity ELSE 0 END), 0)
           FROM stock_movements WHERE movement_date = CURDATE()"
    );
    $keluar = (float) t_dash_scalar(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'KELUAR' THEN -quantity ELSE 0 END), 0)
           FROM stock_movements WHERE movement_date = CURDATE()"
    );
    t_eq($masuk, (float) $d['summary']['masuk_qty'], 'Qty masuk hari ini sesuai ledger');
    t_eq($keluar, (float) $d['summary']['keluar_qty'], 'Qty keluar hari ini sesuai ledger');
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM goods_receipts WHERE receipt_date = CURDATE()'),
        (int) $d['summary']['receipts'],
        'Jumlah dokumen masuk hari ini sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM goods_issues WHERE issue_date = CURDATE()'),
        (int) $d['summary']['issues'],
        'Jumlah dokumen keluar hari ini sesuai database'
    );

    t_eq(14, count($d['chart']['labels']), 'Grafik Partman memuat 14 hari');
    t_eq(14, count($d['chart']['masuk']), 'Seri masuk grafik lengkap');
    t_eq(14, count($d['chart']['keluar']), 'Seri keluar grafik lengkap');

    t_true(count($d['latest']) <= 8, 'Transaksi terbaru dibatasi 8 baris');
    if ($d['latest'] !== []) {
        foreach (['kind', 'id', 'number', 'doc_date', 'party', 'qty', 'actor'] as $key) {
            t_true(array_key_exists($key, $d['latest'][0]), "Transaksi terbaru memiliki field {$key}");
        }
    }

    t_eq(
        AiPrediction::summary()['total'] > 0,
        $d['ai']['ready'],
        'Status modul AI mengikuti isi tabel prediksi (bukan lagi placeholder Fase 5)'
    );
    t_true((int) $d['ai']['pending_reorder'] >= 1, 'Permintaan reorder menunggu tercatat');
    t_true(array_key_exists('actions', $d['daily']), 'Ringkasan aktivitas harian tersedia');
    t_true(array_key_exists('users', $d['daily']), 'Ringkasan pengguna aktif tersedia');

    $user = ['full_name' => 'Partman Uji', 'role_name' => 'Partman'];
    $html = View::render('dashboard/index', [
        'title' => 'Dashboard',
        'user' => $user,
        'role' => 'partman',
        'dashboard' => $d,
        'notifications' => NotificationService::forRole('partman'),
        'identity' => SettingService::identity(),
    ], 'layouts/app');
    t_true(!str_contains($html, '<?php'), 'Dashboard Partman tidak membocorkan tag PHP');
    t_contains('Total jenis sparepart', $html, 'Kartu total jenis sparepart tampil');
    t_contains('Barang masuk hari ini', $html, 'Kartu barang masuk hari ini tampil');
    t_contains('Grafik pergerakan stok', $html, 'Grafik pergerakan stok tampil');
    t_contains('Perlu perhatian', $html, 'Daftar stok perlu perhatian tampil');
    t_contains('Rekomendasi reorder', $html, 'Bagian rekomendasi reorder tampil');
    t_contains('Prediksi AI', $html, 'Status prediksi AI dinyatakan eksplisit pada dashboard Partman');
    t_contains('Transaksi terbaru', $html, 'Tabel transaksi terbaru tampil');
    t_contains('Ringkasan aktivitas hari ini', $html, 'Ringkasan aktivitas tampil');
    t_contains('table-responsive', $html, 'Tabel dashboard responsif');
}

function test_dashboard_kepala_bengkel_angka_dan_tampilan(): void
{
    $f = t_f5_fixture();
    $d = DashboardService::kepala();

    foreach (['summary', 'stock_stats', 'alerts', 'top_used', 'least_used', 'period', 'chart', 'month', 'po', 'payment', 'ai'] as $key) {
        t_true(array_key_exists($key, $d), "Dashboard Kepala memiliki bagian {$key}");
    }

    $valueAvg = (float) t_dash_scalar(
        'SELECT COALESCE(SUM(stock * COALESCE(avg_purchase_price, purchase_price)), 0)
           FROM spareparts WHERE is_active = 1'
    );
    t_eq($valueAvg, (float) $d['summary']['value_avg'], 'Nilai persediaan sesuai query weighted average');
    t_eq((int) $d['period']['days'], SettingService::reportConfig()['period_days'], 'Periode analisis mengikuti pengaturan');

    $used = array_map(static fn (array $row): float => (float) $row['used_qty'], $d['top_used']);
    $sorted = $used;
    rsort($sorted);
    t_eq($sorted, $used, 'Paling sering digunakan terurut menurun');
    $least = array_map(static fn (array $row): float => (float) $row['used_qty'], $d['least_used']);
    $sortedLeast = $least;
    sort($sortedLeast);
    t_eq($sortedLeast, $least, 'Paling jarang digunakan terurut menaik');

    // Peringkat top-5 dashboard dibatasi, sehingga sparepart fixture tidak
    // harus selalu berada di lima besar bila database uji berisi sisa
    // fixture dari pengujian sebelumnya. Verifikasi peringkat lebar memakai
    // periode yang sama, lalu pastikan dashboard tetap terurut & maksimal 5.
    t_true(count($d['top_used']) <= 5, 'Peringkat pemakaian tertinggi dibatasi 5 baris');
    $periodFrom = (string) $d['period']['from'];
    $periodTo = (string) $d['period']['to'];
    $ranking = Analytics::usageRanking($periodFrom, $periodTo, 50, true);
    $fast = array_values(array_filter($ranking, static fn (array $row): bool => (string) $row['code'] === $f['p'] . '-C'));
    t_true($fast !== [], 'Sparepart dengan pemakaian tertinggi muncul pada ranking');
    if ($fast !== []) {
        t_true((float) $fast[0]['used_qty'] >= 12.0, 'Qty pemakaian ranking sesuai ledger');
    }
    $usedInPeriod = (float) t_dash_scalar(
        'SELECT COALESCE(SUM(-m.quantity), 0)
           FROM stock_movements m
           JOIN spareparts s ON s.id = m.sparepart_id
          WHERE s.code = ? AND m.movement_type = \'KELUAR\'
            AND m.movement_date BETWEEN ? AND ?',
        [$f['p'] . '-C', $periodFrom, $periodTo]
    );
    t_true($usedInPeriod >= 12.0, 'Pemakaian 12 qty tercatat pada ledger untuk periode dashboard');

    t_eq(30, count($d['chart']['labels']), 'Grafik Kepala memuat 30 hari');
    foreach (['purchase', 'issue', 'opname'] as $key) {
        t_true(array_key_exists($key, $d['month']), "Ringkasan bulan ini memiliki {$key}");
    }
    t_true(isset($d['po']['MENUNGGU_PERSETUJUAN']['count']), 'Ringkasan status PO tersedia');
    t_true(isset($d['payment']['BELUM_DIBAYAR']['amount']), 'Ringkasan nilai pembayaran tersedia');
    t_eq(AiPrediction::summary()['total'] > 0, $d['ai']['ready'], 'Dashboard Kepala menandai status AI sesuai isi tabel prediksi');

    $html = View::render('dashboard/index', [
        'title' => 'Dashboard',
        'user' => ['full_name' => 'Kepala Uji', 'role_name' => 'Kepala Bengkel'],
        'role' => 'kepala_bengkel',
        'dashboard' => $d,
        'notifications' => NotificationService::forRole('kepala_bengkel'),
        'identity' => SettingService::identity(),
    ], 'layouts/app');
    t_true(!str_contains($html, '<?php'), 'Dashboard Kepala tidak membocorkan tag PHP');
    t_contains('Nilai persediaan (rata-rata)', $html, 'Nilai persediaan rata-rata tampil');
    t_contains('Nilai harga terakhir', $html, 'Nilai harga terakhir sebagai pembanding tampil');
    t_contains('Paling sering digunakan', $html, 'Ranking pemakaian tertinggi tampil');
    t_contains('Paling jarang digunakan', $html, 'Ranking pemakaian terendah tampil');
    t_contains('Status pembayaran PO', $html, 'Status pembayaran PO tampil');
    t_contains('Prediksi stok (AI)', $html, 'Bagian prediksi stok AI tampil pada dashboard Kepala Bengkel');
    t_contains('Grafik barang masuk/keluar', $html, 'Grafik barang masuk/keluar tampil');
}

function test_dashboard_admin_angka_dan_tampilan(): void
{
    $f = t_f5_fixture();
    $d = DashboardService::admin();

    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM users'),
        (int) $d['users']['total'],
        'Total pengguna sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM users WHERE is_active = 1'),
        (int) $d['users']['active'],
        'Pengguna aktif sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM spareparts'),
        (int) $d['sparepart']['types'],
        'Total sparepart (termasuk nonaktif) sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM spareparts WHERE is_active = 1'),
        (int) $d['sparepart_active']['types'],
        'Total sparepart aktif sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM goods_receipts'),
        (int) $d['transactions']['receipts'],
        'Statistik penerimaan sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM goods_issues'),
        (int) $d['transactions']['issues'],
        'Statistik pengeluaran sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM stock_opnames'),
        (int) $d['transactions']['opnames'],
        'Statistik opname sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM stock_movements'),
        (int) $d['transactions']['movements'],
        'Statistik ledger sesuai database'
    );
    t_eq(
        (int) t_dash_scalar('SELECT COUNT(*) FROM purchase_orders'),
        (int) $d['transactions']['po'],
        'Statistik PO sesuai database'
    );
    t_true(in_array(($d['system']['status'] ?? ''), ['ok', 'warning', 'error'], true), 'Status sistem memiliki nilai yang dikenal');
    t_eq(SettingService::identity()['name'], $d['identity']['name'], 'Dashboard Admin menampilkan identitas aktif');

    $html = View::render('dashboard/index', [
        'title' => 'Dashboard',
        'user' => ['full_name' => 'Admin Uji', 'role_name' => 'Admin'],
        'role' => 'admin',
        'dashboard' => $d,
        'notifications' => NotificationService::forRole('admin'),
        'identity' => SettingService::identity(),
    ], 'layouts/app');
    t_true(!str_contains($html, '<?php'), 'Dashboard Admin tidak membocorkan tag PHP');
    t_contains('Total pengguna', $html, 'Kartu total pengguna tampil');
    t_contains('Total sparepart', $html, 'Kartu total sparepart tampil');
    t_contains('Aktivitas pengguna terbaru', $html, 'Aktivitas pengguna tampil');
    t_contains('Status sistem', $html, 'Status sistem tampil');
    t_contains('Zona waktu', $html, 'Status sistem memuat zona waktu');
    t_contains('Pengaturan &amp; chatbot', $html, 'Akses pengaturan dan chatbot tampil');
    t_contains('Pengaturan Aplikasi', $html, 'Tautan pengaturan tersedia untuk Admin');
    t_contains('Buka Chatbot', $html, 'Tautan chatbot aktif tersedia untuk Admin');
    t_contains('Pengaturan Chatbot', $html, 'Tautan pengaturan chatbot tersedia untuk Admin');
}

function test_dashboard_notifikasi_sesuai_role(): void
{
    $f = t_f5_fixture();

    $partman = NotificationService::forRole('partman');
    $partmanTitles = implode(' | ', array_column($partman, 'title'));
    t_contains('Permintaan reorder menunggu persetujuan', $partmanTitles, 'Partman melihat reorder yang menunggu');
    t_true(!str_contains($partmanTitles, 'Persetujuan Purchase Order'), 'Partman tidak melihat notifikasi persetujuan PO Kepala');
    t_true(!str_contains($partmanTitles, 'PO menunggu administrasi'), 'Partman tidak melihat notifikasi administrasi Admin');

    $kepala = NotificationService::forRole('kepala_bengkel');
    $kepalaTitles = implode(' | ', array_column($kepala, 'title'));
    t_contains('Persetujuan reorder', $kepalaTitles, 'Kepala melihat reorder menunggu persetujuan');
    t_contains('Persetujuan Purchase Order', $kepalaTitles, 'Kepala melihat PO menunggu persetujuan');
    t_true(!str_contains($kepalaTitles, 'PO menunggu administrasi'), 'Kepala tidak melihat notifikasi administrasi Admin');

    $admin = NotificationService::forRole('admin');
    $adminTitles = implode(' | ', array_column($admin, 'title'));
    t_contains('PO menunggu administrasi', $adminTitles, 'Admin melihat PO yang perlu diproses');
    t_contains('Pembayaran PO belum lunas', $adminTitles, 'Admin melihat PO belum lunas');
    t_true(!str_contains($adminTitles, 'Persetujuan reorder'), 'Admin tidak melihat notifikasi persetujuan reorder');

    foreach ([$partman, $kepala, $admin] as $list) {
        foreach ($list as $note) {
            foreach (['level', 'title', 'message', 'url', 'count'] as $key) {
                t_true(array_key_exists($key, $note), "Notifikasi memiliki field {$key}");
            }
            t_true(in_array($note['level'], ['danger', 'warning', 'info', 'success'], true), 'Level notifikasi valid');
            t_true((int) $note['count'] >= 0, 'Jumlah notifikasi tidak negatif');
            if ($note['url'] !== null) {
                $ok = str_contains((string) $note['url'], '/laporan/')
                    || str_contains((string) $note['url'], '/reorder')
                    || str_contains((string) $note['url'], '/pembelian');
                t_true($ok, 'Tautan notifikasi menuju halaman yang ada (laporan/reorder/pembelian)');
            }
        }
    }

    $stock = array_values(array_filter($partman, static fn (array $n): bool => str_contains((string) $n['title'], 'Stok')));
    t_true($stock !== [], 'Notifikasi stok tersedia pada dashboard');
    t_eq([], DashboardService::forRole('tidak_dikenal'), 'Role tidak dikenal tidak mendapat data dashboard');
}

function test_dashboard_analitik_konsisten_dengan_database(): void
{
    $f = t_f5_fixture();
    $p = $f['p'];

    $period = Analytics::periodTotals('2024-02-10', '2024-02-12');
    $masuk = (float) t_dash_scalar(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'MASUK' THEN quantity ELSE 0 END), 0)
           FROM stock_movements WHERE movement_date BETWEEN '2024-02-10' AND '2024-02-12'"
    );
    $keluar = (float) t_dash_scalar(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'KELUAR' THEN -quantity ELSE 0 END), 0)
           FROM stock_movements WHERE movement_date BETWEEN '2024-02-10' AND '2024-02-12'"
    );
    t_eq($masuk, (float) $period['masuk_qty'], 'PeriodTotals qty masuk sesuai ledger');
    t_eq($keluar, (float) $period['keluar_qty'], 'PeriodTotals qty keluar sesuai ledger');

    $series = Analytics::movementSeries('2024-02-10', '2024-02-12');
    t_true((float) ($series['2024-02-11']['masuk'] ?? 0) >= 2.0, 'Movement series menjumlahkan masuk per hari');
    t_true((float) ($series['2024-02-12']['keluar'] ?? 0) >= 13.0, 'Movement series menjumlahkan keluar per hari');

    $purchase = Analytics::purchaseSummary('2024-02-01', '2024-02-29');
    $purchaseSql = (float) t_dash_scalar(
        "SELECT COALESCE(SUM(i.quantity * i.unit_price), 0)
           FROM goods_receipts r JOIN goods_receipt_items i ON i.receipt_id = r.id
          WHERE r.receipt_type = 'pembelian' AND r.receipt_date BETWEEN '2024-02-01' AND '2024-02-29'"
    );
    t_eq($purchaseSql, (float) $purchase['value'], 'Ringkasan pembelian sesuai query database');

    $issue = Analytics::issueSummary('2024-02-01', '2024-02-29');
    t_true((float) $issue['qty'] >= 1.0, 'Ringkasan pengeluaran memuat data fixture');

    $opname = Analytics::opnameSummary('2024-02-01', '2024-02-29');
    t_true((int) $opname['total'] >= 1, 'Ringkasan opname memuat sesi fixture');

    $ranking = Analytics::usageRanking('2024-02-10', '2024-02-12', 100, true);
    $row = t_f5_row($ranking, 'code', $p . '-C');
    t_true($row !== null, 'Ranking pemakaian memuat sparepart cepat');
    if ($row !== null) {
        t_true((float) $row['used_qty'] >= 12.0, 'Qty pemakaian ranking sesuai ledger');
    }

    $latest = Analytics::latestTransactions(50);
    $numbers = array_column($latest, 'number');
    t_true(in_array($f['receipt_number'], $numbers, true), 'Transaksi terbaru memuat penerimaan fixture');
    t_true(in_array($f['issue_number'], $numbers, true), 'Transaksi terbaru memuat pengeluaran fixture');

    $po = Analytics::poStatusCounts();
    t_true((int) ($po['MENUNGGU_PERSETUJUAN']['count'] ?? 0) >= 1, 'Hitung status PO menunggu persetujuan');
    $payment = Analytics::poPaymentCounts();
    t_true((int) ($payment['BELUM_DIBAYAR']['count'] ?? 0) >= 1, 'Hitung PO belum dibayar');
    t_true((float) ($payment['BELUM_DIBAYAR']['amount'] ?? 0) >= 50000.0, 'Nilai PO belum dibayar dijumlahkan');
}
