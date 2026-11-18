<?php
$user = is_array($user ?? null) ? $user : [];
$verified = false;
try {
    $verified = \App\Services\WhatsAppNotificationService::isVerified((int) ($user['id'] ?? 0));
} catch (Throwable $e) {
    $verified = false;
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 text-navy mb-0">Verifikasi Nomor WhatsApp (OTP)</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp')) ?>">Kembali</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="post" action="<?= e(base_url('pengaturan/whatsapp/uji')) ?>">
            <?= csrf_field() ?>
            <label class="form-label">Nomor Uji</label>
            <input class="form-control mb-2" type="text" name="test_number" placeholder="628xxxxxxxxxx">
            <button class="btn btn-outline-secondary" type="submit">Kirim Pesan Uji</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <p class="small mb-2"><strong>Nama:</strong> <?= e((string) ($user['full_name'] ?? '')) ?></p>
        <p class="small mb-3">Status: <?= $verified ? 'Terverifikasi' : 'Belum Diverifikasi' ?></p>

        <form method="post" action="<?= e(base_url('pengaturan/whatsapp/penerima/' . (int) $user['id'] . '/kirim-otp')) ?>" class="mb-3">
            <?= csrf_field() ?>
            <label class="form-label small">Nomor tujuan OTP</label>
            <input class="form-control mb-2" type="text" name="whatsapp_number" value="<?= e((string) ($user['whatsapp_number'] ?? '')) ?>" placeholder="628xxxxxxxxxx" required>
            <button class="btn btn-outline-secondary" type="submit">Kirim Pesan Uji</button>
        </form>

        <form method="post" action="<?= e(base_url('pengaturan/whatsapp/penerima/' . (int) $user['id'] . '/verifikasi-otp')) ?>">
            <?= csrf_field() ?>
            <label class="form-label small">Kode OTP (6 digit)</label>
            <input class="form-control mb-2" type="text" name="otp" maxlength="6" pattern="\d{6}" required>
            <button class="btn btn-navy" type="submit">Verifikasi</button>
        </form>

        <p class="small text-muted mt-3 mb-0">Kode OTP berlaku 5 menit dan hanya dapat digunakan satu kali.</p>
    </div>
</div>