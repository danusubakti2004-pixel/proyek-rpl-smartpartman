<?php
/**
 * Detail penyesuaian stok.
 *
 * @var array<string,mixed> $row
 */
$qty = (float) $row['quantity'];
$isAdd = $qty > 0;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Detail Penyesuaian <?= e((string) ($row['reference_number'] ?? '')) ?></h2>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/penyesuaian')) ?>">&laquo; Kembali</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
            <tr><th style="width:30%;">Nomor</th><td><?= e((string) ($row['reference_number'] ?? '-')) ?></td></tr>
            <tr><th>Tanggal</th><td><?= e(tanggal_id((string) $row['movement_date'])) ?></td></tr>
            <tr><th>Sparepart</th><td><?= e((string) $row['sparepart_code'] . ' — ' . (string) $row['sparepart_name']) ?></td></tr>
            <tr><th>Jenis</th><td><?= $isAdd ? 'Penambahan' : 'Pengurangan' ?></td></tr>
            <tr><th>Stok sebelum</th><td><?= e(rtrim(rtrim(number_format((float) $row['stock_before'], 2, ',', '.'), '0'), ',')) ?></td></tr>
            <tr><th>Perubahan</th><td class="<?= $isAdd ? 'text-success' : 'text-danger' ?>"><?= ($isAdd ? '+' : '') . e(rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',')) ?></td></tr>
            <tr><th>Stok sesudah</th><td class="fw-semibold"><?= e(rtrim(rtrim(number_format((float) $row['stock_after'], 2, ',', '.'), '0'), ',')) ?></td></tr>
            <tr><th>Alasan/Catatan</th><td><?= e((string) ($row['note'] ?? '-')) ?></td></tr>
            <tr><th>Dilakukan oleh</th><td><?= e((string) ($row['user_name'] ?? $row['username'] ?? '-')) ?></td></tr>
            <tr><th>Waktu sistem</th><td><?= e((string) ($row['created_at'] ?? '-')) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>
PY