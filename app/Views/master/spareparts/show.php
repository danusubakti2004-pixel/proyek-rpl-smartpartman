<?php
/**
 * Detail sparepart + kartu stok (FR-03, FR-05).
 *
 * @var bool $canManage
 * @var array<string,mixed> $row
 * @var string $status 'habis'|'kritis'|'menipis'|'aman'|'tanpa_minimum'
 * @var array<int,array<string,mixed>> $movements
 * @var array<string,mixed>|null $initial
 * @var array<int,array<string,mixed>> $purchases
 */
use App\Models\StockMovement;
use App\Services\SparepartService;

$purchases = $purchases ?? [];

[$statusLabel, $statusColor] = SparepartService::statusLabel($status);
$statusPercent = SparepartService::statusPercent($row);
$active = (int) $row['is_active'] === 1;

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};

$movementLabel = static function (string $type): array {
    return match ($type) {
        StockMovement::TYPE_INITIAL    => ['Stok Awal', 'primary'],
        StockMovement::TYPE_IN         => ['Masuk', 'success'],
        StockMovement::TYPE_OUT        => ['Keluar', 'danger'],
        StockMovement::TYPE_OPNAME     => ['Penyesuaian Opname', 'warning'],
        StockMovement::TYPE_CORRECTION => ['Koreksi', 'info'],
        default                        => [$type, 'secondary'],
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">
            <?= e((string) $row['code']) ?> — <?= e((string) $row['name']) ?>
        </h2>
        <div class="d-flex flex-wrap gap-1 mt-1">
            <span class="badge text-bg-<?= e($statusColor) ?>">Stok <?= e($statusLabel) ?></span>
            <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? 'Aktif' : 'Nonaktif' ?></span>
            <span class="badge text-bg-light border"><?= e((string) $row['category_name']) ?></span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('sparepart')) ?>">&laquo; Daftar</a>
        <?php if ($canManage): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= e(base_url('sparepart/' . (int) $row['id'] . '/ubah')) ?>">Ubah Data</a>
            <a class="btn btn-navy btn-sm" href="<?= e(base_url('sparepart/' . (int) $row['id'] . '/stok-awal')) ?>">
                <?= $initial === null ? 'Input Stok Awal' : 'Lihat Stok Awal' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Data Master</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th class="w-50 text-muted fw-normal small">Kode</th><td class="fw-semibold"><?= e((string) $row['code']) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Nama</th><td><?= e((string) $row['name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Kategori</th><td><?= e((string) $row['category_name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Brand / Model</th>
                        <td><?= e((string) ($row['brand'] ?? '-')) ?> / <?= e((string) ($row['vehicle_model'] ?? '-')) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Satuan</th><td><?= e((string) $row['unit']) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Harga Beli Terakhir</th>
                        <td><?= e(rupiah($row['purchase_price'])) ?>
                            <div class="small text-muted">Diperbarui otomatis dari penerimaan bertipe pembelian.</div>
                        </td></tr>
                    <tr><th class="text-muted fw-normal small">Harga Beli Rata-rata</th>
                        <td><?= $row['avg_purchase_price'] !== null
                            ? e(rupiah($row['avg_purchase_price']))
                            : '<span class="text-muted">belum dihitung (belum ada pembelian)</span>' ?>
                            <?php if ($row['avg_purchase_price'] !== null): ?>
                                <div class="small text-muted">Dipakai sebagai dasar nilai persediaan.</div>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th class="text-muted fw-normal small">Harga Jual</th><td><?= e(rupiah($row['selling_price'])) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Stok Tersedia</th>
                        <td><span class="fw-semibold"><?= e($qty($row['stock'])) ?></span> <?= e((string) $row['unit']) ?>
                            <span class="text-muted small">&middot; minimum <?= e($qty($row['min_stock'])) ?></span>
                            <div class="small text-muted">
                                <?= $statusPercent !== null
                                    ? e((string) $statusPercent) . '% dari stok minimum'
                                    : 'Stok minimum belum diatur — perbaiki di Ubah Data agar status stok terdefinisi.' ?>
                            </div></td></tr>
                    <tr><th class="text-muted fw-normal small">Lokasi</th>
                        <td><?= $row['location_code'] !== null
                            ? e((string) $row['location_code'] . ' — ' . (string) $row['location_name'])
                            : '<span class="text-muted">belum ditentukan</span>' ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Supplier</th>
                        <td><?= $row['supplier_name'] !== null
                            ? e((string) $row['supplier_name'])
                            : '<span class="text-muted">belum ditentukan</span>' ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Pembelian Terakhir</th>
                        <td><?= e(tanggal_id($row['last_purchase_date'] !== null ? (string) $row['last_purchase_date'] : null)) ?>
                            <?php if ($row['avg_purchase_price'] !== null): ?>
                                <span class="text-muted small">&middot; rata-rata <?= e(rupiah($row['avg_purchase_price'])) ?>/unit</span>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th class="text-muted fw-normal small">Dibuat</th>
                        <td class="small text-muted"><?= e(tanggal_id((string) $row['created_at'], true)) ?></td></tr>
                    <tr><th class="text-muted fw-normal small">Diubah</th>
                        <td class="small text-muted"><?= e(tanggal_id($row['updated_at'] !== null ? (string) $row['updated_at'] : null, true)) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Nilai Persediaan (estimasi)</div>
            <div class="card-body">
                <div class="row g-3">
                    <?php
                    $nilaiBeliPerUnit = ($row['avg_purchase_price'] !== null && (float) $row['avg_purchase_price'] > 0)
                        ? (float) $row['avg_purchase_price']
                        : (float) $row['purchase_price'];
                    ?>
                    <div class="col-6">
                        <div class="text-muted small">Nilai beli</div>
                        <div class="fw-semibold"><?= e(rupiah((float) $row['stock'] * $nilaiBeliPerUnit)) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Nilai jual</div>
                        <div class="fw-semibold"><?= e(rupiah((float) $row['stock'] * (float) $row['selling_price'])) ?></div>
                    </div>
                </div>
                <p class="text-muted small mb-0 mt-2">
                    Nilai beli memakai <strong>harga beli rata-rata</strong> (weighted average dari seluruh
                    penerimaan bertipe pembelian) bila tersedia; selain itu memakai harga beli terakhir.
                </p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold">Riwayat Harga Beli (10 pembelian terakhir)</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Dokumen</th>
                        <th>Supplier</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Harga/unit</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($purchases === []): ?>
                        <tr><td colspan="5" class="text-center text-muted small py-3">
                            Belum ada pembelian tercatat untuk sparepart ini.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td class="small"><?= e(tanggal_id((string) $purchase['receipt_date'])) ?></td>
                            <td class="small"><?= e((string) $purchase['receipt_number']) ?>
                                <?php if (($purchase['invoice_number'] ?? null) !== null && $purchase['invoice_number'] !== ''): ?>
                                    <div class="text-muted" style="font-size:.75rem">Inv: <?= e((string) $purchase['invoice_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e((string) ($purchase['supplier_name'] ?? '-')) ?></td>
                            <td class="text-end small"><?= e($qty($purchase['quantity'])) ?></td>
                            <td class="text-end small"><?= e(rupiah($purchase['unit_price'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Riwayat harga berasal dari dokumen penerimaan (append-only) — harga master hanya menyimpan nilai terakhir.
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Stok Awal</div>
            <div class="card-body">
                <?php if ($initial === null): ?>
                    <p class="text-muted small mb-0">
                        Belum ada catatan stok awal untuk sparepart ini.
                        <?= $canManage ? 'Gunakan tombol "Input Stok Awal".' : 'Hubungi Partman untuk pencatatan.' ?>
                    </p>
                <?php else: ?>
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted fw-normal">Jumlah</dt>
                        <dd class="col-7 fw-semibold"><?= e($qty($initial['quantity'])) ?> <?= e((string) $row['unit']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Tanggal</dt>
                        <dd class="col-7"><?= e(tanggal_id((string) ($initial['movement_date'] ?? $initial['created_at']))) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Dicatat oleh</dt>
                        <dd class="col-7"><?= e((string) ($initial['user_name'] ?? '-')) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Sumber</dt>
                        <dd class="col-7"><?= e((string) ($initial['reference_type'] ?? '-')) ?></dd>
                        <?php if (!empty($initial['note'])): ?>
                            <dt class="col-5 text-muted fw-normal">Catatan</dt>
                            <dd class="col-7"><?= e((string) $initial['note']) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <p class="text-muted small mb-0 mt-2">
                        Koreksi setelah ini hanya lewat <strong>stock opname</strong> (menu Stock Opname) — bukan
                        dengan menimpa catatan lama.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Kartu Stok (20 pergerakan terakhir)</span>
                <a class="small" href="<?= e(base_url('transaksi/riwayat?sparepart=' . (int) $row['id'])) ?>">Riwayat lengkap &raquo;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th class="text-end">Jumlah</th>
                        <th class="text-end">Sisa</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($movements === []): ?>
                        <tr><td colspan="4" class="text-center text-muted small py-3">Belum ada pergerakan stok.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($movements as $movement): ?>
                        <?php [$label, $color] = $movementLabel((string) $movement['movement_type']); ?>
                        <tr>
                            <td class="small"><?= e(tanggal_id((string) ($movement['movement_date'] ?? $movement['created_at']))) ?></td>
                            <td><span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span></td>
                            <td class="text-end small"><?= e($qty($movement['quantity'])) ?></td>
                            <td class="text-end small fw-semibold"><?= e($qty($movement['stock_after'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Ledger bersifat <strong>append-only</strong>: baris lama tidak pernah diubah atau dihapus.
            </div>
        </div>
    </div>
</div>
