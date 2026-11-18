<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Proteksi CSRF (token per sesi, dijamin dengan hash_equals).
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . self::token() . '">';
    }

    public static function verify(mixed $token): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Cek token; jika gagal, kirim respons 403 dan hentikan eksekusi. */
    public static function verifyOrAbort(mixed $token): void
    {
        if (!self::verify($token)) {
            http_response_code(403);
            echo View::render('errors/403', [
                'appName' => App::config('app.name'),
                'message' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman lalu coba lagi.',
            ], null);
            exit;
        }
    }
}
