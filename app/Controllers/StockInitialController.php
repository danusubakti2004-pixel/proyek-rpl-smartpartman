<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\MasterRules;
use App\Services\SparepartService;
use App\Services\StockInitialService;
use RuntimeException;

/**
 * Stok awal manual (FR-05).
 *
 * Stok awal BUKAN pembelian dan TIDAK mengubah stok secara diam-diam:
 * setiap pencatatan membuat satu baris ledger bertipe STOK_AWAL
 * (pengguna, tanggal, item, jumlah, catatan) dan hanya boleh sekali
 * per sparepart — koreksi berikutnya lewat penyesuaian opname (FR-10).
 *
 * HANYA Partman (dipaksa RoleMiddleware di route).
 */
final class StockInitialController extends Controller
{
    /** GET /sparepart/{id}/stok-awal */
    public function form(array $params = []): void
    {
        $sparepart = SparepartService::find((int) ($params['id'] ?? 0));
        if ($sparepart === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $this->view('master/spareparts/stock_initial', [
            'title'     => 'Input Stok Awal',
            'row'       => $sparepart,
            'initial'   => StockInitialService::initialFor((int) $sparepart['id']),
            'old'       => Session::oldInput(),
        ]);
    }

    /** POST /sparepart/{id}/stok-awal */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (SparepartService::find($id) === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $rawQuantity = trim((string) $this->request->input('quantity', ''));
        $quantity = MasterRules::parseDecimal($rawQuantity);
        $rawDate = trim((string) $this->request->input('movement_date', ''));

        if ($quantity === null || $quantity <= 0) {
            Session::flash('danger', 'Jumlah stok awal harus berupa angka lebih dari 0.');
            Session::flashInput($this->request->post());
            $this->redirect(base_url('sparepart/' . $id . '/stok-awal'));
        }
        if ($rawDate !== '' && MasterRules::parseDate($rawDate) === null) {
            Session::flash('danger', 'Tanggal tidak dikenali. Gunakan format dd/mm/yyyy atau yyyy-mm-dd.');
            Session::flashInput($this->request->post());
            $this->redirect(base_url('sparepart/' . $id . '/stok-awal'));
        }

        try {
            StockInitialService::apply(
                $id,
                $quantity,
                $rawDate !== '' ? $rawDate : null,
                (string) $this->request->input('note', ''),
                Auth::id(),
                'MANUAL'
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('sparepart/' . $id . '/stok-awal'));
        }

        Session::flash('success', 'Stok awal berhasil dicatat dan tercatat pada kartu stok.');
        $this->redirect(base_url('sparepart/' . $id));
    }
}
