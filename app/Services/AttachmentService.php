<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Bukti transaksi (foto/PDF) untuk penerimaan barang (FR-06).
 *
 * Aturan keamanan berkas (PRD §10.1):
 *  - Ekstensi, MIME type (isi berkas, bukan header kiriman), dan ukuran
 *    diperiksa.
 *  - Nama berkas kiriman TIDAK pernah dipakai sebagai path (hanya disimpan
 *    sebagai keterangan) — mencegah path traversal.
 *  - Berkas disimpan di storage/private_uploads (DI LUAR web root) dengan
 *    nama acak dan hanya dapat diunduh lewat route yang memeriksa otorisasi.
 *  - Berkas yatim dihapus bila penyimpanan transaksi gagal.
 */
final class AttachmentService
{
    /** 5 MB — cukup untuk foto nota maupun PDF invoice. */
    public const MAX_BYTES = 5242880;

    /** @var array<string,string> ekstensi → MIME yang diizinkan */
    public const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    /**
     * Simpan berkas unggahan.
     *
     * @param array<string,mixed>|null $file Entri dari $_FILES (name/type/tmp_name/error/size)
     * @param string $category Sub-folder (mis. "receipts")
     * @return array{path:string,name:string}|null null bila pengguna tidak mengunggah berkas
     *
     * @throws RuntimeException bila berkas tidak valid
     */
    public static function store(?array $file, string $category = 'receipts'): ?array
    {
        if ($file === null || !isset($file['name']) || $file['name'] === '') {
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Berkas bukti tidak terbaca. Silakan unggah ulang.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Berkas bukti kosong.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran bukti maksimal ' . self::maxSizeLabel() . '.');
        }

        $originalName = self::sanitizeName((string) ($file['name'] ?? ''));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw new RuntimeException('Jenis berkas bukti harus salah satu dari: JPG, JPEG, PNG, atau PDF.');
        }

        $detected = self::detectMime($tmp);
        if ($detected === null || $detected !== self::ALLOWED[$extension]) {
            throw new RuntimeException('Isi berkas bukti tidak sesuai dengan ekstensinya (JPG/PNG/PDF).');
        }

        if ($detected !== 'application/pdf') {
            // Pastikan benar-benar gambar utuh, bukan berkas yang diganti nama.
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new RuntimeException('Berkas gambar bukti rusak atau tidak valid.');
            }
        }

        if (!self::isTrustedUpload($tmp)) {
            throw new RuntimeException('Berkas bukti tidak berasal dari proses unggah yang sah.');
        }

        $relativeDir = trim($category, '/') . '/' . date('Y') . '/' . date('m');
        $absoluteDir = self::basePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0770, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Folder penyimpanan bukti tidak dapat dibuat.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
        $absoluteFile = $absoluteDir . DIRECTORY_SEPARATOR . $fileName;

        if (!self::moveFile($tmp, $absoluteFile)) {
            throw new RuntimeException('Gagal menyimpan berkas bukti.');
        }

        @chmod($absoluteFile, 0640);

        return [
            'path' => $relativeDir . '/' . $fileName,
            'name' => $originalName,
        ];
    }

    /** Path absolut bila berkas benar-benar ada DI DALAM folder unggahan; selain itu null. */
    public static function absolutePath(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = realpath(self::basePath());
        if ($base === false) {
            return null;
        }

        $candidate = realpath(self::basePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        $baseWithSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $baseWithSep)) {
            return null; // pertahanan terhadap path traversal
        }

        return $candidate;
    }

    /** Metadata untuk mengirim berkas ke peramban. @return array{mime:string,size:int,name:string}|null */
    public static function describe(string $relative, ?string $originalName = null): ?array
    {
        $absolute = self::absolutePath($relative);
        if ($absolute === null) {
            return null;
        }

        return [
            'mime' => self::detectMime($absolute) ?? 'application/octet-stream',
            'size' => (int) filesize($absolute),
            'name' => $originalName !== null && $originalName !== '' ? $originalName : basename($absolute),
        ];
    }

    /** Hapus berkas (mis. ketika penyimpanan transaksi gagal). Tidak melempar exception. */
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
        return (int) (self::MAX_BYTES / 1048576) . ' MB';
    }

    private static function basePath(): string
    {
        return (string) App::config('paths.uploads', BASE_PATH . '/storage/private_uploads');
    }

    /** Nama berkas asli hanya untuk keterangan: buang path, karakter kendali, dan batasi panjang. */
    private static function sanitizeName(string $name): string
    {
        $name = str_replace(['\\', '/'], ' ', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = MasterRules::text($name);
        if ($name === '') {
            $name = 'bukti';
        }
        return mb_substr($name, 0, 200);
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

    /**
     * Di web: wajib berasal dari unggahan HTTP. Di CLI (pengujian/otomatisasi)
     * berkas disiapkan sendiri oleh proses, sehingga pemeriksaan dilonggarkan.
     */
    private static function isTrustedUpload(string $tmp): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        return is_uploaded_file($tmp);
    }

    private static function moveFile(string $from, string $to): bool
    {
        if (PHP_SAPI === 'cli') {
            return @rename($from, $to);
        }
        return move_uploaded_file($from, $to);
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran bukti melebihi batas yang diizinkan server.',
            UPLOAD_ERR_PARTIAL                        => 'Unggahan bukti terputus. Silakan ulangi.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server tidak menyediakan folder sementara untuk unggahan.',
            UPLOAD_ERR_CANT_WRITE                     => 'Server gagal menulis berkas bukti.',
            UPLOAD_ERR_EXTENSION                      => 'Unggahan bukti dihentikan oleh ekstensi PHP.',
            default                                   => 'Berkas bukti gagal diunggah.',
        };
    }
}
