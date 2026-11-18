<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Penulis PDF minimal (native PHP, tanpa Composer/ekstensi tambahan).
 *
 * Dipakai untuk ekspor laporan Fase 5. Mendukung:
 * - A4 potret / lanskap, kop identitas bengkel, ringkasan statistik,
 * - tabel multi-halaman dengan header berulang, teks terbungkus,
 * - nomor halaman, catatan kaki, dan encoding Windows-1252
 *   (karakter Indonesia pada umumnya; simbol di luar itu ditransliterasi).
 *
 * Berkas dihasilkan TIDAK terkompresi agar mudah diuji dan diperiksa.
 * PDF hanya memuat teks/tabel — tidak ada JavaScript maupun lampiran.
 */
final class PdfService
{
    private const MARGIN = 30.0;
    private const BOTTOM = 44.0;

    /** Lebar karakter Helvetica (1/1000 em) untuk ASCII 32–126. */
    private const HELVETICA_WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /**
     * Bangun PDF laporan.
     *
     * @param array{
     *   identity: array{name:string,address:string,phone:string,email:string,note?:string},
     *   title: string,
     *   subtitle?: string,
     *   summary?: array<int,array{label:string,value:string}>,
     *   columns: array<int,array{label:string,width?:int,align?:string}>,
     *   rows: array<int,array<int,string>>,
     *   notes?: array<int,string>,
     *   orientation?: string,
     *   footer_left?: string
     * } $options
     */
    public static function report(array $options): string
    {
        $columns = array_values($options['columns'] ?? []);
        if ($columns === []) {
            throw new RuntimeException('PDF laporan membutuhkan minimal satu kolom.');
        }

        $orientation = ($options['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $pageW = $orientation === 'portrait' ? 595.28 : 841.89;
        $pageH = $orientation === 'portrait' ? 841.89 : 595.28;

        $rows = array_values($options['rows'] ?? []);
        $summary = array_values($options['summary'] ?? []);
        $notes = array_values(array_filter($options['notes'] ?? [], static fn ($n): bool => trim((string) $n) !== ''));
        $notesTitle = (string) ($options['notes_title'] ?? 'Catatan');
        $identity = $options['identity'] ?? ['name' => 'SmartPartMan', 'address' => '', 'phone' => '', 'email' => ''];
        $title = (string) ($options['title'] ?? 'Laporan');
        $subtitle = trim((string) ($options['subtitle'] ?? ''));
        $footerLeft = (string) ($options['footer_left'] ?? ('Dicetak ' . tanggal_id(date('Y-m-d'), true)));

        $available = $pageW - (self::MARGIN * 2);
        $widths = self::normalizeWidths($columns, $available);

        $pages = [];
        $content = [];
        $cursor = 0.0;

        $drawHeader = static function (bool $continued) use (&$content, &$cursor, $pageW, $pageH, $identity, $title, $subtitle): void {
            $top = $pageH - self::MARGIN;
            $content[] = self::text(self::MARGIN, $top - 10, (string) $identity['name'], 14, true, [0.086, 0.188, 0.361]);
            $line2 = trim(implode(' · ', array_filter([
                (string) ($identity['address'] ?? ''),
                (string) ($identity['phone'] ?? ''),
                (string) ($identity['email'] ?? ''),
            ])));
            if ($line2 !== '') {
                $content[] = self::text(self::MARGIN, $top - 22, $line2, 7.5, false, [0.42, 0.45, 0.47]);
            }

            $titleText = $title . ($continued ? ' (lanjutan)' : '');
            $titleWidth = self::textWidth($titleText, 12, true);
            $content[] = self::text($pageW - self::MARGIN - $titleWidth, $top - 10, $titleText, 12, true, [0.086, 0.188, 0.361]);
            if ($subtitle !== '') {
                $subtitleWidth = self::textWidth($subtitle, 8, false);
                $content[] = self::text($pageW - self::MARGIN - $subtitleWidth, $top - 22, $subtitle, 8, false, [0.42, 0.45, 0.47]);
            }

            $content[] = self::line(self::MARGIN, $top - 30, $pageW - self::MARGIN, $top - 30, [0.086, 0.188, 0.361], 0.8);
            $cursor = $top - 40;
        };

        $drawSummary = static function () use (&$content, &$cursor, $pageW, $summary): void {
            if ($summary === []) {
                return;
            }
            $text = implode('   |   ', array_map(
                static fn (array $item): string => (string) $item['label'] . ': ' . (string) $item['value'],
                $summary
            ));
            $lines = self::wrap($text, $pageW - (self::MARGIN * 2), 8.5, false);
            foreach ($lines as $line) {
                $content[] = self::text(self::MARGIN, $cursor, $line, 8.5, false, [0.20, 0.23, 0.25]);
                $cursor -= 12;
            }
            $cursor -= 4;
        };

        $drawTableHeader = static function () use (&$content, &$cursor, $columns, $widths, $pageW): void {
            $height = 18.0;
            $content[] = self::rect(self::MARGIN, $cursor - $height, $pageW - (self::MARGIN * 2), $height, [0.086, 0.188, 0.361], true);
            $x = self::MARGIN;
            foreach ($columns as $i => $column) {
                $label = (string) ($column['label'] ?? '');
                $align = (string) ($column['align'] ?? 'left');
                $width = $widths[$i];
                $content[] = self::cellText($label, $x, $cursor - 12.5, $width, 8, true, $align, [1, 1, 1]);
                $x += $width;
            }
            $cursor -= $height;
        };

        $newPage = static function (bool $first) use (&$pages, &$content, &$cursor, $drawHeader, $drawSummary, $drawTableHeader, $summary): void {
            if ($content !== []) {
                $pages[] = $content;
            }
            $content = [];
            $drawHeader(!$first);
            if ($first) {
                $drawSummary();
            }
            $drawTableHeader();
        };

        $newPage(true);

        if ($rows === []) {
            $content[] = self::text(self::MARGIN, $cursor - 16, 'Tidak ada data pada filter ini.', 9, false, [0.42, 0.45, 0.47]);
            $cursor -= 28;
        }

        $rowIndex = 0;
        foreach ($rows as $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $i => $column) {
                $value = (string) ($row[$i] ?? '');
                $lines = self::wrap($value, $widths[$i] - 8, 8, false);
                $lines = $lines === [] ? [''] : $lines;
                $cells[$i] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $rowHeight = max(13.0, ($maxLines * 9.6) + 5.0);

            if ($cursor - $rowHeight < self::BOTTOM + 12) {
                $newPage(false);
            }

            $fill = $rowIndex % 2 === 1;
            if ($fill) {
                $content[] = self::rect(self::MARGIN, $cursor - $rowHeight, $pageW - (self::MARGIN * 2), $rowHeight, [0.965, 0.969, 0.976], true);
            }
            $x = self::MARGIN;
            $textTop = $cursor - 10.5;
            foreach ($columns as $i => $column) {
                $align = (string) ($column['align'] ?? 'left');
                foreach ($cells[$i] as $lineNo => $line) {
                    $content[] = self::cellText($line, $x, $textTop - ($lineNo * 9.6), $widths[$i], 8, false, $align, [0.15, 0.17, 0.19]);
                }
                $x += $widths[$i];
            }
            $content[] = self::line(self::MARGIN, $cursor - $rowHeight, $pageW - self::MARGIN, $cursor - $rowHeight, [0.87, 0.89, 0.91], 0.4);
            $cursor -= $rowHeight;
            $rowIndex++;
        }

        if ($notes !== []) {
            $needed = 18;
            foreach ($notes as $note) {
                $needed += count(self::wrap($note, $pageW - (self::MARGIN * 2), 7.5, false)) * 10;
            }
            if ($cursor - $needed < self::BOTTOM + 8) {
                $newPage(false);
            }
            $cursor -= 8;
            $content[] = self::text(self::MARGIN, $cursor, $notesTitle, 8, true, [0.086, 0.188, 0.361]);
            $cursor -= 12;
            foreach ($notes as $note) {
                foreach (self::wrap($note, $pageW - (self::MARGIN * 2), 7.5, false) as $line) {
                    $content[] = self::text(self::MARGIN, $cursor, $line, 7.5, false, [0.42, 0.45, 0.47]);
                    $cursor -= 10;
                }
            }
        }

        if ($content !== []) {
            $pages[] = $content;
        }

        // Footer setiap halaman (butuh jumlah halaman final).
        $total = count($pages);
        foreach ($pages as $index => $pageContent) {
            $footerY = 24;
            $pageContent[] = self::line(
                self::MARGIN,
                34,
                $pageW - self::MARGIN,
                34,
                [0.87, 0.89, 0.91],
                0.4
            );
            $pageContent[] = self::text(self::MARGIN, $footerY, $footerLeft, 7, false, [0.48, 0.51, 0.53]);
            $pageLabel = 'Halaman ' . ($index + 1) . ' dari ' . $total;
            $pageContent[] = self::text(
                $pageW - self::MARGIN - self::textWidth($pageLabel, 7, false),
                $footerY,
                $pageLabel,
                7,
                false,
                [0.48, 0.51, 0.53]
            );
            $pages[$index] = $pageContent;
        }

        return self::assemble($pages, $pageW, $pageH, $title);
    }

    /**
     * @param array<int,array{width?:int}> $columns
     * @return array<int,float>
     */
    private static function normalizeWidths(array $columns, float $available): array
    {
        $requested = [];
        foreach ($columns as $column) {
            $requested[] = max(1, (int) ($column['width'] ?? 1));
        }
        $sum = array_sum($requested);
        return array_map(static fn (int $w): float => ($w / $sum) * $available, $requested);
    }

    /** @param array<int,string> $lines */
    private static function assemble(array $pages, float $pageW, float $pageH, string $title): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next = 5;
        $kids = [];
        foreach ($pages as $lines) {
            $stream = implode("\n", $lines);
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $pageW,
                $pageH,
                $contentId
            );
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';

        $infoId = $next;
        $objects[$infoId] = '<< /Title (' . self::escape(self::encode($title)) . ') /Producer (SmartPartMan) /Creator (SmartPartMan)'
            . ' /CreationDate (D:' . date('YmdHis') . "+07'00') >>";

        ksort($objects);
        $maxId = max(array_keys($objects));

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R /Info " . $infoId . " 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    /** @return array<int,string> */
    private static function wrap(string $text, float $width, float $size, bool $bold): array
    {
        $text = trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text));
        if ($text === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (self::textWidth($candidate, $size, $bold) <= $width) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            // Kata tunggal lebih lebar dari kolom → potong paksa.
            while (self::textWidth($word, $size, $bold) > $width) {
                $chunk = '';
                $length = mb_strlen($word);
                for ($i = 0; $i < $length; $i++) {
                    $next = $chunk . mb_substr($word, $i, 1);
                    if (self::textWidth($next, $size, $bold) > $width && $chunk !== '') {
                        break;
                    }
                    $chunk = $next;
                }
                if ($chunk === '') {
                    break;
                }
                $lines[] = $chunk;
                $word = mb_substr($word, mb_strlen($chunk));
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines === [] ? [''] : $lines;
    }

    private static function textWidth(string $text, float $size, bool $bold): float
    {
        $text = self::encode($text);
        $units = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($text[$i]);
            $units += self::HELVETICA_WIDTHS[$ord - 32] ?? 556;
        }
        $factor = $bold ? 1.03 : 1.0;
        return ($units / 1000) * $size * $factor;
    }

    /** @param array<int,float> $color */
    private static function text(float $x, float $y, string $text, float $size, bool $bold, array $color): string
    {
        return sprintf(
            "BT\n/%s %.2f Tf\n%.3f %.3f %.3f rg\n1 0 0 1 %.2f %.2f Tm\n(%s) Tj\nET",
            $bold ? 'F2' : 'F1',
            $size,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            self::escape(self::encode($text))
        );
    }

    /** @param array<int,float> $color */
    private static function cellText(string $text, float $x, float $y, float $width, float $size, bool $bold, string $align, array $color): string
    {
        $textWidth = self::textWidth($text, $size, $bold);
        $offset = 4.0;
        if ($align === 'right') {
            $offset = max(4.0, $width - $textWidth - 4.0);
        } elseif ($align === 'center') {
            $offset = max(4.0, ($width - $textWidth) / 2);
        }
        return self::text($x + $offset, $y, $text, $size, $bold, $color);
    }

    /** @param array<int,float> $fill @param array<int,float> $stroke */
    private static function rect(float $x, float $y, float $w, float $h, array $fill, bool $filled): string
    {
        return sprintf(
            "%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re\n%s",
            $fill[0],
            $fill[1],
            $fill[2],
            $x,
            $y,
            $w,
            $h,
            $filled ? 'f' : 'S'
        );
    }

    /** @param array<int,float> $color */
    private static function line(float $x1, float $y1, float $x2, float $y2, array $color, float $width): string
    {
        return sprintf(
            "%.3f %.3f %.3f RG\n%.2f w\n%.2f %.2f m %.2f %.2f l\nS",
            $color[0],
            $color[1],
            $color[2],
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    private static function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        }
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }
        return (string) $converted;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }
}
