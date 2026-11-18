<?php
declare(strict_types=1);

/**
 * Helper global (fungsional, tanpa namespace).
 * Semua output yang berasal dari data harus melewati e() untuk anti-XSS.
 */

use App\Core\Auth;
use App\Core\Csrf;

/** Escape HTML (anti-XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * URL absolut-relatif ke basis aplikasi.
 * Basis diturunkan dari lokasi index.php sehingga tetap benar saat diakses
 * via localhost maupun IP jaringan LAN (http://192.168.x.x/smartpartman).
 */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Alias ringkas base_url(). */
function url(string $path = ''): string
{
    return base_url($path);
}

/**
 * Path aset statis.
 *
 * Ditambahkan cache-busting berbasis waktu modifikasi berkas agar browser
 * selalu mengambil CSS/JS terbaru setelah aplikasi diperbarui (mencegah
 * tampilan lama/rusak akibat cache heuristik Apache tanpa Cache-Control).
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $url = base_url('assets/' . $path);

    if (defined('BASE_PATH')) {
        $file = BASE_PATH . '/assets/' . $path;
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
    }

    return $url;
}

/**
 * URL logo bengkel melalui route ber-otorisasi. Cache-busting memakai hash
 * path agar logo baru langsung tampil setelah diganti.
 */
function logo_url(?string $path): string
{
    $path = trim((string) $path);
    return $path === ''
        ? ''
        : base_url('pengaturan/logo') . '?v=' . substr(hash('sha256', $path), 0, 8);
}

/** Hidden field token CSRF. */
function csrf_field(): string
{
    return Csrf::field();
}

/** Format Rupiah: 15000 → "Rp 15.000". */
function rupiah(int|float|string|null $value): string
{
    return 'Rp ' . number_format((float) ($value ?? 0), 0, ',', '.');
}

/** Data pengguna login (atau null). @return array<string,mixed>|null */
function auth(): ?array
{
    return Auth::user();
}

function auth_check(): bool
{
    return Auth::check();
}

/** Tanggal Indonesia: 2026-10-18 → 18 Oktober 2026. */
function tanggal_id(string|null $date, bool $withTime = false): string
{
    if ($date === null || $date === '') {
        return '-';
    }
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return (string) $date;
    }
    $formatted = date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    if ($withTime) {
        $formatted .= ', ' . date('H:i', $timestamp) . ' WIB';
    }
    return $formatted;
}
