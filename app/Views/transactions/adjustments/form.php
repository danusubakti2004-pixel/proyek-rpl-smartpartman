<?php
/**
 * Form penyesuaian stok (Partman).
 *
 * @var array<string,mixed> $old
 * @var array<int,array<string,mixed>> $spareparts
 */
use App\Services\StockAdjustmentService;

$value = static function (string $field, string $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) ($old[$field] ?? '') : $default;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Penyesuaian Stok</h2>
        <p class="text-muted small mb-0">Tambah atau kurangi stok sparepart dengan alasan. Stok tidak boleh negatif.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/penyesuaian')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(base_url('transaksi/penyesuaian')) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="sparepart_id">Sparepart <span class="text-danger">*</span></label>
                            <select class="form-select" id="sparepart_id" name="sparepart_id" required>
                                <option value="">— pilih sparepart —</option>
                                <?php foreach ($spareparts as $sparepart): ?>
                                    <option value="<?= (int) $sparepart['id'] ?>"
                                        data-stock="<?= e((string) $sparepart['stock']) ?>"
                                        data-unit="<?= e((string) $sparepart['unit']) ?>"
                                        <?= $value('sparepart_id') === (string) $sparepart['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $sparepart['code'] . ' — ' . (string) $sparepart['name']) ?>
                                        (stok <?= e(rtrim(rtrim(number_format((float) $sparepart['stock'], 2, ',', '.'), '0'), ',')) ?> <?= e((string) $sparepart['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="adjustment_type">Jenis penyesuaian <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_type" name="adjustment_type" required>
                                <option value="penambahan" <?= $value('adjustment_type', 'penambahan') === 'penambahan' ? 'selected' : '' ?>>Penambahan</option>
                                <option value="pengurangan" <?= $value('adjustment_type') === 'pengurangan' ? 'selected' : '' ?>>Pengurangan</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="quantity">Jumlah <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" required
                                   min="1" step="1" value="<?= e($value('quantity')) ?>">
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Stok saat ini</label>
                            <input type="text" class="form-control" id="stock_before" readonly value="">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Stok setelah penyesuaian</label>
                            <input type="text" class="form-control" id="stock_after" readonly value="">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="adjustment_date">Tanggal penyesuaian <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="adjustment_date" name="adjustment_date" required
                                   value="<?= e($value('adjustment_date', date('Y-m-d'))) ?>">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label" for="reason">Alasan penyesuaian <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reason" name="reason" rows="2"
                                  maxlength="<?= StockAdjustmentService::REASON_MAX ?>" required><?= e($value('reason')) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="note">Catatan tambahan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  maxlength="<?= StockAdjustmentService::NOTE_MAX ?>"><?= e($value('note')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit">Simpan Penyesuaian</button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('transaksi/penyesuaian')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var sp = document.getElementById('sparepart_id');
    var type = document.getElementById('adjustment_type');
    var qty = document.getElementById('quantity');
    var before = document.getElementById('stock_before');
    var after = document.getElementById('stock_after');

    function recalc() {
        var opt = sp.options[sp.selectedIndex];
        var stock = opt && opt.value ? parseFloat(opt.getAttribute('data-stock') || '0') : 0;
        var q = parseFloat(qty.value || '0');
        if (isNaN(q) || q < 0) { q = 0; }
        var signed = (type.value === 'pengurangan') ? -q : q;
        var res = stock + signed;
        before.value = stock;
        after.value = res;
    }

    if (sp) { sp.addEventListener('change', recalc); }
    if (type) { type.addEventListener('change', recalc); }
    if (qty) { qty.addEventListener('input', recalc); }
    recalc();
})();
</script>
PY