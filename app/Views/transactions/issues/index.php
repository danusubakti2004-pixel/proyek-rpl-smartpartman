<?php
/**
 * Daftar barang keluar / pengeluaran (FR-07).
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 */
$usageLabel = static function (string $usage): string {
    return match ($usage) {
        'service'   => 'Service',
        'penjualan' => 'Penjualan',
        default     => 'Lainnya',
    };
};

$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return $params === [] ? '' : '?' . http_build_query($params);
};

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Barang Keluar</h2>
        <p class="text-muted small mb-0">
            Pengeluaran sparepart. Stok berkurang hanya setelah transaksi tersimpan.
            Total <strong><?= (int) $total ?></strong> dokumen.
        </p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-navy" href="<?= e(base_url('transaksi/keluar/tambah')) ?>">+ Catat Pengeluaran</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('transaksi/keluar')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Nomor dokumen / SPK / nomor polisi">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="jenis">Jenis penggunaan</label>
                <select class="form-select form-select-sm" id="jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach (['service' => 'Service', 'penjualan' => 'Penjualan', 'lainnya' => 'Lainnya'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['usage_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
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
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button>
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
                <th>Sparepart</th>
                <th class="text-end">Jumlah</th>
                <th>Jenis</th>
                <th>SPK / Nopol</th>
                <th>Pencatat</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-center text-muted small py-4">
                    Belum ada pengeluaran yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['issue_number']) ?></td>
                    <td class="small"><?= e(tanggal_id((string) $row['issue_date'])) ?></td>
                    <td class="small">
                        <?php if (!empty($row['sparepart_code'])): ?>
                            <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                            <div class="text-muted"><?= e((string) $row['sparepart_name']) ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e($qty($row['total_quantity'])) ?></td>
                    <td><span class="badge text-bg-light border"><?= e($usageLabel((string) $row['usage_type'])) ?></span></td>
                    <td class="small">
                        <div><?= e((string) ($row['spk_number'] ?? '-')) ?></div>
                        <?php if (!empty($row['plate_number'])): ?>
                            <div class="text-muted"><?= e((string) $row['plate_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e((string) ($row['recorded_by_name'] ?? '-')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('transaksi/keluar/' . (int) $row['id'])) ?>">Lihat</a>
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
                   href="<?= e(base_url('transaksi/keluar') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('transaksi/keluar') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Sistem <strong>menolak</strong> pengeluaran yang melebihi stok tersedia, sehingga stok tidak pernah negatif.
    Transaksi tersimpan tidak dihapus — gunakan <strong>Koreksi Transaksi</strong> bila ada kesalahan.
</p>
