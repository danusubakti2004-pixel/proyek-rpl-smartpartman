<?php
/**
 * Reset password pengguna oleh Admin.
 *
 * @var array<string,mixed> $user
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Reset Password</h2>
        <p class="text-muted small mb-0">
            Akun: <strong><?= e((string) $user['username']) ?></strong>
            (<?= e((string) $user['full_name']) ?> &middot; <?= e((string) $user['role_name']) ?>)
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengguna')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="alert alert-warning small">
                    Reset password juga <strong>membuka kunci</strong> akun bila sedang terkunci.
                    Sampaikan password baru kepada pengguna secara aman.
                </div>

                <form method="post" action="<?= e(base_url('pengguna/' . (int) $user['id'] . '/reset-password')) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password Baru <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="8" autofocus>
                        <div class="form-text">Minimal 8 karakter.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password_confirmation">Ulangi Password Baru <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password_confirmation"
                               name="password_confirmation" required minlength="8">
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-danger" type="submit">Reset Password</button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('pengguna')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
