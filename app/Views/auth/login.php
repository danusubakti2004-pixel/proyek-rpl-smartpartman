<?php /** @var string $appName */ ?>
<div class="auth-form-head mb-4">
    <span class="auth-eyebrow">Selamat datang kembali</span>
    <h2 class="mb-1">Masuk ke Aplikasi</h2>
    <p class="auth-sub mb-0">Gunakan akun yang diberikan Admin untuk melanjutkan.</p>
</div>

<form action="<?= e(base_url('login')) ?>" method="post" autocomplete="off">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <div class="input-group">
            <span class="input-group-text" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
            </span>
            <input type="text" class="form-control" id="username" name="username"
                   maxlength="50" required autofocus placeholder="Username Anda">
        </div>
    </div>

    <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <span class="input-group-text" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </span>
            <input type="password" class="form-control" id="password" name="password"
                   required placeholder="••••••••">
            <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                    aria-label="Tampilkan atau sembunyikan password">Lihat</button>
        </div>
    </div>

    <button type="submit" class="btn btn-navy w-100">Login</button>
</form>

<p class="text-muted small mt-3 mb-0">
    Akses dibatasi sesuai peran: Partman, Kepala Bengkel, dan Admin.
    Akun hanya dapat dibuat/diubah oleh Admin.
</p>

<script>
(function () {
    var btn = document.getElementById('togglePassword');
    var input = document.getElementById('password');
    if (!btn || !input) { return; }
    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Sembunyi' : 'Lihat';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
})();
</script>
