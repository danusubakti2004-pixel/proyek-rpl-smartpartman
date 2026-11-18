<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\Import\Spreadsheet;
use App\Services\Import\XlsxWriter;
use RuntimeException;

/**
 * Impor master data (FR-04) dan stok awal (FR-05).
 *
 * Alur: unggah → PRATINJAU (validasi per baris) → KOMIT (atomik).
 *
 * Prinsip yang dipatuhi:
 * - Template kolom dapat diunduh (CSV & XLSX).
 * - Pratinjau menampilkan nomor baris + alasan kegagalan tiap baris.
 * - Komit memakai SATU transaksi: bila ada satu baris bermasalah,
 *   TIDAK ADA data yang tersimpan (tidak setengah jalan).
 * - Default: kode duplikat DITOLAK (tidak menimpa data lama).
 * - Nilai stok pada impor master TIDAK ditulis diam-diam: dibuatkan
 *   pergerakan STOK_AWAL di ledger.
 */
final class MasterImportService
{
    public const TYPE_SPAREPART = 'sparepart';
    public const TYPE_STOCK_INITIAL = 'stok_awal';

    public const PREVIEW_KEY = '_import_preview';
    public const MAX_ROWS = Spreadsheet::MAX_ROWS;
    public const MAX_BYTES = Spreadsheet::MAX_BYTES;

    /** @var array<string,array<string,array{label:string,required:bool,aliases:string[]}>> */
    private const FIELDS = [
        self::TYPE_SPAREPART => [
            'kode'         => ['label' => 'Kode', 'required' => true, 'aliases' => ['kode', 'kode_sparepart', 'kode_part', 'part_number', 'partnumber', 'code']],
            'nama'         => ['label' => 'Nama', 'required' => true, 'aliases' => ['nama', 'nama_sparepart', 'name', 'deskripsi']],
            'kategori'     => ['label' => 'Kategori', 'required' => true, 'aliases' => ['kategori', 'kategori_sparepart', 'category']],
            'brand'        => ['label' => 'Brand', 'required' => false, 'aliases' => ['brand', 'merek', 'merk']],
            'model'        => ['label' => 'Model Kendaraan', 'required' => false, 'aliases' => ['model', 'model_kendaraan', 'vehicle_model', 'kendaraan']],
            'satuan'       => ['label' => 'Satuan', 'required' => false, 'aliases' => ['satuan', 'unit', 'uom']],
            'harga_beli'   => ['label' => 'Harga Beli', 'required' => false, 'aliases' => ['harga_beli', 'hargabeli', 'purchase_price', 'buy_price']],
            'harga_jual'   => ['label' => 'Harga Jual', 'required' => false, 'aliases' => ['harga_jual', 'hargajual', 'selling_price', 'sell_price']],
            'stok'         => ['label' => 'Stok Awal', 'required' => false, 'aliases' => ['stok', 'stock', 'stok_awal', 'qty', 'quantity', 'jumlah']],
            'stok_minimum' => ['label' => 'Stok Minimum', 'required' => false, 'aliases' => ['stok_minimum', 'stokminimum', 'stok_min', 'min_stock', 'minimum']],
            'lokasi'       => ['label' => 'Lokasi', 'required' => false, 'aliases' => ['lokasi', 'lokasi_rak', 'rak', 'location']],
            'supplier'     => ['label' => 'Supplier', 'required' => false, 'aliases' => ['supplier', 'pemasok', 'vendor']],
        ],
        self::TYPE_STOCK_INITIAL => [
            'kode'    => ['label' => 'Kode', 'required' => true, 'aliases' => ['kode', 'kode_sparepart', 'part_number', 'code']],
            'jumlah'  => ['label' => 'Jumlah', 'required' => true, 'aliases' => ['jumlah', 'qty', 'quantity', 'stok', 'stok_awal']],
            'tanggal' => ['label' => 'Tanggal', 'required' => false, 'aliases' => ['tanggal', 'tanggal_pencatatan', 'tgl', 'date']],
            'catatan' => ['label' => 'Catatan', 'required' => false, 'aliases' => ['catatan', 'keterangan', 'note']],
        ],
    ];

    /** @return string[] */
    public static function types(): array
    {
        return [self::TYPE_SPAREPART, self::TYPE_STOCK_INITIAL];
    }

    public static function titleOf(string $type): string
    {
        return match ($type) {
            self::TYPE_SPAREPART     => 'Master Sparepart',
            self::TYPE_STOCK_INITIAL => 'Stok Awal',
            default                  => throw new RuntimeException('Jenis impor tidak dikenal.'),
        };
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /** @return array<string,array{label:string,required:bool,aliases:string[]}> */
    public static function fields(string $type): array
    {
        if (!isset(self::FIELDS[$type])) {
            throw new RuntimeException('Jenis impor tidak dikenal.');
        }
        return self::FIELDS[$type];
    }

    /** @return string[] Nama kolom kanonik (untuk header template). */
    public static function headers(string $type): array
    {
        $out = [];
        foreach (self::fields($type) as $spec) {
            $out[] = $spec['label'];
        }
        return $out;
    }

    /**
     * Contoh isi kolom (ditampilkan di halaman, TIDAK ikut ke template
     * supaya tidak ada data contoh yang terimpor tanpa sengaja).
     *
     * @return array<int,string>
     */
    public static function sampleRow(string $type): array
    {
        return match ($type) {
            self::TYPE_SPAREPART => [
                'FLT-001', 'Filter Oli Suzuki Satria', 'Filter', 'Suzuki', 'Satria F150',
                'pcs', '45000', '60000', '24', '5', 'RAK-A1-T1', 'SUP-01',
            ],
            self::TYPE_STOCK_INITIAL => ['FLT-001', '24', date('d/m/Y'), 'Hasil hitung fisik awal'],
            default => throw new RuntimeException('Jenis impor tidak dikenal.'),
        };
    }

    /** Template CSV (header saja, UTF-8 + BOM agar rapi dibuka Excel). */
    public static function csvTemplate(string $type): string
    {
        return "\xEF\xBB\xBF" . self::csvLine(self::headers($type)) . "\r\n";
    }

    /** Template XLSX (header saja). */
    public static function xlsxTemplate(string $type): string
    {
        return XlsxWriter::build([self::headers($type)]);
    }

    public static function fileNameOf(string $type): string
    {
        return $type === self::TYPE_SPAREPART ? 'template-sparepart' : 'template-stok-awal';
    }

    /**
     * Baca berkas dari disk lalu buat pratinjau (dipakai pengujian/CLI).
     *
     * @return array<string,mixed>
     */
    public static function previewFile(string $type, string $path, string $extension, string $fileName): array
    {
        $data = Spreadsheet::readFile($path, $extension);
        return self::preview($type, $data['headers'], $data['rows'], $fileName, $extension);
    }

    /**
     * Bentuk data pratinjau dari isi berkas yang sudah dibaca (TIDAK menulis DB).
     *
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rows
     * @return array<string,mixed>
     */
    public static function preview(
        string $type,
        array $headers,
        array $rows,
        string $fileName,
        string $extension = ''
    ): array {
        if (!self::isKnownType($type)) {
            throw new RuntimeException('Jenis impor tidak dikenal.');
        }
        $data = ['headers' => $headers, 'rows' => $rows];
        $map = self::mapHeaders($data['headers'], $type);

        $rows = [];
        $seen = [];
        $total = 0;
        $okCount = 0;

        foreach ($data['rows'] as $index => $rawRow) {
            $line = $index + 2; // baris 1 = header
            $cells = [];
            foreach (self::fields($type) as $field => $spec) {
                $column = $map['map'][$field] ?? null;
                $cells[$field] = $column === null ? '' : trim((string) ($rawRow[$column] ?? ''));
            }
            if (trim(implode('', $cells)) === '') {
                continue; // baris kosong diabaikan
            }

            $total++;
            $missing = $map['missing'];
            $result = $missing === []
                ? self::validateRow($type, $cells, $seen, $line)
                : ['ok' => false, 'errors' => ['Kolom wajib belum ada di berkas: ' . implode(', ', $missing) . '.'], 'values' => []];
            if ($result['ok']) {
                $okCount++;
            }

            $rows[] = [
                'line'   => $line,
                'cells'  => $cells,
                'ok'     => $result['ok'],
                'errors' => $result['errors'],
                'values' => $result['values'],
            ];
        }

        return [
            'type'             => $type,
            'file'             => $fileName,
            'extension'        => $extension,
            'headers'          => $data['headers'],
            'recognized'       => $map['map'],
            'missing_required' => $map['missing'],
            'unknown'          => $map['unknown'],
            'rows'             => $rows,
            'summary'          => ['total' => $total, 'ok' => $okCount, 'error' => $total - $okCount],
        ];
    }

    /**
     * Simpan pratinjau di sesi agar dapat dikomit pengguna pada langkah berikutnya.
     *
     * @param array<string,mixed> $preview
     */
    public static function stash(string $token, array $preview): void
    {
        Session::put(self::PREVIEW_KEY, ['token' => $token] + $preview);
    }

    /** @return array<string,mixed>|null */
    public static function pull(string $token): ?array
    {
        $data = Session::get(self::PREVIEW_KEY);
        if (!is_array($data) || !hash_equals((string) ($data['token'] ?? ''), $token)) {
            return null;
        }
        return $data;
    }

    public static function forget(): void
    {
        Session::forget(self::PREVIEW_KEY);
    }

    /**
     * Tulis hasil impor ke database dalam SATU transaksi.
     *
     * @param array<int,array<string,mixed>> $rows Baris dari pratinjau.
     * @return array{created:int, movements:int, type:string}
     */
    public static function commit(string $type, array $rows, ?int $actorId, string $fileName): array
    {
        if (!self::isKnownType($type)) {
            throw new RuntimeException('Jenis impor tidak dikenal.');
        }
        if ($rows === []) {
            throw new RuntimeException('Tidak ada baris untuk diimpor.');
        }

        $seen = [];
        $prepared = [];
        $problems = [];
        foreach ($rows as $row) {
            $line = (int) ($row['line'] ?? 0);
            $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
            $result = self::validateRow($type, $cells, $seen, $line);
            if (!$result['ok']) {
                $problems[] = 'baris ' . $line . ': ' . implode(' ', $result['errors']);
                continue;
            }
            $prepared[] = $result['values'];
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Impor dibatalkan — tidak ada data yang disimpan. Masalah: ' . implode(' | ', array_slice($problems, 0, 5))
                . (count($problems) > 5 ? ' (dan ' . (count($problems) - 5) . ' masalah lain)' : '')
            );
        }

        return Database::transaction(static function () use ($type, $prepared, $actorId, $fileName): array {
            $created = 0;
            $movements = 0;

            foreach ($prepared as $values) {
                if ($type === self::TYPE_SPAREPART) {
                    SparepartService::create([
                        'code'           => $values['code'],
                        'name'           => $values['name'],
                        'category_id'    => $values['category_id'],
                        'brand'          => $values['brand'],
                        'vehicle_model'  => $values['vehicle_model'],
                        'unit'           => $values['unit'],
                        'purchase_price' => $values['purchase_price'],
                        'selling_price'  => $values['selling_price'],
                        'min_stock'      => $values['min_stock'],
                        'supplier_id'    => $values['supplier_id'],
                        'location_id'    => $values['location_id'],
                        'initial_stock'  => $values['stock'],
                        'initial_stock_note' => 'Stok awal dari impor berkas "' . $fileName . '"',
                    ], $actorId);
                    $created++;
                    if ($values['stock'] > 0) {
                        $movements++;
                    }
                    continue;
                }

                StockInitialService::apply(
                    (int) $values['sparepart_id'],
                    (float) $values['quantity'],
                    (string) $values['movement_date'],
                    $values['note'] !== null ? (string) $values['note'] . ' (impor "' . $fileName . '")' : 'Impor "' . $fileName . '"',
                    $actorId,
                    'IMPORT'
                );
                $created++;
                $movements++;
            }

            AuditService::log(
                $type === self::TYPE_SPAREPART ? 'IMPORT_MASTER' : 'IMPORT_STOCK_INITIAL',
                $type === self::TYPE_SPAREPART ? 'spareparts' : 'stock_movements',
                null,
                sprintf(
                    'Impor %s dari berkas "%s" berhasil untuk %d baris',
                    self::titleOf($type),
                    $fileName,
                    $created
                ),
                null,
                ['file' => $fileName, 'baris_dibuat' => $created, 'pergerakan_stok' => $movements]
            );

            return ['created' => $created, 'movements' => $movements, 'type' => $type];
        });
    }

    // ------------------------------------------------------------------
    // Validasi
    // ------------------------------------------------------------------

    /**
     * @param array<string,string> $cells
     * @param array<string,int> $seen Kode yang sudah muncul di berkas (kode → baris pertama)
     * @return array{ok:bool, errors:string[], values:array<string,mixed>}
     */
    private static function validateRow(string $type, array $cells, array &$seen, int $line): array
    {
        return $type === self::TYPE_SPAREPART
            ? self::validateSparepart($cells, $seen, $line)
            : self::validateStockInitial($cells, $seen);
    }

    /**
     * @param array<string,string> $cells
     * @param array<string,int> $seen
     * @return array{ok:bool, errors:string[], values:array<string,mixed>}
     */
    private static function validateSparepart(array $cells, array &$seen, int $line): array
    {
        $errors = [];
        $values = [];

        $code = MasterRules::code($cells['kode'] ?? '');
        if ($code === '') {
            $errors[] = 'Kode wajib diisi.';
        } elseif (mb_strlen($code) > SparepartService::CODE_MAX) {
            $errors[] = 'Kode maksimal ' . SparepartService::CODE_MAX . ' karakter.';
        } elseif (isset($seen[$code])) {
            $errors[] = 'Kode "' . $code . '" duplikat di dalam berkas (sudah ada di baris ' . $seen[$code] . ').';
        } else {
            $seen[$code] = $line;
            if (Sparepart::codeExists($code)) {
                $errors[] = 'Kode "' . $code . '" sudah ada di database (impor tidak menimpa data lama).';
            }
        }
        $values['code'] = $code;

        $name = MasterRules::text($cells['nama'] ?? '');
        if ($name === '') {
            $errors[] = 'Nama wajib diisi.';
        } elseif (mb_strlen($name) > SparepartService::NAME_MAX) {
            $errors[] = 'Nama maksimal ' . SparepartService::NAME_MAX . ' karakter.';
        }
        $values['name'] = $name;

        $category = MasterRules::text($cells['kategori'] ?? '');
        if ($category === '') {
            $errors[] = 'Kategori wajib diisi.';
        } else {
            $found = CategoryService::findForImport($category);
            if ($found === null) {
                $errors[] = 'Kategori "' . $category . '" tidak ditemukan.';
            } elseif ((int) $found['is_active'] !== 1) {
                $errors[] = 'Kategori "' . $category . '" nonaktif sehingga tidak dapat dipakai untuk sparepart baru.';
            } else {
                $values['category_id'] = (int) $found['id'];
            }
        }

        $values['brand'] = MasterRules::text($cells['brand'] ?? '') ?: null;
        $values['vehicle_model'] = MasterRules::text($cells['model'] ?? '') ?: null;
        $unit = MasterRules::text($cells['satuan'] ?? '');
        $values['unit'] = $unit === '' ? 'pcs' : $unit;
        if (mb_strlen((string) $values['unit']) > SparepartService::UNIT_MAX) {
            $errors[] = 'Satuan maksimal ' . SparepartService::UNIT_MAX . ' karakter.';
        }

        $values['purchase_price'] = self::number($cells['harga_beli'] ?? '', 'Harga Beli', $errors, 0.0) ?? 0.0;
        $values['selling_price'] = self::number($cells['harga_jual'] ?? '', 'Harga Jual', $errors, 0.0) ?? 0.0;
        $values['min_stock'] = self::number($cells['stok_minimum'] ?? '', 'Stok Minimum', $errors, 0.0) ?? 0.0;
        $values['stock'] = self::number($cells['stok'] ?? '', 'Stok Awal', $errors, 0.0) ?? 0.0;

        $location = MasterRules::text($cells['lokasi'] ?? '');
        $values['location_id'] = null;
        if ($location !== '') {
            $found = StorageLocationService::findForImport($location);
            if ($found === null) {
                $errors[] = 'Lokasi "' . $location . '" tidak ditemukan.';
            } else {
                $values['location_id'] = (int) $found['id'];
            }
        }

        $supplier = MasterRules::text($cells['supplier'] ?? '');
        $values['supplier_id'] = null;
        if ($supplier !== '') {
            $found = SupplierService::findForImport($supplier);
            if ($found === null) {
                $errors[] = 'Supplier "' . $supplier . '" tidak ditemukan.';
            } else {
                $values['supplier_id'] = (int) $found['id'];
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'values' => $values];
    }

    /**
     * @param array<string,string> $cells
     * @param array<string,int> $seen
     * @return array{ok:bool, errors:string[], values:array<string,mixed>}
     */
    private static function validateStockInitial(array $cells, array &$seen): array
    {
        $errors = [];
        $values = [];

        $code = MasterRules::code($cells['kode'] ?? '');
        if ($code === '') {
            $errors[] = 'Kode wajib diisi.';
        } elseif (isset($seen[$code])) {
            $errors[] = 'Kode "' . $code . '" duplikat di dalam berkas (stok awal hanya boleh sekali per sparepart).';
        } else {
            $seen[$code] = 1;
            $sparepart = SparepartService::findForImport($code);
            if ($sparepart === null) {
                $errors[] = 'Sparepart "' . $code . '" tidak ditemukan.';
            } elseif (StockMovement::hasInitial((int) $sparepart['id'])) {
                $errors[] = 'Stok awal sparepart "' . $code . '" sudah pernah dicatat (koreksi lewat penyesuaian opname).';
            } else {
                $values['sparepart_id'] = (int) $sparepart['id'];
                $values['sparepart_code'] = (string) $sparepart['code'];
                $values['sparepart_name'] = (string) $sparepart['name'];
                $values['unit'] = (string) $sparepart['unit'];
            }
        }
        $values['code'] = $code;

        $rawQuantity = trim((string) ($cells['jumlah'] ?? ''));
        $quantity = MasterRules::parseDecimal($rawQuantity);
        if ($rawQuantity === '') {
            $errors[] = 'Jumlah wajib diisi.';
        } elseif ($quantity === null) {
            $errors[] = 'Jumlah "' . $rawQuantity . '" bukan angka.';
        } elseif ($quantity <= 0) {
            $errors[] = 'Jumlah harus lebih dari 0.';
        }
        $values['quantity'] = $quantity ?? 0.0;

        $rawDate = trim((string) ($cells['tanggal'] ?? ''));
        $date = MasterRules::parseDate($rawDate);
        if ($rawDate !== '' && $date === null) {
            $errors[] = 'Tanggal "' . $rawDate . '" tidak dikenali (gunakan dd/mm/yyyy atau yyyy-mm-dd).';
        }
        $values['movement_date'] = $date ?? date('Y-m-d');
        $values['note'] = MasterRules::text($cells['catatan'] ?? '') ?: null;

        return ['ok' => $errors === [], 'errors' => $errors, 'values' => $values];
    }

    /**
     * Angka opsional dari berkas.
     *
     * @param string[] $errors
     */
    private static function number(string $raw, string $label, array &$errors, float $default): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        $value = MasterRules::parseDecimal($raw);
        if ($value === null) {
            $errors[] = $label . ' "' . $raw . '" bukan angka.';
            return $default;
        }
        if ($value < 0) {
            $errors[] = $label . ' tidak boleh negatif.';
            return $default;
        }
        return $value;
    }

    /**
     * @param array<int,string> $headers
     * @return array{map:array<string,int>, missing:string[], unknown:string[]}
     */
    private static function mapHeaders(array $headers, string $type): array
    {
        $lookup = [];
        foreach (self::fields($type) as $field => $spec) {
            foreach ($spec['aliases'] as $alias) {
                $lookup[self::normalizeHeader($alias)] = $field;
            }
        }

        $map = [];
        $unknown = [];
        foreach ($headers as $column => $header) {
            $key = self::normalizeHeader((string) $header);
            if ($key === '') {
                continue;
            }
            if (isset($lookup[$key])) {
                $field = $lookup[$key];
                if (!isset($map[$field])) {
                    $map[$field] = $column;
                }
                continue;
            }
            $unknown[] = (string) $header;
        }

        $missing = [];
        foreach (self::fields($type) as $field => $spec) {
            if ($spec['required'] && !isset($map[$field])) {
                $missing[] = $spec['label'];
            }
        }

        return ['map' => $map, 'missing' => $missing, 'unknown' => $unknown];
    }

    private static function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
        return trim($header, '_');
    }

    /** @param array<int,string|int|float> $cells */
    private static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $text = (string) $cell;
            $out[] = (str_contains($text, ',') || str_contains($text, '"') || str_contains($text, "\n"))
                ? '"' . str_replace('"', '""', $text) . '"'
                : $text;
        }
        return implode(',', $out);
    }
}
