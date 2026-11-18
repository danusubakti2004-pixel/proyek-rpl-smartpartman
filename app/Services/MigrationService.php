<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Migration runner terdokumentasi (PRD aturan #13).
 *
 * - File .sql di database/migrations/ diurutkan by nama dan dijalankan sekali saja.
 * - Setiap file yang sukses dicatat ke tabel `migrations` (batch + waktu).
 * - Koneksi migrasi MENGAKTIFKAN multi-statement HANYA di sini (bukan di koneksi aplikasi).
 * - Skema InnoDB, utf8mb4, foreign key, dan indeks didefinisikan di file SQL.
 *
 * Catatan MySQL/MariaDB: perintah DDL memicu implicit commit, sehingga satu file
 * migrasi tidak bisa di-rollback penuh. Karena itu file disusun kecil, berurutan,
 * aman dijalankan ulang (idempoten: CREATE TABLE IF NOT EXISTS / INSERT jika kosong).
 */
final class MigrationService
{
    /** @param array<string,mixed> $db Konfigurasi DB (host, port, name, user, pass, charset). */
    public function __construct(
        private array $db,
        private string $migrationsPath
    ) {
    }

    private function connection(bool $multiStatements = false, ?string $dbName = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s%s',
            (string) $this->db['host'],
            (int) $this->db['port'],
            (string) ($this->db['charset'] ?? 'utf8mb4'),
            $dbName !== null ? ';dbname=' . $dbName : ''
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($multiStatements) {
            $options[\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }
        return new PDO($dsn, (string) $this->db['user'], (string) $this->db['pass'], $options);
    }

    public function ensureDatabase(): void
    {
        $name = $this->databaseName();
        $this->connection()->exec(
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function databaseName(): string
    {
        $name = (string) $this->db['name'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Nama database tidak valid: {$name}");
        }
        return $name;
    }

    /** @return string[] Daftar nama file migrasi (berurutan). */
    public function files(): array
    {
        $found = glob($this->migrationsPath . '/*.sql');
        if ($found === false) {
            return [];
        }
        sort($found, SORT_STRING);
        return array_map(static fn (string $p): string => basename($p), $found);
    }

    /** @return string[] Nama migrasi yang sudah tercatat. */
    public function applied(): array
    {
        $name = $this->databaseName();
        $pdo = $this->connection(false, $name);
        $rows = $pdo->query('SELECT migration FROM migrations ORDER BY migration ASC')->fetchAll();
        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /** @return string[] File migrasi yang belum dijalankan. */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /**
     * Jalankan semua migrasi yang belum berjalan.
     *
     * @param callable(string):void|null $output Callback untuk mencetak progres (CLI).
     * @return string[] Nama migrasi yang berhasil dijalankan.
     */
    public function run(?callable $output = null): array
    {
        $this->ensureDatabase();
        $name = $this->databaseName();

        $pdo = $this->connection(true, $name);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(191) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pending = $this->pending();
        if ($pending === []) {
            $output && $output('Tidak ada migrasi yang tertunda.');
            return [];
        }

        $batch = (int) $this->connection(false, $name)
            ->query('SELECT COALESCE(MAX(batch), 0) + 1 AS b FROM migrations')
            ->fetch()['b'];

        $executed = [];
        foreach ($pending as $file) {
            $sql = file_get_contents($this->migrationsPath . '/' . $file);
            if ($sql === false) {
                throw new RuntimeException("Tidak bisa membaca file migrasi: {$file}");
            }
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                throw new RuntimeException("Migrasi GAGAL pada file {$file}: " . $e->getMessage(), 0, $e);
            }
            $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:name, :batch)');
            $stmt->execute([':name' => $file, ':batch' => $batch]);
            $executed[] = $file;
            $output && $output("  [OK] {$file}");
        }

        return $executed;
    }
}
