<?php
/**
 * Daftar Purchase Order (FR-16, Fase 6).
 *
 * @var bool $canCreate
 * @var string $role
 * @var int $userId
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string,int> $counts
 * @var array<int,array<string,mixed>> $suppliers
 */
use App\Models\PurchaseOrder;
use App\Services\ReportService;

$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return $params === [] ? '' : '?' . http_build_query($params);
};

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
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
};

$paymentBadgeClass = static function (string $status): string {
    return match ($status) {
        PurchaseOrder::PAYMENT_UNPAID  => 'text-bg-light border',
        PurchaseOrder::PAYMENT_PARTIAL => 'text-bg-warning',
        PurchaseOrder::PAYMENT_PAID    => 'text-bg-success',
        default                        => 'text-bg-light border',
    };
};

// Filter cepat yang diminta alur PO (terpisah dari status pembayaran).
$quickFilters = [
    PurchaseOrder::STATUS_DRAFT    => 'Draf',
    PurchaseOrder::STATUS_PENDING  => 'Menunggu Persetujuan',
    PurchaseOrder::STATUS_APPROVED => 'Disetujui',
    PurchaseOrder::STATUS_REJECTED => 'Ditolak',
    PurchaseOrder::STATUS_COMPLETE => 'Selesai Diterima',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Pembelian / Purchase Order</h2>
        <p class="text-muted small mb-0">
            Status persetujuan, penerimaan, dan pembayaran tercatat terpisah.
            Persetujuan PO <strong>tidak menambah stok</strong> — stok bertambah saat barang diterima.
            Total <strong><?= (int) $total ?></strong> PO.
        </p>
    </div>
    <?php if ($canCreate): ?>
        <a class="btn btn-navy" href="<?= e(base_url('pembelian/tambah')) ?>">+ Buat PO</a>
    <?php endif; ?>
</div>

<?php if ($role === 'kepala_bengkel' && (int) ($counts['pending'] ?? 0) > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <span class="small mb-0">
            <strong><?= (int) $counts['pending'] ?></strong> PO menunggu keputusan Anda.
        </span>
        <a class="btn btn-sm btn-warning"
           href="<?= e(base_url('pembelian') . $query(['status' => PurchaseOrder::STATUS_PENDING, 'page' => 1])) ?>">
            Lihat pengajuan
        </a>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-sm <?= ($filters['status'] ?? '') === '' ? 'btn-navy' : 'btn-outline-secondary' ?>"
               href="<?= e(base_url('pembelian') . $query(['status' => '', 'page' => 1])) ?>">Semua status</a>
            <?php foreach ($quickFilters as $quickStatus => $quickLabel): ?>
                <?php if ($role === 'kepala_bengkel' && $quickStatus === PurchaseOrder::STATUS_DRAFT) { continue; } ?>
                <a class="btn btn-sm <?= ($filters['status'] ?? '') === $quickStatus ? 'btn-navy' : 'btn-outline-secondary' ?>"
                   href="<?= e(base_url('pembelian') . $query(['status' => $quickStatus, 'page' => 1])) ?>">
                    <?= e($quickLabel) ?>
                    <?php if ($quickStatus === PurchaseOrder::STATUS_PENDING && (int) ($counts['pending'] ?? 0) > 0): ?>
                        <span class="badge text-bg-light border ms-1"><?= (int) $counts['pending'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="<?= e(base_url('pembelian')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Nomor PO / supplier / catatan">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="status">Status persetujuan</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (PurchaseOrder::STATUSES as $status): ?>
                        <?php if ($role === 'kepala_bengkel' && $status === PurchaseOrder::STATUS_DRAFT) { continue; } ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(ReportService::label('po', $status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="bayar">Status pembayaran</label>
                <select class="form-select form-select-sm" id="bayar" name="bayar">
                    <option value="">Semua pembayaran</option>
                    <?php foreach (PurchaseOrder::PAYMENT_STATUSES as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['payment'] === $status ? 'selected' : '' ?>>
                            <?= e(ReportService::label('payment', $status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="supplier">Supplier</label>
                <select class="form-select form-select-sm" id="supplier" name="supplier">
                    <option value="">Semua supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>"
                            <?= $filters['supplier_id'] === (string) $supplier['id'] ? 'selected' : '' ?>>
                            <?= e((string) $supplier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pembelian')) ?>">Bersihkan</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Nomor PO</th>
                <th>Tanggal</th>
                <th>Supplier</th>
                <th class="text-end">Item</th>
                <th class="text-end">Total</th>
                <th>Status persetujuan</th>
                <th>Pembayaran</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-center text-muted small py-4">
                    Belum ada PO yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $rowStatus = (string) $row['status'];
                $isOwner = (int) ($row['created_by'] ?? 0) === $userId;
                $rowCanSubmit = $role === 'partman'
                    && $rowStatus === PurchaseOrder::STATUS_DRAFT && $isOwner;
                $rowCanApprove = $role === 'kepala_bengkel'
                    && $rowStatus === PurchaseOrder::STATUS_PENDING;
                $rowCanPay = $role === 'admin'
                    && in_array($rowStatus, [
                        PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
                        PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_COMPLETE,
                    ], true)
                    && (string) $row['payment_status'] !== PurchaseOrder::PAYMENT_PAID;
                $rowCanReceive = $role === 'admin'
                    && in_array($rowStatus, [
                        PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
                        PurchaseOrder::STATUS_PARTIAL,
                    ], true)
                    && (float) ($row['total_remaining'] ?? 0) > 0;
                $detailUrl = base_url('pembelian/' . (int) $row['id']);
                ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['po_number']) ?></td>
                    <td class="small"><?= e(tanggal_id((string) $row['created_at'])) ?></td>
                    <td class="small"><?= e((string) $row['supplier_name']) ?></td>
                    <td class="text-end small"><?= (int) ($row['item_count'] ?? count(PurchaseOrder::items((int) $row['id']))) ?></td>
                    <td class="text-end"><?= e(rupiah($row['total_amount'])) ?></td>
                    <td><span class="badge <?= e($statusBadgeClass((string) $row['status'])) ?>">
                        <?= e(ReportService::label('po', (string) $row['status'])) ?></span></td>
                    <td><span class="badge <?= e($paymentBadgeClass((string) $row['payment_status'])) ?>">
                        <?= e(ReportService::label('payment', (string) $row['payment_status'])) ?></span></td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap justify-content-end gap-1">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e($detailUrl) ?>">Lihat</a>
                            <?php if ($rowCanSubmit): ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e($detailUrl . '/ubah') ?>">Ubah</a>
                                <form method="post" class="d-inline"
                                      action="<?= e($detailUrl . '/ajukan') ?>"
                                      onsubmit="return confirm('Ajukan PO ini ke Kepala Bengkel? Setelah diajukan, isi PO tidak dapat diubah sampai diputuskan.');">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-navy" type="submit">Ajukan</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($rowCanApprove): ?>
                                <form method="post" class="d-inline"
                                      action="<?= e($detailUrl . '/setujui') ?>"
                                      onsubmit="return confirm('Setujui PO ini? Persetujuan tidak menambah stok.');">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-success" type="submit">Setujui</button>
                                </form>
                                <a class="btn btn-sm btn-outline-danger" href="<?= e($detailUrl . '#keputusan') ?>">Tolak</a>
                            <?php endif; ?>
                            <?php if ($rowCanPay): ?>
                                <a class="btn btn-sm btn-navy" href="<?= e($detailUrl . '#pembayaran') ?>">Bayar</a>
                            <?php endif; ?>
                            <?php if ($rowCanReceive): ?>
                                <a class="btn btn-sm btn-success" href="<?= e($detailUrl . '#penerimaan') ?>">Terima</a>
                            <?php endif; ?>
                        </div>
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
                   href="<?= e(base_url('pembelian') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('pembelian') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Tiga status dipisah: <strong>persetujuan</strong> (draf → menunggu → disetujui/ditolak),
    <strong>penerimaan</strong> (sebagian/selesai, lewat menu Barang Masuk), dan
    <strong>pembayaran</strong> (oleh Admin). PO tidak pernah dihapus — pembatalan wajib beralasan
    dan tetap tercatat.
</p>
