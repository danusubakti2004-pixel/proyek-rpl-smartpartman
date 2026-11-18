<?php
/**
 * Bagian dashboard: Rekomendasi Prediksi Persediaan (otomatis).
 *
 * Dipakai oleh dashboard Admin, Partman, dan Kepala Bengkel dengan data yang
 * SAMA (DashboardService::predictionInsight → PredictionAutomation::insight),
 * sehingga angka antar-role tidak berbeda.
 *
 * @var array<string,mixed> $prediction
 */

$prediction = $prediction ?? [];
$pRows = is_array($prediction['rows'] ?? null) ? $prediction['rows'] : [];
$pSummary = is_array($prediction['summary'] ?? null) ? $prediction['summary'] : [];
$pAuto = is_array($prediction['automation'] ?? null) ? $prediction['automation'] : [];
$pPeriod = (int) ($prediction['period_days'] ?? 0);

$num = static function (mixed $value, int $decimals = 2): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float) $value, $decimals, ',', '.');
};
$when = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Belum ada';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('d/m/Y H:i', $ts);
};
$pStatusBadge = static function (array $row): string {
    $class = match (true) {
        ($row['sufficiency'] ?? '') === 'tidak_cukup' => 'secondary',
        !($row['has_prediction'] ?? false)             => 'secondary',
        (int) ($row['severity'] ?? 0) >= 4             => 'danger',
        (int) ($row['severity'] ?? 0) === 3            => 'warning',
        (int) ($row['severity'] ?? 0) === 2            => 'info',
        default                                        => 'success',
    };
    return '<span class="badge text-bg-' . e($class) . '">' . e((string) ($row['status'] ?? '—')) . '</span>';
};

$pLastStatus = (string) ($pSummary['last_status'] ?? $pAuto['last_status'] ?? 'never');
$pLastAt = $pSummary['latest_at'] ?? null;
$pPending = (int) ($pSummary['pending'] ?? 0);
?>
<div class="card border-0 shadow-sm mb-3" id="rekomendasi-prediksi">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <strong class="text-navy">Rekomendasi Prediksi Persediaan</strong>
            <div class="small text-muted">
                Otomatis dari data persediaan aktual
                <?php if ($pPeriod > 0): ?>&middot; periode <?= (int) $pPeriod ?> hari<?php endif; ?>
            </div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('prediksi')) ?>">Buka Prediksi Stok</a>
    </div>
    <div class="card-body">
        <?php if ($pLastStatus === 'error'): ?>
            <div class="alert alert-warning small mb-3">
                <strong>Pembaruan prediksi terakhir gagal.</strong>
                Hasil terakhir yang tersimpan tetap ditampilkan sebagai data lama, bukan hasil terbaru.
                <?php if (!empty($pSummary['last_ok_at'])): ?>
                    Terakhir berhasil: <?= e($when((string) $pSummary['last_ok_at'])) ?>.
                <?php endif; ?>
            </div>
        <?php elseif ($pLastStatus === 'partial'): ?>
            <div class="alert alert-warning small mb-3">
                Sebagian prediksi gagal diperbarui. Data terakhir yang berhasil tetap ditampilkan.
            </div>
        <?php elseif ($pPending > 0): ?>
            <div class="alert alert-info small mb-3">
                <?= (int) $pPending ?> sparepart menunggu pembaruan prediksi setelah perubahan stok.
            </div>
        <?php endif; ?>

        <div class="row g-2 mb-3">
            <div class="col-6 col-xl-3">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted">Sparepart diprediksi</div>
                    <div class="fw-semibold"><?= (int) ($pSummary['predicted'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted">Perlu perhatian</div>
                    <div class="fw-semibold"><?= (int) ($pSummary['attention'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted">Di bawah ROP</div>
                    <div class="fw-semibold"><?= (int) ($pSummary['below_rop'] ?? 0) ?></div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted">Pembaruan terakhir</div>
                    <div class="fw-semibold"><?= e($when(is_string($pLastAt) ? $pLastAt : null)) ?></div>
                </div>
            </div>
        </div>

        <?php if ($pRows === []): ?>
            <div class="text-muted small">Belum ada sparepart aktif yang dapat diprediksi.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead class="table-light">
                        <tr>
                            <th>Kode</th>
                            <th>Nama sparepart</th>
                            <th class="text-end">Stok aktual</th>
                            <th class="text-end">MA</th>
                            <th class="text-end">LR</th>
                            <th class="text-end">Safety Stock</th>
                            <th class="text-end">ROP</th>
                            <th>Status</th>
                            <th>Rekomendasi tindakan</th>
                            <th>Pembaruan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pRows as $row): ?>
                            <tr>
                                <td class="text-nowrap"><?= e((string) ($row['code'] ?? '')) ?></td>
                                <td>
                                    <a href="<?= e(base_url('prediksi/' . (int) ($row['sparepart_id'] ?? 0))) ?>">
                                        <?= e((string) ($row['name'] ?? '')) ?>
                                    </a>
                                    <?php if (!empty($row['stale'])): ?>
                                        <span class="badge text-bg-warning ms-1" title="Stok berubah setelah prediksi terakhir">belum terbaru</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?= e($qty($row['stock'] ?? 0)) ?> <?= e((string) ($row['unit'] ?? '')) ?>
                                </td>
                                <td class="text-end text-nowrap"><?= e($num($row['moving_average'] ?? null)) ?></td>
                                <td class="text-end text-nowrap"><?= e($num($row['linear_regression'] ?? null)) ?></td>
                                <td class="text-end text-nowrap"><?= e($num($row['safety_stock'] ?? null)) ?></td>
                                <td class="text-end text-nowrap"><?= e($num($row['reorder_point'] ?? null)) ?></td>
                                <td><?= $pStatusBadge($row) ?></td>
                                <td class="small"><?= e((string) ($row['action'] ?? '—')) ?></td>
                                <td class="small text-nowrap"><?= e($when(isset($row['processed_at']) ? (string) $row['processed_at'] : null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="small text-muted mt-2">
            Hasil prediksi merupakan rekomendasi pendukung keputusan. Pemesanan sparepart tetap dilakukan secara manual oleh pengguna yang berwenang.
        </div>
    </div>
</div>
