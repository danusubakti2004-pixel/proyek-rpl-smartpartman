<?php
/**
 * Riwayat penyesuaian stok.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Riwayat Penyesuaian Stok</h2>
        <p class="text-muted small mb-0">Penyesuaian stok oleh Partman (tambah/kurang).</p>
    </div>
    <?php if ($canManage ?? false): ?>
        <a class="btn btn-navy btn-sm" href="<?= e(base_url('transaksi/penyesuaian/tambah')) ?>">+ Penyesuaian Baru</a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get" action="<?= e(base_url('transaksi/penyesuaian')) ?>">
    <div class="col-12 col-md-4">
        <input class="form-control form-control-sm" type="text" name="q" placeholder="Cari sparepart/catatan"
               value="<?= e((string) ($filters['search'] ?? '')) ?>">
    </div>
    <div class="col-12 col-md-3">
        <select class="form-select form-select-sm" name="sparepart">
            <option value="0">Semua sparepart</option>
            <?php foreach ($spareparts as $sp): ?>
                <option value="<?= (int) $sp['id'] ?>" <?= (int) ($filters['sparepart_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>>
                    <?= e((string) $sp['code'] . ' — ' . (string) $sp['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <input class="form-control form-control-sm" type="date" name="dari" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
    </div>
    <div class="col-6 col-md-2">
        <input class="form-control form-control-sm" type="date" name="sampai" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
    </div>
    <div class="col-12 col-md-1">
        <button class="btn btn-outline-secondary btn-sm w-100" type="submit">Filter</button>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nomor</th>
                    <th>Tanggal</th>
                    <th>Sparepart</th>
                    <th>Jenis</th>
                    <th class="text-end">Sebelum</th>
                    <th class="text-end">Perubahan</th>
                    <th class="text-end">Sesudah</th>
                    <th>Alasan</th>
                    <th>Oleh</th>
                    <th class="text-end">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" class="text-center text-muted small py-3">Belum ada penyesuaian stok.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $qty = (float) $row['quantity'];
                    $isAdd = $qty > 0;
                    ?>
                    <tr>
                        <td class="fw-semibold small"><?= e((string) ($row['reference_number'] ?? '-')) ?></td>
                        <td class="small"><?= e(tanggal_id((string) $row['movement_date'])) ?></td>
                        <td class="small"><?= e((string) $row['sparepart_code'] . ' — ' . (string) $row['sparepart_name']) ?></td>
                        <td>
                            <?php if ($isAdd): ?>
                                <span class="badge text-bg-success">Penambahan</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Pengurangan</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small"><?= e(rtrim(rtrim(number_format((float) $row['stock_before'], 2, ',', '.'), '0'), ',')) ?></td>
                        <td class="text-end small <?= $isAdd ? 'text-success' : 'text-danger' ?>">
                            <?= $isAdd ? '+' : '' ?><?= e(rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',')) ?>
                        </td>
                        <td class="text-end small fw-semibold"><?= e(rtrim(rtrim(number_format((float) $row['stock_after'], 2, ',', '.'), '0'), ',')) ?></td>
                        <td class="small"><?= e((string) ($row['note'] ?? '-')) ?></td>
                        <td class="small"><?= e((string) ($row['user_name'] ?? $row['username'] ?? '-')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('transaksi/penyesuaian/' . (int) $row['id'])) ?>">Lihat</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (($pages ?? 1) > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(base_url('transaksi/penyesuaian?page=' . $i)) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
PY