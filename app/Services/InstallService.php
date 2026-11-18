<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Installer Admin pertama (PRD FR-01).
 *
 * - Hanya bisa dijalankan sekali: setelah ada akun admin, isInstalled() = true
 *   dan route /install terkunci oleh App::run().
 * - Tidak pernah menyimpan password plaintext (password_hash PASSWORD_DEFAULT).
 */
final class InstallService
{
    public static function isInstalled(): bool
    {
        try {
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) AS c FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin'"
            );
            $row = $stmt->fetch();
            return (int) ($row['c'] ?? 0) > 0;
        } catch (Throwable) {
            // Tabel belum ada (migrasi belum dijalankan) → dianggap belum terpasang.
            return false;
        }
    }

    /** true jika koneksi DB berhasil dan tabel users sudah ada. */
    public static function tablesReady(): bool
    {
        try {
            Database::pdo()->query('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{connected:bool, error:string|null} */
    public static function dbStatus(): array
    {
        try {
            Database::pdo()->query('SELECT 1');
            return ['connected' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array{username:string, full_name:string, email?:string|null, password:string} $input
     * @return int id pengguna baru
     */
    public static function createAdmin(array $input): int
    {
        if (self::isInstalled()) {
            throw new RuntimeException('Instalasi sudah pernah dijalankan. Gunakan halaman login.');
        }
        if (!self::tablesReady()) {
            throw new RuntimeException('Tabel database belum tersedia. Jalankan migrasi terlebih dahulu: php database\migrate.php');
        }

        $pdo = Database::pdo();

        $exists = $pdo->prepare('SELECT id FROM users WHERE username = :username');
        $exists->execute([':username' => $input['username']]);
        if ($exists->fetch() !== false) {
            throw new RuntimeException('Username sudah digunakan. Pilih username lain.');
        }

        $roleStmt = $pdo->query("SELECT id FROM roles WHERE code = 'admin'");
        $role = $roleStmt->fetch();
        if ($role === false) {
            throw new RuntimeException('Role "admin" tidak ditemukan. Jalankan migrasi database terlebih dahulu.');
        }

        $pdo->prepare(
            'INSERT INTO users (username, full_name, email, password_hash, role_id, is_active, created_by)
             VALUES (:username, :full_name, :email, :password_hash, :role_id, 1, NULL)'
        )->execute([
            ':username'     => $input['username'],
            ':full_name'    => $input['full_name'],
            ':email'        => $input['email'] ?? null,
            ':password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            ':role_id'      => (int) $role['id'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        AuditService::log(
            'INSTALASI_ADMIN',
            'users',
            (string) $userId,
            'Admin pertama dibuat melalui halaman instalasi: ' . $input['username']
        );

        return $userId;
    }
}
