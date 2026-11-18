<?php
declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * Pembaca berkas CSV (FR-04).
 *
 * - Mendeteksi pemisah kolom otomatis (koma / titik koma / tab),
 *   karena Excel berbahasa Indonesia sering mengekspor dengan ";".
 * - Membuang BOM UTF-8 dan mengubah Windows-1252 menjadi UTF-8.
 * - Menghormati tanda kutip (nilai yang memuat pemisah/baris baru).
 */
final class CsvReader
{
    /**
     * @return array{headers:array<int,string>, rows:array<int,array<int,string>>}
     */
    public static function read(string $path, int $maxRows): array
    {
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('Berkas CSV kosong atau tidak dapat dibaca.');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        // Buang BOM.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $delimiter = self::detectDelimiter($content);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Gagal menyiapkan pembacaan CSV.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $matrix = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue; // baris kosong
            }
            $matrix[] = array_map(
                static fn ($cell): string => trim((string) ($cell ?? '')),
                $cells
            );
            if (count($matrix) > $maxRows + 1) {
                fclose($handle);
                throw new RuntimeException('Berkas berisi lebih dari ' . $maxRows . ' baris data. Pecah berkas lalu impor bertahap.');
            }
        }
        fclose($handle);

        if ($matrix === []) {
            throw new RuntimeException('Berkas CSV tidak berisi baris data.');
        }

        $headers = array_shift($matrix);
        return ['headers' => $headers, 'rows' => $matrix];
    }

    private static function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n");
        if ($firstLine === false) {
            return ',';
        }
        $counts = [
            ','  => substr_count($firstLine, ','),
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return ($counts[$best] ?? 0) > 0 ? (string) $best : ',';
    }
}
