<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use RuntimeException;

/**
 * Prediksi stok (Fase 7 — AI Stock Prediction).
 *
 * Metode integrasi PHP ↔ Python (keputusan tercatat di docs/ASSUMPTIONS.md §H):
 *   PROSES CLI LOKAL via proc_open dengan argumen array (TANPA shell),
 *   data lewat JSON pada stdin/stdout. Tidak ada socket/proses jaringan —
 *   layanan Python mustahil terbuka ke jaringan publik (lokal-only by design).
 *
 * Keamanan & ketahanan:
 *   - Payload dibangun sepenuhnya di server (prepared statement). Browser
 *     hanya mengirim sparepart_id + periode + rentang riwayat sebagai
 *     bilangan yang divalidasi; TIDAK PERNAH menerima SQL dari luar/LLM.
 *   - Output Python divalidasi ketat sebelum disimpan (skema, periode,
 *     enum, batas nilai, panjang string).
 *   - Kegagalan Python → array status "error" + fallback aturan
 *     stok-minimum; TIDAK PERNAH melempar ke jalur transaksi inventory
 *     (Aturan Fase 7 ayat 8-9). Hanya input tidak sah yang melempar
 *     RuntimeException untuk ditangani controller.
 *   - Batas waktu proses (timeout) & batas ukuran input/output mencegah
 *     proses menggantung atau membanjiri memori.
 *
 * Prediksi = REKOMENDASI. Partman meninjau; mengubah jumlah dari saran
 * selalu wajib alasan (ReorderRequestService).
 */
final class PredictionService
{
    /** Periode target yang didukung. */
    public const PERIODS = [7, 30, 90];

    /** Rentang riwayat yang dapat dipilih pengguna. */
    public const HISTORY_OPTIONS = [30, 60, 90, 180, 365];

    public const DEFAULT_PERIOD = 30;
    public const DEFAULT_HISTORY = 90;

    /** Batas waktu proses Python (detik). */
    public const TIMEOUT_SECONDS = 20.0;

    /** Batas ukuran payload input & output proses Python. */
    public const MAX_INPUT_BYTES = 262144;
    public const MAX_OUTPUT_BYTES = 1048576;

    /** Prediksi lebih tua dari ini tidak lagi dipakai sebagai saran reorder. */
    public const PREDICTION_MAX_AGE_DAYS = 7;

    /** Enum kecukupan data & risiko (harus selaras dengan pipeline Python). */
    public const SUFFICIENCY = ['cukup', 'terbatas', 'tidak_cukup'];
    public const RISKS = ['rendah', 'sedang', 'tinggi'];
    public const SELECTED_METHODS = ['moving_average', 'linear_regression'];
    public const LEAD_TIME_SOURCES = ['receipt_history', 'supplier_master', 'manual_override', 'assumption'];

    /** Batas override lead time manual oleh Partman (C1 keputusan final). */
    public const MIN_LEAD_TIME_DAYS = 1;
    public const MAX_LEAD_TIME_DAYS = 365;
    /** Panjang minimum alasan override lead time (alasan wajib). */
    public const MIN_OVERRIDE_REASON_LENGTH = 10;

    /**
     * Parameter model yang dikirim ke pipeline (ikut dicatat pada details).
     * Angka-angka ini juga dipertahankan oleh forecast.py sebagai bawaan.
     */
    public const PARAMS = [
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
    ];

    // ==================================================================
    // Payload — data terotorisasi dari database (server-side saja)
    // ==================================================================

    /**
     * Bangun payload terstruktur untuk pipeline Python.
     *
     * @throws RuntimeException bila input tidak sah / sparepart tak ditemukan
     * @return array<string,mixed>
     */
    public static function buildPayload(
        int $sparepartId,
        int $periodDays,
        int $historyDays,
        ?int $leadTimeOverrideDays = null,
        ?string $leadTimeReason = null
    ): array {
        if (!in_array($periodDays, self::PERIODS, true)) {
            throw new RuntimeException('Periode target harus 7, 30, atau 90 hari.');
        }
        if (!in_array($historyDays, self::HISTORY_OPTIONS, true)) {
            throw new RuntimeException('Rentang riwayat harus salah satu dari 30, 60, 90, 180, atau 365 hari.');
        }

        // Override lead time manual (C1.4): jumlah hari + alasan WAJIB.
        $overrideReason = trim((string) $leadTimeReason);
        if ($leadTimeOverrideDays !== null) {
            if ($leadTimeOverrideDays < self::MIN_LEAD_TIME_DAYS
                || $leadTimeOverrideDays > self::MAX_LEAD_TIME_DAYS) {
                throw new RuntimeException(sprintf(
                    'Override lead time harus antara %d dan %d hari.',
                    self::MIN_LEAD_TIME_DAYS,
                    self::MAX_LEAD_TIME_DAYS
                ));
            }
            if (mb_strlen($overrideReason) < self::MIN_OVERRIDE_REASON_LENGTH) {
                throw new RuntimeException(sprintf(
                    'Alasan override lead time wajib diisi (minimal %d karakter).',
                    self::MIN_OVERRIDE_REASON_LENGTH
                ));
            }
        } elseif ($overrideReason !== '') {
            throw new RuntimeException('Jumlah hari override lead time wajib diisi bila alasan diisi.');
        }

        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            throw new RuntimeException('Sparepart tidak ditemukan.');
        }
        if ((int) $sparepart['is_active'] !== 1) {
            throw new RuntimeException('Sparepart nonaktif tidak dapat diprediksi.');
        }

        $asOf = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-' . ($historyDays - 1) . ' days'));
        $pdo = Database::pdo();

        // Riwayat barang keluar per hari (agregat kronologis pada rentang pilihan pengguna).
        // Tanggal bisnis memakai konvensi aplikasi: COALESCE(movement_date, DATE(created_at)).
        // PENTING: kuantitas KELUAR tercatat NEGATIF pada ledger → dipakai ABS().
        $stmt = $pdo->prepare(
            'SELECT COALESCE(movement_date, DATE(created_at)) AS d, COALESCE(SUM(ABS(quantity)), 0) AS q
               FROM stock_movements
              WHERE sparepart_id = ? AND movement_type = ?
                AND COALESCE(movement_date, DATE(created_at)) >= ?
              GROUP BY d
              ORDER BY d'
        );
        $stmt->execute([$sparepartId, StockMovement::TYPE_OUT, $start]);
        $dailyOut = [];
        foreach ($stmt->fetchAll() as $row) {
            $qty = (float) $row['q'];
            if ($qty > 0) {
                $dailyOut[] = ['date' => (string) $row['d'], 'qty' => $qty];
            }
        }

        // Jumlah catatan transaksi keluar (dipakai indikator kecukupan data).
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM stock_movements
              WHERE sparepart_id = ? AND movement_type = ?
                AND COALESCE(movement_date, DATE(created_at)) >= ?'
        );
        $stmt->execute([$sparepartId, StockMovement::TYPE_OUT, $start]);
        $txCount = (int) $stmt->fetchColumn();

        // Riwayat penerimaan barang (informatif untuk analisis).
        $stmt = $pdo->prepare(
            'SELECT DATE(r.receipt_date) AS d, COALESCE(SUM(i.quantity), 0) AS q
               FROM goods_receipt_items i
               JOIN goods_receipts r ON r.id = i.receipt_id
              WHERE i.sparepart_id = ? AND r.receipt_date >= ?
              GROUP BY DATE(r.receipt_date)
              ORDER BY d'
        );
        $stmt->execute([$sparepartId, $start]);
        $receipts = [];
        foreach ($stmt->fetchAll() as $row) {
            $qty = (float) $row['q'];
            if ($qty > 0) {
                $receipts[] = ['date' => (string) $row['d'], 'qty' => $qty];
            }
        }

        // Lead time supplier bila tersedia (bila kosong → asumsi berlabel).
        $leadTime = null;
        if (($sparepart['supplier_id'] ?? null) !== null) {
            $stmt = $pdo->prepare('SELECT lead_time_days FROM suppliers WHERE id = ?');
            $stmt->execute([(int) $sparepart['supplier_id']]);
            $lt = $stmt->fetchColumn();
            if ($lt !== false && $lt !== null && (float) $lt > 0) {
                $leadTime = (int) $lt;
            }
        }

        // Lead time NYATA dari riwayat penerimaan pembelian (DIPRIORITASKAN).
        // Diukur dari tanggal PO efektif (disetujui/diproses/dibuat) ke tanggal
        // penerimaan. Hanya PO yang tidak dibatalkan/ditolak.
        $stmt = $pdo->prepare(
            'SELECT DATEDIFF(r.receipt_date, DATE(COALESCE(po.approved_at, po.processed_at, po.created_at))) AS lt
               FROM goods_receipt_items i
               JOIN goods_receipts r ON r.id = i.receipt_id
               JOIN purchase_order_items poi ON poi.id = i.po_item_id
               JOIN purchase_orders po ON po.id = poi.po_id
              WHERE i.sparepart_id = ?
                AND po.status NOT IN (?, ?)
              ORDER BY r.receipt_date DESC, i.id DESC
              LIMIT 20'
        );
        $stmt->execute([$sparepartId, 'DIBATALKAN', 'DITOLAK']);
        $leadTimeReceiptDays = [];
        foreach ($stmt->fetchAll() as $row) {
            $days = (int) $row['lt'];
            if ($days >= 0) {
                $leadTimeReceiptDays[] = $days;
            }
        }

        // Penjagaan nilai master yang tidak valid (MariaDB non-strict).
        $payloadWarnings = [];
        $minStock = (float) $sparepart['min_stock'];
        if ($minStock < 0) {
            $minStock = 0.0;
            $payloadWarnings[] = 'Stok minimum pada master tidak valid (negatif) — diperlakukan 0.';
        }
        $stock = (float) $sparepart['stock'];
        if ($stock < 0) {
            $stock = 0.0;
            $payloadWarnings[] = 'Stok pada master tidak valid (negatif) — diperlakukan 0.';
        }

        return [
            'schema'            => 1,
            'sparepart_id'      => $sparepartId,
            'period_days'       => $periodDays,
            'history_days'      => $historyDays,
            'as_of'             => $asOf,
            'stock'             => $stock,
            'min_stock'         => $minStock,
            'unit'              => (string) $sparepart['unit'],
            'lead_time_days'    => $leadTime,
            'lead_time_receipt_days' => $leadTimeReceiptDays,
            'lead_time_override_days' => $leadTimeOverrideDays,
            'tx_count'          => $txCount,
            'daily_out'         => $dailyOut,
            'receipts'          => $receipts,
            'params'            => self::PARAMS,
            'generated_at'      => date('c'),
            'payload_warnings'  => $payloadWarnings,
        ];
    }

    // ==================================================================
    // Proses lokal — proc_open argumen array, JSON stdin/stdout
    // ==================================================================

    /**
     * Jalankan pipeline Python sebagai proses CLI lokal.
     *
     * @param array<string,mixed> $payload
     * @return array{ok: bool, raw: string, error: ?string}
     */
    public static function invokePython(
        array $payload,
        ?string $scriptPath = null,
        ?float $timeoutSeconds = null
    ): array {
        $script = $scriptPath ?? (string) App::config('cli.predict_script', BASE_PATH . '/python/prediction/forecast.py');
        if ($script === '' || !is_file($script)) {
            return ['ok' => false, 'raw' => '', 'error' => 'Skrip prediksi tidak ditemukan.'];
        }

        $python = trim((string) App::config('cli.python', 'py'));
        if ($python === '') {
            return ['ok' => false, 'raw' => '', 'error' => 'Interpreter Python belum dikonfigurasi.'];
        }
        $argString = trim((string) App::config('cli.python_args', '-3.12'));
        $pythonArgs = $argString === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(' ', $argString)), static fn (string $a): bool => $a !== ''));

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['ok' => false, 'raw' => '', 'error' => 'Payload prediksi gagal disandikan.'];
        }
        if (strlen($encoded) > self::MAX_INPUT_BYTES) {
            return ['ok' => false, 'raw' => '', 'error' => 'Payload prediksi melebihi batas ukuran.'];
        }

        $timeout = $timeoutSeconds ?? self::TIMEOUT_SECONDS;
        $command = array_merge([$python], $pythonArgs, [$script]);
        // Output dialihkan ke BERKAS sementara, bukan pipe: `stream_set_blocking()`
        // dan `stream_select()` TIDAK andal untuk pipe di Windows (baca tetap
        // menunggu EOF dan mengabaikan timeout — sudah terbukti lewat uji stub).
        // Dengan berkas, PHP memeriksa ukuran & status proses tanpa memblokir.
        $outFile = tempnam(sys_get_temp_dir(), 'spm_pred_out_');
        $errFile = tempnam(sys_get_temp_dir(), 'spm_pred_err_');
        if ($outFile === false || $errFile === false) {
            return ['ok' => false, 'raw' => '', 'error' => 'Berkas sementara prediksi tidak dapat dibuat.'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);
            return ['ok' => false, 'raw' => '', 'error' => 'Proses Python tidak dapat dijalankan.'];
        }

        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        $tooLarge = false;

        // 1) Tulis payload ke stdin (pipe bisa penuh → ulangi sampai selesai).
        stream_set_blocking($pipes[0], false);
        $total = strlen($encoded);
        $offset = 0;
        while ($offset < $total) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            $chunk = @fwrite($pipes[0], substr($encoded, $offset, 65536));
            if ($chunk === false) {
                break;
            }
            if ($chunk === 0) {
                usleep(5000);
                continue;
            }
            $offset += $chunk;
        }
        @fclose($pipes[0]);

        // 2) Tunggu proses selesai / batas waktu tanpa membaca pipe.
        while (!$timedOut) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            clearstatcache(true, $outFile);
            clearstatcache(true, $errFile);
            if (@filesize($outFile) > self::MAX_OUTPUT_BYTES || @filesize($errFile) > self::MAX_OUTPUT_BYTES) {
                $tooLarge = true;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(10000); // 10 ms
        }

        // 3) Hentikan proses (timeout / output membeludak) BESERTA anaknya.
        if ($timedOut || $tooLarge) {
            self::terminateProcess($process);
        }
        @proc_close($process);

        // 4) Baca output & error dari berkas (selalu dapat dibaca PHP).
        $stdout = (string) @file_get_contents($outFile);
        $stderr = (string) @file_get_contents($errFile);
        @unlink($outFile);
        @unlink($errFile);

        if ($timedOut) {
            return [
                'ok'    => false,
                'raw'   => '',
                'error' => sprintf('Proses prediksi melebihi batas waktu %.0f detik.', $timeout),
            ];
        }
        if ($tooLarge) {
            return ['ok' => false, 'raw' => '', 'error' => 'Output prediksi melebihi batas ukuran.'];
        }
        if ($stdout === '') {
            $detail = trim((string) preg_replace('/\s+/', ' ', $stderr));
            return [
                'ok'    => false,
                'raw'   => '',
                'error' => 'Proses prediksi tidak menghasilkan output.'
                    . ($detail !== '' ? ' ' . mb_substr($detail, -300) : ''),
            ];
        }

        return ['ok' => true, 'raw' => $stdout, 'error' => null];
    }

    // ==================================================================
    // Validasi output & penyimpanan
    // ==================================================================

    /**
     * Validasi ketat output pipeline; melempar bila skema/nilai tidak sah.
     *
     * @param array<string,mixed> $out
     * @return array<string,mixed> nilai ternormalisasi untuk penyimpanan
     * @throws RuntimeException
     */
    public static function validateOutput(array $out, int $periodDays): array
    {
        if (($out['status'] ?? '') !== 'ok') {
            throw new RuntimeException(
                'Pipeline melaporkan kegagalan: '
                . mb_substr((string) ($out['error'] ?? 'tanpa keterangan'), 0, 300)
            );
        }
        if ((int) ($out['period_days'] ?? -1) !== $periodDays) {
            throw new RuntimeException('Periode output tidak sesuai permintaan.');
        }

        $sufficiency = (string) ($out['data_sufficiency'] ?? '');
        if (!in_array($sufficiency, self::SUFFICIENCY, true)) {
            throw new RuntimeException('Indikator kecukupan data tidak dikenal.');
        }

        $risk = $out['stockout_risk'] ?? null;
        if ($risk !== null && !in_array((string) $risk, self::RISKS, true)) {
            throw new RuntimeException('Risiko stockout tidak dikenal.');
        }

        $comparison = is_array($out['comparison'] ?? null) ? $out['comparison'] : null;
        $selected = $comparison['selected_method'] ?? null;
        if ($selected !== null && !in_array((string) $selected, self::SELECTED_METHODS, true)) {
            throw new RuntimeException('Metode prediksi terpilih tidak dikenal.');
        }
        $method = $selected !== null ? (string) $selected : 'belum_dihitung';

        $leadTimeSource = (string) ($out['lead_time_source'] ?? 'assumption');
        if (!in_array($leadTimeSource, self::LEAD_TIME_SOURCES, true)) {
            $leadTimeSource = 'assumption';
        }

        $modelVersion = trim((string) ($out['model_version'] ?? ''));
        if ($modelVersion === '') {
            $modelVersion = 'tanpa-versi';
        }

        $historyRange = is_array($out['history_range'] ?? null) ? $out['history_range'] : [];

        return [
            'period'            => $periodDays,
            'method'            => $method,
            'data_sufficiency'  => $sufficiency,
            'reason'            => mb_substr((string) ($out['data_sufficiency_reason'] ?? ''), 0, 500),
            'risk'              => $risk !== null ? (string) $risk : null,
            'demand'            => self::boundedFloat($out, 'predicted_demand', 0.0, 1_000_000.0),
            'daily_rate'        => self::boundedFloat($out, 'daily_rate', 0.0, 1_000_000.0),
            'days_to_min'       => self::boundedInt($out, 'days_to_min', 0, 36500),
            'days_to_zero'      => self::boundedInt($out, 'days_to_zero', 0, 36500),
            'recommended'       => self::boundedFloat($out, 'recommended_qty', 0.0, 1_000_000.0),
            'safety_stock'      => self::boundedFloat($out, 'safety_stock', 0.0, 1_000_000_000.0),
            'reorder_point'     => self::boundedFloat($out, 'reorder_point', 0.0, 1_000_000_000.0),
            'lead_time_days'    => self::boundedInt($out, 'lead_time_days', 1, 3650),
            'lead_time_assumed' => (bool) ($out['lead_time_assumed'] ?? false),
            'lead_time_source'  => $leadTimeSource,
            'lead_time_samples' => self::boundedInt($out, 'lead_time_samples', 0, 1000),
            'lead_time_reliable' => !((bool) ($out['lead_time_assumed'] ?? false)),
            'warnings'          => self::boundedWarnings($out['warnings'] ?? null),
            'model_version'     => mb_substr($modelVersion, 0, 30),
            'generated_at'      => mb_substr((string) ($out['generated_at'] ?? ''), 0, 40),
            'history_range'     => $historyRange,
            'methods'           => is_array($out['methods'] ?? null) ? $out['methods'] : null,
            'evaluation'        => is_array($out['evaluation'] ?? null) ? $out['evaluation'] : null,
            'comparison'        => $comparison,
            'parameters'        => is_array($out['parameters'] ?? null) ? $out['parameters'] : null,
            'library_versions'  => is_array($out['library_versions'] ?? null) ? $out['library_versions'] : null,
            'python_version'    => mb_substr((string) ($out['python_version'] ?? ''), 0, 30),
            'receipt_summary'   => is_array($out['receipt_summary'] ?? null) ? $out['receipt_summary'] : null,
        ];
    }

    /**
     * Jalankan analisis penuh: payload → proses Python → validasi → simpan.
     *
     * Kegagalan pipeline TIDAK melempar — mengembalikan status "error"
     * beserta fallback saran aturan stok-minimum.
     *
     * @return array<string,mixed>
     */
    public static function run(
        int $sparepartId,
        int $periodDays,
        int $historyDays,
        ?string $scriptPath = null,
        ?float $timeoutSeconds = null,
        ?int $leadTimeOverrideDays = null,
        ?string $leadTimeReason = null
    ): array {
        $payload = self::buildPayload(
            $sparepartId,
            $periodDays,
            $historyDays,
            $leadTimeOverrideDays,
            $leadTimeReason
        );

        $overrideReason = trim((string) $leadTimeReason);
        $autoLeadTime = self::autoLeadTime($payload);

        $invocation = self::invokePython($payload, $scriptPath, $timeoutSeconds);
        if (!$invocation['ok']) {
            return self::failure($sparepartId, (string) $invocation['error']);
        }

        $out = json_decode($invocation['raw'], true);
        if (!is_array($out)) {
            return self::failure($sparepartId, 'Output pipeline bukan JSON valid.');
        }

        try {
            $norm = self::validateOutput($out, $periodDays);
        } catch (RuntimeException $e) {
            return self::failure($sparepartId, $e->getMessage());
        }

        $classification = self::classification($sparepartId);
        $warnings = array_merge(
            array_values($payload['payload_warnings']),
            $norm['warnings']
        );

        // Lead time: PHP bersifat OTORITATIF untuk override manual, sehingga
        // hasil tetap konsisten walau pipeline (versi lama) mengabaikan field.
        $leadTimeSource = $norm['lead_time_source'];
        $leadTimeDays = $norm['lead_time_days'];
        $leadTimeAssumed = $norm['lead_time_assumed'];
        $leadTimeReliable = !$leadTimeAssumed;
        if ($leadTimeOverrideDays !== null) {
            $leadTimeSource = 'manual_override';
            $leadTimeDays = $leadTimeOverrideDays;
            $leadTimeAssumed = false;
            $leadTimeReliable = true;
        }
        $leadTimeDetails = [
            'days'     => $leadTimeDays,
            'assumed'  => $leadTimeAssumed,
            'source'   => $leadTimeSource,
            'samples'  => $norm['lead_time_samples'],
            'reliable' => $leadTimeReliable,
        ];
        if ($leadTimeOverrideDays !== null) {
            $leadTimeDetails['override'] = true;
            $leadTimeDetails['reason'] = $overrideReason;
            $leadTimeDetails['auto_days'] = $autoLeadTime['days'];
            $leadTimeDetails['auto_source'] = $autoLeadTime['source'];
        }

        $details = [
            'schema'           => 1,
            'reason'           => $norm['reason'],
            'daily_rate'       => $norm['daily_rate'],
            'safety_stock'     => $norm['safety_stock'],
            'reorder_point'    => $norm['reorder_point'],
            'lead_time'        => $leadTimeDetails,
            'methods'          => $norm['methods'],
            'evaluation'       => $norm['evaluation'],
            'comparison'       => $norm['comparison'],
            'parameters'       => $norm['parameters'],
            'library_versions' => $norm['library_versions'],
            'python_version'   => $norm['python_version'],
            'receipt_summary'  => $norm['receipt_summary'],
            'classification'   => $classification,
            'analysis'         => [
                'generated_at'  => $norm['generated_at'],
                'as_of'         => $payload['as_of'],
                'history_days'  => $payload['history_days'],
                'tx_count'      => $payload['tx_count'],
                'history_range' => $norm['history_range'],
            ],
            'integration'      => [
                'method'     => 'cli_proc_open',
                'script'     => 'python/prediction/forecast.py',
                'timeout_s'  => self::TIMEOUT_SECONDS,
                'endpoint'   => 'lokal-only (tanpa layanan jaringan)',
            ],
            'warnings'         => $warnings,
        ];
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($detailsJson === false) {
            return self::failure($sparepartId, 'Details prediksi gagal disandikan.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO ai_predictions
                (sparepart_id, period_days, history_range_start, history_range_end, method,
                 predicted_demand, forecast_days_to_min, forecast_days_to_zero, stockout_risk,
                 recommended_qty, moving_classification, data_sufficiency, warnings,
                 model_version, details, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $rangeStart = isset($norm['history_range']['start']) ? (string) $norm['history_range']['start'] : null;
        $rangeEnd = isset($norm['history_range']['end']) ? (string) $norm['history_range']['end'] : null;
        $stmt->execute([
            $sparepartId,
            $periodDays,
            $rangeStart,
            $rangeEnd,
            $norm['method'],
            $norm['demand'],
            $norm['days_to_min'],
            $norm['days_to_zero'],
            $norm['risk'],
            $norm['recommended'],
            $classification['class'],
            $norm['data_sufficiency'],
            $warnings === [] ? null : implode("\n", array_slice($warnings, 0, 20)),
            $norm['model_version'],
            $detailsJson,
        ]);
        $predictionId = (int) $pdo->lastInsertId();

        $sparepart = Sparepart::find($sparepartId);
        $code = (string) ($sparepart['code'] ?? '#' . $sparepartId);
        AuditService::log(
            'PREDIKSI_DIBUAT',
            'ai_predictions',
            (string) $predictionId,
            sprintf(
                'Prediksi %d hari untuk %s (%s): estimasi %s, risiko %s, saran reorder %s — %s.',
                $periodDays,
                $code,
                self::methodLabel($norm['method']),
                $norm['demand'] !== null ? StockLedger::formatQty($norm['demand']) : '-',
                $norm['risk'] ?? 'belum dinilai',
                $norm['recommended'] !== null ? StockLedger::formatQty($norm['recommended']) : '-',
                $norm['data_sufficiency']
            ),
            null,
            ['prediction_id' => $predictionId, 'period_days' => $periodDays]
        );

        // C1.4/C1.5 — override lead time manual WAJIB beralasan & dicatat audit.
        if ($leadTimeOverrideDays !== null) {
            AuditService::log(
                'LEAD_TIME_OVERRIDE',
                'ai_predictions',
                (string) $predictionId,
                sprintf(
                    'Lead time %s di-override manual menjadi %d hari (otomatis: %d hari via %s). Alasan: %s',
                    $code,
                    $leadTimeOverrideDays,
                    $autoLeadTime['days'],
                    self::leadTimeSourceLabel($autoLeadTime['source']),
                    $overrideReason
                ),
                ['days' => $autoLeadTime['days'], 'source' => $autoLeadTime['source']],
                ['days' => $leadTimeOverrideDays, 'reason' => $overrideReason]
            );
        }

        return [
            'status'        => 'ok',
            'prediction_id' => $predictionId,
            'norm'          => $norm,
            'classification' => $classification['class'],
        ];
    }

    // ==================================================================
    // Klasifikasi fast/slow moving (mengikuti ambang pengaturan laporan)
    // ==================================================================

    /**
     * Kategori pergerakan memakai ambang & periode yang sama dengan
     * laporan fast/slow moving (Admin → Pengaturan), agar tidak ada
     * dua definisi berbeda dalam aplikasi.
     *
     * @return array{class: string, period_days: int, fast_threshold: int, slow_threshold: int, qty_out: float}
     */
    public static function classification(int $sparepartId): array
    {
        $config = SettingService::reportConfig();
        $start = date('Y-m-d', strtotime('-' . ($config['period_days'] - 1) . ' days'));
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(ABS(quantity)), 0) FROM stock_movements
              WHERE sparepart_id = ? AND movement_type = ?
                AND COALESCE(movement_date, DATE(created_at)) >= ?'
        );
        $stmt->execute([$sparepartId, StockMovement::TYPE_OUT, $start]);
        $used = (float) $stmt->fetchColumn();

        $class = $used >= $config['fast_threshold']
            ? 'fast'
            : ($used <= $config['slow_threshold'] ? 'slow' : 'medium');

        return [
            'class'          => $class,
            'period_days'    => $config['period_days'],
            'fast_threshold' => $config['fast_threshold'],
            'slow_threshold' => $config['slow_threshold'],
            'qty_out'        => $used,
        ];
    }

    /** Label metode untuk tampilan. */
    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'moving_average'    => 'Moving Average',
            'linear_regression' => 'Linear Regression',
            default             => 'Belum dihitung',
        };
    }

    /** Label sumber lead time untuk tampilan/audit. */
    public static function leadTimeSourceLabel(string $source): string
    {
        return match ($source) {
            'receipt_history' => 'riwayat penerimaan PO',
            'supplier_master' => 'master supplier',
            'manual_override' => 'override manual Partman',
            default           => 'asumsi',
        };
    }

    /**
     * Lead time otomatis (tanpa override) menurut prioritas C1:
     * median riwayat penerimaan PO → master supplier → asumsi 7 hari.
     *
     * Dipakai untuk mencatat nilai "lama" saat Partman melakukan override
     * manual pada audit log. Logika ini mencerminkan urutan di forecast.py.
     *
     * @param array<string,mixed> $payload
     * @return array{days:int, source:string, samples:int}
     */
    public static function autoLeadTime(array $payload): array
    {
        $samples = [];
        foreach ($payload['lead_time_receipt_days'] ?? [] as $value) {
            $days = (int) $value;
            if ($days > 0) {
                $samples[] = $days;
            }
        }
        if ($samples !== []) {
            sort($samples, SORT_NUMERIC);
            $count = count($samples);
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1
                ? (float) $samples[$middle]
                : ((float) $samples[$middle - 1] + (float) $samples[$middle]) / 2.0;
            return [
                'days'    => max(1, (int) $median),
                'source'  => 'receipt_history',
                'samples' => $count,
            ];
        }

        $master = $payload['lead_time_days'] ?? null;
        if ($master !== null && (int) $master > 0) {
            return ['days' => (int) $master, 'source' => 'supplier_master', 'samples' => 0];
        }

        $fallback = (int) ($payload['params']['lead_time_fallback_days'] ?? self::PARAMS['lead_time_fallback_days']);
        return ['days' => max(1, $fallback), 'source' => 'assumption', 'samples' => 0];
    }

    // ==================================================================
    // Pembantu internal
    // ==================================================================

    /**
     * Kegagalan pipeline: catat audit + kembalikan fallback saran aturan.
     * Tidak melempar — jalur transaksi inventory tidak pernah terhambat.
     *
     * @return array<string,mixed>
     */
    private static function failure(int $sparepartId, string $reason): array
    {
        $reason = trim((string) preg_replace('/\s+/', ' ', $reason));
        $reason = mb_substr($reason !== '' ? $reason : 'galat tanpa keterangan', 0, 300);

        AuditService::log(
            'PREDIKSI_GAGAL',
            'spareparts',
            (string) $sparepartId,
            'Prediksi stok gagal: ' . $reason
        );

        return [
            'status'   => 'error',
            'message'  => $reason,
            'fallback' => ReorderRequestService::suggestion($sparepartId),
        ];
    }

    /**
     * Hentikan proses Python beserta ANAK prosesnya.
     *
     * Di Windows `proc_terminate()` hanya menghentikan launcher (`py.exe`),
     * sehingga `python.exe` dapat tertinggal; `taskkill /F /T` menutup
     * seluruh pohon proses. Di sistem lain cukup `proc_terminate()`.
     */
    private static function terminateProcess($process): void
    {
        $status = @proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);

        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
            $pipes = [];
            $killer = @proc_open(
                ['taskkill', '/F', '/T', '/PID', (string) $pid],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($killer)) {
                foreach ([1, 2] as $index) {
                    if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                        @stream_set_blocking($pipes[$index], false);
                        @stream_get_contents($pipes[$index]);
                        @fclose($pipes[$index]);
                    }
                }
                @proc_close($killer);
            }
        }

        @proc_terminate($process);
    }

    /** Nilai float dari output Python: null atau dalam batas; di luar batas → RuntimeException. */
    private static function boundedFloat(array $out, string $key, float $min, float $max): ?float
    {
        $value = $out[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $number = is_numeric($value) ? (float) $value : NAN;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new RuntimeException("Nilai {$key} di luar batas wajar.");
        }
        return round($number, 4);
    }

    /** Nilai int dari output Python: null atau dalam batas; di luar batas → RuntimeException. */
    private static function boundedInt(array $out, string $key, int $min, int $max): ?int
    {
        $value = $out[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new RuntimeException("Nilai {$key} di luar batas wajar.");
        }
        return (int) round((float) $value);
    }

    /** Normalisasi daftar peringatan: maksimal 20 butir, 500 karakter tiap butir. */
    private static function boundedWarnings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $warnings = [];
        foreach (array_slice($value, 0, 20) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $warnings[] = mb_substr(trim($item), 0, 500);
            }
        }
        return $warnings;
    }
}
