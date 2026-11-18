<?php
/**
 * Form tambah/ubah supplier (FR-11) — hanya Partman.
 *
 * @var string $mode
 * @var array<string,mixed>|null $row
 * @var array<string,mixed> $old
 */
use App\Services\SupplierService;

$isEdit = $mode === 'edit';
$action = $isEdit ? base_url('supplier/' . (int) $row['id']) : base_url('supplier');

$value = static function (string $field) use ($old, $row): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    return $row !== null ? (string) ($row[$field] ?? '') : '';
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Supplier' : 'Tambah Supplier' ?></h2>
        <p class="text-muted small mb-0">Kode supplier harus unik dan otomatis ditulis dengan huruf besar.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('supplier')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-9 col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($action) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="code">Kode <span class="text-danger">*</span></label>
                            <input class="form-control" id="code" name="code" required
                                   maxlength="<?= SupplierService::CODE_MAX ?>" value="<?= e($value('code')) ?>"
                                   placeholder="mis. SUP-01">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="name">Nama Perusahaan <span class="text-danger">*</span></label>
                            <input class="form-control" id="name" name="name" required
                                   maxlength="<?= SupplierService::NAME_MAX ?>" value="<?= e($value('name')) ?>">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label" for="address">Alamat <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="address" name="address" rows="2" maxlength="255"><?= e($value('address')) ?></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="phone">Telepon <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="phone" name="phone" maxlength="30" value="<?= e($value('phone')) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="email">Email <span class="text-muted small">(opsional)</span></label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="100" value="<?= e($value('email')) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="contact_person">Contact Person <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="contact_person" name="contact_person" maxlength="100"
                                   value="<?= e($value('contact_person')) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="lead_time_days">Lead Time (hari) <span class="text-muted small">(opsional)</span></label>
                            <input type="number" class="form-control" id="lead_time_days" name="lead_time_days"
                                   min="0" max="3650" value="<?= e($value('lead_time_days')) ?>">
                            <div class="form-text">Dipakai untuk perhitungan prediksi pembelian (Fase 7).</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Supplier' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('supplier')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
