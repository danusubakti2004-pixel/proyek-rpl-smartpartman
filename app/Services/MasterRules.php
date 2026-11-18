<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Aturan bersama untuk data master & import (FR-03, FR-04, FR-05).
 *
 * Semua masukan dari pengguna dinormalkan DI SINI supaya:
 *  - penulisan kode/nama konsisten (spasi ganda dirapatkan, dipangkas);
 *  - angka dari Excel/CSV Indonesia ("15.000,50") dan format polos
 *    ("15000.50") sama-sama terbaca;
 *  - tanggal dari Excel (serial number) atau "18/10/2026" terbaca.
 *
 * Kelas ini TIDAK menulis ke database; hanya mengubah/memeriksa nilai.
 */
final class MasterRules
{
    public const MAX_CODE = 50;
    public const MAX_NAME = 200;

    /** Bersihkan teks: pangkas + rapatkan spasi ganda. */
    public static function text(?string $value): string
    {
        $value = (string) ($value ?? '');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /** Kode master (huruf besar agar konsisten; uji keunikan tetap ci di DB). */
    public static function code(?string $value): string
    {
        return mb_strtoupper(self::text($value), 'UTF-8');
    }

    /**
     * Ubah nilai apa pun menjadi angka desimal.
     * Mendukung: 15000 | 15000.50 | 15.000 | 15.000,50 | "Rp 15.000" | (1.500) = negatif.
     * Mengembalikan null bila tidak dapat diartikan sebagai angka.
     */
    public static function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = self::text((string) $value);
        if ($raw === '') {
            return null;
        }
        // Buang mata uang & pemisah ribuan non-standar.
        $s = preg_replace('/^(rp|idr)\.?\s*/i', '', $raw) ?? $raw;
        $s = str_replace(["\u{00A0}", ' '], '', $s);

        $negative = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $negative = true;
            $s = trim($s, '()');
        }
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = ltrim($s, '-');
        }
        $s = preg_replace('/[^0-9,.]/u', '', $s) ?? '';
        if ($s === '' || $s === ',' || $s === '.') {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Gaya Indonesia: titik = ribuan, koma = desimal.
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif ($hasComma) {
            // "1.500,50" sudah tertangani di atas; di sini "1,500" atau "1,5".
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s)
                ? str_replace(',', '', $s)
                : str_replace(',', '.', $s);
        } elseif ($hasDot && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            // "15.000" / "1.234.567" → pemisah ribuan.
            $s = str_replace('.', '', $s);
        }

        if (!is_numeric($s)) {
            return null;
        }
        $number = (float) $s;
        return $negative ? -$number : $number;
    }

    /**
     * Ubah nilai menjadi tanggal 'Y-m-d'.
     * Mendukung 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', serial Excel, dan teks tanggal.
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return self::fromExcelSerial((float) $value);
        }

        $s = self::text((string) $value);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m) === 1) {
            return self::buildDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m) === 1) {
            return self::buildDate((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $s) === 1) {
            return self::fromExcelSerial((float) $s);
        }

        $timestamp = strtotime($s);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /** Tafsir boolean dari '1/Ya/true/aktif' dan '0/tidak/false/nonaktif'. */
    public static function parseBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $s = mb_strtolower(self::text((string) $value), 'UTF-8');
        if (in_array($s, ['1', 'ya', 'y', 'true', 'aktif', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'tidak', 't', 'false', 'nonaktif', 'no'], true)) {
            return false;
        }
        return $default;
    }

    private static function buildDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year) || $year < 1900 || $year > 2200) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Serial tanggal Excel (hari sejak 1899-12-30). */
    private static function fromExcelSerial(float $serial): ?string
    {
        if ($serial < 1 || $serial > 109575) { // 1900-01-01 .. 2199-12-31
            return null;
        }
        $base = strtotime('1899-12-30 00:00:00');
        if ($base === false) {
            return null;
        }
        return date('Y-m-d', $base + (int) floor($serial) * 86400);
    }
}
