<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Alur login (PRD FR-01):
 * verifikasi kredensial → kebijakan penguncian → sesi → audit + login_attempts.
 *
 * Pesan balikan selalu disiapkan di sini agar controller tetap tipis dan
 * konsisten. Password TIDAK PERNAH direkam ke audit log.
 */
final class AuthService
{
    public const REASON_OK = 'ok';
    public const REASON_CREDENTIALS = 'credentials';
    public const REASON_INACTIVE = 'inactive';
    public const REASON_LOCKED = 'locked';

    /**
     * Hash dummy: dipakai agar waktu respons untuk username yang tidak ada
     * tetap mirip dengan username yang ada (mengurangi user enumeration).
     */
    private const DUMMY_HASH = '$2y$10$.qAaK5bTCNv.1Jky7rE7Vedf0asDI1fPfOSYLT./rNgvJSiJfHBAS';

    /**
     * @return array{ok:bool, reason:string, user:array<string,mixed>|null, locked_minutes:int, message:string}
     */
    public static function attempt(string $username, string $password, ?string $ip = null): array
    {
        $username = trim($username);
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
        $user = self::findByUsername($username);

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH); // samakan waktu respons
            LoginAttemptService::record($username, $ip, false);
            AuditService::log(
                'LOGIN_FAILED',
                'users',
                null,
                'Percobaan login gagal (username tidak terdaftar): ' . $username
            );
            return self::result(false, self::REASON_CREDENTIALS);
        }

        $id = (int) $user['id'];

        // Akun nonaktif: tolak tanpa mengungkap apa pun tentang password.
        if ((int) $user['is_active'] !== 1) {
            LoginAttemptService::record($username, $ip, false);
            AuditService::log('LOGIN_FAILED', 'users', (string) $id, 'Percobaan login pada akun NONAKTIF: ' . $username);
            return self::result(false, self::REASON_INACTIVE);
        }

        // Kunci kedaluwarsa → bersihkan agar hitungan mulai dari nol.
        if (LoginAttemptService::lockExpired($user)) {
            LoginAttemptService::clear($id);
            AuditService::log('ACCOUNT_UNLOCKED_EXPIRED', 'users', (string) $id, 'Masa kunci berakhir otomatis: ' . $username);
            $user['failed_attempts'] = 0;
            $user['locked_until'] = null;
        }

        if (LoginAttemptService::isLocked($user)) {
            $minutes = LoginAttemptService::lockRemainingMinutes($user);
            LoginAttemptService::record($username, $ip, false);
            AuditService::log(
                'LOGIN_BLOCKED',
                'users',
                (string) $id,
                sprintf('Login ditolak karena akun terkunci (%d menit tersisa): %s', $minutes, $username)
            );
            return self::result(false, self::REASON_LOCKED, null, $minutes);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $state = LoginAttemptService::registerFailure($id, $username, $ip);
            AuditService::log(
                'LOGIN_FAILED',
                'users',
                (string) $id,
                sprintf('Password salah (gagal %d dari %d): %s', $state['failed'], LoginAttemptService::MAX_ATTEMPTS, $username)
            );

            if ($state['locked_until'] !== null) {
                AuditService::log(
                    'ACCOUNT_LOCKED',
                    'users',
                    (string) $id,
                    sprintf('Akun terkunci otomatis setelah %d kali gagal: %s', LoginAttemptService::MAX_ATTEMPTS, $username)
                );
                return self::result(false, self::REASON_LOCKED, null, LoginAttemptService::LOCK_MINUTES);
            }

            return self::result(false, self::REASON_CREDENTIALS);
        }

        // ---- Sukses ----
        LoginAttemptService::record($username, $ip, true);
        LoginAttemptService::clear($id);
        Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);

        $identity = [
            'id'        => $id,
            'username'  => (string) $user['username'],
            'full_name' => (string) $user['full_name'],
            'role'      => (string) $user['role_code'],
            'role_name' => (string) $user['role_name'],
        ];
        Auth::login($identity);

        AuditService::log('LOGIN_SUCCESS', 'users', (string) $id, 'Login berhasil: ' . $username);

        return self::result(true, self::REASON_OK, $identity);
    }

    /** Logout + audit. */
    public static function logout(): void
    {
        $user = Auth::user();
        if ($user !== null) {
            AuditService::log(
                'LOGOUT',
                'users',
                isset($user['id']) ? (string) $user['id'] : null,
                'Logout: ' . ($user['username'] ?? '-')
            );
        }
        Auth::logout();
    }

    /** @return array<string,mixed>|null */
    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.username = ?
              LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Verifikasi ulang password pengguna yang sedang login.
     *
     * Dipakai untuk aksi sangat berisiko (restore database). Password tidak
     * pernah dicatat di audit/log. Mengembalikan false bila sesi bukan Admin,
     * akun nonaktif, atau password salah.
     */
    public static function verifyCurrentPassword(string $password): bool
    {
        $session = Auth::user();
        if ($session === null || (string) ($session['role'] ?? '') !== 'admin' || $password === '') {
            return false;
        }

        $user = self::findByUsername((string) ($session['username'] ?? ''));
        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            return false;
        }

        return password_verify($password, (string) ($user['password_hash'] ?? ''));
    }

    /**
     * @return array{ok:bool, reason:string, user:array<string,mixed>|null, locked_minutes:int, message:string}
     */
    private static function result(bool $ok, string $reason, ?array $user = null, int $lockedMinutes = 0): array
    {
        $roleName = $user['role_name'] ?? '';

        $message = match ($reason) {
            self::REASON_OK        => 'Selamat datang, ' . ($user['full_name'] ?? '') . ' (' . $roleName . ').',
            self::REASON_INACTIVE  => 'Akun Anda sedang nonaktif. Hubungi Admin untuk mengaktifkan kembali.',
            self::REASON_LOCKED    => sprintf(
                'Akun terkunci sementara karena %d kali gagal login. Coba lagi dalam %d menit atau minta Admin membuka kunci.',
                LoginAttemptService::MAX_ATTEMPTS,
                $lockedMinutes
            ),
            default                => 'Username atau password salah.',
        };

        return [
            'ok'             => $ok,
            'reason'         => $reason,
            'user'           => $user,
            'locked_minutes' => $lockedMinutes,
            'message'        => $message,
        ];
    }
}
