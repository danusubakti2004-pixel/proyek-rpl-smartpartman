<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Model dasar: pembungkus tipis PDO untuk akses data.
 *
 * Pembagian tanggung jawab (lihat README):
 *   Controller → Service (aturan bisnis, transaksi, audit) → Model (SQL).
 * Model TIDAK menyimpan aturan bisnis; hanya baca/tulis baris.
 *
 * Semua query memakai prepared statement — tidak pernah merangkai SQL
 * dari input pengguna.
 */
abstract class Model
{
    protected static function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    protected static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    protected static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    protected static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $params */
    protected static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    protected static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
