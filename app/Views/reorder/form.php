<?php
/**
 * Form buat/ubah permintaan reorder (FR-15) — hanya Partman.
 *
 * @var array<string,mixed> $old
 * @var array<int,array<string,mixed>> $spareparts
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<string,mixed>|null $reorder
 */
use App\Models\ReorderRequest;
use App\Services\ReorderRequestService;

$isEdit = $reorder !== null;

$value = static function (string $field, string $default = '') use ($old, $reorder, $isEdit): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    if ($isEdit) {
        return match ($field) {
            'requested_qty'  => rtrim(rtrim(number_format((float) $reorder['requested_qty'], 2, ',', '.'), '0'), ','),
            'change_reason'  => (string) ($reorder['change_reason'] ?? ''),
            'sparepart_id'   => (string) $reorder['sparepart_id'],
            'supplier_id'    => (string) ($reorder['supplier_id'] ?? ''),
            default          => $default,
        };
    }
    return $default;
};

$sparepartValue = $value('sparepart_id');
$supplierValue = $value('supplier_id');

// Prefill saat datang dari panel "kebutuhan reorder" (?sparepart=ID).
if (!$isEdit && $sparepartValue === '' && isset($_GET['sparepart'])) {
    $sparepartValue = (string) (int) $_GET['sparepart'];
}

$initialSuggestion = null;
if ($isEdit) {
    $initialSuggestion = $reorder['suggested_qty'] !== null ? (float) $reorder['suggested_qty'] : null;
} elseif ($sparepartValue !== '') {
    $initialSuggestion = ReorderRequestService::suggestion((int) $sparepartValue)['suggested_qty'];
}

// Info stok awal (fallback tanpa JS) untuk sparepart yang sudah terpilih.
$initialStock = '—';
$initialMin = '—';
if ($sparepartValue !== '') {
    foreach ($spareparts as $opt) {
        if ((string) $opt['id'] === $sparepartValue) {
            $fmtQty = static fn (float|int|string $v): string =>
                rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
            $initialStock = $fmtQty($opt['stock']) . ' ' . (string) $opt['unit'];
            $initialMin = $fmtQty($opt['min_stock']) . ' ' . (string) $opt['unit'];
            break;
        }
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Permintaan Reorder' : 'Buat Permintaan Reorder' ?></h2>
        <p class="text-muted small mb-0">
            Permintaan diajukan ke Kepala Bengkel. Persetujuan <strong>tidak menambah stok</strong>.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('reorder')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($isEdit ? base_url('reorder/' . (int) $reorder['id']) : base_url('reorder')) ?>"
                      autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="sparepart_id">Sparepart <span class="text-danger">*</span></label>
                            <select class="form-select" id="sparepart_id" name="sparepart_id" required
                                    <?= $isEdit ? 'disabled' : '' ?>>
                                <option value="">— pilih sparepart —</option>
                                <?php foreach ($spareparts as $sparepart): ?>
                                    <option value="<?= (int) $sparepart['id'] ?>"
                                        <?= $sparepartValue === (string) $sparepart['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $sparepart['code'] . ' — ' . (string) $sparepart['name']) ?>
                                        (stok <?= e(rtrim(rtrim(number_format((float) $sparepart['stock'], 2, ',', '.'), '0'), ',')) ?>
                                        <?= e((string) $sparepart['unit']) ?>, min.
                                        <?= e(rtrim(rtrim(number_format((float) $sparepart['min_stock'], 2, ',', '.'), '0'), ',')) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($isEdit): ?>
                                <div class="form-text">Sparepart tidak dapat diubah — buat permintaan baru bila perlu.</div>
                                <input type="hidden" name="sparepart_id" value="<?= e($sparepartValue) ?>">
                            <?php else: ?>
                                <div class="form-text">Sparepart aktif saja yang dapat diminta reorder.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="supplier_id">Supplier <span class="text-muted small">(opsional)</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id">
                                <option value="">— pilih sendiri saat membuat PO —</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['id'] ?>"
                                        <?= $supplierValue === (string) $supplier['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $supplier['code'] . ' — ' . (string) $supplier['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pilihan awal; keputusan supplier akhir tetap di PO.</div>
                        </div>
                    </div>

                    <div class="card bg-light border-0 mt-3">
                        <div class="card-body py-3">
                            <div class="d-flex flex-wrap gap-4 small">
                                <div>Stok saat ini: <strong id="infoStock"><?= e($initialStock) ?></strong></div>
                                <div>Stok minimum: <strong id="infoMin"><?= e($initialMin) ?></strong></div>
                                <div>Saran jumlah: <strong id="infoSuggestion"><?= $initialSuggestion !== null ? e(rtrim(rtrim(number_format($initialSuggestion, 2, ',', '.'), '0'), ',')) : '—' ?></strong></div>
                                <div class="text-muted" id="infoSource"></div>
                            </div>
                            <div class="form-text mt-1">
                                Saran berasal dari prediksi AI terbaru (bila ada dan masih segar) atau aturan
                                stok-minimum. Bila stok sudah di atas minimum, tidak ada saran — permintaan dianggap manual.
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="requested_qty">Jumlah diminta <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="requested_qty" name="requested_qty" required
                                   min="0.01" step="0.01" value="<?= e($value('requested_qty')) ?>">
                            <div class="form-text">Maks. <?= number_format(ReorderRequestService::QTY_MAX, 0, ',', '.') ?>.</div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="change_reason">
                                Alasan perubahan jumlah
                                <span class="text-danger" id="reasonRequired" style="display:none;">*</span>
                                <span class="text-muted small">(wajib bila berbeda dari saran)</span>
                            </label>
                            <textarea class="form-control" id="change_reason" name="change_reason" rows="2"
                                      maxlength="<?= ReorderRequestService::REASON_MAX ?>"><?= e($value('change_reason')) ?></textarea>
                            <div class="form-text" id="reasonHint">
                                Disimpan beserta permintaan dan terlihat oleh Kepala Bengkel.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Ajukan Permintaan' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('reorder')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Aturan permintaan reorder</h3>
                <ul class="ps-3 mb-0">
                    <li>Nomor permintaan dibuat otomatis (RQ-YYYYMM-####).</li>
                    <li>Mengubah jumlah dari saran sistem <strong>wajib mencantumkan alasan</strong>.</li>
                    <li>Kepala Bengkel menyetujui/menolak; penolakan wajib beralasan.</li>
                    <li>Persetujuan <strong>tidak menambah stok</strong>.</li>
                    <li>Setelah disetujui: buat PO, lalu catat penerimaan barang.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Ambil saran jumlah dari server saat sparepart dipilih (tanpa framework).
(function () {
    var select = document.getElementById('sparepart_id');
    var input = document.getElementById('requested_qty');
    var reason = document.getElementById('change_reason');
    var reasonRequired = document.getElementById('reasonRequired');
    var infoStock = document.getElementById('infoStock');
    var infoMin = document.getElementById('infoMin');
    var infoSuggestion = document.getElementById('infoSuggestion');
    var infoSource = document.getElementById('infoSource');
    if (!select || !input) { return; }

    var currentSuggestion = <?= json_encode($initialSuggestion) ?>;
    var locked = <?= $isEdit ? 'true' : 'false' ?>;
    var base = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')) ?>;

    function fmt(value) {
        if (value === null || value === undefined) { return '—'; }
        var decimals = Math.floor(value) === value ? 0 : 2;
        return value.toFixed(decimals).replace('.', ',');
    }

    function syncReason() {
        if (!reason || !reasonRequired) { return; }
        var different = currentSuggestion !== null
            && Math.abs(parseFloat(input.value || '0') - currentSuggestion) > 0.005;
        reasonRequired.style.display = different ? '' : 'none';
        reason.required = different;
    }

    function load() {
        var id = parseInt(select.value || '0', 10);
        if (!id) {
            currentSuggestion = null;
            if (infoStock) { infoStock.textContent = '—'; }
            if (infoMin) { infoMin.textContent = '—'; }
            if (infoSuggestion) { infoSuggestion.textContent = '—'; }
            if (infoSource) { infoSource.textContent = ''; }
            syncReason();
            return;
        }
        fetch(base + '/reorder/saran?sparepart_id=' + id, { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
            .then(function (data) {
                if (infoStock) { infoStock.textContent = fmt(data.stock) + ' ' + data.unit; }
                if (infoMin) { infoMin.textContent = fmt(data.min_stock) + ' ' + data.unit; }
                currentSuggestion = data.suggested_qty;
                if (infoSuggestion) { infoSuggestion.textContent = fmt(data.suggested_qty); }
                if (infoSource) {
                    infoSource.textContent = data.source === 'ai' ? '(dari prediksi AI)'
                        : (data.source === 'aturan_minimum' ? '(aturan stok-minimum)' : '(tanpa saran)');
                }
                if (!locked && !input.value && data.suggested_qty !== null) {
                    input.value = String(data.suggested_qty).replace('.', ',');
                }
                syncReason();
            })
            .catch(function () { /* biarkan nilai lama bila jaringan gagal */ });
    }

    select.addEventListener('change', load);
    if (input) { input.addEventListener('input', syncReason); }
    if (select.value) { load(); }
    syncReason();
})();
</script>
