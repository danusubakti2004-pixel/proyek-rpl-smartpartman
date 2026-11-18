<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Query analitik baca-saja untuk dashboard (Fase 5).
 *
 * Tidak ada operasi tulis di kelas ini. Angka yang disajikan dashboard
 * dihitung langsung dari database (bukan cache) agar selalu konsisten
 * dengan ledger dan dokumen transaksi.
 *
 * Catatan definisi:
 * - "Barang masuk/keluar" pada grafik memakai baris ledger bertipe
 *   MASUK/KELUAR. Koreksi (KOREKSI) dan penyesuaian opname tidak
 *   dimasukkan karena keduanya adalah penyesuaian, bukan arus barang.
 * - Nilai persediaan memakai `stock × avg_purchase_price` (weighted
 *   average, keputusan Fase 4) dengan fallback harga beli terakhir.
 */
final class Analytics extends Model
{
    /**
     * @return array{types:int,stock:float,value_last:float,value_avg:float,tanpa_minimum:int}
     */
    public static function sparepartSummary(bool $activeOnly = true): array
    {
        $where = $activeOnly ? ' WHERE is_active = 1' : '';
        $row = self::fetchOne(
            'SELECT COUNT(*) AS types,
                    COALESCE(SUM(stock), 0) AS stock,
                    COALESCE(SUM(stock * purchase_price), 0) AS value_last,
                    COALESCE(SUM(stock * COALESCE(avg_purchase_price, purchase_price)), 0) AS value_avg,
                    COALESCE(SUM(CASE WHEN stock > 0 AND min_stock <= 0 THEN 1 ELSE 0 END), 0) AS tanpa_minimum
               FROM spareparts' . $where
        );

        return [
            'types'          => (int) ($row['types'] ?? 0),
            'stock'          => (float) ($row['stock'] ?? 0),
            'value_last'     => (float) ($row['value_last'] ?? 0),
            'value_avg'      => (float) ($row['value_avg'] ?? 0),
            'tanpa_minimum'  => (int) ($row['tanpa_minimum'] ?? 0),
        ];
    }

    /**
     * Arus masuk/keluar per tanggal (hanya hari yang ada pergerakan).
     *
     * @return array<string,array{masuk:float,keluar:float}>
     */
    public static function movementSeries(string $from, string $to): array
    {
        $rows = self::fetchAll(
            "SELECT movement_date,
                    COALESCE(SUM(CASE WHEN movement_type = 'MASUK' THEN quantity ELSE 0 END), 0) AS masuk,
                    COALESCE(SUM(CASE WHEN movement_type = 'KELUAR' THEN -quantity ELSE 0 END), 0) AS keluar
               FROM stock_movements
              WHERE movement_date BETWEEN ? AND ?
           GROUP BY movement_date
           ORDER BY movement_date ASC",
            [$from, $to]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['movement_date']] = [
                'masuk'  => (float) $row['masuk'],
                'keluar' => (float) $row['keluar'],
            ];
        }
        return $out;
    }

    /**
     * Status stok dihitung dengan aturan yang sama seperti master sparepart
     * (PRD §13.3), tetapi dapat dibatasi ke sparepart aktif saja.
     *
     * @return array{total:int,habis:int,kritis:int,menipis:int,aman:int,tanpa_minimum:int}
     */
    public static function stockStatusCounts(bool $activeOnly = true): array
    {
        $rows = self::fetchAll(
            'SELECT stock, min_stock FROM spareparts' . ($activeOnly ? ' WHERE is_active = 1' : '')
        );
        $stats = [
            'total'         => count($rows),
            'habis'         => 0,
            'kritis'        => 0,
            'menipis'       => 0,
            'aman'          => 0,
            'tanpa_minimum' => 0,
        ];
        foreach ($rows as $row) {
            $stats[Sparepart::stockStatusOf($row)]++;
        }
        return $stats;
    }

    /**
     * @return array{masuk_qty:float,keluar_qty:float,receipts:int,issues:int}
     */
    public static function periodTotals(string $from, string $to): array
    {
        $ledger = self::fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN movement_type = 'MASUK' THEN quantity ELSE 0 END), 0) AS masuk,
                    COALESCE(SUM(CASE WHEN movement_type = 'KELUAR' THEN -quantity ELSE 0 END), 0) AS keluar
               FROM stock_movements
              WHERE movement_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $docs = self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN ? AND ?) AS receipts,
                    (SELECT COUNT(*) FROM goods_issues WHERE issue_date BETWEEN ? AND ?) AS issues',
            [$from, $to, $from, $to]
        );

        return [
            'masuk_qty'  => (float) ($ledger['masuk'] ?? 0),
            'keluar_qty' => (float) ($ledger['keluar'] ?? 0),
            'receipts'   => (int) ($docs['receipts'] ?? 0),
            'issues'     => (int) ($docs['issues'] ?? 0),
        ];
    }

    /** @return array{masuk_qty:float,keluar_qty:float,receipts:int,issues:int} */
    public static function todayTotals(): array
    {
        $today = date('Y-m-d');
        return self::periodTotals($today, $today);
    }

    /**
     * Transaksi terbaru (gabungan penerimaan & pengeluaran).
     *
     * @return array<int,array{kind:string,id:int,number:string,doc_date:string,party:string,qty:float,actor:string,created_at:string}>
     */
    public static function latestTransactions(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));

        $rows = self::fetchAll(
            "SELECT * FROM (
                SELECT 'masuk' AS kind, r.id, r.receipt_number AS number, r.receipt_date AS doc_date,
                       COALESCE(sup.name, '-') AS party, COALESCE(SUM(i.quantity), 0) AS qty,
                       COALESCE(u.full_name, '-') AS actor, r.created_at
                  FROM goods_receipts r
             LEFT JOIN suppliers sup ON sup.id = r.supplier_id
             LEFT JOIN goods_receipt_items i ON i.receipt_id = r.id
             LEFT JOIN users u ON u.id = r.recorded_by
              GROUP BY r.id, r.receipt_number, r.receipt_date, sup.name, u.full_name, r.created_at
                UNION ALL
                SELECT 'keluar' AS kind, g.id, g.issue_number AS number, g.issue_date AS doc_date,
                       COALESCE(NULLIF(g.plate_number, ''), g.usage_type) AS party, COALESCE(SUM(i.quantity), 0) AS qty,
                       COALESCE(u.full_name, '-') AS actor, g.created_at
                  FROM goods_issues g
             LEFT JOIN goods_issue_items i ON i.issue_id = g.id
             LEFT JOIN users u ON u.id = g.recorded_by
              GROUP BY g.id, g.issue_number, g.issue_date, g.plate_number, g.usage_type, u.full_name, g.created_at
             ) t
          ORDER BY t.created_at DESC, t.id DESC
             LIMIT " . $limit
        );

        return array_map(static fn (array $row): array => [
            'kind'       => (string) $row['kind'],
            'id'         => (int) $row['id'],
            'number'     => (string) $row['number'],
            'doc_date'   => (string) $row['doc_date'],
            'party'      => (string) $row['party'],
            'qty'        => (float) $row['qty'],
            'actor'      => (string) $row['actor'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Sparepart paling sering / paling jarang dipakai (barang keluar).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function usageRanking(string $from, string $to, int $limit = 5, bool $mostUsed = true): array
    {
        $limit = max(1, min(50, $limit));
        $order = $mostUsed ? 'used_qty DESC, s.name ASC' : 'used_qty ASC, s.name ASC';

        return self::fetchAll(
            "SELECT s.id, s.code, s.name, s.unit, s.stock, s.min_stock,
                    COALESCE(SUM(-m.quantity), 0) AS used_qty,
                    COUNT(m.id) AS used_tx
               FROM spareparts s
          LEFT JOIN stock_movements m
                 ON m.sparepart_id = s.id
                AND m.movement_type = 'KELUAR'
                AND m.movement_date BETWEEN ? AND ?
              WHERE s.is_active = 1
           GROUP BY s.id, s.code, s.name, s.unit, s.stock, s.min_stock
           ORDER BY " . $order . '
              LIMIT ' . $limit,
            [$from, $to]
        );
    }

    /**
     * Jumlah transaksi per jenis ledger pada rentang tanggal.
     *
     * @return array<string,int>
     */
    public static function movementTypeCounts(string $from, string $to): array
    {
        $rows = self::fetchAll(
            'SELECT movement_type, COUNT(*) AS total
               FROM stock_movements
              WHERE movement_date BETWEEN ? AND ?
           GROUP BY movement_type',
            [$from, $to]
        );
        $out = [
            'STOK_AWAL' => 0,
            'MASUK' => 0,
            'KELUAR' => 0,
            'PENYESUAIAN_OPNAME' => 0,
            'KOREKSI' => 0,
        ];
        foreach ($rows as $row) {
            $out[(string) $row['movement_type']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Ringkasan pembelian (penerimaan bertipe pembelian) pada rentang tanggal.
     *
     * @return array{docs:int,qty:float,value:float}
     */
    public static function purchaseSummary(string $from, string $to): array
    {
        $row = self::fetchOne(
            "SELECT COUNT(DISTINCT r.id) AS docs,
                    COALESCE(SUM(i.quantity), 0) AS qty,
                    COALESCE(SUM(i.quantity * i.unit_price), 0) AS value
               FROM goods_receipts r
               JOIN goods_receipt_items i ON i.receipt_id = r.id
              WHERE r.receipt_type = 'pembelian'
                AND r.receipt_date BETWEEN ? AND ?",
            [$from, $to]
        );

        return [
            'docs'  => (int) ($row['docs'] ?? 0),
            'qty'   => (float) ($row['qty'] ?? 0),
            'value' => (float) ($row['value'] ?? 0),
        ];
    }

    /**
     * @return array{docs:int,qty:float}
     */
    public static function issueSummary(string $from, string $to): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(DISTINCT g.id) AS docs, COALESCE(SUM(i.quantity), 0) AS qty
               FROM goods_issues g
               JOIN goods_issue_items i ON i.issue_id = g.id
              WHERE g.issue_date BETWEEN ? AND ?',
            [$from, $to]
        );

        return ['docs' => (int) ($row['docs'] ?? 0), 'qty' => (float) ($row['qty'] ?? 0)];
    }

    /** @return array{total:int,open:int,confirmed:int} */
    public static function opnameSummary(string $from, string $to): array
    {
        $row = self::fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END), 0) AS open_count,
                    COALESCE(SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END), 0) AS confirmed
               FROM stock_opnames
              WHERE opname_date BETWEEN ? AND ?",
            [$from, $to]
        );

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'open'      => (int) ($row['open_count'] ?? 0),
            'confirmed' => (int) ($row['confirmed'] ?? 0),
        ];
    }

    /** @return array<string,array{count:int,amount:float}> */
    public static function poStatusCounts(): array
    {
        $rows = self::fetchAll(
            'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS amount
               FROM purchase_orders
           GROUP BY status'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = [
                'count'  => (int) $row['total'],
                'amount' => (float) $row['amount'],
            ];
        }
        return $out;
    }

    /** @return array<string,array{count:int,amount:float}> */
    public static function poPaymentCounts(): array
    {
        $rows = self::fetchAll(
            'SELECT payment_status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS amount
               FROM purchase_orders
           GROUP BY payment_status'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['payment_status']] = [
                'count'  => (int) $row['total'],
                'amount' => (float) $row['amount'],
            ];
        }
        return $out;
    }

    /** @return array<string,int> */
    public static function reorderStatusCounts(): array
    {
        $rows = self::fetchAll('SELECT status, COUNT(*) AS total FROM reorder_requests GROUP BY status');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }
        return $out;
    }

    /** @return array{total:int,active:int,inactive:int,locked:int} */
    public static function userSummary(): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active,
                    COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive,
                    COALESCE(SUM(CASE WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1 ELSE 0 END), 0) AS locked
               FROM users'
        );

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'locked'   => (int) ($row['locked'] ?? 0),
        ];
    }

    /** @return array{actions:int,users:int} */
    public static function auditToday(): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS actions, COUNT(DISTINCT user_id) AS users
               FROM audit_logs
              WHERE created_at >= CURDATE()'
        );

        return ['actions' => (int) ($row['actions'] ?? 0), 'users' => (int) ($row['users'] ?? 0)];
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentAudit(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        return self::fetchAll(
            'SELECT a.id, a.username, a.role, a.activity, a.description, a.created_at
               FROM audit_logs a
           ORDER BY a.id DESC
              LIMIT ' . $limit
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function todayActivityBreakdown(): array
    {
        return self::fetchAll(
            'SELECT activity, COUNT(*) AS total
               FROM audit_logs
              WHERE created_at >= CURDATE()
           GROUP BY activity
           ORDER BY total DESC, activity ASC
              LIMIT 6'
        );
    }

    /**
     * Rekomendasi reorder dari tabel prediksi AI (Fase 7).
     * Fase 5 belum menjalankan model AI, sehingga biasanya kosong —
     * dashboard menampilkan empty state yang menjelaskan hal ini.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function aiRecommendations(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return self::fetchAll(
            'SELECT p.id, p.sparepart_id, p.period_days, p.method, p.predicted_demand, p.recommended_qty,
                    p.stockout_risk, p.data_sufficiency, p.processed_at,
                    s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    s.stock, s.min_stock
               FROM ai_predictions p
               JOIN spareparts s ON s.id = p.sparepart_id
               JOIN (
                    SELECT sparepart_id, MAX(id) AS max_id
                      FROM ai_predictions
                  GROUP BY sparepart_id
               ) latest ON latest.max_id = p.id
              WHERE s.is_active = 1
                AND p.recommended_qty IS NOT NULL
                AND p.recommended_qty > 0
           ORDER BY p.processed_at DESC, p.id DESC
              LIMIT ' . $limit
        );
    }
}
