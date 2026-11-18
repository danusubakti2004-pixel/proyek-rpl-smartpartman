<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\AiPrediction;
use App\Models\StockMovement;
use App\Services\PredictionService;

/**
 * Pengujian Fase 7 — pipeline prediksi stok (proses Python lokal).
 *
 * Mencakup matriks wajib Fase 7:
 *   - dataset kosong; data sangat sedikit; data normal;
 *   - permintaan musiman/tidak stabil;
 *   - lead time kosong; stok 0; stok minimum 0 / tidak valid (negatif);
 *   - prediksi 7/30/90 hari;
 *   - pembanding hasil terhadap perhitungan manual (deret konstan).
 *
 * Setiap fungsi membangun fixture sendiri, menjalankan pipeline SUNGGUHAN
 * (py -3.12), lalu membersihkan datanya agar idempoten. Kegagalan pipeline
 * diuji tidak boleh menggagalkan transaksi inventory (lihat
 * test_prediction_service).
 */

/**
 * Fixture prediksi: kategori + sparepart (+supplier opsional) dan riwayat
 * barang keluar harian. KELUAR dicatat NEGATIF pada ledger (konvensi
 * StockLedger), persis seperti transaksi sungguhan.
 *
 * @param array{days?:int,daily_qty?:float|array<int,float>,stock?:float,min_stock?:float,
 *              lead_time?:int|null,supplier?:bool} $opts
 * @return array{sparepart:int,category:int,supplier:?int}
 */
function t_pred_fixture(string $tag, array $opts = []): array
{
    $days      = (int) ($opts['days'] ?? 60);
    $daily     = $opts['daily_qty'] ?? 2.0;
    $stock     = (float) ($opts['stock'] ?? 50);
    $minStock  = (float) ($opts['min_stock'] ?? 10);
    $leadTime  = $opts['lead_time'] ?? null;
    $supplier  = (bool) ($opts['supplier'] ?? false);

    $pdo = Database::pdo();
    $categoryId = t_make_category('Kategori Prediksi ' . $tag);

    $supplierId = null;
    if ($supplier) {
        $supplierId = t_make_supplier('Supplier Prediksi ' . $tag);
        if ($leadTime !== null) {
            $pdo->prepare('UPDATE suppliers SET lead_time_days = ? WHERE id = ?')
                ->execute([(int) $leadTime, $supplierId]);
        }
    }

    $sparepartId = t_make_sparepart($categoryId, [
        'stock'       => $stock,
        'min_stock'   => $minStock,
        'supplier_id' => $supplierId,
        'unit'        => 'pcs',
    ]);

    $insert = $pdo->prepare(
        'INSERT INTO stock_movements
            (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type)
         VALUES (?, ?, ?, ?, 0, 0, ?)'
    );
    for ($i = 0; $i < $days; $i++) {
        $qty = is_array($daily) ? (float) ($daily[$i] ?? 0.0) : (float) $daily;
        if ($qty <= 0) {
            continue;
        }
        $insert->execute([
            $sparepartId,
            StockMovement::TYPE_OUT,
            -1 * $qty, // KELUAR negatif pada ledger
            date('Y-m-d', strtotime('-' . $i . ' days')),
            'GOODS_ISSUE',
        ]);
    }

    return ['sparepart' => $sparepartId, 'category' => $categoryId, 'supplier' => $supplierId];
}

/** Hapus fixture prediksi (anak → induk) agar pengujian idempoten. */
function t_pred_purge(array $fx): void
{
    $pdo = Database::pdo();
    $pdo->prepare('DELETE FROM ai_predictions WHERE sparepart_id = ?')->execute([$fx['sparepart']]);
    $pdo->prepare('DELETE FROM stock_movements WHERE sparepart_id = ?')->execute([$fx['sparepart']]);
    $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$fx['sparepart']]);
    if (($fx['supplier'] ?? null) !== null) {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$fx['supplier']]);
    }
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$fx['category']]);
}

/** Baris prediksi tersimpan terbaru untuk sparepart (assert helper). */
function t_pred_stored(int $sparepartId): ?array
{
    return AiPrediction::latestForSparepart($sparepartId);
}

/** Apakah teks mengandung frasa (tanpa membedakan huruf besar/kecil). */
function t_pred_has(string $needle, string $haystack): bool
{
    return stripos($haystack, $needle) !== false;
}

// ------------------------------------------------------------------
// 1. Dataset kosong — TIDAK BOLEH mengarang angka
// ------------------------------------------------------------------
function test_prediction_kosong(): void
{
    $fx = t_pred_fixture('kosong', ['days' => 0, 'stock' => 10, 'min_stock' => 5]);
    try {
        $result = PredictionService::run($fx['sparepart'], 7, 30);

        t_eq('ok', $result['status'] ?? null, 'Pipeline tetap menjawab "ok" untuk dataset kosong');
        $norm = $result['norm'];
        t_eq('tidak_cukup', $norm['data_sufficiency'], 'Dataset kosong ditandai data tidak cukup');
        t_eq('belum_dihitung', $norm['method'], 'Metode "belum_dihitung" saat data tidak cukup');
        t_eq(null, $norm['demand'], 'Estimasi permintaan NULL (tidak dikarang)');
        t_eq(null, $norm['recommended'], 'Saran reorder NULL (tidak dikarang)');
        t_eq(null, $norm['risk'], 'Risiko stockout NULL saat tidak dihitung');
        t_true(t_pred_has('tidak', (string) $norm['reason']), 'Alasan ketidakcukupan data dijelaskan');

        $stored = t_pred_stored($fx['sparepart']);
        t_true($stored !== null, 'Baris prediksi tetap disimpan sebagai catatan jujur');
        t_eq(null, $stored['predicted_demand'] ?? null, 'Kolom predicted_demand tersimpan NULL');
        t_eq('belum_dihitung', (string) $stored['method'], 'Metode tersimpan "belum_dihitung"');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 2. Data sangat sedikit
// ------------------------------------------------------------------
function test_prediction_data_sangat_sedikit(): void
{
    $fx = t_pred_fixture('sedikit', ['days' => 3, 'daily_qty' => 2.0, 'stock' => 20, 'min_stock' => 5]);
    try {
        $norm = PredictionService::run($fx['sparepart'], 30, 30)['norm'];
        t_eq('tidak_cukup', $norm['data_sufficiency'], '3 transaksi masih "tidak_cukup"');
        t_eq(null, $norm['recommended'], 'Tidak ada saran saat data sangat sedikit');
        t_eq(null, $norm['days_to_min'], 'Perkiraan hari ke minimum tidak dikarang');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3. Data normal + pembanding perhitungan manual (deret konstan 2/hari)
// ------------------------------------------------------------------
function test_prediction_normal_dan_pembanding_manual(): void
{
    // Deret konstan 2/hari selama 60 hari; stok 50; minimum 10; lead time 7.
    $fx = t_pred_fixture('normal', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10,
        'supplier' => true, 'lead_time' => 7,
    ]);
    try {
        $result = PredictionService::run($fx['sparepart'], 30, 60);
        t_eq('ok', $result['status'] ?? null, 'Pipeline berhasil untuk data normal');
        $norm = $result['norm'];

        t_eq('cukup', $norm['data_sufficiency'], '60 transaksi pada 60 hari berstatus "cukup"');
        t_eq('moving_average', $norm['method'], 'Seri konstan → Moving Average (seri MAE) dipilih');

        // --- Pembanding manual untuk deret konstan 2/hari, periode 30 ---
        t_eq(2.0, (float) $norm['daily_rate'], 'Laju harian manual = 2');
        t_eq(60.0, (float) $norm['demand'], 'Estimasi permintaan manual = 2 × 30 = 60');
        t_eq(20, (int) $norm['days_to_min'], 'Hari ke minimum manual = floor((50−10)/2) = 20');
        t_eq(25, (int) $norm['days_to_zero'], 'Hari ke nol manual = floor(50/2) = 25');
        // SS = z·σ·√lead; σ konstan = 0 → SS = 0; ROP = 2×7 + 0 = 14.
        t_eq(0.0, (float) $norm['safety_stock'], 'Safety Stock manual = 0 (σ deret = 0)');
        t_eq(14.0, (float) $norm['reorder_point'], 'ROP manual = 2×7 + 0 = 14');
        // Saran = ceil(60 + 0 + 10 − 50) = 20.
        t_eq(20.0, (float) $norm['recommended'], 'Saran reorder manual = ceil(60+0+10−50) = 20');
        t_eq(2.0, (float) ($norm['methods']['moving_average']['daily_avg'] ?? 0), 'Rata-rata MA manual = 2');

        t_true((bool) ($norm['evaluation']['performed'] ?? false), 'Evaluasi kronologis dilakukan pada data cukup');
        t_true(isset($norm['evaluation']['moving_average']['mae']), 'MAE Moving Average dihitung');
        t_true(isset($norm['evaluation']['linear_regression']['mae']), 'MAE Linear Regression dihitung');
        t_eq(false, (bool) $norm['lead_time_assumed'], 'Lead time master supplier dipakai (bukan asumsi)');
        t_eq(7, (int) $norm['lead_time_days'], 'Lead time master = 7 hari');

        $stored = t_pred_stored($fx['sparepart']);
        t_true($stored !== null, 'Hasil tersimpan pada tabel ai_predictions');
        t_eq(30, (int) $stored['period_days'], 'Periode 30 hari tersimpan');
        t_eq('sfm-forecast/1.0.0', (string) $stored['model_version'], 'Versi model tercatat');
        t_true((string) $stored['details'] !== '', 'Details JSON tersimpan');
        $details = AiPrediction::details($stored);
        t_eq(false, (bool) ($details['lead_time']['assumed'] ?? true), 'Details mencatat lead time bukan asumsi');
        t_eq('cli_proc_open', (string) ($details['integration']['method'] ?? ''), 'Metode integrasi tercatat (CLI lokal)');
        t_eq(60, (int) ($details['analysis']['history_days'] ?? 0), 'Rentang riwayat tercatat pada details');

        $audit = t_latest_audit('PREDIKSI_DIBUAT', (int) $stored['id']);
        t_true($audit !== null, 'Audit PREDIKSI_DIBUAT tercatat untuk prediksi ini');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4. Periode 7 / 30 / 90 hari
// ------------------------------------------------------------------
function test_prediction_periode_7_30_90(): void
{
    $fx = t_pred_fixture('periode', [
        'days' => 90, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10,
        'supplier' => true, 'lead_time' => 7,
    ]);
    try {
        foreach ([7, 30, 90] as $period) {
            $norm = PredictionService::run($fx['sparepart'], $period, 90)['norm'];
            t_eq($period, (int) $norm['period'], "Output periode = {$period} hari");
            t_true(in_array($norm['data_sufficiency'], PredictionService::SUFFICIENCY, true), "Indikator kecukupan valid ({$period} hari)");
            // Deret konstan 2/hari → estimasi = 2 × periode.
            t_eq((float) (2 * $period), (float) $norm['demand'], "Estimasi {$period} hari = 2 × {$period}");

            $stored = t_pred_stored($fx['sparepart']);
            t_eq($period, (int) $stored['period_days'], "Periode {$period} tersimpan sebagai baris tersendiri");
        }
        t_true(count($historyRows = AiPrediction::historyForSparepart($fx['sparepart'], 10)) >= 3, 'Riwayat menyimpan tiap periode');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 5. Permintaan musiman / tidak stabil + lead time KOSONG
// ------------------------------------------------------------------
function test_prediction_musiman_dan_lead_time_kosong(): void
{
    // Pola bergantian 1 dan 10 per hari (rata-rata 5,5) selama 90 hari.
    $pattern = [];
    for ($i = 0; $i < 90; $i++) {
        $pattern[] = ($i % 2 === 0) ? 1.0 : 10.0;
    }
    // Tanpa supplier → lead time kosong.
    $fx = t_pred_fixture('musiman', ['days' => 90, 'daily_qty' => $pattern, 'stock' => 100, 'min_stock' => 10]);
    try {
        $norm = PredictionService::run($fx['sparepart'], 30, 90)['norm'];

        t_true(in_array($norm['data_sufficiency'], ['cukup', 'terbatas'], true), 'Data musiman cukup untuk dihitung');
        t_true((float) $norm['demand'] > 0, 'Estimasi permintaan dihitung (> 0)');
        t_eq(true, (bool) $norm['lead_time_assumed'], 'Lead time kosong → ditandai asumsi');
        $warnings = implode(' | ', $norm['warnings']);
        t_true(t_pred_has('lead time', $warnings), 'Peringatan lead time kosong dijelaskan');
        t_true(t_pred_has('asumsi', $warnings), 'Peringatan menyebut perhitungan memakai asumsi');
        t_true((float) $norm['safety_stock'] > 0, 'Safety Stock > 0 karena permintaan berfluktuasi');
        t_true((bool) ($norm['evaluation']['performed'] ?? false), 'Evaluasi dilakukan pada permintaan tidak stabil');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 6. Lead time tersedia pada master supplier
// ------------------------------------------------------------------
function test_prediction_lead_time_master(): void
{
    $fx = t_pred_fixture('leadtime', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10,
        'supplier' => true, 'lead_time' => 5,
    ]);
    try {
        $norm = PredictionService::run($fx['sparepart'], 30, 60)['norm'];
        t_eq(false, (bool) $norm['lead_time_assumed'], 'Lead time master dipakai (bukan asumsi)');
        t_eq(5, (int) $norm['lead_time_days'], 'Lead time = 5 hari');
        t_eq(10.0, (float) $norm['reorder_point'], 'ROP manual = 2×5 + 0 = 10');
        $warnings = implode(' | ', $norm['warnings']);
        t_true(!t_pred_has('Lead time supplier tidak tersedia', $warnings), 'Tidak ada peringatan lead time kosong');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 6b. Override lead time manual (C1.4) — pipeline NYATA menghormatinya
// ------------------------------------------------------------------
function test_prediction_lead_time_override_pipeline(): void
{
    $fx = t_pred_fixture('ltoverride', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10,
        'supplier' => true, 'lead_time' => 5,
    ]);
    try {
        $reason = 'Permintaan pemilik bengkel untuk pengadaan khusus';
        $result = PredictionService::run($fx['sparepart'], 30, 60, null, null, 15, $reason);
        t_eq('ok', $result['status'] ?? null, 'Pipeline nyata menerima override lead time');
        $norm = $result['norm'];
        t_eq('manual_override', (string) $norm['lead_time_source'], 'Sumber lead time = manual_override (mengalahkan master 5 hari)');
        t_eq(15, (int) $norm['lead_time_days'], 'Lead time override 15 hari dipakai pipeline');
        t_eq(false, (bool) $norm['lead_time_assumed'], 'Override dianggap andal (bukan asumsi)');
        t_true(abs((float) $norm['reorder_point'] - 30.0) < 0.01, 'ROP memakai lead time override (laju 2 × 15 = 30)');

        $stored = t_pred_stored($fx['sparepart']);
        t_true($stored !== null, 'Baris prediksi override pipeline tersimpan');
        if ($stored !== null) {
            $details = AiPrediction::details($stored);
            t_eq('manual_override', (string) ($details['lead_time']['source'] ?? ''), 'Details menyimpan sumber override');
            t_eq(15, (int) ($details['lead_time']['days'] ?? 0), 'Details menyimpan lead time override');
            t_eq($reason, (string) ($details['lead_time']['reason'] ?? ''), 'Details menyimpan alasan override');
            t_eq(5, (int) ($details['lead_time']['auto_days'] ?? 0), 'Details menyimpan nilai otomatis (master 5 hari)');
            t_true(t_latest_audit('LEAD_TIME_OVERRIDE', (int) $stored['id']) !== null, 'Audit override tercatat dari pipeline nyata');
        }
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 7. Stok 0
// ------------------------------------------------------------------
function test_prediction_stok_nol(): void
{
    $fx = t_pred_fixture('stoknol', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 0, 'min_stock' => 5,
        'supplier' => true, 'lead_time' => 7,
    ]);
    try {
        $norm = PredictionService::run($fx['sparepart'], 30, 60)['norm'];
        t_eq(0, (int) $norm['days_to_min'], 'Stok 0 → sudah di bawah minimum (0 hari)');
        t_eq(0, (int) $norm['days_to_zero'], 'Stok 0 → 0 hari menuju nol');
        t_eq('tinggi', $norm['risk'], 'Stok 0 → risiko stockout tinggi');
        t_eq(65.0, (float) $norm['recommended'], 'Saran manual = ceil(60+0+5−0) = 65');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 8. Stok minimum 0 (tidak boleh dianggap error)
// ------------------------------------------------------------------
function test_prediction_min_stock_nol(): void
{
    $fx = t_pred_fixture('minnol', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 0,
        'supplier' => true, 'lead_time' => 7,
    ]);
    try {
        $norm = PredictionService::run($fx['sparepart'], 30, 60)['norm'];
        t_eq('cukup', $norm['data_sufficiency'], 'Stok minimum 0 tetap dapat diprediksi');
        t_eq(25, (int) $norm['days_to_min'], 'Hari ke minimum manual = floor(50/2) = 25');
        t_eq(10.0, (float) $norm['recommended'], 'Saran manual = ceil(60+0+0−50) = 10');
        $warnings = implode(' | ', $norm['warnings']);
        t_true(!t_pred_has('Stok minimum', $warnings), 'Minimum 0 bukan peringatan (nilai sah)');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 9. Stok minimum tidak valid (negatif) — dijaga berlapis, bukan crash
// ------------------------------------------------------------------
function test_prediction_min_stock_tidak_valid(): void
{
    $fx = t_pred_fixture('minneg', ['days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10]);
    try {
        // (a) Lapis pertama: skema menolak data master yang tidak valid.
        $rejected = false;
        try {
            Database::pdo()->prepare('UPDATE spareparts SET min_stock = -5 WHERE id = ?')
                ->execute([$fx['sparepart']]);
        } catch (PDOException $e) {
            $rejected = true;
        }
        t_true($rejected, 'Skema menolak stok minimum negatif (CHECK constraint)');

        // (b) Lapis kedua: bila nilai tak valid tetap lolos (mis. data lama/impor),
        // pipeline memperlakukannya 0 dan memberi peringatan — bukan crash.
        $payload = PredictionService::buildPayload($fx['sparepart'], 30, 60);
        $payload['min_stock'] = -5.0;

        $invocation = PredictionService::invokePython($payload);
        t_eq(true, $invocation['ok'], 'Pipeline tetap dapat dijalankan dengan minimum tak valid');
        $out = json_decode((string) $invocation['raw'], true);
        t_true(is_array($out), 'Keluaran pipeline dengan minimum tak valid berupa JSON');

        $warnings = implode(' | ', $out['warnings'] ?? []);
        t_true(t_pred_has('minimum', $warnings) && t_pred_has('negatif', $warnings), 'Pipeline memperingatkan minimum negatif');

        $norm = PredictionService::validateOutput($out, 30);
        t_true($norm['recommended'] !== null, 'Saran tetap dihitung (minimum diperlakukan 0)');
        t_eq(10.0, (float) $norm['recommended'], 'Saran manual dengan minimum 0 = ceil(60+0+0−50) = 10');
    } finally {
        t_pred_purge($fx);
    }
}
