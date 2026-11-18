<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Grafik SVG sisi server (tanpa CDN / JavaScript).
 *
 * Alasan: aplikasi harus tetap tampil di jaringan lokal tanpa internet,
 * grafik harus ikut tercetak rapi, dan tidak boleh menambah dependensi
 * frontend baru. SVG dengan viewBox otomatis responsif di HP/desktop.
 *
 * Format data:
 *   labels : ['2026-10-01', ...]
 *   series : [['label' => 'Masuk', 'values' => [1, 2], 'color' => '#198754'], ...]
 */
final class ChartService
{
    private const WIDTH = 760;
    private const PALETTE = ['#16305c', '#d92d20', '#198754', '#f0ad4e', '#6f42c1', '#0dcaf0', '#6c757d'];

    /**
     * Grafik batang (boleh beberapa seri, dikelompokkan).
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,values:array<int,float|int>,color?:string}> $series
     * @param array{height?:int,money?:bool,empty?:string} $options
     */
    public static function bar(array $labels, array $series, array $options = []): string
    {
        return self::render('bar', $labels, $series, $options);
    }

    /**
     * Grafik garis (boleh beberapa seri).
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,values:array<int,float|int>,color?:string}> $series
     * @param array{height?:int,money?:bool,empty?:string} $options
     */
    public static function line(array $labels, array $series, array $options = []): string
    {
        return self::render('line', $labels, $series, $options);
    }

    /**
     * @param array<int,string> $labels
     * @param array<int,array{label:string,values:array<int,float|int>,color?:string}> $series
     * @param array{height?:int,money?:bool,empty?:string} $options
     */
    private static function render(string $kind, array $labels, array $series, array $options): string
    {
        $labels = array_values($labels);
        $series = array_values(array_filter($series, static fn (array $s): bool => isset($s['values'])));
        $money = (bool) ($options['money'] ?? false);
        $emptyMessage = (string) ($options['empty'] ?? 'Belum ada data untuk digrafikkan.');

        if ($labels === [] || $series === []) {
            return self::emptyState($emptyMessage);
        }

        $max = 0.0;
        foreach ($series as $item) {
            foreach ($item['values'] as $value) {
                $max = max($max, (float) $value);
            }
        }
        if ($max <= 0) {
            return self::emptyState($emptyMessage);
        }

        $height = max(200, min(420, (int) ($options['height'] ?? 280)));
        $left = 60.0;
        $right = 18.0;
        $top = 34.0;
        $bottom = 48.0;
        $plotW = self::WIDTH - $left - $right;
        $plotH = $height - $top - $bottom;
        $axisMax = self::niceMax($max);
        $count = count($labels);
        $step = $count <= 0 ? 1 : $plotW / $count;

        $svg = [];
        $svg[] = sprintf(
            '<svg class="sp-chart-svg" viewBox="0 0 %d %d" role="img" aria-label="%s" xmlns="http://www.w3.org/2000/svg">',
            self::WIDTH,
            $height,
            e((string) ($options['title'] ?? 'Grafik'))
        );

        // Legend.
        $legendX = $left;
        foreach ($series as $i => $item) {
            $color = self::color($item, $i);
            $svg[] = sprintf(
                '<rect x="%.1f" y="8" width="12" height="10" rx="2" fill="%s"></rect>',
                $legendX,
                $color
            );
            $svg[] = sprintf(
                '<text x="%.1f" y="17" font-size="10" fill="#495057">%s</text>',
                $legendX + 17,
                e($item['label'])
            );
            $legendX += 30 + (mb_strlen($item['label']) * 6);
        }

        // Grid + label sumbu Y.
        $gridLines = 4;
        for ($g = 0; $g <= $gridLines; $g++) {
            $ratio = $g / $gridLines;
            $y = $top + $plotH - ($plotH * $ratio);
            $value = $axisMax * $ratio;
            $svg[] = sprintf(
                '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#e9ecef" stroke-width="1"></line>',
                $left,
                $y,
                $left + $plotW,
                $y
            );
            $svg[] = sprintf(
                '<text x="%.1f" y="%.1f" font-size="9" fill="#6c757d" text-anchor="end">%s</text>',
                $left - 7,
                $y + 3,
                e(self::formatAxis($value, $money))
            );
        }

        if ($kind === 'bar') {
            $seriesCount = max(1, count($series));
            $groupInner = $step * 0.72;
            $barWidth = max(2.0, $groupInner / $seriesCount);

            foreach ($labels as $index => $label) {
                $groupX = $left + ($step * $index) + (($step - $groupInner) / 2);
                foreach ($series as $sIndex => $item) {
                    $value = (float) ($item['values'][$index] ?? 0);
                    $barH = $axisMax > 0 ? ($value / $axisMax) * $plotH : 0;
                    $x = $groupX + ($sIndex * $barWidth);
                    $y = $top + $plotH - $barH;
                    $svg[] = sprintf(
                        '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1.5" fill="%s"><title>%s: %s</title></rect>',
                        $x,
                        $y,
                        max(0.0, $barWidth - 1.5),
                        max(0.0, $barH),
                        self::color($item, $sIndex),
                        e($label . ' — ' . $item['label']),
                        e(self::formatValue($value, $money))
                    );
                }
            }
        } else {
            foreach ($series as $sIndex => $item) {
                $points = [];
                foreach ($labels as $index => $label) {
                    $value = (float) ($item['values'][$index] ?? 0);
                    $x = $left + ($step * $index) + ($step / 2);
                    $y = $top + $plotH - (($value / $axisMax) * $plotH);
                    $points[] = [$x, $y, $label, $value];
                }
                $polyline = implode(' ', array_map(
                    static fn (array $p): string => sprintf('%.2f,%.2f', $p[0], $p[1]),
                    $points
                ));
                $svg[] = sprintf(
                    '<polyline points="%s" fill="none" stroke="%s" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"></polyline>',
                    $polyline,
                    self::color($item, $sIndex)
                );
                foreach ($points as $p) {
                    $svg[] = sprintf(
                        '<circle cx="%.2f" cy="%.2f" r="2.8" fill="%s"><title>%s: %s</title></circle>',
                        $p[0],
                        $p[1],
                        self::color($item, $sIndex),
                        e($p[2] . ' — ' . $item['label']),
                        e(self::formatValue($p[3], $money))
                    );
                }
            }
        }

        // Label sumbu X (dibatasi agar tidak bertumpuk).
        $labelStep = max(1, (int) ceil($count / 12));
        foreach ($labels as $index => $label) {
            if ($index % $labelStep !== 0 && $index !== $count - 1) {
                continue;
            }
            $x = $kind === 'bar'
                ? $left + ($step * $index) + ($step / 2)
                : $left + ($step * $index) + ($step / 2);
            $short = mb_strlen($label) > 12 ? mb_substr($label, 0, 11) . '…' : $label;
            $svg[] = sprintf(
                '<text x="%.1f" y="%.1f" font-size="9" fill="#495057" text-anchor="middle">%s<title>%s</title></text>',
                $x,
                $top + $plotH + 16,
                e($short),
                e($label)
            );
        }

        $svg[] = sprintf(
            '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#adb5bd" stroke-width="1"></line>',
            $left,
            $top + $plotH,
            $left + $plotW,
            $top + $plotH
        );
        $svg[] = '</svg>';

        return '<div class="sp-chart">' . implode('', $svg) . '</div>';
    }

    /** @param array{label:string,values:array<int,float|int>,color?:string} $item */
    private static function color(array $item, int $index): string
    {
        $color = (string) ($item['color'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return $color;
        }
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    private static function niceMax(float $max): float
    {
        $exponent = (int) floor(log10($max));
        $base = 10 ** $exponent;
        $fraction = $max / $base;
        $nice = $fraction <= 1 ? 1 : ($fraction <= 2 ? 2 : ($fraction <= 5 ? 5 : 10));
        return (float) ($nice * $base);
    }

    private static function formatAxis(float $value, bool $money): string
    {
        if (!$money) {
            return number_format($value, 0, ',', '.');
        }
        if ($value >= 1_000_000_000) {
            return 'Rp ' . number_format($value / 1_000_000_000, 1, ',', '.') . ' M';
        }
        if ($value >= 1_000_000) {
            return 'Rp ' . number_format($value / 1_000_000, 1, ',', '.') . ' jt';
        }
        if ($value >= 1_000) {
            return 'Rp ' . number_format($value / 1_000, 0, ',', '.') . ' rb';
        }
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    private static function formatValue(float $value, bool $money): string
    {
        if ($money) {
            return 'Rp ' . number_format($value, 0, ',', '.');
        }
        $decimals = floor($value) === $value ? 0 : 2;
        return number_format($value, $decimals, ',', '.');
    }

    private static function emptyState(string $message): string
    {
        return '<div class="sp-chart-empty text-muted small text-center py-4">' . e($message) . '</div>';
    }
}
