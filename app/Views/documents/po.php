<?php
/**
 * Dokumen Purchase Order (Fase 5, baca-saja dari data PO yang ada).
 *
 * @var array<string,mixed> $po
 * @var array<int,array<string,mixed>> $items
 * @var array{name:string,address:string,phone:string,email:string,note:string} $identity
 */
use App\Services\ReportService;

$po = $po ?? [];
$items = $items ?? [];
$identity = $identity ?? ['name' => 'SmartPartMan', 'address' => '', 'phone' => '', 'email' => '', 'note' => ''];
$qty = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    $number = (float) $value;
    $decimals = floor($number) === $number ? 0 : 2;
    return number_format($number, $decimals, ',', '.');
};
$total = 0.0;
foreach ($items as $item) {
    $total += (float) $item['quantity'] * (float) $item['unit_price'];
}
?>
<div class="doc-header d-flex justify-content-between gap-3">
    <div class="d-flex align-items-center gap-2">
        <?php if (($identity['logo'] ?? '') !== ''): ?>
            <img src="<?= e(logo_url((string) $identity['logo'])) ?>" alt="Logo" class="doc-logo">
        <?php endif; ?>
        <div>
            <div class="doc-workshop"><?= e((string) $identity['name']) ?></div>
            <div class="doc-meta">
                <?php if (($identity['address'] ?? '') !== ''): ?>
                    <?= e((string) $identity['address']) ?><br>
                <?php endif; ?>
                <?php if (($identity['phone'] ?? '') !== ''): ?>Telp. <?= e((string) $identity['phone']) ?><?php endif; ?>
                <?php if (($identity['email'] ?? '') !== ''): ?> &middot; <?= e((string) $identity['email']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <div>
        <div class="doc-title">PURCHASE ORDER</div>
        <div class="doc-subtitle"><?= e((string) ($po['po_number'] ?? '-')) ?></div>
    </div>
</div>

<div class="row g-3 small mb-2">
    <div class="col-6">
        <div class="doc-section-title mb-1">Kepada</div>
        <div class="fw-semibold"><?= e((string) ($po['supplier_name'] ?? '-')) ?></div>
        <?php if (($po['supplier_address'] ?? '') !== ''): ?>
            <div class="text-muted"><?= e((string) $po['supplier_address']) ?></div>
        <?php endif; ?>
        <div class="text-muted">
            <?php if (($po['supplier_phone'] ?? '') !== ''): ?>Telp. <?= e((string) $po['supplier_phone']) ?><?php endif; ?>
            <?php if (($po['supplier_email'] ?? '') !== ''): ?> &middot; <?= e((string) $po['supplier_email']) ?><?php endif; ?>
        </div>
        <?php if (($po['supplier_contact'] ?? '') !== ''): ?>
            <div class="text-muted">Kontak: <?= e((string) $po['supplier_contact']) ?></div>
        <?php endif; ?>
    </div>
    <div class="col-6">
        <table class="table table-sm table-borderless mb-0 small">
            <tbody>
            <tr><th style="width:42%;">Tanggal PO</th><td><?= e(tanggal_id((string) ($po['created_at'] ?? ''))) ?></td></tr>
            <tr><th>Status PO</th><td><?= e(ReportService::label('po', $po['status'] ?? '')) ?></td></tr>
            <tr><th>Pembayaran</th><td><?= e(ReportService::label('payment', $po['payment_status'] ?? '')) ?></td></tr>
            <tr><th>Dibuat oleh</th><td><?= e((string) ($po['created_by_name'] ?? '-')) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<table class="doc-table">
    <colgroup>
        <col style="width:5%"><col style="width:13%"><col style="width:37%">
        <col style="width:9%"><col style="width:9%"><col style="width:13%"><col style="width:14%">
    </colgroup>
    <thead>
    <tr>
        <th>#</th>
        <th>Kode</th>
        <th>Nama Barang</th>
        <th class="num">Qty</th>
        <th>Satuan</th>
        <th class="num">Harga</th>
        <th class="num">Subtotal</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($items === []): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Belum ada item PO.</td></tr>
    <?php else: ?>
        <?php foreach ($items as $index => $item): ?>
            <tr>
                <td class="num"><?= $index + 1 ?></td>
                <td><?= e((string) $item['sparepart_code']) ?></td>
                <td><?= e((string) $item['sparepart_name']) ?></td>
                <td class="num"><?= e($qty($item['quantity'])) ?></td>
                <td><?= e((string) $item['unit']) ?></td>
                <td class="num"><?= e(rupiah($item['unit_price'])) ?></td>
                <td class="num"><?= e(rupiah((float) $item['quantity'] * (float) $item['unit_price'])) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
    <tr>
        <td colspan="6" class="num">TOTAL</td>
        <td class="num"><?= e(rupiah($total)) ?></td>
    </tr>
    </tfoot>
</table>

<div class="doc-notes">
    <?php if (($po['note'] ?? '') !== ''): ?>
        <div class="mt-2"><strong>Catatan:</strong> <?= e((string) $po['note']) ?></div>
    <?php endif; ?>
    <div class="mt-2">
        Status persetujuan, penerimaan, dan pembayaran dikelola melalui modul pembelian (/pembelian).
        Dokumen ini mencetak data yang tersimpan saat ini; perubahan setelah cetak tidak otomatis
        memperbarui dokumen ini.
    </div>
    <?php if (($identity['note'] ?? '') !== ''): ?>
        <div class="mt-1"><?= e((string) $identity['note']) ?></div>
    <?php endif; ?>
</div>

<div class="doc-signatures">
    <div class="box">
        <div>Dibuat oleh</div>
        <div class="line"><?= e((string) ($po['created_by_name'] ?? '________________')) ?></div>
    </div>
    <div class="box">
        <div>Disetujui oleh</div>
        <div class="line"><?= e((string) ($po['approved_by_name'] ?? '________________')) ?></div>
    </div>
    <div class="box">
        <div>Konfirmasi supplier</div>
        <div class="line">Nama &amp; tanggal</div>
    </div>
</div>
