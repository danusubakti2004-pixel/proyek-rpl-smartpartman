<?php
/**
 * Audit Log (Admin) — hanya baca (PRD FR-20).
 *
 * @var array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var array<string,string> $filters
 * @var string[] $activities
 * @var string[] $entityTypes
 */
$query = static function (array $overrides) use ($filters): string {
    $params = array_filter(
        array_merge($filters, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return $params === [] ? '' : '?' . http_build_query($params);
};
$filter = static fn (string $key): string => (string) ($filters[$key] ?? '');
$entityTypes = is_array($entityTypes ?? null) ? $entityTypes : [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Audit Log</h2>
        <p class="text-muted small mb-0">
            Catatan aktivitas penting aplikasi. Total <strong><?= (int) $result['total'] ?></strong> catatan
            (sesuai filter).
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge text-bg-light border">
            Append-only &middot; tidak dapat diubah/dihapus
        </span>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(base_url('audit-log/export') . $query([])) ?>">Ekspor CSV</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('audit-log')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-3 col-xl-2">
                <label class="form-label small mb-1" for="username">Pengguna</label>
                <input class="form-control form-control-sm" id="username" name="username"
                       value="<?= e($filter('username')) ?>" placeholder="username">
            </div>
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label small mb-1" for="activity">Aktivitas</label>
                <select class="form-select form-select-sm" id="activity" name="activity">
                    <option value="">Semua aktivitas</option>
                    <?php foreach ($activities as $activity): ?>
                        <option value="<?= e($activity) ?>" <?= $filter('activity') === $activity ? 'selected' : '' ?>>
                            <?= e($activity) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small mb-1" for="entity_type">Jenis entitas</label>
                <select class="form-select form-select-sm" id="entity_type" name="entity_type">
                    <option value="">Semua entitas</option>
                    <?php foreach ($entityTypes as $entityType): ?>
                        <option value="<?= e($entityType) ?>" <?= $filter('entity_type') === $entityType ? 'selected' : '' ?>>
                            <?= e($entityType) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 col-xl-1">
                <label class="form-label small mb-1" for="entity_id">ID entitas</label>
                <input class="form-control form-control-sm" id="entity_id" name="entity_id"
                       value="<?= e($filter('entity_id')) ?>" maxlength="64" placeholder="#">
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label small mb-1" for="keyword">Kata kunci</label>
                <input class="form-control form-control-sm" id="keyword" name="keyword"
                       value="<?= e($filter('keyword')) ?>" maxlength="100" placeholder="deskripsi/username">
            </div>
            <div class="col-6 col-md-3 col-xl-1">
                <label class="form-label small mb-1" for="date_from">Dari</label>
                <input type="date" class="form-control form-control-sm" id="date_from" name="date_from"
                       value="<?= e($filter('date_from')) ?>">
            </div>
            <div class="col-6 col-md-3 col-xl-1">
                <label class="form-label small mb-1" for="date_to">Sampai</label>
                <input type="date" class="form-control form-control-sm" id="date_to" name="date_to"
                       value="<?= e($filter('date_to')) ?>">
            </div>
            <div class="col-12 col-md-6 col-xl-2 d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm flex-grow-1" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('audit-log')) ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Waktu</th>
                <th>Pengguna</th>
                <th>Role</th>
                <th>Aktivitas</th>
                <th>Entitas</th>
                <th>Deskripsi</th>
                <th>IP</th>
                <th class="text-end">Detail</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($result['rows'] === []): ?>
                <tr><td colspan="8" class="text-center text-muted small py-4">Tidak ada catatan yang cocok dengan filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(tanggal_id((string) $row['created_at'], true)) ?></td>
                    <td class="small"><?= e((string) ($row['username'] ?? '-')) ?></td>
                    <td class="small"><?= e((string) ($row['role'] ?? '-')) ?></td>
                    <td class="small"><span class="badge text-bg-light border"><?= e((string) $row['activity']) ?></span></td>
                    <td class="small">
                        <?php if (($row['entity_type'] ?? null) !== null): ?>
                            <?= e((string) $row['entity_type']) ?>
                            <?= ($row['entity_id'] ?? null) !== null ? '#' . e((string) $row['entity_id']) : '' ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e((string) $row['description']) ?></td>
                    <td class="small text-muted"><?= e((string) ($row['ip_address'] ?? '-')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('audit-log/' . (int) $row['id'])) ?>">Lihat</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="small text-muted">Halaman <?= (int) $result['page'] ?> dari <?= (int) $result['pages'] ?></span>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary <?= $result['page'] <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(base_url('audit-log') . $query(['page' => max(1, $result['page'] - 1)])) ?>">&laquo; Sebelumnya</a>
                <a class="btn btn-outline-secondary <?= $result['page'] >= $result['pages'] ? 'disabled' : '' ?>"
                   href="<?= e(base_url('audit-log') . $query(['page' => min($result['pages'], $result['page'] + 1)])) ?>">Berikutnya &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    Audit Log hanya dapat dilihat Admin dan <strong>tidak dapat diedit atau dihapus</strong> dari aplikasi;
    database menolak UPDATE/DELETE pada tabel ini. Password, API key, dan token tidak pernah direkam.
</p>
