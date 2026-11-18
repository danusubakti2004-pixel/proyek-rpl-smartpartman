<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Services\AuthService;
use App\Services\BackupService;

/**
 * Pengujian Fase 9 — header keamanan, verifikasi ulang password restore,
 * dan konfirmasi restore. Tidak menyentuh data produksi.
 */

function test_f9_header_keamanan_dasar(): void
{
    $headers = App::securityHeaders();
    t_eq('nosniff', $headers['X-Content-Type-Options'] ?? '', 'Header nosniff aktif');
    t_eq('SAMEORIGIN', $headers['X-Frame-Options'] ?? '', 'Header anti-clickjacking aktif');
    t_eq(
        'strict-origin-when-cross-origin',
        $headers['Referrer-Policy'] ?? '',
        'Kebijakan referrer membatasi kebocoran URL'
    );
    t_contains("frame-ancestors 'self'", $headers['Content-Security-Policy'] ?? '', 'CSP membatasi frame');
    t_contains("form-action 'self'", $headers['Content-Security-Policy'] ?? '', 'CSP membatasi tujuan form');
    t_eq('noindex, nofollow', $headers['X-Robots-Tag'] ?? '', 'Halaman aplikasi tidak diindeks mesin pencari');
    t_contains('no-store', $headers['Cache-Control'] ?? '', 'Respons aplikasi tidak di-cache');
}

function test_f9_verifikasi_password_restore(): void
{
    $username = 'f9verify_' . strtolower(t_uniq('U'));
    $id = t_make_user($username, 'admin', t_password());
    try {
        $_SESSION['auth_user'] = ['id' => $id, 'username' => $username, 'role' => 'admin'];
        t_true(AuthService::verifyCurrentPassword(t_password()), 'Password Admin yang benar diterima');
        t_true(!AuthService::verifyCurrentPassword('PasswordSalah#1'), 'Password Admin yang salah ditolak');
        t_true(!AuthService::verifyCurrentPassword(''), 'Password kosong ditolak');

        $_SESSION['auth_user'] = ['id' => $id, 'username' => $username, 'role' => 'partman'];
        t_true(!AuthService::verifyCurrentPassword(t_password()), 'Role selain Admin tidak dapat memverifikasi restore');
    } finally {
        t_logout();
    }
}

function test_f9_konfirmasi_restore_wajib_teks_tepat(): void
{
    t_eq('PULIHKAN', BackupService::CONFIRMATION, 'Teks konfirmasi restore adalah PULIHKAN');
    t_true(BackupService::CONFIRMATION !== 'pulihkan', 'Konfirmasi bersifat case-sensitive');
}
