<?php
$settings = is_array($settings ?? null) ? $settings : [];
$old = is_array($old ?? null) ? $old : [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Pengaturan Integrasi WhatsApp</h1>
        <p class="text-muted small mb-0">Konfigurasi Fonnte, jadwal laporan, dan pengaturan gateway. Hanya Admin.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp/riwayat')) ?>">Riwayat Notifikasi</a>
</div>

<form method="post" action="<?= e(base_url('pengaturan/whatsapp')) ?>" class="card border-0 shadow-sm mb-3">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_enabled">Aktifkan integrasi WhatsApp</label>
        </div>
        <div class="mb-3">
            <label class="form-label">API Token Fonnte</label>
            <input class="form-control" type="password" name="api_token" placeholder="Kosongkan untuk mempertahankan token lama">
            <?php if (!empty($settings['has_token'])): ?>
                <div class="form-text">Token tersimpan: <?= e((string) $settings['token_masked']) ?></div>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label class="form-label">Nomor pengirim</label>
            <input class="form-control" type="text" name="sender_number" value="<?= e((string) ($settings['sender_number'] ?? '')) ?>" placeholder="628xxxxxxxxxx">
        </div>
        <div class="mb-3">
            <label class="form-label">Timezone</label>
            <?php
            $tz = (string) ($settings['timezone'] ?? 'Asia/Jakarta');
            if ($tz === '') {
                $tz = 'Asia/Jakarta';
            }
            $tzOptions = [
                'Asia/Jakarta' => 'Asia/Jakarta — WIB (UTC+07:00)',
                'Asia/Pontianak' => 'Asia/Pontianak — WIB (UTC+07:00)',
                'Asia/Makassar' => 'Asia/Makassar — WITA (UTC+08:00)',
                'Asia/Jayapura' => 'Asia/Jayapura — WIT (UTC+09:00)',
                'Asia/Singapore' => 'Asia/Singapore',
                'Asia/Kuala_Lumpur' => 'Asia/Kuala_Lumpur',
                'Asia/Bangkok' => 'Asia/Bangkok',
                'Asia/Tokyo' => 'Asia/Tokyo',
                'Asia/Seoul' => 'Asia/Seoul',
                'Europe/London' => 'Europe/London',
                'Europe/Paris' => 'Europe/Paris',
                'America/New_York' => 'America/New_York',
                'America/Los_Angeles' => 'America/Los_Angeles',
                'UTC' => 'UTC',
            ];
            if (!array_key_exists($tz, $tzOptions)) {
                $tz = 'Asia/Jakarta';
            }
            ?>
            <select class="form-select" name="timezone">
                <?php foreach ($tzOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $value === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Jam laporan harian</label>
            <input class="form-control" type="time" name="report_time" value="<?= e((string) ($settings['report_time'] ?? '08:00')) ?>">
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="daily_report_enabled" name="daily_report_enabled" value="1" <?= !empty($settings['daily_report_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="daily_report_enabled">Aktifkan laporan harian</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="monthly_report_enabled" name="monthly_report_enabled" value="1" <?= !empty($settings['monthly_report_enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="monthly_report_enabled">Aktifkan laporan bulanan</label>
        </div>
        <button class="btn btn-navy" type="submit">Simpan</button>
    </div>
</form>