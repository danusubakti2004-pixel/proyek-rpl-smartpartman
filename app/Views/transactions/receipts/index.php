<?php
/**
 * Daftar barang masuk / penerimaan (FR-06).
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<int,array<string,mixed>> $suppliers
 */
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
        <h2 class="h5 text-navy mb-0">Barang Masuk</h2>
        <p class="text-muted small mb-0">
            Penerimaan sparepart. Stok bertambah <strong>hanya setelah</strong> transaksi tersimpan.
            Total <strong><?= (int) $total ?></strong> dokumen.
        </p>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-navy" href="<?= e(base_url('transaksi/masuk/tambah')) ?>">+ Catat Penerimaan</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('transaksi/masuk')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Nomor dokumen / invoice / supplier">
            </div>
            <div class="col-6 col-md-3">
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
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="jenis">Jenis</label>
                <select class="form-select form-select-sm" id="jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <option value="pembelian" <?= $filters['receipt_type'] === 'pembelian' ? 'selected' : '' ?>>Pembelian</option>
                    <option value="lainnya" <?= $filters['receipt_type'] === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
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
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/masuk')) ?>">Bersihkan</a>
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
                <th class="text-end">Harga/Unit</th>
                <th>Supplier</th>
                <th>Jenis</th>
                <th>Bukti</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-center text-muted small py-4">
                    Belum ada penerimaan yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e((string) $row['receipt_number']) ?></td>
                    <td class="small"><?= e(tanggal_id((string) $row['receipt_date'])) ?></td>
                    <td class="small">
                        <?php if (!empty($row['sparepart_code'])): ?>
                            <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                            <div class="text-muted"><?= e((string) $row['sparepart_name']) ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e($qty($row['total_quantity'])) ?></td>
                    <td class="text-end"><?= e(rupiah($row['unit_price'])) ?></td>
                    <td class="small"><?= e((string) $row['supplier_name']) ?></td>
                    <td class="small">
                        <?php if ((string) $row['receipt_type'] === 'pembelian'): ?>
                            <span class="badge text-bg-primary">Pembelian</span>
                        <?php else: ?>
                            <span class="badge text-bg-light border">Lainnya</span>
                        <?php endif; ?>
                        <?php if ((int) $row['has_po'] === 1): ?>
                            <span class="badge text-bg-info">PO</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning" title="<?= e((string) ($row['without_po_reason'] ?? '')) ?>">Tanpa PO</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($row['attachment_path'])): ?>
                            <span class="badge text-bg-success">Ada</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(base_url('transaksi/masuk/' . (int) $row['id'])) ?>">Lihat</a>
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
                   href="<?= e(base_url('transaksi/masuk') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('transaksi/masuk') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Penerimaan tanpa PO wajib mencantumkan alasan. Hanya penerimaan bertipe <strong>pembelian</strong> yang
    memperbarui tanggal pembelian terakhir &amp; harga beli rata-rata sparepart. Transaksi yang sudah tersimpan
    tidak dihapus — koreksi lewat menu <strong>Koreksi Transaksi</strong>.
</p>
