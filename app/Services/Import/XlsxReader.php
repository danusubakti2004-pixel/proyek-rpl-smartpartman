<?php
declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Pembaca berkas Excel .xlsx tanpa pustaka eksternal (native PHP + ZipArchive).
 *
 * .xlsx = arsip ZIP berisi XML. Yang dibaca:
 *   xl/sharedStrings.xml          → tabel teks bersama
 *   xl/workbook.xml + _rels       → lokasi sheet pertama
 *   xl/worksheets/sheetN.xml      → sel
 *
 * Membutuhkan ekstensi `zip` (lihat docs/INSTALL.md §4). Bila belum aktif,
 * pesan galat dibuat jelas agar pengguna tahu harus menghubungi Admin/IT.
 */
final class XlsxReader
{
    /**
     * @return array{headers:array<int,string>, rows:array<int,array<int,string>>}
     */
    public static function read(string $path, int $maxRows): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Ekstensi PHP "zip" belum aktif sehingga berkas Excel (.xlsx) belum dapat dibaca. '
                . 'Aktifkan extension=zip (docs/INSTALL.md §4) atau gunakan format CSV.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Berkas Excel (.xlsx) rusak atau bukan berkas Excel yang sah.');
        }

        try {
            $sharedStrings = self::sharedStrings($zip);
            $sheetPath = self::firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('Struktur berkas Excel tidak dikenali (sheet pertama tidak ditemukan).');
            }
            $matrix = self::parseSheet($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }

        // Rapikan: buang baris kosong di awal/akhir dan baris yang seluruh selnya kosong.
        $matrix = array_values(array_filter(
            $matrix,
            static fn (array $row): bool => trim(implode('', $row)) !== ''
        ));

        if ($matrix === []) {
            throw new RuntimeException('Berkas Excel tidak berisi baris data.');
        }
        if (count($matrix) > $maxRows + 1) {
            throw new RuntimeException('Berkas berisi lebih dari ' . $maxRows . ' baris data. Pecah berkas lalu impor bertahap.');
        }

        $headers = array_map('trim', array_shift($matrix));
        return ['headers' => $headers, 'rows' => $matrix];
    }

    /** @return array<int,string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $simple = self::xml($xml);
        if ($simple === null) {
            return [];
        }
        $out = [];
        foreach ($simple->si as $item) {
            $text = '';
            foreach ($item->t as $t) {
                $text .= (string) $t;
            }
            foreach ($item->r as $run) {
                foreach ($run->t as $t) {
                    $text .= (string) $t;
                }
            }
            $out[] = $text;
        }
        return $out;
    }

    /** @return array<int,array<int,string>> */
    private static function parseSheet(string $xml, array $sharedStrings): array
    {
        $simple = self::xml($xml);
        if ($simple === null) {
            throw new RuntimeException('Isi berkas Excel tidak dapat dibaca (XML tidak valid).');
        }
        if (!isset($simple->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($simple->sheetData->row as $row) {
            $cells = [];
            $index = 0;
            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                if ($ref !== '' && preg_match('/^([A-Za-z]+)\d+$/', $ref, $m) === 1) {
                    $index = self::columnIndex($m[1]);
                }
                $cells[$index] = self::cellValue($cell, $sharedStrings);
                $index++;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /** @return array<int,string> */
    private static function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->is->t as $t) {
                $text .= (string) $t;
            }
            return $text;
        }
        $value = (string) ($cell->v ?? '');
        if ($type === 's') {
            $key = (int) $value;
            return $sharedStrings[$key] ?? '';
        }
        return $value;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false
            && preg_match('/<sheet[^>]*>/i', $workbook, $sheetTag) === 1
            && preg_match('/r:id="([^"]+)"/i', $sheetTag[0], $id) === 1) {
            $rid = $id[1];
            if (preg_match('/<Relationship[^>]*Id="' . preg_quote($rid, '/') . '"[^>]*>/i', $rels, $rel) === 1
                && preg_match('/Target="([^"]+)"/i', $rel[0], $target) === 1) {
                $path = ltrim($target[1], '/');
                return str_starts_with($path, 'xl/') ? $path : 'xl/' . $path;
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** Hilangkan deklarasi & prefiks namespace agar SimpleXML mudah dipakai. */
    private static function xml(string $xml): ?SimpleXMLElement
    {
        $xml = preg_replace('/\sxmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', $xml) ?? $xml;
        $xml = preg_replace('/(<\/?)[A-Za-z0-9_]+:/', '$1', $xml) ?? $xml;
        $xml = preg_replace('/\s[A-Za-z0-9_]+:([A-Za-z0-9_]+)=/', ' $1=', $xml) ?? $xml;

        $previous = libxml_use_internal_errors(true);
        $simple = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $simple === false ? null : $simple;
    }

    /** "A" → 0, "B" → 1, "AA" → 26. */
    private static function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }
}
