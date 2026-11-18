<?php
/**
 * Bukti barang masuk (Fase 5) — dokumen cetak.
 *
 * @var array<string,mixed> $row
 * @var array<int,array<string,mixed>> $items
 * @var array<string,mixed>|null $movement
 * @var array{name:string,address:string,phone:string,email:string,note:string} $identity
 */
$row = $row ?? [];
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
        <div class="doc-title">BUKTI BARANG MASUK</div>
        <div class="doc-subtitle"><?= e((string) ($row['receipt_number'] ?? '-')) ?></div>
    </div>
</div>

<table class="table table-sm table-borderless small mb-2">
    <tbody>
    <tr>
        <th style="width: 18%;">Tanggal diterima</th>
        <td style="width: 32%;"><?= e(tanggal_id((string) ($row['receipt_date'] ?? ''))) ?></td>
        <th style="width: 18%;">Jenis</th>
        <td style="width: 32%;">
            <?= (string) ($row['receipt_type'] ?? '') === 'pembelian' ? 'Pembelian' : 'Lainnya' ?>
        </td>
    </tr>
    <tr>
        <th>Supplier</th>
        <td>
            <?= e((string) ($row['supplier_name'] ?? '-')) ?>
            <?php if ((int) ($row['supplier_active'] ?? 1) !== 1): ?>
                <span class="badge text-bg-secondary">Nonaktif</span>
            <?php endif; ?>
        </td>
        <th>Invoice</th>
        <td><?= e((string) ($row['invoice_number'] ?? '-') ?: '-') ?></td>
    </tr>
    <tr>
        <th>Purchase Order</th>
        <td>
            <?php if ((int) ($row['has_po'] ?? 0) === 1): ?>
                #<?= (int) ($row['purchase_order_id'] ?? 0) ?>
            <?php else: ?>
                Tanpa PO
            <?php endif; ?>
        </td>
        <th>Dicatat oleh</th>
        <td><?= e((string) ($row['recorded_by_name'] ?? '-')) ?></td>
    </tr>
    <?php if (($row['note'] ?? '') !== ''): ?>
        <tr>
            <th>Catatan</th>
            <td colspan="3"><?= e((string) $row['note']) ?></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<table class="doc-table">
    <colgroup>
        <col style="width:4%"><col style="width:13%"><col style="width:38%">
        <col style="width:9%"><col style="width:8%"><col style="width:13%"><col style="width:15%">
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
        <tr><td colspan="7" class="text-center text-muted py-3">Tidak ada item.</td></tr>
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
    <div class="mt-2">
        Dokumen ini dicetak dari SmartPartMan. Stok sparepart hanya berubah melalui ledger penerimaan;
        bukti asli tetap tersimpan sebagai riwayat.
    </div>
    <?php if (($identity['note'] ?? '') !== ''): ?>
        <div class="mt-1"><?= e((string) $identity['note']) ?></div>
    <?php endif; ?>
</div>

<div class="doc-signatures">
    <div class="box">
        <div>Diserahkan oleh (Supplier)</div>
        <div class="line">Nama &amp; tanggal</div>
    </div>
    <div class="box">
        <div>Diterima oleh (Partman)</div>
        <div class="line"><?= e((string) ($row['recorded_by_name'] ?? '________________')) ?></div>
    </div>
</div>
