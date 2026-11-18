<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\AuthService;
use App\Services\LoginAttemptService;
use App\Services\UserService;

/**
 * Uji penguncian akun (PRD FR-01, §15.1): 5x gagal → kunci sementara,
 * Admin membuka kunci, dan kunci kedaluwarsa otomatis.
 */

function test_lockout_setelah_lima_kali_gagal(): void
{
    t_logout();
    $id = t_make_user('uji_lock_5x', 'partman', t_password());

    for ($i = 1; $i <= LoginAttemptService::MAX_ATTEMPTS; $i++) {
        $res = AuthService::attempt('uji_lock_5x', 'salah-terus', '10.0.0.1');
        t_true(!$res['ok'], "Percobaan gagal ke-{$i} ditolak");
        if ($i < LoginAttemptService::MAX_ATTEMPTS) {
            t_eq(AuthService::REASON_CREDENTIALS, $res['reason'], "Gagal ke-{$i} belum mengunci akun");
        } else {
            t_eq(AuthService::REASON_LOCKED, $res['reason'], 'Gagal ke-5 langsung mengunci akun');
        }
    }

    $user = UserService::find($id);
    t_eq(LoginAttemptService::MAX_ATTEMPTS, (int) $user['failed_attempts'], 'failed_attempts = 5');
    t_true(LoginAttemptService::isLocked($user), 'Akun terkunci sementara (locked_until di masa depan)');
    t_eq('terkunci', UserService::statusOf($user), 'Status akun = terkunci');
    t_true(t_latest_audit('ACCOUNT_LOCKED', $id) !== null, 'ACCOUNT_LOCKED tercatat di Audit Log');

    $res = AuthService::attempt('uji_lock_5x', t_password(), '10.0.0.1');
    t_eq(AuthService::REASON_LOCKED, $res['reason'], 'Password benar tetap ditolak selama terkunci');
    t_contains('terkunci', $res['message'], 'Pesan menjelaskan akun terkunci');
    t_true(!Auth::check(), 'Tidak ada sesi selama akun terkunci');
    t_true(t_latest_audit('LOGIN_BLOCKED', $id) !== null, 'LOGIN_BLOCKED tercatat');
}

function test_lockout_akun_lain_tidak_terpengaruh(): void
{
    t_logout();
    $lain = t_make_user('uji_lock_lain', 'partman', t_password());
    t_true(AuthService::attempt('uji_lock_lain', t_password(), '10.0.0.9')['ok'], 'Akun lain tetap dapat login');
    t_eq('aktif', UserService::statusOf(UserService::find($lain)), 'Status akun lain tetap aktif');
}

function test_lockout_dibuka_oleh_admin(): void
{
    t_logout();
    $id = t_make_user('uji_lock_unlock', 'kepala_bengkel', t_password());
    for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
        AuthService::attempt('uji_lock_unlock', 'salah', '10.0.0.2');
    }
    t_true(LoginAttemptService::isLocked(UserService::find($id)), 'Akun terkunci sebelum dibuka');

    UserService::unlock($id);
    $user = UserService::find($id);
    t_true(!LoginAttemptService::isLocked($user), 'Setelah dibuka Admin: tidak terkunci');
    t_eq(0, (int) $user['failed_attempts'], 'Hitungan gagal dinolkan');
    t_eq(null, $user['locked_until'], 'locked_until dibersihkan');

    $log = t_latest_audit('USER_UNLOCK', $id);
    t_true($log !== null, 'USER_UNLOCK tercatat di Audit Log');
    t_contains('uji_lock_unlock', (string) $log['description'], 'Deskripsi unlock memuat username');

    $res = AuthService::attempt('uji_lock_unlock', t_password(), '10.0.0.2');
    t_true($res['ok'], 'Login berhasil setelah dibuka Admin');
}

function test_lockout_kedaluwarsa_otomatis(): void
{
    t_logout();
    $id = t_make_user('uji_lock_expired', 'partman', t_password());
    t_pdo()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
        ->execute([LoginAttemptService::MAX_ATTEMPTS, date('Y-m-d H:i:s', time() - 120), $id]);
    t_true(!LoginAttemptService::isLocked(UserService::find($id)), 'Masa kunci sudah lewat');

    $res = AuthService::attempt('uji_lock_expired', t_password(), '10.0.0.3');
    t_true($res['ok'], 'Login boleh setelah masa kunci habis');

    $user = UserService::find($id);
    t_eq(0, (int) $user['failed_attempts'], 'Hitungan gagal direset');
    t_eq(null, $user['locked_until'], 'locked_until dibersihkan');
    t_true(t_latest_audit('ACCOUNT_UNLOCKED_EXPIRED', $id) !== null, 'Pembukaan otomatis tercatat');
}

function test_lockout_sisa_waktu_dilaporkan(): void
{
    $id = t_make_user('uji_lock_sisa', 'partman', t_password());
    t_pdo()->prepare('UPDATE users SET failed_attempts = 5, locked_until = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s', time() + 600), $id]);

    $user = UserService::find($id);
    t_true(LoginAttemptService::isLocked($user), 'Akun terkunci');
    $remaining = LoginAttemptService::lockRemainingMinutes($user);
    t_true($remaining > 0 && $remaining <= LoginAttemptService::LOCK_MINUTES, "Sisa waktu wajar ({$remaining} menit)");
}
