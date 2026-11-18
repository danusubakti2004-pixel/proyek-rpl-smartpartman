<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Backup;
use App\Services\AuditService;
use App\Services\BackupService;

/**
 * Pengujian Fase 9 — backup/restore database.
 *
 * Dijalankan TERAKHIR (nama file test_zz_...) karena salah satu pengujian
 * melakukan restore database uji ke snapshot yang dibuat di dalam test.
 * Hanya menyentuh DB uji; tidak pernah DB produksi.
 */

/** @return array{service:BackupService,dir:string,beforeMax:int,db:array<string,mixed>} */
function t_f9_service(): array
{
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['db'];
    $db['name'] = (string) $config['db']['test_name'];
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spm_f9_backup_' . bin2hex(random_bytes(5));
    $service = new BackupService(
        $db,
        $dir,
        (string) $config['backup']['dump'],
        (string) $config['backup']['client'],
        (int) $config['backup']['max_bytes']
    );
    $beforeMax = (int) Database::pdo()->query('SELECT COALESCE(MAX(id), 0) FROM backups')->fetchColumn();
    return ['service' => $service, 'dir' => $dir, 'beforeMax' => $beforeMax, 'db' => $db];
}

function t_f9_cleanup(string $dir, int $beforeMax): void
{
    $pdo = Database::pdo();
    $rows = $pdo->query('SELECT id, filepath FROM backups WHERE id > ' . $beforeMax)->fetchAll();
    foreach ($rows as $row) {
        $path = $dir . DIRECTORY_SEPARATOR . basename((string) $row['filepath']);
        if (is_file($path)) {
            @unlink($path);
        }
        $pdo->prepare('DELETE FROM backups WHERE id = ?')->execute([(int) $row['id']]);
    }

    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

function test_f9_backup_create_validasi_dan_restore_aktual(): void
{
    $fixture = t_f9_service();
    $service = $fixture['service'];
    $dir = $fixture['dir'];
    $beforeMax = $fixture['beforeMax'];
    $targetId = 0;
    $safetyId = 0;

    try {
        $actorId = t_make_user('f9backup_' . strtolower(t_uniq('U')), 'admin', t_password());
        $backup = $service->create('Backup uji Fase 9', $actorId);

        $targetId = (int) $backup['id'];
        $filename = (string) $backup['filename'];
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        t_true($targetId > 0, 'Backup menghasilkan ID metadata');
        t_true(is_file($path), 'Berkas backup benar-benar dibuat di folder terpisah');
        t_eq('smartpartman_test', (string) $backup['database_name'], 'Metadata mencatat database uji');
        t_eq(64, strlen((string) $backup['checksum_sha256']), 'Checksum SHA-256 tersimpan (64 hex)');
        t_true((int) $backup['migration_count'] >= 14, 'Jumlah migrasi tersimpan');
        t_true($service->isAvailable($backup), 'Backup baru tersedia untuk diunduh');
        t_eq(hash_file('sha256', $path), (string) $backup['checksum_sha256'], 'Checksum berkas cocok');

        $download = $service->download($targetId);
        t_eq(realpath($path), $download['path'], 'Unduhan memakai path tervalidasi');

        $oldNote = (string) Database::pdo()->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'workshop_note'"
        )->fetchColumn();
        Database::pdo()->exec(
            "UPDATE app_settings SET setting_value = 'F9_MARKER_SEBELUM_RESTORE' WHERE setting_key = 'workshop_note'"
        );
        AuditService::log('UJI_F9_MARKER_RESTORE', 'uji', '1', 'Marker yang harus hilang setelah restore');
        t_eq(
            1,
            (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE activity = 'UJI_F9_MARKER_RESTORE'")->fetchColumn(),
            'Marker audit ada sebelum restore'
        );

        $result = $service->restore($targetId, BackupService::CONFIRMATION, $actorId, 'uji_f9', 'admin');
        $safetyId = (int) $result['safety_id'];
        t_true($safetyId > 0, 'Restore membuat backup keselamatan otomatis');
        t_true(
            is_file($dir . DIRECTORY_SEPARATOR . (string) ($result['safety']['filename'] ?? '')),
            'Berkas backup keselamatan tersimpan'
        );

        $targetRow = Backup::find($targetId);
        t_eq('restored', (string) ($targetRow['status'] ?? ''), 'Status backup target menjadi restored');
        t_true(($targetRow['restored_at'] ?? null) !== null, 'Waktu restore tercatat');
        t_eq($actorId, (int) ($targetRow['restored_by'] ?? 0), 'Pelaku restore tercatat');
        t_true(Backup::find($safetyId) !== null, 'Metadata backup keselamatan didaftarkan kembali setelah restore');

        $restoredNote = (string) Database::pdo()->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'workshop_note'"
        )->fetchColumn();
        t_eq($oldNote, $restoredNote, 'Data app_settings kembali ke snapshot sebelum restore');
        t_eq(
            0,
            (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE activity = 'UJI_F9_MARKER_RESTORE'")->fetchColumn(),
            'Marker audit yang dibuat setelah snapshot hilang setelah restore'
        );
        t_true(t_latest_audit('BACKUP_RESTORE') !== null, 'Aksi restore dicatat di Audit Log');
        t_true(
            t_latest_audit('BACKUP_RESTORE_FAILED') === null
            || (int) t_latest_audit('BACKUP_RESTORE_FAILED')['id'] < (int) t_latest_audit('BACKUP_RESTORE')['id'],
            'Tidak ada kegagalan restore terakhir'
        );
    } finally {
        t_f9_cleanup($dir, $beforeMax);
    }
}

function test_f9_backup_tamper_ditolak_tanpa_menyentuh_database(): void
{
    $fixture = t_f9_service();
    $service = $fixture['service'];
    $dir = $fixture['dir'];
    $beforeMax = $fixture['beforeMax'];

    try {
        $backup = $service->create('Backup untuk uji tamper');
        $id = (int) $backup['id'];
        $path = $dir . DIRECTORY_SEPARATOR . (string) $backup['filename'];
        file_put_contents($path, "\n-- TAMPERED\n", FILE_APPEND);

        t_throws(
            static fn () => $service->restore($id, BackupService::CONFIRMATION),
            RuntimeException::class,
            'Backup yang berubah (checksum tidak cocok) ditolak'
        );
        $row = Backup::find($id);
        t_eq(null, $row['restored_at'] ?? null, 'Backup yang ditolak tidak ditandai restored');
        t_eq(
            0,
            (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM backups WHERE kind = 'pre_restore' AND id > " . $beforeMax
            )->fetchColumn(),
            'Tidak ada backup keselamatan dibuat bila validasi berkas gagal'
        );
    } finally {
        t_f9_cleanup($dir, $beforeMax);
    }
}

function test_f9_backup_konfirmasi_dan_path_traversal_ditolak(): void
{
    $fixture = t_f9_service();
    $service = $fixture['service'];
    $dir = $fixture['dir'];
    $beforeMax = $fixture['beforeMax'];
    $fakeId = 0;

    try {
        $backup = $service->create('Backup untuk uji konfirmasi');
        $id = (int) $backup['id'];
        t_throws(
            static fn () => $service->restore($id, 'pulihkan'),
            RuntimeException::class,
            'Konfirmasi huruf kecil ditolak'
        );
        t_eq(null, Backup::find($id)['restored_at'] ?? null, 'Database tidak berubah saat konfirmasi salah');

        $fakeId = Backup::create([
            'filename'        => '../../.env',
            'filepath'        => '../../.env',
            'filesize'        => 10,
            'checksum_sha256' => str_repeat('a', 64),
            'database_name'   => 'smartpartman_test',
            'migration_count' => 14,
            'status'          => 'success',
            'kind'            => 'manual',
        ]);
        t_throws(
            static fn () => $service->download($fakeId),
            RuntimeException::class,
            'Path traversal pada metadata backup ditolak'
        );
        t_eq(false, $service->isAvailable(['filepath' => '../../.env']), 'Berkas di luar folder backup ditandai tidak tersedia');
    } finally {
        if ($fakeId > 0) {
            Database::pdo()->prepare('DELETE FROM backups WHERE id = ?')->execute([$fakeId]);
        }
        t_f9_cleanup($dir, $beforeMax);
    }
}
