<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\View;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\AiPrediction;
use App\Models\Sparepart;
use App\Services\PredictionService;

/**
 * Pengujian Fase 7 — tampilan modul prediksi stok & hak akses (RBAC).
 *
 * Memastikan halaman indeks/detail dapat dirender dengan data NYATA dari basis
 * data uji, tidak membocorkan tag PHP / peringatan PHP, menyembunyikan formulir
 * "jalankan analisis" dari peran non-Partman, dan seluruh route prediksi
 * terlindungi (tidak dapat diakses tanpa login / oleh peran tak berwenang).
 */

/** Render view (tanpa layout) + pemeriksaan kebersihan keluaran. */
function t_prediction_view_render(string $template, array $data, string $label): string
{
    try {
        $html = View::render($template, $data, null);
    } catch (Throwable $e) {
        t_true(false, "View {$label} gagal dirender: " . $e->getMessage());
        return '';
    }

    t_true($html !== '', "View {$label} berhasil dirender");
    t_true(!str_contains($html, '<?php'), "View {$label} tidak membocorkan tag PHP");
    t_true(
        !str_contains($html, 'Undefined')
        && !str_contains($html, 'Warning:')
        && !str_contains($html, 'Notice:')
        && !str_contains($html, 'Fatal error'),
        "View {$label} dirender tanpa peringatan/galat PHP"
    );

    return $html;
}

/**
 * Details JSON lengkap khas keluaran pipeline Python (untuk uji tampilan).
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function t_prediction_view_details(array $overrides = []): array
{
    return array_replace_recursive([
        'schema'         => 1,
        'reason'         => 'Data cukup untuk evaluasi kronologis.',
        'daily_rate'     => 2.0,
        'safety_stock'   => 4.62,
        'reorder_point'  => 18.62,
        'lead_time'      => ['days' => 7, 'assumed' => false, 'source' => 'supplier_master', 'samples' => 0, 'reliable' => true],
        'methods'        => [
            'moving_average'    => ['forecast' => 60.0, 'daily_avg' => 2.0],
            'linear_regression' => ['forecast' => 61.0, 'slope_per_day' => 0.01],
        ],
        'evaluation'     => [
            'performed'         => true,
            'split'             => 'Latih 42 hari, uji 18 hari (kronologis)',
            'moving_average'    => ['mae' => 0.1, 'rmse' => 0.2, 'mape' => 5.0],
            'linear_regression' => ['mae' => 0.3, 'rmse' => 0.4, 'mape' => 8.0],
            'note'              => '',
        ],
        'comparison'     => ['selected_method' => 'moving_average', 'rule' => 'MAE terkecil pada data uji.'],
        'parameters'     => [
            'ma_window' => 7, 'safety_z' => 1.65, 'test_ratio' => 0.3,
            'min_days_with_data' => 30, 'min_transactions' => 5,
        ],
        'library_versions' => ['pandas' => '3.0.6', 'scikit_learn' => '1.9.1', 'numpy' => '2.5.4'],
        'classification'   => ['class' => 'fast', 'period_days' => 90, 'qty_out' => 180],
        'integration'      => [
            'method' => 'cli_proc_open', 'script' => 'python/prediction/forecast.py',
            'timeout_s' => 60, 'endpoint' => 'lokal-only (tanpa layanan jaringan)',
        ],
        'analysis'         => [
            'generated_at'  => '2026-10-19T10:00:00+07:00',
            'as_of'         => '2026-10-19',
            'history_days'  => 60,
            'tx_count'      => 60,
            'history_range' => [
                'start' => '2026-08-20', 'end' => '2026-10-18',
                'days' => 60, 'days_with_data' => 60, 'tx_count' => 60,
            ],
        ],
    ], $overrides);
}

/** Sisipkan satu baris prediksi lengkap untuk pengujian tampilan. */
function t_prediction_view_insert(int $sparepartId, array $overrides = []): int
{
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO ai_predictions
            (sparepart_id, period_days, history_range_start, history_range_end, method,
             predicted_demand, forecast_days_to_min, forecast_days_to_zero, stockout_risk,
             recommended_qty, moving_classification, data_sufficiency, warnings,
             model_version, details, processed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $sparepartId,
        (int) ($overrides['period_days'] ?? 30),
        $overrides['range_start'] ?? '2026-08-20',
        $overrides['range_end'] ?? '2026-10-18',
        (string) ($overrides['method'] ?? 'moving_average'),
        $overrides['demand'] ?? 60.0,
        array_key_exists('days_to_min', $overrides) ? $overrides['days_to_min'] : 20,
        array_key_exists('days_to_zero', $overrides) ? $overrides['days_to_zero'] : 25,
        $overrides['risk'] ?? 'sedang',
        $overrides['recommended'] ?? 20.0,
        $overrides['classification'] ?? 'fast',
        (string) ($overrides['sufficiency'] ?? 'cukup'),
        $overrides['warnings'] ?? null,
        (string) ($overrides['model_version'] ?? 'sfm-forecast/1.0.0'),
        (string) json_encode(
            $overrides['details'] ?? t_prediction_view_details(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        (string) ($overrides['processed_at'] ?? date('Y-m-d H:i:s')),
    ]);

    return (int) $pdo->lastInsertId();
}

// ------------------------------------------------------------------
// 1. Indeks prediksi dapat dirender (Partman & non-Partman)
// ------------------------------------------------------------------
function test_prediction_view_index(): void
{
    $fx = t_pred_fixture('view-index', ['days' => 0, 'stock' => 40, 'min_stock' => 10]);
    try {
        $sparepart = Sparepart::find($fx['sparepart']);
        t_true($sparepart !== null, 'Sparepart uji indeks tersedia');
        $code = (string) $sparepart['code'];

        t_prediction_view_insert($fx['sparepart'], ['risk' => 'tinggi', 'sufficiency' => 'cukup']);
        $result = AiPrediction::paginate(['q' => $code, 'risiko' => ''], 1, 25);

        $partman = t_prediction_view_render('prediksi/index', [
            'title'   => 'Prediksi Stok',
            'filters' => ['q' => $code, 'risiko' => ''],
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $result['page'],
            'pages'   => $result['pages'],
            'summary' => AiPrediction::summary(),
            'canRun'  => true,
            'periods' => PredictionService::PERIODS,
        ], 'prediksi/index (Partman)');

        t_contains('Prediksi Stok', $partman, 'Indeks menampilkan judul "Prediksi Stok"');
        t_contains('Moving Average', $partman, 'Indeks menyebut metode Moving Average');
        t_contains('Linear Regression', $partman, 'Indeks menyebut metode Linear Regression');
        t_contains($code, $partman, 'Indeks menampilkan kode sparepart yang dianalisis');
        t_contains('Tinggi', $partman, 'Indeks menampilkan lencana risiko tinggi');
        t_contains('30 hari', $partman, 'Indeks menampilkan periode prediksi');
        t_contains('Detail', $partman, 'Indeks menyediakan tautan ke halaman detail');
        t_contains('Anda (Partman) menjalankan analisis', $partman, 'Catatan peran Partman tampil di indeks');

        // Tanpa prediksi: pesan berbeda untuk Partman vs peran lain.
        $emptyData = [
            'title'   => 'Prediksi Stok',
            'filters' => ['q' => 'zzz-tidak-ada', 'risiko' => 'belum'],
            'rows'    => [],
            'total'   => 0,
            'page'    => 1,
            'pages'   => 1,
            'summary' => [
                'total' => 0, 'active' => 0, 'pending' => 0,
                'risk_high' => 0, 'risk_medium' => 0, 'latest_at' => null,
            ],
            'periods' => PredictionService::PERIODS,
        ];

        $emptyPartman = t_prediction_view_render('prediksi/index', $emptyData + ['canRun' => true], 'prediksi/index (kosong, Partman)');
        t_contains('pilih sparepart lalu tekan', $emptyPartman, 'Partman diarahkan menjalankan analisis dari halaman detail');

        $emptyOther = t_prediction_view_render('prediksi/index', $emptyData + ['canRun' => false], 'prediksi/index (kosong, non-Partman)');
        t_contains('menunggu Partman menjalankan analisis', $emptyOther, 'Non-Partman diberi tahu bahwa analisis dijalankan Partman');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 2. Halaman detail: hasil lengkap + formulir hanya untuk Partman
// ------------------------------------------------------------------
function test_prediction_view_show(): void
{
    $fx = t_pred_fixture('view-show', ['days' => 0, 'stock' => 40, 'min_stock' => 10]);
    try {
        $sparepart = Sparepart::find($fx['sparepart']);
        t_prediction_view_insert($fx['sparepart'], ['risk' => 'sedang', 'sufficiency' => 'cukup']);
        // Baris kedua agar blok "Riwayat analisis" terisi.
        t_prediction_view_insert($fx['sparepart'], [
            'period_days' => 7, 'processed_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $base = [
            'title'          => 'Prediksi ' . $sparepart['code'],
            'sparepart'      => $sparepart,
            'latest'         => AiPrediction::latestForSparepart($fx['sparepart']),
            'history'        => AiPrediction::historyForSparepart($fx['sparepart'], 15),
            'periods'        => PredictionService::PERIODS,
            'historyOptions' => PredictionService::HISTORY_OPTIONS,
            'defaultPeriod'  => PredictionService::DEFAULT_PERIOD,
            'defaultHistory' => PredictionService::DEFAULT_HISTORY,
            'unit'           => (string) $sparepart['unit'],
        ];

        $partman = t_prediction_view_render('prediksi/show', $base + ['canRun' => true], 'prediksi/show (Partman)');
        t_contains((string) $sparepart['code'], $partman, 'Detail menampilkan kode sparepart');
        t_contains('Jalankan Prediksi', $partman, 'Partman melihat tombol menjalankan analisis');
        t_contains('name="_token"', $partman, 'Formulir analisis menyertakan token CSRF');
        t_contains('name="period_days"', $partman, 'Formulir analisis memuat pilihan periode');
        t_contains('name="history_days"', $partman, 'Formulir analisis memuat rentang riwayat');
        t_contains('name="lead_time_override_days"', $partman, 'Formulir analisis memuat override lead time');
        t_contains('name="lead_time_reason"', $partman, 'Formulir analisis memuat alasan override lead time');
        t_contains('Estimasi permintaan', $partman, 'Detail menampilkan estimasi permintaan');
        t_contains('Safety Stock', $partman, 'Detail menampilkan Safety Stock');
        t_contains('Reorder Point (ROP)', $partman, 'Detail menampilkan Reorder Point');
        t_contains('Lead time dipakai', $partman, 'Detail menampilkan lead time yang dipakai');
        t_contains('Master supplier', $partman, 'Sumber lead time master supplier ditandai');
        t_contains('Evaluasi model', $partman, 'Detail menampilkan evaluasi model');
        t_contains('Metode terpilih', $partman, 'Detail menampilkan metode terpilih');
        t_contains('Parameter &amp; versi model', $partman, 'Detail menampilkan parameter & versi model');
        t_contains('Riwayat analisis', $partman, 'Detail menampilkan riwayat analisis');

        $readOnly = t_prediction_view_render('prediksi/show', $base + ['canRun' => false], 'prediksi/show (non-Partman)');
        t_true(
            !str_contains($readOnly, 'name="_token"')
            && !str_contains($readOnly, 'name="period_days"')
            && !str_contains($readOnly, 'Jalankan Prediksi'),
            'Non-Partman TIDAK melihat formulir maupun tombol menjalankan analisis'
        );
        t_contains('Menjalankan analisis hanya dapat dilakukan', $readOnly, 'Non-Partman diberi tahu analisis dijalankan Partman');
        t_contains('Estimasi permintaan', $readOnly, 'Non-Partman tetap dapat melihat hasil analisis');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3. Lead time tidak tersedia → ROP ditandai belum andal (asumsi)
// ------------------------------------------------------------------
function test_prediction_view_lead_time_asumsi(): void
{
    $fx = t_pred_fixture('view-lt', ['days' => 0, 'stock' => 40, 'min_stock' => 10]);
    try {
        $sparepart = Sparepart::find($fx['sparepart']);
        t_prediction_view_insert($fx['sparepart'], [
            'sufficiency' => 'terbatas',
            'details'     => t_prediction_view_details([
                'reason'    => 'Data terbatas, belum mencukupi seluruh jendela evaluasi.',
                'lead_time' => ['days' => 7, 'assumed' => true, 'source' => 'assumption', 'samples' => 0, 'reliable' => false],
            ]),
        ]);

        $html = t_prediction_view_render('prediksi/show', [
            'title'          => 'Prediksi ' . $sparepart['code'],
            'sparepart'      => $sparepart,
            'latest'         => AiPrediction::latestForSparepart($fx['sparepart']),
            'history'        => AiPrediction::historyForSparepart($fx['sparepart'], 15),
            'canRun'         => true,
            'periods'        => PredictionService::PERIODS,
            'historyOptions' => PredictionService::HISTORY_OPTIONS,
            'defaultPeriod'  => PredictionService::DEFAULT_PERIOD,
            'defaultHistory' => PredictionService::DEFAULT_HISTORY,
            'unit'           => (string) $sparepart['unit'],
        ], 'prediksi/show (lead time asumsi)');

        t_contains('Asumsi', $html, 'Lead time tanpa data ditandai "Asumsi"');
        t_contains('ROP belum dapat dihitung secara andal', $html, 'Halaman menjelaskan ROP belum andal tanpa data lead time');
        t_true(!str_contains($html, 'Master supplier'), 'Tanpa data lead time TIDAK ditandai master supplier');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3b. Override lead time manual: nilai, alasan, dan sumber ditampilkan
// ------------------------------------------------------------------
function test_prediction_view_lead_time_override(): void
{
    $fx = t_pred_fixture('view-lt-override', ['days' => 0, 'stock' => 40, 'min_stock' => 10]);
    try {
        $sparepart = Sparepart::find($fx['sparepart']);
        $reason = 'Permintaan pemilik bengkel untuk pengadaan khusus';
        t_prediction_view_insert($fx['sparepart'], [
            'sufficiency' => 'cukup',
            'details'     => t_prediction_view_details([
                'lead_time' => [
                    'days'       => 12,
                    'assumed'    => false,
                    'source'     => 'manual_override',
                    'samples'    => 0,
                    'reliable'   => true,
                    'override'   => true,
                    'reason'     => $reason,
                    'auto_days'  => 7,
                    'auto_source' => 'assumption',
                ],
            ]),
        ]);

        $html = t_prediction_view_render('prediksi/show', [
            'title'          => 'Prediksi ' . $sparepart['code'],
            'sparepart'      => $sparepart,
            'latest'         => AiPrediction::latestForSparepart($fx['sparepart']),
            'history'        => AiPrediction::historyForSparepart($fx['sparepart'], 15),
            'canRun'         => true,
            'periods'        => PredictionService::PERIODS,
            'historyOptions' => PredictionService::HISTORY_OPTIONS,
            'defaultPeriod'  => PredictionService::DEFAULT_PERIOD,
            'defaultHistory' => PredictionService::DEFAULT_HISTORY,
            'unit'           => (string) $sparepart['unit'],
        ], 'prediksi/show (override lead time)');

        t_contains('Override manual', $html, 'Lead time override ditandai "Override manual"');
        t_contains('Alasan override', $html, 'Alasan override ditampilkan');
        t_contains($reason, $html, 'Teks alasan override tampil di halaman');
        t_contains('Nilai otomatis', $html, 'Nilai lead time otomatis sebelum override ditampilkan');
        t_contains('12 hari', $html, 'Lead time override yang dipakai ditampilkan');
        t_true(!str_contains($html, 'ROP belum dapat dihitung secara andal'), 'Override tidak lagi menandai ROP belum andal');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4. Data tidak cukup → tidak ada angka karangan
// ------------------------------------------------------------------
function test_prediction_view_data_tidak_cukup(): void
{
    $fx = t_pred_fixture('view-kurang', ['days' => 0, 'stock' => 40, 'min_stock' => 10]);
    try {
        $sparepart = Sparepart::find($fx['sparepart']);
        t_prediction_view_insert($fx['sparepart'], [
            'method'       => 'belum_dihitung',
            'sufficiency'  => 'tidak_cukup',
            'demand'       => null,
            'recommended'  => null,
            'risk'         => null,
            'days_to_min'  => null,
            'days_to_zero' => null,
            'details'      => t_prediction_view_details([
                'reason'        => 'Riwayat hanya 3 hari berdata (kurang dari ambang 30 hari).',
                'safety_stock'  => null,
                'reorder_point' => null,
                'evaluation'    => ['performed' => false, 'note' => 'Data belum cukup untuk evaluasi.'],
            ]),
        ]);

        $html = t_prediction_view_render('prediksi/show', [
            'title'          => 'Prediksi ' . $sparepart['code'],
            'sparepart'      => $sparepart,
            'latest'         => AiPrediction::latestForSparepart($fx['sparepart']),
            'history'        => AiPrediction::historyForSparepart($fx['sparepart'], 15),
            'canRun'         => true,
            'periods'        => PredictionService::PERIODS,
            'historyOptions' => PredictionService::HISTORY_OPTIONS,
            'defaultPeriod'  => PredictionService::DEFAULT_PERIOD,
            'defaultHistory' => PredictionService::DEFAULT_HISTORY,
            'unit'           => (string) $sparepart['unit'],
        ], 'prediksi/show (data tidak cukup)');

        t_contains('Prediksi tidak dihitung — data terbatas', $html, 'Halaman menandai prediksi tidak dihitung');
        t_contains('Riwayat hanya 3 hari', $html, 'Alasan ketidakcukupan data ditampilkan');
        t_contains('Perhitungan tidak tersedia', $html, 'Kartu SS/ROP menyatakan perhitungan tidak tersedia');
        t_contains('Data belum cukup untuk evaluasi', $html, 'Catatan evaluasi default ditampilkan');
        t_true(!str_contains($html, 'Rp'), 'Tidak ada nilai karangan untuk data tidak cukup');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 5. RBAC: seluruh route prediksi terlindungi
// ------------------------------------------------------------------
function test_prediction_rbac_route(): void
{
    $index = t_rbac_find_route('GET /prediksi');
    $show = t_rbac_find_route('GET /prediksi/{id}');
    $run = t_rbac_find_route('POST /prediksi/{id}/jalankan');

    t_true($index !== null, 'Route GET /prediksi terdaftar');
    t_true($show !== null, 'Route GET /prediksi/{id} terdaftar');
    t_true($run !== null, 'Route POST /prediksi/{id}/jalankan terdaftar');
    if ($index === null || $show === null || $run === null) {
        return;
    }

    // Melihat hasil diizinkan semua peran login; menjalankan analisis hanya Partman.
    foreach (['GET /prediksi' => $index, 'GET /prediksi/{id}' => $show] as $key => $route) {
        t_true(t_rbac_has_middleware($route['middleware'], AuthMiddleware::class), "{$key} wajib login");
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class),
            "{$key} tidak dibatasi role tertentu (semua peran login boleh melihat)"
        );
    }

    t_true(t_rbac_has_middleware($run['middleware'], AuthMiddleware::class), 'POST /prediksi/{id}/jalankan wajib login');
    t_true(t_rbac_has_middleware($run['middleware'], RoleMiddleware::class, 'partman'), 'POST jalankan dibatasi Partman');
    t_true(!t_rbac_has_middleware($run['middleware'], RoleMiddleware::class, 'admin'), 'POST jalankan TIDAK membuka akses Admin');
    t_true(!t_rbac_has_middleware($run['middleware'], RoleMiddleware::class, 'kepala_bengkel'), 'POST jalankan TIDAK membuka akses Kepala Bengkel');

    // Tepat tiga route prediksi, semuanya memakai middleware.
    $prediksi = array_values(array_filter(
        t_routes(),
        static fn (array $route): bool => str_starts_with((string) $route['path'], '/prediksi')
    ));
    t_eq(3, count($prediksi), 'Tepat tiga route prediksi terdaftar');
    foreach ($prediksi as $route) {
        t_true($route['middleware'] !== [], 'Route ' . $route['method'] . ' ' . $route['path'] . ' terlindungi middleware');
    }

    // Urutan route: /prediksi harus sebelum /prediksi/{id}.
    $paths = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], t_routes());
    $i = array_search('GET /prediksi', $paths, true);
    $j = array_search('GET /prediksi/{id}', $paths, true);
    t_true($i !== false && $j !== false && $i < $j, 'Route /prediksi terdaftar sebelum /prediksi/{id}');

    // Perilaku middleware role yang dipakai route "jalankan".
    $partman = new RoleMiddleware('partman');
    t_true($partman->allows('partman'), 'Partman diizinkan menjalankan analisis');
    t_true(!$partman->allows('kepala_bengkel'), 'Kepala Bengkel DITOLAK menjalankan analisis');
    t_true(!$partman->allows('admin'), 'Admin DITOLAK menjalankan analisis');
    t_true(!$partman->allows(null), 'Tanpa login DITOLAK menjalankan analisis');
}
