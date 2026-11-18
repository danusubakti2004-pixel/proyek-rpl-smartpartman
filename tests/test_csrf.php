<?php
declare(strict_types=1);

use App\Core\Csrf;

/**
 * Uji token CSRF (PRD §10.1 — proteksi CSRF pada form yang mengubah data).
 * Catatan: Csrf memakai superglobal $_SESSION sehingga dapat diuji di CLI
 * tanpa session_start().
 */
function test_csrf_token_unik_dan_panjang(): void
{
    $_SESSION = [];
    $token = Csrf::token();
    t_eq(64, strlen($token), 'Token CSRF 64 karakter (32 byte hex)');
    t_true(ctype_xdigit($token), 'Token berupa hex');

    // Token tidak boleh berulang antar sesi.
    $_SESSION = [];
    $token2 = Csrf::token();
    t_true($token !== $token2, 'Token berbeda antar sesi (random_bytes)');
}

function test_csrf_verifikasi(): void
{
    $_SESSION = [];
    $token = Csrf::token();

    t_true(Csrf::verify($token), 'Token asli diterima');
    t_true(!Csrf::verify('0000000000000000000000000000000000000000000000000000000000000000'), 'Token palsu ditolak');
    t_true(!Csrf::verify(''), 'String kosong ditolak');
    t_true(!Csrf::verify(null), 'null ditolak');
    t_true(!Csrf::verify(['array']), 'Non-string ditolak');
}

function test_csrf_field_hidden(): void
{
    $_SESSION = [];
    $field = Csrf::field();
    t_contains('type="hidden"', $field, 'Field tersembunyi');
    t_contains('name="_token"', $field, 'Nama field _token');
    $token = Csrf::token();
    t_contains($token, $field, 'Field membawa token sesi');
}
