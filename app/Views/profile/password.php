<?php
/**
 * Ganti password sendiri — hanya Admin (PRD §6.4).
 *
 * @var array<string,mixed>|null $user
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Ganti Password Saya</h2>
        <p class="text-muted small mb-0">
            Akun: <strong><?= e($user['username'] ?? '') ?></strong> &middot; <?= e($user['role_name'] ?? '') ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('dashboard')) ?>">&laquo; Dashboard</a>
</div>

<div class="row">
    <div class="col-12 col-lg-7 col-xl-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(base_url('profil/password')) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Password Saat Ini <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="current_password"
                               name="current_password" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">Password Baru <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               required minlength="8">
                        <div class="form-text">Minimal 8 karakter dan berbeda dari password lama.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="new_password_confirmation">Ulangi Password Baru <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="new_password_confirmation"
                               name="new_password_confirmation" required minlength="8">
                    </div>

                    <button class="btn btn-navy" type="submit">Simpan Password Baru</button>
                </form>
            </div>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Hanya Admin yang dapat mengubah password sendiri. Partman dan Kepala Bengkel harus meminta
            reset kepada Admin.
        </p>
    </div>
</div>
