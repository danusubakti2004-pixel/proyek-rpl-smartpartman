<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\AiPrediction;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\DashboardService;
use App\Services\PredictionAutomation;
use App\Services\PredictionService;
use App\Services\StockLedger;

/**
 * Pengujian revisi Fase 7 — OTOMATISASI prediksi stok.
 *
 * Fokus:
 *  - pemicu setelah commit transaksi stok (masuk/keluar/penyesuaian/koreksi);
 *  - transaksi batal TIDAK memicu prediksi;
 *  - prediksi tidak mengubah stok dan tidak membuat Purchase Order;
 *  - lock mencegah proses ganda;
 *  - kegagalan Python tidak menghapus hasil prediksi terakhir;
 *  - data historis kurang ditampilkan tanpa angka palsu;
 *  - tiga role membaca hasil yang sama;
 *  - konstanta algoritma (MA/LR/Safety Stock/ROP) tidak berubah.
 */

/** Hash pipeline Python — mengunci agar algoritma tidak diubah tanpa sengaja. */
const T_PRED_FORECAST_SHA256 = 'f0fab1f2973049fc4652c2df33fd7f49d7bda81f2fa1863b67ce99e48df8bdf4';

/**
 * Nonaktifkan sementara seluruh sparepart lain agar insight deterministik.
 *
 * @return array<int,int> ID yang sebelumnya aktif (untuk dipulihkan)
 */
function t_auto_isolate(int $sparepartId): array
{
    $pdo = Database::pdo();
    $others = array_map('intval', $pdo->query(
        'SELECT id FROM spareparts WHERE is_active = 1 AND id <> ' . $sparepartId
    )->fetchAll(PDO::FETCH_COLUMN));
    if ($others !== []) {
        $placeholders = implode(',', array_fill(0, count($others), '?'));
        $pdo->prepare('UPDATE spareparts SET is_active = 0 WHERE id IN (' . $placeholders . ')')
            ->execute($others);
    }
    return $others;
}

/** @param array<int,int> $ids */
function t_auto_restore(array $ids): void
{
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    Database::pdo()->prepare('UPDATE spareparts SET is_active = 1 WHERE id IN (' . $placeholders . ')')
        ->execute($ids);
}

/** Simpan satu snapshot prediksi buatan (untuk menguji hasil lama dipertahankan). */
function t_auto_store_prediction(int $sparepartId, array $details = [], string $sufficiency = 'cukup'): int
{
    $pdo = Database::pdo();
    $pdo->prepare(
        'INSERT INTO ai_predictions
            (sparepart_id, period_days, method, predicted_demand, stockout_risk,
             recommended_qty, data_sufficiency, details, processed_at)
         VALUES (?, 30, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        $sparepartId,
        'moving_average',
        $details['predicted_demand'] ?? 12.5,
        'rendah',
        $details['recommended_qty'] ?? null,
        $sufficiency,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return (int) $pdo->lastInsertId();
}

/** Baris insight untuk satu sparepart. @return array<string,mixed>|null */
function t_auto_row(int $sparepartId, int $limit = 50): ?array
{
    foreach (PredictionAutomation::insight($limit)['rows'] as $row) {
        if ((int) ($row['sparepart_id'] ?? 0) === $sparepartId) {
            return $row;
        }
    }
    return null;
}

/** Bersihkan sisa antrean agar test lain tidak terpengaruh. */
function t_auto_reset(): void
{
    PredictionAutomation::setImmediateRefreshForTesting(false);
    PredictionAutomation::clearQueueForTesting();
}

// ------------------------------------------------------------------
// 1. Algoritma & parameter tidak berubah
// ------------------------------------------------------------------
function test_prediction_automation_algoritma_tidak_berubah(): void
{
    t_eq([7, 30, 90], PredictionService::PERIODS, 'Pilihan periode prediksi tetap 7/30/90 hari');
    t_eq([30, 60, 90, 180, 365], PredictionService::HISTORY_OPTIONS, 'Pilihan rentang riwayat tetap 30/60/90/180/365 hari');
    t_eq(30, PredictionService::DEFAULT_PERIOD, 'Periode bawaan tetap 30 hari');
    t_eq(90, PredictionService::DEFAULT_HISTORY, 'Riwayat bawaan tetap 90 hari');
    t_eq([
        'ma_window' => 7,
        'safety_z' => 1.65,
        'test_ratio' => 0.30,
        'min_train_days' => 14,
        'min_test_days' => 7,
        'min_days_with_data' => 30,
        'min_transactions' => 5,
        'sufficient_days' => 60,
        'sufficient_transactions' => 10,
        'lead_time_fallback_days' => 7,
    ], PredictionService::PARAMS, 'PARAMS Moving Average/Safety Stock/ROP tidak berubah');

    t_eq(PredictionService::DEFAULT_PERIOD, PredictionAutomation::RUN_PERIOD, 'Otomatisasi memakai periode algoritma yang sama');
    t_eq(PredictionService::DEFAULT_HISTORY, PredictionAutomation::RUN_HISTORY, 'Otomatisasi memakai riwayat algoritma yang sama');

    $script = BASE_PATH . '/python/prediction/forecast.py';
    t_true(is_file($script), 'Skrip pipeline Python tetap ada');
    t_eq(T_PRED_FORECAST_SHA256, hash_file('sha256', $script), 'Isi forecast.py identik dengan versi sebelum revisi (algoritma tidak diubah)');
}

// ------------------------------------------------------------------
// 2. Pemicu hanya SETELAH commit (masuk/keluar/penyesuaian/koreksi)
// ------------------------------------------------------------------
function test_prediction_automation_menandai_setelah_commit(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-commit', ['days' => 45, 'stock' => 50, 'min_stock' => 10]);
    $actor = t_make_user('uji_auto_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $pdo = Database::pdo();

    try {
        $predBefore = (int) $pdo->query('SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ' . $fx['sparepart'])->fetchColumn();
        $poBefore = (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();

        $result = Database::transaction(static fn (): array => StockLedger::apply(
            $fx['sparepart'],
            StockMovement::TYPE_IN,
            5,
            date('Y-m-d'),
            'MANUAL',
            null,
            null,
            'Uji pemicu otomatis setelah commit',
            $actor
        ));

        $state = PredictionAutomation::state();
        t_true(isset($state['pending'][(string) $fx['sparepart']]), 'Perubahan stok menandai sparepart untuk prediksi setelah commit');
        t_eq(55.0, (float) Sparepart::find($fx['sparepart'])['stock'], 'Stok aktual bertambah sesuai transaksi (MASUK)');
        t_eq(50.0, (float) $result['stock_before'], 'StockLedger mencatat stok sebelum dengan benar');
        t_eq(55.0, (float) $result['stock_after'], 'StockLedger mencatat stok sesudah dengan benar');

        $predAfter = (int) $pdo->query('SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ' . $fx['sparepart'])->fetchColumn();
        t_eq($predBefore, $predAfter, 'CLI tidak menjalankan Python otomatis; render/dashboard tetap cepat');
        t_eq($poBefore, (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(), 'Prediksi/pemicu tidak membuat Purchase Order');

        // KELUAR, penyesuaian opname, dan koreksi juga memicu (satu choke point).
        Database::transaction(static fn () => StockLedger::apply(
            $fx['sparepart'], StockMovement::TYPE_OUT, -3, date('Y-m-d'), 'MANUAL', null, null, 'Uji keluar', $actor
        ));
        Database::transaction(static fn () => StockLedger::apply(
            $fx['sparepart'], StockMovement::TYPE_OPNAME, -2, date('Y-m-d'), 'OPNAME', null, null, 'Uji penyesuaian', $actor
        ));
        Database::transaction(static fn () => StockLedger::apply(
            $fx['sparepart'], StockMovement::TYPE_CORRECTION, 1, date('Y-m-d'), 'CORRECTION', null, null, 'Uji koreksi', $actor
        ));

        t_eq(51.0, (float) Sparepart::find($fx['sparepart'])['stock'], 'Stok akhir konsisten setelah keluar/penyesuaian/koreksi');
        t_true(isset(PredictionAutomation::state()['pending'][(string) $fx['sparepart']]), 'Setiap jenis perubahan stok tetap menandai antrean prediksi');
    } finally {
        t_auto_reset();
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3. Transaksi batal TIDAK memicu prediksi
// ------------------------------------------------------------------
function test_prediction_automation_transaksi_batal_tidak_memicu(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-rollback', ['days' => 40, 'stock' => 20, 'min_stock' => 5]);
    $actor = t_make_user('uji_auto_' . bin2hex(random_bytes(2)), 'partman', t_password());

    try {
        $before = (float) Sparepart::find($fx['sparepart'])['stock'];
        try {
            Database::transaction(static function () use ($fx, $actor): void {
                StockLedger::apply($fx['sparepart'], StockMovement::TYPE_IN, 10, date('Y-m-d'), 'MANUAL', null, null, 'Akan dibatalkan', $actor);
                throw new RuntimeException('batal disengaja');
            });
        } catch (RuntimeException $e) {
            // diharapkan
        }

        $state = PredictionAutomation::state();
        t_eq($before, (float) Sparepart::find($fx['sparepart'])['stock'], 'Transaksi batal tidak mengubah stok');
        t_true(!isset($state['pending'][(string) $fx['sparepart']]), 'Transaksi batal tidak menandai antrean prediksi (callback after-commit tidak dijalankan)');
    } finally {
        t_auto_reset();
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4. Sparepart nonaktif diabaikan
// ------------------------------------------------------------------
function test_prediction_automation_nonaktif_diabaikan(): void
{
    t_auto_reset();
    $categoryId = t_make_category('Kategori Prediksi Auto Nonaktif');
    $sparepartId = t_make_sparepart($categoryId, ['is_active' => 0, 'stock' => 5, 'min_stock' => 1]);

    try {
        PredictionAutomation::markDirty([$sparepartId], 'uji nonaktif');
        $result = PredictionAutomation::refreshPending([$sparepartId], 5);

        t_eq('idle', $result['status'], 'Sparepart nonaktif tidak diproses prediksi');
        t_eq(0, (int) $result['processed'], 'Tidak ada prediksi dibuat untuk sparepart nonaktif');
        t_true(AiPrediction::latestForSparepart($sparepartId) === null, 'Sparepart nonaktif tidak menghasilkan rekomendasi baru');
        t_true(!isset(PredictionAutomation::state()['pending'][(string) $sparepartId]), 'Sparepart nonaktif dikeluarkan dari antrean');
    } finally {
        t_auto_reset();
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM ai_predictions WHERE sparepart_id = ?')->execute([$sparepartId]);
        $pdo->prepare('DELETE FROM stock_movements WHERE sparepart_id = ?')->execute([$sparepartId]);
        $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$sparepartId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
}

// ------------------------------------------------------------------
// 5. Lock mencegah proses ganda
// ------------------------------------------------------------------
function test_prediction_automation_lock_mencegah_proses_ganda(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-lock', ['days' => 40, 'stock' => 30, 'min_stock' => 5]);
    $lockFile = BASE_PATH . '/storage/cache/prediction_auto.lock';
    if (!is_dir(dirname($lockFile))) {
        @mkdir(dirname($lockFile), 0775, true);
    }

    try {
        $handle = fopen($lockFile, 'c');
        t_true(is_resource($handle), 'Berkas lock prediksi dapat dibuka');
        if (!is_resource($handle)) {
            t_auto_reset();
            t_pred_purge($fx);
            return;
        }
        flock($handle, LOCK_EX);

        $result = PredictionAutomation::refreshPending([$fx['sparepart']], 5);
        t_eq('busy', $result['status'], 'Saat lock dipegang proses lain, eksekusi berikutnya ditolak (busy)');
        t_eq(0, (int) $result['processed'], 'Tidak ada prediksi dijalankan saat lock sibuk');
        t_true(AiPrediction::latestForSparepart($fx['sparepart']) === null, 'Tidak ada proses ganda yang menulis hasil');

        flock($handle, LOCK_UN);
        fclose($handle);

        t_eq('idle', PredictionAutomation::refreshPending([], 5)['status'], 'Lock dilepas dan antrean kosong ditangani tanpa proses ganda');
    } finally {
        t_auto_reset();
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 6. Kegagalan Python tidak menghapus hasil terakhir
// ------------------------------------------------------------------
function test_prediction_automation_gagal_tidak_hapus_hasil(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-gagal', ['days' => 40, 'stock' => 30, 'min_stock' => 5]);

    try {
        $existingId = t_auto_store_prediction($fx['sparepart'], [
            'methods' => [
                'moving_average' => ['forecast' => 10.0],
                'linear_regression' => ['forecast' => 11.0],
            ],
            'safety_stock' => 4.0,
            'reorder_point' => 9.0,
        ]);

        PredictionAutomation::markDirty([$fx['sparepart']], 'uji gagal');
        $result = PredictionAutomation::refreshPending(
            [$fx['sparepart']],
            5,
            BASE_PATH . '/python/prediction/tidak_ada_skrip.py'
        );

        t_eq('error', $result['status'], 'Kegagalan pipeline dicatat sebagai error (bukan sukses palsu)');
        t_eq(1, (int) $result['failed'], 'Kegagalan dihitung pada status');

        $latest = AiPrediction::latestForSparepart($fx['sparepart']);
        t_true($latest !== null && (int) $latest['id'] === $existingId, 'Hasil prediksi terakhir TIDAK dihapus saat proses baru gagal');
        t_eq('error', (string) PredictionAutomation::state()['last_status'], 'Status kegagalan tersimpan untuk ditampilkan di dashboard');

        $state = PredictionAutomation::state();
        $errorText = (string) ($state['last_error'] ?? '');
        t_true(!str_contains($errorText, BASE_PATH), 'Pesan kegagalan tidak membocorkan path server');
        t_true(stripos($errorText, 'select ') === false && stripos($errorText, 'sqlstate') === false, 'Pesan kegagalan tidak membocorkan SQL');
    } finally {
        t_auto_reset();
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 7. Data historis belum cukup — tanpa angka palsu
// ------------------------------------------------------------------
function test_prediction_automation_data_kurang_tanpa_angka_palsu(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-kurang', ['days' => 0, 'stock' => 8, 'min_stock' => 2]);
    $others = t_auto_isolate($fx['sparepart']);

    try {
        t_auto_store_prediction($fx['sparepart'], ['methods' => null], 'tidak_cukup');
        $row = t_auto_row($fx['sparepart'], 10);

        t_true($row !== null, 'Sparepart tampil di bagian rekomendasi dashboard');
        t_eq('Data historis belum mencukupi', $row['status'] ?? null, 'Status data historis kurang ditampilkan apa adanya');
        t_true($row['moving_average'] === null, 'Moving Average kosong, bukan angka karangan');
        t_true($row['linear_regression'] === null, 'Linear Regression kosong, bukan angka karangan');
        t_true($row['safety_stock'] === null && $row['reorder_point'] === null, 'Safety Stock/ROP kosong saat data belum cukup');
        t_eq(8.0, (float) $row['stock'], 'Stok aktual tetap ditampilkan dari database');
    } finally {
        t_auto_reset();
        t_auto_restore($others);
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 8. End-to-end: penandaan → pipeline Python nyata → hasil tersimpan
// ------------------------------------------------------------------
function test_prediction_automation_end_to_end(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-e2e', ['days' => 70, 'daily_qty' => 2.0, 'stock' => 40, 'min_stock' => 5]);
    $pdo = Database::pdo();

    try {
        $poBefore = (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();

        PredictionAutomation::markDirty([$fx['sparepart']], 'uji end-to-end');
        $result = PredictionAutomation::refreshPending([$fx['sparepart']], 1);

        t_eq('ok', $result['status'], 'Prediksi otomatis menjalankan pipeline nyata dan berhasil');
        t_eq(1, (int) $result['processed'], 'Antrean otomatis diproses tepat satu kali');

        $row = AiPrediction::latestForSparepart($fx['sparepart']);
        t_true($row !== null, 'Hasil prediksi otomatis tersimpan di ai_predictions');
        if ($row !== null) {
            $details = AiPrediction::details($row);
            t_true(is_array($details['methods'] ?? null), 'Hasil menyimpan metode Moving Average & Linear Regression');
            t_true(($details['methods']['moving_average']['forecast'] ?? null) !== null, 'Nilai Moving Average tersedia');
            t_true(($details['methods']['linear_regression']['forecast'] ?? null) !== null, 'Nilai Linear Regression tersedia');
            t_true(($details['safety_stock'] ?? null) !== null, 'Safety Stock tersedia');
            t_true(($details['reorder_point'] ?? null) !== null, 'ROP tersedia');
            t_eq(30, (int) $row['period_days'], 'Otomatisasi memakai periode algoritma 30 hari');
        }

        t_eq(40.0, (float) Sparepart::find($fx['sparepart'])['stock'], 'Prediksi otomatis TIDAK mengubah stok aktual');
        t_eq($poBefore, (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(), 'Prediksi otomatis TIDAK membuat Purchase Order');
        t_eq('ok', (string) PredictionAutomation::state()['last_status'], 'Status eksekusi terakhir tercatat berhasil');
    } finally {
        t_auto_reset();
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 9. Tiga role membaca hasil yang identik + stok sesuai persediaan
// ------------------------------------------------------------------
function test_prediction_automation_tiga_role_konsisten(): void
{
    t_auto_reset();
    $fx = t_pred_fixture('auto-role', ['days' => 50, 'stock' => 25, 'min_stock' => 5]);
    $others = t_auto_isolate($fx['sparepart']);

    try {
        t_auto_store_prediction($fx['sparepart'], [
            'methods' => [
                'moving_average' => ['forecast' => 12.0],
                'linear_regression' => ['forecast' => 13.0],
            ],
            'safety_stock' => 5.0,
            'reorder_point' => 30.0,
        ]);

        $partman = DashboardService::forRole('partman')['prediction'] ?? [];
        $kepala = DashboardService::forRole('kepala_bengkel')['prediction'] ?? [];
        $admin = DashboardService::forRole('admin')['prediction'] ?? [];

        t_eq($partman['rows'] ?? [], $kepala['rows'] ?? [], 'Dashboard Partman & Kepala Bengkel menampilkan hasil prediksi identik');
        t_eq($partman['rows'] ?? [], $admin['rows'] ?? [], 'Dashboard Admin menampilkan hasil prediksi identik');
        t_eq($partman['summary'] ?? [], $admin['summary'] ?? [], 'Ringkasan jumlah prediksi/perlu perhatian/ROP identik antar-role');

        $row = $partman['rows'][0] ?? [];
        $expectedStock = (float) Database::pdo()
            ->query('SELECT stock FROM spareparts WHERE id = ' . $fx['sparepart'])
            ->fetchColumn();
        t_eq($expectedStock, (float) ($row['stock'] ?? -1), 'Stok pada rekomendasi sama dengan stok aktual menu persediaan');
        t_eq(12.0, (float) ($row['moving_average'] ?? 0), 'Nilai Moving Average berasal dari snapshot prediksi');
        t_eq(13.0, (float) ($row['linear_regression'] ?? 0), 'Nilai Linear Regression berasal dari snapshot prediksi');
        t_eq(5.0, (float) ($row['safety_stock'] ?? 0), 'Safety Stock dari pipeline yang sama');
        t_eq(30.0, (float) ($row['reorder_point'] ?? 0), 'ROP dari pipeline yang sama');
        t_true(($row['processed_at'] ?? null) !== null, 'Waktu pembaruan prediksi ditampilkan');

        // Prediksi tidak boleh mengubah stok (dashboard hanya baca).
        $afterStock = (float) Database::pdo()
            ->query('SELECT stock FROM spareparts WHERE id = ' . $fx['sparepart'])
            ->fetchColumn();
        t_eq($expectedStock, $afterStock, 'Membuka dashboard/rekomendasi tidak mengubah stok aktual');
    } finally {
        t_auto_reset();
        t_auto_restore($others);
        t_pred_purge($fx);
    }
}
