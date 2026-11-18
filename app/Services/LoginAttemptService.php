<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Penguncian akun & riwayat percobaan login (PRD FR-01).
 *
 * Kebijakan: setelah MAX_ATTEMPTS kali gagal, akun dikunci selama
 * LOCK_MINUTES menit. Admin dapat membuka kunci lebih awal (UserService::unlock).
 * Setiap percobaan dicatat ke tabel `login_attempts` (tanpa password).
 */
final class LoginAttemptService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    /** Catat satu percobaan login (sukses/gagal) berdasarkan username & IP. */
    public static function record(string $username, string $ip, bool $success): void
    {
        Database::pdo()
            ->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)')
            ->execute([$username, $ip, $success ? 1 : 0]);
    }

    /** true bila akun masih dikunci (locked_until di masa depan). */
    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        if (!is_string($until) || $until === '') {
            return false;
        }
        $timestamp = strtotime($until);
        return $timestamp !== false && $timestamp > time();
    }

    /** Sisa menit penguncian (0 bila tidak terkunci). */
    public static function lockRemainingMinutes(array $user): int
    {
        $until = $user['locked_until'] ?? null;
        if (!is_string($until) || $until === '') {
            return 0;
        }
        $timestamp = strtotime($until);
        if ($timestamp === false) {
            return 0;
        }
        return max(0, (int) ceil(($timestamp - time()) / 60));
    }

    /** true bila pernah dikunci tetapi masa kuncinya sudah lewat. */
    public static function lockExpired(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        if (!is_string($until) || $until === '') {
            return false;
        }
        $timestamp = strtotime($until);
        return $timestamp !== false && $timestamp <= time();
    }

    /**
     * Tambah hitungan gagal; kunci akun bila mencapai batas.
     *
     * @return array{failed:int, locked_until:string|null}
     */
    public static function registerFailure(int $userId, string $username, string $ip): array
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([$userId]);

        $stmt = $pdo->prepare('SELECT failed_attempts FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $failed = (int) $stmt->fetchColumn();

        $lockedUntil = null;
        if ($failed >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
            $pdo->prepare('UPDATE users SET locked_until = ? WHERE id = ?')->execute([$lockedUntil, $userId]);
        }

        self::record($username, $ip, false);

        return ['failed' => $failed, 'locked_until' => $lockedUntil];
    }

    /** Nolkan hitungan gagal + buka kunci. */
    public static function clear(int $userId): void
    {
        Database::pdo()
            ->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$userId]);
    }

    /** Jumlah percobaan gagal terakhir untuk username tertentu (untuk pemantauan). */
    public static function recentFailures(string $username, int $minutes = 15): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND success = 0
                AND attempted_at >= (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->bindValue(1, $username);
        $stmt->bindValue(2, $minutes, \PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
