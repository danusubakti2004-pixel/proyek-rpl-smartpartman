<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Enkripsi simetris untuk rahasia yang disimpan di database (Fase 8).
 *
 * Algoritma : AES-256-GCM (terautentikasi: mendeteksi perubahan data).
 * Kunci     : diturunkan dari APP_KEY (.env) dengan SHA-256.
 * Format    : "v1:" + base64(nonce 12 byte + tag 16 byte + ciphertext).
 *
 * Aturan:
 * - Plaintext TIDAK PERNAH ditulis ke log, audit, atau tampilan.
 * - Bila APP_KEY belum diatur / berubah, dekripsi gagal dengan pesan aman.
 * - Kelas ini tidak menyimpan kunci; kunci selalu dibaca dari konfigurasi.
 */
final class Crypto
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function isConfigured(): bool
    {
        return strlen(self::rawKey()) >= 32;
    }

    /**
     * Enkripsi plaintext. Hasil aman disimpan pada kolom TEXT.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Enkripsi rahasia gagal. Periksa ekstensi OpenSSL.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Dekripsi payload hasil encrypt(). Melempar RuntimeException bila
     * format salah, kunci berubah, atau data dimodifikasi.
     */
    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('Format data terenkripsi tidak dikenal.');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        $minimum = self::NONCE_BYTES + self::TAG_BYTES + 1;
        if ($raw === false || strlen($raw) < $minimum) {
            throw new RuntimeException('Data terenkripsi rusak atau tidak lengkap.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Data terenkripsi tidak dapat dibuka (kunci berubah atau data rusak).');
        }

        return $plaintext;
    }

    /**
     * Kunci mentah APP_KEY dari konfigurasi aplikasi, fallback .env
     * (dipakai skrip CLI/pengujian yang tidak memanggil App::boot()).
     */
    private static function rawKey(): string
    {
        $key = App::config('app.key', null);
        if (!is_string($key) || trim($key) === '') {
            $key = Env::get('APP_KEY', '');
        }
        return trim((string) $key);
    }

    private static function key(): string
    {
        $raw = self::rawKey();
        if (strlen($raw) < 32) {
            throw new RuntimeException(
                'APP_KEY belum diatur dengan benar. Isi APP_KEY 64 karakter pada .env (php database/keygen.php).'
            );
        }
        return hash('sha256', $raw, true);
    }
}
