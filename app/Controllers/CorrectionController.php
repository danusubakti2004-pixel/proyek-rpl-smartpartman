<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\StockMovement;
use App\Services\CorrectionService;
use App\Services\StockLedger;
use RuntimeException;

/**
 * Koreksi transaksi (PRD §7.4, Aturan Bisnis 8).
 *
 * Transaksi lama TIDAK dihapus/diubah: koreksi membuat pergerakan pembalik
 * (KOREKSI) yang menunjuk transaksi asal, wajib beralasan, dan tercatat di
 * Audit Log beserta nilai sebelum/sesudah.
 *
 * HANYA Partman (RoleMiddleware di route).
 */
final class CorrectionController extends Controller
{
    /** GET /transaksi/koreksi — pilih transaksi yang masih dapat dikoreksi. */
    public function index(array $params = []): void
    {
        $this->view('transactions/corrections/index', [
            'title'    => 'Koreksi Transaksi',
            'rows'     => StockMovement::correctable(100),
            'canManage' => Auth::hasRole('partman'),
        ]);
    }

    /** GET /transaksi/koreksi/{id} */
    public function form(array $params = []): void
    {
        $movementId = (int) ($params['id'] ?? 0);
        $movement = StockMovement::find($movementId);
        if ($movement === null) {
            $this->abort(404, 'Transaksi asal tidak ditemukan.');
        }

        $remaining = StockLedger::remainingReversible($movement);

        $this->view('transactions/corrections/form', [
            'title'        => 'Koreksi Transaksi #' . $movementId,
            'canManage'    => Auth::hasRole('partman'),
            'row'          => $movement,
            'remaining'    => $remaining,
            'correctable'  => CorrectionService::isCorrectable($movement) && $remaining > 0,
            'old'          => Session::oldInput(),
        ]);
    }

    /** POST /transaksi/koreksi/{id} */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $movementId = (int) ($params['id'] ?? 0);
        $input = $this->request->post();

        try {
            $result = CorrectionService::correct($movementId, $input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('transaksi/koreksi/' . $movementId));
        }

        Session::flash(
            'success',
            sprintf(
                'Koreksi tersimpan (pergerakan #%d). Stok %s → %s. Transaksi asli tetap tersimpan di riwayat.',
                $result['movement_id'],
                StockLedger::formatQty($result['stock_before']),
                StockLedger::formatQty($result['stock_after'])
            )
        );
        $this->redirect(base_url('transaksi/riwayat?sparepart=' . $result['sparepart_id']));
    }
}
