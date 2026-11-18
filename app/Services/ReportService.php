<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AiPrediction;
use App\Models\Analytics;
use App\Models\Sparepart;
use RuntimeException;

/**
 * Laporan Fase 5 (baca-saja) + data untuk cetak/PDF/Excel.
 *
 * Prinsip:
 * - SEMUA laporan memakai prepared statement dan hanya membaca data.
 * - Filter divalidasi terhadap whitelist; nilai tak dikenal diabaikan.
 * - Ringkasan/grafik/tabel berasal dari SATU kumpulan baris yang sama,
 *   sehingga angka ringkasan selalu konsisten dengan tabel.
 * - Nilai persediaan memakai harga beli rata-rata tertimbang (weighted
 *   average) dengan fallback harga beli terakhir — keputusan Fase 4.
 *   Harga terakhir tetap ditampilkan sebagai pembanding, bukan dasar nilai.
 * - Fast/slow moving memakai ambang yang dapat dikonfigurasi Admin dan
 *   label aturannya ditampilkan pada laporan.
 *
 * Batas 5.000 baris per laporan (dengan pemberitahuan) menjaga agar
 * cetak/PDF/Excel tetap wajar; filter tanggal/kategori/search tersedia
 * untuk mempersempit data.
 */
final class ReportService
{
    public const MAX_ROWS = 5000;

    /** @var string[] */
    public const TYPES = [
        'stok', 'masuk', 'keluar', 'stok_minimum', 'opname', 'riwayat',
        'fast_slow', 'supplier', 'pembelian', 'penerimaan_po', 'pembayaran', 'ai',
    ];

    private const PO_STATUSES = [
        'DRAFT', 'MENUNGGU_PERSETUJUAN', 'DISETUJUI', 'DITOLAK',
        'DIPROSES_ADMIN', 'SEBAGIAN_DITERIMA', 'SELESAI_DITERIMA', 'DIBATALKAN',
    ];

    private const PAYMENT_STATUSES = ['BELUM_DIBAYAR', 'DIBAYAR_SEBAGIAN', 'LUNAS'];
    private const STOCK_ALERTS = ['habis', 'kritis', 'menipis', 'tanpa_minimum'];

    /**
     * Bangun satu laporan lengkap.
     *
     * @param array<string,mixed> $input Parameter query/filter.
     * @return array<string,mixed>
     */
    public static function build(string $type, array $input = []): array
    {
        $meta = self::meta($type);
        $filters = self::normalizeFilters($input, $meta);

        [$rows, $summary, $chart, $extraNotes] = match ($type) {
            'stok'          => self::stokReport($filters),
            'masuk'         => self::masukReport($filters),
            'keluar'        => self::keluarReport($filters),
            'stok_minimum'  => self::stokMinimumReport($filters),
            'opname'        => self::opnameReport($filters),
            'riwayat'       => self::riwayatReport($filters),
            'fast_slow'     => self::fastSlowReport($filters),
            'supplier'      => self::supplierReport($filters),
            'pembelian'     => self::pembelianReport($filters),
            'penerimaan_po' => self::penerimaanPoReport($filters),
            'pembayaran'    => self::pembayaranReport($filters),
            'ai'            => self::aiReport($filters),
            default         => throw new RuntimeException('Jenis laporan tidak dikenal.'),
        };

        $notes = array_merge($meta['notes'], $extraNotes);
        if (count($rows) > self::MAX_ROWS) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
            $notes[] = 'Data melebihi ' . number_format(self::MAX_ROWS, 0, ',', '.')
                . ' baris; yang ditampilkan hanya ' . number_format(self::MAX_ROWS, 0, ',', '.')
                . ' baris pertama. Persempit filter untuk melihat sisanya.';
        }

        return [
            'type'         => $type,
            'meta'         => $meta,
            'filters'      => $filters,
            'rows'         => $rows,
            'summary'      => $summary,
            'chart'        => $chart,
            'notes'        => $notes,
            'filter_text'  => self::filterText($type, $filters),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    public static function meta(string $type): array
    {
        $registry = self::registry();
        if (!isset($registry[$type])) {
            throw new RuntimeException('Jenis laporan tidak dikenal.');
        }
        return $registry[$type];
    }

    /** @return array<int,array<string,mixed>> */
    public static function allMeta(): array
    {
        $reports = array_values(self::registry());
        foreach ($reports as $index => $report) {
            if (($report['key'] ?? '') === 'ai') {
                $summary = AiPrediction::summary();
                $reports[$index]['badge'] = $summary['total'] > 0
                    ? ['class' => 'success', 'label' => number_format($summary['total'], 0, ',', '.') . ' sparepart dianalisis']
                    : ['class' => 'info', 'label' => 'Belum pernah dijalankan'];
            }
        }
        return $reports;
    }

    /**
     * Opsi filter kategori/supplier (termasuk nonaktif agar data historis
     * tetap dapat difilter).
     *
     * @return array{categories:array<int,array<string,mixed>>,suppliers:array<int,array<string,mixed>>}
     */
    public static function filterOptions(): array
    {
        return [
            'categories' => CategoryService::options(false),
            'suppliers'  => SupplierService::options(false),
        ];
    }

    /**
     * Label ramah pengguna untuk nilai status/kelompok.
     */
    public static function label(string $group, mixed $value): string
    {
        $key = (string) $value;
        $map = [
            'stock' => [
                'habis' => 'Habis', 'kritis' => 'Kritis', 'menipis' => 'Menipis',
                'aman' => 'Aman', 'tanpa_minimum' => 'Tanpa minimum',
            ],
            'po' => [
                'DRAFT' => 'Draf', 'MENUNGGU_PERSETUJUAN' => 'Menunggu persetujuan',
                'DISETUJUI' => 'Disetujui', 'DITOLAK' => 'Ditolak',
                'DIPROSES_ADMIN' => 'Diproses admin', 'SEBAGIAN_DITERIMA' => 'Diterima sebagian',
                'SELESAI_DITERIMA' => 'Selesai diterima', 'DIBATALKAN' => 'Dibatalkan',
            ],
            'payment' => [
                'BELUM_DIBAYAR' => 'Belum dibayar', 'DIBAYAR_SEBAGIAN' => 'Dibayar sebagian',
                'LUNAS' => 'Lunas',
            ],
            'opname' => ['OPEN' => 'Berjalan', 'CONFIRMED' => 'Terkonfirmasi'],
            'reorder' => [
                'DRAFT' => 'Draf', 'MENUNGGU_PERSETUJUAN' => 'Menunggu persetujuan',
                'DISETUJUI' => 'Disetujui', 'DITOLAK' => 'Ditolak',
            ],
            'usage' => ['service' => 'Servis', 'penjualan' => 'Penjualan', 'lainnya' => 'Lainnya'],
            'movement' => [
                'STOK_AWAL' => 'Stok awal', 'MASUK' => 'Masuk', 'KELUAR' => 'Keluar',
                'PENYESUAIAN_OPNAME' => 'Penyesuaian opname', 'KOREKSI' => 'Koreksi', 'PENYESUAIAN' => 'Penyesuaian stok',
            ],
            'receipt' => ['pembelian' => 'Pembelian', 'lainnya' => 'Lainnya'],
            'classification' => ['fast' => 'Cepat (fast moving)', 'medium' => 'Sedang', 'slow' => 'Lambat (slow moving)'],
            'active' => ['1' => 'Aktif', '0' => 'Nonaktif'],
            'yesno' => ['1' => 'Ya', '0' => 'Tidak'],
        ];

        return $map[$group][$key] ?? $key;
    }

    /** Kelas badge Bootstrap untuk nilai tertentu. */
    public static function badgeClass(string $group, mixed $value): string
    {
        $key = (string) $value;
        $map = [
            'stock' => [
                'habis' => 'danger', 'kritis' => 'danger', 'menipis' => 'warning',
                'aman' => 'success', 'tanpa_minimum' => 'info',
            ],
            'po' => [
                'DRAFT' => 'secondary', 'MENUNGGU_PERSETUJUAN' => 'warning', 'DISETUJUI' => 'info',
                'DITOLAK' => 'danger', 'DIPROSES_ADMIN' => 'primary', 'SEBAGIAN_DITERIMA' => 'warning',
                'SELESAI_DITERIMA' => 'success', 'DIBATALKAN' => 'secondary',
            ],
            'payment' => ['BELUM_DIBAYAR' => 'danger', 'DIBAYAR_SEBAGIAN' => 'warning', 'LUNAS' => 'success'],
            'opname' => ['OPEN' => 'warning', 'CONFIRMED' => 'success'],
            'usage' => ['service' => 'info', 'penjualan' => 'primary', 'lainnya' => 'secondary'],
            'movement' => [
                'STOK_AWAL' => 'secondary', 'MASUK' => 'success', 'KELUAR' => 'danger',
                'PENYESUAIAN_OPNAME' => 'info', 'KOREKSI' => 'warning', 'PENYESUAIAN' => 'secondary',
            ],
            'classification' => ['fast' => 'success', 'medium' => 'secondary', 'slow' => 'warning'],
            'active' => ['1' => 'success', '0' => 'secondary'],
        ];

        return $map[$group][$key] ?? 'secondary';
    }

    /** Format nilai sel untuk tampilan/ekspor. */
    public static function formatCell(array $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $type = (string) ($column['type'] ?? 'text');
        return match ($type) {
            'money'   => rupiah((float) ($value ?? 0)),
            'number'  => number_format((float) ($value ?? 0), 0, ',', '.'),
            'decimal' => self::qty((float) ($value ?? 0)),
            'date'    => tanggal_id($value === null ? null : (string) $value),
            'badge'   => self::label((string) ($column['group'] ?? ''), $value),
            default   => (string) ($value ?? ''),
        };
    }

    /**
     * Susun baris ekspor (judul, filter, ringkasan, header, data).
     *
     * @param array<string,mixed> $report
     * @return array<int,array<int,string|int|float>>
     */
    public static function exportRows(array $report): array
    {
        $rows = [];
        $rows[] = [(string) $report['meta']['title']];
        foreach ($report['filter_text'] as $line) {
            $rows[] = [$line];
        }
        $summary = [];
        foreach ($report['summary'] as $item) {
            $summary[] = $item['label'] . ': ' . $item['value'];
        }
        if ($summary !== []) {
            $rows[] = [implode('  |  ', $summary)];
        }
        $rows[] = [];

        $header = [];
        foreach ($report['meta']['columns'] as $column) {
            $header[] = (string) $column['label'];
        }
        $rows[] = $header;

        foreach ($report['rows'] as $row) {
            $line = [];
            foreach ($report['meta']['columns'] as $column) {
                $line[] = self::formatCell($column, $row[$column['key']] ?? '');
            }
            $rows[] = $line;
        }

        if ($report['notes'] !== []) {
            $rows[] = [];
            $rows[] = ['Catatan'];
            foreach ($report['notes'] as $note) {
                $rows[] = [(string) $note];
            }
        }

        return $rows;
    }

    /**
     * Opsi untuk PDF.
     *
     * @param array<string,mixed> $report
     * @param array{name:string,address:string,phone:string,email:string,note:string} $identity
     * @return array<string,mixed>
     */
    public static function pdfOptions(array $report, array $identity): array
    {
        $columns = [];
        foreach ($report['meta']['columns'] as $column) {
            $columns[] = [
                'label' => (string) $column['label'],
                'width' => (int) ($column['width'] ?? 20),
                'align' => (string) ($column['align'] ?? 'left'),
            ];
        }

        $rows = [];
        foreach ($report['rows'] as $row) {
            $line = [];
            foreach ($report['meta']['columns'] as $column) {
                $line[] = self::formatCell($column, $row[$column['key']] ?? '');
            }
            $rows[] = $line;
        }

        return [
            'identity'    => $identity,
            'title'       => (string) $report['meta']['title'],
            'subtitle'    => (string) ($report['filter_text'][0] ?? ''),
            'summary'     => $report['summary'],
            'columns'     => $columns,
            'rows'        => $rows,
            'notes'       => array_merge($report['filter_text'], $report['notes']),
            'notes_title' => 'Filter & catatan',
            'orientation' => (string) ($report['meta']['orientation'] ?? 'landscape'),
            'footer_left' => 'SmartPartMan · ' . $identity['name'] . ' · Dicetak ' . tanggal_id(date('Y-m-d'), true),
        ];
    }

    // ==================================================================
    // Query per laporan
    // ==================================================================

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function stokReport(array $f): array
    {
        [$sql, $params] = self::stockBaseQuery($f, false);
        $rows = self::query($sql . ' ORDER BY s.name ASC', $params);

        foreach ($rows as &$row) {
            $row['stock_status'] = Sparepart::stockStatusOf($row);
            $row['stock_percent'] = Sparepart::stockPercentOf($row);
            $row['avg_purchase_price'] = (float) ($row['avg_purchase_price'] ?? $row['purchase_price']);
            $row['value_last'] = (float) $row['stock'] * (float) $row['purchase_price'];
            $row['value_avg'] = (float) $row['stock'] * (float) $row['avg_purchase_price'];
            $row['status_data'] = ((int) $row['is_active'] === 1) ? '1' : '0';
        }
        unset($row);

        if ($f['status'] !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['stock_status'] === $f['status']
            ));
        }

        $statusCounts = array_fill_keys(Sparepart::STOCK_STATUSES, 0);
        $totalStock = 0.0;
        $valueLast = 0.0;
        $valueAvg = 0.0;
        $byCategory = [];
        foreach ($rows as $row) {
            $statusCounts[$row['stock_status']]++;
            $totalStock += (float) $row['stock'];
            $valueLast += (float) $row['value_last'];
            $valueAvg += (float) $row['value_avg'];
            $category = (string) ($row['category_name'] ?? '-');
            $byCategory[$category] = ($byCategory[$category] ?? 0.0) + (float) $row['value_avg'];
        }
        arsort($byCategory);
        $byCategory = array_slice($byCategory, 0, 8, true);

        $summary = [
            ['label' => 'Jumlah sparepart', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Total stok', 'value' => self::qty($totalStock), 'raw' => $totalStock],
            ['label' => 'Nilai harga terakhir', 'value' => rupiah($valueLast), 'raw' => $valueLast],
            ['label' => 'Nilai rata-rata tertimbang', 'value' => rupiah($valueAvg), 'raw' => $valueAvg],
            [
                'label' => 'Habis / Kritis / Menipis',
                'value' => $statusCounts['habis'] . ' / ' . $statusCounts['kritis'] . ' / ' . $statusCounts['menipis'],
                'raw'   => $statusCounts['habis'] + $statusCounts['kritis'] + $statusCounts['menipis'],
            ],
        ];

        $chart = [
            'kind'   => 'bar',
            'title'  => 'Nilai persediaan per kategori (rata-rata tertimbang)',
            'labels' => array_keys($byCategory),
            'series' => [[
                'label'  => 'Nilai persediaan',
                'values' => array_values($byCategory),
                'color'  => '#16305c',
            ]],
            'money'  => true,
        ];

        return [$rows, $summary, $chart, [
            'Nilai persediaan dihitung dari stok × harga beli rata-rata tertimbang (weighted average). '
            . 'Bila belum ada pembelian, dipakai harga beli terakhir sebagai fallback.',
            'Kolom harga terakhir ditampilkan sebagai pembanding; dasar nilai persediaan adalah harga rata-rata.',
            'Laporan ini menghormati filter status data (aktif/nonaktif). Dashboard hanya menghitung sparepart aktif.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function stokMinimumReport(array $f): array
    {
        $f['active'] = 'aktif';
        [$sql, $params] = self::stockBaseQuery($f, true);
        $rows = self::query($sql . ' ORDER BY s.name ASC', $params);

        foreach ($rows as &$row) {
            $row['stock_status'] = Sparepart::stockStatusOf($row);
            $row['stock_percent'] = Sparepart::stockPercentOf($row);
            $row['avg_purchase_price'] = (float) ($row['avg_purchase_price'] ?? $row['purchase_price']);
            $row['value_last'] = (float) $row['stock'] * (float) $row['purchase_price'];
            $row['value_avg'] = (float) $row['stock'] * (float) $row['avg_purchase_price'];
        }
        unset($row);

        if ($f['status'] !== '' && in_array($f['status'], self::STOCK_ALERTS, true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['stock_status'] === $f['status']
            ));
        } else {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['stock_status'], self::STOCK_ALERTS, true)
            ));
        }

        $severity = ['habis' => 0, 'kritis' => 1, 'menipis' => 2, 'tanpa_minimum' => 3];
        usort($rows, static function (array $a, array $b) use ($severity): int {
            $sa = $severity[$a['stock_status']] ?? 9;
            $sb = $severity[$b['stock_status']] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $counts = array_fill_keys(self::STOCK_ALERTS, 0);
        $value = 0.0;
        foreach ($rows as $row) {
            $counts[$row['stock_status']] = ($counts[$row['stock_status']] ?? 0) + 1;
            $value += (float) $row['value_avg'];
        }

        $summary = [
            ['label' => 'Sparepart perlu perhatian', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Habis', 'value' => (string) $counts['habis'], 'raw' => $counts['habis']],
            ['label' => 'Kritis', 'value' => (string) $counts['kritis'], 'raw' => $counts['kritis']],
            ['label' => 'Menipis', 'value' => (string) $counts['menipis'], 'raw' => $counts['menipis']],
            ['label' => 'Nilai persediaan terdampak', 'value' => rupiah($value), 'raw' => $value],
        ];

        $chart = [
            'kind'   => 'bar',
            'title'  => 'Jumlah sparepart per status stok',
            'labels' => ['Habis', 'Kritis', 'Menipis', 'Tanpa minimum'],
            'series' => [[
                'label'  => 'Jumlah',
                'values' => [$counts['habis'], $counts['kritis'], $counts['menipis'], $counts['tanpa_minimum']],
                'color'  => '#d92d20',
            ]],
            'money'  => false,
        ];

        return [$rows, $summary, $chart, [
            'Hanya sparepart AKTIF yang ditampilkan. Status mengikuti PRD §13.3: '
            . 'habis (stok 0), kritis (< minimum), menipis (minimum–150% minimum), '
            . 'tanpa minimum (stok minimum belum diatur).',
            'Notifikasi dashboard memakai aturan status yang sama.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function masukReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'r.receipt_date >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'r.receipt_date <= :to';
            $params[':to'] = $f['date_to'];
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 'r.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        if ($f['category_id'] !== null) {
            $where[] = 's.category_id = :category';
            $params[':category'] = $f['category_id'];
        }
        self::searchWhere($where, $params, $f['search'], [
            'r.receipt_number', 'r.invoice_number', 'sup.name', 's.code', 's.name',
        ]);

        $rows = self::query(
            "SELECT r.receipt_number, r.receipt_date, r.receipt_type, r.invoice_number,
                    COALESCE(sup.name, '-') AS supplier_name,
                    s.code, s.name, i.quantity, i.unit_price,
                    (i.quantity * i.unit_price) AS subtotal,
                    COALESCE(u.full_name, '-') AS recorded_by,
                    r.note
               FROM goods_receipt_items i
               JOIN goods_receipts r ON r.id = i.receipt_id
               JOIN spareparts s ON s.id = i.sparepart_id
          LEFT JOIN categories c ON c.id = s.category_id
          LEFT JOIN suppliers sup ON sup.id = r.supplier_id
          LEFT JOIN users u ON u.id = r.recorded_by
              WHERE " . implode(' AND ', $where) . '
           ORDER BY r.receipt_date DESC, r.id DESC, i.id ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $docs = [];
        $qty = 0.0;
        $value = 0.0;
        $daily = [];
        foreach ($rows as $row) {
            $docs[(string) $row['receipt_number']] = true;
            $qty += (float) $row['quantity'];
            $value += (float) $row['subtotal'];
            $day = (string) $row['receipt_date'];
            $daily[$day] = ($daily[$day] ?? 0.0) + (float) $row['quantity'];
        }
        ksort($daily);

        $summary = [
            ['label' => 'Dokumen penerimaan', 'value' => number_format(count($docs), 0, ',', '.'), 'raw' => count($docs)],
            ['label' => 'Total qty masuk', 'value' => self::qty($qty), 'raw' => $qty],
            ['label' => 'Total nilai pembelian', 'value' => rupiah($value), 'raw' => $value],
            ['label' => 'Baris item', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
        ];

        $chart = self::dailyChart($daily, 'Qty barang masuk per tanggal', 'Masuk', '#198754');

        return [$rows, $summary, $chart, [
            'Nilai baris = qty × harga satuan pada saat penerimaan (bukan harga master saat ini).',
            'Total nilai mencakup seluruh jenis penerimaan; hanya penerimaan bertipe "pembelian" yang memperbarui harga beli master.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function keluarReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'g.issue_date >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'g.issue_date <= :to';
            $params[':to'] = $f['date_to'];
        }
        if ($f['category_id'] !== null) {
            $where[] = 's.category_id = :category';
            $params[':category'] = $f['category_id'];
        }
        if ($f['usage'] !== '') {
            $where[] = 'g.usage_type = :usage';
            $params[':usage'] = $f['usage'];
        }
        self::searchWhere($where, $params, $f['search'], [
            'g.issue_number', 'g.spk_number', 'g.plate_number', 's.code', 's.name',
        ]);

        $rows = self::query(
            "SELECT g.issue_number, g.issue_date, g.usage_type, g.spk_number, g.plate_number,
                    s.code, s.name, i.quantity,
                    COALESCE(u.full_name, '-') AS recorded_by, g.note
               FROM goods_issue_items i
               JOIN goods_issues g ON g.id = i.issue_id
               JOIN spareparts s ON s.id = i.sparepart_id
          LEFT JOIN categories c ON c.id = s.category_id
          LEFT JOIN users u ON u.id = g.recorded_by
              WHERE " . implode(' AND ', $where) . '
           ORDER BY g.issue_date DESC, g.id DESC, i.id ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $docs = [];
        $qty = 0.0;
        $usage = ['service' => 0, 'penjualan' => 0, 'lainnya' => 0];
        $daily = [];
        foreach ($rows as $row) {
            $docs[(string) $row['issue_number']] = true;
            $qty += (float) $row['quantity'];
            $usage[(string) $row['usage_type']] = ($usage[(string) $row['usage_type']] ?? 0) + (float) $row['quantity'];
            $day = (string) $row['issue_date'];
            $daily[$day] = ($daily[$day] ?? 0.0) + (float) $row['quantity'];
        }
        ksort($daily);

        $summary = [
            ['label' => 'Dokumen pengeluaran', 'value' => number_format(count($docs), 0, ',', '.'), 'raw' => count($docs)],
            ['label' => 'Total qty keluar', 'value' => self::qty($qty), 'raw' => $qty],
            ['label' => 'Qty servis', 'value' => self::qty($usage['service']), 'raw' => $usage['service']],
            ['label' => 'Qty penjualan', 'value' => self::qty($usage['penjualan']), 'raw' => $usage['penjualan']],
            ['label' => 'Baris item', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
        ];

        $chart = self::dailyChart($daily, 'Qty barang keluar per tanggal', 'Keluar', '#d92d20');

        return [$rows, $summary, $chart, [
            'Qty di atas adalah jumlah barang keluar yang dicatat. Koreksi transaksi tidak menghapus baris asli; '
            . 'lihat laporan Riwayat Stok untuk jejak koreksi dan penyesuaian opname.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function opnameReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'COALESCE(o.opname_date, DATE(o.started_at)) >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'COALESCE(o.opname_date, DATE(o.started_at)) <= :to';
            $params[':to'] = $f['date_to'];
        }
        if (in_array($f['status'], ['OPEN', 'CONFIRMED'], true)) {
            $where[] = 'o.status = :status';
            $params[':status'] = $f['status'];
        }
        self::searchWhere($where, $params, $f['search'], [
            'o.opname_number', 's.code', 's.name',
        ]);

        $rows = self::query(
            "SELECT o.opname_number, COALESCE(o.opname_date, DATE(o.started_at)) AS opname_date, o.status,
                    s.code, s.name, i.system_stock, i.physical_stock, i.difference, i.reason,
                    COALESCE(us.full_name, '-') AS started_by,
                    COALESCE(uc.full_name, '-') AS confirmed_by,
                    i.counted_at
               FROM stock_opname_items i
               JOIN stock_opnames o ON o.id = i.opname_id
               JOIN spareparts s ON s.id = i.sparepart_id
          LEFT JOIN users us ON us.id = o.started_by
          LEFT JOIN users uc ON uc.id = o.confirmed_by
              WHERE " . implode(' AND ', $where) . '
           ORDER BY opname_date DESC, o.id DESC, s.name ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $sessions = [];
        $counted = 0;
        $differenceAbs = 0.0;
        $daily = [];
        foreach ($rows as $row) {
            $sessions[(string) $row['opname_number']] = true;
            if ($row['physical_stock'] !== null) {
                $counted++;
                $diff = (float) ($row['difference'] ?? 0);
                $differenceAbs += abs($diff);
                $day = (string) $row['opname_date'];
                $daily[$day] = ($daily[$day] ?? 0.0) + abs($diff);
            }
        }
        ksort($daily);

        $summary = [
            ['label' => 'Sesi opname', 'value' => number_format(count($sessions), 0, ',', '.'), 'raw' => count($sessions)],
            ['label' => 'Baris dihitung', 'value' => number_format($counted, 0, ',', '.'), 'raw' => $counted],
            ['label' => 'Total selisih absolut', 'value' => self::qty($differenceAbs), 'raw' => $differenceAbs],
            ['label' => 'Total baris', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
        ];

        $chart = self::dailyChart($daily, 'Total selisih absolut per tanggal', 'Selisih', '#f0ad4e');

        return [$rows, $summary, $chart, [
            'Selisih = stok fisik − stok sistem. Stok hanya berubah setelah sesi opname dikonfirmasi oleh Partman.',
            'Baris dengan kolom fisik kosong berarti belum dihitung.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function riwayatReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'COALESCE(m.movement_date, DATE(m.created_at)) >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'COALESCE(m.movement_date, DATE(m.created_at)) <= :to';
            $params[':to'] = $f['date_to'];
        }
        if (in_array($f['status'], ['STOK_AWAL', 'MASUK', 'KELUAR', 'PENYESUAIAN_OPNAME', 'KOREKSI', 'PENYESUAIAN'], true)) {
            $where[] = 'm.movement_type = :type';
            $params[':type'] = $f['status'];
        }
        self::searchWhere($where, $params, $f['search'], [
            's.code', 's.name', 'm.reference_number', 'm.note',
        ]);

        $rows = self::query(
            "SELECT COALESCE(m.movement_date, DATE(m.created_at)) AS movement_date,
                    m.movement_type, s.code, s.name, m.quantity,
                    m.stock_before, m.stock_after, m.reference_number, m.note,
                    COALESCE(u.full_name, '-') AS actor
               FROM stock_movements m
               JOIN spareparts s ON s.id = m.sparepart_id
          LEFT JOIN users u ON u.id = m.user_id
              WHERE " . implode(' AND ', $where) . '
           ORDER BY movement_date DESC, m.id DESC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $inQty = 0.0;
        $outQty = 0.0;
        $adjust = 0;
        $dailyIn = [];
        $dailyOut = [];
        foreach ($rows as $row) {
            $type = (string) $row['movement_type'];
            $qty = (float) $row['quantity'];
            if ($type === 'MASUK') {
                $inQty += $qty;
                $dailyIn[(string) $row['movement_date']] = ($dailyIn[(string) $row['movement_date']] ?? 0.0) + $qty;
            } elseif ($type === 'KELUAR') {
                $outQty += abs($qty);
                $dailyOut[(string) $row['movement_date']] = ($dailyOut[(string) $row['movement_date']] ?? 0.0) + abs($qty);
            } else {
                $adjust++;
            }
        }

        $labels = self::mergeDateKeys($dailyIn, $dailyOut);
        $chartLabels = array_map(static fn (string $date): string => date('d/m', strtotime($date)), $labels);

        $summary = [
            ['label' => 'Total baris ledger', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Total qty masuk', 'value' => self::qty($inQty), 'raw' => $inQty],
            ['label' => 'Total qty keluar', 'value' => self::qty($outQty), 'raw' => $outQty],
            ['label' => 'Baris penyesuaian/koreksi', 'value' => number_format($adjust, 0, ',', '.'), 'raw' => $adjust],
        ];

        $chart = [
            'kind'   => 'bar',
            'title'  => 'Pergerakan stok per tanggal',
            'labels' => $chartLabels,
            'series' => [
                ['label' => 'Masuk', 'values' => array_map(static fn (string $d): float => $dailyIn[$d] ?? 0.0, $labels), 'color' => '#198754'],
                ['label' => 'Keluar', 'values' => array_map(static fn (string $d): float => $dailyOut[$d] ?? 0.0, $labels), 'color' => '#d92d20'],
            ],
            'money'  => false,
        ];

        return [$rows, $summary, $chart, [
            'Ledger bersifat append-only: baris lama tidak pernah diubah/dihapus. Koreksi dicatat sebagai baris KOREKSI baru.',
            'Grafik hanya menjumlahkan MASUK dan KELUAR; penyesuaian opname dan koreksi ditampilkan pada tabel.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function fastSlowReport(array $f): array
    {
        $config = SettingService::reportConfig();
        $from = $f['date_from'];
        $to = $f['date_to'];
        if ($from === null && $to === null) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-' . ($config['period_days'] - 1) . ' days'));
        } elseif ($from === null) {
            $from = date('Y-m-d', strtotime((string) $to . ' -' . ($config['period_days'] - 1) . ' days'));
        } elseif ($to === null) {
            $to = date('Y-m-d');
        }

        $where = ['s.is_active = 1'];
        $params = [':from' => $from, ':to' => $to];
        if ($f['category_id'] !== null) {
            $where[] = 's.category_id = :category';
            $params[':category'] = $f['category_id'];
        }
        self::searchWhere($where, $params, $f['search'], ['s.code', 's.name', 's.brand']);

        $rows = self::query(
            "SELECT s.id, s.code, s.name, s.unit, s.stock, s.min_stock,
                    c.name AS category_name,
                    COALESCE(SUM(-m.quantity), 0) AS used_qty,
                    COUNT(m.id) AS used_tx
               FROM spareparts s
               JOIN categories c ON c.id = s.category_id
          LEFT JOIN stock_movements m
                 ON m.sparepart_id = s.id
                AND m.movement_type = 'KELUAR'
                AND COALESCE(m.movement_date, DATE(m.created_at)) BETWEEN :from AND :to
              WHERE " . implode(' AND ', $where) . '
           GROUP BY s.id, s.code, s.name, s.unit, s.stock, s.min_stock, c.name
           ORDER BY used_qty DESC, s.name ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $counts = ['fast' => 0, 'medium' => 0, 'slow' => 0];
        foreach ($rows as &$row) {
            $used = (float) $row['used_qty'];
            if ($used >= $config['fast_threshold']) {
                $row['classification'] = 'fast';
            } elseif ($used <= $config['slow_threshold']) {
                $row['classification'] = 'slow';
            } else {
                $row['classification'] = 'medium';
            }
            $counts[$row['classification']]++;
        }
        unset($row);

        if (in_array($f['classification'], ['fast', 'medium', 'slow'], true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['classification'] === $f['classification']
            ));
        }
        if ($f['classification'] === 'slow') {
            usort($rows, static fn (array $a, array $b): int => ((float) $a['used_qty'] <=> (float) $b['used_qty'])
                ?: strcmp((string) $a['name'], (string) $b['name']));
        }

        $summary = [
            ['label' => 'Cepat (fast moving)', 'value' => (string) $counts['fast'], 'raw' => $counts['fast']],
            ['label' => 'Sedang', 'value' => (string) $counts['medium'], 'raw' => $counts['medium']],
            ['label' => 'Lambat (slow moving)', 'value' => (string) $counts['slow'], 'raw' => $counts['slow']],
            ['label' => 'Periode analisis', 'value' => tanggal_id($from) . ' – ' . tanggal_id($to), 'raw' => $config['period_days']],
        ];

        $top = array_slice($rows, 0, 10);
        $chart = [
            'kind'   => 'bar',
            'title'  => '10 sparepart dengan pemakaian tertinggi',
            'labels' => array_map(static fn (array $r): string => (string) $r['name'], $top),
            'series' => [[
                'label'  => 'Qty keluar',
                'values' => array_map(static fn (array $r): float => (float) $r['used_qty'], $top),
                'color'  => '#16305c',
            ]],
            'money'  => false,
        ];

        return [$rows, $summary, $chart, [
            'Aturan yang dipakai — periode ' . $config['period_days'] . ' hari: '
            . 'Cepat bila total qty keluar ≥ ' . $config['fast_threshold'] . '; '
            . 'Lambat bila ≤ ' . $config['slow_threshold'] . '; selain itu Sedang.',
            'Ambang dan periode dapat diubah Admin pada menu Pengaturan. Aturan ini juga ditampilkan pada halaman laporan.',
            'Hanya sparepart aktif yang dihitung. Pemakaian berasal dari transaksi KELUAR (koreksi tidak mengurangi angka).',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function supplierReport(array $f): array
    {
        $where = ["r.receipt_type = 'pembelian'"];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'r.receipt_date >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'r.receipt_date <= :to';
            $params[':to'] = $f['date_to'];
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 'r.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        self::searchWhere($where, $params, $f['search'], ['sup.code', 'sup.name']);

        $rows = self::query(
            "SELECT sup.code, sup.name AS supplier_name,
                    COUNT(DISTINCT r.id) AS receipts,
                    COALESCE(SUM(i.quantity), 0) AS qty,
                    COALESCE(SUM(i.quantity * i.unit_price), 0) AS value,
                    MAX(r.receipt_date) AS last_receipt
               FROM goods_receipts r
               JOIN suppliers sup ON sup.id = r.supplier_id
               JOIN goods_receipt_items i ON i.receipt_id = r.id
              WHERE " . implode(' AND ', $where) . '
           GROUP BY sup.id, sup.code, sup.name
           ORDER BY value DESC, sup.name ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $docs = 0;
        $value = 0.0;
        foreach ($rows as $row) {
            $docs += (int) $row['receipts'];
            $value += (float) $row['value'];
        }

        $summary = [
            ['label' => 'Jumlah supplier', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Dokumen pembelian', 'value' => number_format($docs, 0, ',', '.'), 'raw' => $docs],
            ['label' => 'Total nilai pembelian', 'value' => rupiah($value), 'raw' => $value],
        ];

        $top = array_slice($rows, 0, 8);
        $chart = [
            'kind'   => 'bar',
            'title'  => 'Nilai pembelian per supplier',
            'labels' => array_map(static fn (array $r): string => (string) $r['supplier_name'], $top),
            'series' => [[
                'label'  => 'Nilai pembelian',
                'values' => array_map(static fn (array $r): float => (float) $r['value'], $top),
                'color'  => '#16305c',
            ]],
            'money'  => true,
        ];

        return [$rows, $summary, $chart, [
            'Hanya penerimaan bertipe "pembelian" yang dihitung (penerimaan lain tidak dianggap pembelian).',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function pembelianReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'po.created_at >= :from';
            $params[':from'] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== null) {
            $where[] = 'po.created_at <= :to';
            $params[':to'] = $f['date_to'] . ' 23:59:59';
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 'po.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        if (in_array($f['status'], self::PO_STATUSES, true)) {
            $where[] = 'po.status = :status';
            $params[':status'] = $f['status'];
        }
        if (in_array($f['payment'], self::PAYMENT_STATUSES, true)) {
            $where[] = 'po.payment_status = :payment';
            $params[':payment'] = $f['payment'];
        }
        self::searchWhere($where, $params, $f['search'], ['po.po_number', 'sup.name', 'po.note']);

        $rows = self::query(
            "SELECT po.id, po.po_number, DATE(po.created_at) AS po_date,
                    sup.name AS supplier_name, po.status, po.payment_status, po.total_amount, po.note,
                    COUNT(i.id) AS item_count, COALESCE(SUM(i.quantity), 0) AS qty,
                    COALESCE(u.full_name, '-') AS created_by
               FROM purchase_orders po
               JOIN suppliers sup ON sup.id = po.supplier_id
          LEFT JOIN purchase_order_items i ON i.po_id = po.id
          LEFT JOIN users u ON u.id = po.created_by
              WHERE " . implode(' AND ', $where) . '
           GROUP BY po.id, po.po_number, po.created_at, sup.name, po.status, po.payment_status, po.total_amount, po.note, u.full_name
           ORDER BY po.created_at DESC, po.id DESC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $statusCounts = [];
        $paymentCounts = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $statusCounts[(string) $row['status']] = ($statusCounts[(string) $row['status']] ?? 0) + 1;
            $paymentCounts[(string) $row['payment_status']] = ($paymentCounts[(string) $row['payment_status']] ?? 0) + 1;
            $total += (float) $row['total_amount'];
        }

        $summary = [
            ['label' => 'Jumlah PO', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Total nilai PO', 'value' => rupiah($total), 'raw' => $total],
            ['label' => 'Menunggu persetujuan', 'value' => (string) ($statusCounts['MENUNGGU_PERSETUJUAN'] ?? 0), 'raw' => $statusCounts['MENUNGGU_PERSETUJUAN'] ?? 0],
            ['label' => 'Belum dibayar', 'value' => (string) ($paymentCounts['BELUM_DIBAYAR'] ?? 0), 'raw' => $paymentCounts['BELUM_DIBAYAR'] ?? 0],
        ];

        $labels = [];
        $values = [];
        foreach (self::PO_STATUSES as $status) {
            $labels[] = self::label('po', $status);
            $values[] = $statusCounts[$status] ?? 0;
        }
        $chart = [
            'kind'   => 'bar',
            'title'  => 'Jumlah PO per status',
            'labels' => $labels,
            'series' => [['label' => 'Jumlah PO', 'values' => $values, 'color' => '#16305c']],
            'money'  => false,
        ];

        return [$rows, $summary, $chart, [
            'Laporan ini MEMBACA data PO yang sudah ada. Pembuatan/penyetujuan PO adalah modul Fase 6.',
            'Cetak Purchase Order tersedia dari tombol pada baris laporan.',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function penerimaanPoReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'r.receipt_date >= :from';
            $params[':from'] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = 'r.receipt_date <= :to';
            $params[':to'] = $f['date_to'];
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 'po.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        self::searchWhere($where, $params, $f['search'], [
            'r.receipt_number', 'po.po_number', 'sup.name', 's.code', 's.name',
        ]);

        $rows = self::query(
            "SELECT r.receipt_number, r.receipt_date, po.po_number,
                    COALESCE(sup.name, '-') AS supplier_name,
                    s.code, s.name, i.quantity, i.unit_price,
                    poi.quantity AS po_qty, poi.received_qty AS po_received,
                    GREATEST(poi.quantity - poi.received_qty, 0) AS remaining,
                    COALESCE(u.full_name, '-') AS recorded_by
               FROM goods_receipt_items i
               JOIN goods_receipts r ON r.id = i.receipt_id
               JOIN purchase_order_items poi ON poi.id = i.po_item_id
               JOIN purchase_orders po ON po.id = poi.po_id
               JOIN spareparts s ON s.id = i.sparepart_id
          LEFT JOIN suppliers sup ON sup.id = po.supplier_id
          LEFT JOIN users u ON u.id = r.recorded_by
              WHERE " . implode(' AND ', $where) . '
           ORDER BY r.receipt_date DESC, r.id DESC, i.id ASC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $docs = [];
        $qty = 0.0;
        $value = 0.0;
        $remaining = 0.0;
        $byReceipt = [];
        foreach ($rows as $row) {
            $docs[(string) $row['receipt_number']] = true;
            $qty += (float) $row['quantity'];
            $value += (float) $row['quantity'] * (float) $row['unit_price'];
            $remaining += (float) $row['remaining'];
            $key = (string) $row['receipt_number'];
            $byReceipt[$key] = ($byReceipt[$key] ?? 0.0) + (float) $row['quantity'];
        }
        arsort($byReceipt);
        $top = array_slice($byReceipt, 0, 8, true);

        $summary = [
            ['label' => 'Dokumen penerimaan PO', 'value' => number_format(count($docs), 0, ',', '.'), 'raw' => count($docs)],
            ['label' => 'Qty diterima', 'value' => self::qty($qty), 'raw' => $qty],
            ['label' => 'Nilai penerimaan', 'value' => rupiah($value), 'raw' => $value],
            ['label' => 'Sisa qty PO', 'value' => self::qty($remaining), 'raw' => $remaining],
        ];

        $chart = [
            'kind'   => 'bar',
            'title'  => 'Qty diterima per dokumen penerimaan',
            'labels' => array_keys($top),
            'series' => [[
                'label'  => 'Qty diterima',
                'values' => array_values($top),
                'color'  => '#198754',
            ]],
            'money'  => false,
        ];

        return [$rows, $summary, $chart, [
            'Hanya penerimaan yang tertaut ke item PO yang ditampilkan.',
            'Sisa qty = qty PO − akumulasi qty yang sudah diterima (termasuk penerimaan parsial).',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function pembayaranReport(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($f['date_from'] !== null) {
            $where[] = 'po.created_at >= :from';
            $params[':from'] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== null) {
            $where[] = 'po.created_at <= :to';
            $params[':to'] = $f['date_to'] . ' 23:59:59';
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 'po.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        if (in_array($f['status'], self::PAYMENT_STATUSES, true)) {
            $where[] = 'po.payment_status = :payment';
            $params[':payment'] = $f['status'];
        }
        self::searchWhere($where, $params, $f['search'], ['po.po_number', 'sup.name', 'h.note']);

        $rows = self::query(
            "SELECT po.po_number, DATE(po.created_at) AS po_date, sup.name AS supplier_name,
                    po.status, po.payment_status, po.total_amount,
                    h.status AS history_status, h.note AS payment_note, h.changed_at,
                    COALESCE(u.full_name, '-') AS changed_by
               FROM purchase_orders po
               JOIN suppliers sup ON sup.id = po.supplier_id
          LEFT JOIN purchase_payment_history h
                 ON h.id = (SELECT h2.id FROM purchase_payment_history h2
                             WHERE h2.purchase_order_id = po.id
                          ORDER BY h2.id DESC LIMIT 1)
          LEFT JOIN users u ON u.id = h.changed_by
              WHERE " . implode(' AND ', $where) . '
           ORDER BY po.created_at DESC, po.id DESC
              LIMIT ' . (self::MAX_ROWS + 1),
            $params
        );

        $amounts = ['BELUM_DIBAYAR' => 0.0, 'DIBAYAR_SEBAGIAN' => 0.0, 'LUNAS' => 0.0];
        $counts = ['BELUM_DIBAYAR' => 0, 'DIBAYAR_SEBAGIAN' => 0, 'LUNAS' => 0];
        $total = 0.0;
        foreach ($rows as $row) {
            $status = (string) $row['payment_status'];
            $amounts[$status] = ($amounts[$status] ?? 0.0) + (float) $row['total_amount'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $total += (float) $row['total_amount'];
        }

        $summary = [
            ['label' => 'Jumlah PO', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Total nilai PO', 'value' => rupiah($total), 'raw' => $total],
            ['label' => 'Belum dibayar', 'value' => $counts['BELUM_DIBAYAR'] . ' PO · ' . rupiah($amounts['BELUM_DIBAYAR']), 'raw' => $counts['BELUM_DIBAYAR']],
            ['label' => 'Dibayar sebagian', 'value' => $counts['DIBAYAR_SEBAGIAN'] . ' PO · ' . rupiah($amounts['DIBAYAR_SEBAGIAN']), 'raw' => $counts['DIBAYAR_SEBAGIAN']],
            ['label' => 'Lunas', 'value' => $counts['LUNAS'] . ' PO · ' . rupiah($amounts['LUNAS']), 'raw' => $counts['LUNAS']],
        ];

        $chart = [
            'kind'   => 'bar',
            'title'  => 'Nilai PO per status pembayaran',
            'labels' => [self::label('payment', 'BELUM_DIBAYAR'), self::label('payment', 'DIBAYAR_SEBAGIAN'), self::label('payment', 'LUNAS')],
            'series' => [[
                'label'  => 'Nilai PO',
                'values' => [$amounts['BELUM_DIBAYAR'], $amounts['DIBAYAR_SEBAGIAN'], $amounts['LUNAS']],
                'color'  => '#16305c',
            ]],
            'money'  => true,
        ];

        return [$rows, $summary, $chart, [
            'Status pembayaran pada PO adalah status terkini; kolom riwayat menampilkan perubahan terakhir.',
            'Perubahan status pembayaran dilakukan Admin (UI modul PO tersedia pada Fase 6).',
        ]];
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:?array<string,mixed>,3:array<int,string>} */
    private static function aiReport(array $f): array
    {
        $rows = Analytics::aiRecommendations(200);
        $stats = AiPrediction::summary();
        $totalRecommendation = 0.0;
        foreach ($rows as $index => $row) {
            $totalRecommendation += (float) ($row['recommended_qty'] ?? 0);
            $rows[$index]['method'] = PredictionService::methodLabel((string) $row['method']);
            $rows[$index]['stockout_risk'] = $row['stockout_risk'] !== null
                ? (AiPrediction::RISK_LABELS[(string) $row['stockout_risk']] ?? (string) $row['stockout_risk'])
                : '—';
        }

        $summary = [
            ['label' => 'Rekomendasi tersedia', 'value' => number_format(count($rows), 0, ',', '.'), 'raw' => count($rows)],
            ['label' => 'Total qty rekomendasi', 'value' => self::qty($totalRecommendation), 'raw' => $totalRecommendation],
            ['label' => 'Risiko tinggi', 'value' => number_format($stats['risk_high'], 0, ',', '.'), 'raw' => $stats['risk_high']],
            [
                'label' => 'Status modul prediksi',
                'value' => $stats['total'] > 0
                    ? 'Aktif — ' . number_format($stats['total'], 0, ',', '.') . ' sparepart dianalisis'
                    : 'Belum pernah dijalankan',
                'raw'   => $stats['total'],
            ],
        ];

        return [$rows, $summary, null, [
            'Rekomendasi dihitung pipeline Python lokal (Moving Average, Linear Regression, Safety Stock, ROP) dari riwayat barang keluar; latih/uji selalu memakai data lampau (kronologis).',
            'Rekomendasi = usulan, BUKAN pesanan otomatis. Partman meninjau; mengubah jumlah saat membuat reorder wajib mencantumkan alasan.',
            'Prediksi tanpa data cukup tidak menampilkan angka (ditandai "tidak cukup") agar tidak mengarang. Jalankan analisis dari menu Prediksi Stok (Partman).',
            'Kolom Stok/Minimum adalah data aktual saat laporan dibuka; hanya kolom rekomendasi/risiko/periode/metode yang berasal dari prediksi.',
        ]];
    }

    // ==================================================================
    // Pembantu query & filter
    // ==================================================================

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function stockBaseQuery(array $f, bool $forceActive): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($forceActive) {
            $where[] = 's.is_active = 1';
        } elseif ($f['active'] === 'aktif') {
            $where[] = 's.is_active = 1';
        } elseif ($f['active'] === 'nonaktif') {
            $where[] = 's.is_active = 0';
        }
        if ($f['category_id'] !== null) {
            $where[] = 's.category_id = :category';
            $params[':category'] = $f['category_id'];
        }
        if ($f['supplier_id'] !== null) {
            $where[] = 's.supplier_id = :supplier';
            $params[':supplier'] = $f['supplier_id'];
        }
        self::searchWhere($where, $params, $f['search'], ['s.code', 's.name', 's.brand']);

        $sql = 'SELECT s.id, s.code, s.name, s.brand, s.unit, s.stock, s.min_stock,
                       s.purchase_price, s.avg_purchase_price, s.is_active, s.last_purchase_date,
                       c.name AS category_name, sup.name AS supplier_name
                  FROM spareparts s
                  JOIN categories c ON c.id = s.category_id
             LEFT JOIN suppliers sup ON sup.id = s.supplier_id
                 WHERE ' . implode(' AND ', $where);

        return [$sql, $params];
    }

    /**
     * Tambahkan kondisi pencarian multi-kolom dengan placeholder unik
     * (PDO native tidak mengizinkan satu nama parameter dipakai berulang).
     *
     * @param array<int,string> $where
     * @param array<string,mixed> $params
     * @param array<int,string> $columns
     */
    private static function searchWhere(array &$where, array &$params, string $search, array $columns): void
    {
        $search = trim($search);
        if ($search === '' || $columns === []) {
            return;
        }
        $parts = [];
        foreach ($columns as $index => $column) {
            $key = ':q' . $index;
            $parts[] = $column . ' LIKE ' . $key;
            $params[$key] = '%' . $search . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private static function query(string $sql, array $params = []): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    private static function normalizeFilters(array $input, array $meta): array
    {
        $search = $input['search'] ?? '';
        $filters = [
            'date_from'      => self::validDate($input['date_from'] ?? null),
            'date_to'        => self::validDate($input['date_to'] ?? null),
            'category_id'    => self::positiveInt($input['category_id'] ?? null),
            'supplier_id'    => self::positiveInt($input['supplier_id'] ?? null),
            'search'         => is_scalar($search) ? mb_substr(trim((string) $search), 0, 100) : '',
            'status'         => '',
            'usage'          => '',
            'payment'        => '',
            'classification' => '',
            'active'         => '',
        ];

        if ($filters['date_from'] !== null && $filters['date_to'] !== null
            && $filters['date_from'] > $filters['date_to']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        $allowed = $meta['filters'];
        $statusOptions = $allowed['status'] ?? [];
        $status = $input['status'] ?? '';
        if (is_scalar($status) && $status !== '' && in_array((string) $status, $statusOptions, true)) {
            $filters['status'] = (string) $status;
        }

        $usageOptions = $allowed['usage'] ?? [];
        $usage = $input['usage'] ?? '';
        if (is_scalar($usage) && $usage !== '' && in_array((string) $usage, $usageOptions, true)) {
            $filters['usage'] = (string) $usage;
        }

        $paymentOptions = $allowed['payment'] ?? [];
        $payment = $input['payment'] ?? '';
        if (is_scalar($payment) && $payment !== '' && in_array((string) $payment, $paymentOptions, true)) {
            $filters['payment'] = (string) $payment;
        }

        $classification = $input['classification'] ?? '';
        if (!empty($allowed['classification']) && is_scalar($classification)
            && in_array((string) $classification, ['fast', 'medium', 'slow'], true)) {
            $filters['classification'] = (string) $classification;
        }

        $active = $input['active'] ?? '';
        if (!empty($allowed['active']) && is_scalar($active)
            && in_array((string) $active, ['aktif', 'nonaktif'], true)) {
            $filters['active'] = (string) $active;
        }

        // Filter yang tidak relevan tidak boleh memengaruhi laporan.
        if (empty($allowed['date'])) {
            $filters['date_from'] = null;
            $filters['date_to'] = null;
        }
        if (empty($allowed['category'])) {
            $filters['category_id'] = null;
        }
        if (empty($allowed['supplier'])) {
            $filters['supplier_id'] = null;
        }
        if (empty($allowed['search'])) {
            $filters['search'] = '';
        }

        return $filters;
    }

    /**
     * @return array<int,string>
     */
    private static function filterText(string $type, array $f): array
    {
        $lines = [];
        $dateFrom = $f['date_from'];
        $dateTo = $f['date_to'];

        // Fast/slow moving memakai periode default bila tanggal tidak diisi.
        // Tampilkan periode yang BENAR-BENAR dipakai, bukan "semua tanggal".
        if ($type === 'fast_slow') {
            $config = SettingService::reportConfig();
            if ($dateFrom === null && $dateTo === null) {
                $dateTo = date('Y-m-d');
                $dateFrom = date('Y-m-d', strtotime('-' . ($config['period_days'] - 1) . ' days'));
            } elseif ($dateFrom === null) {
                $dateFrom = date('Y-m-d', strtotime((string) $dateTo . ' -' . ($config['period_days'] - 1) . ' days'));
            } elseif ($dateTo === null) {
                $dateTo = date('Y-m-d');
            }
        }

        if ($dateFrom !== null || $dateTo !== null) {
            if ($dateFrom !== null && $dateTo !== null) {
                $lines[] = 'Periode: ' . tanggal_id($dateFrom) . ' – ' . tanggal_id($dateTo);
            } elseif ($dateFrom !== null) {
                $lines[] = 'Periode: sejak ' . tanggal_id($dateFrom);
            } else {
                $lines[] = 'Periode: sampai ' . tanggal_id($dateTo);
            }
        } else {
            $lines[] = 'Periode: semua tanggal';
        }

        if ($f['category_id'] !== null) {
            $category = CategoryService::find($f['category_id']);
            $lines[] = 'Kategori: ' . ($category['name'] ?? ('#' . $f['category_id']));
        }
        if ($f['supplier_id'] !== null) {
            $supplier = SupplierService::find($f['supplier_id']);
            $lines[] = 'Supplier: ' . ($supplier['name'] ?? ('#' . $f['supplier_id']));
        }
        if ($f['search'] !== '') {
            $lines[] = 'Pencarian: "' . $f['search'] . '"';
        }
        if ($f['active'] !== '') {
            $lines[] = 'Status data: ' . self::label('active', $f['active'] === 'aktif' ? '1' : '0');
        }
        if ($f['status'] !== '') {
            $group = match ($type) {
                'stok', 'stok_minimum' => 'stock',
                'opname' => 'opname',
                'riwayat' => 'movement',
                'pembelian' => 'po',
                'pembayaran' => 'payment',
                default => 'status',
            };
            $lines[] = 'Status: ' . self::label($group, $f['status']);
        }
        if ($f['usage'] !== '') {
            $lines[] = 'Jenis pemakaian: ' . self::label('usage', $f['usage']);
        }
        if ($f['payment'] !== '') {
            $lines[] = 'Pembayaran: ' . self::label('payment', $f['payment']);
        }
        if ($f['classification'] !== '') {
            $lines[] = 'Klasifikasi: ' . self::label('classification', $f['classification']);
        }
        if ($type === 'fast_slow') {
            $config = SettingService::reportConfig();
            $lines[] = 'Aturan: ' . $config['period_days'] . ' hari; cepat ≥ ' . $config['fast_threshold']
                . ', lambat ≤ ' . $config['slow_threshold'];
        }

        return $lines;
    }

    private static function validDate(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private static function qty(float $value): string
    {
        $decimals = floor($value) === $value ? 0 : 2;
        return number_format($value, $decimals, ',', '.');
    }

    /**
     * @param array<string,float> $daily
     * @return array<string,mixed>
     */
    private static function dailyChart(array $daily, string $title, string $label, string $color): array
    {
        $labels = array_keys($daily);
        return [
            'kind'   => 'line',
            'title'  => $title,
            'labels' => array_map(static fn (string $date): string => date('d/m', strtotime($date)), $labels),
            'series' => [[
                'label'  => $label,
                'values' => array_values($daily),
                'color'  => $color,
            ]],
            'money'  => false,
        ];
    }

    /**
     * @param array<string,float> ...$maps
     * @return array<int,string>
     */
    private static function mergeDateKeys(array ...$maps): array
    {
        $keys = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $key) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    // ==================================================================
    // Registry laporan
    // ==================================================================

    /** @return array<string,array<string,mixed>> */
    private static function registry(): array
    {
        return [
            'stok' => [
                'key'         => 'stok',
                'title'       => 'Laporan Stok Keseluruhan',
                'short'       => 'Stok keseluruhan',
                'description' => 'Seluruh sparepart beserta stok, status, harga, dan nilai persediaan.',
                'icon'        => '&#128230;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => false, 'category' => true, 'supplier' => true, 'search' => true,
                    'active' => true, 'status' => Sparepart::STOCK_STATUSES,
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 16],
                    ['key' => 'name', 'label' => 'Nama', 'type' => 'text', 'width' => 34],
                    ['key' => 'category_name', 'label' => 'Kategori', 'type' => 'text', 'width' => 20],
                    ['key' => 'unit', 'label' => 'Satuan', 'type' => 'text', 'width' => 9],
                    ['key' => 'stock', 'label' => 'Stok', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'min_stock', 'label' => 'Minimum', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'stock_status', 'label' => 'Status', 'type' => 'badge', 'group' => 'stock', 'width' => 13],
                    ['key' => 'purchase_price', 'label' => 'Harga terakhir', 'type' => 'money', 'width' => 17, 'align' => 'right'],
                    ['key' => 'avg_purchase_price', 'label' => 'Harga rata-rata', 'type' => 'money', 'width' => 17, 'align' => 'right'],
                    ['key' => 'value_avg', 'label' => 'Nilai (rata-rata)', 'type' => 'money', 'width' => 18, 'align' => 'right'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 22],
                ],
                'notes' => [],
            ],
            'masuk' => [
                'key'         => 'masuk',
                'title'       => 'Laporan Barang Masuk',
                'short'       => 'Barang masuk',
                'description' => 'Penerimaan barang per dokumen dan per item, termasuk supplier dan harga.',
                'icon'        => '&#128229;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'category' => true, 'supplier' => true, 'search' => true,
                ],
                'columns' => [
                    ['key' => 'receipt_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'receipt_number', 'label' => 'No. Dokumen', 'type' => 'text', 'width' => 19],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 24],
                    ['key' => 'invoice_number', 'label' => 'Faktur', 'type' => 'text', 'width' => 15],
                    ['key' => 'receipt_type', 'label' => 'Jenis', 'type' => 'badge', 'group' => 'receipt', 'width' => 12],
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama Sparepart', 'type' => 'text', 'width' => 32],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'decimal', 'width' => 8, 'align' => 'right'],
                    ['key' => 'unit_price', 'label' => 'Harga', 'type' => 'money', 'width' => 16, 'align' => 'right'],
                    ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'money', 'width' => 17, 'align' => 'right'],
                    ['key' => 'recorded_by', 'label' => 'Dicatat oleh', 'type' => 'text', 'width' => 18],
                ],
                'notes' => [],
            ],
            'keluar' => [
                'key'         => 'keluar',
                'title'       => 'Laporan Barang Keluar',
                'short'       => 'Barang keluar',
                'description' => 'Pengeluaran barang per dokumen dan per item, termasuk jenis pemakaian.',
                'icon'        => '&#128228;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'category' => true, 'search' => true,
                    'usage' => ['service', 'penjualan', 'lainnya'],
                ],
                'columns' => [
                    ['key' => 'issue_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'issue_number', 'label' => 'No. Dokumen', 'type' => 'text', 'width' => 19],
                    ['key' => 'usage_type', 'label' => 'Jenis', 'type' => 'badge', 'group' => 'usage', 'width' => 12],
                    ['key' => 'plate_number', 'label' => 'Plat', 'type' => 'text', 'width' => 12],
                    ['key' => 'spk_number', 'label' => 'SPK', 'type' => 'text', 'width' => 15],
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama Sparepart', 'type' => 'text', 'width' => 34],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'decimal', 'width' => 8, 'align' => 'right'],
                    ['key' => 'recorded_by', 'label' => 'Dicatat oleh', 'type' => 'text', 'width' => 20],
                ],
                'notes' => [],
            ],
            'stok_minimum' => [
                'key'         => 'stok_minimum',
                'title'       => 'Laporan Stok Minimum / Kritis / Habis',
                'short'       => 'Stok minimum',
                'description' => 'Sparepart aktif yang habis, kritis, menipis, atau belum punya stok minimum.',
                'icon'        => '&#9888;',
                'orientation' => 'landscape',
                'filters'     => [
                    'category' => true, 'supplier' => true, 'search' => true,
                    'status' => self::STOCK_ALERTS,
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 16],
                    ['key' => 'name', 'label' => 'Nama', 'type' => 'text', 'width' => 36],
                    ['key' => 'category_name', 'label' => 'Kategori', 'type' => 'text', 'width' => 20],
                    ['key' => 'stock', 'label' => 'Stok', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'min_stock', 'label' => 'Minimum', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'stock_status', 'label' => 'Status', 'type' => 'badge', 'group' => 'stock', 'width' => 14],
                    ['key' => 'avg_purchase_price', 'label' => 'Harga rata-rata', 'type' => 'money', 'width' => 18, 'align' => 'right'],
                    ['key' => 'value_avg', 'label' => 'Nilai', 'type' => 'money', 'width' => 18, 'align' => 'right'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 24],
                ],
                'notes' => [],
            ],
            'opname' => [
                'key'         => 'opname',
                'title'       => 'Laporan Stock Opname',
                'short'       => 'Stock opname',
                'description' => 'Sesi perhitungan fisik, selisih stok, alasan, dan status konfirmasi.',
                'icon'        => '&#128203;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'search' => true, 'status' => ['OPEN', 'CONFIRMED'],
                ],
                'columns' => [
                    ['key' => 'opname_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'opname_number', 'label' => 'No. Sesi', 'type' => 'text', 'width' => 19],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'group' => 'opname', 'width' => 14],
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama Sparepart', 'type' => 'text', 'width' => 30],
                    ['key' => 'system_stock', 'label' => 'Sistem', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'physical_stock', 'label' => 'Fisik', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'difference', 'label' => 'Selisih', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'reason', 'label' => 'Alasan', 'type' => 'text', 'width' => 28],
                    ['key' => 'started_by', 'label' => 'Dimulai', 'type' => 'text', 'width' => 18],
                ],
                'notes' => [],
            ],
            'riwayat' => [
                'key'         => 'riwayat',
                'title'       => 'Laporan Riwayat Transaksi Stok',
                'short'       => 'Riwayat transaksi',
                'description' => 'Ledger lengkap: stok awal, masuk, keluar, opname, dan koreksi.',
                'icon'        => '&#128200;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'search' => true,
                    'status' => ['STOK_AWAL', 'MASUK', 'KELUAR', 'PENYESUAIAN_OPNAME', 'KOREKSI', 'PENYESUAIAN'],
                ],
                'columns' => [
                    ['key' => 'movement_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'movement_type', 'label' => 'Jenis', 'type' => 'badge', 'group' => 'movement', 'width' => 16],
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama Sparepart', 'type' => 'text', 'width' => 30],
                    ['key' => 'quantity', 'label' => 'Qty', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'stock_before', 'label' => 'Sebelum', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'stock_after', 'label' => 'Sesudah', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'reference_number', 'label' => 'Referensi', 'type' => 'text', 'width' => 18],
                    ['key' => 'actor', 'label' => 'Oleh', 'type' => 'text', 'width' => 18],
                    ['key' => 'note', 'label' => 'Catatan', 'type' => 'text', 'width' => 28],
                ],
                'notes' => [],
            ],
            'fast_slow' => [
                'key'         => 'fast_slow',
                'title'       => 'Laporan Fast / Slow Moving',
                'short'       => 'Fast / slow moving',
                'description' => 'Klasifikasi pemakaian sparepart berdasarkan ambang yang dapat dikonfigurasi.',
                'icon'        => '&#9889;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'category' => true, 'search' => true,
                    'classification' => true,
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama', 'type' => 'text', 'width' => 34],
                    ['key' => 'category_name', 'label' => 'Kategori', 'type' => 'text', 'width' => 20],
                    ['key' => 'unit', 'label' => 'Satuan', 'type' => 'text', 'width' => 9],
                    ['key' => 'stock', 'label' => 'Stok', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'used_qty', 'label' => 'Qty keluar', 'type' => 'decimal', 'width' => 11, 'align' => 'right'],
                    ['key' => 'used_tx', 'label' => 'Transaksi', 'type' => 'number', 'width' => 10, 'align' => 'right'],
                    ['key' => 'classification', 'label' => 'Klasifikasi', 'type' => 'badge', 'group' => 'classification', 'width' => 18],
                ],
                'notes' => [],
            ],
            'supplier' => [
                'key'         => 'supplier',
                'title'       => 'Laporan Supplier',
                'short'       => 'Supplier',
                'description' => 'Nilai dan jumlah pembelian per supplier pada rentang tanggal.',
                'icon'        => '&#128666;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'supplier' => true, 'search' => true,
                ],
                'columns' => [
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 34],
                    ['key' => 'receipts', 'label' => 'Dokumen', 'type' => 'number', 'width' => 10, 'align' => 'right'],
                    ['key' => 'qty', 'label' => 'Qty', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'value', 'label' => 'Nilai pembelian', 'type' => 'money', 'width' => 20, 'align' => 'right'],
                    ['key' => 'last_receipt', 'label' => 'Pembelian terakhir', 'type' => 'date', 'width' => 16],
                ],
                'notes' => [],
            ],
            'pembelian' => [
                'key'         => 'pembelian',
                'title'       => 'Laporan Pembelian / Purchase Order',
                'short'       => 'Pembelian / PO',
                'description' => 'Daftar PO beserta status, nilai, dan status pembayaran. Cetak PO tersedia.',
                'icon'        => '&#128196;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'supplier' => true, 'search' => true,
                    'status' => self::PO_STATUSES, 'payment' => self::PAYMENT_STATUSES,
                ],
                'columns' => [
                    ['key' => 'po_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'po_number', 'label' => 'No. PO', 'type' => 'text', 'width' => 19],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 26],
                    ['key' => 'item_count', 'label' => 'Item', 'type' => 'number', 'width' => 8, 'align' => 'right'],
                    ['key' => 'qty', 'label' => 'Qty', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'total_amount', 'label' => 'Total', 'type' => 'money', 'width' => 18, 'align' => 'right'],
                    ['key' => 'status', 'label' => 'Status PO', 'type' => 'badge', 'group' => 'po', 'width' => 19],
                    ['key' => 'payment_status', 'label' => 'Pembayaran', 'type' => 'badge', 'group' => 'payment', 'width' => 17],
                    ['key' => 'created_by', 'label' => 'Dibuat oleh', 'type' => 'text', 'width' => 18],
                ],
                'notes' => [],
            ],
            'penerimaan_po' => [
                'key'         => 'penerimaan_po',
                'title'       => 'Laporan Penerimaan PO',
                'short'       => 'Penerimaan PO',
                'description' => 'Penerimaan barang yang tertaut ke PO, termasuk qty dan sisa yang belum diterima.',
                'icon'        => '&#128666;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'supplier' => true, 'search' => true,
                ],
                'columns' => [
                    ['key' => 'receipt_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'receipt_number', 'label' => 'No. Penerimaan', 'type' => 'text', 'width' => 19],
                    ['key' => 'po_number', 'label' => 'No. PO', 'type' => 'text', 'width' => 19],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 24],
                    ['key' => 'code', 'label' => 'Kode', 'type' => 'text', 'width' => 14],
                    ['key' => 'name', 'label' => 'Nama', 'type' => 'text', 'width' => 28],
                    ['key' => 'quantity', 'label' => 'Diterima', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'unit_price', 'label' => 'Harga', 'type' => 'money', 'width' => 15, 'align' => 'right'],
                    ['key' => 'po_qty', 'label' => 'Qty PO', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                    ['key' => 'po_received', 'label' => 'Akumulasi', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'remaining', 'label' => 'Sisa', 'type' => 'decimal', 'width' => 9, 'align' => 'right'],
                ],
                'notes' => [],
            ],
            'pembayaran' => [
                'key'         => 'pembayaran',
                'title'       => 'Laporan Status Pembayaran',
                'short'       => 'Status pembayaran',
                'description' => 'Status pembayaran PO beserta perubahan terakhir.',
                'icon'        => '&#128176;',
                'orientation' => 'landscape',
                'filters'     => [
                    'date' => true, 'supplier' => true, 'search' => true,
                    'status' => self::PAYMENT_STATUSES,
                ],
                'columns' => [
                    ['key' => 'po_number', 'label' => 'No. PO', 'type' => 'text', 'width' => 19],
                    ['key' => 'po_date', 'label' => 'Tanggal', 'type' => 'date', 'width' => 13],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'width' => 26],
                    ['key' => 'total_amount', 'label' => 'Total PO', 'type' => 'money', 'width' => 18, 'align' => 'right'],
                    ['key' => 'payment_status', 'label' => 'Status', 'type' => 'badge', 'group' => 'payment', 'width' => 16],
                    ['key' => 'changed_at', 'label' => 'Perubahan terakhir', 'type' => 'date', 'width' => 15],
                    ['key' => 'changed_by', 'label' => 'Oleh', 'type' => 'text', 'width' => 18],
                    ['key' => 'payment_note', 'label' => 'Catatan', 'type' => 'text', 'width' => 28],
                ],
                'notes' => [],
            ],
            'ai' => [
                'key'         => 'ai',
                'title'       => 'Laporan Rekomendasi AI',
                'short'       => 'Rekomendasi AI',
                'description' => 'Rekomendasi reorder terbaru per sparepart hasil prediksi AI (permintaan, risiko, saran qty).',
                'icon'        => '&#129302;',
                'orientation' => 'portrait',
                'filters'     => [],
                'columns' => [
                    ['key' => 'sparepart_code', 'label' => 'Kode', 'type' => 'text', 'width' => 16],
                    ['key' => 'sparepart_name', 'label' => 'Nama', 'type' => 'text', 'width' => 34],
                    ['key' => 'stock', 'label' => 'Stok', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'min_stock', 'label' => 'Minimum', 'type' => 'decimal', 'width' => 10, 'align' => 'right'],
                    ['key' => 'stockout_risk', 'label' => 'Risiko', 'type' => 'text', 'width' => 12],
                    ['key' => 'recommended_qty', 'label' => 'Rekomendasi', 'type' => 'decimal', 'width' => 12, 'align' => 'right'],
                    ['key' => 'period_days', 'label' => 'Periode (hari)', 'type' => 'number', 'width' => 10, 'align' => 'right'],
                    ['key' => 'method', 'label' => 'Metode', 'type' => 'text', 'width' => 18],
                    ['key' => 'processed_at', 'label' => 'Diproses', 'type' => 'date', 'width' => 16],
                ],
                'notes' => [],
            ],
        ];
    }
}
