<?php
declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;
use ZipArchive;

/**
 * Penulis berkas Excel .xlsx minimal (native PHP + ZipArchive) untuk
 * template unduhan (FR-04: "Menyediakan template kolom yang dapat diunduh").
 *
 * Semua sel teks ditulis sebagai inline string agar tidak perlu
 * sharedStrings.xml — struktur tetap sah dibuka Excel/LibreOffice.
 */
final class XlsxWriter
{
    public const SHEET_NAME = 'Template';

    /**
     * @param array<int,array<int,string|int|float>> $rows Baris pertama = header.
     * @param string $sheetName Nama sheet (Fase 5: dipakai untuk laporan).
     * @return string Isi berkas .xlsx (biner)
     */
    public static function build(array $rows, string $sheetName = self::SHEET_NAME): string
    {
        $sheetName = self::sheetName($sheetName);
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Ekstensi PHP "zip" belum aktif sehingga template Excel (.xlsx) belum dapat dibuat. '
                . 'Aktifkan extension=zip (docs/INSTALL.md §4) atau unduh template CSV.'
            );
        }

        $temporary = tempnam(sys_get_temp_dir(), 'spmxlsx');
        if ($temporary === false) {
            throw new RuntimeException('Gagal membuat berkas sementara untuk template Excel.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal menyiapkan berkas template Excel.');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($rows));
            $zip->close();

            $binary = file_get_contents($temporary);
            if ($binary === false) {
                throw new RuntimeException('Gagal membaca berkas template Excel.');
            }
            return $binary;
        } finally {
            @unlink($temporary);
        }
    }

    /** @param array<int,array<int,string|int|float>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach (array_values($rows) as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach (array_values($cells) as $columnIndex => $value) {
                $reference = self::columnLetters($columnIndex) . $rowNumber;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $reference . '"><v>' . $value . '</v></c>';
                    continue;
                }
                $text = (string) $value;
                if ($text === '') {
                    continue;
                }
                $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::escape($text) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    /** Batasi nama sheet sesuai aturan Excel (maks. 31 karakter, tanpa []:*?/\\). */
    private static function sheetName(string $name): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return self::SHEET_NAME;
        }
        return mb_substr($name, 0, 31, 'UTF-8');
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private static function columnLetters(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }
        return $letters;
    }

    private static function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $escaped) ?? $escaped;
    }
}
