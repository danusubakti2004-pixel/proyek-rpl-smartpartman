<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\InstallService;

/**
 * Uji installer Admin pertama + Audit Log (PRD FR-01, FR-20).
 * Berjalan di database uji setelah test_database memastikan migrasi selesai.
 */
function test_install_keadaan_awal_bersih(): void
{
    $pdo = Database::pdo();
    // Transaksi Fase 4 memakai ON DELETE RESTRICT ke `users` → bersihkan dulu.
    t_purge_transaction_data();
    // Menghapus pengguna tetap boleh (FK audit_logs → user_id menjadi NULL).
    // audit_logs sendiri bersifat append-only (trigger migrasi 007).
    $pdo->exec('DELETE FROM users');

    t_true(!InstallService::isInstalled(), 'Sebelum dibuat: status belum terpasang');
    t_true(InstallService::tablesReady(), 'Tabel database siap');
    $status = InstallService::dbStatus();
    t_eq(true, $status['connected'], 'Status koneksi database terhubung');
}

function test_install_membuat_admin_pertama(): void
{
    $userId = InstallService::createAdmin([
        'username'  => 'admin_tes',
        'full_name' => 'Admin Uji Fase 1',
        'email'     => 'admin_tes@example.test',
        'password'  => 'rahasia123',
    ]);

    t_true($userId > 0, 'Admin pertama berhasil dibuat (ID: ' . $userId . ')');
    t_true(InstallService::isInstalled(), 'Setelah dibuat: instalasi terkunci (isInstalled = true)');

    $stmt = Database::pdo()->prepare(
        'SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    t_true($row !== false, 'Baris pengguna tersimpan');
    t_eq('admin', $row['role'], 'Role pengguna = admin');
    t_eq(1, (int) $row['is_active'], 'Akun aktif');
    t_true(!str_contains((string) $row['password_hash'], 'rahasia123'), 'Password TIDAK tersimpan plaintext');
    t_true(password_verify('rahasia123', (string) $row['password_hash']), 'password_verify cocok');
}

function test_install_installer_terkunci_setelah_berhasil(): void
{
    t_throws(
        static fn () => InstallService::createAdmin([
            'username'  => 'admin_kedua',
            'full_name' => 'Admin Kedua',
            'password'  => 'rahasia123',
        ]),
        RuntimeException::class,
        'Installer menolak pembuatan admin kedua'
    );
}

function test_install_audit_log_tercatat_tanpa_rahasia(): void
{
    $pdo = Database::pdo();

    $audit = $pdo->query(
        "SELECT * FROM audit_logs WHERE activity = 'INSTALASI_ADMIN' ORDER BY id DESC LIMIT 1"
    )->fetch();
    t_true($audit !== false, 'Audit log INSTALASI_ADMIN tercatat');
    t_contains('admin_tes', (string) $audit['description'], 'Deskripsi memuat username admin');
    t_eq('sistem', (string) $audit['username'], 'Pelaku = sistem (belum ada sesi login)');
    t_true($audit['ip_address'] !== null && $audit['ip_address'] !== '', 'IP pelaku tercatat');

    $all = $pdo->query('SELECT description, old_values, new_values FROM audit_logs')->fetchAll();
    $dump = json_encode($all, JSON_UNESCAPED_UNICODE);
    t_true(!str_contains((string) $dump, 'rahasia123'), 'Tidak ada password plaintext di seluruh audit log');
}
