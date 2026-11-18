<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\AuthService;
use App\Services\UserService;

/**
 * Uji login/logout Fase 2 (PRD FR-01): setiap role, pesan aman,
 * pencatatan login_attempts + audit, dan akun nonaktif.
 */

function test_auth_login_setiap_role_berhasil(): void
{
    $cases = [
        'admin'         => 'uji_login_admin',
        'partman'       => 'uji_login_partman',
        'kepala_bengkel' => 'uji_login_kb',
    ];

    foreach ($cases as $role => $username) {
        t_logout();
        $id = t_make_user($username, $role, t_password());
        $res = AuthService::attempt($username, t_password(), '127.0.0.1');

        t_true($res['ok'], "Login role {$role} berhasil");
        t_eq($role, $res['user']['role'] ?? null, "Role pada sesi = {$role}");
        t_eq($id, $res['user']['id'] ?? null, 'ID sesi sesuai pengguna');
        t_eq($role, Auth::role(), 'Auth::role() sesuai');
        t_true(Auth::check(), 'Auth::check() true setelah login');

        $row = UserService::find($id);
        t_eq(0, (int) $row['failed_attempts'], 'Hitungan gagal dinolkan setelah login berhasil');
        t_true($row['last_login_at'] !== null, 'last_login_at diperbarui');
        t_true(t_latest_audit('LOGIN_SUCCESS', $id) !== null, 'LOGIN_SUCCESS tercatat');
        t_true(t_attempt_count($username, true) >= 1, 'Percobaan sukses tercatat di login_attempts');
    }
}

function test_auth_login_password_salah(): void
{
    t_logout();
    $id = t_make_user('uji_login_salah', 'partman', t_password());
    $res = AuthService::attempt('uji_login_salah', 'password-salah', '127.0.0.1');

    t_true(!$res['ok'], 'Login dengan password salah ditolak');
    t_eq(AuthService::REASON_CREDENTIALS, $res['reason'], 'Reason = credentials');
    t_eq('Username atau password salah.', $res['message'], 'Pesan generik (aman, tanpa detail akun)');
    t_true(!Auth::check(), 'Tidak ada sesi dibuat');

    t_eq(1, (int) UserService::find($id)['failed_attempts'], 'failed_attempts bertambah 1');
    t_true(t_latest_audit('LOGIN_FAILED', $id) !== null, 'LOGIN_FAILED tercatat');
    t_true(t_attempt_count('uji_login_salah', false) >= 1, 'Percobaan gagal tercatat di login_attempts');
}

function test_auth_login_username_tidak_terdaftar(): void
{
    t_logout();
    $res = AuthService::attempt('username_tidak_ada_xyz9', 'apa-saja', '127.0.0.1');

    t_true(!$res['ok'], 'Username tidak terdaftar ditolak');
    t_eq(AuthService::REASON_CREDENTIALS, $res['reason'], 'Pesan tidak membedakan username ada/tidak');
    t_true(!Auth::check(), 'Tidak ada sesi');
}

function test_auth_login_akun_nonaktif(): void
{
    t_logout();
    $id = t_make_user('uji_login_nonaktif', 'partman', t_password(), false);
    $res = AuthService::attempt('uji_login_nonaktif', t_password(), '127.0.0.1');

    t_true(!$res['ok'], 'Akun nonaktif tidak dapat login meski password benar');
    t_eq(AuthService::REASON_INACTIVE, $res['reason'], 'Reason = inactive');
    t_true(!Auth::check(), 'Tidak ada sesi dibuat');
    t_true(UserService::find($id) !== null, 'Akun TIDAK dihapus saat dinonaktifkan');
    t_true(t_latest_audit('LOGIN_FAILED', $id) !== null, 'Percobaan pada akun nonaktif tercatat');
}

function test_auth_logout_mengakhiri_sesi(): void
{
    t_logout();
    $id = t_make_user('uji_logout', 'admin', t_password());
    AuthService::attempt('uji_logout', t_password(), '127.0.0.1');
    t_true(Auth::check(), 'Login berhasil sebelum logout');

    AuthService::logout();
    t_true(!Auth::check(), 'Setelah logout: sesi kosong');
    t_true(t_latest_audit('LOGOUT', $id) !== null, 'LOGOUT tercatat di Audit Log');
}

function test_auth_login_ulang_menolak_jika_nanti_dinonaktifkan(): void
{
    t_logout();
    $id = t_make_user('uji_relogin', 'kepala_bengkel', t_password());
    t_true(AuthService::attempt('uji_relogin', t_password(), '127.0.0.1')['ok'], 'Login pertama berhasil');
    t_logout();

    UserService::setActive($id, false);
    $res = AuthService::attempt('uji_relogin', t_password(), '127.0.0.1');
    t_eq(AuthService::REASON_INACTIVE, $res['reason'], 'Setelah dinonaktifkan, login ditolak');

    UserService::setActive($id, true);
    t_true(AuthService::attempt('uji_relogin', t_password(), '127.0.0.1')['ok'], 'Setelah diaktifkan kembali, login berhasil');
}
