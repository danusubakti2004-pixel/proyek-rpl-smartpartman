<?php
/**
 * Daftar pengguna + filter + aksi (Admin).
 *
 * @var array<int,array<string,mixed>> $users
 * @var array{search:string,role:string,status:string} $filters
 * @var array<string,string> $roles
 * @var array{total:int,aktif:int,nonaktif:int,terkunci:int} $stats
 */
use App\Services\UserService;

$statusBadge = static function (array $row): array {
    return match (UserService::statusOf($row)) {
        'aktif'    => ['Aktif', 'success'],
        'nonaktif' => ['Nonaktif', 'secondary'],
        default    => ['Terkunci', 'danger'],
    };
};

$me = (int) (\App\Core\Auth::id() ?? 0);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Manajemen Pengguna</h2>
        <p class="text-muted small mb-0">Kelola akun Partman, Kepala Bengkel, dan Admin.</p>
    </div>
    <a class="btn btn-navy" href="<?= e(base_url('pengguna/tambah')) ?>">+ Tambah Pengguna</a>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Total', $stats['total'], 'secondary'],
        ['Aktif', $stats['aktif'], 'success'],
        ['Nonaktif', $stats['nonaktif'], 'secondary'],
        ['Terkunci', $stats['terkunci'], 'danger'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small"><?= e($label) ?></div>
                    <div class="fs-4 fw-semibold text-<?= e($color) ?>"><?= (int) $value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(base_url('pengguna')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="q">Cari</label>
                <input class="form-control form-control-sm" id="q" name="q"
                       value="<?= e($filters['search']) ?>" placeholder="Username / nama / email">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="role">Role</label>
                <select class="form-select form-select-sm" id="role" name="role">
                    <option value="">Semua role</option>
                    <?php foreach ($roles as $code => $name): ?>
                        <option value="<?= e($code) ?>" <?= $filters['role'] === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'terkunci' => 'Terkunci'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Username</th>
                <th>Nama</th>
                <th>Role</th>
                <th>Status</th>
                <th class="text-center">Gagal</th>
                <th>Terakhir Login</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr><td colspan="7" class="text-center text-muted small py-4">Tidak ada pengguna yang cocok dengan filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $row): ?>
                <?php [$statusLabel, $statusColor] = $statusBadge($row); ?>
                <tr>
                    <td class="fw-semibold">
                        <?= e((string) $row['username']) ?>
                        <?php if ((int) $row['id'] === $me): ?>
                            <span class="badge text-bg-light border ms-1">Anda</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $row['full_name']) ?></td>
                    <td><span class="badge text-bg-light border"><?= e((string) $row['role_name']) ?></span></td>
                    <td><span class="badge text-bg-<?= e($statusColor) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="text-center small"><?= (int) $row['failed_attempts'] ?></td>
                    <td class="small text-muted"><?= e(tanggal_id($row['last_login_at'] !== null ? (string) $row['last_login_at'] : null, true)) ?></td>
                    <td class="text-end">
                        <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pengguna/' . (int) $row['id'] . '/ubah')) ?>">Ubah</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('pengguna/' . (int) $row['id'] . '/reset-password')) ?>">Reset Password</a>

                            <?php if (UserService::statusOf($row) === 'terkunci'): ?>
                                <form method="post" action="<?= e(base_url('pengguna/' . (int) $row['id'] . '/unlock')) ?>" class="d-inline"
                                      data-confirm="Buka kunci akun <?= e((string) $row['username']) ?>?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-danger" type="submit">Buka Kunci</button>
                                </form>
                            <?php endif; ?>

                            <?php
                            $isSelf = (int) $row['id'] === $me;
                            $isActive = (int) $row['is_active'] === 1;
                            ?>
                            <form method="post" action="<?= e(base_url('pengguna/' . (int) $row['id'] . '/status')) ?>" class="d-inline"
                                  data-confirm="<?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= e((string) $row['username']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                                <button class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                        type="submit" <?= $isSelf ? 'disabled title="Tidak dapat menonaktifkan akun sendiri"' : '' ?>>
                                    <?= $isActive ? 'Nonaktifkan' : 'Aktifkan' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    Penonaktifkan akun <strong>tidak menghapus</strong> data atau riwayat: akun hanya diblokir untuk login
    dan seluruh perubahan tercatat di Audit Log.
</p>
