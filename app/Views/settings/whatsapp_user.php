<?php
$prefs = is_array($prefs ?? null) ? $prefs : [];
$types = is_array($types ?? null) ? $types : [];
$old = is_array($old ?? null) ? $old : [];
$settings = WhatsAppNotificationService::settings();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1"><?= e($title) ?></h1>
        <p class="text-muted small mb-0">Atur nomor WhatsApp dan preferensi notifikasi pribadi.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp/riwayat')) ?>">Riwayat Saya</a>
</div>

<?php if (empty($settings['is_enabled'])): ?>
    <div class="alert alert-warning small">Integrasi WhatsApp global belum diaktifkan oleh Admin.</div>
<?php endif; ?>

<form method="post" action="<?= e(base_url('pengaturan/whatsapp')) ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" <?= !empty($prefs['is_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_enabled">Aktifkan penerimaan notifikasi WhatsApp</label>
        </div>
        <div class="mb-3">
            <label class="form-label">Nomor WhatsApp pribadi</label>
            <input class="form-control" type="text" name="whatsapp_number" value="<?= e((string) ($prefs['whatsapp_number'] ?? '')) ?>" placeholder="628xxxxxxxxxx">
        </div>
        <div class="mb-3">
            <label class="form-label">Jenis notifikasi</label>
            <?php foreach ($types as $type => $label): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="type_<?= e($type) ?>" name="types[<?= e($type) ?>]" value="1" <?= !empty($prefs['preferences'][$type]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="type_<?= e($type) ?>"><?= e($label) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-navy" type="submit">Simpan Pengaturan</button>
    </div>
</form>

<form method="post" action="<?= e(base_url('pengaturan/whatsapp/uji')) ?>" class="card border-0 shadow-sm mt-3">
    <?= csrf_field() ?>
    <div class="card-body">
        <p class="small text-muted">Pesan uji akan dikirim ke nomor WhatsApp akun Anda.</p>
        <button class="btn btn-outline-secondary" type="submit">Kirim Pesan Uji</button>
    </div>
</form>