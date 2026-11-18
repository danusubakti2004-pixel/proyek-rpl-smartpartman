<?php
/**
 * Form tambah/ubah kategori (FR-12) — hanya Partman.
 *
 * @var string $mode 'create'|'edit'
 * @var array<string,mixed>|null $row
 * @var array<string,mixed> $old
 */
use App\Services\CategoryService;

$isEdit = $mode === 'edit';
$action = $isEdit ? base_url('kategori/' . (int) $row['id']) : base_url('kategori');

$value = static function (string $field) use ($old, $row): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    return $row !== null ? (string) ($row[$field] ?? '') : '';
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Kategori' : 'Tambah Kategori' ?></h2>
        <p class="text-muted small mb-0">Kode kategori harus unik dan otomatis ditulis dengan huruf besar.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('kategori')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($action) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="code">Kode Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" id="code" name="code" required
                               maxlength="<?= CategoryService::CODE_MAX ?>" value="<?= e($value('code')) ?>"
                               placeholder="mis. FLT">
                        <div class="form-text">Maksimal <?= CategoryService::CODE_MAX ?> karakter.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nama Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" id="name" name="name" required
                               maxlength="<?= CategoryService::NAME_MAX ?>" value="<?= e($value('name')) ?>"
                               placeholder="mis. Filter">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="description">Keterangan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="description" name="description" rows="2"
                                  maxlength="255"><?= e($value('description')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Kategori' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('kategori')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
