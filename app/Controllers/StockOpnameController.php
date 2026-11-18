<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\StockOpname;
use App\Services\StockOpnameService;
use RuntimeException;

/**
 * Stock opname (FR-10).
 *
 * Hak akses (PRD §6.4):
 *   - Melihat daftar/hasil : semua role (Kepala Bengkel memantau, Admin melihat).
 *   - Memulai/mengisi/konfirmasi: HANYA Partman (RoleMiddleware di route).
 *
 * Stok TIDAK berubah selama sesi berstatus OPEN — perubahan hanya terjadi
 * saat Partman menekan "Konfirmasi" (StockOpnameService::confirm).
 */
final class StockOpnameController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /transaksi/opname */
    public function index(array $params = []): void
    {
        $filters = [
            'search'    => trim((string) $this->request->input('q', '')),
            'status'    => trim((string) $this->request->input('status', '')),
            'date_from' => trim((string) $this->request->input('dari', '')),
            'date_to'   => trim((string) $this->request->input('sampai', '')),
        ];

        $result = StockOpname::paginate($filters, (int) $this->request->input('page', 1), self::PER_PAGE);

        $this->view('transactions/opnames/index', [
            'title'         => 'Stock Opname',
            'canManage'     => Auth::hasRole('partman'),
            'filters'       => $filters,
            'rows'          => $result['rows'],
            'total'         => $result['total'],
            'page'          => $result['page'],
            'pages'         => $result['pages'],
            'hasOpenSession' => StockOpname::hasOpenSession(),
        ]);
    }

    /** GET /transaksi/opname/mulai */
    public function createForm(array $params = []): void
    {
        $this->view('transactions/opnames/form', [
            'title'         => 'Mulai Stock Opname',
            'old'           => Session::oldInput(),
            'hasOpenSession' => StockOpname::hasOpenSession(),
        ]);
    }

    /** POST /transaksi/opname */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();

        try {
            $result = StockOpnameService::start($input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('transaksi/opname/mulai'));
        }

        Session::flash(
            'success',
            'Sesi stock opname ' . $result['number'] . ' dimulai dengan ' . $result['item_count']
            . ' item. Isi jumlah fisik, lalu konfirmasi.'
        );
        $this->redirect(base_url('transaksi/opname/' . $result['opname_id']));
    }

    /** GET /transaksi/opname/{id} */
    public function show(array $params = []): void
    {
        $opname = StockOpname::find((int) ($params['id'] ?? 0));
        if ($opname === null) {
            $this->abort(404, 'Sesi stock opname tidak ditemukan.');
        }

        $this->view('transactions/opnames/show', [
            'title'     => 'Stock Opname ' . (string) $opname['opname_number'],
            'canManage' => Auth::hasRole('partman'),
            'row'       => $opname,
            'items'     => StockOpname::items((int) $opname['id']),
            'old'       => Session::oldInput(),
        ]);
    }

    /** POST /transaksi/opname/{id}/simpan */
    public function saveInputs(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $opnameId = (int) ($params['id'] ?? 0);
        $physical = $this->request->input('physical', []);
        $reasons = $this->request->input('reason', []);

        try {
            $summary = StockOpnameService::saveInputs(
                $opnameId,
                is_array($physical) ? $physical : [],
                is_array($reasons) ? $reasons : [],
                Auth::id()
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('transaksi/opname/' . $opnameId));
        }

        Session::flash(
            'success',
            sprintf(
                'Hasil hitung disimpan (belum mengubah stok): %d item, %d sesuai, %d berselisih.',
                $summary['counted'],
                $summary['matched'],
                $summary['differences']
            )
        );
        $this->redirect(base_url('transaksi/opname/' . $opnameId));
    }

    /** POST /transaksi/opname/{id}/konfirmasi */
    public function confirm(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $opnameId = (int) ($params['id'] ?? 0);

        try {
            $result = StockOpnameService::confirm($opnameId, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('transaksi/opname/' . $opnameId));
        }

        Session::flash(
            'success',
            sprintf(
                'Stock opname dikonfirmasi: %d item disesuaikan, %d item sudah sesuai. Stok diperbarui lewat ledger.',
                $result['adjusted'],
                $result['matched']
            )
        );
        $this->redirect(base_url('transaksi/opname/' . $opnameId));
    }
}
