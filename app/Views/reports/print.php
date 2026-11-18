<?php
/**
 * Cetak laporan (Fase 5). Memakai layout print (tanpa sidebar).
 *
 * @var array<string,mixed> $report
 * @var array{name:string,address:string,phone:string,email:string,note:string} $identity
 * @var string $orientation
 * @var string $backUrl
 * @var string $pdfUrl
 */
use App\Services\ChartService;
use App\Services\ReportService;

$report = $report ?? [];
$identity = $identity ?? ['name' => 'SmartPartMan', 'address' => '', 'phone' => '', 'email' => '', 'note' => ''];
$orientation = $orientation ?? 'portrait';
$meta = $report['meta'] ?? [];
$rows = $report['rows'] ?? [];
$summary = $report['summary'] ?? [];
$chart = $report['chart'] ?? null;
$notes = $report['notes'] ?? [];
$filterText = $report['filter_text'] ?? [];
$columns = $meta['columns'] ?? [];
$type = (string) ($report['type'] ?? '');
$totalWidth = 0;
foreach ($columns as $column) {
    $totalWidth += max(1, (int) ($column['width'] ?? 20));
}
$qty = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    $number = (float) $value;
    $decimals = floor($number) === $number ? 0 : 2;
    return number_format($number, $decimals, ',', '.');
};
?>
<div class="doc-header d-flex justify-content-between gap-3">
    <div class="d-flex align-items-center gap-2">
        <?php if (($identity['logo'] ?? '') !== ''): ?>
            <img src="<?= e(logo_url((string) $identity['logo'])) ?>" alt="Logo" class="doc-logo">
        <?php endif; ?>
        <div>
            <div class="doc-workshop"><?= e((string) $identity['name']) ?></div>
            <div class="doc-meta">
                <?php if (($identity['address'] ?? '') !== ''): ?>
                    <?= e((string) $identity['address']) ?><br>
                <?php endif; ?>
                <?php if (($identity['phone'] ?? '') !== ''): ?>
                    Telp. <?= e((string) $identity['phone']) ?>
                <?php endif; ?>
                <?php if (($identity['email'] ?? '') !== ''): ?>
                    &middot; <?= e((string) $identity['email']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div>
        <div class="doc-title"><?= e((string) ($meta['title'] ?? 'Laporan')) ?></div>
        <div class="doc-subtitle">SmartPartMan &middot; <?= e(tanggal_id(date('Y-m-d'), true)) ?></div>
    </div>
</div>

<?php if ($filterText !== []): ?>
    <div class="doc-filters">
        <?php foreach ($filterText as $line): ?>
            <span><?= e((string) $line) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($type === 'ai'): ?>
    <?php
    $aiActive = false;
    foreach ($summary as $summaryItem) {
        if (($summaryItem['label'] ?? '') === 'Status modul prediksi') {
            $aiActive = (int) ($summaryItem['raw'] ?? 0) > 0;
        }
    }
    ?>
    <div class="alert alert-<?= $aiActive ? 'light' : 'info' ?> py-2 small">
        <?php if ($aiActive): ?>
            Rekomendasi terbaru per sparepart hasil pipeline Python (Moving Average / Linear Regression,
            Safety Stock &amp; ROP). Model hanya memakai data lampau; rekomendasi = usulan, bukan pesanan otomatis.
            Kolom Stok/Minimum adalah data aktual.
        <?php else: ?>
            Belum ada analisis prediksi — tabel ini belum memuat angka prediksi. Jalankan dari menu Prediksi Stok (Partman).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($summary !== []): ?>
    <div class="doc-summary">
        <?php foreach ($summary as $item): ?>
            <div class="item">
                <div class="label"><?= e((string) ($item['label'] ?? '')) ?></div>
                <div class="value"><?= e((string) ($item['value'] ?? '-')) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (is_array($chart) && ($chart['labels'] ?? []) !== []): ?>
    <div class="mb-3">
        <div class="doc-section-title mb-1"><?= e((string) ($chart['title'] ?? 'Grafik')) ?></div>
        <?php
        $series = $chart['series'] ?? [];
        $options = [
            'title' => (string) ($chart['title'] ?? 'Grafik'),
            'money' => (bool) ($chart['money'] ?? false),
            'height' => 210,
            'empty' => 'Belum ada data untuk digrafikkan.',
        ];
        echo ($chart['kind'] ?? 'bar') === 'line'
            ? ChartService::line($chart['labels'], $series, $options)
            : ChartService::bar($chart['labels'], $series, $options);
        ?>
    </div>
<?php endif; ?>

<table class="doc-table">
    <?php if ($columns !== [] && $totalWidth > 0): ?>
        <colgroup>
            <?php foreach ($columns as $column): ?>
                <col style="width: <?= number_format((max(1, (int) ($column['width'] ?? 20)) / $totalWidth) * 100, 3, '.', '') ?>%">
            <?php endforeach; ?>
        </colgroup>
    <?php endif; ?>
    <thead>
    <tr>
        <th style="width: 34px;">#</th>
        <?php foreach ($columns as $column): ?>
            <th class="<?= ($column['align'] ?? 'left') === 'right' ? 'num' : '' ?>">
                <?= e((string) ($column['label'] ?? '')) ?>
            </th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php if ($rows === []): ?>
        <tr>
            <td colspan="<?= count($columns) + 1 ?>" class="text-center text-muted py-3">
                Tidak ada data pada filter ini.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td class="num"><?= $index + 1 ?></td>
                <?php foreach ($columns as $column): ?>
                    <td class="<?= ($column['align'] ?? 'left') === 'right' ? 'num' : '' ?>">
                        <?= e(ReportService::formatCell($column, $row[(string) ($column['key'] ?? '')] ?? null)) ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div class="doc-notes">
    <div class="mt-2">
        Jumlah baris: <strong><?= number_format(count($rows), 0, ',', '.') ?></strong>
        <?php if (count($rows) >= ReportService::MAX_ROWS): ?>
            (batas tampilan <?= number_format(ReportService::MAX_ROWS, 0, ',', '.') ?> baris)
        <?php endif; ?>
        &middot; Dibuat <?= e(tanggal_id((string) ($report['generated_at'] ?? ''), true)) ?>.
    </div>
    <?php if ($notes !== []): ?>
        <div class="mt-2 fw-semibold">Catatan &amp; definisi</div>
        <ul>
            <?php foreach ($notes as $note): ?>
                <li><?= e((string) $note) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (($identity['note'] ?? '') !== ''): ?>
        <div class="mt-2"><?= e((string) $identity['note']) ?></div>
    <?php endif; ?>
</div>
