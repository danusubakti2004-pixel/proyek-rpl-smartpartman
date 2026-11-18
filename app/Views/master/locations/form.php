<?php
/**
 * Form tambah/ubah lokasi penyimpanan (FR-09) — hanya Partman.
 *
 * @var string $mode
 * @var array<string,mixed>|null $row
 * @var array<string,mixed> $old
 */
use App\Services\StorageLocationService;

$isEdit = $mode === 'edit';
$action = $isEdit ? base_url('lokasi/' . (int) $row['id']) : base_url('lokasi');

$value = static function (string $field) use ($old, $row): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    return $row !== null ? (string) ($row[$field] ?? '') : '';
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Lokasi' : 'Tambah Lokasi' ?></h2>
        <p class="text-muted small mb-0">Contoh penamaan: <code>RAK-A1-T2</code> (Gudang Utama, Rak A1, Tingkat 2).</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('lokasi')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($action) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="code">Kode Lokasi <span class="text-danger">*</span></label>
                        <input class="form-control" id="code" name="code" required
                               maxlength="<?= StorageLocationService::CODE_MAX ?>" value="<?= e($value('code')) ?>"
                               placeholder="mis. RAK-A1-T2">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nama Lokasi <span class="text-danger">*</span></label>
                        <input class="form-control" id="name" name="name" required
                               maxlength="<?= StorageLocationService::NAME_MAX ?>" value="<?= e($value('name')) ?>"
                               placeholder="mis. Gudang Utama - Rak A1 Tingkat 2">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="description">Keterangan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="description" name="description" rows="2"
                                  maxlength="255"><?= e($value('description')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Simpan Lokasi' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('lokasi')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
