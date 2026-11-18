<?php
/**
 * Detail satu catatan Audit Log (Admin) — hanya baca.
 *
 * @var array<string,mixed> $log
 */
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '-';
    }
    $decoded = json_decode($json, true);
    if ($decoded === null) {
        return $json;
    }
    return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Detail Audit Log #<?= (int) $log['id'] ?></h2>
        <p class="text-muted small mb-0"><?= e(tanggal_id((string) $log['created_at'], true)) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('audit-log')) ?>">&laquo; Kembali</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong class="text-navy">Ringkasan</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><th style="width: 40%">Aktivitas</th><td><span class="badge text-bg-light border"><?= e((string) $log['activity']) ?></span></td></tr>
                    <tr><th>Pengguna</th><td><?= e((string) ($log['username'] ?? '-')) ?></td></tr>
                    <tr><th>Role</th><td><?= e((string) ($log['role'] ?? '-')) ?></td></tr>
                    <tr><th>Entitas</th><td>
                        <?= ($log['entity_type'] ?? null) !== null ? e((string) $log['entity_type']) : '-' ?>
                        <?= ($log['entity_id'] ?? null) !== null ? '#' . e((string) $log['entity_id']) : '' ?>
                    </td></tr>
                    <tr><th>Alamat IP</th><td><?= e((string) ($log['ip_address'] ?? '-')) ?></td></tr>
                    <tr><th>Deskripsi</th><td><?= e((string) $log['description']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong class="text-navy">Nilai Sebelum</strong></div>
            <div class="card-body">
                <pre class="small mb-0" style="white-space: pre-wrap;"><?= e($pretty($log['old_values'] !== null ? (string) $log['old_values'] : null)) ?></pre>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong class="text-navy">Nilai Sesudah</strong></div>
            <div class="card-body">
                <pre class="small mb-0" style="white-space: pre-wrap;"><?= e($pretty($log['new_values'] !== null ? (string) $log['new_values'] : null)) ?></pre>
            </div>
        </div>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    Catatan ini bersifat append-only: tidak tersedia fungsi ubah/hapus, baik di aplikasi maupun di database.
</p>
