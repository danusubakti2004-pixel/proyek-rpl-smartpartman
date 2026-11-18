<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\StockAdjustmentService;
use App\Services\StockLedger;
use App\Services\WhatsAppNotificationService;
use RuntimeException;

/**
 * Penyesuaian stok sparepart (Partman).
 */
final class StockAdjustmentController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /transaksi/penyesuaian */
    public function index(array $params = []): void
    {
        $filters = [
            'search'        => trim((string) $this->request->input('q', '')),
            'sparepart_id'  => (int) $this->request->input('sparepart', 0),
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'date_from'     => trim((string) $this->request->input('dari', '')),
            'date_to'       => trim((string) $this->request->input('sampai', '')),
        ];

        $limit = self::PER_PAGE;
        $offset = ((int) $this->request->input('page', 1) - 1) * $limit;
        if ($offset < 0) {
            $offset = 0;
        }

        $rows = StockMovement::filtered($filters, $limit, $offset);
        $total = StockMovement::countFiltered($filters);
        $pages = max(1, (int) ceil($total / $limit));
        $page = max(1, min($pages, (int) $this->request->input('page', 1)));

        $this->view('transactions/adjustments/index', [
            'title'      => 'Penyesuaian Stok',
            'canManage'  => Auth::hasRole('partman'),
            'filters'    => $filters,
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'spareparts' => Sparepart::options(false),
        ]);
    }

    /** GET /transaksi/penyesuaian/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('transactions/adjustments/form', [
            'title'      => 'Penyesuaian Stok',
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
        ]);
    }

    /** POST /transaksi/penyesuaian */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();
        try {
            $result = StockAdjustmentService::adjust($input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('transaksi/penyesuaian/tambah'));
        }

        try {
            WhatsAppNotificationService::notifyAllRecipients(
                'STOCK_ADJUSTMENT',
                'PENYESUAIAN STOK\nNomor: ' . (string) ($result['adjustment_number'] ?? ''),
                'adj:' . (int) $result['movement_id']
            );
        } catch (\Throwable $e) {
            // ignore
        }

        Session::flash(
            'success',
            sprintf(
                'Penyesuaian %s berhasil. Stok %s → %s.',
                (string) ($result['adjustment_number'] ?? ''),
                StockLedger::formatQty($result['stock_before']),
                StockLedger::formatQty($result['stock_after'])
            )
        );
        $this->redirect(base_url('transaksi/penyesuaian/' . (int) $result['movement_id']));
    }

    /** GET /transaksi/penyesuaian/{id} */
    public function show(array $params = []): void
    {
        $movement = StockMovement::find((int) ($params['id'] ?? 0));
        if ($movement === null || (string) $movement['movement_type'] !== StockMovement::TYPE_ADJUSTMENT) {
            $this->abort(404, 'Penyesuaian tidak ditemukan.');
        }

        $this->view('transactions/adjustments/show', [
            'title'    => 'Detail Penyesuaian ' . (string) ($movement['reference_number'] ?? ''),
            'canManage' => Auth::hasRole('partman'),
            'row'      => $movement,
        ]);
    }
}