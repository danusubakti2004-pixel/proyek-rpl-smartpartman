<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Logo identitas bengkel (Fase 9).
 *
 * Keamanan berkas:
 *  - Hanya PNG/JPEG (MIME isi berkas, bukan header kiriman), maksimal 1 MB.
 *  - Nama asli tidak pernah dipakai sebagai path; nama acak di
 *    storage/documents/logos/YYYY/MM (di luar web root).
 *  - Logo hanya disajikan lewat route ber-otorisasi `/pengaturan/logo`.
 *  - SVG sengaja DITOLAK karena dapat memuat JavaScript.
 */
final class LogoService
{
    public const MAX_BYTES = 1048576;

    /** @var array<string,string> ekstensi -> MIME yang diizinkan */
    public const ALLOWED = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /**
     * @param array<string,mixed>|null $file Entri $_FILES
     * @return string Path relatif (mis. logos/2026/10/abc.png)
     */
    public static function store(?array $file): string
    {
        if ($file === null || !isset($file['name']) || $file['name'] === '') {
            throw new RuntimeException('Pilih berkas logo terlebih dahulu.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Pilih berkas logo terlebih dahulu.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Berkas logo tidak terbaca. Silakan unggah ulang.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Berkas logo kosong.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran logo maksimal ' . self::maxSizeLabel() . '.');
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw new RuntimeException('Logo harus berupa berkas PNG atau JPEG.');
        }

        $mime = self::detectMime($tmp);
        if ($mime === null || $mime !== self::ALLOWED[$extension]) {
            throw new RuntimeException('Isi berkas logo tidak sesuai dengan ekstensinya (PNG/JPEG).');
        }
        if (@getimagesize($tmp) === false) {
            throw new RuntimeException('Berkas logo rusak atau bukan gambar yang valid.');
        }
        if (!self::isTrustedUpload($tmp)) {
            throw new RuntimeException('Berkas logo tidak berasal dari proses unggah yang sah.');
        }

        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $relativeDir = 'logos/' . date('Y') . '/' . date('m');
        $absoluteDir = self::basePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0770, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Folder penyimpanan logo tidak dapat dibuat.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
        $absoluteFile = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;
        if (!self::moveFile($tmp, $absoluteFile)) {
            throw new RuntimeException('Gagal menyimpan berkas logo.');
        }

        @chmod($absoluteFile, 0640);
        return $relativeDir . '/' . $fileName;
    }

    /** Validasi bentuk path TANPA mewajibkan berkas ada (untuk data pengaturan lama). */
    public static function isValidPath(string $relative): bool
    {
        $relative = str_replace('\\', '/', trim($relative));
        return preg_match('#^logos/\d{4}/\d{2}/[a-f0-9]{32}\.(png|jpg)$#', $relative) === 1;
    }

    /** Path absolut hanya bila berkas ada DI DALAM folder documents. */
    public static function absolutePath(string $relative): ?string
    {
        if (!self::isValidPath($relative)) {
            return null;
        }

        $base = realpath(self::basePath());
        if ($base === false) {
            return null;
        }
        $candidate = realpath(
            self::basePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        $baseWithSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $baseWithSep) ? $candidate : null;
    }

    /** @return array{mime:string,size:int,name:string}|null */
    public static function describe(string $relative): ?array
    {
        $absolute = self::absolutePath($relative);
        if ($absolute === null) {
            return null;
        }
        return [
            'mime' => self::detectMime($absolute) ?? 'application/octet-stream',
            'size' => (int) filesize($absolute),
            'name' => basename($absolute),
        ];
    }

    public static function delete(?string $relative): void
    {
        if ($relative === null || $relative === '') {
            return;
        }
        $absolute = self::absolutePath($relative);
        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    public static function maxSizeLabel(): string
    {
        return '1 MB';
    }

    private static function basePath(): string
    {
        return (string) App::config('paths.documents', BASE_PATH . '/storage/documents');
    }

    private static function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private static function isTrustedUpload(string $tmp): bool
    {
        return PHP_SAPI === 'cli' ? true : is_uploaded_file($tmp);
    }

    private static function moveFile(string $from, string $to): bool
    {
        return PHP_SAPI === 'cli' ? @rename($from, $to) : move_uploaded_file($from, $to);
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran logo melebihi batas yang diizinkan server.',
            UPLOAD_ERR_PARTIAL                        => 'Unggahan logo terputus. Silakan ulangi.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server tidak menyediakan folder sementara untuk unggahan.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server gagal menulis berkas logo.',
            UPLOAD_ERR_EXTENSION                      => 'Unggahan logo dihentikan oleh ekstensi PHP.',
            default                                   => 'Berkas logo gagal diunggah.',
        };
    }
}
