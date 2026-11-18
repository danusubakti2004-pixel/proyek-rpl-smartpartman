<?php
/**
 * Detail penerimaan barang (FR-06).
 *
 * @var bool $canManage
 * @var array<string,mixed> $row
 * @var array<int,array<string,mixed>> $items
 * @var array<string,mixed>|null $movement
 * @var bool $hasEvidence
 * @var string $attachmentUrl
 */
use App\Services\StockLedger;

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Penerimaan <?= e((string) $row['receipt_number']) ?></h2>
        <p class="text-muted small mb-0">
            Dicatat oleh <?= e((string) ($row['recorded_by_name'] ?? '-')) ?>
            pada <?= e(tanggal_id((string) $row['created_at'])) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/masuk')) ?>">&laquo; Kembali</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/masuk/' . (int) $row['id'] . '/cetak')) ?>"
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
            <div class="card-header bg-white fw-semibold text-navy">Data Penerimaan</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 col-md-4">Nomor dokumen</dt>
                    <dd class="col-7 col-md-8"><?= e((string) $row['receipt_number']) ?></dd>

                    <dt class="col-5 col-md-4">Tanggal diterima</dt>
                    <dd class="col-7 col-md-8"><?= e(tanggal_id((string) $row['receipt_date'])) ?></dd>

                    <dt class="col-5 col-md-4">Jenis penerimaan</dt>
                    <dd class="col-7 col-md-8">
                        <?= (string) $row['receipt_type'] === 'pembelian' ? 'Pembelian' : 'Lainnya' ?>
                        <?php if ((string) $row['receipt_type'] !== 'pembelian'): ?>
                            <span class="text-muted">(tidak memperbarui tanggal pembelian terakhir)</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 col-md-4">Supplier</dt>
                    <dd class="col-7 col-md-8">
                        <?= e((string) $row['supplier_code'] . ' — ' . (string) $row['supplier_name']) ?>
                        <?php if ((int) $row['supplier_active'] !== 1): ?>
                            <span class="badge text-bg-secondary ms-1">Nonaktif</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 col-md-4">Nomor invoice</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['invoice_number'] ?? '-')) ?></dd>

                    <dt class="col-5 col-md-4">Purchase Order</dt>
                    <dd class="col-7 col-md-8">
                        <?php if ((int) $row['has_po'] === 1): ?>
                            <a href="<?= e(base_url('pembelian/' . (int) $row['purchase_order_id'])) ?>">
                                <?= e((string) ($row['po_number'] ?? ('#' . (int) $row['purchase_order_id']))) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Tanpa PO</span>
                            <div class="text-muted">Alasan: <?= e((string) ($row['without_po_reason'] ?? '-')) ?></div>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 col-md-4">Catatan</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['note'] ?? '-')) ?></dd>

                    <dt class="col-5 col-md-4">Bukti pembelian</dt>
                    <dd class="col-7 col-md-8">
                        <?php if ($hasEvidence): ?>
                            <a href="<?= e($attachmentUrl) ?>" target="_blank" rel="noopener">
                                <?= e((string) ($row['attachment_name'] ?? 'Lihat bukti')) ?>
                            </a>
                            <div class="text-muted">Disimpan pada penyimpanan privat (butuh login untuk mengunduh).</div>
                        <?php else: ?>
                            <span class="text-muted">Tidak ada</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">Item Diterima</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th class="text-end">Jumlah</th>
                        <th class="text-end">Harga/Unit</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $item['sparepart_code']) ?></td>
                            <td>
                                <?= e((string) $item['sparepart_name']) ?>
                                <div class="small text-muted">
                                    Stok sekarang: <?= e($qty($item['sparepart_stock'])) ?> <?= e((string) $item['unit']) ?>
                                </div>
                            </td>
                            <td class="text-end"><?= e($qty($item['quantity'])) ?> <?= e((string) $item['unit']) ?></td>
                            <td class="text-end"><?= e(rupiah($item['unit_price'])) ?></td>
                            <td class="text-end"><?= e(rupiah((float) $item['quantity'] * (float) $item['unit_price'])) ?></td>
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
                        <dd class="col-6">Masuk (MASUK)</dd>

                        <dt class="col-6">Jumlah</dt>
                        <dd class="col-6">+<?= e($qty($movement['quantity'])) ?> <?= e((string) $movement['unit']) ?></dd>

                        <dt class="col-6">Stok sebelum</dt>
                        <dd class="col-6"><?= e($qty($movement['stock_before'])) ?></dd>

                        <dt class="col-6">Stok sesudah</dt>
                        <dd class="col-6"><?= e($qty($movement['stock_after'])) ?></dd>

                        <dt class="col-6">Tanggal bisnis</dt>
                        <dd class="col-6"><?= e(tanggal_id((string) ($movement['movement_date'] ?? $movement['created_at']))) ?></dd>

                        <dt class="col-6">Pencatat</dt>
                        <dd class="col-6"><?= e((string) ($movement['user_name'] ?? '-')) ?></dd>

                        <dt class="col-6">Sisa dapat dikoreksi</dt>
                        <dd class="col-6"><?= e(StockLedger::formatQty(StockLedger::remainingReversible($movement))) ?></dd>
                    </dl>
                    <?php if ((int) ($movement['reversal_of_id'] ?? 0) > 0): ?>
                        <p class="text-danger mb-0 mt-2">Baris ini adalah pergerakan pembalik.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info small mt-3 mb-0">
            Stok sparepart hanya berubah melalui ledger ini. Setiap koreksi membuat
            <strong>pergerakan pembalik baru</strong> — dokumen asli tetap tersimpan sebagai riwayat.
        </div>
    </div>
</div>
