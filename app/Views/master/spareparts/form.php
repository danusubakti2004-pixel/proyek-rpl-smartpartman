<?php
/**
 * Form tambah/ubah master sparepart (FR-03) — hanya Partman.
 *
 * Catatan penting: kolom `stock` TIDAK ada di form ini. Stok tidak pernah
 * diubah langsung; pada saat TAMBAH, stok awal (opsional) dicatat lewat
 * pergerakan STOK_AWAL, dan untuk sparepart yang sudah ada gunakan
 * menu "Input Stok Awal" (sekali) atau penyesuaian opname (Fase 4).
 *
 * @var string $mode 'create'|'edit'
 * @var array<string,mixed>|null $row
 * @var array<string,mixed> $old
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $suppliers
 * @var array<int,array<string,mixed>> $locations
 */
use App\Services\SparepartService;

$isEdit = $mode === 'edit';
$action = $isEdit ? base_url('sparepart/' . (int) $row['id']) : base_url('sparepart');

$value = static function (string $field) use ($old, $row): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    return $row !== null ? (string) ($row[$field] ?? '') : '';
};

/** Angka tanpa pemisah ribuan agar mudah disunting kembali. */
$num = static function (string $field) use ($value): string {
    $raw = $value($field);
    if ($raw === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
};

$selectedCategory = $value('category_id');
$selectedSupplier = $value('supplier_id');
$selectedLocation = $value('location_id');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Sparepart' : 'Tambah Sparepart' ?></h2>
        <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Stok tidak diubah di sini — gunakan kartu stok (stok awal / penyesuaian opname).'
                : 'Kategori wajib dipilih dan harus berstatus aktif.' ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="<?= e($isEdit ? base_url('sparepart/' . (int) $row['id']) : base_url('sparepart')) ?>">&laquo; Kembali</a>
</div>

<form method="post" action="<?= e($action) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Identitas Sparepart</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="code">Kode Sparepart <span class="text-danger">*</span></label>
                            <input class="form-control" id="code" name="code" required
                                   maxlength="<?= SparepartService::CODE_MAX ?>" value="<?= e($value('code')) ?>"
                                   placeholder="mis. FLT-001">
                            <div class="form-text">Unik, otomatis huruf besar.</div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="name">Nama Sparepart <span class="text-danger">*</span></label>
                            <input class="form-control" id="name" name="name" required
                                   maxlength="<?= SparepartService::NAME_MAX ?>" value="<?= e($value('name')) ?>"
                                   placeholder="mis. Filter Oli Suzuki Satria">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="category_id">Kategori <span class="text-danger">*</span></label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">— pilih kategori —</option>
                                <?php foreach ($categories as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"
                                        <?= $selectedCategory === (string) $option['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $option['code']) ?> — <?= e((string) $option['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Kategori nonaktif tidak muncul di sini.</div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="brand">Brand <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="brand" name="brand" maxlength="100" value="<?= e($value('brand')) ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="vehicle_model">Model Kendaraan <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="vehicle_model" name="vehicle_model" maxlength="100"
                                   value="<?= e($value('vehicle_model')) ?>" placeholder="mis. Satria F150">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="unit">Satuan <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="unit" name="unit" maxlength="<?= SparepartService::UNIT_MAX ?>"
                                   value="<?= e($value('unit')) ?>" placeholder="pcs">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="location_id">Lokasi Rak/Tingkat <span class="text-muted small">(opsional)</span></label>
                            <select class="form-select" id="location_id" name="location_id">
                                <option value="">— belum ditentukan —</option>
                                <?php foreach ($locations as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"
                                        <?= $selectedLocation === (string) $option['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $option['code']) ?> — <?= e((string) $option['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="supplier_id">Supplier <span class="text-muted small">(opsional)</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id">
                                <option value="">— belum ditentukan —</option>
                                <?php foreach ($suppliers as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"
                                        <?= $selectedSupplier === (string) $option['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $option['code']) ?> — <?= e((string) $option['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Supplier nonaktif tetap boleh tersimpan sebagai asal barang.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Harga &amp; Stok Minimum</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="purchase_price">Harga Beli (Rp)</label>
                            <input class="form-control" id="purchase_price" name="purchase_price" inputmode="decimal"
                                   value="<?= e($num('purchase_price')) ?>" placeholder="45000">
                            <div class="form-text">Boleh format <code>45.000</code> atau <code>45000</code>.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="selling_price">Harga Jual (Rp)</label>
                            <input class="form-control" id="selling_price" name="selling_price" inputmode="decimal"
                                   value="<?= e($num('selling_price')) ?>" placeholder="60000">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="min_stock">Stok Minimum</label>
                            <input class="form-control" id="min_stock" name="min_stock" inputmode="decimal"
                                   value="<?= e($num('min_stock')) ?>" placeholder="5">
                            <div class="form-text">Dipakai untuk peringatan stok kritis dan prediksi (Fase 7).</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$isEdit): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Stok Awal <span class="text-muted small">(opsional)</span></div>
                    <div class="card-body">
                        <div class="alert alert-secondary small">
                            Stok awal dicatat sebagai pergerakan <strong>STOK_AWAL</strong> pada kartu stok
                            (pengguna, tanggal, jumlah, catatan) — bukan pembelian, dan tidak dapat dicatat dua kali
                            untuk sparepart yang sama. Biarkan kosong bila stok fisik belum dihitung.
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="initial_stock">Jumlah Stok Awal</label>
                                <input class="form-control" id="initial_stock" name="initial_stock" inputmode="decimal"
                                       value="<?= e($value('initial_stock')) ?>" placeholder="mis. 24">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="initial_stock_date">Tanggal Pencatatan</label>
                                <input class="form-control" id="initial_stock_date" name="initial_stock_date"
                                       value="<?= e($value('initial_stock_date')) ?>" placeholder="<?= e(date('d/m/Y')) ?>">
                                <div class="form-text">Kosongkan untuk memakai tanggal hari ini.</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="initial_stock_note">Catatan</label>
                                <input class="form-control" id="initial_stock_note" name="initial_stock_note" maxlength="255"
                                       value="<?= e($value('initial_stock_note')) ?>" placeholder="mis. hasil hitung fisik awal">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 mb-4">
                <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Sparepart' ?></button>
                <a class="btn btn-outline-secondary"
                   href="<?= e($isEdit ? base_url('sparepart/' . (int) $row['id']) : base_url('sparepart')) ?>">Batal</a>
            </div>
        </div>
    </div>
</form>
