<?php
/**
 * Tampilan satu laporan (Fase 5).
 *
 * @var array<string,mixed> $report
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $suppliers
 * @var string $query
 */
use App\Services\ChartService;
use App\Services\ReportService;
use App\Services\SettingService;

$report = $report ?? [];
$categories = $categories ?? [];
$suppliers = $suppliers ?? [];
$query = $query ?? '';
$meta = $report['meta'] ?? [];
$type = (string) ($report['type'] ?? '');
$rows = $report['rows'] ?? [];
$summary = $report['summary'] ?? [];
$chart = $report['chart'] ?? null;
$notes = $report['notes'] ?? [];
$filters = $report['filters'] ?? [];
$filterText = $report['filter_text'] ?? [];
$action = base_url('laporan/' . $type);
$link = static function (string $suffix) use ($type, $query): string {
    return base_url('laporan/' . $type . $suffix) . ($query !== '' ? '?' . $query : '');
};
$qty = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    $number = (float) $value;
    $decimals = floor($number) === $number ? 0 : 2;
    return number_format($number, $decimals, ',', '.');
};
$cellText = static function (array $column, mixed $value): string {
    return ReportService::formatCell($column, $value);
};
?>


<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-muted mb-1">
            <a href="<?= e(base_url('laporan')) ?>">Laporan</a> / <?= e((string) ($meta['short'] ?? '')) ?>
        </div>
        <h1 class="h4 text-navy mb-1"><?= e((string) ($meta['title'] ?? 'Laporan')) ?></h1>
        <p class="text-muted small mb-0"><?= e((string) ($meta['description'] ?? '')) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($link('/cetak')) ?>" target="_blank" rel="noopener">
            &#128424; Cetak
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($link('/pdf')) ?>">PDF</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($link('/excel')) ?>">Excel</a>
        <a class="btn btn-navy btn-sm" href="<?= e(base_url('laporan')) ?>">Daftar Laporan</a>
    </div>
</div>

<?php if ($type === 'ai'): ?>
    <?php
    $aiActive = false;
    foreach ($summary as $summaryItem) {
        if (($summaryItem['label'] ?? '') === 'Status modul prediksi') {
            $aiActive = (int) ($summaryItem['raw'] ?? 0) > 0;
        }
    }
    ?>
    <div class="alert alert-<?= $aiActive ? 'light' : 'info' ?> border-0 shadow-sm">
        <div class="fw-semibold mb-1"><?= $aiActive ? 'Modul prediksi aktif' : 'Modul prediksi belum dijalankan' ?></div>
        <div class="small">
            <?php if ($aiActive): ?>
                Laporan menampilkan rekomendasi terbaru per sparepart hasil pipeline Python
                (Moving Average / Linear Regression + Safety Stock &amp; ROP); model hanya memakai data lampau.
                Kolom <em>Stok</em> dan <em>Minimum</em> adalah data aktual, sedangkan
                <em>risiko</em>, <em>rekomendasi</em>, <em>periode</em>, dan <em>metode</em> berasal dari prediksi.
            <?php else: ?>
                Belum ada analisis prediksi; tabel di bawah belum memuat angka prediksi. Jalankan analisis
                dari menu <strong>Prediksi Stok</strong> (Partman) — angka stok tetap data aktual.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$action = $action;
require __DIR__ . '/_filters.php';
?>

<?php if ($filterText !== []): ?>
    <div class="alert alert-light border small d-flex flex-wrap gap-2 align-items-center mb-3">
        <span class="fw-semibold text-navy">Filter aktif:</span>
        <?php foreach ($filterText as $line): ?>
            <span class="badge text-bg-light border fw-normal"><?= e($line) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($summary !== []): ?>
    <div class="row g-2 mb-3">
        <?php foreach ($summary as $item): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="card card-stat shadow-sm h-100">
                    <div class="card-body py-2">
                        <div class="stat-label"><?= e((string) ($item['label'] ?? '')) ?></div>
                        <div class="stat-value fs-5"><?= e((string) ($item['value'] ?? '-')) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($type === 'fast_slow'): ?>
    <div class="alert alert-warning small border-0">
        <strong>Aturan klasifikasi (dapat diubah Admin di Pengaturan):</strong>
        periode <strong><?= (int) (SettingService::reportConfig()['period_days']) ?> hari</strong>,
        <strong>Cepat</strong> bila total qty keluar ≥ <?= (int) (SettingService::reportConfig()['fast_threshold']) ?>,
        <strong>Lambat</strong> bila ≤ <?= (int) (SettingService::reportConfig()['slow_threshold']) ?>,
        selain itu <strong>Sedang</strong>.
        Aturan ini juga tercantum pada catatan laporan dan ekspor.
    </div>
<?php endif; ?>

<?php if (is_array($chart) && ($chart['labels'] ?? []) !== []): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong class="text-navy"><?= e((string) ($chart['title'] ?? 'Grafik')) ?></strong>
            <span class="small text-muted">Grafik mengikuti filter di atas</span>
        </div>
        <div class="card-body">
            <?php
            $series = $chart['series'] ?? [];
            $options = [
                'title' => (string) ($chart['title'] ?? 'Grafik'),
                'money' => (bool) ($chart['money'] ?? false),
                'height' => ($chart['kind'] ?? 'bar') === 'line' ? 250 : 280,
                'empty' => 'Belum ada data untuk digrafikkan pada filter ini.',
            ];
            echo ($chart['kind'] ?? 'bar') === 'line'
                ? ChartService::line($chart['labels'], $series, $options)
                : ChartService::bar($chart['labels'], $series, $options);
            ?>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong class="text-navy">Data laporan</strong>
        <span class="small text-muted">
            <?= number_format(count($rows), 0, ',', '.') ?> baris
            <?php if (count($rows) >= ReportService::MAX_ROWS): ?>
                &middot; batas tampilan <?= number_format(ReportService::MAX_ROWS, 0, ',', '.') ?> baris
            <?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover report-table align-middle mb-0">
            <thead>
            <tr>
                <th class="text-end" style="width: 42px;">#</th>
                <?php foreach (($meta['columns'] ?? []) as $column): ?>
                    <th class="<?= ($column['align'] ?? 'left') === 'right' ? 'text-end' : '' ?>">
                        <?= e((string) ($column['label'] ?? '')) ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($type === 'pembelian'): ?>
                    <th class="text-end">Aksi</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="<?= count($meta['columns'] ?? []) + ($type === 'pembelian' ? 2 : 1) ?>"
                        class="text-center text-muted py-4">
                        Tidak ada data pada filter ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td class="text-end text-muted small"><?= $index + 1 ?></td>
                        <?php foreach (($meta['columns'] ?? []) as $column): ?>
                            <?php
                            $key = (string) ($column['key'] ?? '');
                            $value = $row[$key] ?? null;
                            $align = ($column['align'] ?? 'left') === 'right' ? 'text-end' : '';
                            ?>
                            <td class="<?= $align ?>">
                                <?php if (($column['type'] ?? 'text') === 'badge'): ?>
                                    <span class="badge text-bg-<?= e(ReportService::badgeClass((string) ($column['group'] ?? ''), $value)) ?>">
                                        <?= e($cellText($column, $value)) ?>
                                    </span>
                                <?php else: ?>
                                    <?= e($cellText($column, $value)) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($type === 'pembelian'): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm py-0"
                                   href="<?= e(base_url('laporan/po/' . (int) ($row['id'] ?? 0) . '/cetak')) ?>"
                                   target="_blank" rel="noopener">Cetak PO</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($notes !== []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong class="text-navy">Catatan &amp; definisi</strong></div>
        <div class="card-body small text-muted">
            <ul class="mb-0 ps-3">
                <?php foreach ($notes as $note): ?>
                    <li class="mb-1"><?= e((string) $note) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="mt-2">
                Dibuat: <?= e(tanggal_id((string) ($report['generated_at'] ?? ''), true)) ?>
                &middot; Sumber: database SmartPartMan (bukan cache).
            </div>
        </div>
    </div>
<?php endif; ?>
