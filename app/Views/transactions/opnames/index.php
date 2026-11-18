<?php
/**
 * Daftar sesi stock opname (FR-10).
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var bool $hasOpenSession
 */
$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return $params === [] ? '' : '?' . http_build_query($params);
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Stock Opname</h2>
        <p class="text-muted small mb-0">
            Perhitungan fisik stok. Stok sistem <strong>tidak berubah</strong> sampai Partman mengonfirmasi hasil.
            Total <strong><?= (int) $total ?></strong> sesi.
        </p>
    </div>
    <?php if ($canManage && !$hasOpenSession): ?>
        <a class="btn btn-navy" href="<?= e(base_url('transaksi/opname/mulai')) ?>">+ Mulai Stock Opname</a>
    <?php elseif ($canManage): ?>
        <span class="badge text-bg-warning">Ada sesi belum dikonfirmasi</span>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('transaksi/opname')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Nomor opname / catatan">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <option value="OPEN" <?= $filters['status'] === 'OPEN' ? 'selected' : '' ?>>Belum dikonfirmasi</option>
                    <option value="CONFIRMED" <?= $filters['status'] === 'CONFIRMED' ? 'selected' : '' ?>>Sudah dikonfirmasi</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="dari">Dari tanggal</label>
                <input type="date" class="form-control form-control-sm" id="dari" name="dari" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="sampai">Sampai</label>
                <input type="date" class="form-control form-control-sm" id="sampai" name="sampai" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
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
                <th>Tanggal</th>
                <th class="text-center">Item</th>
                <th class="text-center">Berselisih</th>
                <th>Status</th>
                <th>Dimulai oleh</th>
                <th>Dikonfirmasi oleh</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-center text-muted small py-4">
                    Belum ada sesi stock opname yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $open = (string) $row['status'] === 'OPEN'; ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['opname_number']) ?></td>
                    <td class="small"><?= e(tanggal_id((string) ($row['opname_date'] ?? $row['started_at']))) ?></td>
                    <td class="text-center small"><?= (int) $row['item_count'] ?></td>
                    <td class="text-center small">
                        <?php if ((int) $row['diff_count'] > 0): ?>
                            <span class="badge text-bg-warning"><?= (int) $row['diff_count'] ?></span>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge text-bg-<?= $open ? 'secondary' : 'success' ?>">
                            <?= $open ? 'Belum dikonfirmasi' : 'Sudah dikonfirmasi' ?>
                        </span>
                    </td>
                    <td class="small"><?= e((string) $row['started_by_name']) ?></td>
                    <td class="small"><?= e((string) ($row['confirmed_by_name'] ?? '-')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('transaksi/opname/' . (int) $row['id'])) ?>">
                            <?= $canManage && $open ? 'Isi' : 'Lihat' ?>
                        </a>
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
                   href="<?= e(base_url('transaksi/opname') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('transaksi/opname') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Kepala Bengkel dan Admin dapat <strong>melihat hasil</strong> stock opname, tetapi tidak dapat mengubahnya.
</p>
