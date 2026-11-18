<?php
/**
 * Form buat/ubah Purchase Order (FR-16) — hanya Partman.
 *
 * Rekomendasi supplier ditampilkan berdasar harga beli & riwayat pembelian
 * bila datanya tersedia; keputusan akhir tetap pada Partman.
 *
 * @var array<string,mixed> $old
 * @var array<string,mixed>|null $po
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $spareparts
 * @var array<string,mixed>|null $reorder
 * @var array<int,array<string,mixed>> $recommendations
 */
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;

$isEdit = $po !== null;

$value = static function (string $field, string $default = '') use ($old, $po, $isEdit): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    if ($isEdit) {
        return match ($field) {
            'supplier_id' => (string) $po['supplier_id'],
            'note'        => (string) ($po['note'] ?? ''),
            default       => $default,
        };
    }
    return $default;
};

$supplierValue = $value('supplier_id');
if (!$isEdit && $supplierValue === '' && $reorder !== null && !empty($reorder['supplier_id'])) {
    $supplierValue = (string) (int) $reorder['supplier_id'];
}

// Baris item: dari input lama (setelah gagal simpan), data PO (edit), atau
// prainformasi permintaan reorder (satu baris).
$rows = [];
if (array_key_exists('item_sparepart_id', $old) && is_array($old['item_sparepart_id'])) {
    foreach (array_values($old['item_sparepart_id']) as $index => $sparepartId) {
        if ((string) $sparepartId === '') {
            continue;
        }
        $rows[] = [
            'sparepart_id' => (string) $sparepartId,
            'quantity'     => (string) ($old['item_quantity'][$index] ?? ''),
            'unit_price'   => (string) ($old['item_unit_price'][$index] ?? ''),
        ];
    }
} elseif ($isEdit) {
    foreach ($items as $item) {
        $rows[] = [
            'sparepart_id' => (string) $item['sparepart_id'],
            'quantity'     => rtrim(rtrim(number_format((float) $item['quantity'], 2, ',', '.'), '0'), ','),
            'unit_price'   => rtrim(rtrim(number_format((float) $item['unit_price'], 2, ',', '.'), '0'), ','),
        ];
    }
} elseif ($reorder !== null) {
    $rows[] = [
        'sparepart_id' => (string) $reorder['sparepart_id'],
        'quantity'     => rtrim(rtrim(number_format((float) $reorder['requested_qty'], 2, ',', '.'), '0'), ','),
        'unit_price'   => '',
    ];
}
if ($rows === []) {
    $rows[] = ['sparepart_id' => '', 'quantity' => '', 'unit_price' => ''];
}

$recommendationHtml = static function (array $recommendations): string {
    if ($recommendations === []) {
        return '<div class="text-muted small mb-0">Belum ada riwayat pembelian untuk item ini — '
            . 'pilih supplier secara manual. Rekomendasi muncul setelah ada penerimaan pembelian.</div>';
    }
    $out = '<div class="list-group list-group-flush small">';
    foreach ($recommendations as $index => $rec) {
        $badge = $rec['is_default']
            ? ' <span class="badge text-bg-light border">supplier default sparepart</span>'
            : '';
        $times = (int) $rec['times'] > 0
            ? sprintf('pernah membeli %d× · terakhir %s', (int) $rec['times'], $rec['last_date'] !== '' ? tanggal_id((string) $rec['last_date']) : '-')
            : 'belum ada pembelian — dipilih karena supplier default sparepart';
        $price = $rec['last_price'] !== null ? ' · harga terakhir ' . rupiah((float) $rec['last_price']) : '';
        $star = $index === 0 && (int) $rec['times'] > 0
            ? ' <span class="badge text-bg-success">disarankan</span>' : '';
        $out .= '<div class="list-group-item px-0 py-2 d-flex justify-content-between gap-2">'
            . '<div><span class="fw-semibold">' . e((string) $rec['name']) . '</span>' . $badge
            . '<div class="text-muted">' . e($times . $price) . '</div></div>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary align-self-center js-pakai-supplier" '
            . 'data-supplier-id="' . (int) $rec['supplier_id'] . '">Pakai</button>'
            . $star . '</div>';
    }
    return $out . '</div>';
};

$qtyFmt = static fn (float|int|string|null $v): string =>
    $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Draft PO' : 'Buat Purchase Order' ?></h2>
        <p class="text-muted small mb-0">
            Nomor PO dibuat otomatis (PO-YYYYMM-####). Persetujuan PO <strong>tidak menambah stok</strong>.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pembelian')) ?>">&laquo; Kembali</a>
</div>

<?php if ($reorder !== null): ?>
    <div class="alert alert-info small">
        Dibuat dari permintaan reorder
        <strong><?= e((string) $reorder['request_number']) ?></strong>
        (<?= e((string) $reorder['sparepart_code']) ?> — <?= e((string) $reorder['sparepart_name']) ?>,
        jumlah diminta <?= e($qtyFmt($reorder['requested_qty'])) ?> <?= e((string) $reorder['unit']) ?>).
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12 col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post"
                      action="<?= e($isEdit ? base_url('pembelian/' . (int) $po['id']) : base_url('pembelian')) ?>"
                      autocomplete="off">
                    <?= csrf_field() ?>
                    <?php if ($reorder !== null): ?>
                        <input type="hidden" name="reorder_request_id" value="<?= (int) $reorder['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
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
                            <div class="form-text">Hanya supplier aktif. Keputusan akhir tetap pada Anda.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="note">Catatan <span class="text-muted small">(opsional)</span></label>
                            <textarea class="form-control" id="note" name="note" rows="2"
                                      maxlength="<?= PurchaseOrderService::NOTE_MAX ?>"><?= e($value('note')) ?></textarea>
                        </div>
                    </div>

                    <div class="card bg-light border-0 mt-3">
                        <div class="card-body py-3">
                            <h3 class="h6 text-navy mb-1">Rekomendasi supplier</h3>
                            <p class="text-muted small mb-2">
                                Berdasar harga beli &amp; riwayat pembelian item yang dipilih (bila datanya tersedia).
                                Pilihan Anda yang menentukan.
                            </p>
                            <div id="recommendationPanel">
                                <?= $recommendations !== [] ? $recommendationHtml($recommendations) : '' ?>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h3 class="h6 text-navy">Item PO <span class="text-danger">*</span></h3>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="itemsTable">
                            <thead class="table-light">
                            <tr>
                                <th style="width:46%;">Sparepart</th>
                                <th style="width:18%;">Jumlah</th>
                                <th style="width:24%;">Harga beli / unit</th>
                                <th style="width:12%;" class="text-end"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <tr class="js-item-row">
                                    <td>
                                        <select class="form-select form-select-sm js-item-sparepart"
                                                name="item_sparepart_id[]" required>
                                            <option value="">— pilih sparepart —</option>
                                            <?php foreach ($spareparts as $opt): ?>
                                                <option value="<?= (int) $opt['id'] ?>"
                                                    <?= $row['sparepart_id'] === (string) $opt['id'] ? 'selected' : '' ?>>
                                                    <?= e((string) $opt['code'] . ' — ' . (string) $opt['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm"
                                               name="item_quantity[]" min="0.01" step="0.01" required
                                               placeholder="qty" value="<?= e($row['quantity']) ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm"
                                               name="item_unit_price[]" min="0" step="0.01"
                                               placeholder="Rp" value="<?= e($row['unit_price']) ?>">
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger js-remove-row <?= count($rows) <= 1 ? 'd-none' : '' ?>"
                                                title="Hapus baris">&times;</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addRow">+ Tambah item</button>
                        <span class="text-muted small">Maksimal <?= PurchaseOrderService::MAX_ITEMS ?> item per PO;
                            satu sparepart hanya sekali.</span>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Draft' : 'Simpan Draft PO' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('pembelian')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Alur PO</h3>
                <ol class="ps-3 mb-0">
                    <li>Draft dibuat &amp; diajukan oleh Partman.</li>
                    <li>Kepala Bengkel menyetujui/menolak (alasan wajib saat menolak).</li>
                    <li>Admin memproses administrasi PO disetujui.</li>
                    <li>Partman mencatat penerimaan — stok bertambah di sini, boleh bertahap.</li>
                    <li>Admin memperbarui status pembayaran.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<template id="itemRowTemplate">
    <tr class="js-item-row">
        <td>
            <select class="form-select form-select-sm js-item-sparepart" name="item_sparepart_id[]" required>
                <option value="">— pilih sparepart —</option>
                <?php foreach ($spareparts as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>">
                        <?= e((string) $opt['code'] . ' — ' . (string) $opt['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="item_quantity[]"
                   min="0.01" step="0.01" required placeholder="qty">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="item_unit_price[]"
                   min="0" step="0.01" placeholder="Rp">
        </td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Hapus baris">&times;</button>
        </td>
    </tr>
</template>

<script>
// Baris item dinamis + panel rekomendasi supplier (vanilla JS, tanpa CDN).
(function () {
    var table = document.getElementById('itemsTable');
    var template = document.getElementById('itemRowTemplate');
    var addBtn = document.getElementById('addRow');
    var supplierSelect = document.getElementById('supplier_id');
    var panel = document.getElementById('recommendationPanel');
    var base = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')) ?>;
    var maxItems = <?= PurchaseOrderService::MAX_ITEMS ?>;
    if (!table || !template) { return; }
    var tbody = table.querySelector('tbody');

    function refreshRemoveButtons() {
        var rows = tbody.querySelectorAll('.js-item-row');
        rows.forEach(function (row) {
            var btn = row.querySelector('.js-remove-row');
            if (btn) { btn.classList.toggle('d-none', rows.length <= 1); }
        });
        if (addBtn) { addBtn.disabled = rows.length >= maxItems; }
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            if (tbody.querySelectorAll('.js-item-row').length >= maxItems) { return; }
            tbody.appendChild(template.content.cloneNode(true));
            refreshRemoveButtons();
        });
    }

    tbody.addEventListener('click', function (event) {
        var btn = event.target.closest('.js-remove-row');
        if (!btn) { return; }
        var row = btn.closest('.js-item-row');
        if (row && tbody.querySelectorAll('.js-item-row').length > 1) {
            row.remove();
            refreshRemoveButtons();
        }
    });

    // Rekomendasi supplier untuk sparepart yang baru dipilih di baris mana pun.
    function loadRecommendations(sparepartId) {
        if (!panel || !sparepartId) { return; }
        fetch(base + '/pembelian/rekomendasi-supplier?sparepart_id=' + sparepartId,
              { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
            .then(function (data) {
                var list = data.recommendations || [];
                if (list.length === 0) {
                    panel.innerHTML = '<div class="text-muted small mb-0">Belum ada riwayat pembelian '
                        + 'untuk item ini — pilih supplier secara manual.</div>';
                    return;
                }
                var html = '<div class="list-group list-group-flush small">';
                list.forEach(function (rec, index) {
                    var times = rec.times > 0
                        ? 'pernah membeli ' + rec.times + '× · terakhir ' + (rec.last_date || '-')
                        : 'belum ada pembelian — supplier default sparepart';
                    var price = rec.last_price !== null ? ' · harga terakhir Rp ' + Number(rec.last_price).toLocaleString('id-ID') : '';
                    var star = (index === 0 && rec.times > 0)
                        ? ' <span class="badge text-bg-success">disarankan</span>' : '';
                    html += '<div class="list-group-item px-0 py-2 d-flex justify-content-between gap-2">'
                        + '<div><span class="fw-semibold">' + rec.name + '</span>' + star
                        + '<div class="text-muted">' + times + price + '</div></div>'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary align-self-center js-pakai-supplier" '
                        + 'data-supplier-id="' + rec.supplier_id + '">Pakai</button></div>';
                });
                panel.innerHTML = html + '</div>';
            })
            .catch(function () { /* biarkan panel apa adanya bila jaringan gagal */ });
    }

    tbody.addEventListener('change', function (event) {
        var select = event.target.closest('.js-item-sparepart');
        if (select && select.value) { loadRecommendations(parseInt(select.value, 10)); }
    });

    // Tombol "Pakai" hanya mempermudah memilih — keputusan tetap pengguna.
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.js-pakai-supplier');
        if (!btn || !supplierSelect) { return; }
        supplierSelect.value = btn.getAttribute('data-supplier-id') || '';
    });

    refreshRemoveButtons();
})();
</script>
