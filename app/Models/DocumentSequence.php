<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Penomoran dokumen transaksi (GR-…, GI-…, SO-…).
 *
 * Tabel `document_sequences` menyimpan satu baris counter per (prefix, periode).
 * nextNumber() WAJIB dipanggil dari dalam transaksi database: UPDATE-nya
 * mengambil row lock sehingga dua pengguna yang menyimpan transaksi pada
 * saat yang sama tidak pernah memperoleh nomor yang sama (FR-06/FR-07,
 * §10.2 "cegah race condition").
 */
final class DocumentSequence extends Model
{
    /** Naikkan counter dan kembalikan nomor berikutnya (>= 1). */
    public static function nextNumber(string $prefix, string $period): int
    {
        // Pastikan baris counter ada (INSERT ... ON DUPLICATE KEY tidak mengubah apa pun bila ada).
        self::execute(
            'INSERT INTO document_sequences (prefix, period, last_number)
             VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE prefix = prefix',
            [$prefix, $period]
        );

        // Baris ini terkunci sampai transaksi selesai → penomoran aman.
        self::execute(
            'UPDATE document_sequences SET last_number = last_number + 1 WHERE prefix = ? AND period = ?',
            [$prefix, $period]
        );

        return (int) self::scalar(
            'SELECT last_number FROM document_sequences WHERE prefix = ? AND period = ?',
            [$prefix, $period]
        );
    }
}
