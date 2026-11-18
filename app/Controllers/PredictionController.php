<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\AiPrediction;
use App\Models\Sparepart;
use App\Services\PredictionService;
use App\Services\PredictionAutomation;
use App\Services\StockLedger;
use RuntimeException;

/**
 * Prediksi stok (Fase 7 — AI Stock Prediction).
 *
 * Hak akses:
 *   - Melihat hasil (indeks, detail, dashboard): semua role — hasil adalah
 *     rekomendasi yang dapat ditinjau sesuai peran.
 *   - Menjalankan analisis: HANYA Partman (route partmanOnly) — Partman
 *     meninjau rekomendasi dan mengubah jumlah reorder dengan alasan.
 *
 * Analisis hanyalah REKOMENDASI; tidak ada pesanan otomatis dan tidak ada
 * transaksi inventory yang berubah dari halaman ini.
 */
final class PredictionController extends Controller
{
    private const PER_PAGE = 25;
    private const RISK_FILTERS = ['', 'ada', 'belum', 'tinggi', 'sedang', 'rendah'];

    /** GET /prediksi */
    public function index(array $params = []): void
    {
        $filters = [
            'q'      => trim((string) $this->request->input('q', '')),
            'risiko' => trim((string) $this->request->input('risiko', '')),
        ];
        if (!in_array($filters['risiko'], self::RISK_FILTERS, true)) {
            $filters['risiko'] = '';
        }

        $result = AiPrediction::paginate(
            $filters,
            (int) $this->request->input('page', 1),
            self::PER_PAGE
        );

        $this->view('prediksi/index', [
            'title'   => 'Prediksi Stok',
            'filters' => $filters,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $result['page'],
            'pages'   => $result['pages'],
            'summary' => AiPrediction::summary(),
            'canRun'  => Auth::hasRole('partman'),
            'periods' => PredictionService::PERIODS,
            'automation' => PredictionAutomation::state(),
            'automationSummary' => PredictionAutomation::insight(10)['summary'],
        ]);
    }

    /** GET /prediksi/{id} — id = sparepart. */
    public function show(array $params = []): void
    {
        $sparepartId = (int) ($params['id'] ?? 0);
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $history = AiPrediction::historyForSparepart($sparepartId, 15);

        $this->view('prediksi/show', [
            'title'          => 'Prediksi ' . (string) $sparepart['code'],
            'sparepart'      => $sparepart,
            'latest'         => $history[0] ?? null,
            'history'        => $history,
            'canRun'         => Auth::hasRole('partman') && (int) $sparepart['is_active'] === 1,
            'periods'        => PredictionService::PERIODS,
            'historyOptions' => PredictionService::HISTORY_OPTIONS,
            'defaultPeriod'  => PredictionService::DEFAULT_PERIOD,
            'defaultHistory' => PredictionService::DEFAULT_HISTORY,
            'unit'           => (string) $sparepart['unit'],
        ]);
    }

    /** POST /prediksi/{id}/jalankan — hanya Partman. */
    public function run(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $sparepartId = (int) ($params['id'] ?? 0);
        $period = (int) $this->request->input('period_days', PredictionService::DEFAULT_PERIOD);
        $history = (int) $this->request->input('history_days', PredictionService::DEFAULT_HISTORY);
        $overrideRaw = trim((string) $this->request->input('lead_time_override_days', ''));
        $overrideReason = trim((string) $this->request->input('lead_time_reason', ''));
        $override = null;
        if ($overrideRaw !== '') {
            if (!ctype_digit($overrideRaw)) {
                Session::flash('danger', 'Override lead time harus berupa bilangan bulat hari.');
                $this->redirect(base_url('prediksi/' . $sparepartId));
                return;
            }
            $override = (int) $overrideRaw;
        }

        try {
            $result = PredictionService::run(
                $sparepartId,
                $period,
                $history,
                null,
                null,
                $override,
                $overrideReason
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('prediksi/' . $sparepartId));
            return;
        }

        if (($result['status'] ?? '') === 'ok') {
            $norm = $result['norm'];
            $sparepart = Sparepart::find($sparepartId);
            $overrideNote = $override !== null
                ? sprintf(' Lead time di-override manual %d hari (alasan dicatat di audit log).', $override)
                : '';
            Session::flash(
                'success',
                sprintf(
                    'Prediksi %d hari selesai (analisis: %s): estimasi permintaan %s%s, risiko %s, saran reorder %s%s. Rekomendasi tetap ditinjau Anda — mengubah jumlah wajib alasan.%s',
                    $period,
                    self::when($norm['generated_at'] ?? ''),
                    $norm['demand'] !== null ? StockLedger::formatQty($norm['demand']) : '—',
                    $sparepart !== null ? ' ' . (string) $sparepart['unit'] : '',
                    $norm['risk'] ?? 'belum dinilai',
                    $norm['recommended'] !== null ? StockLedger::formatQty($norm['recommended']) : '—',
                    $sparepart !== null ? ' ' . (string) $sparepart['unit'] : '',
                    $overrideNote
                )
            );
        } else {
            Session::flash(
                'danger',
                'Prediksi gagal: ' . (string) $result['message'] .
                ' Sistem tetap memakai saran aturan stok-minimum / prediksi terakhir — transaksi inventory tidak terpengaruh.'
            );
        }

        $this->redirect(base_url('prediksi/' . $sparepartId));
    }

    /** Waktu analisis ringkas (tanpa zona teknis). */
    private static function when(string $generatedAt): string
    {
        if ($generatedAt === '') {
            return date('d-m-Y H:i');
        }
        $ts = strtotime($generatedAt);
        return $ts === false ? $generatedAt : date('d-m-Y H:i', $ts);
    }
}
