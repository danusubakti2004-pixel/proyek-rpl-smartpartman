<?php
/**
 * Halaman instalasi awal — membuat Admin pertama.
 * @var array{connected:bool, error:string|null} $dbStatus
 * @var bool $tablesReady
 */
?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <h2 class="h5 text-navy mb-1">Instalasi SmartPartMan</h2>
        <p class="text-muted small mb-3">Langkah 1 dari 1: buat akun Admin pertama. Halaman ini otomatis terkunci setelah berhasil.</p>

        <!-- Status lingkungan -->
        <div class="small mb-3">
            <div class="d-flex justify-content-between border-bottom py-1">
                <span>Koneksi database</span>
                <?php if ($dbStatus['connected']): ?>
                    <span class="badge text-bg-success">Terhubung</span>
                <?php else: ?>
                    <span class="badge text-bg-danger">Gagal</span>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-between border-bottom py-1">
                <span>Tabel database</span>
                <?php if ($tablesReady): ?>
                    <span class="badge text-bg-success">Siap</span>
                <?php else: ?>
                    <span class="badge text-bg-warning text-dark">Belum ada</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$dbStatus['connected']): ?>
            <div class="alert alert-danger small" role="alert">
                <strong>Tidak bisa terhubung ke database.</strong><br>
                Pastikan MySQL/XAMPP berjalan dan kredensial di file <code>.env</code> benar.
                <br><span class="text-break"><?= e($dbStatus['error'] ?? '') ?></span>
            </div>
        <?php elseif (!$tablesReady): ?>
            <div class="alert alert-warning small" role="alert">
                <strong>Tabel database belum dibuat.</strong><br>
                Jalankan migrasi lewat terminal (CMD) dari folder proyek:<br>
                <code>php database\migrate.php</code><br>
                lalu muat ulang halaman ini.
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger small"><?= e(implode('<br>', array_map('e', $errors))) ?></div>
            <?php endif; ?>

            <form action="<?= e(base_url('install')) ?>" method="post" autocomplete="off">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="username" name="username"
                           minlength="4" maxlength="50" required autofocus
                           placeholder="mis. admin">
                    <div class="form-text">4–50 karakter, huruf/angka/tanda titik/garis bawah.</div>
                </div>

                <div class="mb-3">
                    <label for="full_name" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           minlength="3" maxlength="100" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email <span class="text-muted small">(opsional)</span></label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="100">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password" name="password"
                           minlength="8" required>
                    <div class="form-text">Minimal 8 karakter.</div>
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Ulangi Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password_confirmation"
                           name="password_confirmation" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-navy w-100">Buat Admin &amp; Selesaikan Instalasi</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<p class="text-center small text-muted mt-3 mb-0">
    <a href="<?= e(base_url('install')) ?>" class="link-secondary">Muat ulang status</a>
</p>
