<?php
/**
 * Detail pengeluaran barang (FR-07).
 *
 * @var bool $canManage
 * @var array<string,mixed> $row
 * @var array<int,array<string,mixed>> $items
 * @var array<string,mixed>|null $movement
 */
use App\Services\StockLedger;

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};

$usageLabel = match ((string) $row['usage_type']) {
    'service'   => 'Service',
    'penjualan' => 'Penjualan',
    default     => 'Lainnya',
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Pengeluaran <?= e((string) $row['issue_number']) ?></h2>
        <p class="text-muted small mb-0">
            Dicatat oleh <?= e((string) ($row['recorded_by_name'] ?? '-')) ?>
            pada <?= e(tanggal_id((string) $row['created_at'])) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/keluar')) ?>">&laquo; Kembali</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/keluar/' . (int) $row['id'] . '/cetak')) ?>"
           target="_blank" rel="noopener">Cetak Bukti</a>
        <?php if ($canManage && $movement !== null): ?>
            <a class="btn btn-outline-danger btn-sm"
               href="<?= e(base_url('transaksi/koreksi/' . (int) $movement['id'])) ?>">Koreksi Transaksi</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Data Pengeluaran</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 col-md-4">Nomor dokumen</dt>
                    <dd class="col-7 col-md-8"><?= e((string) $row['issue_number']) ?></dd>

                    <dt class="col-5 col-md-4">Tanggal keluar</dt>
                    <dd class="col-7 col-md-8"><?= e(tanggal_id((string) $row['issue_date'])) ?></dd>

                    <dt class="col-5 col-md-4">Jenis penggunaan</dt>
                    <dd class="col-7 col-md-8"><?= e($usageLabel) ?></dd>

                    <dt class="col-5 col-md-4">Nomor SPK</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['spk_number'] ?? '-')) ?></dd>

                    <dt class="col-5 col-md-4">Nomor polisi</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['plate_number'] ?? '-')) ?></dd>

                    <dt class="col-5 col-md-4">Catatan</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['note'] ?? '-')) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">Item Dikeluarkan</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th class="text-end">Jumlah</th>
                        <th class="text-end">Stok Sekarang</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $item['sparepart_code']) ?></td>
                            <td><?= e((string) $item['sparepart_name']) ?></td>
                            <td class="text-end"><?= e($qty($item['quantity'])) ?> <?= e((string) $item['unit']) ?></td>
                            <td class="text-end"><?= e($qty($item['sparepart_stock'])) ?> <?= e((string) $item['unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">Pergerakan Stok Terkait</div>
            <div class="card-body small">
                <?php if ($movement === null): ?>
                    <p class="text-muted mb-0">Belum ada pergerakan stok untuk dokumen ini.</p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-6">Pergerakan #</dt>
                        <dd class="col-6"><?= (int) $movement['id'] ?></dd>

                        <dt class="col-6">Jenis</dt>
                        <dd class="col-6">Keluar (KELUAR)</dd>

                        <dt class="col-6">Jumlah</dt>
                        <dd class="col-6"><?= e($qty($movement['quantity'])) ?> <?= e((string) $movement['unit']) ?></dd>

                        <dt class="col-6">Stok sebelum</dt>
                        <dd class="col-6"><?= e($qty($movement['stock_before'])) ?></dd>

                        <dt class="col-6">Stok sesudah</dt>
                        <dd class="col-6"><?= e($qty($movement['stock_after'])) ?></dd>

                        <dt class="col-6">Tanggal bisnis</dt>
                        <dd class="col-6"><?= e(tanggal_id((string) ($movement['movement_date'] ?? $movement['created_at']))) ?></dd>

                        <dt class="col-6">Sisa dapat dikoreksi</dt>
                        <dd class="col-6"><?= e(StockLedger::formatQty(StockLedger::remainingReversible($movement))) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info small mt-3 mb-0">
            Karena stok berkurang di sini, koreksi pengeluaran akan <strong>menambah</strong> stok kembali
            (transaksi pembalik) tanpa menghapus riwayat.
        </div>
    </div>
</div>
