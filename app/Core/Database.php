<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Koneksi database (PDO) + bantuan transaksi.
 *
 * - Selalu memakai prepared statement (tidak pernah interpolasi input pengguna).
 * - ERRMODE_EXCEPTION agar kegagalan transaksi ter-rollBack dan ditangani.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string,mixed> */
    private static array $config = [];

    /**
     * Callback yang dijalankan SETELAH transaksi terluar berhasil di-commit.
     * Dipakai untuk pekerjaan turunan yang tidak boleh berjalan bila transaksi
     * dibatalkan (mis. menandai prediksi stok perlu diperbarui).
     *
     * @var array<int,callable>
     */
    private static array $afterCommit = [];

    /** Kedalaman transaksi bersarang (hanya transaksi terluar yang memicu callback). */
    private static int $depth = 0;

    /** @param array<string,mixed> $dbConfig */
    public static function configure(array $dbConfig): void
    {
        self::$config = $dbConfig;
    }

    /**
     * Konfigurasi koneksi yang sedang aktif (host, port, name, user, pass, charset).
     * HANYA untuk kebutuhan internal, mis. merangkai MigrationService.
     * JANGAN pernah menuliskan array ini ke respons HTTP atau log.
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        return self::$config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($c['host'] ?? '127.0.0.1'),
                (int) ($c['port'] ?? 3306),
                (string) ($c['name'] ?? ''),
                (string) ($c['charset'] ?? 'utf8mb4')
            );
            self::$pdo = new PDO($dsn, (string) ($c['user'] ?? 'root'), (string) ($c['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Tutup koneksi aktif. Wajib dipanggil setelah restore database agar
     * request berikutnya memakai skema/data hasil restore, bukan cache PDO lama.
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /**
     * Daftarkan callback yang dijalankan setelah transaksi terluar commit.
     *
     * Callback TIDAK dijalankan bila transaksi dibatalkan. Kesalahan pada
     * callback tidak boleh menggagalkan pekerjaan yang sudah tersimpan
     * (exception ditelan), karena callback bersifat turunan/tidak kritis.
     */
    public static function afterCommit(callable $fn): void
    {
        self::$afterCommit[] = $fn;
    }

    /**
     * Menjalankan callback di dalam transaksi database.
     * Jika callback melempar exception, transaksi di-rollBack (stok tidak berubah setengah jalan).
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            self::$depth++;
            try {
                return $fn($pdo); // transaksi induk sudah aktif
            } finally {
                self::$depth--;
            }
        }
        $pdo->beginTransaction();
        self::$depth = 1;
        $committed = false;
        try {
            $result = $fn($pdo);
            $pdo->commit();
            $committed = true;
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::$depth = 0;
            $callbacks = self::$afterCommit;
            self::$afterCommit = [];
            if ($committed) {
                foreach ($callbacks as $callback) {
                    try {
                        $callback();
                    } catch (Throwable $e) {
                        // Pekerjaan utama sudah commit; kegagalan turunan tidak
                        // boleh mengubah hasil transaksi atau menggagalkan request.
                    }
                }
            }
        }
    }
}
