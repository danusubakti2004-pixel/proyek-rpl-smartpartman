<?php
declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * Fasad pembacaan berkas tabular + pemeriksaan berkas unggahan (FR-04).
 *
 * Aturan keamanan berkas:
 *  - Hanya .csv dan .xlsx.
 *  - Ukuran maksimal 2 MB.
 *  - .xlsx wajib ber-tanda tangan ZIP (PK..) → berkas yang diganti nama
 *    atau rusak ditolak; .csv menolak isi biner.
 *  - Nama berkas TIDAK dipakai sebagai path (hanya ditampilkan).
 */
final class Spreadsheet
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 2097152; // 2 MB

    /** @var string[] */
    public const ALLOWED_EXTENSIONS = ['csv', 'xlsx'];

    /**
     * Baca berkas yang sudah ada di disk (dipakai juga oleh pengujian).
     *
     * @return array{headers:array<int,string>, rows:array<int,array<int,string>>}
     */
    public static function readFile(string $path, string $extension, int $maxRows = self::MAX_ROWS): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Berkas tidak ditemukan.');
        }
        $maxRows = max(1, $maxRows);

        return match (strtolower($extension)) {
            'csv'   => CsvReader::read($path, $maxRows),
            'xlsx'  => XlsxReader::read($path, $maxRows),
            default => throw new RuntimeException('Format berkas tidak didukung. Gunakan .csv atau .xlsx.'),
        };
    }

    /**
     * Periksa + baca berkas unggahan ($_FILES[...]).
     *
     * @param array<string,mixed> $file
     * @return array{headers:array<int,string>, rows:array<int,array<int,string>>, name:string, extension:string, size:int}
     */
    public static function readUploaded(array $file, int $maxRows = self::MAX_ROWS): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || (PHP_SAPI !== 'cli' && !is_uploaded_file($temporary))) {
            throw new RuntimeException('Berkas unggahan tidak sah. Coba unggah ulang.');
        }
        if ($size <= 0) {
            throw new RuntimeException('Berkas kosong tidak dapat diimpor.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran berkas maksimal ' . self::formatBytes(self::MAX_BYTES) . '.');
        }

        $extension = self::extensionOf($name);
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Format berkas harus .csv atau .xlsx.');
        }
        self::assertSignature($temporary, $extension);

        $data = self::readFile($temporary, $extension, $maxRows) + [
            'name'      => $name,
            'extension' => $extension,
            'size'      => $size,
        ];
        /** @var array{headers:array<int,string>, rows:array<int,array<int,string>>, name:string, extension:string, size:int} $data */
        return $data;
    }

    public static function extensionOf(string $filename): string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return $extension;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024) . ' KB';
    }

    private static function assertSignature(string $path, string $extension): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Berkas tidak dapat dibaca.');
        }
        $head = (string) fread($handle, 2048);
        fclose($handle);

        if ($head === '') {
            throw new RuntimeException('Berkas kosong tidak dapat diimpor.');
        }
        if ($extension === 'xlsx') {
            if (!str_starts_with($head, "PK\x03\x04")) {
                throw new RuntimeException('Berkas .xlsx rusak atau bukan berkas Excel yang sah.');
            }
            return;
        }
        if (str_contains($head, "\0")) {
            throw new RuntimeException('Berkas CSV tidak valid (terdeteksi isi biner).');
        }
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Berkas terlalu besar untuk diunggah (melebihi batas server).',
            UPLOAD_ERR_PARTIAL  => 'Berkas hanya terunggah sebagian. Coba unggah ulang.',
            UPLOAD_ERR_NO_FILE  => 'Pilih berkas .csv atau .xlsx terlebih dahulu.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server tidak dapat menyimpan berkas sementara.',
            default             => 'Berkas gagal diunggah (kode ' . $error . ').',
        };
    }
}
