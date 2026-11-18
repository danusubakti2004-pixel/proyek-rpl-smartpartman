<?php
/**
 * Backup & restore database — khusus Admin (Fase 9).
 *
 * @var array<int,array<string,mixed>> $backups
 * @var string $database
 * @var string $maxLabel
 */
$backups = is_array($backups ?? null) ? $backups : [];
$bytes = static function (mixed $value): string {
    $n = (int) $value;
    if ($n <= 0) {
        return '-';
    }
    return $n >= 1048576
        ? number_format($n / 1048576, 1, ',', '.') . ' MB'
        : number_format($n / 1024, 1, ',', '.') . ' KB';
};
$statusBadge = static function (string $status): string {
    return match ($status) {
        'success'  => 'text-bg-success',
        'restored' => 'text-bg-primary',
        default    => 'text-bg-danger',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Backup &amp; Restore Database</h1>
        <p class="text-muted small mb-0">
            Database: <strong><?= e($database) ?></strong>. Berkas disimpan di
            <code>storage/backups</code> (di luar web root) dan hanya dapat diunduh Admin.
        </p>
    </div>
    <span class="badge text-bg-dark align-self-center">Khusus Admin</span>
</div>

<div class="alert alert-warning border-0 shadow-sm">
    <strong>Restore mengganti seluruh data aplikasi.</strong>
    Sebelum restore, sistem otomatis membuat backup keselamatan. Restore hanya menerima
    berkas buatan aplikasi ini dengan checksum valid dan versi skema yang sama.
    Jangan menutup browser saat proses berjalan.
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="small text-muted">
            Backup terakhir: <strong><?= e((string) ($backups[0]['created_at'] ?? '-')) ?></strong>
            &middot; Batas ukuran per berkas: <strong><?= e($maxLabel) ?></strong>
            &middot; Tidak ada penghapusan otomatis.
        </div>
        <form method="post" action="<?= e(base_url('pengaturan/backup')) ?>" class="m-0"
              data-confirm="Buat backup database sekarang?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-navy">Buat Backup Sekarang</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold text-navy">Riwayat backup</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Waktu</th>
                <th>Berkas / jenis</th>
                <th>Ukuran</th>
                <th>Status</th>
                <th>Dibuat / dipulihkan oleh</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($backups === []): ?>
                <tr><td colspan="6" class="text-center text-muted small py-4">Belum ada backup. Klik tombol di atas untuk membuat backup pertama.</td></tr>
            <?php endif; ?>
            <?php foreach ($backups as $row): ?>
                <?php
                $id = (int) ($row['id'] ?? 0);
                $status = (string) ($row['status'] ?? 'failed');
                $available = !empty($row['file_available']);
                $canRestore = $available && $status !== 'failed' && (string) ($row['checksum_sha256'] ?? '') !== '';
                ?>
                <tr>
                    <td class="small text-nowrap">
                        <?= e(tanggal_id((string) ($row['created_at'] ?? ''), true)) ?>
                        <?php if (!empty($row['restored_at'])): ?>
                            <div class="text-muted">Restore: <?= e(tanggal_id((string) $row['restored_at'], true)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <span class="font-monospace"><?= e((string) ($row['filename'] ?? '-')) ?></span>
                        <div class="text-muted">
                            <?= (string) ($row['kind'] ?? 'manual') === 'pre_restore' ? 'Otomatis sebelum restore' : 'Manual' ?>
                            <?php if (($row['note'] ?? null) !== null): ?>
                                &middot; <?= e((string) $row['note']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!$available && $status !== 'failed'): ?>
                            <div class="text-danger">Berkas tidak ditemukan di storage/backups.</div>
                        <?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= e($bytes($row['filesize'] ?? 0)) ?></td>
                    <td class="small">
                        <span class="badge <?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                    </td>
                    <td class="small">
                        <?= e((string) ($row['created_by_name'] ?? '-')) ?>
                        <?php if (($row['restored_by_name'] ?? null) !== null): ?>
                            <div class="text-muted">oleh <?= e((string) $row['restored_by_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($available): ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(base_url('pengaturan/backup/' . $id . '/unduh')) ?>">Unduh</a>
                        <?php endif; ?>
                        <?php if ($canRestore): ?>
                            <button class="btn btn-sm btn-outline-danger" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#restore-<?= $id ?>"
                                    aria-expanded="false" aria-controls="restore-<?= $id ?>">Restore</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($canRestore): ?>
                    <tr class="table-light">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="restore-<?= $id ?>">
                                <form method="post" action="<?= e(base_url('pengaturan/backup/' . $id . '/restore')) ?>"
                                      class="p-3 border-top" data-confirm="Restore database dari backup ini? Seluruh data saat ini akan diganti.">
                                    <?= csrf_field() ?>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label small" for="password-<?= $id ?>">Password Admin</label>
                                            <input type="password" class="form-control form-control-sm" id="password-<?= $id ?>"
                                                   name="password" required autocomplete="current-password" maxlength="200">
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label small" for="confirmation-<?= $id ?>">Ketik PULIHKAN</label>
                                            <input type="text" class="form-control form-control-sm" id="confirmation-<?= $id ?>"
                                                   name="confirmation" required maxlength="20" autocomplete="off" placeholder="PULIHKAN">
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <button type="submit" class="btn btn-danger btn-sm">Restore Sekarang</button>
                                            <span class="small text-muted ms-2">Database akan diganti dan sesi login berakhir.</span>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    Password tidak pernah disimpan di form/log. Berkas backup berisi data aplikasi —
    simpan di tempat aman dan batasi aksesnya. Retensi backup bersifat manual;
    hapus hanya setelah ada salinan lain yang terverifikasi.
</p>
