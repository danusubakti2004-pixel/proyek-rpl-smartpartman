<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Ledger pergerakan stok (lookup PRD §12 / FR-05..FR-10).
 *
 * Tabel ini bersifat APPEND-ONLY: tidak ada metode update/delete di sini.
 * Koreksi dilakukan dengan pergerakan baru bertipe PENYESUAIAN_OPNAME
 * (FR-10), bukan dengan mengubah baris lama.
 *
 * Catatan: stok awal hanya boleh SEKALI per sparepart — dijaga trigger
 * database (migrasi 008) + StockInitialService.
 */
final class StockMovement extends Model
{
    public const TYPE_INITIAL = 'STOK_AWAL';
    public const TYPE_IN = 'MASUK';
    public const TYPE_OUT = 'KELUAR';
    public const TYPE_OPNAME = 'PENYESUAIAN_OPNAME';
    public const TYPE_CORRECTION = 'KOREKSI';
    public const TYPE_ADJUSTMENT = 'PENYESUAIAN';

    /** @var string[] Jenis pergerakan yang sah (dipakai filter & validasi). */
    public const TYPES = [self::TYPE_INITIAL, self::TYPE_IN, self::TYPE_OUT, self::TYPE_OPNAME, self::TYPE_CORRECTION, self::TYPE_ADJUSTMENT];

    /** @var string[] Jenis pergerakan yang boleh dikoreksi lewat transaksi pembalik (FR-07/§7.4). */
    public const CORRECTABLE_TYPES = [self::TYPE_IN, self::TYPE_OUT];

    /**
     * @param array{sparepart_id:int, movement_type:string, quantity:float, movement_date?:string|null,
     *              stock_before:float, stock_after:float, reference_type?:string|null,
     *              reference_id?:int|null, reference_number?:string|null, note?:string|null,
     *              reversal_of_id?:int|null} $data
     */
    public static function insert(array $data, ?int $actorId): int
    {
        self::execute(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after,
                 reference_type, reference_id, reference_number, reversal_of_id, note, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['sparepart_id'],
                (string) $data['movement_type'],
                $data['quantity'],
                $data['movement_date'] ?? date('Y-m-d'),
                $data['stock_before'],
                $data['stock_after'],
                $data['reference_type'] ?? null,
                $data['reference_id'] ?? null,
                $data['reference_number'] ?? null,
                $data['reversal_of_id'] ?? null,
                $data['note'] ?? null,
                $actorId,
            ]
        );
        return self::lastId();
    }

    /** Satu baris ledger + data pendukung (untuk halaman koreksi). @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::selectWithUser() . ' WHERE m.id = ?', [$id]);
    }

    /** Total kuantitas yang sudah dibalik oleh transaksi koreksi untuk sebuah pergerakan. */
    public static function reversedTotal(int $movementId): float
    {
        return (float) self::scalar(
            'SELECT COALESCE(SUM(ABS(quantity)), 0) FROM stock_movements WHERE reversal_of_id = ?',
            [$movementId]
        );
    }

    /**
     * Riwayat pergerakan stok dengan filter (FR-08 "tersedia riwayat pergerakan").
     *
     * @param array{search?:string, sparepart_id?:int|null, movement_type?:string|null,
     *              reference_type?:string|null, date_from?:string|null, date_to?:string|null} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function filtered(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        [$where, $params] = self::buildFilter($filters);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return self::fetchAll(
            self::selectWithUser() . $where . ' ORDER BY m.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public static function countFiltered(array $filters = []): int
    {
        [$where, $params] = self::buildFilter($filters);
        return (int) self::scalar(
            'SELECT COUNT(*) FROM stock_movements m
               JOIN spareparts s ON s.id = m.sparepart_id' . $where,
            $params
        );
    }

    /**
     * Total masuk/keluar pada rentang tanggal (untuk ringkasan halaman riwayat).
     *
     * @param array<string,mixed> $filters
     * @return array{masuk:float, keluar:float, menyesuaikan:float}
     */
    public static function totals(array $filters = []): array
    {
        [$where, $params] = self::buildFilter($filters);
        $row = self::fetchOne(
            'SELECT
                COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END), 0) AS masuk,
                COALESCE(SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END), 0) AS keluar,
                COALESCE(SUM(m.quantity), 0) AS menyesuaikan
               FROM stock_movements m
               JOIN spareparts s ON s.id = m.sparepart_id' . $where,
            $params
        ) ?? ['masuk' => 0, 'keluar' => 0, 'menyesuaikan' => 0];

        return [
            'masuk'       => (float) $row['masuk'],
            'keluar'      => (float) $row['keluar'],
            'menyesuaikan' => (float) $row['menyesuaikan'],
        ];
    }

    /** Pergerakan yang masih dapat dikoreksi (MASUK/KELUAR) untuk halaman koreksi. @return array<int,array<string,mixed>> */
    public static function correctable(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return self::fetchAll(
            self::selectWithUser() . "
             WHERE m.movement_type IN ('MASUK', 'KELUAR')
               AND COALESCE((SELECT SUM(ABS(k.quantity)) FROM stock_movements k WHERE k.reversal_of_id = m.id), 0) < ABS(m.quantity)
             ORDER BY m.id DESC LIMIT " . $limit
        );
    }

    public static function hasInitial(int $sparepartId): bool
    {
        return self::scalar(
            'SELECT id FROM stock_movements WHERE sparepart_id = ? AND movement_type = ? LIMIT 1',
            [$sparepartId, self::TYPE_INITIAL]
        ) !== null;
    }

    /** @return array<string,mixed>|null */
    public static function initialFor(int $sparepartId): ?array
    {
        return self::fetchOne(
            'SELECT * FROM stock_movements WHERE sparepart_id = ? AND movement_type = ? ORDER BY id ASC LIMIT 1',
            [$sparepartId, self::TYPE_INITIAL]
        );
    }

    /**
     * Pergerakan yang dihasilkan sebuah dokumen (penerimaan/pengeluaran).
     *
     * @return array<string,mixed>|null
     */
    public static function forReference(string $referenceType, int $referenceId): ?array
    {
        return self::fetchOne(
            self::selectWithUser() . ' WHERE m.reference_type = ? AND m.reference_id = ? ORDER BY m.id ASC LIMIT 1',
            [$referenceType, $referenceId]
        );
    }

    /** Pergerakan koreksi (pembalik) yang menunjuk baris ledger tertentu. @return array<int,array<string,mixed>> */
    public static function reversalsOf(int $movementId): array
    {
        return self::fetchAll(
            self::selectWithUser() . ' WHERE m.reversal_of_id = ? ORDER BY m.id ASC',
            [$movementId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function latestFor(int $sparepartId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return self::fetchAll(
            'SELECT m.*, u.full_name AS user_name, u.username
               FROM stock_movements m
          LEFT JOIN users u ON u.id = m.user_id
              WHERE m.sparepart_id = ?
           ORDER BY m.id DESC
              LIMIT ' . $limit,
            [$sparepartId]
        );
    }

    /** @return array<string,int> */
    public static function countByType(): array
    {
        $rows = self::fetchAll('SELECT movement_type, COUNT(*) AS jumlah FROM stock_movements GROUP BY movement_type');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['movement_type']] = (int) $row['jumlah'];
        }
        return $out;
    }

    /** SELECT dasar: ledger + sparepart + pengguna pencatat. */
    private static function selectWithUser(): string
    {
        return 'SELECT m.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                       u.full_name AS user_name, u.username
                  FROM stock_movements m
                  JOIN spareparts s ON s.id = m.sparepart_id
             LEFT JOIN users u ON u.id = m.user_id';
    }

    /**
     * Rakit klausa WHERE dari filter (selalu prepared statement).
     *
     * @param array{search?:string, sparepart_id?:int|null, movement_type?:string|null,
     *              reference_type?:string|null, date_from?:string|null, date_to?:string|null} $filters
     * @return array{0:string, 1:array<int,mixed>}
     */
    private static function buildFilter(array $filters): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (s.code LIKE ? OR s.name LIKE ? OR m.reference_number LIKE ? OR m.note LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sparepartId = (int) ($filters['sparepart_id'] ?? 0);
        if ($sparepartId > 0) {
            $where .= ' AND m.sparepart_id = ?';
            $params[] = $sparepartId;
        }

        $type = trim((string) ($filters['movement_type'] ?? ''));
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where .= ' AND m.movement_type = ?';
            $params[] = $type;
        }

        $referenceType = trim((string) ($filters['reference_type'] ?? ''));
        if ($referenceType !== '') {
            $where .= ' AND m.reference_type = ?';
            $params[] = $referenceType;
        }

        $dateFrom = self::normalizeDate($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $where .= ' AND m.movement_date >= ?';
            $params[] = $dateFrom;
        }

        $dateTo = self::normalizeDate($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $where .= ' AND m.movement_date <= ?';
            $params[] = $dateTo;
        }

        return [$where, $params];
    }

    /** Terima YYYY-MM-DD atau dd/mm/yyyy; kembalikan YYYY-MM-DD atau null. */
    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) === 1) {
            return $value;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }
}
