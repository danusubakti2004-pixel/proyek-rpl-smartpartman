<?php
declare(strict_types=1);

use App\Services\AuthService;
use App\Services\LoginAttemptService;
use App\Services\UserService;

/**
 * Uji pengelolaan pengguna (PRD §6.3, FR-01):
 * tambah/ubah/aktif-nonaktif/reset password/ubah password sendiri/buka kunci,
 * validasi, dan pencatatan audit (tanpa pernah merekam password).
 */

function test_user_create_dan_validasi(): void
{
    $id = UserService::create([
        'username'  => 'uji_buat',
        'full_name' => 'Pengguna Baru',
        'email'     => 'uji_buat@example.test',
        'role'      => 'partman',
        'password'  => t_password(),
    ]);

    t_true($id > 0, 'Akun baru berhasil dibuat (ID: ' . $id . ')');

    $row = UserService::find($id);
    t_eq('partman', $row['role'], 'Role akun sesuai');
    t_eq(1, (int) $row['is_active'], 'Akun baru langsung aktif');

    $hash = t_user_hash($id);
    t_true($hash !== t_password(), 'Password TIDAK disimpan sebagai plaintext');
    t_true(password_verify(t_password(), $hash), 'Password ter-hash dan dapat diverifikasi');

    $log = t_latest_audit('USER_CREATE', $id);
    t_true($log !== null, 'USER_CREATE tercatat di Audit Log');
    t_contains('partman', (string) $log['new_values'], 'Nilai sesudah pada audit memuat role');
    t_true(!str_contains((string) $log['new_values'], t_password()), 'Password TIDAK bocor ke Audit Log');
    t_true(!str_contains((string) $log['description'], t_password()), 'Password TIDAK bocor di deskripsi audit');

    // Validasi backend.
    t_throws(static fn () => UserService::create([
        'username' => 'uji_buat', 'full_name' => 'Duplikat', 'role' => 'partman', 'password' => t_password(),
    ]), RuntimeException::class, 'Username duplikat ditolak');

    t_throws(static fn () => UserService::create([
        'username' => 'uji_role_salah', 'full_name' => 'Role Salah', 'role' => 'superadmin', 'password' => t_password(),
    ]), RuntimeException::class, 'Role di luar whitelist ditolak');

    t_throws(static fn () => UserService::create([
        'username' => 'uji_pass_pendek', 'full_name' => 'Pass Pendek', 'role' => 'partman', 'password' => 'pendek',
    ]), RuntimeException::class, 'Password kurang dari 8 karakter ditolak');

    t_throws(static fn () => UserService::create([
        'username' => 'uji_email_dup', 'full_name' => 'Email Duplikat',
        'email' => 'uji_buat@example.test', 'role' => 'partman', 'password' => t_password(),
    ]), RuntimeException::class, 'Email duplikat ditolak');

    t_true(!UserService::usernameExists('uji_buat', $id), 'usernameExists mengabaikan ID yang dikecualikan');
    t_true(UserService::usernameExists('uji_buat'), 'usernameExists mendeteksi username terpakai');
}

function test_user_ubah_data(): void
{
    $id = UserService::create([
        'username'  => 'uji_ubah',
        'full_name' => 'Nama Awal',
        'email'     => 'uji_ubah@example.test',
        'role'      => 'partman',
        'password'  => t_password(),
    ]);

    UserService::update($id, [
        'username'  => 'uji_ubah2',
        'full_name' => 'Nama Baru',
        'email'     => null,
        'role'      => 'kepala_bengkel',
    ]);

    $row = UserService::find($id);
    t_eq('uji_ubah2', $row['username'], 'Username berhasil diubah');
    t_eq('Nama Baru', $row['full_name'], 'Nama berhasil diubah');
    t_eq('kepala_bengkel', $row['role'], 'Role berhasil diubah');
    t_eq(null, $row['email'], 'Email dapat dikosongkan');

    $log = t_latest_audit('USER_UPDATE', $id);
    t_true($log !== null, 'USER_UPDATE tercatat');
    t_contains('uji_ubah', (string) $log['old_values'], 'Nilai sebelum perubahan tercatat');
    t_contains('uji_ubah2', (string) $log['new_values'], 'Nilai sesudah perubahan tercatat');

    t_throws(static fn () => UserService::update($id, [
        'username' => 'uji_buat', 'full_name' => 'X', 'role' => 'partman',
    ]), RuntimeException::class, 'Ubah ke username yang sudah dipakai ditolak');

    t_throws(static fn () => UserService::update(987654, [
        'username' => 'tidak_ada', 'full_name' => 'X', 'role' => 'partman',
    ]), RuntimeException::class, 'Ubah pengguna yang tidak ada ditolak');
}

function test_user_nonaktif_memblokir_login_tanpa_menghapus_riwayat(): void
{
    t_logout();
    $id = UserService::create([
        'username'  => 'uji_matikan',
        'full_name' => 'Uji Matikan',
        'role'      => 'partman',
        'password'  => t_password(),
    ]);

    t_true(AuthService::attempt('uji_matikan', t_password(), '127.0.0.1')['ok'], 'Login berhasil sebelum dinonaktifkan');
    t_logout();
    $attemptsBefore = t_attempt_count('uji_matikan');
    t_true($attemptsBefore >= 1, 'Sudah ada riwayat percobaan login');

    UserService::setActive($id, false);

    $row = UserService::find($id);
    t_true($row !== null, 'Baris pengguna TIDAK dihapus (riwayat utuh)');
    t_eq(0, (int) $row['is_active'], 'is_active menjadi 0');

    $res = AuthService::attempt('uji_matikan', t_password(), '127.0.0.1');
    t_true(!$res['ok'], 'Login diblokir saat akun nonaktif');
    t_eq(AuthService::REASON_INACTIVE, $res['reason'], 'Reason = inactive');
    t_true(t_attempt_count('uji_matikan') > $attemptsBefore, 'Percobaan login tetap dicatat (riwayat tidak hilang)');
    t_true(t_latest_audit('USER_DEACTIVATE', $id) !== null, 'Penonaktifan tercatat di Audit Log');

    UserService::setActive($id, true);
    t_true(AuthService::attempt('uji_matikan', t_password(), '127.0.0.1')['ok'], 'Login kembali normal setelah diaktifkan');
    t_true(t_latest_audit('USER_ACTIVATE', $id) !== null, 'Pengaktifan kembali tercatat di Audit Log');
    t_logout();
}

function test_user_guard_admin_terakhir(): void
{
    $pdo = t_pdo();

    // Catat admin aktif lain, lalu nonaktifkan sementara agar kondisi deterministik.
    $stmt = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1"
    );
    /** @var array<int,string> $others */
    $others = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($others as $otherId) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $otherId]);
    }

    try {
        $solo = UserService::create([
            'username'  => 'uji_solo_admin',
            'full_name' => 'Admin Tunggal',
            'role'      => 'admin',
            'password'  => t_password(),
        ]);

        t_throws(static fn () => UserService::setActive($solo, false),
            RuntimeException::class, 'Admin aktif terakhir TIDAK dapat dinonaktifkan');

        t_throws(static fn () => UserService::update($solo, [
            'username' => 'uji_solo_admin', 'full_name' => 'X', 'role' => 'partman',
        ]), RuntimeException::class, 'Admin aktif terakhir TIDAK dapat diturunkan rolenya');

        t_eq(1, (int) UserService::find($solo)['is_active'], 'Admin tunggal tetap aktif setelah percobaan gagal');
    } finally {
        // Pulihkan kondisi semula agar test lain tidak terganggu.
        foreach ($others as $otherId) {
            $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([(int) $otherId]);
        }
    }

    t_true(true, 'Kondisi admin lain dipulihkan setelah pengujian');
}

function test_user_tidak_dapat_menonaktifkan_diri_sendiri(): void
{
    $id = UserService::create([
        'username'  => 'uji_diri_sendiri',
        'full_name' => 'Admin Diri',
        'role'      => 'admin',
        'password'  => t_password(),
    ]);

    t_throws(static fn () => UserService::setActive($id, false, $id),
        RuntimeException::class, 'Admin tidak dapat menonaktifkan akunnya sendiri');

    t_true((int) UserService::find($id)['is_active'] === 1, 'Akun tetap aktif');
}

function test_user_reset_password_dan_buka_kunci(): void
{
    t_logout();
    $id = UserService::create([
        'username'  => 'uji_reset',
        'full_name' => 'Uji Reset',
        'role'      => 'partman',
        'password'  => t_password(),
    ]);

    for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
        AuthService::attempt('uji_reset', 'salah', '10.0.1.1');
    }
    t_true(LoginAttemptService::isLocked(UserService::find($id)), 'Akun terkunci sebelum reset');

    $newPassword = 'PasswordBaru#2026';
    UserService::resetPassword($id, $newPassword);

    $row = UserService::find($id);
    t_true(password_verify($newPassword, t_user_hash($id)), 'Password baru berhasil diverifikasi');
    t_true(!password_verify(t_password(), t_user_hash($id)), 'Password lama tidak berlaku lagi');
    t_true(!LoginAttemptService::isLocked($row), 'Reset password sekaligus membuka kunci');
    t_eq(0, (int) $row['failed_attempts'], 'Hitungan gagal dinolkan');

    $log = t_latest_audit('USER_PASSWORD_RESET', $id);
    t_true($log !== null, 'USER_PASSWORD_RESET tercatat');
    t_true(!str_contains(json_encode($log), $newPassword), 'Password baru TIDAK bocor ke Audit Log');

    t_throws(static fn () => UserService::resetPassword($id, 'pendek'),
        RuntimeException::class, 'Reset dengan password terlalu pendek ditolak');

    t_logout();
    t_true(AuthService::attempt('uji_reset', $newPassword, '10.0.1.1')['ok'], 'Login dengan password hasil reset berhasil');
    t_logout();
}

function test_user_ubah_password_sendiri(): void
{
    t_logout();
    $id = UserService::create([
        'username'  => 'uji_ganti_pass',
        'full_name' => 'Admin Ganti Pass',
        'role'      => 'admin',
        'password'  => t_password(),
    ]);

    t_throws(static fn () => UserService::changeOwnPassword($id, 'password-lama-salah', 'Baru#2026xx'),
        RuntimeException::class, 'Password saat ini yang salah ditolak');

    $newPassword = 'Baru#2026xx';
    UserService::changeOwnPassword($id, t_password(), $newPassword);
    t_true(password_verify($newPassword, t_user_hash($id)), 'Password baru tersimpan');

    t_throws(static fn () => UserService::changeOwnPassword($id, $newPassword, $newPassword),
        RuntimeException::class, 'Password baru yang sama dengan lama ditolak');

    $log = t_latest_audit('PASSWORD_CHANGE_SELF', $id);
    t_true($log !== null, 'PASSWORD_CHANGE_SELF tercatat');
    t_true(!str_contains(json_encode($log), $newPassword), 'Password tidak bocor ke Audit Log');
}

function test_user_buka_kunci_dan_audit(): void
{
    t_logout();
    $id = UserService::create([
        'username'  => 'uji_buka_kunci',
        'full_name' => 'Uji Buka Kunci',
        'role'      => 'kepala_bengkel',
        'password'  => t_password(),
    ]);

    for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
        AuthService::attempt('uji_buka_kunci', 'salah', '10.0.2.1');
    }

    UserService::unlock($id);
    $log = t_latest_audit('USER_UNLOCK', $id);
    t_true($log !== null, 'USER_UNLOCK tercatat di Audit Log');
    t_contains('buka kunci', (string) $log['description'], 'Deskripsi unlock jelas (bahasa Indonesia)');
    t_eq(0, (int) UserService::find($id)['failed_attempts'], 'Hitungan gagal dinolkan setelah unlock');

    t_throws(static fn () => UserService::unlock(987654),
        RuntimeException::class, 'Unlock pengguna yang tidak ada ditolak');
}

function test_user_daftar_statistik_dan_filter(): void
{
    $all = UserService::all();
    t_true($all !== [], 'Daftar pengguna tidak kosong');

    $admin = UserService::all(null, 'admin');
    foreach ($admin as $row) {
        t_eq('admin', $row['role'], 'Filter role admin hanya mengembalikan admin');
    }

    $nonaktif = UserService::all(null, null, 'nonaktif');
    foreach ($nonaktif as $row) {
        t_eq(0, (int) $row['is_active'], 'Filter status nonaktif konsisten');
    }

    $stats = UserService::countByStatus();
    t_eq($stats['total'], count($all), 'Statistik total = jumlah baris daftar');
    t_eq(
        $stats['total'],
        $stats['aktif'] + $stats['nonaktif'] + $stats['terkunci'],
        'Total = aktif + nonaktif + terkunci'
    );
}
