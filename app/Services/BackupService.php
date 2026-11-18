<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Models\Backup;
use RuntimeException;
use Throwable;

/**
 * Backup & restore database (Fase 9).
 *
 * Prinsip keamanan:
 *  - Hanya Admin (ditegakkan route + middleware + verifikasi password ulang).
 *  - Perintah mysqldump/mysql dijalankan TANPA shell, memakai array argumen.
 *  - Kredensial DB tidak pernah masuk command line; dipakai file sementara
 *    ber-permission 0600 yang selalu dihapus.
 *  - Berkas backup hanya di storage/backups, nama dibuat server, dan tidak
 *    dapat diakses langsung dari web.
 *  - Restore wajib: konfirmasi teks, checksum SHA-256, nama database, dan
 *    jumlah migrasi yang sama. Sebelum restore selalu dibuat backup otomatis;
 *    bila restore gagal, backup otomatis itu dipulihkan kembali.
 *  - Tidak ada penghapusan backup otomatis (retensi manual, keputusan Fase 9).
 */
final class BackupService
{
    public const CONFIRMATION = 'PULIHKAN';
    public const KIND_MANUAL = 'manual';
    public const KIND_PRE_RESTORE = 'pre_restore';
    public const MAX_NOTE = 500;

    /** @param array<string,mixed> $db */
    public function __construct(
        private array $db,
        private string $backupDir,
        private string $dumpBinary,
        private string $mysqlBinary,
        private int $maxBytes = 104857600
    ) {
        $this->databaseName(); // validasi nama sejak awal
    }

    public static function fromConfig(): self
    {
        return new self(
            (array) App::config('db', []),
            (string) App::config('paths.backups', BASE_PATH . '/storage/backups'),
            (string) App::config('backup.dump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'),
            (string) App::config('backup.client', 'C:\\xampp\\mysql\\bin\\mysql.exe'),
            (int) App::config('backup.max_bytes', 104857600)
        );
    }

    public function databaseName(): string
    {
        $name = (string) ($this->db['name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('Nama database tidak valid untuk backup.');
        }
        return $name;
    }

    public function directory(): string
    {
        return $this->backupDir;
    }

    /**
     * Buat backup baru + catat metadata & audit.
     *
     * @return array<string,mixed> Baris backup yang tersimpan
     */
    public function create(string $note = '', ?int $actorId = null, string $kind = self::KIND_MANUAL): array
    {
        if (!in_array($kind, [self::KIND_MANUAL, self::KIND_PRE_RESTORE], true)) {
            throw new RuntimeException('Jenis backup tidak valid.');
        }
        $this->ensureDirectory();
        @set_time_limit(300);

        $dbName = $this->databaseName();
        $filename = sprintf(
            'spm_%s_%s_%s.sql',
            $dbName,
            date('Ymd_His'),
            bin2hex(random_bytes(3))
        );
        $absolute = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        $note = mb_substr(trim($note), 0, self::MAX_NOTE);
        $migrationCount = $this->migrationCount();

        try {
            $this->runDump($absolute);

            clearstatcache(true, $absolute);
            if (!is_file($absolute)) {
                throw new RuntimeException('Berkas backup tidak terbentuk.');
            }
            $size = (int) filesize($absolute);
            if ($size <= 0) {
                throw new RuntimeException('Berkas backup kosong.');
            }
            if ($this->maxBytes > 0 && $size > $this->maxBytes) {
                throw new RuntimeException('Ukuran backup melebihi batas ' . $this->maxLabel() . '.');
            }

            $header = (string) file_get_contents($absolute, false, null, 0, 80);
            if (preg_match('/^-- (MariaDB|MySQL) dump/i', $header) !== 1) {
                throw new RuntimeException('Format berkas backup tidak dikenali.');
            }

            $hash = hash_file('sha256', $absolute);
            if ($hash === false) {
                throw new RuntimeException('Checksum backup tidak dapat dihitung.');
            }
            @chmod($absolute, 0640);

            $id = Backup::create([
                'filename'        => $filename,
                'filepath'        => $filename,
                'filesize'        => $size,
                'checksum_sha256' => $hash,
                'database_name'   => $dbName,
                'migration_count' => $migrationCount,
                'status'          => 'success',
                'kind'            => $kind,
                'note'            => $note !== '' ? $note : null,
                'created_by'      => $actorId,
            ]);

            AuditService::log(
                'BACKUP_CREATE',
                'backups',
                (string) $id,
                'Membuat backup database: ' . $filename,
                null,
                [
                    'filename'        => $filename,
                    'filesize'        => $size,
                    'checksum_sha256' => $hash,
                    'database_name'   => $dbName,
                    'migration_count' => $migrationCount,
                    'kind'            => $kind,
                ]
            );

            return Backup::find($id) ?? [
                'id'       => $id,
                'filename' => $filename,
                'filepath' => $filename,
                'filesize' => $size,
                'status'   => 'success',
                'kind'     => $kind,
            ];
        } catch (Throwable $e) {
            if (is_file($absolute)) {
                @unlink($absolute);
            }

            $safeMessage = $this->sanitize($e->getMessage());
            $failedId = null;
            try {
                $failedId = Backup::create([
                    'filename'        => $filename,
                    'filepath'        => '',
                    'filesize'        => null,
                    'checksum_sha256' => null,
                    'database_name'   => $dbName,
                    'migration_count' => $migrationCount,
                    'status'          => 'failed',
                    'kind'            => $kind,
                    'note'            => 'Gagal: ' . $safeMessage,
                    'created_by'      => $actorId,
                ]);
            } catch (Throwable) {
                // Tabel metadata belum termigrasi / DB bermasalah: tetap laporkan error aman.
            }

            AuditService::log(
                'BACKUP_FAILED',
                'backups',
                $failedId !== null ? (string) $failedId : null,
                'Backup database gagal: ' . $safeMessage
            );

            throw new RuntimeException('Backup gagal dibuat: ' . $safeMessage);
        }
    }

    /**
     * Apakah berkas backup masih ada dan berada di folder yang benar?
     * Tidak menghitung ulang checksum (untuk daftar halaman).
     *
     * @param array<string,mixed> $row
     */
    public function isAvailable(array $row): bool
    {
        try {
            $path = $this->candidatePath((string) ($row['filepath'] ?? ''));
            return $path !== null && (int) @filesize($path) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ambil berkas untuk diunduh setelah validasi penuh.
     *
     * @return array{row:array<string,mixed>,path:string}
     */
    public function download(int $id): array
    {
        $row = Backup::find($id);
        if ($row === null) {
            throw new RuntimeException('Data backup tidak ditemukan.');
        }
        $path = $this->validatedFile($row);
        return ['row' => $row, 'path' => $path];
    }

    /**
     * Restore database dari backup.
     *
     * Pemanggil WAJIB sudah memverifikasi password Admin dan CSRF.
     * Konfirmasi teks diverifikasi ulang di sini.
     *
     * @return array{target:array<string,mixed>|null,safety:array<string,mixed>|null,safety_id:int}
     */
    public function restore(
        int $id,
        string $confirmation,
        ?int $actorId = null,
        string $actorUsername = 'sistem',
        ?string $actorRole = null
    ): array {
        if ($confirmation !== self::CONFIRMATION) {
            throw new RuntimeException('Konfirmasi restore tidak cocok. Ketik ' . self::CONFIRMATION . ' dengan huruf besar.');
        }

        $target = Backup::find($id);
        if ($target === null) {
            throw new RuntimeException('Data backup tidak ditemukan.');
        }
        $targetFile = $this->validatedFile($target);

        $this->ensureDirectory();
        $lock = $this->acquireLock();
        @set_time_limit(600);

        try {
            // Backup keselamatan WAJIB berhasil sebelum menyentuh database.
            $safety = $this->create(
                'Otomatis sebelum restore backup #' . $id . ' (' . (string) $target['filename'] . ')',
                $actorId,
                self::KIND_PRE_RESTORE
            );
            $safetyFile = $this->validatedFile($safety);

            try {
                $this->runImport($targetFile);
            } catch (Throwable $e) {
                // Restore gagal di tengah jalan → pulihkan snapshot sebelum restore.
                try {
                    $this->runImport($safetyFile);
                } catch (Throwable $rollbackError) {
                    Database::disconnect();
                    AuditService::logActor(
                        $this->actor($actorId, $actorUsername, $actorRole),
                        'BACKUP_RESTORE_FAILED',
                        'backups',
                        (string) $id,
                        'Restore gagal dan rollback otomatis juga gagal. Diperlukan pemulihan manual.'
                    );
                    throw new RuntimeException(
                        'Restore gagal dan pemulihan otomatis juga gagal. '
                        . 'Segera pulihkan database secara manual dari berkas ' . (string) $safety['filename'] . '.'
                    );
                }

                Database::disconnect();
                $safetyId = $this->reRegister($safety, $actorId);
                AuditService::logActor(
                    $this->actor($actorId, $actorUsername, $actorRole),
                    'BACKUP_RESTORE_FAILED',
                    'backups',
                    (string) $id,
                    'Restore gagal; database dikembalikan ke kondisi sebelum restore memakai backup #' . $safetyId . '.'
                );
                throw new RuntimeException(
                    'Restore gagal; database sudah dikembalikan ke kondisi sebelum restore. '
                    . $this->sanitize($e->getMessage())
                );
            }

            Database::disconnect();

            $actorExists = $actorId !== null && Backup::userExists($actorId);
            $restoredBy = $actorExists ? $actorId : null;

            Backup::markRestored($id, $restoredBy);
            if (Backup::find($id) === null) {
                $target['created_by'] = $this->existingUserOrNull($target['created_by'] ?? null);
                $target['status'] = 'restored';
                $target['restored_at'] = date('Y-m-d H:i:s');
                $target['restored_by'] = $restoredBy;
                Backup::insertFromExisting($target, $restoredBy);
            }

            $safetyId = $this->reRegister($safety, $restoredBy);

            AuditService::logActor(
                $this->actor($actorId, $actorUsername, $actorRole),
                'BACKUP_RESTORE',
                'backups',
                (string) $id,
                'Restore database dari backup #' . $id . ' (' . (string) $target['filename'] . '). '
                . 'Backup keselamatan: #' . $safetyId . ' (' . (string) $safety['filename'] . ').',
                null,
                [
                    'filename'        => (string) $target['filename'],
                    'checksum_sha256' => (string) ($target['checksum_sha256'] ?? ''),
                    'safety_backup_id'=> $safetyId,
                    'safety_filename' => (string) $safety['filename'],
                ]
            );
            AuditService::logActor(
                $this->actor($actorId, $actorUsername, $actorRole),
                'BACKUP_CREATE',
                'backups',
                (string) $safetyId,
                'Membuat backup otomatis sebelum restore: ' . (string) $safety['filename']
            );

            return [
                'target'    => Backup::find($id),
                'safety'    => Backup::find($safetyId),
                'safety_id' => $safetyId,
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Validasi penuh berkas backup: path, ukuran, checksum, header SQL,
     * nama database, dan jumlah migrasi.
     *
     * @param array<string,mixed> $row
     */
    private function validatedFile(array $row): string
    {
        if (!in_array((string) ($row['status'] ?? ''), ['success', 'restored'], true)) {
            throw new RuntimeException('Backup ini tidak berstatus sukses dan tidak dapat dipulihkan.');
        }

        $path = $this->candidatePath((string) ($row['filepath'] ?? ''));
        if ($path === null) {
            throw new RuntimeException('Berkas backup tidak ditemukan di folder backup.');
        }

        clearstatcache(true, $path);
        $size = (int) @filesize($path);
        if ($size <= 0) {
            throw new RuntimeException('Berkas backup kosong atau tidak dapat dibaca.');
        }
        if ($this->maxBytes > 0 && $size > $this->maxBytes) {
            throw new RuntimeException('Ukuran backup melebihi batas ' . $this->maxLabel() . '.');
        }

        $expected = strtolower(trim((string) ($row['checksum_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new RuntimeException('Backup lama tidak memiliki checksum SHA-256. Buat backup baru.');
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('Checksum backup tidak cocok. Berkas mungkin berubah/rusak.');
        }

        $header = (string) file_get_contents($path, false, null, 0, 80);
        if (preg_match('/^-- (MariaDB|MySQL) dump/i', $header) !== 1) {
            throw new RuntimeException('Format berkas backup tidak dikenali.');
        }

        $databaseName = (string) ($row['database_name'] ?? '');
        if ($databaseName !== $this->databaseName()) {
            throw new RuntimeException('Backup berasal dari database lain dan tidak dapat dipulihkan di sini.');
        }

        $migrationCount = (int) ($row['migration_count'] ?? -1);
        if ($migrationCount !== $this->migrationCount()) {
            throw new RuntimeException(
                'Backup dibuat pada versi skema yang berbeda. '
                . 'Jalankan migrasi yang sesuai atau buat backup baru.'
            );
        }

        return $path;
    }

    /** @return string|null path absolut yang aman (di dalam folder backup) */
    private function candidatePath(string $filepath): ?string
    {
        $filepath = str_replace('\\', '/', trim($filepath));
        if ($filepath === ''
            || basename($filepath) !== $filepath
            || str_contains($filepath, '..')
            || preg_match('/^spm_[A-Za-z0-9_]+_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/', $filepath) !== 1
        ) {
            return null;
        }

        $base = realpath($this->backupDir);
        if ($base === false) {
            return null;
        }
        $candidate = realpath($this->backupDir . DIRECTORY_SEPARATOR . $filepath);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        $baseWithSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $baseWithSep) ? $candidate : null;
    }

    private function runDump(string $destination): void
    {
        $credential = $this->credentialFile();
        $out = $this->tempFile('out');
        $err = $this->tempFile('err');
        try {
            $args = [
                $this->dumpBinary,
                '--defaults-extra-file=' . $credential,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                '--result-file=' . $destination,
                $this->databaseName(),
            ];
            $exit = $this->runProcess($args, $out, $err);
            if ($exit !== 0) {
                throw new RuntimeException('mysqldump gagal: ' . $this->readError($err, $exit));
            }
        } finally {
            @unlink($credential);
            @unlink($out);
            @unlink($err);
        }
    }

    private function runImport(string $file): void
    {
        $credential = $this->credentialFile();
        $out = $this->tempFile('out');
        $err = $this->tempFile('err');
        $input = null;
        $process = null;
        $pipes = [];
        try {
            $args = [
                $this->mysqlBinary,
                '--defaults-extra-file=' . $credential,
                '--default-character-set=utf8mb4',
                '--binary-mode',
                $this->databaseName(),
            ];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $out, 'w'],
                2 => ['file', $err, 'w'],
            ];
            $process = @proc_open($args, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Tidak dapat menjalankan klien MySQL.');
            }

            $input = @fopen($file, 'rb');
            if ($input === false) {
                throw new RuntimeException('Berkas backup tidak dapat dibaca.');
            }

            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Gagal membaca berkas backup.');
                }
                $length = strlen($chunk);
                $written = 0;
                while ($written < $length) {
                    $n = @fwrite($pipes[0], substr($chunk, $written));
                    if ($n === false || $n === 0) {
                        throw new RuntimeException('Koneksi ke klien MySQL terputus saat restore.');
                    }
                    $written += $n;
                }
            }
            fclose($input);
            $input = null;
            fclose($pipes[0]);
            $pipes = [];

            $exit = proc_close($process);
            $process = null;
            if ($exit !== 0) {
                throw new RuntimeException('mysql gagal: ' . $this->readError($err, $exit));
            }
        } finally {
            if (is_resource($input)) {
                @fclose($input);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if (is_resource($process)) {
                @proc_terminate($process);
                @proc_close($process);
            }
            @unlink($credential);
            @unlink($out);
            @unlink($err);
        }
    }

    /** @param string[] $args */
    private function runProcess(array $args, string $stdoutFile, string $stderrFile): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $pipes = [];
        $process = @proc_open($args, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Tidak dapat menjalankan utilitas MySQL.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        return proc_close($process);
    }

    private function credentialFile(): string
    {
        $escape = static function (string $value): string {
            return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
        };

        $content = "[client]\n"
            . 'host="' . $escape((string) ($this->db['host'] ?? '127.0.0.1')) . "\"\n"
            . 'port=' . (int) ($this->db['port'] ?? 3306) . "\n"
            . 'user="' . $escape((string) ($this->db['user'] ?? 'root')) . "\"\n"
            . 'password="' . $escape((string) ($this->db['pass'] ?? '')) . "\"\n"
            . "default-character-set=utf8mb4\n";

        $path = tempnam(sys_get_temp_dir(), 'spm_mysql_');
        if ($path === false || file_put_contents($path, $content) === false) {
            if (is_string($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Tidak dapat menyiapkan kredensial sementara untuk MySQL.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private function tempFile(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spm_bak_' . $suffix . '_');
        if ($path === false) {
            throw new RuntimeException('Tidak dapat membuat berkas sementara.');
        }
        return $path;
    }

    private function readError(string $file, int $exit): string
    {
        $message = trim((string) @file_get_contents($file));
        if ($message === '') {
            $message = 'kode keluar ' . $exit;
        }
        return $this->sanitize($message);
    }

    private function sanitize(string $message): string
    {
        $password = (string) ($this->db['pass'] ?? '');
        if ($password !== '') {
            $message = str_replace($password, '[REDAKTIF]', $message);
        }
        $message = preg_replace('/[\r\n]+/', ' ', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        return mb_substr(trim($message), 0, 600);
    }

    private function migrationCount(): int
    {
        try {
            return (int) Database::pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0770, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Folder backup tidak dapat dibuat.');
        }
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $path = $this->backupDir . DIRECTORY_SEPARATOR . '.restore.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuat kunci restore.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Proses restore lain sedang berjalan. Coba lagi nanti.');
        }
        return $handle;
    }

    /** @param resource|null $handle */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @param array<string,mixed> $row */
    private function reRegister(array $row, ?int $actorId): int
    {
        $existing = Backup::find((int) ($row['id'] ?? 0));
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $row['created_by'] = $this->existingUserOrNull($row['created_by'] ?? null);
        return Backup::insertFromExisting($row, $actorId);
    }

    private function existingUserOrNull(mixed $id): ?int
    {
        $id = $id !== null ? (int) $id : 0;
        return $id > 0 && Backup::userExists($id) ? $id : null;
    }

    /** @return array{id:int|null,username:string,role:string|null} */
    private function actor(?int $id, string $username, ?string $role): array
    {
        return [
            'id'       => $id !== null && $id > 0 && Backup::userExists($id) ? $id : null,
            'username' => $username !== '' ? $username : 'sistem',
            'role'     => $role,
        ];
    }

    private function maxLabel(): string
    {
        return max(1, (int) round($this->maxBytes / 1048576)) . ' MB';
    }
}
