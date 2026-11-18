<?php
/**
 * Detail Purchase Order (FR-16, Fase 6).
 *
 * Tiga status dipisah: persetujuan, penerimaan, pembayaran.
 *
 * @var array<string,mixed> $po
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $receipts
 * @var array<int,array<string,mixed>> $payments
 * @var bool $canSubmit
 * @var bool $canEdit
 * @var bool $canApprove
 * @var bool $canProcess
 * @var bool $canPay
 * @var bool $canCancel
 * @var string $printUrl
 * @var string $pdfUrl
 */
use App\Models\PurchaseOrder;
use App\Services\ReportService;

$qty = static function (float|int|string|null $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
};

$status = (string) $po['status'];

$statusBadgeClass = match ($status) {
    PurchaseOrder::STATUS_DRAFT     => 'text-bg-secondary',
    PurchaseOrder::STATUS_PENDING   => 'text-bg-warning',
    PurchaseOrder::STATUS_APPROVED  => 'text-bg-primary',
    PurchaseOrder::STATUS_REJECTED  => 'text-bg-danger',
    PurchaseOrder::STATUS_PROCESSED => 'text-bg-info',
    PurchaseOrder::STATUS_PARTIAL   => 'text-bg-warning',
    PurchaseOrder::STATUS_COMPLETE  => 'text-bg-success',
    PurchaseOrder::STATUS_CANCELLED => 'text-bg-dark',
    default                         => 'text-bg-light border',
};

$total = 0.0;
$remaining = 0.0;
foreach ($items as $item) {
    $total += (float) $item['quantity'] * (float) $item['unit_price'];
    $remaining += max(0.0, (float) $item['quantity'] - (float) $item['received_qty']);
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Purchase Order <?= e((string) $po['po_number']) ?></h2>
        <p class="text-muted small mb-0">
            Dibuat <?= e(tanggal_id((string) $po['created_at'], true)) ?> oleh
            <?= e((string) ($po['created_by_name'] ?? '-')) ?>
            <?php if (!empty($po['reorder_number'])): ?>
                · dari permintaan reorder <a href="<?= e(base_url('reorder')) ?>"><?= e((string) $po['reorder_number']) ?></a>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= e(base_url('pembelian/' . (int) $po['id'] . '/ubah')) ?>">Ubah Draft</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($printUrl) ?>" target="_blank">Cetak PO</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($pdfUrl) ?>">Unduh PDF</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pembelian')) ?>">&laquo; Kembali</a>
    </div>
</div>

<?php if ($canSubmit): ?>
    <div class="alert alert-secondary d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small mb-0">Draft ini <strong>belum diajukan</strong>. Ajukan ke Kepala Bengkel agar dapat diproses.</span>
        <a class="btn btn-sm btn-navy" href="#ajukan">Ajukan PO</a>
    </div>
<?php endif; ?>
<?php if ($canApprove): ?>
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small mb-0">PO ini <strong>menunggu keputusan Kepala Bengkel</strong>.</span>
        <a class="btn btn-sm btn-warning" href="#keputusan">Beri keputusan</a>
    </div>
<?php endif; ?>
<?php if ($canPay): ?>
    <div class="alert alert-primary d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small mb-0">PO sudah disetujui. Catat status pembayaran bila sudah dibayar.</span>
        <a class="btn btn-sm btn-navy" href="#pembayaran">Catat pembayaran</a>
    </div>
<?php endif; ?>

<?php if ($status === PurchaseOrder::STATUS_REJECTED && !empty($po['rejected_reason'])): ?>
    <?php
    $rejectionEntry = null;
    foreach ($history as $entry) {
        if ((string) $entry['new_status'] === PurchaseOrder::STATUS_REJECTED) {
            $rejectionEntry = $entry;
        }
    }
    ?>
    <div class="alert alert-danger small mb-3">
        PO ditolak
        <?php if ($rejectionEntry !== null): ?>
            oleh <strong><?= e((string) ($rejectionEntry['actor_full_name'] ?? $rejectionEntry['actor_name'] ?? '-')) ?></strong>
            pada <?= e(tanggal_id((string) $rejectionEntry['created_at'], true)) ?>
        <?php endif; ?>.
        Alasan: <?= e((string) $po['rejected_reason']) ?>
    </div>
<?php endif; ?>
<?php if ($status === PurchaseOrder::STATUS_CANCELLED && !empty($po['cancel_reason'])): ?>
    <div class="alert alert-secondary small mb-3">
        PO dibatalkan oleh <?= e((string) ($po['cancelled_by_name'] ?? '-')) ?>
        (<?= e(tanggal_id((string) $po['cancelled_at'], true)) ?>). Alasan: <?= e((string) $po['cancel_reason']) ?>
        — PO tidak dihapus, tetap tercatat untuk riwayat.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Item PO</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Kode</th><th>Nama Barang</th>
                            <th class="text-end">Dipesan</th>
                            <th class="text-end">Diterima</th>
                            <th class="text-end">Sisa</th>
                            <th class="text-end">Harga</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($items === []): ?>
                            <tr><td colspan="7" class="text-center text-muted small py-3">PO tanpa item.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $itemRemaining = (float) $item['quantity'] - (float) $item['received_qty'];
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) $item['sparepart_code']) ?></td>
                                <td class="small"><?= e((string) $item['sparepart_name']) ?></td>
                                <td class="text-end"><?= e($qty($item['quantity'])) ?></td>
                                <td class="text-end"><?= e($qty($item['received_qty'])) ?></td>
                                <td class="text-end <?= $itemRemaining <= 0 ? 'text-success' : 'fw-semibold' ?>">
                                    <?= e($qty($itemRemaining)) ?></td>
                                <td class="text-end"><?= e(rupiah($item['unit_price'])) ?></td>
                                <td class="text-end"><?= e(rupiah((float) $item['quantity'] * (float) $item['unit_price'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td colspan="6" class="text-end">TOTAL</td>
                            <td class="text-end fw-semibold"><?= e(rupiah($total)) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="text-muted small mt-2">
                    Sisa seluruh item: <strong><?= e($qty($remaining)) ?></strong>.
                    Penerimaan dicatat lewat menu <a href="<?= e(base_url('transaksi/masuk')) ?>">Barang Masuk</a>
                    (boleh bertahap; jumlah diterima tidak boleh melebihi sisa PO).
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Riwayat persetujuan &amp; status</h3>
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
                                <td class="small"><?php if ($entry['old_status'] !== null) {
                                    echo e(ReportService::label('po', (string) $entry['old_status']));
                                } else {
                                    echo '<span class="text-muted">— (dokumen baru)</span>';
                                } ?></td>
                                <td class="small fw-semibold"><?= e(ReportService::label('po', (string) $entry['new_status'])) ?></td>
                                <td class="small"><?= e((string) ($entry['actor_full_name'] ?? $entry['actor_name'] ?? '-')) ?></td>
                                <td class="small"><?= e((string) ($entry['reason'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Penerimaan parsial terkait</h3>
                <?php if ($receipts === []): ?>
                    <p class="text-muted small mb-0">Belum ada barang diterima untuk PO ini.
                        Stok bertambah hanya saat penerimaan tersimpan.</p>
                <?php else: ?>
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr><th>Tanggal</th><th>Nomor</th><th>Jumlah</th><th>Dicatat oleh</th><th class="text-end">Aksi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td class="small"><?= e(tanggal_id((string) $receipt['receipt_date'])) ?></td>
                                <td class="fw-semibold small"><?= e((string) $receipt['receipt_number']) ?></td>
                                <td class="small"><?= e($qty($receipt['quantity'])) ?></td>
                                <td class="small"><?= e((string) ($receipt['recorded_by_name'] ?? '-')) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(base_url('transaksi/masuk/' . (int) $receipt['id'])) ?>">Lihat</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Riwayat pembayaran</h3>
                <p class="text-muted small mb-2">
                    Hanya status &amp; catatan yang dicatat — bukan modul akuntansi
                    (tanpa buku besar/kas/bank).
                </p>
                <?php if ($payments === []): ?>
                    <p class="text-muted small mb-0">Belum ada perubahan status pembayaran.</p>
                <?php else: ?>
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr><th>Waktu</th><th>Status</th><th>Catatan</th><th>Oleh</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="small"><?= e(tanggal_id((string) $payment['changed_at'], true)) ?></td>
                                <td><span class="badge text-bg-light border">
                                    <?= e(ReportService::label('payment', (string) $payment['status'])) ?></span></td>
                                <td class="small"><?= e((string) ($payment['note'] ?? '—')) ?></td>
                                <td class="small"><?= e((string) ($payment['changed_by_name'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h3 class="h6 text-navy">Status PO</h3>
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th style="width:44%;">Persetujuan</th><td>
                        <span class="badge <?= e($statusBadgeClass) ?>"><?= e(ReportService::label('po', $status)) ?></span>
                    </td></tr>
                    <tr><th>Penerimaan</th><td><?php
                        if ($status === PurchaseOrder::STATUS_COMPLETE) {
                            echo '<span class="badge text-bg-success">Selesai diterima</span>';
                        } elseif ($status === PurchaseOrder::STATUS_PARTIAL) {
                            echo '<span class="badge text-bg-warning">Diterima sebagian</span>';
                        } else {
                            echo '<span class="text-muted small">Belum ada penerimaan</span>';
                        }
                    ?></td></tr>
                    <tr><th>Pembayaran</th><td>
                        <span class="badge text-bg-light border">
                            <?= e(ReportService::label('payment', (string) $po['payment_status'])) ?></span>
                    </td></tr>
                    <tr><th>Supplier</th><td><?= e((string) $po['supplier_name']) ?></td></tr>
                    <tr><th>Total</th><td class="fw-semibold"><?= e(rupiah($total)) ?></td></tr>
                    <tr><th>Disetujui oleh</th><td><?= e((string) ($po['approved_by_name'] ?? '—')) ?>
                        <?php if (!empty($po['approved_at'])): ?>
                            <div class="text-muted small"><?= e(tanggal_id((string) $po['approved_at'], true)) ?></div>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Diproses oleh</th><td><?= e((string) ($po['processed_by_name'] ?? '—')) ?>
                        <?php if (!empty($po['processed_at'])): ?>
                            <div class="text-muted small"><?= e(tanggal_id((string) $po['processed_at'], true)) ?></div>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Catatan</th><td><?= e((string) ($po['note'] ?? '—')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($canSubmit): ?>
            <div class="card border-0 shadow-sm mb-3" id="ajukan">
                <div class="card-body">
                    <h3 class="h6 text-navy">Ajukan ke Kepala Bengkel</h3>
                    <p class="small text-muted">Pastikan isi draft sudah benar — PO yang diajukan
                        tidak dapat diubah sampai diputuskan.</p>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/ajukan')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-navy w-100" type="submit">Ajukan PO</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canApprove): ?>
            <div class="card border-0 shadow-sm mb-3" id="keputusan">
                <div class="card-body">
                    <h3 class="h6 text-navy">Keputusan Kepala Bengkel</h3>
                    <p class="small text-muted">
                        Persetujuan PO <strong>tidak menambah stok</strong> — stok bertambah saat
                        barang diterima.
                    </p>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/setujui')) ?>" class="mb-3">
                        <?= csrf_field() ?>
                        <button class="btn btn-success w-100" type="submit">Setujui PO</button>
                    </form>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/tolak')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="reject_reason">Alasan penolakan <span class="text-danger">*</span></label>
                        <textarea class="form-control mb-2" id="reject_reason" name="reason" rows="2"
                                  maxlength="500" required></textarea>
                        <button class="btn btn-outline-danger w-100" type="submit">Tolak PO</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canProcess): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6 text-navy">Administrasi PO (Admin)</h3>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/proses')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-navy w-100" type="submit">Proses Administrasi PO</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canReceive ?? false): ?>
            <div class="card border-0 shadow-sm mb-3" id="penerimaan">
                <div class="card-body">
                    <h3 class="h6 text-navy">Terima Barang (Admin)</h3>
                    <p class="small text-muted">
                        Penerimaan berdasarkan PO yang disetujui. Stok bertambah
                        sesuai jumlah aktual yang diterima (boleh bertahap).
                    </p>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/terima-otomatis')) ?>"
                          onsubmit="return confirm('Apakah Anda yakin ingin menerima seluruh sisa barang dari PO ini? Stok akan otomatis bertambah setelah transaksi berhasil.');">
                        <?= csrf_field() ?>
                        <button class="btn btn-navy w-100" type="submit">Terima Barang dari PO</button>
                    </form>
                    <div class="small text-muted mt-2">
                        Ringkasan: <?= count($items) ?> jenis sparepart, sisa total <?= e($qty($remaining)) ?>.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canPay): ?>
            <div class="card border-0 shadow-sm mb-3" id="pembayaran">
                <div class="card-body">
                    <h3 class="h6 text-navy">Status Pembayaran (Admin)</h3>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/pembayaran')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="payment_status">Status</label>
                        <select class="form-select form-select-sm mb-2" id="payment_status" name="payment_status" required>
                            <?php foreach (PurchaseOrder::PAYMENT_STATUSES as $payStatus): ?>
                                <option value="<?= e($payStatus) ?>"
                                    <?= (string) $po['payment_status'] === $payStatus ? 'disabled' : '' ?>>
                                    <?= e(ReportService::label('payment', $payStatus)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label small" for="payment_note">Catatan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control mb-2" id="payment_note" name="payment_note" rows="2"
                                  maxlength="500"></textarea>
                        <button class="btn btn-navy w-100" type="submit">Simpan Status Pembayaran</button>
                        <div class="form-text">Bukan modul akuntansi — hanya status &amp; catatan.</div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canCancel): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6 text-navy">Batalkan PO</h3>
                    <p class="small text-muted">
                        PO <strong>tidak dihapus</strong> — status menjadi Dibatalkan dengan alasan
                        yang tercatat.
                    </p>
                    <form method="post" action="<?= e(base_url('pembelian/' . (int) $po['id'] . '/batal')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small" for="cancel_reason">Alasan pembatalan <span class="text-danger">*</span></label>
                        <textarea class="form-control mb-2" id="cancel_reason" name="reason" rows="2"
                                  maxlength="500" required></textarea>
                        <button class="btn btn-outline-dark w-100" type="submit">Batalkan PO</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Catatan alur</h3>
                <ul class="ps-3 mb-0">
                    <li>Persetujuan PO tidak menambah stok.</li>
                    <li>Stok bertambah saat penerimaan barang (boleh bertahap).</li>
                    <li>Jumlah diterima tidak boleh melebihi sisa PO — kelebihan selalu ditolak.</li>
                    <li>PO dengan histori tidak pernah dihapus; tutup lewat pembatalan beralasan.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
