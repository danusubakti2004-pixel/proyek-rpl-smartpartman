<?php
/**
 * Detail sesi stock opname (FR-10).
 *
 * Stok TIDAK berubah selama status OPEN. Setelah dikonfirmasi, halaman
 * menjadi baca-saja (Kepala Bengkel dapat melihat, tidak dapat mengubah).
 *
 * @var bool $canManage
 * @var array<string,mixed> $row
 * @var array<int,array<string,mixed>> $items
 * @var array<string,mixed> $old
 */
$open = (string) $row['status'] === 'OPEN';
$editable = $open && $canManage;

$oldPhysical = is_array($old['physical'] ?? null) ? $old['physical'] : [];
$oldReason = is_array($old['reason'] ?? null) ? $old['reason'] : [];

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};

$physicalValue = static function (int $sparepartId, array $item) use ($oldPhysical): string {
    if (array_key_exists($sparepartId, $oldPhysical)) {
        return (string) ($oldPhysical[$sparepartId] ?? '');
    }
    return $item['physical_stock'] !== null ? (string) (float) $item['physical_stock'] : '';
};

$reasonValue = static function (int $sparepartId, array $item) use ($oldReason): string {
    if (array_key_exists($sparepartId, $oldReason)) {
        return (string) ($oldReason[$sparepartId] ?? '');
    }
    return (string) ($item['reason'] ?? '');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Stock Opname <?= e((string) $row['opname_number']) ?></h2>
        <p class="text-muted small mb-0">
            <?= e(tanggal_id((string) ($row['opname_date'] ?? $row['started_at']))) ?> &middot;
            <?= (int) $row['counted_count'] ?>/<?= (int) $row['item_count'] ?> item dihitung &middot;
            <?= (int) $row['diff_count'] ?> berselisih
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge text-bg-<?= $open ? 'warning' : 'success' ?>">
            <?= $open ? 'Belum dikonfirmasi — stok belum berubah' : 'Sudah dikonfirmasi — stok sudah disesuaikan' ?>
        </span>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/opname')) ?>">&laquo; Kembali</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Dimulai oleh</dt>
                    <dd class="col-7"><?= e((string) $row['started_by_name']) ?> (<?= e(tanggal_id((string) $row['started_at'])) ?>)</dd>

                    <dt class="col-5">Dikonfirmasi oleh</dt>
                    <dd class="col-7">
                        <?= $row['confirmed_by_name'] !== null
                            ? e((string) $row['confirmed_by_name']) . ' (' . e(tanggal_id((string) $row['confirmed_at'])) . ')'
                            : '<span class="text-muted">belum dikonfirmasi</span>' ?>
                    </dd>

                    <dt class="col-5">Catatan sesi</dt>
                    <dd class="col-7"><?= e((string) ($row['note'] ?? '-')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-5">
        <div class="alert alert-<?= $open ? 'warning' : 'success' ?> small mb-0 h-100" role="status">
            <?php if ($open): ?>
                <strong>Stok belum berubah.</strong> Isi jumlah fisik lalu <em>Simpan Hasil Hitung</em>.
                Stok diperbarui hanya setelah Anda menekan <strong>Konfirmasi</strong>.
                Alasan wajib diisi untuk setiap item yang berselisih.
            <?php else: ?>
                <strong>Sesi sudah dikonfirmasi.</strong> Setiap selisih sudah dicatat sebagai pergerakan
                <em>PENYESUAIAN_OPNAME</em> pada ledger. Sesi tidak dapat diubah lagi; koreksi berikutnya
                dilakukan lewat sesi opname baru.
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($editable): ?>
<form method="post" action="<?= e(base_url('transaksi/opname/' . (int) $row['id'] . '/simpan')) ?>" id="opnameForm">
    <?= csrf_field() ?>
<?php endif; ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th class="text-end">Stok Sistem<br><span class="fw-normal small text-muted">(snapshot)</span></th>
                    <th class="text-end">Stok Sekarang</th>
                    <th class="text-end" style="min-width:9rem;">Jumlah Fisik</th>
                    <th class="text-end">Selisih</th>
                    <th style="min-width:14rem;">Alasan selisih</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($items === []): ?>
                    <tr><td colspan="7" class="text-center text-muted small py-4">Tidak ada item pada sesi ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item): ?>
                    <?php
                    $sparepartId = (int) $item['sparepart_id'];
                    $system = (float) $item['system_stock'];
                    $current = (float) $item['current_stock'];
                    $difference = (float) ($item['difference'] ?? 0);
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e((string) $item['sparepart_code']) ?></td>
                        <td class="small">
                            <?= e((string) $item['sparepart_name']) ?>
                            <div class="text-muted"><?= e((string) $item['unit']) ?></div>
                        </td>
                        <td class="text-end"><?= e($qty($system)) ?></td>
                        <td class="text-end">
                            <?= e($qty($current)) ?>
                            <?php if (round($current, 2) !== round($system, 2)): ?>
                                <div class="small text-warning-emphasis">berubah sejak snapshot</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($editable): ?>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end physical-input"
                                       name="physical[<?= $sparepartId ?>]"
                                       data-system="<?= e((string) $system) ?>"
                                       data-target="diff-<?= $sparepartId ?>"
                                       value="<?= e($physicalValue($sparepartId, $item)) ?>"
                                       placeholder="—">
                            <?php else: ?>
                                <?= $item['physical_stock'] !== null ? e($qty($item['physical_stock'])) : '<span class="text-muted">belum dihitung</span>' ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold" id="diff-<?= $sparepartId ?>">
                            <?php if ($item['physical_stock'] === null): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <span class="<?= $difference === 0.0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $difference > 0 ? '+' : '' ?><?= e($qty($difference)) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($editable): ?>
                                <input class="form-control form-control-sm" name="reason[<?= $sparepartId ?>]"
                                       maxlength="500" value="<?= e($reasonValue($sparepartId, $item)) ?>"
                                       placeholder="wajib bila selisih ≠ 0">
                            <?php else: ?>
                                <span class="small"><?= e((string) ($item['reason'] ?? '-')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($editable): ?>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <button class="btn btn-navy" type="submit">Simpan Hasil Hitung</button>
            <span class="text-muted small align-self-center">
                Menyimpan hasil hitung <strong>tidak</strong> mengubah stok.
            </span>
        </div>
    <?php endif; ?>
<?php if ($editable): ?>
</form>
<?php endif; ?>

<?php if ($editable): ?>
    <form method="post" action="<?= e(base_url('transaksi/opname/' . (int) $row['id'] . '/konfirmasi')) ?>"
          class="mt-3" data-confirm="Konfirmasi hasil opname? Stok akan disesuaikan lewat pergerakan ledger dan sesi tidak dapat diubah lagi.">
        <?= csrf_field() ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="small text-muted">
                    Konfirmasi membuat pergerakan <strong>PENYESUAIAN_OPNAME</strong> untuk setiap item berselisih
                    dan memperbarui stok sesuai jumlah fisik.
                </div>
                <button class="btn btn-danger" type="submit">Konfirmasi Hasil Opname</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<script>
// Hitung selisih di sisi klien agar Partman langsung melihat dampaknya.
(function () {
    var inputs = document.querySelectorAll('.physical-input');
    if (!inputs.length) { return; }
    function fmt(n) {
        var s = (Math.round(n * 100) / 100).toString();
        return s.replace('.', ',');
    }
    function update() {
        inputs.forEach(function (input) {
            var target = document.getElementById(input.getAttribute('data-target'));
            if (!target) { return; }
            if (input.value.trim() === '') { target.innerHTML = '<span class="text-muted">—</span>'; return; }
            var system = parseFloat(input.getAttribute('data-system'));
            var physical = parseFloat(input.value);
            if (isNaN(physical)) { target.innerHTML = '<span class="text-muted">—</span>'; return; }
            var diff = Math.round((physical - system) * 100) / 100;
            var cls = diff === 0 ? 'text-success' : 'text-danger';
            var sign = diff > 0 ? '+' : '';
            target.innerHTML = '<span class="' + cls + '">' + sign + fmt(diff) + '</span>';
        });
    }
    inputs.forEach(function (input) { input.addEventListener('input', update); });
    update();
})();
</script>
