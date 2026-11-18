<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AiPrediction;
use Throwable;

/**
 * Otomatisasi prediksi stok (revisi Fase 7).
 *
 * Prinsip:
 *  - ALGORITMA TIDAK BERUBAH: seluruh perhitungan tetap di PredictionService
 *    dan python/prediction/forecast.py. Kelas ini hanya menjadwalkan,
 *    mengantrekan, dan mencatat status eksekusi.
 *  - Prediksi adalah turunan data persediaan. Setiap perubahan stok menandai
 *    sparepart terkait sebagai "kotor" (pending) SETELAH transaksi commit.
 *  - Hasil disimpan pada tabel ai_predictions yang sudah ada (snapshot),
 *    sehingga dashboard semua role membaca sumber data yang sama dan tidak
 *    memanggil Python berulang-ulang.
 *  - Lock berkas (flock) menjamin hanya satu proses prediksi berjalan pada
 *    satu waktu per instalasi/database. Bila lock sibuk, pekerjaan ditunda.
 *  - Kegagalan Python tidak menghapus hasil prediksi terakhir; status
 *    kegagalan dan waktu pembaruan terakhir dicatat untuk ditampilkan.
 */
final class PredictionAutomation
{
    /** Prediksi lebih tua dari ini dianggap perlu penyegaran berkala (menit). */
    public const STALE_MINUTES = 30;

    /** Jeda minimum antar-penyegaran dari halaman dashboard (detik). */
    public const REFRESH_MIN_INTERVAL_SECONDS = 60;

    /** Batas jumlah sparepart yang diproses dalam satu eksekusi. */
    public const MAX_PER_RUN = 15;

    /** Anggaran waktu satu eksekusi (detik) agar request tidak menggantung. */
    public const TIME_BUDGET_SECONDS = 20.0;

    /** Periode & riwayat tetap memakai bawaan modul prediksi (tidak diubah). */
    public const RUN_PERIOD = PredictionService::DEFAULT_PERIOD;
    public const RUN_HISTORY = PredictionService::DEFAULT_HISTORY;

    /** Status publik: never | ok | partial | error | busy | idle */
    private static bool $deferredRegistered = false;
    private static bool $immediateRefreshForTesting = false;
    private static $lockHandle = null;

    // ==================================================================
    // Antrean & status
    // ==================================================================

    /**
     * Tandai sparepart perlu dianalisis ulang.
     *
     * @param array<int,int|string> $sparepartIds
     */
    public static function markDirty(array $sparepartIds, string $reason = 'perubahan data'): void
    {
        $ids = [];
        foreach ($sparepartIds as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $ids[$value] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        $now = date('c');
        self::updateState(static function (array $state) use ($ids, $now, $reason): array {
            foreach (array_keys($ids) as $id) {
                $state['pending'][(string) $id] = $now;
            }
            $state['dirty_reason'] = mb_substr($reason, 0, 120);
            return $state;
        });

        if (self::$immediateRefreshForTesting) {
            // Hanya untuk pengujian otomatis (mensimulasikan eksekusi nyata).
            self::refreshPending();
            return;
        }

        if (PHP_SAPI !== 'cli') {
            self::deferUntilAfterResponse();
        }
    }

    /**
     * Jalankan pemrosesan antrean setelah respons terkirim (web).
     * Dashboard tetap cepat karena Python tidak dijalankan di jalur render.
     */
    public static function deferUntilAfterResponse(): void
    {
        if (self::$deferredRegistered) {
            return;
        }
        self::$deferredRegistered = true;

        register_shutdown_function(static function (): void {
            try {
                self::finishResponse();
                self::refreshPending();
            } catch (Throwable $e) {
                self::recordFailure($e->getMessage());
            }
        });
    }

    /**
     * Dipanggil saat dashboard dibuka. Tidak memblokir render:
     *  - bila ada antrean, jadwalkan pemrosesan setelah respons;
     *  - bila belum pernah berjalan / sudah kedaluwarsa, tandai seluruh
     *    sparepart aktif agar disegarkan (sekali, bukan per role).
     */
    public static function ensureFresh(): void
    {
        if (PHP_SAPI === 'cli') {
            return; // pengujian CLI tidak memanggil Python otomatis
        }

        $state = self::state();
        $lastRun = $state['last_run_at'] ?? null;
        $recentlyRun = $lastRun !== null
            && (time() - (int) strtotime((string) $lastRun)) < self::REFRESH_MIN_INTERVAL_SECONDS;

        if (($state['pending_count'] ?? 0) > 0) {
            // Jangan memproses ulang terus-menerus pada setiap refresh halaman;
            // antrean sisa diproses pada kesempatan berikutnya (atau penjadwal).
            if (!$recentlyRun) {
                self::deferUntilAfterResponse();
            }
            return;
        }

        $stale = $lastRun === null
            || (time() - (int) strtotime((string) $lastRun)) >= self::STALE_MINUTES * 60;
        if (!$stale) {
            return;
        }

        $ids = self::activeSparepartIds();
        if ($ids !== []) {
            self::markDirty($ids, 'penyegaran berkala');
            self::deferUntilAfterResponse();
        }
    }

    /**
     * Proses antrean (atau daftar ID tertentu) dengan lock tunggal.
     *
     * @param array<int,int>|null $ids null = ambil dari antrean
     * @return array{status:string,processed:int,failed:int,message:string}
     */
    public static function refreshPending(
        ?array $ids = null,
        int $max = self::MAX_PER_RUN,
        ?string $scriptPath = null,
        float $timeBudgetSeconds = self::TIME_BUDGET_SECONDS
    ): array {
        $lock = self::acquireLock();
        if ($lock === null) {
            return [
                'status'    => 'busy',
                'processed' => 0,
                'failed'    => 0,
                'message'   => 'Proses prediksi lain sedang berjalan.',
            ];
        }

        $runStartedAt = date('c');
        $deadline = microtime(true) + max(1.0, $timeBudgetSeconds);

        try {
            $state = self::state();
            $pending = $state['pending'] ?? [];

            if ($ids === null) {
                $candidates = array_map('intval', array_keys($pending));
            } else {
                $candidates = array_values(array_unique(array_filter(
                    array_map('intval', $ids),
                    static fn (int $id): bool => $id > 0
                )));
            }

            $activeAll = self::filterActive($candidates);
            $active = array_slice($activeAll, 0, max(1, $max));

            // Sparepart yang sudah tidak aktif tidak boleh menghasilkan
            // rekomendasi baru; keluarkan dari antrean agar tidak menumpuk.
            $inactive = array_values(array_diff($candidates, $activeAll));
            if ($inactive !== []) {
                self::updateState(static function (array $state) use ($inactive): array {
                    foreach ($inactive as $id) {
                        unset($state['pending'][(string) $id]);
                    }
                    return $state;
                });
            }

            if ($active === []) {
                return [
                    'status'    => 'idle',
                    'processed' => 0,
                    'failed'    => 0,
                    'message'   => 'Tidak ada sparepart aktif yang perlu diprediksi.',
                ];
            }

            $processed = 0;
            $failed = 0;
            $lastError = '';
            $attempted = [];

            foreach ($active as $sparepartId) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $attempted[] = $sparepartId;
                try {
                    $result = PredictionService::run(
                        $sparepartId,
                        self::RUN_PERIOD,
                        self::RUN_HISTORY,
                        $scriptPath
                    );
                    if (($result['status'] ?? '') === 'ok') {
                        $processed++;
                    } else {
                        $failed++;
                        $lastError = (string) ($result['message'] ?? 'Prediksi gagal.');
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $lastError = $e->getMessage();
                }
            }

            // Hapus dari antrean HANYA penanda yang lebih lama dari awal proses.
            // Penanda baru (transaksi lain selama proses berjalan) tetap disimpan.
            self::updateState(static function (array $state) use ($attempted, $runStartedAt, $processed, $failed, $lastError): array {
                foreach ($attempted as $id) {
                    $key = (string) $id;
                    if (!isset($state['pending'][$key])) {
                        continue;
                    }
                    $markedAt = (string) $state['pending'][$key];
                    if ($markedAt <= $runStartedAt) {
                        unset($state['pending'][$key]);
                    }
                }
                $now = date('c');
                $state['last_run_at'] = $now;
                $state['last_processed'] = $processed;
                $state['last_failed'] = $failed;
                if ($processed > 0) {
                    $state['last_ok_at'] = $now;
                }
                if ($failed === 0) {
                    $state['last_status'] = 'ok';
                    $state['last_error'] = null;
                } elseif ($processed > 0) {
                    $state['last_status'] = 'partial';
                    $state['last_error'] = mb_substr(trim($lastError), 0, 200);
                } else {
                    $state['last_status'] = 'error';
                    $state['last_error'] = mb_substr(trim($lastError), 0, 200);
                }
                return $state;
            });

            $status = $failed === 0 ? 'ok' : ($processed > 0 ? 'partial' : 'error');
            return [
                'status'    => $status,
                'processed' => $processed,
                'failed'    => $failed,
                'message'   => sprintf('%d prediksi diperbarui, %d gagal.', $processed, $failed),
            ];
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Segarkan seluruh sparepart aktif (dipakai penjadwal berkala).
     *
     * @return array{status:string,processed:int,failed:int,message:string}
     */
    public static function refreshAllActive(int $max = 200, ?string $scriptPath = null): array
    {
        $ids = self::activeSparepartIds();
        if ($ids === []) {
            return [
                'status'    => 'idle',
                'processed' => 0,
                'failed'    => 0,
                'message'   => 'Tidak ada sparepart aktif.',
            ];
        }
        return self::refreshPending($ids, $max, $scriptPath);
    }

    /**
     * Ringkasan untuk dashboard (SEMUA role memakai struktur yang sama).
     *
     * @return array{
     *   rows: array<int,array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   period_days: int,
     *   automation: array<string,mixed>
     * }
     */
    public static function insight(int $limit = 10): array
    {
        $all = AiPrediction::dashboardRows();
        $state = self::state();

        $rows = [];
        $predicted = 0;
        $attention = 0;
        $belowRop = 0;
        $latestAt = null;

        foreach ($all as $row) {
            $built = self::buildRow($row);
            if ($built['has_prediction']) {
                $predicted++;
                if ($built['needs_attention']) {
                    $attention++;
                }
                if ($built['below_rop']) {
                    $belowRop++;
                }
                if ($built['processed_at'] !== null
                    && ($latestAt === null || strtotime((string) $built['processed_at']) > strtotime((string) $latestAt))) {
                    $latestAt = $built['processed_at'];
                }
            }
            $rows[] = $built;
        }

        // Urutkan: yang paling perlu perhatian lebih dahulu, lalu kode.
        usort($rows, static function (array $a, array $b): int {
            $rank = $b['severity'] <=> $a['severity'];
            return $rank !== 0 ? $rank : strcmp((string) $a['code'], (string) $b['code']);
        });

        $rows = array_slice($rows, 0, max(1, min(50, $limit)));

        return [
            'rows'       => $rows,
            'period_days' => self::RUN_PERIOD,
            'summary'    => [
                'predicted'   => $predicted,
                'attention'   => $attention,
                'below_rop'   => $belowRop,
                'latest_at'   => $latestAt,
                'last_status' => (string) ($state['last_status'] ?? 'never'),
                'last_ok_at'  => $state['last_ok_at'] ?? null,
                'last_error'  => $state['last_error'] ?? null,
                'pending'     => (int) ($state['pending_count'] ?? 0),
            ],
            'automation' => [
                'enabled'    => true,
                'stale_minutes' => self::STALE_MINUTES,
                'last_run_at'   => $state['last_run_at'] ?? null,
                'last_status'   => (string) ($state['last_status'] ?? 'never'),
            ],
        ];
    }

    /** Status ringkas untuk halaman Prediksi Stok. @return array<string,mixed> */
    public static function state(): array
    {
        $raw = self::readState();
        $pending = is_array($raw['pending'] ?? null) ? $raw['pending'] : [];

        return [
            'pending'        => $pending,
            'pending_count'  => count($pending),
            'last_run_at'    => $raw['last_run_at'] ?? null,
            'last_ok_at'     => $raw['last_ok_at'] ?? null,
            'last_status'    => (string) ($raw['last_status'] ?? 'never'),
            'last_processed' => (int) ($raw['last_processed'] ?? 0),
            'last_failed'    => (int) ($raw['last_failed'] ?? 0),
            'last_error'     => $raw['last_error'] ?? null,
            'dirty_reason'   => $raw['dirty_reason'] ?? null,
        ];
    }

    /** Hanya untuk pengujian: jalankan prediksi segera setelah penandaan. */
    public static function setImmediateRefreshForTesting(?bool $enabled): void
    {
        self::$immediateRefreshForTesting = (bool) $enabled;
    }

    /** Hapus antrean (khusus pengujian agar idempoten). */
    public static function clearQueueForTesting(): void
    {
        self::updateState(static function (array $state): array {
            $state['pending'] = [];
            return $state;
        });
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array<string,mixed> $row */
    private static function buildRow(array $row): array
    {
        $details = AiPrediction::details($row);
        $methods = is_array($details['methods'] ?? null) ? $details['methods'] : [];
        $ma = $methods['moving_average']['forecast'] ?? null;
        $lr = $methods['linear_regression']['forecast'] ?? null;
        $safety = self::numberOrNull($details['safety_stock'] ?? null);
        $rop = self::numberOrNull($details['reorder_point'] ?? null);
        $stock = (float) ($row['stock'] ?? 0);
        $minStock = (float) ($row['min_stock'] ?? 0);
        $hasPrediction = isset($row['prediction_id']) && $row['prediction_id'] !== null;
        $sufficiency = (string) ($row['data_sufficiency'] ?? '');
        $risk = $row['stockout_risk'] ?? null;

        $status = 'Belum ada prediksi';
        $action = 'Menunggu prediksi otomatis';
        $severity = 0;
        $needsAttention = false;
        $belowRop = false;

        if ($hasPrediction) {
            if ($sufficiency === 'tidak_cukup') {
                $status = 'Data historis belum mencukupi';
                $action = 'Perbanyak riwayat transaksi keluar';
                $severity = 1;
            } elseif ($stock <= 0) {
                $status = 'Stok habis';
                $action = 'Stok berada di bawah ROP — segera proses pengadaan';
                $severity = 5;
                $needsAttention = true;
                $belowRop = $rop === null || $stock < $rop;
            } elseif ($rop !== null && $stock < $rop) {
                $status = 'Stok berada di bawah ROP';
                $action = 'Segera evaluasi pemesanan';
                $severity = 4;
                $needsAttention = true;
                $belowRop = true;
            } elseif ($stock <= $minStock) {
                $status = 'Disarankan evaluasi pemesanan';
                $action = 'Stok menyentuh batas minimum — siapkan reorder';
                $severity = 3;
                $needsAttention = true;
            } elseif ($risk === 'tinggi' || $risk === 'sedang') {
                $status = 'Perlu perhatian';
                $action = 'Pantau pergerakan stok dan permintaan';
                $severity = 2;
                $needsAttention = true;
            } else {
                $status = 'Stok masih aman';
                $action = 'Tidak perlu tindakan mendesak';
                $severity = 0;
            }
        }

        $stale = false;
        if ($hasPrediction
            && ($row['processed_at'] ?? null) !== null
            && ($row['last_movement_at'] ?? null) !== null) {
            $stale = strtotime((string) $row['last_movement_at']) > strtotime((string) $row['processed_at']);
        }

        return [
            'sparepart_id'   => (int) ($row['sparepart_id'] ?? $row['id'] ?? 0),
            'code'           => (string) ($row['code'] ?? ''),
            'name'           => (string) ($row['name'] ?? ''),
            'unit'           => (string) ($row['unit'] ?? ''),
            'stock'          => $stock,
            'min_stock'      => $minStock,
            'has_prediction' => $hasPrediction,
            'prediction_id'  => $hasPrediction ? (int) $row['prediction_id'] : null,
            'period_days'    => (int) ($row['period_days'] ?? self::RUN_PERIOD),
            'method'         => (string) ($row['method'] ?? 'belum_dihitung'),
            'moving_average' => self::numberOrNull($ma),
            'linear_regression' => self::numberOrNull($lr),
            'predicted_demand'  => self::numberOrNull($row['predicted_demand'] ?? null),
            'recommended_qty'   => self::numberOrNull($row['recommended_qty'] ?? null),
            'safety_stock'   => $safety,
            'reorder_point'  => $rop,
            'risk'           => $risk !== null ? (string) $risk : null,
            'sufficiency'    => $sufficiency,
            'status'         => $status,
            'action'         => $action,
            'severity'       => $severity,
            'needs_attention' => $needsAttention,
            'below_rop'      => $belowRop,
            'stale'          => $stale,
            'processed_at'   => $row['processed_at'] ?? null,
        ];
    }

    private static function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /** @return array<int,int> */
    private static function activeSparepartIds(): array
    {
        $rows = Database::pdo()
            ->query('SELECT id FROM spareparts WHERE is_active = 1 ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('intval', $rows));
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private static function filterActive(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM spareparts WHERE is_active = 1 AND id IN ({$placeholders}) ORDER BY id"
        );
        $stmt->execute($ids);
        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private static function cacheDir(): string
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function stateFile(): string
    {
        $name = (string) (Database::config()['name'] ?? 'default');
        return self::cacheDir() . '/prediction_auto_' . substr(sha1($name), 0, 12) . '.json';
    }

    private static function lockFile(): string
    {
        return self::cacheDir() . '/prediction_auto.lock';
    }

    /** @return array<string,mixed> */
    private static function readState(): array
    {
        $default = [
            'pending'        => [],
            'last_run_at'    => null,
            'last_ok_at'     => null,
            'last_status'    => 'never',
            'last_processed' => 0,
            'last_failed'    => 0,
            'last_error'     => null,
            'dirty_reason'   => null,
        ];
        $file = self::stateFile();
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }
        return array_merge($default, $decoded);
    }

    /**
     * Baca-ubah-tulis state dengan lock agar penandaan dari beberapa request
     * tidak saling menimpa.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     */
    private static function updateState(callable $mutator): void
    {
        $file = self::stateFile();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (@flock($handle, LOCK_EX)) {
                $raw = stream_get_contents($handle);
                $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
                $state = is_array($decoded) ? $decoded : [];
                $state = array_merge([
                    'pending'        => [],
                    'last_run_at'    => null,
                    'last_ok_at'     => null,
                    'last_status'    => 'never',
                    'last_processed' => 0,
                    'last_failed'    => 0,
                    'last_error'     => null,
                    'dirty_reason'   => null,
                ], $state);
                $state = $mutator($state);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource|null */
    private static function acquireLock()
    {
        if (is_resource(self::$lockHandle)) {
            return self::$lockHandle;
        }
        $handle = @fopen(self::lockFile(), 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        self::$lockHandle = $handle;
        return $handle;
    }

    private static function releaseLock(): void
    {
        if (is_resource(self::$lockHandle)) {
            @flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
        }
        self::$lockHandle = null;
    }

    private static function recordFailure(string $message): void
    {
        $clean = mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 200);
        self::updateState(static function (array $state) use ($clean): array {
            $state['last_run_at'] = date('c');
            $state['last_status'] = 'error';
            $state['last_error'] = $clean;
            return $state;
        });
    }

    /** Kirim respons lebih dulu agar pemrosesan prediksi tidak menahan pengguna. */
    private static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}
