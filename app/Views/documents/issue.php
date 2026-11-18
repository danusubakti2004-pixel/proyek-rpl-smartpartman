<?php
/**
 * Bukti barang keluar (Fase 5) — dokumen cetak.
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
$usageLabel = match ((string) ($row['usage_type'] ?? '')) {
    'service'   => 'Servis',
    'penjualan' => 'Penjualan',
    default     => 'Lainnya',
};
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
        <div class="doc-title">BUKTI BARANG KELUAR</div>
        <div class="doc-subtitle"><?= e((string) ($row['issue_number'] ?? '-')) ?></div>
    </div>
</div>

<table class="table table-sm table-borderless small mb-2">
    <tbody>
    <tr>
        <th style="width: 18%;">Tanggal keluar</th>
        <td style="width: 32%;"><?= e(tanggal_id((string) ($row['issue_date'] ?? ''))) ?></td>
        <th style="width: 18%;">Jenis penggunaan</th>
        <td style="width: 32%;"><?= e($usageLabel) ?></td>
    </tr>
    <tr>
        <th>Nomor SPK</th>
        <td><?= e((string) ($row['spk_number'] ?? '') ?: '-') ?></td>
        <th>Nomor polisi</th>
        <td><?= e((string) ($row['plate_number'] ?? '') ?: '-') ?></td>
    </tr>
    <tr>
        <th>Dicatat oleh</th>
        <td><?= e((string) ($row['recorded_by_name'] ?? '-')) ?></td>
        <th>Total qty</th>
        <td><?= e($qty($row['total_quantity'] ?? 0)) ?></td>
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
        <col style="width:6%"><col style="width:20%"><col style="width:54%">
        <col style="width:10%"><col style="width:10%">
    </colgroup>
    <thead>
    <tr>
        <th>#</th>
        <th>Kode</th>
        <th>Nama Barang</th>
        <th class="num">Qty</th>
        <th>Satuan</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($items === []): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">Tidak ada item.</td></tr>
    <?php else: ?>
        <?php foreach ($items as $index => $item): ?>
            <tr>
                <td class="num"><?= $index + 1 ?></td>
                <td><?= e((string) $item['sparepart_code']) ?></td>
                <td><?= e((string) $item['sparepart_name']) ?></td>
                <td class="num"><?= e($qty($item['quantity'])) ?></td>
                <td><?= e((string) $item['unit']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div class="doc-notes">
    <div class="mt-2">
        Barang keluar mengurangi stok melalui ledger. Koreksi pengeluaran dilakukan dengan transaksi pembalik;
        riwayat asli tidak dihapus.
    </div>
    <?php if (($identity['note'] ?? '') !== ''): ?>
        <div class="mt-1"><?= e((string) $identity['note']) ?></div>
    <?php endif; ?>
</div>

<div class="doc-signatures">
    <div class="box">
        <div>Penerima</div>
        <div class="line">Nama &amp; tanggal</div>
    </div>
    <div class="box">
        <div>Petugas Partman</div>
        <div class="line"><?= e((string) ($row['recorded_by_name'] ?? '________________')) ?></div>
    </div>
</div>
