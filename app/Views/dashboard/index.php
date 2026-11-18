<?php
/**
 * Dashboard per role (Fase 5).
 *
 * @var array<string,mixed>|null $user
 * @var string $role
 * @var array<string,mixed> $dashboard
 * @var array<int,array<string,mixed>> $notifications
 * @var array{name:string,address:string,phone:string,email:string,note:string} $identity
 */
use App\Services\ChartService;
use App\Services\ReportService;

$user = $user ?? null;
$role = $role ?? '';
$dashboard = $dashboard ?? [];
$notifications = $notifications ?? [];
$identity = $identity ?? ['name' => 'SmartPartMan', 'address' => '', 'phone' => '', 'email' => '', 'note' => ''];
$roleLabel = (string) ($user['role_name'] ?? '');

$statusBadge = static function (string $status): string {
    $class = ReportService::badgeClass('stock', $status);
    return '<span class="badge text-bg-' . e($class) . '">' . e(ReportService::label('stock', $status)) . '</span>';
};
$qty = static fn (mixed $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
$riskBadge = static function (?string $risk): string {
    return match ($risk) {
        'tinggi' => '<span class="badge text-bg-danger">Tinggi</span>',
        'sedang' => '<span class="badge text-bg-warning text-dark">Sedang</span>',
        'rendah' => '<span class="badge text-bg-success">Rendah</span>',
        default  => '<span class="badge text-bg-light border text-muted">Belum dinilai</span>',
    };
};
/** Ikon kartu statistik (SVG konsisten, bukan emoji). */
$statIcon = static function (string $icon): string {
    $paths = [
        'box'     => '<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'stack'   => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'in'      => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="m8 12 4 4 4-4"/>',
        'out'     => '<circle cx="12" cy="12" r="9"/><path d="M12 16V8"/><path d="m8 12 4-4 4 4"/>',
        'value'   => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'tag'     => '<path d="M20.6 13.4 12 22l-9-9V4a2 2 0 0 1 2-2h9l6.6 6.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'alert'   => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'activity'=> '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'swap'    => '<path d="M7 16V4"/><path d="m3 8 4-4 4 4"/><path d="M17 8v12"/><path d="m21 16-4 4-4-4"/>',
    ];
    $path = $paths[$icon] ?? $paths['box'];
    return '<span class="stat-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg></span>';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Selamat datang, <?= e($user['full_name'] ?? '') ?></h1>
        <div class="text-muted small">
            <?= e($roleLabel) ?> &middot; <?= e($identity['name']) ?>
            &middot; <?= e(tanggal_id(date('Y-m-d'))) ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan')) ?>">Buka Laporan</a>
        <?php if ($role === 'admin'): ?>
            <a class="btn btn-navy btn-sm" href="<?= e(base_url('pengaturan')) ?>">Pengaturan</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($notifications !== []): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong class="text-navy">Notifikasi</strong>
            <span class="badge text-bg-secondary"><?= count($notifications) ?></span>
        </div>
        <div class="list-group list-group-flush notification-list">
            <?php foreach ($notifications as $note): ?>
                <div class="list-group-item level-<?= e($note['level'] ?? 'info') ?>">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold text-navy">
                                <?= e($note['title'] ?? '') ?>
                                <span class="badge text-bg-<?= e($note['level'] ?? 'info') ?> ms-1"><?= (int) ($note['count'] ?? 0) ?></span>
                            </div>
                            <div class="small text-muted"><?= e($note['message'] ?? '') ?></div>
                        </div>
                        <?php if (!empty($note['url'])): ?>
                            <a class="btn btn-outline-secondary btn-sm align-self-center"
                               href="<?= e((string) $note['url']) ?>">Lihat</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-success py-2 small mb-3">
        Tidak ada peringatan stok atau status transaksi yang memerlukan tindakan saat ini.
    </div>
<?php endif; ?>

<?php if ($role === 'partman'):
    $s = $dashboard['summary'] ?? [];
    $stats = $dashboard['stock_stats'] ?? [];
    $chart = $dashboard['chart'] ?? ['labels' => [], 'masuk' => [], 'keluar' => []];
    $alerts = $dashboard['alerts'] ?? [];
    $ai = $dashboard['ai'] ?? ['items' => [], 'pending_reorder' => 0];
    $latest = $dashboard['latest'] ?? [];
    $daily = $dashboard['daily'] ?? [];
    ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Total jenis sparepart</div>
                    <?= $statIcon('box') ?>
                    <div class="stat-value"><?= $qty($s['types'] ?? 0) ?></div>
                    <div class="small text-muted">Aktif</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Total stok</div>
                    <?= $statIcon('stack') ?>
                    <div class="stat-value"><?= $qty($s['stock'] ?? 0) ?></div>
                    <div class="small text-muted">Seluruh sparepart aktif</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Barang masuk hari ini</div>
                    <?= $statIcon('in') ?>
                    <div class="stat-value"><?= $qty($s['masuk_qty'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($s['receipts'] ?? 0) ?> dokumen</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Barang keluar hari ini</div>
                    <?= $statIcon('out') ?>
                    <div class="stat-value"><?= $qty($s['keluar_qty'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($s['issues'] ?? 0) ?> dokumen</div>
                </div>
            </div>
        </div>
    </div>

    <?php $prediction = $dashboard['prediction'] ?? []; require __DIR__ . '/_prediction_insight.php'; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong class="text-navy">Grafik pergerakan stok (14 hari)</strong>
                    <span class="small text-muted">Ledger MASUK/KELUAR</span>
                </div>
                <div class="card-body">
                    <?= ChartService::bar(
                        $chart['labels'] ?? [],
                        [
                            ['label' => 'Masuk', 'values' => $chart['masuk'] ?? [], 'color' => '#198754'],
                            ['label' => 'Keluar', 'values' => $chart['keluar'] ?? [], 'color' => '#d92d20'],
                        ],
                        ['title' => 'Pergerakan stok 14 hari', 'empty' => 'Belum ada pergerakan stok pada 14 hari terakhir.']
                    ) ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong class="text-navy">Perlu perhatian</strong>
                    <a class="small" href="<?= e(base_url('laporan/stok_minimum')) ?>">Detail</a>
                </div>
                <div class="card-body p-0">
                    <div class="d-flex justify-content-around border-bottom py-2 small">
                        <span>Habis <strong class="text-danger"><?= (int) ($stats['habis'] ?? 0) ?></strong></span>
                        <span>Kritis <strong class="text-danger"><?= (int) ($stats['kritis'] ?? 0) ?></strong></span>
                        <span>Menipis <strong class="text-warning"><?= (int) ($stats['menipis'] ?? 0) ?></strong></span>
                    </div>
                    <?php if ($alerts === []): ?>
                        <div class="text-muted small text-center py-4">Tidak ada stok yang perlu perhatian.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($alerts, 0, 6) as $item): ?>
                                <a class="list-group-item list-group-item-action py-2"
                                   href="<?= e(base_url('sparepart/' . (int) $item['id'])) ?>">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="small text-truncate"><?= e($item['name']) ?></span>
                                        <?= $statusBadge((string) ($item['stock_status'] ?? 'aman')) ?>
                                    </div>
                                    <div class="small text-muted">
                                        Stok <?= $qty($item['stock'] ?? 0) ?> <?= e($item['unit'] ?? '') ?>
                                        &middot; minimum <?= $qty($item['min_stock'] ?? 0) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Rekomendasi reorder</strong>
                </div>
                <div class="card-body">
                    <?php if (($ai['ready'] ?? false)): ?>
                        <div class="alert alert-success small mb-2">
                            <strong>Prediksi AI aktif.</strong>
                            Analisis terakhir: <?= e(($ai['latest_at'] ?? null) !== null ? date('d-m-Y H:i', strtotime((string) $ai['latest_at'])) : '—') ?> &middot;
                            <?= (int) ($ai['total'] ?? 0) ?> sparepart dianalisis &middot;
                            <span class="<?= (int) ($ai['risk_high'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                <?= (int) ($ai['risk_high'] ?? 0) ?> berisiko tinggi
                            </span>.
                            <a href="<?= e(base_url('prediksi')) ?>">Buka Prediksi Stok</a>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info small mb-2">
                            <strong>Prediksi AI belum pernah dijalankan.</strong>
                            Jalankan analisis dari modul <a href="<?= e(base_url('prediksi')) ?>">Prediksi Stok</a>
                            (Partman); sebelum itu saran reorder memakai aturan stok-minimum.
                        </div>
                    <?php endif; ?>
                    <?php if (($ai['items'] ?? []) === []): ?>
                        <div class="text-muted small border rounded p-3 bg-light">
                            Belum ada rekomendasi reorder dari prediksi. Setelah prediksi berjalan, sparepart
                            berisiko habis akan muncul di sini beserta qty yang disarankan.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Sparepart</th><th class="text-end">Saran</th><th>Risiko</th></tr></thead>
                                <tbody>
                                <?php foreach ($ai['items'] as $item): ?>
                                    <tr>
                                        <td class="small">
                                            <a href="<?= e(base_url('prediksi/' . (int) $item['sparepart_id'])) ?>"><?= e($item['sparepart_name'] ?? '') ?></a>
                                        </td>
                                        <td class="text-end small"><?= $qty($item['recommended_qty'] ?? 0) ?> <?= e((string) ($item['unit'] ?? '')) ?></td>
                                        <td class="small"><?= $riskBadge($item['stockout_risk'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="small text-muted mt-3">
                        Permintaan reorder menunggu persetujuan: <strong><?= (int) ($ai['pending_reorder'] ?? 0) ?></strong>
                        &middot; disetujui: <strong><?= (int) ($ai['approved_reorder'] ?? 0) ?></strong>.
                        <a href="<?= e(base_url('reorder')) ?>">Buka modul Reorder</a> &middot;
                        <a href="<?= e(base_url('pembelian')) ?>">modul Pembelian/PO</a>.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong class="text-navy">Transaksi terbaru</strong>
                    <a class="small" href="<?= e(base_url('transaksi/riwayat')) ?>">Riwayat lengkap</a>
                </div>
                <div class="card-body p-0">
                    <?php if ($latest === []): ?>
                        <div class="text-muted small text-center py-4">Belum ada transaksi.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead><tr><th>Jenis</th><th>Dokumen</th><th>Tanggal</th><th>Pihak</th><th class="text-end">Qty</th></tr></thead>
                                <tbody>
                                <?php foreach ($latest as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="badge text-bg-<?= $row['kind'] === 'masuk' ? 'success' : 'danger' ?>">
                                                <?= $row['kind'] === 'masuk' ? 'Masuk' : 'Keluar' ?>
                                            </span>
                                        </td>
                                        <td class="small">
                                            <a href="<?= e(base_url('transaksi/' . ($row['kind'] === 'masuk' ? 'masuk' : 'keluar') . '/' . (int) $row['id'])) ?>">
                                                <?= e($row['number']) ?>
                                            </a>
                                        </td>
                                        <td class="small"><?= e(tanggal_id((string) $row['doc_date'])) ?></td>
                                        <td class="small text-truncate"><?= e($row['party']) ?></td>
                                        <td class="text-end small"><?= $qty($row['qty']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <strong class="text-navy">Ringkasan aktivitas hari ini</strong>
        </div>
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-6 col-md-3">
                    <div class="text-muted">Penerimaan</div>
                    <div class="fs-5 fw-semibold text-navy"><?= (int) ($daily['receipts'] ?? 0) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Pengeluaran</div>
                    <div class="fs-5 fw-semibold text-navy"><?= (int) ($daily['issues'] ?? 0) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Aktivitas tercatat</div>
                    <div class="fs-5 fw-semibold text-navy"><?= (int) ($daily['actions'] ?? 0) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Pengguna aktif hari ini</div>
                    <div class="fs-5 fw-semibold text-navy"><?= (int) ($daily['users'] ?? 0) ?></div>
                </div>
            </div>
            <?php if (($daily['breakdown'] ?? []) !== []): ?>
                <hr>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($daily['breakdown'] as $activity): ?>
                        <span class="badge text-bg-light border">
                            <?= e(ucwords(strtolower(str_replace('_', ' ', (string) $activity['activity'])))) ?>
                            <strong><?= (int) $activity['total'] ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="small text-muted mt-2">
                Status stok: aman <?= (int) ($stats['aman'] ?? 0) ?>,
                tanpa minimum <?= (int) ($stats['tanpa_minimum'] ?? 0) ?>.
            </div>
        </div>
    </div>

<?php elseif ($role === 'kepala_bengkel'):
    $s = $dashboard['summary'] ?? [];
    $stats = $dashboard['stock_stats'] ?? [];
    $chart = $dashboard['chart'] ?? ['labels' => [], 'masuk' => [], 'keluar' => []];
    $alerts = $dashboard['alerts'] ?? [];
    $month = $dashboard['month'] ?? [];
    $po = $dashboard['po'] ?? [];
    $payment = $dashboard['payment'] ?? [];
    $period = $dashboard['period'] ?? ['days' => 30];
    $ai = $dashboard['ai'] ?? ['ready' => false, 'items' => []];
    ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Jenis / total stok</div>
                    <?= $statIcon('box') ?>
                    <div class="stat-value"><?= $qty($s['types'] ?? 0) ?> <span class="fs-6 text-muted">/ <?= $qty($s['stock'] ?? 0) ?></span></div>
                    <div class="small text-muted">Sparepart aktif</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Nilai persediaan (rata-rata)</div>
                    <?= $statIcon('value') ?>
                    <div class="stat-value fs-5"><?= e(rupiah($s['value_avg'] ?? 0)) ?></div>
                    <div class="small text-muted">Weighted average</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Nilai harga terakhir</div>
                    <?= $statIcon('tag') ?>
                    <div class="stat-value fs-5"><?= e(rupiah($s['value_last'] ?? 0)) ?></div>
                    <div class="small text-muted">Pembanding, bukan dasar nilai</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat is-critical shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Habis / kritis / menipis</div>
                    <?= $statIcon('alert') ?>
                    <div class="stat-value">
                        <?= (int) ($stats['habis'] ?? 0) ?> / <?= (int) ($stats['kritis'] ?? 0) ?> / <?= (int) ($stats['menipis'] ?? 0) ?>
                    </div>
                    <div class="small text-muted"><a href="<?= e(base_url('laporan/stok_minimum')) ?>">Lihat detail</a></div>
                </div>
            </div>
        </div>
    </div>

    <?php $prediction = $dashboard['prediction'] ?? []; require __DIR__ . '/_prediction_insight.php'; ?>

    <div class="alert alert-light border small">
        Nilai persediaan = stok × harga beli rata-rata tertimbang (weighted average), dengan fallback harga beli
        terakhir bila belum ada pembelian. Harga terakhir ditampilkan sebagai pembanding. Metode ini telah
        disepakati dan diuji pada Fase 4.
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong class="text-navy">Grafik barang masuk/keluar (30 hari)</strong>
                    <span class="small text-muted">Ledger MASUK/KELUAR</span>
                </div>
                <div class="card-body">
                    <?= ChartService::bar(
                        $chart['labels'] ?? [],
                        [
                            ['label' => 'Masuk', 'values' => $chart['masuk'] ?? [], 'color' => '#198754'],
                            ['label' => 'Keluar', 'values' => $chart['keluar'] ?? [], 'color' => '#d92d20'],
                        ],
                        ['title' => 'Pergerakan stok 30 hari', 'empty' => 'Belum ada pergerakan stok pada 30 hari terakhir.']
                    ) ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong class="text-navy">Stok kritis / habis</strong>
                    <a class="small" href="<?= e(base_url('laporan/stok_minimum')) ?>">Detail</a>
                </div>
                <div class="card-body p-0">
                    <?php if ($alerts === []): ?>
                        <div class="text-muted small text-center py-4">Tidak ada stok yang perlu perhatian.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($alerts, 0, 6) as $item): ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="small text-truncate"><?= e($item['name']) ?></span>
                                        <?= $statusBadge((string) ($item['stock_status'] ?? 'aman')) ?>
                                    </div>
                                    <div class="small text-muted">Stok <?= $qty($item['stock'] ?? 0) ?> <?= e($item['unit'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Paling sering digunakan (<?= (int) ($period['days'] ?? 30) ?> hari)</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (($dashboard['top_used'] ?? []) === []): ?>
                        <div class="text-muted small text-center py-4">Belum ada pemakaian.</div>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Sparepart</th><th class="text-end">Qty keluar</th></tr></thead>
                            <tbody>
                            <?php foreach ($dashboard['top_used'] as $item): ?>
                                <tr><td class="small"><?= e($item['name']) ?></td><td class="text-end small"><?= $qty($item['used_qty'] ?? 0) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Paling jarang digunakan (<?= (int) ($period['days'] ?? 30) ?> hari)</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (($dashboard['least_used'] ?? []) === []): ?>
                        <div class="text-muted small text-center py-4">Belum ada data.</div>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Sparepart</th><th class="text-end">Qty keluar</th></tr></thead>
                            <tbody>
                            <?php foreach ($dashboard['least_used'] as $item): ?>
                                <tr><td class="small"><?= e($item['name']) ?></td><td class="text-end small"><?= $qty($item['used_qty'] ?? 0) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Ringkasan laporan bulan ini</strong>
                </div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Pembelian</div>
                            <div class="fw-semibold text-navy"><?= e(rupiah($month['purchase']['value'] ?? 0)) ?></div>
                            <div class="text-muted"><?= (int) ($month['purchase']['docs'] ?? 0) ?> dokumen</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Barang keluar</div>
                            <div class="fw-semibold text-navy"><?= $qty($month['issue']['qty'] ?? 0) ?></div>
                            <div class="text-muted"><?= (int) ($month['issue']['docs'] ?? 0) ?> dokumen</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">Opname</div>
                            <div class="fw-semibold text-navy"><?= (int) ($month['opname']['total'] ?? 0) ?></div>
                            <div class="text-muted"><?= (int) ($month['opname']['confirmed'] ?? 0) ?> terkonfirmasi</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="text-muted">PO</div>
                            <div class="fw-semibold text-navy"><?= (int) array_sum(array_map(static fn ($p) => (int) $p['count'], $po)) ?></div>
                            <div class="text-muted">semua status</div>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan/masuk')) ?>">Laporan Masuk</a>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan/keluar')) ?>">Laporan Keluar</a>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan/pembelian')) ?>">Laporan PO</a>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan/pembayaran')) ?>">Pembayaran</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Status pembayaran PO</strong>
                </div>
                <div class="card-body small">
                    <?php
                    $paymentLabels = ['BELUM_DIBAYAR', 'DIBAYAR_SEBAGIAN', 'LUNAS'];
                    foreach ($paymentLabels as $status):
                        $row = $payment[$status] ?? ['count' => 0, 'amount' => 0];
                        ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span><?= e(ReportService::label('payment', $status)) ?></span>
                            <span>
                                <strong><?= (int) $row['count'] ?></strong> PO
                                &middot; <?= e(rupiah($row['amount'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <div class="border-top mt-3 pt-2">
                        <div class="fw-semibold text-navy mb-1">Prediksi stok (AI)</div>
                        <?php if (($ai['ready'] ?? false)): ?>
                            <div class="small text-muted mb-2">
                                Analisis terakhir: <?= e(($ai['latest_at'] ?? null) !== null ? date('d-m-Y H:i', strtotime((string) $ai['latest_at'])) : '—') ?>
                                &middot; <?= (int) ($ai['total'] ?? 0) ?> sparepart dianalisis
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span>Risiko tinggi</span>
                                <strong class="<?= (int) ($ai['risk_high'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= (int) ($ai['risk_high'] ?? 0) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <span>Risiko sedang</span>
                                <strong><?= (int) ($ai['risk_medium'] ?? 0) ?></strong>
                            </div>
                            <?php if (($ai['items'] ?? []) !== []): ?>
                                <div class="mt-2">
                                    <?php foreach (array_slice($ai['items'], 0, 3) as $item): ?>
                                        <div class="d-flex justify-content-between small border-bottom py-1">
                                            <a href="<?= e(base_url('prediksi/' . (int) $item['sparepart_id'])) ?>">
                                                <?= e((string) ($item['sparepart_code'] ?? $item['sparepart_name'] ?? '')) ?>
                                            </a>
                                            <span>
                                                <?= $riskBadge($item['stockout_risk'] ?? null) ?>
                                                <span class="text-muted">saran <?= $qty($item['recommended_qty'] ?? 0) ?> <?= e((string) ($item['unit'] ?? '')) ?></span>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2"><a href="<?= e(base_url('prediksi')) ?>">Lihat semua prediksi</a></div>
                        <?php else: ?>
                            <div class="alert alert-info small mb-0">
                                <strong>Prediksi AI belum pernah dijalankan.</strong>
                                Analisis dijalankan Partman dari modul
                                <a href="<?= e(base_url('prediksi')) ?>">Prediksi Stok</a>.
                                Angka persediaan di atas adalah data aktual, bukan prediksi.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($role === 'admin'):
    $users = $dashboard['users'] ?? [];
    $sp = $dashboard['sparepart'] ?? [];
    $spActive = $dashboard['sparepart_active'] ?? [];
    $stats = $dashboard['stock_stats'] ?? [];
    $trx = $dashboard['transactions'] ?? [];
    $activity = $dashboard['activity'] ?? [];
    $system = $dashboard['system'] ?? [];
    $po = $dashboard['po'] ?? [];
    $payment = $dashboard['payment'] ?? [];
    ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Total pengguna</div>
                    <?= $statIcon('users') ?>
                    <div class="stat-value"><?= (int) ($users['total'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($users['active'] ?? 0) ?> aktif &middot; <?= (int) ($users['locked'] ?? 0) ?> terkunci</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Total sparepart</div>
                    <?= $statIcon('box') ?>
                    <div class="stat-value"><?= (int) ($sp['types'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($spActive['types'] ?? 0) ?> aktif &middot; <?= (int) ($stats['habis'] ?? 0) ?> habis</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Transaksi</div>
                    <?= $statIcon('swap') ?>
                    <div class="stat-value"><?= (int) (($trx['receipts'] ?? 0) + ($trx['issues'] ?? 0)) ?></div>
                    <div class="small text-muted"><?= (int) ($trx['receipts'] ?? 0) ?> masuk &middot; <?= (int) ($trx['issues'] ?? 0) ?> keluar</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body">
                    <div class="stat-label">Aktivitas hari ini</div>
                    <?= $statIcon('activity') ?>
                    <div class="stat-value"><?= (int) ($activity['today']['actions'] ?? 0) ?></div>
                    <div class="small text-muted"><?= (int) ($activity['today']['users'] ?? 0) ?> pengguna</div>
                </div>
            </div>
        </div>
    </div>

    <?php $prediction = $dashboard['prediction'] ?? []; require __DIR__ . '/_prediction_insight.php'; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Aktivitas pengguna terbaru</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (($activity['recent'] ?? []) === []): ?>
                        <div class="text-muted small text-center py-4">Belum ada aktivitas.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activity['recent'] as $log): ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="small fw-semibold text-navy"><?= e($log['username'] ?? '-') ?></span>
                                        <span class="small text-muted"><?= e(tanggal_id((string) $log['created_at'], true)) ?></span>
                                    </div>
                                    <div class="small text-muted text-truncate"><?= e($log['activity'] ?? '') ?> — <?= e($log['description'] ?? '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white">
                    <a class="small" href="<?= e(base_url('audit-log')) ?>">Buka Audit Log</a>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Status sistem</strong>
                </div>
                <div class="card-body">
                    <?php
                    $sysOk = ($system['status'] ?? '') === 'ok';
                    $badge = static fn (bool $ok): string => $ok ? 'success' : 'danger';
                    ?>
                    <div class="row g-2 small">
                        <div class="col-6 col-md-4">
                            <div class="text-muted">Keseluruhan</div>
                            <span class="badge text-bg-<?= $badge($sysOk) ?>"><?= $sysOk ? 'Sehat' : 'Perlu perhatian' ?></span>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">Database</div>
                            <span class="badge text-bg-<?= $badge(($system['database'] ?? '') === 'ok') ?>"><?= e((string) ($system['database'] ?? '-')) ?></span>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">Migrasi</div>
                            <span class="badge text-bg-<?= $badge(($system['migrations'] ?? '') === 'ok') ?>"><?= e((string) ($system['migrations'] ?? '-')) ?></span>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">PHP</div>
                            <span class="badge text-bg-<?= $badge(($system['php'] ?? '') === 'ok') ?>"><?= e((string) ($system['php_version'] ?? '-')) ?></span>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">MariaDB/MySQL</div>
                            <span class="small"><?= e((string) ($system['database_version'] ?? '-')) ?></span>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">Zona waktu</div>
                            <span class="small"><?= e((string) ($system['timezone'] ?? '-')) ?></span>
                        </div>
                    </div>
                    <?php if (($system['missing_extensions'] ?? []) !== []): ?>
                        <div class="alert alert-warning small mt-3 mb-0">
                            Ekstensi belum aktif: <?= e(implode(', ', (array) $system['missing_extensions'])) ?>
                        </div>
                    <?php endif; ?>
                    <hr>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('audit-log')) ?>">Audit Log</a>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('health')) ?>">Health Check</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Statistik transaksi</strong>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Penerimaan</span><strong><?= (int) ($trx['receipts'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Pengeluaran</span><strong><?= (int) ($trx['issues'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Stock opname</span><strong><?= (int) ($trx['opnames'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Pergerakan ledger</span><strong><?= (int) ($trx['movements'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Purchase Order</span><strong><?= (int) ($trx['po'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between pt-1"><span>Hari ini (masuk/keluar)</span><strong><?= (int) ($trx['today']['receipts'] ?? 0) ?> / <?= (int) ($trx['today']['issues'] ?? 0) ?></strong></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Ringkasan laporan bulan ini</strong>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Nilai pembelian</span><strong><?= e(rupiah($trx['month']['purchase']['value'] ?? 0)) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Qty keluar</span><strong><?= $qty($trx['month']['issue']['qty'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>Opname</span><strong><?= (int) ($trx['month']['opname']['total'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-1"><span>PO belum lunas</span><strong><?= (int) (($payment['BELUM_DIBAYAR']['count'] ?? 0) + ($payment['DIBAYAR_SEBAGIAN']['count'] ?? 0)) ?></strong></div>
                    <div class="d-flex justify-content-between pt-1"><span>Nilai persediaan (rata-rata)</span><strong><?= e(rupiah($spActive['value_avg'] ?? 0)) ?></strong></div>
                    <a class="btn btn-outline-secondary btn-sm mt-3" href="<?= e(base_url('laporan')) ?>">Buka semua laporan</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong class="text-navy">Pengaturan &amp; chatbot</strong>
                </div>
                <div class="card-body small">
                    <p class="text-muted">
                        Identitas bengkel dan parameter fast/slow moving dipakai pada seluruh dokumen cetak.
                    </p>
                    <a class="btn btn-navy btn-sm w-100 mb-2" href="<?= e(base_url('pengaturan')) ?>">Pengaturan Aplikasi</a>
                    <a class="btn btn-outline-secondary btn-sm w-100 mb-2" href="<?= e(base_url('chatbot')) ?>">Buka Chatbot</a>
                    <a class="btn btn-outline-secondary btn-sm w-100" href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a>
                    <div class="text-muted mt-2">
                        Chatbot memakai ringkasan data sesuai role; API key hanya diatur Admin.
                    </div>
                    <hr>
                    <div class="text-muted">Identitas aktif</div>
                    <div class="fw-semibold text-navy"><?= e($identity['name']) ?></div>
                    <div class="text-muted"><?= e($identity['address'] ?: 'Alamat belum diisi') ?></div>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-warning">
        Role pengguna tidak dikenali. Hubungi Admin untuk memeriksa akun Anda.
    </div>
<?php endif; ?>
