<?php
/**
 * Daftar permintaan reorder (FR-15, Fase 6).
 *
 * @var bool $canManage
 * @var bool $canReview
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string,int> $counts
 * @var array<int,array<string,mixed>> $needs
 */
use App\Models\ReorderRequest;
use App\Services\ReportService;

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

$statusBadge = static function (string $status): string {
    return match ($status) {
        ReorderRequest::STATUS_PENDING   => '<span class="badge text-bg-warning">Menunggu persetujuan</span>',
        ReorderRequest::STATUS_APPROVED  => '<span class="badge text-bg-success">Disetujui</span>',
        ReorderRequest::STATUS_REJECTED  => '<span class="badge text-bg-danger">Ditolak</span>',
        default                          => '<span class="badge text-bg-light border">' . e($status) . '</span>',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Permintaan Reorder</h2>
        <p class="text-muted small mb-0">
            Permintaan pengadaan barang oleh Partman, diputuskan Kepala Bengkel.
            Persetujuan <strong>tidak menambah stok</strong> — stok bertambah saat barang diterima.
            Total <strong><?= (int) $total ?></strong> permintaan.
        </p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-navy" href="<?= e(base_url('reorder/tambah')) ?>">+ Buat Permintaan</a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Menunggu persetujuan</div>
                <div class="fs-4 fw-semibold text-navy"><?= (int) ($counts[ReorderRequest::STATUS_PENDING] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Disetujui</div>
                <div class="fs-4 fw-semibold text-success"><?= (int) ($counts[ReorderRequest::STATUS_APPROVED] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="text-muted small">Ditolak</div>
                <div class="fs-4 fw-semibold text-danger"><?= (int) ($counts[ReorderRequest::STATUS_REJECTED] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($canManage && $needs !== []): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h3 class="h6 text-navy">Kebutuhan reorder — stok di bawah minimum</h3>
            <p class="text-muted small mb-2">
                Daftar kebutuhan berdasarkan stok saat ini dibanding stok minimum.
                Klik untuk langsung membuat permintaan (saran jumlah otomatis).
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Kode</th><th>Nama</th>
                        <th class="text-end">Stok</th>
                        <th class="text-end">Minimum</th>
                        <th class="text-end">Kekurangan</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($needs as $need): ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $need['code']) ?></td>
                            <td class="small"><?= e((string) $need['name']) ?></td>
                            <td class="text-end"><?= e($qty($need['stock'])) ?> <?= e((string) $need['unit']) ?></td>
                            <td class="text-end"><?= e($qty($need['min_stock'])) ?></td>
                            <td class="text-end fw-semibold"><?= e($qty($need['shortfall'])) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(base_url('reorder/tambah?sparepart=' . (int) $need['id'])) ?>">Buat reorder</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('reorder')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Nomor / kode / nama sparepart">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (ReorderRequest::STATUSES as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(ReportService::label('reorder', $status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('reorder')) ?>">Bersihkan</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Nomor</th>
                <th>Sparepart</th>
                <th class="text-end">Stok</th>
                <th class="text-end">Saran</th>
                <th class="text-end">Diajukan</th>
                <th>Pengaju</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-center text-muted small py-4">
                    Belum ada permintaan reorder yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['request_number']) ?></td>
                    <td class="small">
                        <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                        <div class="text-muted"><?= e((string) $row['sparepart_name']) ?></div>
                    </td>
                    <td class="text-end small"><?= e($qty($row['sparepart_stock'])) ?> <?= e((string) $row['unit']) ?></td>
                    <td class="text-end small"><?= e($qty($row['suggested_qty'])) ?></td>
                    <td class="text-end fw-semibold"><?= e($qty($row['requested_qty'])) ?></td>
                    <td class="small"><?= e((string) ($row['created_by_name'] ?? '-')) ?></td>
                    <td><?= $statusBadge((string) $row['status']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('reorder/' . (int) $row['id'])) ?>">Lihat</a>
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
                   href="<?= e(base_url('reorder') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('reorder') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Persetujuan reorder hanya memutuskan kebutuhan pengadaan — <strong>stok tidak berubah</strong>.
    Setelah disetujui, Partman membuat Purchase Order lalu mencatat penerimaan barang.
</p>
