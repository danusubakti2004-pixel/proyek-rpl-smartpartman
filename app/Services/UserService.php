<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Manajemen akun pengguna (PRD §6.3, FR-01).
 *
 * Aturan penting:
 * - Password selalu di-hash (PASSWORD_DEFAULT); TIDAK PERNAH masuk audit log.
 * - Role dibatasi whitelist partman|kepala_bengkel|admin.
 * - Penonaktifan tidak menghapus data/riwayat (hanya is_active = 0).
 * - Harus selalu tersisa minimal satu Admin aktif.
 */
final class UserService
{
    /** @var string[] */
    public const ROLE_CODES = ['partman', 'kepala_bengkel', 'admin'];

    /** @var string[] */
    public const STATUSES = ['aktif', 'nonaktif', 'terkunci'];

    public const PASSWORD_MIN = 8;

    /**
     * Daftar pengguna + filter.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function all(?string $search = null, ?string $role = null, ?string $status = null): array
    {
        $sql = 'SELECT u.id, u.username, u.full_name, u.email, u.is_active, u.failed_attempts,
                       u.locked_until, u.last_login_at, u.created_at, r.code AS role, r.name AS role_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                 WHERE 1 = 1';
        $params = [];

        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $sql .= ' AND (u.username LIKE :q1 OR u.full_name LIKE :q2 OR u.email LIKE :q3)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if ($role !== null && in_array($role, self::ROLE_CODES, true)) {
            $sql .= ' AND r.code = :role';
            $params[':role'] = $role;
        }

        $stmt = Database::pdo()->prepare($sql . ' ORDER BY r.id ASC, u.username ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Status "terkunci" dihitung di PHP agar kebijakan kunci hanya di satu tempat.
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => self::statusOf($row) === $status
            ));
        }

        return $rows;
    }

    /** @param array<string,mixed> $user */
    public static function statusOf(array $user): string
    {
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            return 'nonaktif';
        }
        return LoginAttemptService::isLocked($user) ? 'terkunci' : 'aktif';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.full_name, u.email, u.is_active, u.failed_attempts,
                    u.locked_until, u.last_login_at, u.created_at, u.created_by,
                    r.code AS role, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,string> code => name */
    public static function roles(): array
    {
        $rows = Database::pdo()->query('SELECT code, name FROM roles ORDER BY id ASC')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['code']] = (string) $row['name'];
        }
        return $out;
    }

    /** @return array{total:int, aktif:int, nonaktif:int, terkunci:int} */
    public static function countByStatus(): array
    {
        $users = self::all();
        $stats = ['total' => count($users), 'aktif' => 0, 'nonaktif' => 0, 'terkunci' => 0];
        foreach ($users as $user) {
            $stats[self::statusOf($user)]++;
        }
        return $stats;
    }

    /**
     * Buat akun baru.
     *
     * @param array{username:string, full_name:string, email?:string|null, role:string, password:string} $data
     * @return int ID pengguna baru
     */
    public static function create(array $data, ?int $actorId = null): int
    {
        $username = trim($data['username']);
        self::guardRole($data['role']);
        self::guardPassword($data['password']);

        if (self::usernameExists($username)) {
            throw new RuntimeException('Username sudah digunakan. Pilih username lain.');
        }
        $email = self::normalizeEmail($data['email'] ?? null);
        if ($email !== null && self::emailExists($email)) {
            throw new RuntimeException('Email sudah dipakai akun lain.');
        }

        return Database::transaction(static function () use ($username, $data, $email, $actorId): int {
            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO users (username, full_name, email, password_hash, role_id, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, 1, ?)'
            )->execute([
                $username,
                trim($data['full_name']),
                $email,
                password_hash($data['password'], PASSWORD_DEFAULT),
                self::roleId($data['role']),
                $actorId,
            ]);
            $id = (int) $pdo->lastInsertId();

            AuditService::log(
                'USER_CREATE',
                'users',
                (string) $id,
                sprintf('Membuat akun baru "%s" dengan role %s', $username, $data['role']),
                null,
                [
                    'username'  => $username,
                    'full_name' => trim($data['full_name']),
                    'email'     => $email,
                    'role'      => $data['role'],
                    'is_active' => 1,
                ]
            );

            return $id;
        });
    }

    /**
     * Ubah data akun (tanpa password).
     *
     * @param array{username:string, full_name:string, email?:string|null, role:string} $data
     */
    public static function update(int $id, array $data): void
    {
        $existing = self::requireUser($id);
        $username = trim($data['username']);
        self::guardRole($data['role']);

        if (self::usernameExists($username, $id)) {
            throw new RuntimeException('Username sudah digunakan. Pilih username lain.');
        }
        $email = self::normalizeEmail($data['email'] ?? null);
        if ($email !== null && self::emailExists($email, $id)) {
            throw new RuntimeException('Email sudah dipakai akun lain.');
        }
        self::guardLastAdmin($existing, $data['role'], (int) $existing['is_active'] === 1);

        Database::transaction(static function () use ($id, $username, $data, $email, $existing): void {
            Database::pdo()->prepare(
                'UPDATE users SET username = ?, full_name = ?, email = ?, role_id = ? WHERE id = ?'
            )->execute([$username, trim($data['full_name']), $email, self::roleId($data['role']), $id]);

            AuditService::log(
                'USER_UPDATE',
                'users',
                (string) $id,
                sprintf('Mengubah akun "%s"', $username),
                [
                    'username'  => (string) $existing['username'],
                    'full_name' => (string) $existing['full_name'],
                    'email'     => $existing['email'],
                    'role'      => (string) $existing['role'],
                ],
                [
                    'username'  => $username,
                    'full_name' => trim($data['full_name']),
                    'email'     => $email,
                    'role'      => $data['role'],
                ]
            );
        });
    }

    /** Aktifkan / nonaktifkan akun (riwayat tetap tersimpan). */
    public static function setActive(int $id, bool $active, ?int $actorId = null): void
    {
        $existing = self::requireUser($id);

        if (!$active) {
            if ($actorId !== null && $actorId === $id) {
                throw new RuntimeException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }
            self::guardLastAdmin($existing, (string) $existing['role'], false);
        }

        Database::transaction(static function () use ($id, $active, $existing): void {
            Database::pdo()->prepare('UPDATE users SET is_active = ? WHERE id = ?')
                ->execute([$active ? 1 : 0, $id]);

            AuditService::log(
                $active ? 'USER_ACTIVATE' : 'USER_DEACTIVATE',
                'users',
                (string) $id,
                ($active ? 'Mengaktifkan' : 'Menonaktifkan') . ' akun "' . (string) $existing['username'] . '"',
                ['is_active' => (int) $existing['is_active']],
                ['is_active' => $active ? 1 : 0]
            );
        });
    }

    /** Reset password oleh Admin (sekaligus membuka kunci akun). */
    public static function resetPassword(int $id, string $newPassword): void
    {
        $existing = self::requireUser($id);
        self::guardPassword($newPassword);

        Database::transaction(static function () use ($id, $newPassword, $existing): void {
            Database::pdo()->prepare(
                'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
            )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);

            AuditService::log(
                'USER_PASSWORD_RESET',
                'users',
                (string) $id,
                'Reset password akun "' . (string) $existing['username'] . '" oleh Admin'
            );
        });
    }

    /** Ubah password sendiri (hanya Admin yang boleh — dibatasi middleware route). */
    public static function changeOwnPassword(int $id, string $currentPassword, string $newPassword): void
    {
        $existing = self::requireUser($id);

        $stmt = Database::pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            AuditService::log('PASSWORD_CHANGE_FAILED', 'users', (string) $id, 'Gagal ubah password sendiri: password lama tidak cocok');
            throw new RuntimeException('Password saat ini tidak cocok.');
        }
        self::guardPassword($newPassword);
        if (password_verify($newPassword, $hash)) {
            throw new RuntimeException('Password baru tidak boleh sama dengan password lama.');
        }

        Database::transaction(static function () use ($id, $newPassword, $existing): void {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);

            AuditService::log(
                'PASSWORD_CHANGE_SELF',
                'users',
                (string) $id,
                'Mengubah password sendiri'
            );
        });
    }

    /** Buka kunci akun (Admin). */
    public static function unlock(int $id): void
    {
        $existing = self::requireUser($id);

        Database::transaction(static function () use ($id, $existing): void {
            LoginAttemptService::clear($id);
            AuditService::log(
                'USER_UNLOCK',
                'users',
                (string) $id,
                'Membuka kunci akun "' . (string) $existing['username'] . '"',
                ['locked_until' => $existing['locked_until'], 'failed_attempts' => (int) $existing['failed_attempts']],
                ['locked_until' => null, 'failed_attempts' => 0]
            );
        });
    }

    public static function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE username = ?';
        $params = [$username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE email = ?';
        $params = [$email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function requireUser(int $id): array
    {
        $user = self::find($id);
        if ($user === null) {
            throw new RuntimeException('Pengguna tidak ditemukan.');
        }
        return $user;
    }

    private static function guardRole(string $role): void
    {
        if (!in_array($role, self::ROLE_CODES, true)) {
            throw new RuntimeException('Role tidak diizinkan.');
        }
    }

    private static function guardPassword(string $password): void
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new RuntimeException('Password minimal ' . self::PASSWORD_MIN . ' karakter.');
        }
    }

    /**
     * Cegah hilangnya seluruh Admin aktif.
     *
     * @param array<string,mixed> $existing
     */
    private static function guardLastAdmin(array $existing, string $newRole, bool $stillActive): void
    {
        if ((string) $existing['role'] !== 'admin' || (int) $existing['is_active'] !== 1) {
            return; // bukan admin aktif → tidak mengubah jumlah admin aktif
        }
        if ($newRole === 'admin' && $stillActive) {
            return; // tetap admin aktif
        }
        if (self::countActiveAdminsExcept((int) $existing['id']) > 0) {
            return;
        }
        throw new RuntimeException('Tidak bisa dilakukan: harus tersisa minimal satu Admin aktif.');
    }

    private static function countActiveAdminsExcept(int $exceptId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.code = 'admin' AND u.is_active = 1 AND u.id <> ?"
        );
        $stmt->execute([$exceptId]);
        return (int) $stmt->fetchColumn();
    }

    private static function roleId(string $roleCode): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM roles WHERE code = ?');
        $stmt->execute([$roleCode]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Role tidak ditemukan di database: ' . $roleCode);
        }
        return (int) $id;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        $email = $email !== null ? trim($email) : '';
        return $email === '' ? null : mb_strtolower($email);
    }
}
