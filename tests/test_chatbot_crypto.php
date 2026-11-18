<?php
declare(strict_types=1);

use App\Core\Crypto;

/**
 * Pengujian Fase 8 — enkripsi API key chatbot.
 * Memastikan ciphertext tidak memuat plaintext dan perubahan data terdeteksi.
 */

function test_chatbot_crypto_roundtrip_dan_tamper(): void
{
    t_true(Crypto::isConfigured(), 'APP_KEY tersedia untuk enkripsi');

    $plain = 'AIzaContohKeyRahasia-1234567890';
    $cipher = Crypto::encrypt($plain);

    t_true(str_starts_with($cipher, 'v1:'), 'Format ciphertext memakai prefix versi v1');
    t_true(!str_contains($cipher, $plain), 'Ciphertext TIDAK memuat plaintext');
    t_eq($plain, Crypto::decrypt($cipher), 'Dekripsi mengembalikan plaintext yang sama');

    $cipher2 = Crypto::encrypt($plain);
    t_true($cipher !== $cipher2, 'Nonce acak membuat ciphertext berbeda untuk plaintext sama');

    // Ubah satu byte ciphertext → GCM harus menolak.
    $raw = base64_decode(substr($cipher, 3), true);
    t_true(is_string($raw) && strlen($raw) > 30, 'Payload terenkripsi cukup panjang');
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $tampered = 'v1:' . base64_encode((string) $raw);
    t_throws(static fn () => Crypto::decrypt($tampered), RuntimeException::class, 'Ciphertext yang diubah ditolak');

    t_throws(static fn () => Crypto::decrypt('bukan-format-v1'), RuntimeException::class, 'Format tak dikenal ditolak');
    t_throws(static fn () => Crypto::decrypt('v1:%%%bukanbase64'), RuntimeException::class, 'Base64 rusak ditolak');
}
