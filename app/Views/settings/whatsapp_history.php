<?php
$rows = is_array($rows ?? null) ? $rows : [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 text-navy mb-0">Riwayat Notifikasi WhatsApp</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp')) ?>">Kembali</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr><th>Waktu</th><th>Jenis</th><th>Penerima</th><th>Status</th><th>Pesan</th><th>Error</th></tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="text-center text-muted small py-3">Belum ada riwayat.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="small"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['notification_type'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['recipient_number'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($row['status'] ?? '')) ?></td>
                        <td class="small"><?= e(mb_substr((string) ($row['message'] ?? ''), 0, 80)) ?></td>
                        <td class="small text-danger"><?= e((string) ($row['error_message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
PY