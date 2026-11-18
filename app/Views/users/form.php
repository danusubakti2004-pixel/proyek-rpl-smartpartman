<?php
/**
 * Form tambah/ubah pengguna (Admin).
 *
 * @var string $mode 'create'|'edit'
 * @var array<string,mixed>|null $user
 * @var array<string,string> $roles
 * @var array<string,mixed> $old
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? base_url('pengguna/' . (int) $user['id']) : base_url('pengguna');

/** Nilai awal: input terakhir (bila validasi gagal) → data pengguna → kosong. */
$value = static function (string $field) use ($old, $user): string {
    if (array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }
    return $user !== null ? (string) ($user[$field] ?? '') : '';
};

$selectedRole = $value('role');
if ($selectedRole === '' && $user !== null) {
    $selectedRole = (string) $user['role'];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0"><?= $isEdit ? 'Ubah Pengguna' : 'Tambah Pengguna' ?></h2>
        <p class="text-muted small mb-0">
            <?= $isEdit ? 'Perbarui data akun (password diubah lewat menu Reset Password).' : 'Buat akun baru untuk Partman, Kepala Bengkel, atau Admin.' ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengguna')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e($action) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                        <input class="form-control" id="username" name="username" required
                               minlength="4" maxlength="50" value="<?= e($value('username')) ?>"
                               placeholder="mis. partman1">
                        <div class="form-text">4&ndash;50 karakter: huruf, angka, titik, garis bawah, atau strip.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="full_name">Nama Lengkap <span class="text-danger">*</span></label>
                        <input class="form-control" id="full_name" name="full_name" required
                               minlength="3" maxlength="100" value="<?= e($value('full_name')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email <span class="text-muted small">(opsional)</span></label>
                        <input type="email" class="form-control" id="email" name="email"
                               maxlength="100" value="<?= e($value('email')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="">— pilih role —</option>
                            <?php foreach ($roles as $code => $name): ?>
                                <option value="<?= e($code) ?>" <?= $selectedRole === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$isEdit): ?>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password"
                                   required minlength="8">
                            <div class="form-text">Minimal 8 karakter. Password tidak pernah ditampilkan lagi setelah disimpan.</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password_confirmation">Ulangi Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" required minlength="8">
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary small">
                            Password tidak diubah di sini. Gunakan
                            <a href="<?= e(base_url('pengguna/' . (int) $user['id'] . '/reset-password')) ?>">Reset Password</a>.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button class="btn btn-navy" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Buat Akun' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('pengguna')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
