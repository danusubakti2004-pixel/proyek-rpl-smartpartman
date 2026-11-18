<?php
/**
 * Detail permintaan reorder (FR-15, Fase 6).
 *
 * @var array<string,mixed> $row
 * @var bool $canReview
 * @var bool $canEdit
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $purchaseOrders
 */
use App\Models\ReorderRequest;
use App\Services\ReportService;

$qty = static function (float|int|string|null $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
};

$statusBadge = match ((string) $row['status']) {
    ReorderRequest::STATUS_PENDING  => '<span class="badge text-bg-warning">Menunggu persetujuan</span>',
    ReorderRequest::STATUS_APPROVED => '<span class="badge text-bg-success">Disetujui</span>',
    ReorderRequest::STATUS_REJECTED => '<span class="badge text-bg-danger">Ditolak</span>',
    default                         => '<span class="badge text-bg-light border">' . e((string) $row['status']) . '</span>',
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Permintaan Reorder <?= e((string) $row['request_number']) ?></h2>
        <p class="text-muted small mb-0">
            Diajukan <?= e(tanggal_id((string) $row['created_at'], true)) ?> oleh
            <?= e((string) ($row['created_by_name'] ?? '-')) ?>.
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= e(base_url('reorder/' . (int) $row['id'] . '/ubah')) ?>">Ubah Jumlah</a>
        <?php endif; ?>
        <?php if ((string) $row['status'] === ReorderRequest::STATUS_APPROVED && $row['supplier_id'] === null): ?>
            <a class="btn btn-navy btn-sm"
               href="<?= e(base_url('pembelian/tambah?reorder=' . (int) $row['id'])) ?>">Buat PO</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('reorder')) ?>">&laquo; Kembali</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Rincian permintaan</h3>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr><th style="width:34%;">Nomor</th><td><?= e((string) $row['request_number']) ?></td></tr>
                        <tr><th>Sparepart</th><td>
                            <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                            — <?= e((string) $row['sparepart_name']) ?>
                            <div class="text-muted small">Stok <?= e($qty($row['sparepart_stock'])) ?>
                                <?= e((string) $row['unit']) ?> · minimum <?= e($qty($row['sparepart_min_stock'])) ?></div>
                        </td></tr>
                        <tr><th>Saran sistem</th><td><?= e($qty($row['suggested_qty'])) ?> <?= e((string) $row['unit']) ?>
                            <span class="text-muted small">(prediksi AI terbaru bila tersedia; selain itu aturan stok-minimum)</span></td></tr>
                        <tr><th>Jumlah diminta</th><td><strong><?= e($qty($row['requested_qty'])) ?> <?= e((string) $row['unit']) ?></strong></td></tr>
                        <tr><th>Alasan perubahan</th><td><?= e((string) ($row['change_reason'] ?? '—')) ?></td></tr>
                        <tr><th>Supplier usulan</th><td><?= e((string) ($row['supplier_name'] ?? '—')) ?></td></tr>
                        <tr><th>Status</th><td><?= $statusBadge ?></td></tr>
                        <tr><th>Peninjau</th><td><?= e((string) ($row['reviewed_by_name'] ?? '—')) ?>
                            <?php if (!empty($row['reviewed_at'])): ?>
                                <div class="text-muted small"><?= e(tanggal_id((string) $row['reviewed_at'], true)) ?></div>
                            <?php endif; ?>
                        </td></tr>
                        <tr><th>Catatan peninjauan</th><td><?= e((string) ($row['review_note'] ?? '—')) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Riwayat status</h3>
                <p class="text-muted small mb-2">Setiap perubahan status mencatat aktor, waktu, status
                    sebelumnya → berikutnya, dan alasan.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr><th>Waktu</th><th>Status sebelumnya</th><th>Status berikutnya</th><th>Aktor</th><th>Alasan</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($history === []): ?>
                            <tr><td colspan="5" class="text-center text-muted small py-3">Belum ada riwayat.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td class="small"><?= e(tanggal_id((string) $entry['created_at'], true)) ?></td>
                                <td class="small"><?= $entry['old_status'] !== null
                                    ? e(ReportService::label('reorder', (string) $entry['old_status']))
                                    : '<span class="text-muted">— (dokumen baru)</span>' ?></td>
                                <td class="small fw-semibold"><?= e(ReportService::label('reorder', (string) $entry['new_status'])) ?></td>
                                <td class="small"><?= e((string) ($entry['actor_full_name'] ?? $entry['actor_name'] ?? '-')) ?></td>
                                <td class="small"><?= e((string) ($entry['reason'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($purchaseOrders !== []): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6 text-navy">Purchase Order dari permintaan ini</h3>
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr><th>Nomor PO</th><th>Status</th><th>Pembayaran</th><th class="text-end">Total</th><th class="text-end">Aksi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($purchaseOrders as $po): ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) $po['po_number']) ?></td>
                                <td><span class="badge text-bg-secondary"><?= e(ReportService::label('po', (string) $po['status'])) ?></span></td>
                                <td><?= e(ReportService::label('payment', (string) $po['payment_status'])) ?></td>
                                <td class="text-end"><?= e(rupiah($po['total_amount'])) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(base_url('pembelian/' . (int) $po['id'])) ?>">Lihat PO</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-4">
        <?php if ($canReview): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6 text-navy">Keputusan Kepala Bengkel</h3>
                    <p class="small text-muted">
                        Persetujuan hanya memutuskan pengadaan — <strong>stok tidak berubah</strong>.
                    </p>
                    <form method="post" action="<?= e(base_url('reorder/' . (int) $row['id'] . '/setujui')) ?>" class="mb-3">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="approve_note">Catatan persetujuan <span class="text-muted">(opsional)</span></label>
                        <textarea class="form-control mb-2" id="approve_note" name="review_note" rows="2"
                                  maxlength="500"></textarea>
                        <button class="btn btn-success w-100" type="submit">Setujui Permintaan</button>
                    </form>
                    <form method="post" action="<?= e(base_url('reorder/' . (int) $row['id'] . '/tolak')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="reject_note">Alasan penolakan <span class="text-danger">*</span></label>
                        <textarea class="form-control mb-2" id="reject_note" name="review_note" rows="2"
                                  maxlength="500" required></textarea>
                        <button class="btn btn-outline-danger w-100" type="submit">Tolak Permintaan</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Alur berikutnya</h3>
                <ol class="ps-3 mb-0">
                    <li>Permintaan menunggu persetujuan Kepala Bengkel.</li>
                    <li>Setelah disetujui, Partman membuat PO (bisa langsung lewat tombol "Buat PO").</li>
                    <li>Kepala Bengkel menyetujui PO; Admin memproses administrasi.</li>
                    <li>Partman mencatat penerimaan barang — <strong>stok bertambah di sini</strong>,
                        bisa bertahap sampai PO selesai diterima.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
