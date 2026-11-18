<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Prediksi stok (tabel `ai_predictions`, Fase 7).
 *
 * Setiap baris adalah SNAPSHOT analisis pada waktu tertentu — riwayat
 * dipertahankan (tidak ada endpoint hapus/ubah; pipeline hanya menulis
 * baris baru), sehingga perbandingan antar-waktu tetap dapat ditelusuri.
 */
final class AiPrediction
{
    public const RISK_LABELS = [
        'rendah' => 'Rendah',
        'sedang' => 'Sedang',
        'tinggi' => 'Tinggi',
    ];

    public const SUFFICIENCY_LABELS = [
        'cukup'       => 'Cukup',
        'terbatas'    => 'Terbatas',
        'tidak_cukup' => 'Data terbatas',
    ];

    public const CLASSIFICATION_LABELS = [
        'fast'   => 'Fast moving',
        'medium' => 'Sedang',
        'slow'   => 'Slow moving',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    s.stock, s.min_stock, s.is_active
               FROM ai_predictions p
               JOIN spareparts s ON s.id = p.sparepart_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public static function latestForSparepart(int $sparepartId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM ai_predictions
              WHERE sparepart_id = ?
              ORDER BY processed_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$sparepartId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Riwayat analisis per sparepart (terbaru dahulu).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function historyForSparepart(int $sparepartId, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM ai_predictions
              WHERE sparepart_id = ?
              ORDER BY processed_at DESC, id DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$sparepartId]);
        return $stmt->fetchAll();
    }

    /**
     * Daftar sparepart aktif + prediksi terbarunya (untuk halaman indeks).
     *
     * @param array<string,string> $filters
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, pages: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 25): array
    {
        $where = ['s.is_active = 1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.code LIKE ? OR s.name LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $risiko = (string) ($filters['risiko'] ?? '');
        if (in_array($risiko, ['tinggi', 'sedang', 'rendah'], true)) {
            $where[] = 'p.stockout_risk = ?';
            $params[] = $risiko;
        } elseif ($risiko === 'ada') {
            $where[] = 'p.id IS NOT NULL';
        } elseif ($risiko === 'belum') {
            $where[] = 'p.id IS NULL';
        }

        $from = 'FROM spareparts s
                 LEFT JOIN ai_predictions p
                        ON p.id = (SELECT MAX(p2.id) FROM ai_predictions p2
                                    WHERE p2.sparepart_id = s.id)
                WHERE ' . implode(' AND ', $where);

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) ' . $from);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $page = max(1, $page);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // Urutan: risiko tertinggi lebih dahulu; tanpa prediksi di akhir.
        $sql = 'SELECT s.id AS sparepart_id, s.code, s.name, s.unit, s.stock, s.min_stock,
                       p.id AS prediction_id, p.period_days, p.method, p.predicted_demand,
                       p.recommended_qty, p.stockout_risk, p.data_sufficiency,
                       p.moving_classification, p.processed_at, p.model_version,
                       p.history_range_start, p.history_range_end
                ' . $from . '
                ORDER BY (p.id IS NULL) ASC,
                         CASE p.stockout_risk WHEN \'tinggi\' THEN 0 WHEN \'sedang\' THEN 1
                                              WHEN \'rendah\' THEN 2 ELSE 3 END ASC,
                         s.code ASC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Ringkasan prediksi (prediksi terbaru per sparepart aktif).
     *
     * @return array{total: int, latest_at: ?string, risk_high: int, risk_medium: int,
     *               active: int, pending: int}
     */
    public static function summary(): array
    {
        $row = Database::pdo()->query(
            'SELECT COUNT(*) AS total,
                    MAX(p.processed_at) AS latest_at,
                    COALESCE(SUM(p.stockout_risk = \'tinggi\'), 0) AS risk_high,
                    COALESCE(SUM(p.stockout_risk = \'sedang\'), 0) AS risk_medium
               FROM ai_predictions p
               JOIN spareparts s ON s.id = p.sparepart_id
               JOIN (SELECT sparepart_id, MAX(id) AS mid
                       FROM ai_predictions GROUP BY sparepart_id) latest
                     ON latest.mid = p.id
              WHERE s.is_active = 1'
        )->fetch();

        $active = (int) Database::pdo()->query(
            'SELECT COUNT(*) FROM spareparts WHERE is_active = 1'
        )->fetchColumn();

        $total = (int) ($row['total'] ?? 0);

        return [
            'total'       => $total,
            'latest_at'   => $row['latest_at'] ?? null,
            'risk_high'   => (int) ($row['risk_high'] ?? 0),
            'risk_medium' => (int) ($row['risk_medium'] ?? 0),
            'active'      => $active,
            'pending'     => max(0, $active - $total),
        ];
    }

    /** Dekode kolom details JSON menjadi array. @return array<string,mixed> */
    public static function details(array $row): array
    {
        $raw = $row['details'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sparepart AKTIF beserta prediksi terbarunya + waktu pergerakan stok
     * terakhir. Dipakai dashboard seluruh role agar sumber angkanya identik.
     *
     * Stok diambil dari kolom `spareparts.stock` — sumber kebenaran yang sama
     * dengan modul persediaan, bukan angka duplikat/statis.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function dashboardRows(): array
    {
        return Database::pdo()->query(
            'SELECT s.id AS sparepart_id, s.code, s.name, s.unit, s.stock, s.min_stock,
                    p.id AS prediction_id, p.period_days, p.method, p.predicted_demand,
                    p.recommended_qty, p.stockout_risk, p.data_sufficiency,
                    p.moving_classification, p.processed_at, p.details,
                    (SELECT MAX(m.created_at) FROM stock_movements m
                      WHERE m.sparepart_id = s.id) AS last_movement_at
               FROM spareparts s
               LEFT JOIN ai_predictions p
                      ON p.id = (SELECT MAX(p2.id) FROM ai_predictions p2
                                  WHERE p2.sparepart_id = s.id)
              WHERE s.is_active = 1
           ORDER BY (p.id IS NULL) ASC, s.code ASC'
        )->fetchAll();
    }
}
