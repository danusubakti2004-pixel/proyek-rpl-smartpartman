<?php
$users = is_array($users ?? null) ? $users : [];
$prefsMap = is_array($prefsMap ?? null) ? $prefsMap : [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 text-navy mb-0">Manajemen Penerima Notifikasi</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/whatsapp')) ?>">Kembali ke Gateway</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr><th>Nama</th><th>Email</th><th>Role</th><th>Nomor WhatsApp</th><th>Status Verifikasi</th><th>Status Notifikasi</th><th class="text-end">Aksi</th></tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="7" class="text-center text-muted small py-3">Belum ada pengguna.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                    <?php $p = $prefsMap[(int) $u['id']] ?? ['is_enabled' => false]; ?>
                    <tr>
                        <td class="small"><?= e((string) $u['full_name']) ?></td>
                        <td class="small"><?= e((string) ($u['email'] ?? '-')) ?></td>
                        <td class="small"><?= e((string) $u['role_name']) ?></td>
                        <td class="small"><?= e((string) ($u['whatsapp_number'] ?? '-')) ?></td>
                        <td class="small">
                            <?php
                            $verified = false;
                            try {
                                $verified = \App\Services\WhatsAppNotificationService::isVerified((int) $u['id']);
                            } catch (Throwable $e) {
                                $verified = false;
                            }
                            ?>
                            <?= $verified ? 'Terverifikasi' : 'Belum Diverifikasi' ?>
                        </td>
                        <td class="small"><?= !empty($p['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pengaturan/whatsapp/penerima/' . (int) $u['id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>