<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\PurchaseOrder;
use App\Models\ReorderRequest;
use App\Models\Sparepart;
use App\Models\StatusHistory;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierService;
use RuntimeException;

/**
 * Purchase Order (FR-16, Fase 6).
 *
 * Hak akses:
 *   - Daftar & detail (termasuk cetak/PDF): semua role.
 *   - Buat/ubah draft & ajukan            : HANYA Partman.
 *   - Setujui/tolak                       : HANYA Kepala Bengkel.
 *   - Proses administrasi & status bayar  : HANYA Admin.
 *   - Batalkan (pengganti hapus)          : Partman (draft miliknya) / Admin.
 *
 * Persetujuan PO TIDAK menambah stok; stok hanya bertambah saat penerimaan
 * barang dicatat (menu Barang Masuk), bisa bertahap/parsial.
 */
final class PurchaseOrderController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /pembelian */
    public function index(array $params = []): void
    {
        $filters = [
            'status'      => trim((string) $this->request->input('status', '')),
            'payment'     => trim((string) $this->request->input('bayar', '')),
            'supplier_id' => trim((string) $this->request->input('supplier', '')),
            'search'      => trim((string) $this->request->input('q', '')),
        ];

        // Cakupan data per role (ditegakkan di backend, bukan hanya tampilan):
        //  - Partman       : hanya PO miliknya sendiri.
        //  - Kepala Bengkel: semua PO yang sudah diajukan (draft belum diajukan tidak tampil).
        //  - Admin         : semua PO.
        $scope = [];
        if (Auth::role() === 'partman') {
            $scope['created_by'] = (int) Auth::id();
        } elseif (Auth::role() === 'kepala_bengkel') {
            $scope['exclude_status'] = PurchaseOrder::STATUS_DRAFT;
        }

        $result = PurchaseOrder::paginate(
            $filters + $scope,
            (int) $this->request->input('page', 1),
            self::PER_PAGE
        );

        $counts = [
            'pending' => PurchaseOrder::paginate(
                ['status' => PurchaseOrder::STATUS_PENDING] + $scope, 1, 1
            )['total'],
        ];

        $this->view('pembelian/index', [
            'title'     => 'Pembelian / Purchase Order',
            'canCreate' => Auth::hasRole('partman'),
            'role'      => (string) Auth::role(),
            'userId'    => (int) Auth::id(),
            'filters'   => $filters,
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $result['page'],
            'pages'     => $result['pages'],
            'counts'    => $counts,
            'suppliers' => SupplierService::options(false),
        ]);
    }

    /** GET /pembelian/rekomendasi-supplier?sparepart_id=X — rekomendasi berdasar riwayat. */
    public function recommendations(array $params = []): void
    {
        $sparepartId = (int) $this->request->input('sparepart_id', 0);
        $sparepart = Sparepart::find($sparepartId);
        if ($sparepart === null) {
            $this->json(['error' => 'Sparepart tidak ditemukan.'], 404);
        }

        $this->json([
            'sparepart_id'  => $sparepartId,
            'recommendations' => PurchaseOrderService::supplierRecommendations($sparepartId),
        ]);
    }

    /** GET /pembelian/tambah (opsional ?reorder=ID dari permintaan yang disetujui). */
    public function createForm(array $params = []): void
    {
        $reorder = null;
        $recommendations = [];
        $reorderId = (int) $this->request->input('reorder', 0);
        if ($reorderId > 0) {
            $found = ReorderRequest::find($reorderId);
            if ($found === null) {
                $this->abort(404, 'Permintaan reorder tidak ditemukan.');
            }
            if ((string) $found['status'] !== ReorderRequest::STATUS_APPROVED) {
                Session::flash('warning', 'Hanya permintaan reorder yang disetujui yang dapat dijadikan PO.');
                $this->redirect(base_url('pembelian/tambah'));
            }
            if (ReorderRequest::purchaseOrders($reorderId) !== []) {
                Session::flash('warning', 'Permintaan reorder ini sudah pernah dijadikan PO.');
                $this->redirect(base_url('pembelian/tambah'));
            }
            $reorder = $found;
            $recommendations = PurchaseOrderService::supplierRecommendations((int) $found['sparepart_id']);
        }

        $this->view('pembelian/form', [
            'title'           => 'Buat Purchase Order',
            'old'             => Session::oldInput(),
            'po'              => null,
            'items'           => [],
            'suppliers'       => SupplierService::options(true),
            'spareparts'      => Sparepart::options(true),
            'reorder'         => $reorder,
            'recommendations' => $recommendations,
        ]);
    }

    /** POST /pembelian */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();
        $input['items'] = self::collectItems($this->request->post());

        try {
            $poId = PurchaseOrderService::create($input, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('pembelian/tambah'));
        }

        $po = PurchaseOrder::find($poId);
        Session::flash(
            'success',
            'Draft PO ' . (string) ($po['po_number'] ?? '') .
            ' tersimpan. Ajukan ke Kepala Bengkel setelah yakin isinya benar.'
        );
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** GET /pembelian/{id} */
    public function show(array $params = []): void
    {
        $po = PurchaseOrder::find((int) ($params['id'] ?? 0));
        if ($po === null) {
            $this->abort(404, 'Purchase Order tidak ditemukan.');
        }

        $role = (string) Auth::role();
        $status = (string) $po['status'];

        // Cakupan akses detail PO (backend, bukan sekadar tombol):
        //  - Partman hanya boleh membuka PO miliknya sendiri.
        //  - Kepala Bengkel hanya melihat PO yang sudah diajukan (bukan draft).
        if ($role === 'partman' && (int) $po['created_by'] !== (int) Auth::id()) {
            $this->abort(403, 'Anda hanya dapat melihat PO yang Anda ajukan.');
        }
        if ($role === 'kepala_bengkel' && $status === PurchaseOrder::STATUS_DRAFT) {
            $this->abort(403, 'PO ini masih draf dan belum diajukan.');
        }

        $canSubmit = $role === 'partman' && $status === PurchaseOrder::STATUS_DRAFT
            && (int) $po['created_by'] === (int) Auth::id();
        $canEdit = $canSubmit;
        $canApprove = $role === 'kepala_bengkel' && $status === PurchaseOrder::STATUS_PENDING;
        $canProcess = $role === 'admin' && $status === PurchaseOrder::STATUS_APPROVED;
        $canPay = $role === 'admin' && in_array($status, [
            PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
            PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_COMPLETE,
        ], true);

        $hasRemaining = false;
        foreach (PurchaseOrder::items((int) $po['id']) as $poItem) {
            if ((float) ($poItem['remaining_qty'] ?? 0) > 0) {
                $hasRemaining = true;
                break;
            }
        }
        $canReceive = $role === 'admin' && in_array($status, [
            PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
            PurchaseOrder::STATUS_PARTIAL,
        ], true) && $hasRemaining;
        $canCancel = ($role === 'admin' && in_array($status, [
            PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED,
        ], true)) || ($role === 'partman' && in_array($status, [
            PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_PENDING,
        ], true) && (int) $po['created_by'] === (int) Auth::id());

        $this->view('pembelian/show', [
            'title'     => 'Purchase Order ' . (string) $po['po_number'],
            'po'        => $po,
            'items'     => PurchaseOrder::items((int) $po['id']),
            'history'   => StatusHistory::forDocument(StatusHistory::TYPE_PO, (int) $po['id']),
            'receipts'  => PurchaseOrder::receipts((int) $po['id']),
            'payments'  => PurchaseOrder::paymentHistory((int) $po['id']),
            'canSubmit' => $canSubmit,
            'canEdit'   => $canEdit,
            'canApprove' => $canApprove,
            'canProcess' => $canProcess,
            'canPay'    => $canPay,
            'canReceive' => $canReceive,
            'canCancel' => $canCancel,
            'printUrl'  => base_url('laporan/po/' . (int) $po['id'] . '/cetak'),
            'pdfUrl'    => base_url('laporan/po/' . (int) $po['id'] . '/pdf'),
        ]);
    }

    /** GET /pembelian/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $po = PurchaseOrder::find((int) ($params['id'] ?? 0));
        if ($po === null) {
            $this->abort(404, 'Purchase Order tidak ditemukan.');
        }
        if ((string) $po['status'] !== PurchaseOrder::STATUS_DRAFT) {
            Session::flash('warning', 'Hanya PO berstatus Draf yang dapat diubah.');
            $this->redirect(base_url('pembelian/' . (int) $po['id']));
        }
        if ((int) $po['created_by'] !== (int) Auth::id()) {
            $this->abort(403, 'Hanya pembuat PO yang dapat mengubahnya.');
        }

        $this->view('pembelian/form', [
            'title'           => 'Ubah Draft PO',
            'old'             => Session::oldInput(),
            'po'              => $po,
            'items'           => PurchaseOrder::items((int) $po['id']),
            'suppliers'       => SupplierService::options(true),
            'spareparts'      => Sparepart::options(true),
            'reorder'         => null,
            'recommendations' => [],
        ]);
    }

    /** POST /pembelian/{id} */
    public function update(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $poId = (int) ($params['id'] ?? 0);
        $input = $this->request->post();
        $input['items'] = self::collectItems($this->request->post());

        try {
            PurchaseOrderService::updateDraft($poId, $input, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('pembelian/' . $poId . '/ubah'));
        }

        Session::flash('success', 'Draft PO diperbarui.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/ajukan — Partman mengajukan draft ke Kepala Bengkel. */
    public function submit(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if (Auth::role() !== 'partman') {
            $this->abort(403, 'Akses ditolak.');
        }

        $poId = (int) ($params['id'] ?? 0);
        try {
            PurchaseOrderService::submit($poId, (int) Auth::id());
            $po = PurchaseOrder::find($poId);
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'PO_SUBMIT',
                "SmartPartMan\nPengajuan PO baru\nNomor PO: " . (string) ($po['po_number'] ?? $poId) . "\nDiajukan oleh: " . (string) (Auth::user()['full_name'] ?? '') . "\nStatus: Menunggu persetujuan.",
                'PO_SUBMIT:' . $poId
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        Session::flash('success', 'PO diajukan ke Kepala Bengkel untuk disetujui.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/setujui — Kepala Bengkel. TIDAK menambah stok. */
    public function approve(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if (Auth::role() !== 'kepala_bengkel') {
            $this->abort(403, 'Akses ditolak.');
        }

        $poId = (int) ($params['id'] ?? 0);
        try {
            PurchaseOrderService::approve($poId, (int) Auth::id());
            $po = PurchaseOrder::find($poId);
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'PO_APPROVED',
                "SmartPartMan\nPO disetujui\nNomor PO: " . (string) ($po['po_number'] ?? $poId) . "\nDisetujui oleh: " . (string) (Auth::user()['full_name'] ?? '') . "\nStatus: Disetujui.",
                'PO_APPROVED:' . $poId
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        Session::flash('success', 'PO disetujui. Stok TIDAK berubah - stok bertambah saat barang diterima.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/tolak — Kepala Bengkel, alasan wajib. */
    public function reject(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if (Auth::role() !== 'kepala_bengkel') {
            $this->abort(403, 'Akses ditolak.');
        }

        $poId = (int) ($params['id'] ?? 0);
        $reason = (string) $this->request->input('reason', '');
        try {
            PurchaseOrderService::reject($poId, $reason, (int) Auth::id());
            $po = PurchaseOrder::find($poId);
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'PO_REJECTED',
                "SmartPartMan\nPO ditolak\nNomor PO: " . (string) ($po['po_number'] ?? $poId) . "\nDiproses oleh: " . (string) (Auth::user()['full_name'] ?? '') . "\nAlasan: " . $reason,
                'PO_REJECTED:' . $poId
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        Session::flash('success', 'PO ditolak beserta alasannya.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/proses — Admin memproses administrasi PO disetujui. */
    public function process(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $this->simpleAction($params, static function (int $poId, int $actorId): void {
            PurchaseOrderService::process($poId, $actorId);
        }, 'Administrasi PO diproses.');
    }

    /** POST /pembelian/{id}/pembayaran — Admin memperbarui status pembayaran. */
    public function payment(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }

        $poId = (int) ($params['id'] ?? 0);
        try {
            PurchaseOrderService::updatePayment(
                $poId,
                (string) $this->request->input('payment_status', ''),
                (string) $this->request->input('payment_note', ''),
                (int) Auth::id()
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
        }

        Session::flash('success', 'Status pembayaran diperbarui dan tercatat riwayatnya.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/batal — batalkan PO (alasan wajib); PO tidak dihapus. */
    public function cancel(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $poId = (int) ($params['id'] ?? 0);
        $byAdmin = Auth::hasRole('admin');

        try {
            PurchaseOrderService::cancel(
                $poId,
                (string) $this->request->input('reason', ''),
                (int) Auth::id(),
                $byAdmin
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
        }

        Session::flash('success', 'PO dibatalkan beserta alasannya. PO tidak dihapus — tetap tercatat untuk riwayat.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /** POST /pembelian/{id}/terima-otomatis �?" Admin menerima seluruh sisa PO secara otomatis. */
    public function autoReceive(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }

        $poId = (int) ($params['id'] ?? 0);
        $po = PurchaseOrder::find($poId);
        if ($po === null) {
            $this->abort(404, 'Purchase Order tidak ditemukan.');
        }

        $status = (string) $po['status'];
        if (!in_array($status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PROCESSED, PurchaseOrder::STATUS_PARTIAL], true)) {
            Session::flash('danger', 'PO belum disetujui atau sudah selesai diterima.');
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        $items = PurchaseOrder::items($poId);
        $remainingItems = array_values(array_filter($items, static fn (array $item): bool => (float) ($item['remaining_qty'] ?? 0) > 0));
        if ($remainingItems === []) {
            Session::flash('warning', 'Tidak ada sisa barang yang perlu diterima.');
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        $receiptIds = [];
        try {
            Database::transaction(static function () use ($poId, $po, $remainingItems, &$receiptIds): void {
                $actorId = (int) Auth::id();
                $now = date('Y-m-d H:i:s');
                foreach ($remainingItems as $item) {
                    $qty = round((float) $item['remaining_qty'], 2);
                    if ($qty <= 0) {
                        continue;
                    }
                    $note = sprintf(
                        'Penerimaan barang otomatis berdasarkan PO %s dari Supplier %s. Seluruh sisa kuantitas PO diterima oleh Admin pada %s.',
                        (string) $po['po_number'],
                        (string) $po['supplier_name'],
                        $now
                    );
                    $receiptId = GoodsReceiptService::create([
                        'receipt_date' => date('Y-m-d'),
                        'receipt_type' => 'pembelian',
                        'supplier_id' => (int) $po['supplier_id'],
                        'sparepart_id' => (int) $item['sparepart_id'],
                        'quantity' => $qty,
                        'unit_price' => (float) $item['unit_price'],
                        'purchase_order_id' => $poId,
                        'note' => $note,
                    ], $actorId);
                    $receiptIds[] = $receiptId;
                }
            });
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        } catch (Throwable $e) {
            Session::flash('danger', 'Gagal memproses penerimaan: ' . $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
            return;
        }

        try {
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'BARANG_MASUK',
                "SmartPartMan\nPenerimaan barang berhasil\nPO: " . (string) $po['po_number'] . "\nDiproses oleh: " . (string) (Auth::user()['full_name'] ?? 'Admin'),
                'PO_RECEIVE:' . $poId
            );
        } catch (Throwable $e) {
            // ignore
        }

        Session::flash('success', 'Penerimaan barang dari PO ' . (string) $po['po_number'] . ' berhasil diproses. Stok sudah diperbarui.');
        $this->redirect(base_url('pembelian/' . $poId));
    }

    // ------------------------------------------------------------------

    /** @param \Closure(int,int):void $action */
    private function simpleAction(array $params, \Closure $action, string $successMessage): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $poId = (int) ($params['id'] ?? 0);
        try {
            $action($poId, (int) Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pembelian/' . $poId));
        }

        Session::flash('success', $successMessage);
        $this->redirect(base_url('pembelian/' . $poId));
    }

    /**
     * Kumpulkan baris item formulir (item_sparepart_id[], item_quantity[],
     * item_unit_price[]) menjadi daftar seragam.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function collectItems(array $post): array
    {
        $ids = (array) ($post['item_sparepart_id'] ?? []);
        $qtys = (array) ($post['item_quantity'] ?? []);
        $prices = (array) ($post['item_unit_price'] ?? []);

        $items = [];
        foreach (array_values($ids) as $index => $sparepartId) {
            if ((string) $sparepartId === '') {
                continue; // baris kosong diabaikan
            }
            $items[] = [
                'sparepart_id' => (int) $sparepartId,
                'quantity'     => $qtys[$index] ?? null,
                'unit_price'   => $prices[$index] ?? null,
            ];
        }

        return $items;
    }
}
