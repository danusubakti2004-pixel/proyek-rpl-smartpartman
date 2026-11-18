<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Master sparepart (FR-03).
 *
 * Catatan: kolom `stock` HANYA berubah lewat service stok/transaksi
 * (stok awal Fase 3, barang masuk/keluar Fase 4) — model ini menyediakan
 * updateStock() untuk dipakai DI DALAM transaksi service tersebut.
 */
final class Sparepart extends Model
{
    public const STATUS_HABIS = 'habis';
    public const STATUS_KRITIS = 'kritis';
    public const STATUS_MENIPIS = 'menipis';
    public const STATUS_AMAN = 'aman';
    public const STATUS_TANPA_MINIMUM = 'tanpa_minimum';

    /**
     * Status persentase terhadap stok minimum (PRD §13.3):
     *   aman            : stok > 150% stok minimum
     *   menipis         : 100% – 150% stok minimum
     *   kritis          : 0 < stok < 100% stok minimum
     *   habis           : stok = 0
     *   tanpa_minimum   : stok minimum tidak valid/0 → status pengecualian
     *                     eksplisit (tidak pernah membagi dengan nol).
     *
     * @var string[]
     */
    public const STOCK_STATUSES = [
        self::STATUS_HABIS,
        self::STATUS_KRITIS,
        self::STATUS_MENIPIS,
        self::STATUS_AMAN,
        self::STATUS_TANPA_MINIMUM,
    ];

    /** Ambang "menipis" (100%–150% dari stok minimum, PRD §13.3). */
    public const DILUT_RATIO = 1.5;

    /**
     * @param array{search?:string, category_id?:int|null, supplier_id?:int|null, location_id?:int|null,
     *              stock_status?:string|null, active?:string|null} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function all(array $filters = []): array
    {
        $sql = self::baseSelect() . ' WHERE 1 = 1';
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (s.code LIKE :q1 OR s.name LIKE :q2 OR s.brand LIKE :q3 OR s.vehicle_model LIKE :q4)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }
        if (!empty($filters['category_id'])) {
            $sql .= ' AND s.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['supplier_id'])) {
            $sql .= ' AND s.supplier_id = :supplier_id';
            $params[':supplier_id'] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['location_id'])) {
            $sql .= ' AND s.location_id = :location_id';
            $params[':location_id'] = (int) $filters['location_id'];
        }
        if (($filters['active'] ?? '') === 'aktif') {
            $sql .= ' AND s.is_active = 1';
        } elseif (($filters['active'] ?? '') === 'nonaktif') {
            $sql .= ' AND s.is_active = 0';
        }

        $status = (string) ($filters['stock_status'] ?? '');
        if (in_array($status, self::STOCK_STATUSES, true)) {
            $sql .= ' AND ' . self::stockStatusCondition($status);
        }

        return self::fetchAll($sql . ' ORDER BY s.name ASC', $params);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::baseSelect() . ' WHERE s.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return self::fetchOne(self::baseSelect() . ' WHERE s.code = ?', [$code]);
    }

    /**
     * Daftar ringkas sparepart untuk pilihan formulir (dropdown).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function options(bool $onlyActive = true, ?string $search = null, int $limit = 500): array
    {
        $sql = 'SELECT s.id, s.code, s.name, s.unit, s.stock, s.min_stock, s.is_active
                  FROM spareparts s WHERE 1 = 1';
        $params = [];

        if ($onlyActive) {
            $sql .= ' AND s.is_active = 1';
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $sql .= ' AND (s.code LIKE ? OR s.name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }

        $limit = max(1, min(1000, $limit));

        return self::fetchAll($sql . ' ORDER BY s.name ASC LIMIT ' . $limit, $params);
    }

    /** @return array<string,mixed>|null */
    public static function findByName(string $name): ?array
    {
        return self::fetchOne(self::baseSelect() . ' WHERE s.name = ? ORDER BY s.id ASC LIMIT 1', [$name]);
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM spareparts WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return self::scalar($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * Status stok turunan (FR-08, PRD §13.3).
     *
     * Bila `min_stock` tidak valid (<= 0) sedangkan stok masih ada, sistem
     * TIDAK membagi dengan nol: dikembalikan status pengecualian eksplisit
     * `tanpa_minimum` agar pengguna memperbaiki konfigurasi stok minimum.
     *
     * @param array<string,mixed> $row
     */
    public static function stockStatusOf(array $row): string
    {
        $stock = (float) ($row['stock'] ?? 0);
        $min = (float) ($row['min_stock'] ?? 0);

        if ($stock <= 0) {
            return self::STATUS_HABIS;
        }
        if ($min <= 0) {
            return self::STATUS_TANPA_MINIMUM;
        }
        if ($stock < $min) {
            return self::STATUS_KRITIS;
        }
        if ($stock <= $min * self::DILUT_RATIO) {
            return self::STATUS_MENIPIS;
        }
        return self::STATUS_AMAN;
    }

    /**
     * Persentase stok terhadap minimum (PRD §13.3) — null bila tidak terdefinisi.
     *
     * @param array<string,mixed> $row
     */
    public static function stockPercentOf(array $row): ?float
    {
        $min = (float) ($row['min_stock'] ?? 0);
        if ($min <= 0) {
            return null;
        }
        return round(((float) ($row['stock'] ?? 0)) / $min * 100, 1);
    }

    /** @return array{total:int,aktif:int,nonaktif:int,habis:int,kritis:int,menipis:int,aman:int,tanpa_minimum:int} */
    public static function countByStatus(): array
    {
        $rows = self::fetchAll('SELECT stock, min_stock, is_active FROM spareparts');
        $stats = [
            'total'         => count($rows),
            'aktif'         => 0,
            'nonaktif'      => 0,
            'habis'         => 0,
            'kritis'        => 0,
            'menipis'       => 0,
            'aman'          => 0,
            'tanpa_minimum' => 0,
        ];
        foreach ($rows as $row) {
            if ((int) $row['is_active'] === 1) {
                $stats['aktif']++;
            } else {
                $stats['nonaktif']++;
            }
            $stats[self::stockStatusOf($row)]++;
        }
        return $stats;
    }

    /**
     * Sparepart AKTIF yang perlu perhatian (habis/kritis/menipis) atau
     * belum punya stok minimum valid — untuk notifikasi dashboard (FR-08).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function needingAttention(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return self::fetchAll(
            self::baseSelect() . '
              WHERE s.is_active = 1
                AND (s.stock <= 0 OR s.min_stock <= 0 OR s.stock <= s.min_stock * ?)
           ORDER BY (s.stock - s.min_stock) ASC, s.name ASC
              LIMIT ' . $limit,
            [self::DILUT_RATIO]
        );
    }

    /**
     * Kunci baris sparepart untuk pembaruan stok (SELECT ... FOR UPDATE).
     *
     * WAJIB dipanggil dari dalam transaksi database: baris terkunci sampai
     * transaksi selesai sehingga dua transaksi bersamaan tidak saling
     * menimpa (PRD §10.2). Mengembalikan null bila sparepart tidak ada.
     *
     * @return array{id:int,code:string,name:string,unit:string,stock:float,min_stock:float,
     *               purchase_price:float,avg_purchase_price:float|null,last_purchase_date:string|null}|null
     */
    public static function lockForUpdate(int $id): ?array
    {
        $row = self::fetchOne(
            'SELECT id, code, name, unit, stock, min_stock, purchase_price, avg_purchase_price, last_purchase_date
               FROM spareparts WHERE id = ? FOR UPDATE',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'                   => (int) $row['id'],
            'code'                 => (string) $row['code'],
            'name'                 => (string) $row['name'],
            'unit'                 => (string) $row['unit'],
            'stock'                => (float) $row['stock'],
            'min_stock'            => (float) $row['min_stock'],
            'purchase_price'       => (float) $row['purchase_price'],
            'avg_purchase_price'   => $row['avg_purchase_price'] !== null ? (float) $row['avg_purchase_price'] : null,
            'last_purchase_date'   => $row['last_purchase_date'] !== null ? (string) $row['last_purchase_date'] : null,
        ];
    }

    /**
     * Perbarui informasi pembelian. Dipakai HANYA untuk penerimaan bertipe
     * pembelian (FR-06): tanggal pembelian terakhir, harga beli terakhir
     * (`purchase_price`), dan harga beli rata-rata tertimbang
     * (`avg_purchase_price`, weighted average untuk nilai persediaan).
     *
     * Riwayat harga tetap dapat ditelusuri lewat `goods_receipt_items.unit_price`
     * (lihat `purchasePriceHistory()`), sehingga harga master hanya menyimpan
     * nilai terkini — bukan satu-satunya sumber data.
     *
     * Keputusan pemilik produk (Fase 4): `purchase_price` mengikuti harga
     * pembelian terakhir; weighted average dihitung terpisah.
     */
    public static function applyPurchase(
        int $id,
        string $purchaseDate,
        float $qtyReceived,
        float $unitPrice,
        float $stockBefore,
        ?float $avgBefore,
        ?int $actorId
    ): void {
        $avg = $avgBefore ?? 0.0;
        $totalQty = $stockBefore + max(0.0, $qtyReceived);
        $newAvg = $totalQty > 0
            ? round((($stockBefore * $avg) + ($qtyReceived * $unitPrice)) / $totalQty, 2)
            : round($unitPrice, 2);
        $lastPrice = round($unitPrice, 2);

        self::execute(
            'UPDATE spareparts
                SET last_purchase_date = ?, avg_purchase_price = ?, purchase_price = ?, updated_by = ?
              WHERE id = ?',
            [$purchaseDate, $newAvg, $lastPrice, $actorId, $id]
        );
    }

    /**
     * Riwayat harga pembelian (hanya penerimaan bertipe `pembelian`).
     * Dipakai halaman detail sparepart agar perubahan harga dapat ditelusuri.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function purchasePriceHistory(int $id, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return self::fetchAll(
            'SELECT r.receipt_number, r.receipt_date, r.invoice_number,
                    i.quantity, i.unit_price, s.name AS supplier_name
               FROM goods_receipt_items i
               JOIN goods_receipts r ON r.id = i.receipt_id
          LEFT JOIN suppliers s ON s.id = r.supplier_id
              WHERE i.sparepart_id = ?
                AND r.receipt_type = \'pembelian\'
           ORDER BY r.receipt_date DESC, r.id DESC
              LIMIT ' . $limit,
            [$id]
        );
    }

    /** @param array<string,mixed> $data */
    public static function insert(array $data, ?int $actorId): int
    {
        self::execute(
            'INSERT INTO spareparts
                (code, name, category_id, brand, vehicle_model, unit, purchase_price, selling_price,
                 stock, min_stock, location_id, supplier_id, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?)',
            [
                $data['code'],
                $data['name'],
                (int) $data['category_id'],
                $data['brand'] ?? null,
                $data['vehicle_model'] ?? null,
                $data['unit'] ?? 'pcs',
                $data['purchase_price'] ?? 0,
                $data['selling_price'] ?? 0,
                $data['min_stock'] ?? 0,
                $data['location_id'] ?? null,
                $data['supplier_id'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $actorId): void
    {
        self::execute(
            'UPDATE spareparts
                SET code = ?, name = ?, category_id = ?, brand = ?, vehicle_model = ?, unit = ?,
                    purchase_price = ?, selling_price = ?, min_stock = ?, location_id = ?, supplier_id = ?,
                    updated_by = ?
              WHERE id = ?',
            [
                $data['code'],
                $data['name'],
                (int) $data['category_id'],
                $data['brand'] ?? null,
                $data['vehicle_model'] ?? null,
                $data['unit'] ?? 'pcs',
                $data['purchase_price'] ?? 0,
                $data['selling_price'] ?? 0,
                $data['min_stock'] ?? 0,
                $data['location_id'] ?? null,
                $data['supplier_id'] ?? null,
                $actorId,
                $id,
            ]
        );
    }

    public static function setActive(int $id, bool $active, ?int $actorId): void
    {
        self::execute(
            'UPDATE spareparts SET is_active = ?, updated_by = ? WHERE id = ?',
            [$active ? 1 : 0, $actorId, $id]
        );
    }

    /** Dipakai HANYA dari dalam transaksi stok (stok awal / barang masuk-keluar). */
    public static function updateStock(int $id, float $newStock, ?int $actorId = null): void
    {
        self::execute(
            'UPDATE spareparts SET stock = ?, updated_by = ? WHERE id = ?',
            [$newStock, $actorId, $id]
        );
    }

    private static function baseSelect(): string
    {
        return 'SELECT s.id, s.code, s.name, s.brand, s.vehicle_model, s.unit, s.purchase_price, s.selling_price,
                       s.avg_purchase_price, s.stock, s.min_stock, s.last_purchase_date, s.is_active,
                       s.category_id, s.supplier_id, s.location_id, s.created_at, s.updated_at,
                       c.code AS category_code, c.name AS category_name, c.is_active AS category_active,
                       sup.code AS supplier_code, sup.name AS supplier_name, sup.is_active AS supplier_active,
                       l.code AS location_code, l.name AS location_name, l.is_active AS location_active
                  FROM spareparts s
                  JOIN categories c ON c.id = s.category_id
             LEFT JOIN suppliers sup ON sup.id = s.supplier_id
             LEFT JOIN storage_locations l ON l.id = s.location_id';
    }

    private static function stockStatusCondition(string $status): string
    {
        return match ($status) {
            self::STATUS_HABIS          => 's.stock <= 0',
            self::STATUS_KRITIS         => 's.stock > 0 AND s.min_stock > 0 AND s.stock < s.min_stock',
            self::STATUS_MENIPIS        => 's.stock > 0 AND s.min_stock > 0 AND s.stock >= s.min_stock AND s.stock <= s.min_stock * ' . self::DILUT_RATIO,
            self::STATUS_TANPA_MINIMUM  => 's.stock > 0 AND s.min_stock <= 0',
            default                     => 's.stock > 0 AND s.min_stock > 0 AND s.stock > s.min_stock * ' . self::DILUT_RATIO,
        };
    }
}
