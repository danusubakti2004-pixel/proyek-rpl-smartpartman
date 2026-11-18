<?php
/**
 * Riwayat pergerakan stok / kartu stok global (FR-08).
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $filters
 * @var array{masuk:float,keluar:float,menyesuaikan:float} $totals
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<int,array<string,mixed>> $spareparts
 * @var string[] $types
 */
use App\Models\StockMovement;

$typeLabel = static function (string $type): array {
    return match ($type) {
        StockMovement::TYPE_INITIAL    => ['Stok Awal', 'primary'],
        StockMovement::TYPE_IN         => ['Masuk', 'success'],
        StockMovement::TYPE_OUT        => ['Keluar', 'danger'],
        StockMovement::TYPE_OPNAME     => ['Penyesuaian Opname', 'warning'],
        StockMovement::TYPE_CORRECTION => ['Koreksi', 'info'],
        default                        => [$type, 'secondary'],
    };
};

$sourceLabel = static function (string $referenceType): string {
    return match ($referenceType) {
        'GOODS_RECEIPT' => 'Penerimaan',
        'GOODS_ISSUE'   => 'Pengeluaran',
        'OPNAME'        => 'Stock Opname',
        'CORRECTION'    => 'Koreksi',
        'IMPORT'        => 'Impor',
        default         => 'Manual',
    };
};

$sourceUrl = static function (array $row): ?string {
    $id = (int) ($row['reference_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    return match ((string) $row['reference_type']) {
        'GOODS_RECEIPT' => base_url('transaksi/masuk/' . $id),
        'GOODS_ISSUE'   => base_url('transaksi/keluar/' . $id),
        'OPNAME'        => base_url('transaksi/opname/' . $id),
        'CORRECTION'    => base_url('transaksi/koreksi/' . $id),
        default         => null,
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
        <h2 class="h5 text-navy mb-0">Riwayat Pergerakan Stok</h2>
        <p class="text-muted small mb-0">
            Ledger pergerakan stok (append-only). Setiap perubahan kuantitas stok selalu punya baris di sini.
            Total <strong><?= (int) $total ?></strong> baris.
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-danger btn-sm" href="<?= e(base_url('transaksi/koreksi')) ?>">Koreksi Transaksi</a>
        <?php endif; ?>
        <span class="badge text-bg-light border align-self-center">Append-only &middot; tidak dapat diubah</span>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Total masuk (periode filter)</div>
                <div class="fs-5 fw-semibold text-success">+<?= e($qty($totals['masuk'])) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Total keluar (periode filter)</div>
                <div class="fs-5 fw-semibold text-danger">-<?= e($qty($totals['keluar'])) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Perubahan bersih</div>
                <div class="fs-5 fw-semibold text-navy">
                    <?= $totals['menyesuaikan'] > 0 ? '+' : '' ?><?= e($qty($totals['menyesuaikan'])) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('transaksi/riwayat')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q" value="<?= e($filters['search']) ?>"
                       placeholder="Kode / nama / nomor dokumen / catatan">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="sparepart">Sparepart</label>
                <select class="form-select form-select-sm" id="sparepart" name="sparepart">
                    <option value="">Semua sparepart</option>
                    <?php foreach ($spareparts as $sparepart): ?>
                        <option value="<?= (int) $sparepart['id'] ?>"
                            <?= $filters['sparepart_id'] === (string) $sparepart['id'] ? 'selected' : '' ?>>
                            <?= e((string) $sparepart['code'] . ' — ' . (string) $sparepart['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="jenis">Jenis</label>
                <select class="form-select form-select-sm" id="jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= $filters['movement_type'] === $type ? 'selected' : '' ?>>
                            <?= e($typeLabel($type)[0]) ?>
                        </option>
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
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/riwayat')) ?>">Bersihkan</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Tgl bisnis</th>
                <th>Sparepart</th>
                <th>Jenis</th>
                <th class="text-end">Jumlah</th>
                <th class="text-end">Sebelum</th>
                <th class="text-end">Sesudah</th>
                <th>Dokumen sumber</th>
                <th>Pencatat</th>
                <th>Catatan</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="text-center text-muted small py-4">
                    Belum ada pergerakan stok yang cocok dengan filter.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                [$label, $color] = $typeLabel((string) $row['movement_type']);
                $url = $sourceUrl($row);
                $quantity = (float) $row['quantity'];
                ?>
                <tr>
                    <td class="small text-muted"><?= (int) $row['id'] ?></td>
                    <td class="small"><?= e(tanggal_id((string) ($row['movement_date'] ?? $row['created_at']))) ?></td>
                    <td class="small">
                        <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                        <div class="text-muted"><?= e((string) $row['sparepart_name']) ?></div>
                    </td>
                    <td><span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span></td>
                    <td class="text-end fw-semibold <?= $quantity < 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $quantity > 0 ? '+' : '' ?><?= e($qty($quantity)) ?> <?= e((string) $row['unit']) ?>
                    </td>
                    <td class="text-end small"><?= e($qty($row['stock_before'])) ?></td>
                    <td class="text-end small"><?= e($qty($row['stock_after'])) ?></td>
                    <td class="small">
                        <div><?= e($sourceLabel((string) ($row['reference_type'] ?? ''))) ?></div>
                        <?php if (!empty($row['reference_number'])): ?>
                            <?php if ($url !== null): ?>
                                <a href="<?= e($url) ?>"><?= e((string) $row['reference_number']) ?></a>
                            <?php else: ?>
                                <?= e((string) $row['reference_number']) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ((int) ($row['reversal_of_id'] ?? 0) > 0): ?>
                            <div>
                                <a class="text-danger" href="<?= e(base_url('transaksi/koreksi/' . (int) $row['reversal_of_id'])) ?>">
                                    membalik #<?= (int) $row['reversal_of_id'] ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e((string) ($row['user_name'] ?? '-')) ?></td>
                    <td class="small text-muted"><?= e((string) ($row['note'] ?? '-')) ?></td>
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
                   href="<?= e(base_url('transaksi/riwayat') . $query(['page' => max(1, $page - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(base_url('transaksi/riwayat') . $query(['page' => min($pages, $page + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Ledger tidak pernah diubah atau dihapus. Koreksi muncul sebagai baris baru bertipe
    <strong>Koreksi</strong> yang menunjuk transaksi asal, sehingga jejak audit tetap utuh.
</p>
