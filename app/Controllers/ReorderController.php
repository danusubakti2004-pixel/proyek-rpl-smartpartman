<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\ReorderRequest;
use App\Models\Sparepart;
use App\Services\ReorderRequestService;
use App\Services\SupplierService;
use RuntimeException;

/**
 * Permintaan reorder (FR-15, Fase 6).
 *
 * Hak akses:
 *   - Daftar & detail : semua role (Kepala Bengkel meninjau, Admin memantau).
 *   - Buat/ubah       : HANYA Partman (route partmanOnly).
 *   - Setujui/tolak   : HANYA Kepala Bengkel (route kepalaOnly).
 *
 * Persetujuan TIDAK menambah stok — stok hanya berubah lewat penerimaan
 * barang (modul transaksi masuk).
 */
final class ReorderController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /reorder */
    public function index(array $params = []): void
    {
        $filters = [
            'status' => trim((string) $this->request->input('status', '')),
            'search' => trim((string) $this->request->input('q', '')),
        ];

        $result = ReorderRequest::paginate(
            $filters,
            (int) $this->request->input('page', 1),
            self::PER_PAGE
        );

        $this->view('reorder/index', [
            'title'      => 'Permintaan Reorder',
            'canManage'  => Auth::hasRole('partman'),
            'canReview'  => Auth::hasRole('kepala_bengkel'),
            'filters'    => $filters,
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'pages'      => $result['pages'],
            'counts'     => ReorderRequest::countByStatus(),
            'needs'      => ReorderRequest::needs(10),
        ]);
    }

    /** GET /reorder/saran?sparepart_id=X — saran jumlah (AJAX, mengisi formulir). */
    public function suggestion(array $params = []): void
    {
        $sparepartId = (int) $this->request->input('sparepart_id', 0);
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null || (int) $sparepart['is_active'] !== 1) {
            $this->json(['error' => 'Sparepart tidak ditemukan.'], 404);
        }

        $suggestion = ReorderRequestService::suggestion($sparepartId);

        $this->json([
            'sparepart_id'  => $sparepartId,
            'stock'         => (float) $sparepart['stock'],
            'min_stock'     => (float) $sparepart['min_stock'],
            'unit'          => (string) $sparepart['unit'],
            'suggested_qty' => $suggestion['suggested_qty'],
            'source'        => $suggestion['source'],
        ]);
    }

    /** GET /reorder/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('reorder/form', [
            'title'      => 'Buat Permintaan Reorder',
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
            'suppliers'  => SupplierService::options(true),
            'reorder'    => null,
        ]);
    }

    /** POST /reorder */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();

        try {
            $reorderId = ReorderRequestService::create($input, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('reorder/tambah'));
        }

        $row = ReorderRequest::find($reorderId);
        Session::flash(
            'success',
            'Permintaan reorder ' . (string) ($row['request_number'] ?? '') .
            ' diajukan ke Kepala Bengkel. Persetujuan tidak menambah stok — stok bertambah saat barang diterima.'
        );
        $this->redirect(base_url('reorder/' . $reorderId));
    }

    /** GET /reorder/{id} */
    public function show(array $params = []): void
    {
        $reorder = ReorderRequest::find((int) ($params['id'] ?? 0));
        if ($reorder === null) {
            $this->abort(404, 'Permintaan reorder tidak ditemukan.');
        }

        $canEdit = Auth::hasRole('partman')
            && (int) $reorder['created_by'] === (int) Auth::id()
            && (string) $reorder['status'] === ReorderRequest::STATUS_PENDING;

        $this->view('reorder/show', [
            'title'     => 'Permintaan Reorder ' . (string) $reorder['request_number'],
            'row'       => $reorder,
            'canReview' => Auth::hasRole('kepala_bengkel')
                && (string) $reorder['status'] === ReorderRequest::STATUS_PENDING,
            'canEdit'   => $canEdit,
            'history'   => \App\Models\StatusHistory::forDocument(
                \App\Models\StatusHistory::TYPE_REORDER,
                (int) $reorder['id']
            ),
            'purchaseOrders' => ReorderRequest::purchaseOrders((int) $reorder['id']),
        ]);
    }

    /** GET /reorder/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $reorder = ReorderRequest::find((int) ($params['id'] ?? 0));
        if ($reorder === null) {
            $this->abort(404, 'Permintaan reorder tidak ditemukan.');
        }
        if ((int) $reorder['created_by'] !== (int) Auth::id()) {
            $this->abort(403, 'Hanya pengaju permintaan yang dapat mengubahnya.');
        }
        if ((string) $reorder['status'] !== ReorderRequest::STATUS_PENDING) {
            Session::flash('warning', 'Permintaan yang sudah diputus tidak dapat diubah.');
            $this->redirect(base_url('reorder/' . (int) $reorder['id']));
        }

        $this->view('reorder/form', [
            'title'      => 'Ubah Permintaan Reorder',
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
            'suppliers'  => SupplierService::options(true),
            'reorder'    => $reorder,
        ]);
    }

    /** POST /reorder/{id} */
    public function update(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $reorderId = (int) ($params['id'] ?? 0);
        $input = $this->request->post();

        try {
            ReorderRequestService::updateQty($reorderId, $input, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('reorder/' . $reorderId . '/ubah'));
        }

        Session::flash('success', 'Jumlah permintaan diperbarui. Kepala Bengkel akan meninjau permintaan ini.');
        $this->redirect(base_url('reorder/' . $reorderId));
    }

    /** POST /reorder/{id}/setujui — Kepala Bengkel. */
    public function approve(array $params = []): void
    {
        $this->review((int) ($params['id'] ?? 0), true);
    }

    /** POST /reorder/{id}/tolak — Kepala Bengkel, alasan wajib. */
    public function reject(array $params = []): void
    {
        $this->review((int) ($params['id'] ?? 0), false);
    }

    private function review(int $reorderId, bool $approve): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $note = (string) $this->request->input('review_note', '');

        try {
            ReorderRequestService::review($reorderId, $approve, $note, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('reorder/' . $reorderId));
        }

        Session::flash(
            'success',
            $approve
                ? 'Permintaan disetujui. Stok TIDAK berubah — buat PO lalu catat penerimaan barang.'
                : 'Permintaan ditolak beserta alasannya.'
        );
        $this->redirect(base_url('reorder/' . $reorderId));
    }
}
