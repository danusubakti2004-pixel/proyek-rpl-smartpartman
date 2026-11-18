<?php
/**
 * Daftar master sparepart + filter (FR-03).
 *
 * @var bool $canManage true = Partman
 * @var array<int,array<string,mixed>> $spareparts
 * @var array<string,string> $filters
 * @var array{total:int,aktif:int,nonaktif:int,habis:int,kritis:int,menipis:int,aman:int,tanpa_minimum:int} $stats
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $locations
 */
use App\Services\SparepartService;

$statusBadge = static function (array $row): array {
    return SparepartService::statusLabel(SparepartService::stockStatusOf($row));
};

$qty = static function (float $value): string {
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Master Sparepart</h2>
        <p class="text-muted small mb-0">
            Kode unik, satu kategori utama per sparepart. Stok hanya berubah lewat pergerakan stok (kartu stok).
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(base_url('import?jenis=sparepart')) ?>">Impor .csv/.xlsx</a>
            <a class="btn btn-navy" href="<?= e(base_url('sparepart/tambah')) ?>">+ Tambah Sparepart</a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Total', $stats['total'], 'secondary'],
        ['Aktif', $stats['aktif'], 'success'],
        ['Nonaktif', $stats['nonaktif'], 'secondary'],
        ['Stok Habis', $stats['habis'], 'danger'],
        ['Stok Kritis', $stats['kritis'], 'danger'],
        ['Stok Menipis', $stats['menipis'], 'warning'],
        ['Stok Aman', $stats['aman'], 'success'],
        ['Min. Belum Diatur', $stats['tanpa_minimum'], 'secondary'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small"><?= e($label) ?></div>
                    <div class="fs-4 fw-semibold text-<?= e($color) ?>"><?= (int) $value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-info small" role="status">
    Status stok (PRD §13.3): <strong>Aman</strong> &gt; 150% stok minimum &middot;
    <strong>Menipis</strong> 100%–150% &middot; <strong>Kritis</strong> di atas 0 tapi di bawah minimum &middot;
    <strong>Habis</strong> 0. Bila stok minimum belum diatur (0), status
    <strong>Min. Belum Diatur</strong> ditampilkan sebagai pengecualian eksplisit &amp; agar segera diperbaiki.
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('sparepart')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Kode / nama / brand / model">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="kategori">Kategori</label>
                <select class="form-select form-select-sm" id="kategori" name="kategori">
                    <option value="">Semua</option>
                    <?php foreach ($categories as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $filters['category_id'] === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e((string) $option['name']) ?><?= (int) $option['is_active'] === 1 ? '' : ' (nonaktif)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="supplier">Supplier</label>
                <select class="form-select form-select-sm" id="supplier" name="supplier">
                    <option value="">Semua</option>
                    <?php foreach ($suppliers as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $filters['supplier_id'] === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e((string) $option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="lokasi">Lokasi</label>
                <select class="form-select form-select-sm" id="lokasi" name="lokasi">
                    <option value="">Semua</option>
                    <?php foreach ($locations as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $filters['location_id'] === (string) $option['id'] ? 'selected' : '' ?>>
                            <?= e((string) $option['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="status_stok">Status Stok</label>
                <select class="form-select form-select-sm" id="status_stok" name="status_stok">
                    <option value="">Semua</option>
                    <?php foreach ([
                        'habis' => 'Habis',
                        'kritis' => 'Kritis',
                        'menipis' => 'Menipis',
                        'aman' => 'Aman',
                        'tanpa_minimum' => 'Min. Belum Diatur',
                    ] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['stock_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="status">Status Data</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua</option>
                    <?php foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['active'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('sparepart')) ?>">Reset</a>
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
                <th>Nama</th>
                <th>Kategori</th>
                <th class="text-end">Harga Beli</th>
                <th class="text-end">Harga Jual</th>
                <th class="text-end">Stok</th>
                <th class="text-center">Status Stok</th>
                <th>Lokasi</th>
                <th>Supplier</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($spareparts === []): ?>
                <tr><td colspan="11" class="text-center text-muted small py-4">
                    Tidak ada sparepart yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($spareparts as $row): ?>
                <?php
                [$statusLabel, $statusColor] = $statusBadge($row);
                $active = (int) $row['is_active'] === 1;
                ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['code']) ?></td>
                    <td>
                        <?= e((string) $row['name']) ?>
                        <div class="small text-muted">
                            <?= e((string) ($row['brand'] ?? '')) ?>
                            <?= $row['vehicle_model'] !== null && $row['vehicle_model'] !== '' ? ' &middot; ' . e((string) $row['vehicle_model']) : '' ?>
                        </div>
                        <?php if (!$active): ?>
                            <span class="badge text-bg-secondary">nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e((string) $row['category_name']) ?></td>
                    <td class="text-end small"><?= e(rupiah($row['purchase_price'])) ?></td>
                    <td class="text-end small"><?= e(rupiah($row['selling_price'])) ?></td>
                    <td class="text-end fw-semibold">
                        <?= e($qty((float) $row['stock'])) ?> <span class="text-muted small"><?= e((string) $row['unit']) ?></span>
                        <div class="small text-muted">min <?= e($qty((float) $row['min_stock'])) ?></div>
                        <?php $percent = SparepartService::statusPercent($row); ?>
                        <div class="small text-muted">
                            <?= $percent !== null ? e((string) $percent) . '% dari minimum' : 'minimum belum diatur' ?>
                        </div>
                    </td>
                    <td class="text-center"><span class="badge text-bg-<?= e($statusColor) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="small"><?= e((string) ($row['location_code'] ?? '-')) ?></td>
                    <td class="small"><?= e((string) ($row['supplier_name'] ?? '-')) ?></td>
                    <td>
                        <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>">
                            <?= $active ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(base_url('sparepart/' . (int) $row['id'])) ?>">Detail</a>
                            <?php if ($canManage): ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(base_url('sparepart/' . (int) $row['id'] . '/ubah')) ?>">Ubah</a>
                                <form method="post" class="d-inline"
                                      action="<?= e(base_url('sparepart/' . (int) $row['id'] . '/status')) ?>"
                                      data-confirm="<?= $active ? 'Nonaktifkan' : 'Aktifkan' ?> sparepart <?= e((string) $row['code']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                    <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                        <?= $active ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
