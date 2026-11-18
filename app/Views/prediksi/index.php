<?php
/**
 * Indeks prediksi stok (Fase 7 — AI Stock Prediction).
 *
 * @var array<string,string> $filters
 * @var array<int,array<string,mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string,mixed> $summary
 * @var bool $canRun
 * @var array<int,int> $periods
 */
use App\Models\AiPrediction;
use App\Services\PredictionService;

$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return $params === [] ? '' : '?' . http_build_query($params);
};

$qty = static function (float|int|string|null $value): string {
    if ($value === null || $value === '') {
        return '-';
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
        default       => '<span class="badge text-bg-light border text-muted">Belum ada</span>',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Prediksi Stok</h2>
        <p class="text-muted small mb-0">
            Estimasi permintaan, risiko kehabisan stok, dan saran jumlah reorder hasil model
            <strong>Moving Average</strong> &amp; <strong>Linear Regression</strong> (Safety Stock / ROP).
            AI hanya memberi <strong>rekomendasi</strong> — tidak ada pemesanan otomatis.
        </p>
    </div>
</div>

<?php
$auto = $automation ?? [];
$autoSummary = $automationSummary ?? [];
$autoStatus = (string) ($auto['last_status'] ?? 'never');
$autoLast = $autoSummary['latest_at'] ?? null;
$autoPending = (int) ($auto['pending_count'] ?? 0);
$autoWhen = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Belum pernah';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('d/m/Y H:i', $ts);
};
?>
<div class="alert alert-light border small d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <strong class="text-navy">Prediksi otomatis aktif.</strong>
        Hasil diperbarui otomatis setelah perubahan stok (barang masuk/keluar, penerimaan PO,
        penyesuaian, koreksi) dan disegarkan berkala.
        <div class="text-muted">
            Pembaruan terakhir: <?= e($autoWhen(is_string($autoLast) ? $autoLast : null)) ?>
            <?php if ($autoPending > 0): ?>
                &middot; <?= (int) $autoPending ?> sparepart menunggu pembaruan
            <?php endif; ?>
        </div>
    </div>
    <?php if ($autoStatus === 'error'): ?>
        <span class="badge text-bg-warning text-dark">Pembaruan terakhir gagal — menampilkan hasil terakhir yang tersimpan</span>
    <?php elseif ($autoStatus === 'partial'): ?>
        <span class="badge text-bg-warning text-dark">Sebagian pembaruan gagal</span>
    <?php elseif ($autoStatus === 'ok'): ?>
        <span class="badge text-bg-success">Berhasil diperbarui</span>
    <?php else: ?>
        <span class="badge text-bg-secondary">Menunggu pembaruan pertama</span>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Sparepart dianalisis</div>
                <div class="fs-4 fw-semibold text-navy"><?= (int) ($summary['total'] ?? 0) ?></div>
                <div class="small text-muted">dari <?= (int) ($summary['active'] ?? 0) ?> aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Belum dianalisis</div>
                <div class="fs-4 fw-semibold <?= (int) ($summary['pending'] ?? 0) > 0 ? 'text-warning' : 'text-success' ?>">
                    <?= (int) ($summary['pending'] ?? 0) ?>
                </div>
                <div class="small text-muted">sparepart aktif</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Risiko tinggi</div>
                <div class="fs-4 fw-semibold <?= (int) ($summary['risk_high'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= (int) ($summary['risk_high'] ?? 0) ?>
                </div>
                <div class="small text-muted">prediksi terbaru</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Analisis terakhir</div>
                <div class="fs-6 fw-semibold text-navy">
                    <?= ($summary['latest_at'] ?? null) !== null ? e(date('d-m-Y H:i', strtotime((string) $summary['latest_at']))) : '—' ?>
                </div>
                <div class="small text-muted">waktu server</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('prediksi')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['q']) ?>" placeholder="Kode / nama sparepart">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="risiko">Risiko stockout</label>
                <select class="form-select form-select-sm" id="risiko" name="risiko">
                    <option value="">Semua</option>
                    <option value="tinggi" <?= $filters['risiko'] === 'tinggi' ? 'selected' : '' ?>>Tinggi</option>
                    <option value="sedang" <?= $filters['risiko'] === 'sedang' ? 'selected' : '' ?>>Sedang</option>
                    <option value="rendah" <?= $filters['risiko'] === 'rendah' ? 'selected' : '' ?>>Rendah</option>
                    <option value="ada" <?= $filters['risiko'] === 'ada' ? 'selected' : '' ?>>Sudah dianalisis</option>
                    <option value="belum" <?= $filters['risiko'] === 'belum' ? 'selected' : '' ?>>Belum dianalisis</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('prediksi')) ?>">Bersihkan</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Sparepart</th>
                <th class="text-end">Stok</th>
                <th class="text-end">Minimum</th>
                <th>Periode</th>
                <th>Metode</th>
                <th class="text-end">Estimasi permintaan</th>
                <th>Risiko</th>
                <th class="text-end">Saran reorder</th>
                <th>Data</th>
                <th>Dianalisis</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="11" class="text-center text-muted small py-4">
                    Tidak ada sparepart yang cocok dengan filter.
                    <?php if (($summary['total'] ?? 0) === 0): ?>
                        <div class="mt-1">
                            Belum ada prediksi berjalan —<?php if ($canRun): ?>
                                pilih sparepart lalu tekan <strong>Jalankan Prediksi</strong> pada halaman detail.
                            <?php else: ?>
                                menunggu Partman menjalankan analisis.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="small">
                        <span class="fw-semibold"><?= e((string) $row['code']) ?></span>
                        <div class="text-muted"><?= e((string) $row['name']) ?></div>
                    </td>
                    <td class="text-end small"><?= e($qty($row['stock'])) ?> <?= e((string) $row['unit']) ?></td>
                    <td class="text-end small"><?= e($qty($row['min_stock'])) ?></td>
                    <td class="small">
                        <?php if ($row['prediction_id'] !== null): ?>
                            <?= (int) $row['period_days'] ?> hari
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($row['prediction_id'] !== null): ?>
                            <?= e(PredictionService::methodLabel((string) $row['method'])) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end small fw-semibold">
                        <?php if ($row['predicted_demand'] !== null): ?>
                            <?= e($qty($row['predicted_demand'])) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $row['prediction_id'] !== null ? $riskBadge($row['stockout_risk'] !== null ? (string) $row['stockout_risk'] : null) : $riskBadge(null) ?></td>
                    <td class="text-end small fw-semibold">
                        <?php if ($row['recommended_qty'] !== null && (float) $row['recommended_qty'] > 0): ?>
                            <?= e($qty($row['recommended_qty'])) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $suffBadge($row['data_sufficiency'] !== null ? (string) $row['data_sufficiency'] : null) ?></td>
                    <td class="small text-muted">
                        <?php if ($row['processed_at'] !== null): ?>
                            <?= e(date('d-m-Y H:i', strtotime((string) $row['processed_at']))) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('prediksi/' . (int) $row['sparepart_id'])) ?>">Detail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="small text-muted">Halaman <?= (int) $page ?> dari <?= (int) $pages ?></span>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(base_url('prediksi') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('prediksi') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="alert alert-light border small mt-3 mb-0">
    <strong>Cara baca:</strong> “Estimasi permintaan” adalah proyeksi keluaran barang untuk periode terpilih;
    “Saran reorder” = permintaan − stok + safety stock + stok minimum (dibulatkan ke atas, nol bila tidak perlu).
    <strong>Kecukupan data</strong>: prediksi <em>tidak dihitung</em> bila riwayat terlalu sedikit
    (ambang awal <strong>30 hari data historis</strong>) — tidak ada angka karangan. Periode target: <?= e(implode(' / ', array_map(static fn (int $p): string => $p . ' hari', $periods))) ?>.
    <?php if ($canRun): ?>
        Anda (Partman) menjalankan analisis dari halaman detail; mengubah jumlah dari saran
        <strong>wajib mencantumkan alasan</strong> saat membuat permintaan reorder.
    <?php endif; ?>
</div>
