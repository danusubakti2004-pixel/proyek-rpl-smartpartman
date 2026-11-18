<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StorageLocation;
use App\Models\Supplier;
use RuntimeException;

/**
 * Master sparepart (FR-03).
 *
 * Aturan:
 * - Kode sparepart unik (tolak duplikat).
 * - Kategori WAJIB dan harus AKTIF untuk sparepart baru (FR-12).
 * - Supplier/lokasi opsional; bila diisi harus ada di master.
 *   (Supplier nonaktif hanya dilarang untuk TRANSAKSI baru — lihat
 *    SupplierService::ensureUsableForTransaction, dipakai Fase 4.)
 * - `stock` TIDAK pernah diubah lewat ubah master; stok hanya berubah
 *   lewat ledger (stok awal Fase 3, barang masuk/keluar Fase 4, opname).
 * - Semua perubahan tercatat di Audit Log.
 */
final class SparepartService
{
    public const CODE_MAX = 50;
    public const NAME_MAX = 200;
    public const UNIT_MAX = 30;

    /** @return array<int,array<string,mixed>> */
    public static function all(array $filters = []): array
    {
        $clean = [
            'search'       => MasterRules::text($filters['search'] ?? ''),
            'category_id'  => !empty($filters['category_id']) ? (int) $filters['category_id'] : null,
            'supplier_id'  => !empty($filters['supplier_id']) ? (int) $filters['supplier_id'] : null,
            'location_id'  => !empty($filters['location_id']) ? (int) $filters['location_id'] : null,
            'stock_status' => in_array(($filters['stock_status'] ?? ''), Sparepart::STOCK_STATUSES, true)
                ? (string) $filters['stock_status']
                : null,
            'active'       => in_array(($filters['active'] ?? ''), ['aktif', 'nonaktif'], true)
                ? (string) $filters['active']
                : null,
        ];
        return Sparepart::all($clean);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Sparepart::find($id);
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return Sparepart::findByCode(MasterRules::code($code));
    }

    /** @return array<string,mixed>|null Untuk import: cocokkan kode dahulu, lalu nama. */
    public static function findForImport(string $value): ?array
    {
        $value = MasterRules::text($value);
        if ($value === '') {
            return null;
        }
        $byCode = Sparepart::findByCode(MasterRules::code($value));
        return $byCode ?? Sparepart::findByName($value);
    }

    public static function stockStatusOf(array $row): string
    {
        return Sparepart::stockStatusOf($row);
    }

    /** @return array{total:int,aktif:int,nonaktif:int,habis:int,kritis:int,menipis:int,aman:int,tanpa_minimum:int} */
    public static function countByStatus(): array
    {
        return Sparepart::countByStatus();
    }

    /**
     * Label status stok + warna Bootstrap (FR-08, PRD §13.3).
     *
     * @return array{0:string,1:string} [label, warna]
     */
    public static function statusLabel(string $status): array
    {
        return match ($status) {
            Sparepart::STATUS_HABIS         => ['Habis', 'danger'],
            Sparepart::STATUS_KRITIS        => ['Kritis', 'danger'],
            Sparepart::STATUS_MENIPIS       => ['Menipis', 'warning'],
            Sparepart::STATUS_TANPA_MINIMUM => ['Stok Minimum Belum Diatur', 'secondary'],
            default                         => ['Aman', 'success'],
        };
    }

    /** Persentase stok terhadap minimum (null bila stok minimum tidak valid). */
    public static function statusPercent(array $row): ?float
    {
        return Sparepart::stockPercentOf($row);
    }

    /**
     * Daftar sparepart yang perlu perhatian (notifikasi dashboard, FR-08).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function needingAttention(int $limit = 10): array
    {
        return Sparepart::needingAttention($limit);
    }

    /**
     * Buat sparepart baru. Bila `initial_stock` > 0, stok awal sekaligus
     * dicatatkan sebagai pergerakan STOK_AWAL (ledger tetap utuh).
     *
     * @param array<string,mixed> $data
     * @return int ID sparepart baru
     */
    public static function create(array $data, ?int $actorId = null): int
    {
        $clean = self::normalize($data, null);
        self::guard($clean);

        if (Sparepart::codeExists($clean['code'])) {
            throw new RuntimeException('Kode sparepart "' . $clean['code'] . '" sudah dipakai. Gunakan kode lain.');
        }

        $initialStock = MasterRules::parseDecimal($data['initial_stock'] ?? null);
        if ($initialStock !== null && $initialStock < 0) {
            throw new RuntimeException('Stok awal tidak boleh negatif.');
        }

        return Database::transaction(static function () use ($clean, $initialStock, $data, $actorId): int {
            $id = Sparepart::insert($clean, $actorId);

            AuditService::log(
                'SPAREPART_CREATE',
                'spareparts',
                (string) $id,
                sprintf('Menambah sparepart "%s" (%s)', $clean['name'], $clean['code']),
                null,
                [
                    'code'          => $clean['code'],
                    'name'          => $clean['name'],
                    'category_id'   => $clean['category_id'],
                    'brand'         => $clean['brand'],
                    'vehicle_model' => $clean['vehicle_model'],
                    'unit'          => $clean['unit'],
                    'purchase_price' => $clean['purchase_price'],
                    'selling_price' => $clean['selling_price'],
                    'min_stock'     => $clean['min_stock'],
                    'location_id'   => $clean['location_id'],
                    'supplier_id'   => $clean['supplier_id'],
                    'is_active'     => 1,
                ]
            );

            if ($initialStock !== null && $initialStock > 0) {
                StockInitialService::apply(
                    $id,
                    $initialStock,
                    isset($data['initial_stock_date']) ? (string) $data['initial_stock_date'] : null,
                    isset($data['initial_stock_note']) ? (string) $data['initial_stock_note'] : null,
                    $actorId,
                    'MANUAL'
                );
            }

            return $id;
        });
    }

    /**
     * Ubah master sparepart (tanpa mengubah stok).
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data, ?int $actorId = null): void
    {
        $existing = self::require($id);
        $clean = self::normalize($data, (int) $existing['category_id']);
        self::guard($clean);

        if (Sparepart::codeExists($clean['code'], $id)) {
            throw new RuntimeException('Kode sparepart "' . $clean['code'] . '" sudah dipakai. Gunakan kode lain.');
        }

        Database::transaction(static function () use ($id, $clean, $existing, $actorId): void {
            Sparepart::update($id, $clean, $actorId);
            AuditService::log(
                'SPAREPART_UPDATE',
                'spareparts',
                (string) $id,
                sprintf('Mengubah sparepart "%s"', $clean['name']),
                [
                    'code'          => (string) $existing['code'],
                    'name'          => (string) $existing['name'],
                    'category_id'   => (int) $existing['category_id'],
                    'brand'         => $existing['brand'],
                    'vehicle_model' => $existing['vehicle_model'],
                    'unit'          => (string) $existing['unit'],
                    'purchase_price' => (float) $existing['purchase_price'],
                    'selling_price' => (float) $existing['selling_price'],
                    'min_stock'     => (float) $existing['min_stock'],
                    'location_id'   => $existing['location_id'],
                    'supplier_id'   => $existing['supplier_id'],
                ],
                [
                    'code'          => $clean['code'],
                    'name'          => $clean['name'],
                    'category_id'   => $clean['category_id'],
                    'brand'         => $clean['brand'],
                    'vehicle_model' => $clean['vehicle_model'],
                    'unit'          => $clean['unit'],
                    'purchase_price' => $clean['purchase_price'],
                    'selling_price' => $clean['selling_price'],
                    'min_stock'     => $clean['min_stock'],
                    'location_id'   => $clean['location_id'],
                    'supplier_id'   => $clean['supplier_id'],
                ]
            );
        });
    }

    /** Aktifkan / nonaktifkan sparepart (data & riwayat tetap utuh). */
    public static function setActive(int $id, bool $active, ?int $actorId = null): void
    {
        $existing = self::require($id);
        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            throw new RuntimeException('Sparepart sudah dalam status ' . ($active ? 'aktif' : 'nonaktif') . '.');
        }

        Database::transaction(static function () use ($id, $active, $existing, $actorId): void {
            Sparepart::setActive($id, $active, $actorId);
            AuditService::log(
                $active ? 'SPAREPART_ACTIVATE' : 'SPAREPART_DEACTIVATE',
                'spareparts',
                (string) $id,
                sprintf(
                    '%s sparepart "%s" (%s)',
                    $active ? 'Mengaktifkan' : 'Menonaktifkan',
                    (string) $existing['name'],
                    (string) $existing['code']
                ),
                ['is_active' => (int) $existing['is_active']],
                ['is_active' => $active ? 1 : 0]
            );
        });
    }

    /**
     * Normalkan input form/import menjadi bentuk kolom tabel.
     *
     * @param array<string,mixed> $data
     * @param int|null $currentCategoryId Kategori yang sedang dipakai (saat ubah)
     *        — kategori nonaktif tetap diizinkan selama tidak diganti.
     * @return array<string,mixed>
     */
    public static function normalize(array $data, ?int $currentCategoryId): array
    {
        $purchase = MasterRules::parseDecimal($data['purchase_price'] ?? null);
        $selling = MasterRules::parseDecimal($data['selling_price'] ?? null);
        $minStock = MasterRules::parseDecimal($data['min_stock'] ?? null);

        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('Kategori wajib dipilih.');
        }
        if ($categoryId !== $currentCategoryId) {
            // Kategori baru dipilih → wajib aktif (FR-12).
            CategoryService::resolveUsable($categoryId);
        } elseif (CategoryService::find($categoryId) === null) {
            throw new RuntimeException('Kategori tidak ditemukan.');
        }

        $supplierId = (int) ($data['supplier_id'] ?? 0);
        if ($supplierId > 0 && Supplier::find($supplierId) === null) {
            throw new RuntimeException('Supplier tidak ditemukan.');
        }

        $locationId = (int) ($data['location_id'] ?? 0);
        if ($locationId > 0 && StorageLocation::find($locationId) === null) {
            throw new RuntimeException('Lokasi penyimpanan tidak ditemukan.');
        }

        $unit = MasterRules::text($data['unit'] ?? '');
        if ($unit === '') {
            $unit = 'pcs';
        }

        return [
            'code'           => MasterRules::code($data['code'] ?? ''),
            'name'           => MasterRules::text($data['name'] ?? ''),
            'category_id'    => $categoryId,
            'brand'          => MasterRules::text($data['brand'] ?? '') ?: null,
            'vehicle_model'  => MasterRules::text($data['vehicle_model'] ?? '') ?: null,
            'unit'           => $unit,
            'purchase_price' => $purchase ?? 0.0,
            'selling_price'  => $selling ?? 0.0,
            'min_stock'      => $minStock ?? 0.0,
            'supplier_id'    => $supplierId > 0 ? $supplierId : null,
            'location_id'    => $locationId > 0 ? $locationId : null,
        ];
    }

    /** @param array<string,mixed> $clean */
    private static function guard(array $clean): void
    {
        if ($clean['code'] === '') {
            throw new RuntimeException('Kode sparepart wajib diisi.');
        }
        if (mb_strlen($clean['code']) > self::CODE_MAX) {
            throw new RuntimeException('Kode sparepart maksimal ' . self::CODE_MAX . ' karakter.');
        }
        if ($clean['name'] === '') {
            throw new RuntimeException('Nama sparepart wajib diisi.');
        }
        if (mb_strlen($clean['name']) > self::NAME_MAX) {
            throw new RuntimeException('Nama sparepart maksimal ' . self::NAME_MAX . ' karakter.');
        }
        if (mb_strlen((string) $clean['unit']) > self::UNIT_MAX) {
            throw new RuntimeException('Satuan maksimal ' . self::UNIT_MAX . ' karakter.');
        }
        if ((float) $clean['purchase_price'] < 0) {
            throw new RuntimeException('Harga beli tidak boleh negatif.');
        }
        if ((float) $clean['selling_price'] < 0) {
            throw new RuntimeException('Harga jual tidak boleh negatif.');
        }
        if ((float) $clean['min_stock'] < 0) {
            throw new RuntimeException('Stok minimum tidak boleh negatif.');
        }
    }

    /** @return array<string,mixed> */
    private static function require(int $id): array
    {
        $sparepart = Sparepart::find($id);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        return $sparepart;
    }
}
