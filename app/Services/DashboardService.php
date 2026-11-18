<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AiPrediction;
use App\Models\Analytics;
use App\Models\Sparepart;

/**
 * Data dashboard per role (Fase 5).
 *
 * Semua angka dihitung langsung dari database pada setiap permintaan:
 * - Total jenis/stok/nilai  : tabel spareparts (hanya yang aktif).
 * - Masuk/keluar hari ini   : ledger + dokumen bertanggal hari ini.
 * - Grafik                  : ledger MASUK/KELUAR per hari.
 * - Nilai persediaan        : stok × harga rata-rata tertimbang
 *                             (fallback harga beli terakhir), sesuai
 *                             keputusan Fase 4; harga terakhir juga
 *                             ditampilkan sebagai pembanding.
 *
 * Dashboard TIDAK mengubah data dan TIDAK memuat rahasia.
 */
final class DashboardService
{
    /** @return array<string,mixed> */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'partman'        => self::partman(),
            'kepala_bengkel' => self::kepala(),
            'admin'          => self::admin(),
            default          => [],
        };
    }

    /** Dashboard Partman: fokus operasi harian & peringatan stok. */
    public static function partman(): array
    {
        $today = Analytics::todayTotals();
        $summary = Analytics::sparepartSummary(true);
        $stats = Analytics::stockStatusCounts(true);
        $reorder = Analytics::reorderStatusCounts();
        $ai = Analytics::aiRecommendations(5);
        $aiSummary = AiPrediction::summary();
        $daily = Analytics::auditToday();

        return [
            'summary' => [
                'types'       => $summary['types'],
                'stock'       => $summary['stock'],
                'masuk_qty'   => $today['masuk_qty'],
                'keluar_qty'  => $today['keluar_qty'],
                'receipts'    => $today['receipts'],
                'issues'      => $today['issues'],
            ],
            'stock_stats' => $stats,
            'alerts'      => self::alerts(8),
            'chart'       => self::series(14),
            'latest'      => Analytics::latestTransactions(8),
            'ai'          => [
                'ready'            => $aiSummary['total'] > 0,
                'items'            => $ai,
                'total'            => $aiSummary['total'],
                'pending'          => $aiSummary['pending'],
                'risk_high'        => $aiSummary['risk_high'],
                'risk_medium'      => $aiSummary['risk_medium'],
                'latest_at'        => $aiSummary['latest_at'],
                'pending_reorder'  => (int) ($reorder['MENUNGGU_PERSETUJUAN'] ?? 0),
                'approved_reorder' => (int) ($reorder['DISETUJUI'] ?? 0),
            ],
            'daily' => [
                'actions'   => $daily['actions'],
                'users'     => $daily['users'],
                'breakdown' => Analytics::todayActivityBreakdown(),
                'receipts'  => $today['receipts'],
                'issues'    => $today['issues'],
            ],
            'prediction' => self::predictionInsight(),
        ];
    }

    /** Dashboard Kepala Bengkel: fokus nilai, pemakaian, dan pembelian. */
    public static function kepala(): array
    {
        $config = SettingService::reportConfig();
        $periodDays = $config['period_days'];
        $from = date('Y-m-d', strtotime('-' . ($periodDays - 1) . ' days'));
        $to = date('Y-m-d');

        $summary = Analytics::sparepartSummary(true);
        $stats = Analytics::stockStatusCounts(true);
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $aiSummary = AiPrediction::summary();

        return [
            'summary'    => $summary,
            'stock_stats' => $stats,
            'alerts'     => self::alerts(8),
            'top_used'   => Analytics::usageRanking($from, $to, 5, true),
            'least_used' => Analytics::usageRanking($from, $to, 5, false),
            'period'     => ['from' => $from, 'to' => $to, 'days' => $periodDays],
            'chart'      => self::series(30),
            'month'      => [
                'purchase' => Analytics::purchaseSummary($monthStart, $monthEnd),
                'issue'    => Analytics::issueSummary($monthStart, $monthEnd),
                'opname'   => Analytics::opnameSummary($monthStart, $monthEnd),
            ],
            'po'         => Analytics::poStatusCounts(),
            'payment'    => Analytics::poPaymentCounts(),
            'ai'         => [
                'ready'       => $aiSummary['total'] > 0,
                'items'       => Analytics::aiRecommendations(5),
                'total'       => $aiSummary['total'],
                'pending'     => $aiSummary['pending'],
                'risk_high'   => $aiSummary['risk_high'],
                'risk_medium' => $aiSummary['risk_medium'],
                'latest_at'   => $aiSummary['latest_at'],
            ],
            'prediction' => self::predictionInsight(),
        ];
    }

    /** Dashboard Admin: fokus pengguna, statistik, aktivitas, dan sistem. */
    public static function admin(): array
    {
        $today = Analytics::todayTotals();
        $summaryAll = Analytics::sparepartSummary(false);
        $summaryActive = Analytics::sparepartSummary(true);
        $stats = Analytics::stockStatusCounts(true);
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $transactions = [
            'receipts'   => self::count('goods_receipts'),
            'issues'     => self::count('goods_issues'),
            'opnames'    => self::count('stock_opnames'),
            'movements'  => self::count('stock_movements'),
            'po'         => self::count('purchase_orders'),
            'today'      => $today,
            'month'      => [
                'purchase' => Analytics::purchaseSummary($monthStart, $monthEnd),
                'issue'    => Analytics::issueSummary($monthStart, $monthEnd),
                'opname'   => Analytics::opnameSummary($monthStart, $monthEnd),
            ],
        ];

        return [
            'users'        => Analytics::userSummary(),
            'sparepart'    => $summaryAll,
            'sparepart_active' => $summaryActive,
            'stock_stats'  => $stats,
            'transactions' => $transactions,
            'activity'     => [
                'today'     => Analytics::auditToday(),
                'recent'    => Analytics::recentAudit(8),
                'breakdown' => Analytics::todayActivityBreakdown(),
            ],
            'system'       => HealthService::check(true),
            'po'           => Analytics::poStatusCounts(),
            'payment'      => Analytics::poPaymentCounts(),
            'identity'     => SettingService::identity(),
            'prediction'   => self::predictionInsight(),
        ];
    }

    /**
     * Ringkasan prediksi otomatis untuk dashboard (Admin, Partman, Kepala
     * Bengkel memakai struktur yang sama persis).
     *
     * @return array<string,mixed>
     */
    private static function predictionInsight(): array
    {
        PredictionAutomation::ensureFresh();
        return PredictionAutomation::insight(10);
    }

    /**
     * Peringatan stok aktif (habis/kritis/menipis/tanpa minimum),
     * sudah dilengkapi status & persentase untuk tampilan.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function alerts(int $limit = 8): array
    {
        $rows = Sparepart::needingAttention($limit);
        foreach ($rows as &$row) {
            $row['stock_status'] = Sparepart::stockStatusOf($row);
            $row['stock_percent'] = Sparepart::stockPercentOf($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Deret harian untuk grafik (tanggal tanpa pergerakan diisi 0).
     *
     * @return array{labels:array<int,string>,from:string,to:string,masuk:array<int,float>,keluar:array<int,float>}
     */
    private static function series(int $days): array
    {
        $days = max(2, min(90, $days));
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $map = Analytics::movementSeries($from, $to);

        $labels = [];
        $masuk = [];
        $keluar = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $labels[] = date('d/m', strtotime($date));
            $masuk[] = (float) ($map[$date]['masuk'] ?? 0);
            $keluar[] = (float) ($map[$date]['keluar'] ?? 0);
        }

        return ['labels' => $labels, 'from' => $from, 'to' => $to, 'masuk' => $masuk, 'keluar' => $keluar];
    }

    /** Hitung baris tabel (tabel berasal dari daftar internal, bukan input pengguna). */
    private static function count(string $table): int
    {
        $allowed = ['goods_receipts', 'goods_issues', 'stock_opnames', 'stock_movements', 'purchase_orders'];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }
        $row = \App\Core\Database::pdo()->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }
}
