<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\StockMovement;
use App\Services\PredictionService;
use App\Services\ReorderRequestService;

/**
 * Pengujian Fase 7 — layanan prediksi (payload, validasi, ketahanan gagal,
 * integrasi saran reorder). Tidak membutuhkan Python nyata: kegagalan dan
 * batas waktu diuji memakai skrip stub sementara.
 *
 * Memakai helper fixture dari test_prediction_pipeline (t_pred_fixture /
 * t_pred_purge — tidak dikumpulkan runner karena tidak berawalan 'test_').
 */

// ------------------------------------------------------------------
// 1. Payload: hanya data terotorisasi, input tidak sah ditolak
// ------------------------------------------------------------------
function test_prediction_payload_dan_input_tidak_sah(): void
{
    $fx = t_pred_fixture('payload', ['days' => 5, 'daily_qty' => 2.0, 'stock' => 30, 'min_stock' => 5]);
    try {
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 8, 30),
            RuntimeException::class,
            'Periode di luar 7/30/90 ditolak'
        );
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 45),
            RuntimeException::class,
            'Rentang riwayat di luar daftar pilihan ditolak'
        );
        t_throws(
            static fn () => PredictionService::buildPayload(999999999, 30, 30),
            RuntimeException::class,
            'Sparepart tidak ada ditolak'
        );

        $payload = PredictionService::buildPayload($fx['sparepart'], 30, 30);
        t_eq(1, (int) $payload['schema'], 'Payload menyertakan versi skema');
        t_eq($fx['sparepart'], (int) $payload['sparepart_id'], 'Payload menyertakan id sparepart terotorisasi');
        t_eq(30, (int) $payload['period_days'], 'Payload menyertakan periode');
        t_eq(30, (int) $payload['history_days'], 'Payload menyertakan rentang riwayat');
        t_eq(30.0, (float) $payload['stock'], 'Payload memuat stok aktual dari database');
        t_eq(5.0, (float) $payload['min_stock'], 'Payload memuat stok minimum');
        t_eq(5, (int) $payload['tx_count'], 'Payload memuat jumlah transaksi keluar');
        t_true(($payload['params'] ?? []) !== [], 'Payload memuat parameter model');
        t_eq(null, $payload['lead_time_days'], 'Lead time kosong dikirim sebagai null');

        // KELUAR bernilai negatif pada ledger → payload HARUS positif (arus keluar).
        $allPositive = true;
        foreach ($payload['daily_out'] as $row) {
            if ((float) $row['qty'] <= 0) {
                $allPositive = false;
            }
        }
        t_true($payload['daily_out'] !== [] && $allPositive, 'Arus keluar pada payload bernilai positif');

        // Sparepart nonaktif ditolak.
        Database::pdo()->prepare('UPDATE spareparts SET is_active = 0 WHERE id = ?')->execute([$fx['sparepart']]);
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 30),
            RuntimeException::class,
            'Sparepart nonaktif tidak dapat diprediksi'
        );
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 2. Validasi keluaran pipeline (tanpa Python)
// ------------------------------------------------------------------
function test_prediction_validasi_keluaran(): void
{
    $valid = [
        'status'                => 'ok',
        'period_days'           => 30,
        'data_sufficiency'      => 'cukup',
        'predicted_demand'      => 60,
        'daily_rate'            => 2,
        'days_to_min'           => 20,
        'days_to_zero'          => 25,
        'stockout_risk'         => 'sedang',
        'recommended_qty'       => 20,
        'safety_stock'          => 0,
        'reorder_point'         => 14,
        'lead_time_days'        => 7,
        'lead_time_assumed'     => true,
        'model_version'         => 'sfm-forecast/1.0.0',
        'comparison'            => ['selected_method' => 'moving_average'],
        'warnings'              => ['uji'],
    ];
    $norm = PredictionService::validateOutput($valid, 30);
    t_eq('moving_average', $norm['method'], 'Metode terpilih dibaca dari output');
    t_eq(20.0, (float) $norm['recommended'], 'Nilai dalam batas diterima');
    t_eq(30, (int) $norm['period'], 'Periode tervalidasi tercatat');

    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'error', 'error' => 'rusak'], 30),
        RuntimeException::class,
        'Output berstatus error ditolak'
    );
    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'ok', 'period_days' => 99, 'data_sufficiency' => 'cukup'], 30),
        RuntimeException::class,
        'Periode output yang tidak sesuai ditolak'
    );
    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'ok', 'period_days' => 30, 'data_sufficiency' => 'entah'], 30),
        RuntimeException::class,
        'Indikator kecukupan tak dikenal ditolak'
    );
    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'ok', 'period_days' => 30, 'data_sufficiency' => 'cukup', 'stockout_risk' => 'kiamat'], 30),
        RuntimeException::class,
        'Risiko stockout tak dikenal ditolak'
    );
    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'ok', 'period_days' => 30, 'data_sufficiency' => 'cukup', 'recommended_qty' => -5], 30),
        RuntimeException::class,
        'Nilai negatif di luar batas ditolak'
    );
    t_throws(
        static fn () => PredictionService::validateOutput(['status' => 'ok', 'period_days' => 30, 'data_sufficiency' => 'cukup', 'comparison' => ['selected_method' => 'ramalan_batin']], 30),
        RuntimeException::class,
        'Metode terpilih tak dikenal ditolak'
    );

    // Tanpa perhitungan → metode "belum_dihitung".
    $empty = PredictionService::validateOutput([
        'status' => 'ok', 'period_days' => 30, 'data_sufficiency' => 'tidak_cukup', 'comparison' => null,
    ], 30);
    t_eq('belum_dihitung', $empty['method'], 'Tanpa perhitungan → metode belum_dihitung');
    t_eq(null, $empty['demand'], 'Estimasi null tetap null');

    t_eq('Moving Average', PredictionService::methodLabel('moving_average'), 'Label Moving Average');
    t_eq('Linear Regression', PredictionService::methodLabel('linear_regression'), 'Label Linear Regression');
    t_eq('Belum dihitung', PredictionService::methodLabel('belum_dihitung'), 'Label belum dihitung');
}

// ------------------------------------------------------------------
// 3a. Proses BERHASIL: keluaran terbaca PHP & tersimpan
// ------------------------------------------------------------------
function test_prediction_proses_berhasil(): void
{
    $fx = t_pred_fixture('sukses', ['days' => 5, 'daily_qty' => 2.0, 'stock' => 30, 'min_stock' => 5]);
    $stubs = [];
    try {
        $out = [
            'schema'                  => 1,
            'status'                  => 'ok',
            'model_version'           => 'stub-1.0',
            'period_days'             => 30,
            'data_sufficiency'        => 'tidak_cukup',
            'data_sufficiency_reason' => 'data uji belum cukup',
            'comparison'              => null,
            'warnings'                => ['catatan stub'],
            'lead_time_days'          => 7,
            'lead_time_assumed'       => true,
            'lead_time_source'        => 'assumption',
            'lead_time_samples'       => 0,
            'history_range'           => ['start' => '2026-01-01', 'end' => '2026-01-05'],
        ];
        $json = (string) json_encode($out);
        $stubs[] = $okStub = t_temp_file('py', "import sys\nsys.stdout.write(" . var_export($json, true) . ")\n");

        $r = PredictionService::run($fx['sparepart'], 30, 30, $okStub);
        t_eq('ok', $r['status'] ?? null, 'Keluaran stub yang sah → status "ok" (berhasil)');
        t_true(($r['prediction_id'] ?? 0) > 0, 'Prediksi berhasil tersimpan (ada prediction_id)');

        $stored = t_pred_stored($fx['sparepart']);
        t_true($stored !== null, 'Baris prediksi ada di database');
        t_eq('tidak_cukup', (string) $stored['data_sufficiency'], 'Kecukupan data dari keluaran tersimpan');
        t_eq('belum_dihitung', (string) $stored['method'], 'Metode belum_dihitung tersimpan');
        t_eq('stub-1.0', (string) $stored['model_version'], 'Versi model keluaran tersimpan');
        t_true(t_latest_audit('PREDIKSI_DIBUAT', (int) $stored['id']) !== null, 'Audit PREDIKSI_DIBUAT tercatat untuk proses berhasil');
    } finally {
        foreach ($stubs as $stub) {
            if (is_file($stub)) {
                @unlink($stub);
            }
        }
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3b. Proses GAGAL: skrip hilang, error, periode salah, JSON rusak
// ------------------------------------------------------------------
function test_prediction_proses_gagal(): void
{
    $fx = t_pred_fixture('gagal', ['days' => 5, 'daily_qty' => 2.0, 'stock' => 4, 'min_stock' => 10]);
    $stubs = [];
    try {
        // Skrip tidak ditemukan.
        $missing = PredictionService::invokePython([], 'C:/tidak/ada/forecast.py');
        t_eq(false, $missing['ok'], 'Skrip tidak ditemukan → gagal terkendali');

        // Stub: pipeline melaporkan error.
        $stubs[] = $err = t_temp_file('py', "import json,sys\nsys.stdout.write(json.dumps({'schema':1,'status':'error','error':'stub gagal'}))\nsys.stdout.flush()\n");
        $r = PredictionService::run($fx['sparepart'], 30, 30, $err);
        t_eq('error', $r['status'] ?? null, 'Error pipeline → status "error" (tidak melempar)');
        t_true(str_contains((string) $r['message'], 'stub gagal'), 'Pesan kegagalan diteruskan');
        t_true(isset($r['fallback']['source']), 'Fallback saran reorder disertakan');
        t_true(t_latest_audit('PREDIKSI_GAGAL', $fx['sparepart']) !== null, 'Audit PREDIKSI_GAGAL tercatat');

        // Stub: periode output tidak sesuai.
        $stubs[] = $wrong = t_temp_file('py', "import json,sys\nsys.stdout.write(json.dumps({'schema':1,'status':'ok','period_days':99,'data_sufficiency':'cukup'}))\nsys.stdout.flush()\n");
        $r = PredictionService::run($fx['sparepart'], 30, 30, $wrong);
        t_eq('error', $r['status'] ?? null, 'Periode output salah → status "error"');
        t_true(str_contains((string) $r['message'], 'Periode'), 'Pesan menyebut periode tidak sesuai');

        // Stub: JSON rusak.
        $stubs[] = $bad = t_temp_file('py', "import sys\nsys.stdout.write('ini bukan json')\n");
        $r = PredictionService::run($fx['sparepart'], 30, 30, $bad);
        t_eq('error', $r['status'] ?? null, 'JSON rusak → status "error"');
        t_true(str_contains((string) $r['message'], 'JSON'), 'Pesan menyebut JSON tidak valid');

        // Stub: hanya menulis ke stderr → error tetap terbaca PHP.
        $stubs[] = $stderrStub = t_temp_file('py', "import sys\nsys.stderr.write('GALAT_DARI_STDERR')\nsys.exit(1)\n");
        $r = PredictionService::run($fx['sparepart'], 30, 30, $stderrStub);
        t_eq('error', $r['status'] ?? null, 'Keluaran hanya di stderr → status "error"');
        t_true(str_contains((string) $r['message'], 'GALAT_DARI_STDERR'), 'Isi stderr terbaca PHP pada pesan kegagalan');

        // Prediksi gagal TIDAK boleh mengubah stok.
        $stock = (float) Database::pdo()->query('SELECT stock FROM spareparts WHERE id = ' . $fx['sparepart'])->fetchColumn();
        t_eq(4.0, $stock, 'Kegagalan prediksi tidak menyentuh stok sparepart');
        $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ' . $fx['sparepart'])->fetchColumn();
        t_eq(0, $count, 'Kegagalan tidak menulis baris prediksi');
    } finally {
        foreach ($stubs as $stub) {
            if (is_file($stub)) {
                @unlink($stub);
            }
        }
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 3c. Proses TIMEOUT: batas waktu berlaku & proses benar-benar dihentikan
// ------------------------------------------------------------------
function test_prediction_proses_timeout(): void
{
    $fx = t_pred_fixture('timeout', ['days' => 5, 'daily_qty' => 2.0, 'stock' => 30, 'min_stock' => 5]);
    $stubs = [];
    $marker = t_temp_file('txt', '');
    @unlink($marker); // hanya ingin jalurnya
    try {
        // Stub tidur 3 detik, BARU menulis marker. Bila proses benar-benar
        // dihentikan saat timeout, marker tidak akan pernah muncul.
        $src = "import time, pathlib\n"
            . "time.sleep(3)\n"
            . "pathlib.Path(r'" . $marker . "').write_text('hidup')\n";
        $stubs[] = $slow = t_temp_file('py', $src);

        $t0 = microtime(true);
        $r = PredictionService::run($fx['sparepart'], 30, 30, $slow, 0.7);
        $elapsed = microtime(true) - $t0;

        t_eq('error', $r['status'] ?? null, 'Proses menggantung → status "error"');
        t_true(str_contains((string) $r['message'], 'batas waktu'), 'Pesan menyebut batas waktu');
        t_true($elapsed < 2.0, sprintf('Batas waktu benar-benar berlaku (%.2f dtk < 2 dtk, stub tidur 3 dtk)', $elapsed));

        usleep(3500000); // lewati durasi tidur stub (3 dtk)
        t_true(!is_file($marker), 'Proses Python benar-benar dihentikan (marker tidak ditulis)');

        // Tidak ada baris prediksi & audit kegagalan tercatat.
        $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM ai_predictions WHERE sparepart_id = ' . $fx['sparepart'])->fetchColumn();
        t_eq(0, $count, 'Timeout tidak menulis baris prediksi');
        t_true(t_latest_audit('PREDIKSI_GAGAL', $fx['sparepart']) !== null, 'Audit PREDIKSI_GAGAL tercatat saat timeout');
    } finally {
        foreach ($stubs as $stub) {
            if (is_file($stub)) {
                @unlink($stub);
            }
        }
        if (is_file($marker)) {
            @unlink($marker);
        }
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4. Integrasi saran reorder memakai prediksi AI (segar & memadai)
// ------------------------------------------------------------------
function test_prediction_integrasi_saran_reorder(): void
{
    // Stok 4,5 & minimum 10 → aturan minimum memberi 6.
    $fx = t_pred_fixture('saran', ['days' => 0, 'stock' => 4.5, 'min_stock' => 10]);
    try {
        $base = ReorderRequestService::suggestion($fx['sparepart']);
        t_eq('aturan_minimum', $base['source'], 'Tanpa prediksi → fallback aturan stok-minimum');
        t_eq(null, $base['prediction_id'], 'Fallback tidak menautkan prediksi');

        $insert = Database::pdo()->prepare(
            'INSERT INTO ai_predictions
                (sparepart_id, period_days, method, predicted_demand, recommended_qty,
                 stockout_risk, data_sufficiency, model_version, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // Prediksi memadai & segar.
        $insert->execute([$fx['sparepart'], 30, 'moving_average', 60, 12, 'sedang', 'cukup', 'uji-1.0', date('Y-m-d H:i:s')]);
        $freshId = (int) Database::pdo()->lastInsertId();

        $s = ReorderRequestService::suggestion($fx['sparepart']);
        t_eq('ai', $s['source'], 'Prediksi segar & memadai dipakai sebagai sumber saran');
        t_eq(12.0, (float) $s['suggested_qty'], 'Qty saran berasal dari prediksi AI');
        t_eq($freshId, (int) $s['prediction_id'], 'Saran menautkan prediction_id yang benar');

        // Prediksi lebih tua diabaikan (kedaluwarsa) — tetap memakai yang segar.
        $insert->execute([$fx['sparepart'], 30, 'linear_regression', 90, 40, 'tinggi', 'cukup', 'uji-1.0', date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $s = ReorderRequestService::suggestion($fx['sparepart']);
        t_eq(12.0, (float) $s['suggested_qty'], 'Prediksi kedaluwarsa (>7 hari) tidak dipakai');

        // Prediksi yang lebih baru tetapi data tidak cukup → diabaikan.
        $insert->execute([$fx['sparepart'], 30, 'moving_average', null, 99, null, 'tidak_cukup', 'uji-1.0', date('Y-m-d H:i:s')]);
        $s = ReorderRequestService::suggestion($fx['sparepart']);
        t_eq(12.0, (float) $s['suggested_qty'], 'Prediksi data tidak cukup tidak dipakai');

        // Tanpa prediksi yang layak → kembali ke aturan minimum.
        Database::pdo()->prepare('DELETE FROM ai_predictions WHERE sparepart_id = ?')->execute([$fx['sparepart']]);
        $s = ReorderRequestService::suggestion($fx['sparepart']);
        t_eq('aturan_minimum', $s['source'], 'Semua prediksi tidak layak → fallback aturan minimum');
    } finally {
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4b. Lead time DIPRIORITASKAN dari riwayat penerimaan pembelian
// ------------------------------------------------------------------
function test_prediction_lead_time_riwayat_penerimaan(): void
{
    // Master supplier menyebut 20 hari, TETAPI riwayat PO→terima = 10 hari.
    $fx = t_pred_fixture('ltriwayat', [
        'days' => 60, 'daily_qty' => 2.0, 'stock' => 50, 'min_stock' => 10,
        'supplier' => true, 'lead_time' => 20,
    ]);
    $pdo = Database::pdo();
    $userId = 0;
    $poId = 0;
    $poItemId = 0;
    $receiptId = 0;
    try {
        $userId = t_make_user('pred_po_user', 'partman', t_password());

        $poDate = date('Y-m-d H:i:s', strtotime('-10 days'));
        $pdo->prepare(
            'INSERT INTO purchase_orders (po_number, supplier_id, status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([t_uniq('PO-PRED'), $fx['supplier'], 'SELESAI_DITERIMA', $userId, $poDate]);
        $poId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$poId, $fx['sparepart'], 100, 10000, 100]);
        $poItemId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, purchase_order_id, recorded_by)
             VALUES (?, CURDATE(), ?, ?, ?)'
        )->execute([t_uniq('RC-PRED'), $fx['supplier'], $poId, $userId]);
        $receiptId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price, po_item_id)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$receiptId, $fx['sparepart'], 100, 10000, $poItemId]);

        $payload = PredictionService::buildPayload($fx['sparepart'], 30, 60);
        t_eq([10], $payload['lead_time_receipt_days'], 'Lead time dari riwayat penerimaan terbaca (10 hari)');

        $result = PredictionService::run($fx['sparepart'], 30, 60);
        t_eq('ok', $result['status'] ?? null, 'Prediksi dengan riwayat lead time berhasil');
        $norm = $result['norm'] ?? [];
        t_eq('receipt_history', (string) $norm['lead_time_source'], 'Sumber lead time = riwayat penerimaan (diprioritaskan)');
        t_eq(10, (int) $norm['lead_time_days'], 'Lead time = 10 hari (median riwayat, bukan 20 master)');
        t_eq(false, (bool) $norm['lead_time_assumed'], 'Lead time riwayat dianggap andal');
        t_true((bool) ($norm['lead_time_reliable'] ?? false), 'ROP ditandai andal saat lead time terukur');
    } finally {
        if ($receiptId > 0) {
            $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id = ?')->execute([$receiptId]);
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$receiptId]);
        }
        if ($poItemId > 0) {
            $pdo->prepare('DELETE FROM purchase_order_items WHERE id = ?')->execute([$poItemId]);
        }
        if ($poId > 0) {
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        }
        if ($userId > 0) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 4c. Override lead time manual (C1.4/C1.5): alasan wajib + audit
// ------------------------------------------------------------------
function test_prediction_lead_time_override(): void
{
    $fx = t_pred_fixture('ltoverride', ['days' => 5, 'daily_qty' => 2.0, 'stock' => 30, 'min_stock' => 5]);
    $stubs = [];
    $reason = 'Permintaan pemilik bengkel untuk pengadaan khusus';
    try {
        // Validasi input override (backend, bukan hanya form).
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 30, 0, $reason),
            RuntimeException::class,
            'Override 0 hari ditolak'
        );
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 30, 366, $reason),
            RuntimeException::class,
            'Override lebih dari 365 hari ditolak'
        );
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 30, 12, 'pendek'),
            RuntimeException::class,
            'Override tanpa alasan memadai ditolak'
        );
        t_throws(
            static fn () => PredictionService::buildPayload($fx['sparepart'], 30, 30, null, $reason),
            RuntimeException::class,
            'Alasan tanpa jumlah hari override ditolak'
        );

        $payload = PredictionService::buildPayload($fx['sparepart'], 30, 30, 12, $reason);
        t_eq(12, (int) $payload['lead_time_override_days'], 'Override lead time masuk ke payload');
        $auto = PredictionService::autoLeadTime($payload);
        t_eq(7, (int) $auto['days'], 'Lead time otomatis tanpa data = asumsi 7 hari');
        t_eq('assumption', (string) $auto['source'], 'Sumber otomatis = asumsi');

        // Stub mengembalikan source manual_override; pipeline Python nyata diuji
        // terpisah di test_prediction_pipeline.php.
        $out = [
            'schema'                  => 1,
            'status'                  => 'ok',
            'model_version'           => 'stub-1.0',
            'period_days'             => 30,
            'data_sufficiency'        => 'cukup',
            'data_sufficiency_reason' => 'data uji',
            'comparison'              => ['selected_method' => 'moving_average'],
            'warnings'                => [],
            'lead_time_days'          => 12,
            'lead_time_assumed'       => false,
            'lead_time_source'        => 'manual_override',
            'lead_time_samples'       => 0,
            'predicted_demand'        => 60,
            'daily_rate'              => 2,
            'days_to_min'             => 20,
            'days_to_zero'            => 25,
            'stockout_risk'           => 'sedang',
            'recommended_qty'         => 20,
            'safety_stock'            => 4,
            'reorder_point'           => 28,
            'history_range'           => ['start' => '2026-01-01', 'end' => '2026-01-30'],
        ];
        $json = (string) json_encode($out);
        $stubs[] = $stub = t_temp_file('py', "import sys\nsys.stdout.write(" . var_export($json, true) . ")\n");

        $r = PredictionService::run($fx['sparepart'], 30, 30, $stub, null, 12, $reason);
        t_eq('ok', $r['status'] ?? null, 'Prediksi dengan override lead time berhasil');

        $stored = t_pred_stored($fx['sparepart']);
        t_true($stored !== null, 'Baris prediksi override tersimpan');
        if ($stored !== null) {
            $details = App\Models\AiPrediction::details($stored);
            $lt = is_array($details['lead_time'] ?? null) ? $details['lead_time'] : [];
            t_eq('manual_override', (string) ($lt['source'] ?? ''), 'Sumber lead time tersimpan = manual_override');
            t_eq(12, (int) ($lt['days'] ?? 0), 'Lead time override tersimpan');
            t_true((bool) ($lt['override'] ?? false), 'Detail menandai override');
            t_eq($reason, (string) ($lt['reason'] ?? ''), 'Alasan override tersimpan');
            t_eq(7, (int) ($lt['auto_days'] ?? 0), 'Nilai otomatis tersimpan untuk jejak');
            t_true((bool) ($lt['reliable'] ?? false), 'Lead time override dianggap andal (bukan asumsi)');

            $audit = t_latest_audit('LEAD_TIME_OVERRIDE', (int) $stored['id']);
            t_true($audit !== null, 'Audit LEAD_TIME_OVERRIDE tercatat');
            if ($audit !== null) {
                t_true(str_contains((string) $audit['description'], 'override manual'), 'Audit menjelaskan override');
                t_true(str_contains((string) $audit['description'], $reason), 'Audit memuat alasan override');
            }
        }
    } finally {
        foreach ($stubs as $stub) {
            if (is_file($stub)) {
                @unlink($stub);
            }
        }
        t_pred_purge($fx);
    }
}

// ------------------------------------------------------------------
// 5. Klasifikasi fast/slow mengikuti pengaturan laporan
// ------------------------------------------------------------------
function test_prediction_klasifikasi_mengikuti_pengaturan(): void
{
    $fx = t_pred_fixture('klasifikasi', ['days' => 0, 'stock' => 100, 'min_stock' => 10]);
    try {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type)
             VALUES (?, ?, ?, CURDATE(), 0, 0, ?)'
        )->execute([$fx['sparepart'], StockMovement::TYPE_OUT, -50, 'GOODS_ISSUE']);

        $class = PredictionService::classification($fx['sparepart']);
        t_eq(50.0, (float) $class['qty_out'], 'Klasifikasi menjumlahkan arus keluar (nilai absolut)');
        t_true(in_array($class['class'], ['fast', 'medium', 'slow'], true), 'Klasifikasi menghasilkan kategori valid');
        $config = App\Services\SettingService::reportConfig();
        t_eq((int) $config['period_days'], (int) $class['period_days'], 'Periode klasifikasi mengikuti pengaturan laporan');
        t_eq((int) $config['fast_threshold'], (int) $class['fast_threshold'], 'Ambang fast mengikuti pengaturan laporan');
    } finally {
        t_pred_purge($fx);
    }
}
