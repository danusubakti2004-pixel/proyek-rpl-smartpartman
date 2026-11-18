<?php
$waSettings = is_array($waSettings ?? null) ? $waSettings : [];
$chatSettings = is_array($chatSettings ?? null) ? $chatSettings : [];
$chatSummary = is_array($chatSummary ?? null) ? $chatSummary : [];
$waHistory = is_array($waHistory ?? null) ? $waHistory : [];
$chatRecent = is_array($chatRecent ?? null) ? $chatRecent : [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 text-navy mb-0">Monitoring Penggunaan API</h1>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">WhatsApp Gateway — Fonnte</div>
            <div class="card-body small">
                <div>Status integrasi: <span class="badge <?= !empty($waSettings['is_enabled']) ? 'text-bg-success' : 'text-bg-danger' ?>"><?= !empty($waSettings['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></span></div>
                <div>API Token: <?= e((string) ($waSettings['token_masked'] ?? 'Belum diatur')) ?></div>
                <div>Nomor pengirim: <?= e((string) ($waSettings['sender_number'] ?? '-')) ?></div>
                <div>Total pesan: <?= (int) $waTotal ?></div>
                <div>Berhasil: <?= (int) $waSuccess ?></div>
                <div>Gagal: <?= (int) $waFailed ?></div>
                <div>Persentase keberhasilan: <?= e((string) $waRate) ?>%</div>
                <div class="mt-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pengaturan/whatsapp')) ?>">Konfigurasi WhatsApp</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">AI Chatbot — Gemini</div>
            <div class="card-body small">
                <div>Status chatbot: <span class="badge <?= !empty($chatSettings['is_enabled']) ? 'text-bg-success' : 'text-bg-danger' ?>"><?= !empty($chatSettings['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></span></div>
                <div>Model: <?= e((string) ($chatSettings['model'] ?? '-')) ?></div>
                <div>API Key: <?= e((string) ($chatSettings['api_key_masked'] ?? 'Belum diatur')) ?></div>
                <div>Total hari ini: <?= (int) ($chatSummary['today']['total'] ?? 0) ?></div>
                <div>Berhasil: <?= (int) ($chatSummary['today']['success'] ?? 0) ?></div>
                <div>Gagal: <?= (int) ($chatSummary['today']['failed'] ?? 0) ?></div>
                <div>Prompt tokens: <?= (int) ($chatSummary['today']['prompt_tokens'] ?? 0) ?></div>
                <div>Response tokens: <?= (int) ($chatSummary['today']['response_tokens'] ?? 0) ?></div>
                <div class="mt-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan AI Chatbot</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold text-navy">Riwayat Penggunaan WhatsApp</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr><th>Waktu</th><th>Jenis</th><th>Penerima</th><th>Status</th><th>Pesan</th></tr>
                </thead>
                <tbody>
                <?php if (empty($waHistory)): ?>
                    <tr><td colspan="5" class="text-center text-muted small py-3">Data belum tersedia.</td></tr>
                <?php endif; ?>
                <?php foreach ($waHistory as $row): ?>
                    <tr>
                        <td class="small"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['notification_type'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['recipient_number'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['status'] ?? '')) ?></td>
                        <td class="small"><?= e(mb_substr((string) ($row['message'] ?? ''), 0, 60)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold text-navy">Riwayat Penggunaan Chatbot</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr><th>Waktu</th><th>Role</th><th>Model</th><th>Status</th><th>Tokens</th></tr>
                </thead>
                <tbody>
                <?php if (empty($chatRecent)): ?>
                    <tr><td colspan="5" class="text-center text-muted small py-3">Data belum tersedia.</td></tr>
                <?php endif; ?>
                <?php foreach ($chatRecent as $row): ?>
                    <tr>
                        <td class="small"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['role'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['model'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['status'] ?? '')) ?></td>
                        <td class="small"><?= (int) ($row['prompt_tokens'] ?? 0) ?>/<?= (int) ($row['response_tokens'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>