<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session PHP yang aman.
 *
 * CATATAN KEPUTUSAN: tidak ada session timeout otomatis (keputusan pengguna, PRD FR-01).
 * Risiko perangkat bersama harus didokumentasikan; sediakan tombol logout yang jelas.
 */
final class Session
{
    private const FLASH_KEY = '_flash';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1'); // tolak session id yang tidak dikenal
        session_name('spmsess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,                          // JS tidak boleh membaca cookie
            'samesite' => 'Lax',                         // proteksi CSRF dasar
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Simpan pesan sekali pakai (flash). */
    public static function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][$type] = $message;
    }

    /** Ambil lalu kosongkan seluruh pesan flash. @return array<string,string> */
    public static function flashPop(): array
    {
        $bag = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);
        return is_array($bag) ? $bag : [];
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    /**
     * Simpan input form terakhir agar bisa diisikan ulang setelah validasi gagal.
     * Nilai yang mengandung rahasia TIDAK disimpan (lihat REDACTED_KEYS).
     *
     * @param array<string,mixed> $input
     */
    public static function flashInput(array $input): void
    {
        $blocked = ['password', 'password_confirmation', 'current_password', 'new_password', 'new_password_confirmation', '_token'];
        foreach ($blocked as $key) {
            unset($input[$key]);
        }
        $_SESSION['_old_input'] = $input;
    }

    /** @return array<string,mixed> Ambil (sekali pakai) input form terakhir. */
    public static function oldInput(): array
    {
        $bag = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
        return is_array($bag) ? $bag : [];
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
