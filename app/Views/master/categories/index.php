<?php
/**
 * Daftar kategori sparepart (FR-12).
 *
 * @var bool $canManage true = Partman (boleh mengelola)
 * @var array<int,array<string,mixed>> $categories
 * @var array{search:string,status:string} $filters
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Kategori Sparepart</h2>
        <p class="text-muted small mb-0">
            Satu kategori utama per sparepart. Kategori terpakai tidak pernah dihapus — hanya dinonaktifkan.
        </p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-navy" href="<?= e(base_url('kategori/tambah')) ?>">+ Tambah Kategori</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('kategori')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Kode / nama / keterangan">
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
                <th>Nama Kategori</th>
                <th>Keterangan</th>
                <th class="text-center">Dipakai</th>
                <th>Status</th>
                <?php if ($canManage): ?><th class="text-end">Aksi</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($categories === []): ?>
                <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-center text-muted small py-4">
                    Tidak ada kategori yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $row): ?>
                <?php $active = (int) $row['is_active'] === 1; ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['code']) ?></td>
                    <td><?= e((string) $row['name']) ?></td>
                    <td class="small text-muted"><?= e((string) ($row['description'] ?? '-')) ?></td>
                    <td class="text-center small"><?= (int) $row['sparepart_count'] ?> sparepart</td>
                    <td>
                        <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>">
                            <?= $active ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <?php if ($canManage): ?>
                        <td class="text-end">
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(base_url('kategori/' . (int) $row['id'] . '/ubah')) ?>">Ubah</a>
                                <form method="post" class="d-inline"
                                      action="<?= e(base_url('kategori/' . (int) $row['id'] . '/status')) ?>"
                                      data-confirm="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?> kategori <?= e((string) $row['name']) ?>?">
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
    Kategori nonaktif <strong>tidak dapat dipakai</strong> untuk membuat sparepart baru, tetapi sparepart lama
    tetap tercatat pada kategorinya. Penonaktifan tidak menghapus data maupun riwayat.
</p>
