<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Identitas pengguna yang sedang login (berbasis session).
 *
 * Verifikasi kredensial, penguncian akun 5x gagal, dan pencatatan audit
 * ditangani App\Services\AuthService (Fase 2).
 * Kelas ini hanya mengelola SESI identitas agar tanggung jawab tetap jelas.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user';

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        Session::regenerate(true); // cegah session fixation
        Session::put(self::SESSION_KEY, $user);
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        $user = Session::get(self::SESSION_KEY);
        return is_array($user) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user !== null ? (int) $user['id'] : null;
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user['role'] ?? null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $current = self::role();
        return $current !== null && in_array($current, $roles, true);
    }

    public static function logout(bool $keepFlash = false): void
    {
        if ($keepFlash) {
            // Dipakai setelah restore database: sesi identitas lama tidak boleh
            // dipakai lagi, tetapi pesan flash tetap ditampilkan di halaman login.
            Session::forget(self::SESSION_KEY);
            Session::regenerate(true);
            return;
        }
        Session::destroy();
    }
}
