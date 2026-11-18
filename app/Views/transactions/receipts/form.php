<?php
/**
 * Form catat barang masuk (FR-06) — hanya Partman.
 *
 * @var array<string,mixed> $old
 * @var array<int,array<string,mixed>> $spareparts
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $openPos PO terbuka + sisa item (Fase 6)
 */
use App\Services\AttachmentService;
use App\Services\GoodsReceiptService;

$value = static function (string $field, string $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) ($old[$field] ?? '') : $default;
};

$dateValue = $value('receipt_date', date('Y-m-d'));
$typeValue = $value('receipt_type', 'pembelian');
$sparepartValue = $value('sparepart_id');
$supplierValue = $value('supplier_id');
$poValue = $value('purchase_order_id');
// PO terbuka (opsional — dikirim controller Fase 6; kosong bila tak tersedia).
$openPos = $openPos ?? [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Catat Barang Masuk</h2>
        <p class="text-muted small mb-0">
            Nomor dokumen dibuat otomatis. Stok bertambah setelah transaksi berhasil disimpan.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/masuk')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($formAction ?? base_url('transaksi/masuk')) ?>"
                      enctype="multipart/form-data" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="receipt_date">Tanggal diterima <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="receipt_date" name="receipt_date" required
                                   value="<?= e($dateValue) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="receipt_type">Jenis penerimaan <span class="text-danger">*</span></label>
                            <select class="form-select" id="receipt_type" name="receipt_type" required>
                                <option value="pembelian" <?= $typeValue === 'pembelian' ? 'selected' : '' ?>>Pembelian (perbarui tanggal beli)</option>
                                <option value="lainnya" <?= $typeValue === 'lainnya' ? 'selected' : '' ?>>Lainnya (tanpa perbarui tanggal beli)</option>
                            </select>
                            <div class="form-text">Hanya <strong>pembelian</strong> yang memperbarui tanggal pembelian terakhir.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="supplier_id">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">— pilih supplier —</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['id'] ?>"
                                        <?= $supplierValue === (string) $supplier['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $supplier['code'] . ' — ' . (string) $supplier['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hanya supplier aktif yang muncul di sini.</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="sparepart_id">Sparepart <span class="text-danger">*</span></label>
                            <select class="form-select" id="sparepart_id" name="sparepart_id" required>
                                <option value="">— pilih sparepart —</option>
                                <?php foreach ($spareparts as $sparepart): ?>
                                    <option value="<?= (int) $sparepart['id'] ?>"
                                        <?= $sparepartValue === (string) $sparepart['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $sparepart['code'] . ' — ' . (string) $sparepart['name']) ?>
                                        (stok <?= e(rtrim(rtrim(number_format((float) $sparepart['stock'], 2, ',', '.'), '0'), ',')) ?>
                                        <?= e((string) $sparepart['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="quantity">Jumlah diterima <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" required
                                   min="0.01" step="0.01" value="<?= e($value('quantity')) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="unit_price">Harga beli / unit</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price"
                                   min="0" step="0.01" value="<?= e($value('unit_price', '0')) ?>">
                            <div class="form-text">Rupiah, tanpa pemisah ribuan.</div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="invoice_number">Nomor invoice <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="invoice_number" name="invoice_number"
                                   maxlength="<?= GoodsReceiptService::INVOICE_MAX ?>" value="<?= e($value('invoice_number')) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="purchase_order_id">Purchase Order (PO) <span class="text-muted small">(opsional)</span></label>
                            <select class="form-select" id="purchase_order_id" name="purchase_order_id">
                                <option value="">— tanpa PO (wajib alasan) —</option>
                                <?php foreach ($openPos as $openPo): ?>
                                    <option value="<?= (int) $openPo['id'] ?>"
                                        data-supplier-id="<?= (int) $openPo['supplier_id'] ?>"
                                        data-items='<?= e(json_encode(array_map(static fn (array $item): array => [
                                            'id'        => (int) $item['sparepart_id'],
                                            'remaining' => (float) $item['remaining_qty'],
                                            'price'     => (float) $item['unit_price'],
                                        ], $openPo['items']), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                        <?= $poValue === (string) $openPo['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $openPo['po_number'] . ' — ' . (string) $openPo['supplier_name']) ?>
                                        (sisa <?= e(rtrim(rtrim(number_format((float) $openPo['total_remaining'], 2, ',', '.'), '0'), ',')) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="poHint">
                                Penerimaan dengan PO dibatasi sisa PO dan hanya untuk PO yang sudah disetujui.
                                Tanpa PO wajib beralasan.
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 mt-3" id="tanpaPoWrapper">
                        <label class="form-label" for="without_po_reason">Alasan tanpa PO <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="without_po_reason" name="without_po_reason" rows="2"
                                  maxlength="<?= GoodsReceiptService::REASON_MAX ?>"><?= e($value('without_po_reason')) ?></textarea>
                        <div class="form-text">Wajib diisi bila tidak ada PO (pembelian mendadak/kebutuhan khusus).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="note">Catatan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  maxlength="<?= GoodsReceiptService::NOTE_MAX ?>"><?= e($value('note')) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="attachment">Bukti pembelian <span class="text-muted small">(opsional — foto/PDF)</span></label>
                        <input type="file" class="form-control" id="attachment" name="attachment"
                               accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        <div class="form-text">
                            JPG, JPEG, PNG, atau PDF. Maksimal <?= e(AttachmentService::maxSizeLabel()) ?>.
                            Berkas disimpan di penyimpanan privat (di luar web root) dan hanya dapat diunduh
                            oleh pengguna yang sudah login.
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit">Simpan Penerimaan</button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('transaksi/masuk')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Aturan penerimaan</h3>
                <ul class="ps-3 mb-0">
                    <li>Nomor dokumen otomatis &amp; unik (GR-YYYYMM-####).</li>
                    <li>Stok bertambah hanya setelah penyimpanan berhasil — bila gagal, stok tidak berubah.</li>
                    <li>Supplier nonaktif tidak dapat dipakai untuk transaksi baru.</li>
                    <li>Penerimaan tanpa PO wajib mencantumkan alasan.</li>
                    <li>Penerimaan dengan PO hanya untuk PO yang sudah disetujui dan tidak boleh melebihi sisa kuantitas PO (kelebihan selalu ditolak).</li>
                    <li>PO yang belum disetujui/ditolak/dibatalkan tidak dapat dipakai untuk penerimaan.</li>
                    <li>Transaksi tersimpan tidak dihapus; koreksi lewat transaksi pembalik.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Pemilih PO (Fase 6): saring PO sesuai supplier, batasi item ke item PO
// yang masih punya sisa, tampilkan sisa, dan isi harga dari PO.
// Alasan tanpa PO tampil hanya bila PO tidak dipilih.
(function () {
    var po = document.getElementById('purchase_order_id');
    var supplier = document.getElementById('supplier_id');
    var sparepart = document.getElementById('sparepart_id');
    var price = document.getElementById('unit_price');
    var qty = document.getElementById('quantity');
    var hint = document.getElementById('poHint');
    var wrapper = document.getElementById('tanpaPoWrapper');
    var reason = document.getElementById('without_po_reason');
    if (!po) { return; }

    var fullSparepartHtml = sparepart ? sparepart.innerHTML : '';
    var defaultHint = hint ? hint.textContent : '';

    function selectedPoOption() {
        var opt = po.options[po.selectedIndex];
        return opt && opt.value ? opt : null;
    }

    function poItems() {
        var opt = selectedPoOption();
        if (!opt) { return null; }
        try { return JSON.parse(opt.getAttribute('data-items') || '[]'); }
        catch (e) { return []; }
    }

    function syncReason() {
        if (!wrapper || !reason) { return; }
        var tanpaPo = po.value.trim() === '';
        wrapper.style.display = tanpaPo ? '' : 'none';
        reason.required = tanpaPo;
    }

    function updateHint() {
        if (!hint) { return; }
        var items = poItems();
        if (items === null) {
            hint.textContent = defaultHint;
            if (qty) { qty.removeAttribute('max'); }
            return;
        }
        var item = null;
        for (var i = 0; i < items.length; i++) {
            if (String(items[i].id) === (sparepart ? sparepart.value : '')) { item = items[i]; break; }
        }
        if (item) {
            hint.textContent = 'Sisa PO item ini: ' + item.remaining
                + '. Jumlah diterima dibatasi sisa PO; harga diisi dari PO (boleh disesuaikan).';
            if (qty) { qty.setAttribute('max', String(item.remaining)); }
            if (price) { price.value = String(item.price); }
        } else {
            hint.textContent = 'Pilih item pada PO ini — penerimaan dibatasi sisa PO tiap item.';
            if (qty) { qty.removeAttribute('max'); }
        }
    }

    function syncItems() {
        if (!sparepart) { return; }
        var items = poItems();
        var keep = sparepart.value;

        if (items === null) {
            if (sparepart.getAttribute('data-filtered') === '1') {
                sparepart.innerHTML = fullSparepartHtml;
                sparepart.removeAttribute('data-filtered');
                if (keep) { sparepart.value = keep; }
            }
            updateHint();
            return;
        }

        sparepart.innerHTML = fullSparepartHtml;
        var ids = items.map(function (it) { return String(it.id); });
        Array.prototype.slice.call(sparepart.options).forEach(function (opt) {
            if (opt.value && ids.indexOf(opt.value) === -1) { opt.remove(); }
        });
        sparepart.setAttribute('data-filtered', '1');
        if (keep && ids.indexOf(keep) === -1) { sparepart.value = ''; }
        updateHint();
    }

    function filterBySupplier() {
        var sid = supplier ? supplier.value : '';
        for (var i = 0; i < po.options.length; i++) {
            var opt = po.options[i];
            if (!opt.value) { continue; }
            var match = !sid || opt.getAttribute('data-supplier-id') === sid;
            opt.disabled = !match;
            if (!match && opt.selected) { po.value = ''; }
        }
        syncReason();
        syncItems();
    }

    po.addEventListener('change', function () { syncReason(); syncItems(); });
    if (supplier) { supplier.addEventListener('change', filterBySupplier); }
    if (sparepart) { sparepart.addEventListener('change', updateHint); }
    filterBySupplier();
})();
</script>
