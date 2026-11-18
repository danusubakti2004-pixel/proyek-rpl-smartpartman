<?php
$user = is_array($user ?? null) ? $user : [];
$prefs = is_array($prefs ?? null) ? $prefs : [];
$types = is_array($types ?? null) ? $types : [];
$verification = is_array($verification ?? null) ? $verification : [];

$userId = (int) ($user['id'] ?? 0);
$currentNumber = (string) ($verification['number'] ?? ($user['whatsapp_number'] ?? ''));
$verified = !empty($verification['verified']);
$hasPending = !empty($verification['has_pending']);
$pendingNumber = (string) ($verification['pending_number'] ?? '');
$expired = !empty($verification['expired']);
$secondsRemaining = (int) ($verification['seconds_remaining'] ?? 0);
$retryAfter = (int) ($verification['retry_after'] ?? 0);
$attempts = (int) ($verification['attempts'] ?? 0);

if ($hasPending && !$expired) {
    $statusLabel = 'Menunggu Verifikasi';
    $statusClass = 'bg-warning text-dark';
} elseif ($verified) {
    $statusLabel = 'Terverifikasi';
    $statusClass = 'bg-success';
} else {
    $statusLabel = 'Belum Diverifikasi';
    $statusClass = 'bg-secondary';
}
?>
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a class="text-decoration-none" href="<?= e(base_url('pengaturan/whatsapp')) ?>">Pengaturan WhatsApp</a></li>
        <li class="breadcrumb-item"><a class="text-decoration-none" href="<?= e(base_url('pengaturan/whatsapp/notifikasi')) ?>">Manajemen Notifikasi</a></li>
        <li class="breadcrumb-item active" aria-current="page">Edit Penerima</li>
    </ol>
</nav>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Edit Penerima Notifikasi WhatsApp</h1>
        <p class="text-muted small mb-0">Simpan nomor dan preferensi, lalu verifikasi nomor melalui OTP pada halaman ini.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp/notifikasi')) ?>">Kembali ke Manajemen Notifikasi</a>
</div>

<form method="post" action="<?= e(base_url('pengaturan/whatsapp/penerima/' . $userId)) ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Data Penerima</span>
        <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="text-muted small">Nama</div>
                <div class="fw-semibold"><?= e((string) ($user['full_name'] ?? '')) ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Role</div>
                <div class="fw-semibold"><?= e((string) ($user['role_name'] ?? '')) ?></div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="whatsapp_number">Nomor WhatsApp</label>
            <input class="form-control" type="text" id="whatsapp_number" name="whatsapp_number"
                   value="<?= e($currentNumber) ?>" placeholder="628xxxxxxxxxx">
            <div class="form-text">
                Nomor tersimpan saat ini:
                <strong><?= $currentNumber !== '' ? e($currentNumber) : 'Belum ada' ?></strong>.
                Mengubah nomor akan mereset status verifikasi.
            </div>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" <?= !empty($prefs['is_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_enabled">Aktifkan penerimaan notifikasi</label>
        </div>

        <div class="mb-3">
            <label class="form-label">Jenis notifikasi</label>
            <div class="row g-2">
                <?php foreach ($types as $type => $label): ?>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="type_<?= e($type) ?>" name="types[<?= e($type) ?>]" value="1" <?= !empty($prefs['preferences'][$type]) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_<?= e($type) ?>"><?= e($label) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="btn btn-navy" type="submit">Simpan</button>
    </div>
</form>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Verifikasi Nomor WhatsApp (OTP)</span>
        <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
    </div>
    <div class="card-body">
        <?php if ($verified && !$hasPending): ?>
            <div class="alert alert-success d-flex align-items-start gap-2 mb-3">
                <span aria-hidden="true">&#10003;</span>
                <div>
                    <strong>Nomor WhatsApp berhasil diverifikasi.</strong>
                    <div class="small">Nomor <?= e($currentNumber) ?> sudah terverifikasi dan siap menerima notifikasi.</div>
                </div>
            </div>
        <?php elseif ($hasPending && !$expired): ?>
            <div class="alert alert-warning mb-3">
                <strong>Kode OTP telah dikirim.</strong>
                <div class="small">
                    Dikirim ke <strong><?= e($pendingNumber) ?></strong>.
                    Berlaku kurang lebih <strong><?= (int) ceil($secondsRemaining / 60) ?> menit</strong>
                    (<?= e((string) ($verification['expires_at'] ?? '')) ?>).
                    Masukkan kode 6 digit di bawah ini untuk menyelesaikan verifikasi.
                </div>
            </div>
        <?php elseif ($hasPending && $expired): ?>
            <div class="alert alert-danger mb-3">
                <strong>Kode OTP kedaluwarsa.</strong>
                <div class="small">Kirim ulang OTP untuk nomor <?= e($pendingNumber) ?>.</div>
            </div>
        <?php else: ?>
            <p class="small text-muted mb-3">Status nomor: <strong>Belum diverifikasi</strong>. Kirim kode OTP ke nomor tersimpan, lalu masukkan kode yang diterima melalui WhatsApp.</p>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('pengaturan/whatsapp/penerima/' . $userId . '/kirim-otp')) ?>" class="mb-3">
            <?= csrf_field() ?>
            <label class="form-label small" for="otp_number">Nomor tujuan OTP</label>
            <input class="form-control mb-2" type="text" id="otp_number" name="whatsapp_number"
                   value="<?= e($currentNumber) ?>" placeholder="628xxxxxxxxxx" required>
            <button class="btn btn-outline-secondary" type="submit" <?= $retryAfter > 0 ? 'disabled' : '' ?>>Kirim Kode OTP</button>
            <?php if ($retryAfter > 0): ?>
                <div class="form-text">Batas pengiriman ulang: tunggu <?= $retryAfter ?> detik lagi.</div>
            <?php else: ?>
                <div class="form-text">Pengiriman ulang dibatasi 60 detik. Nomor harus disimpan terlebih dahulu.</div>
            <?php endif; ?>
        </form>

        <?php if ($hasPending && !$expired): ?>
            <form method="post" action="<?= e(base_url('pengaturan/whatsapp/penerima/' . $userId . '/verifikasi-otp')) ?>">
                <?= csrf_field() ?>
                <label class="form-label small" for="otp">Kode OTP (6 digit)</label>
                <input class="form-control mb-2" type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" required>
                <button class="btn btn-navy" type="submit">Verifikasi</button>
                <div class="form-text">Percobaan: <?= $attempts ?>/5. Kode berlaku 5 menit.</div>
            </form>
        <?php endif; ?>
    </div>
</div>
