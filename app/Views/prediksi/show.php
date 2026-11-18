<?php
/**
 * Detail prediksi stok per sparepart (Fase 7 — AI Stock Prediction).
 *
 * @var array<string,mixed> $sparepart
 * @var array<string,mixed>|null $latest
 * @var array<int,array<string,mixed>> $history
 * @var bool $canRun
 * @var array<int,int> $periods
 * @var array<int,int> $historyOptions
 * @var int $defaultPeriod
 * @var int $defaultHistory
 * @var string $unit
 */
use App\Models\AiPrediction;
use App\Services\PredictionService;

$qty = static function (float|int|string|null $value, bool $dash = true): string {
    if ($value === null || $value === '') {
        return $dash ? '—' : '0';
    }
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
};

$riskBadge = static function (?string $risk): string {
    return match ($risk) {
        'tinggi' => '<span class="badge text-bg-danger">Tinggi</span>',
        'sedang' => '<span class="badge text-bg-warning text-dark">Sedang</span>',
        'rendah' => '<span class="badge text-bg-success">Rendah</span>',
        default  => '<span class="badge text-bg-light border text-muted">Belum dinilai</span>',
    };
};

$suffBadge = static function (?string $s): string {
    return match ($s) {
        'cukup'       => '<span class="badge text-bg-success">Cukup</span>',
        'terbatas'    => '<span class="badge text-bg-warning text-dark">Terbatas</span>',
        'tidak_cukup' => '<span class="badge text-bg-secondary">Data terbatas</span>',
        default       => '<span class="badge text-bg-light border text-muted">—</span>',
    };
};

$details = $latest !== null ? AiPrediction::details($latest) : [];
$evaluation = is_array($details['evaluation'] ?? null) ? $details['evaluation'] : [];
$comparison = is_array($details['comparison'] ?? null) ? $details['comparison'] : [];
$methods = is_array($details['methods'] ?? null) ? $details['methods'] : [];
$parameters = is_array($details['parameters'] ?? null) ? $details['parameters'] : [];
$libraries = is_array($details['library_versions'] ?? null) ? $details['library_versions'] : [];
$leadTime = is_array($details['lead_time'] ?? null) ? $details['lead_time'] : [];
$classification = is_array($details['classification'] ?? null) ? $details['classification'] : [];
$analysisMeta = is_array($details['analysis'] ?? null) ? $details['analysis'] : [];

$warningsList = [];
if ($latest !== null && trim((string) ($latest['warnings'] ?? '')) !== '') {
    $warningsList = array_values(array_filter(explode("\n", (string) $latest['warnings'])));
}

$asOf = (string) ($analysisMeta['as_of'] ?? date('Y-m-d'));
$daysToMin = $latest['forecast_days_to_min'] ?? null;
$minDate = null;
if ($latest !== null && $daysToMin !== null) {
    $minDate = date('d-m-Y', strtotime($asOf . ' +' . (int) $daysToMin . ' days'));
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-muted mb-1">
            <a href="<?= e(base_url('prediksi')) ?>">Prediksi Stok</a> /
            <?= e((string) $sparepart['code']) ?>
        </div>
        <h1 class="h4 text-navy mb-1"><?= e((string) $sparepart['code']) ?> — <?= e((string) $sparepart['name']) ?></h1>
        <div class="d-flex flex-wrap gap-2 small">
            <span class="badge text-bg-navy">Stok: <?= e($qty($sparepart['stock'])) ?> <?= e($unit) ?></span>
            <span class="badge text-bg-light border">Minimum: <?= e($qty($sparepart['min_stock'])) ?></span>
            <?php if ((int) $sparepart['is_active'] !== 1): ?>
                <span class="badge text-bg-secondary">Nonaktif</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canRun): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('reorder/tambah')) ?>">Buat Reorder</a>
        <?php endif; ?>
        <a class="btn btn-navy btn-sm" href="<?= e(base_url('prediksi')) ?>">Daftar Prediksi</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($latest === null): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center py-5">
                    <div class="fs-1">🔬</div>
                    <h2 class="h6 text-navy mt-2">Belum ada prediksi untuk sparepart ini</h2>
                    <p class="text-muted small mb-2">
                        Jalankan analisis untuk memperkirakan permintaan, risiko stockout, dan saran
                        jumlah reorder dari riwayat barang keluar.
                    </p>
                    <?php if ($canRun): ?>
                        <p class="small text-muted mb-0">Gunakan formulir di samping (periode 7 / 30 / 90 hari).</p>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Analisis dijalankan oleh Partman.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php if ($warningsList !== []): ?>
                <div class="alert alert-warning border-0 shadow-sm small">
                    <div class="fw-semibold mb-1">Peringatan analisis</div>
                    <ul class="ps-3 mb-0">
                        <?php foreach ($warningsList as $warning): ?>
                            <li><?= e($warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong class="text-navy">Hasil analisis — <?= (int) $latest['period_days'] ?> hari</strong>
                    <span class="small text-muted">
                        <?= e(PredictionService::methodLabel((string) $latest['method'])) ?>
                        &middot; <?= e(date('d-m-Y H:i', strtotime((string) $latest['processed_at']))) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?= $suffBadge($latest['data_sufficiency'] !== null ? (string) $latest['data_sufficiency'] : null) ?>
                        <?= $riskBadge($latest['stockout_risk'] !== null ? (string) $latest['stockout_risk'] : null) ?>
                        <?php if (isset($classification['class'])): ?>
                            <span class="badge text-bg-light border">
                                <?= e(AiPrediction::CLASSIFICATION_LABELS[(string) $classification['class']] ?? (string) $classification['class']) ?>
                                (<?= (int) ($classification['period_days'] ?? 0) ?> hari: <?= e($qty($classification['qty_out'] ?? null)) ?> keluar)
                            </span>
                        <?php endif; ?>
                        <?php if (($latest['method'] ?? '') === 'belum_dihitung'): ?>
                            <span class="badge text-bg-secondary">Prediksi tidak dihitung — data terbatas</span>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Estimasi permintaan (<?= (int) $latest['period_days'] ?> hari)</div>
                            <div class="fs-5 fw-semibold text-navy">
                                <?= $latest['predicted_demand'] !== null ? e($qty($latest['predicted_demand'])) . ' ' . e($unit) : '—' ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Laju keluar harian</div>
                            <div class="fs-5 fw-semibold text-navy">
                                <?php $dailyRate = $details['daily_rate'] ?? null; ?>
                                <?= $dailyRate !== null ? e($qty($dailyRate)) . ' ' . e($unit) . '/hari' : '—' ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Stok mencapai minimum</div>
                            <div class="fs-5 fw-semibold <?= $daysToMin !== null && (int) $daysToMin <= (int) (($leadTime['days'] ?? 7)) ? 'text-danger' : 'text-navy' ?>">
                                <?php if ($daysToMin === null): ?>
                                    —
                                <?php elseif ((int) $daysToMin === 0): ?>
                                    sudah di bawah/ sama dengan minimum
                                <?php else: ?>
                                    ± <?= (int) $daysToMin ?> hari
                                <?php endif; ?>
                            </div>
                            <?php if ($minDate !== null && (int) $daysToMin > 0): ?>
                                <div class="small text-muted">± <?= e($minDate) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Stok mencapai nol</div>
                            <div class="fs-5 fw-semibold text-navy">
                                <?php $daysToZero = $latest['forecast_days_to_zero'] ?? null; ?>
                                <?php if ($daysToZero === null): ?>
                                    —
                                <?php elseif ((int) $daysToZero === 0): ?>
                                    stok 0
                                <?php else: ?>
                                    ± <?= (int) $daysToZero ?> hari
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Risiko stockout</div>
                            <div class="fs-5"><?= $riskBadge($latest['stockout_risk'] !== null ? (string) $latest['stockout_risk'] : null) ?></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small">Saran jumlah reorder</div>
                            <div class="fs-5 fw-semibold text-success">
                                <?php if ($latest['recommended_qty'] !== null && (float) $latest['recommended_qty'] > 0): ?>
                                    <?= e($qty($latest['recommended_qty'])) ?> <?= e($unit) ?>
                                <?php else: ?>
                                    tidak perlu
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (trim((string) ($details['reason'] ?? '')) !== ''): ?>
                        <div class="alert alert-light border small mt-3 mb-0">
                            <strong>Kecukupan data:</strong> <?= e((string) $details['reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><strong class="text-navy">Evaluasi model (data kronologis)</strong></div>
                        <div class="card-body small">
                            <?php if (($evaluation['performed'] ?? false) === true): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Metode</th>
                                            <th class="text-end">MAE</th>
                                            <th class="text-end">RMSE</th>
                                            <th class="text-end">MAPE (%)</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <tr>
                                            <td>Moving Average</td>
                                            <td class="text-end"><?= e($qty($evaluation['moving_average']['mae'] ?? null)) ?></td>
                                            <td class="text-end"><?= e($qty($evaluation['moving_average']['rmse'] ?? null)) ?></td>
                                            <td class="text-end"><?= e($qty($evaluation['moving_average']['mape'] ?? null)) ?></td>
                                        </tr>
                                        <tr>
                                            <td>Linear Regression</td>
                                            <td class="text-end"><?= e($qty($evaluation['linear_regression']['mae'] ?? null)) ?></td>
                                            <td class="text-end"><?= e($qty($evaluation['linear_regression']['rmse'] ?? null)) ?></td>
                                            <td class="text-end"><?= e($qty($evaluation['linear_regression']['mape'] ?? null)) ?></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mb-1">
                                    <strong>Metode terpilih:</strong>
                                    <?= e(PredictionService::methodLabel((string) ($comparison['selected_method'] ?? ''))) ?>
                                </div>
                                <div class="text-muted"><?= e((string) ($comparison['rule'] ?? '')) ?></div>
                                <div class="text-muted mt-1"><?= e((string) ($evaluation['split'] ?? '')) ?></div>
                            <?php else: ?>
                                <div class="text-muted mb-1"><?= e((string) ($evaluation['note'] ?? 'Evaluasi tidak tersedia.')) ?></div>
                                <div class="text-muted">Prediksi utama memakai
                                    <?= e(PredictionService::methodLabel((string) ($comparison['selected_method'] ?? 'moving_average'))) ?>.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><strong class="text-navy">Safety Stock &amp; Reorder Point</strong></div>
                        <div class="card-body small">
                            <?php if (($details['safety_stock'] ?? null) !== null): ?>
                                <div class="d-flex justify-content-between border-bottom py-1">
                                    <span>Safety Stock</span>
                                    <strong><?= e($qty($details['safety_stock'])) ?> <?= e($unit) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-1">
                                    <span>Reorder Point (ROP)</span>
                                    <strong><?= e($qty($details['reorder_point'] ?? null)) ?> <?= e($unit) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-1">
                                    <span>Lead time dipakai</span>
                                    <strong>
                                        <?= (int) (($leadTime['days'] ?? 0)) ?> hari
                                        <?php if (($leadTime['assumed'] ?? true) === true): ?>
                                            <span class="badge text-bg-warning text-dark">Asumsi</span>
                                        <?php elseif (($leadTime['source'] ?? '') === 'manual_override'): ?>
                                            <span class="badge text-bg-info text-dark">Override manual</span>
                                        <?php elseif (($leadTime['source'] ?? '') === 'receipt_history'): ?>
                                            <span class="badge text-bg-success">Riwayat penerimaan</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">Master supplier</span>
                                        <?php endif; ?>
                                    </strong>
                                </div>
                                <div class="text-muted mt-1">
                                    <?php if (($leadTime['assumed'] ?? true) === true): ?>
                                        Data lead time tidak tersedia (riwayat penerimaan maupun master supplier kosong) —
                                        SS/ROP memakai <strong>asumsi</strong> dan
                                        <strong>ROP belum dapat dihitung secara andal</strong>.
                                        Selesaikan penerimaan PO, isi <code>lead time</code> pada master supplier,
                                        atau lakukan override manual beralasan lalu jalankan ulang.
                                    <?php elseif (($leadTime['source'] ?? '') === 'manual_override'): ?>
                                        Lead time di-<strong>override manual</strong> oleh Partman
                                        (<?= (int) ($leadTime['days'] ?? 0) ?> hari).
                                        Nilai otomatis saat itu:
                                        <?= (int) ($leadTime['auto_days'] ?? 0) ?> hari
                                        (<?= e(PredictionService::leadTimeSourceLabel((string) ($leadTime['auto_source'] ?? ''))) ?>).
                                        <div class="mt-1">
                                            <strong>Alasan override:</strong>
                                            <?= e((string) ($leadTime['reason'] ?? '—')) ?>
                                            <span class="text-muted">(tercatat di audit log)</span>
                                        </div>
                                    <?php elseif (($leadTime['source'] ?? '') === 'receipt_history'): ?>
                                        Lead time dihitung dari median selisih tanggal PO → penerimaan
                                        (<?= (int) (($leadTime['samples'] ?? 0)) ?> data penerimaan) — dinilai <strong>andal</strong>.
                                    <?php else: ?>
                                        Lead time berasal dari master supplier (±95% layanan).
                                        Z Safety Stock: <?= e((string) ($parameters['safety_z'] ?? '1,65')) ?>,
                                        dihitung dari volatilitas riwayat harian × √lead time.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted">Perhitungan tidak tersedia - data terbatas.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong class="text-navy">Parameter &amp; versi model</strong></div>
                <div class="card-body small">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td class="text-muted">Versi model</td><td class="fw-semibold"><?= e((string) $latest['model_version']) ?></td></tr>
                                <tr><td class="text-muted">Python</td><td><?= e((string) ($details['python_version'] ?? '—')) ?></td></tr>
                                <tr><td class="text-muted">pandas / scikit-learn / numpy</td>
                                    <td>
                                        <?= e((string) ($libraries['pandas'] ?? '—')) ?> /
                                        <?= e((string) ($libraries['scikit_learn'] ?? '—')) ?> /
                                        <?= e((string) ($libraries['numpy'] ?? '—')) ?>
                                    </td></tr>
                                <tr><td class="text-muted">Rentang riwayat</td>
                                    <td>
                                        <?php $range = is_array($details['analysis']['history_range'] ?? null) ? $details['analysis']['history_range'] : []; ?>
                                        <?= e((string) ($range['start'] ?? '—')) ?> → <?= e((string) ($range['end'] ?? '—')) ?>
                                        (<?= (int) (($range['days'] ?? 0)) ?> hari,
                                        <?= (int) (($range['days_with_data'] ?? 0)) ?> berdata,
                                        <?= (int) (($range['tx_count'] ?? 0)) ?> transaksi)
                                    </td></tr>
                                <tr><td class="text-muted">Waktu analisis</td>
                                    <td><?= e((string) ($details['analysis']['generated_at'] ?? '—')) ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td class="text-muted">Jendela Moving Average</td><td><?= (int) (($parameters['ma_window'] ?? 7)) ?> hari</td></tr>
                                <tr><td class="text-muted">Z Safety Stock</td><td><?= e((string) ($parameters['safety_z'] ?? '—')) ?></td></tr>
                                <tr><td class="text-muted">Pembagian data latih/uji</td>
                                    <td><?= e((string) (($parameters['test_ratio'] ?? '—'))) ?> porsi uji (kronologis)</td></tr>
                                <tr><td class="text-muted">Ambang kecukupan</td>
                                    <td>
                                        ≥ <?= (int) (($parameters['min_days_with_data'] ?? 0)) ?> hari berdata &amp;
                                        ≥ <?= (int) (($parameters['min_transactions'] ?? 0)) ?> transaksi
                                    </td></tr>
                                <tr><td class="text-muted">Integrasi</td>
                                    <td>Proses CLI lokal (proc_open, tanpa jaringan)</td></tr>
                            </table>
                        </div>
                    </div>
                    <?php if (isset($methods['moving_average'], $methods['linear_regression'])): ?>
                        <div class="border-top pt-2 mt-1 text-muted">
                            Prakiraan per metode untuk <?= (int) $latest['period_days'] ?> hari:
                            Moving Average <strong><?= e($qty($methods['moving_average']['forecast'] ?? null)) ?></strong>
                            (rata-rata <?= e($qty($methods['moving_average']['daily_avg'] ?? null)) ?>/hari)
                            &middot; Linear Regression
                            <strong><?= e($qty($methods['linear_regression']['forecast'] ?? null)) ?></strong>
                            (kemiringan <?= e((string) ($methods['linear_regression']['slope_per_day'] ?? '0')) ?>/hari).
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (trim((string) ($details['reason'] ?? '')) === '' && $latest['data_sufficiency'] === 'tidak_cukup'): ?>
                <div class="alert alert-secondary small">
                    Data terbatas — prediksi tidak dihitung agar tidak mengarang angka.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($history !== []): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <strong class="text-navy">Riwayat analisis</strong>
                    <span class="small text-muted">(snapshot disimpan — tidak diubah)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Waktu</th>
                            <th class="text-end">Periode</th>
                            <th>Metode</th>
                            <th class="text-end">Estimasi</th>
                            <th>Risiko</th>
                            <th>Data</th>
                            <th class="text-end">Saran</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $item): ?>
                            <tr class="<?= $latest !== null && (int) $item['id'] === (int) $latest['id'] ? 'table-primary' : '' ?>>
                                <td class="small"><?= e(date('d-m-Y H:i', strtotime((string) $item['processed_at']))) ?></td>
                                <td class="text-end small"><?= (int) $item['period_days'] ?> hari</td>
                                <td class="small"><?= e(PredictionService::methodLabel((string) $item['method'])) ?></td>
                                <td class="text-end small"><?= $item['predicted_demand'] !== null ? e($qty($item['predicted_demand'])) : '—' ?></td>
                                <td class="small"><?= $riskBadge($item['stockout_risk'] !== null ? (string) $item['stockout_risk'] : null) ?></td>
                                <td class="small"><?= $suffBadge($item['data_sufficiency'] !== null ? (string) $item['data_sufficiency'] : null) ?></td>
                                <td class="text-end small fw-semibold">
                                    <?php if ($item['recommended_qty'] !== null && (float) $item['recommended_qty'] > 0): ?>
                                        <?= e($qty($item['recommended_qty'])) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <?php if ($canRun): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong class="text-navy">Jalankan analisis</strong></div>
                <div class="card-body">
                    <form method="post" action="<?= e(base_url('prediksi/' . (int) $sparepart['id'] . '/jalankan')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <div class="form-label small mb-1">Periode target <span class="text-danger">*</span></div>
                            <?php foreach ($periods as $index => $period): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="period_days" id="period<?= (int) $period ?>"
                                           value="<?= (int) $period ?>" <?= (int) $period === (int) $defaultPeriod ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="period<?= (int) $period ?>">
                                        <?= (int) $period ?> hari
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="history_days">Rentang riwayat</label>
                            <select class="form-select form-select-sm" id="history_days" name="history_days">
                                <?php foreach ($historyOptions as $option): ?>
                                    <option value="<?= (int) $option ?>" <?= (int) $option === (int) $defaultHistory ? 'selected' : '' ?>>
                                        <?= (int) $option ?> hari terakhir
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="lead_time_override_days">
                                Override lead time (hari) <span class="text-muted fw-normal">— opsional</span>
                            </label>
                            <input class="form-control form-control-sm" type="number" id="lead_time_override_days"
                                   name="lead_time_override_days" min="1" max="365" step="1"
                                   placeholder="mis. 10">
                            <div class="form-text">
                                Kosongkan untuk otomatis: riwayat penerimaan → master supplier → asumsi 7 hari.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="lead_time_reason">
                                Alasan override <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control form-control-sm" id="lead_time_reason"
                                      name="lead_time_reason" rows="2"
                                      placeholder="Wajib bila mengisi override lead time"></textarea>
                            <div class="form-text">Dicatat pada audit log (tanpa rahasia).</div>
                        </div>
                        <button class="btn btn-navy w-100" type="submit">Jalankan Prediksi</button>
                        <div class="form-text mt-2">
                            Data diambil dari riwayat transaksi; proses prediksi berjalan lokal
                            (± beberapa detik). Gagal atau data terbatas → tidak ada angka karangan.
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body small text-muted">
                    Menjalankan analisis hanya dapat dilakukan <strong>Partman</strong>.
                    Anda tetap dapat melihat seluruh hasil &amp; riwayat prediksi.
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body small">
                <h3 class="h6 text-navy">Aturan modul prediksi</h3>
                <ul class="ps-3 mb-0">
                    <li>Hasil bersifat <strong>rekomendasi</strong> — tidak ada pemesanan otomatis.</li>
                    <li>Data di bawah ambang minimum → status <strong>data terbatas</strong>; prediksi tidak dihitung agar tidak mengarang angka.</li>
                    <li>Lead time diprioritaskan dari <strong>riwayat penerimaan</strong> PO; bila kosong → master supplier → <strong>asumsi 7 hari</strong>. Partman dapat melakukan <strong>override manual dengan alasan wajib</strong>, dicatat pada audit log.</li>
                    <li>Mengubah jumlah dari saran saat membuat reorder <strong>wajib alasan</strong>.</li>
                    <li>Gagalnya prediksi <strong>tidak mengganggu</strong> transaksi inventory.</li>
                    <li>Model hanya memakai data lampau (latih/uji kronologis).</li>
                </ul>
            </div>
        </div>

        <?php
        $shortfall = (float) $sparepart['stock'] - (float) $sparepart['min_stock'];
        if ($shortfall < 0):
            ?>
            <div class="alert alert-danger small">
                Stok saat ini sudah di bawah minimum (kurang <?= e($qty(abs($shortfall))) ?> <?= e($unit) ?>).
                <a href="<?= e(base_url('reorder/tambah')) ?>">Buat permintaan reorder</a>
            </div>
        <?php endif; ?>
    </div>
</div>
