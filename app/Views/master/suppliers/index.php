<?php
/**
 * Daftar supplier (FR-11).
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $suppliers
 * @var array{search:string,status:string} $filters
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Supplier</h2>
        <p class="text-muted small mb-0">
            Supplier yang pernah dipakai tidak dihapus permanen — hanya dinonaktifkan.
        </p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-navy" href="<?= e(base_url('supplier/tambah')) ?>">+ Tambah Supplier</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('supplier')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Kode / nama / kontak / telepon">
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label small mb-1" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
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
                <th>Kode</th>
                <th>Nama Perusahaan</th>
                <th>Kontak</th>
                <th>Alamat</th>
                <th class="text-center">Dipakai</th>
                <th>Status</th>
                <?php if ($canManage): ?><th class="text-end">Aksi</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($suppliers === []): ?>
                <tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="text-center text-muted small py-4">
                    Tidak ada supplier yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($suppliers as $row): ?>
                <?php $active = (int) $row['is_active'] === 1; ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['code']) ?></td>
                    <td>
                        <?= e((string) $row['name']) ?>
                        <?php if (!empty($row['lead_time_days'])): ?>
                            <span class="badge text-bg-light border ms-1">lead time <?= (int) $row['lead_time_days'] ?> hari</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($row['contact_person'])): ?><div><?= e((string) $row['contact_person']) ?></div><?php endif; ?>
                        <?php if (!empty($row['phone'])): ?><div class="text-muted"><?= e((string) $row['phone']) ?></div><?php endif; ?>
                        <?php if (!empty($row['email'])): ?><div class="text-muted"><?= e((string) $row['email']) ?></div><?php endif; ?>
                        <?php if (empty($row['contact_person']) && empty($row['phone']) && empty($row['email'])): ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e((string) ($row['address'] ?? '-')) ?></td>
                    <td class="text-center small">
                        <?= (int) $row['sparepart_count'] ?> sparepart<br>
                        <span class="text-muted"><?= (int) $row['receipt_count'] ?> penerimaan</span>
                    </td>
                    <td>
                        <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>">
                            <?= $active ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <?php if ($canManage): ?>
                        <td class="text-end">
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(base_url('supplier/' . (int) $row['id'] . '/ubah')) ?>">Ubah</a>
                                <form method="post" class="d-inline"
                                      action="<?= e(base_url('supplier/' . (int) $row['id'] . '/status')) ?>"
                                      data-confirm="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?> supplier <?= e((string) $row['name']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                    <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                        <?= $active ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    Supplier nonaktif <strong>tidak dapat dipakai untuk transaksi baru</strong> (barang masuk / pembelian),
    namun riwayat penerimaan lama tetap menunjuk supplier aslinya.
</p>
