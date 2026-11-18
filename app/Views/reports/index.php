<?php
/**
 * Daftar laporan (Fase 5).
 *
 * @var array<int,array<string,mixed>> $reports
 */
$reports = $reports ?? [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Laporan</h1>
        <p class="text-muted small mb-0">
            Ringkasan, grafik, cetak, serta ekspor PDF/Excel. Semua role dapat melihat laporan.
        </p>
    </div>
    <div class="small text-muted">
        Filter tanggal, kategori, supplier, dan pencarian tersedia di setiap laporan.
    </div>
</div>

<div class="row g-3">
    <?php foreach ($reports as $report): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <span class="fs-4 text-navy"><?= $report['icon'] ?? '&#128196;' ?></span>
                        <div>
                            <h2 class="h6 text-navy mb-1"><?= e($report['title'] ?? '') ?></h2>
                            <div class="small text-muted"><?= e($report['description'] ?? '') ?></div>
                        </div>
                    </div>
                    <?php if (isset($report['badge']) && is_array($report['badge'])): ?>
                        <div class="mb-2">
                            <span class="badge text-bg-<?= e((string) ($report['badge']['class'] ?? 'info')) ?>">
                                <?= e((string) ($report['badge']['label'] ?? '')) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="mt-auto d-flex gap-2">
                        <a class="btn btn-navy btn-sm" href="<?= e(base_url('laporan/' . $report['key'])) ?>">Buka</a>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('laporan/' . $report['key'] . '/cetak')) ?>">Cetak</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-light border small mt-3">
    <strong>Nilai persediaan:</strong> stok × harga beli rata-rata tertimbang (weighted average), dengan fallback
    harga beli terakhir. Harga terakhir ditampilkan sebagai pembanding. Fast/slow moving memakai ambang yang
    dapat diubah Admin pada menu Pengaturan dan aturannya dicantumkan pada laporan.
</div>
