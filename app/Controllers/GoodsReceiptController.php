<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\GoodsReceipt;
use App\Models\Sparepart;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Services\AttachmentService;
use App\Services\GoodsReceiptService;
use App\Services\SupplierService;
use App\Services\WhatsAppNotificationService;
use RuntimeException;

/**
 * Barang masuk (FR-06).
 *
 * Hak akses (PRD §6.4):
 *   - Daftar, detail, unduh bukti : semua role (Kepala Bengkel & Admin hanya lihat).
 *   - Catat penerimaan             : HANYA Partman (dipaksa RoleMiddleware di route).
 *
 * Formulir tidak pernah menulis kolom `stock`: stok bertambah lewat
 * StockLedger di dalam GoodsReceiptService.
 */
final class GoodsReceiptController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /transaksi/masuk */
    public function index(array $params = []): void
    {
        $filters = [
            'search'       => trim((string) $this->request->input('q', '')),
            'supplier_id'  => trim((string) $this->request->input('supplier', '')),
            'receipt_type' => trim((string) $this->request->input('jenis', '')),
            'date_from'    => trim((string) $this->request->input('dari', '')),
            'date_to'      => trim((string) $this->request->input('sampai', '')),
        ];

        $result = GoodsReceipt::paginate($filters, (int) $this->request->input('page', 1), self::PER_PAGE);

        $this->view('transactions/receipts/index', [
            'title'     => 'Barang Masuk',
            'canManage' => Auth::hasRole('partman'),
            'filters'   => $filters,
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $result['page'],
            'pages'     => $result['pages'],
            'suppliers' => SupplierService::options(false),
        ]);
    }

    /** GET /transaksi/masuk/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('transactions/receipts/form', [
            'title'      => 'Catat Barang Masuk',
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
            'suppliers'  => SupplierService::options(true),
            // Partman hanya mencatat barang masuk tanpa PO; penerimaan berdasarkan
            // PO dilakukan Admin lewat detail PO.
            'openPos'    => [],
            'formAction' => base_url('transaksi/masuk'),
        ]);
    }

    /** POST /transaksi/masuk */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();
        $file = $_FILES['attachment'] ?? null;

        try {
            $receiptId = GoodsReceiptService::create($input, Auth::id(), is_array($file) ? $file : null);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('transaksi/masuk/tambah'));
        }

        $receipt = GoodsReceipt::find($receiptId);

        try {
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'BARANG_MASUK',
                "SmartPartMan\nPenerimaan barang berhasil\nNomor transaksi: " . (string) ($receipt['receipt_number'] ?? '') . "\nTanggal: " . date('Y-m-d'),
                'RECEIPT:' . $receiptId
            );
        } catch (\Throwable $e) {
            // ignore
        }

        Session::flash(
            'success',
            'Penerimaan ' . (string) ($receipt['receipt_number'] ?? '') . ' berhasil disimpan. Stok sudah diperbarui.'
        );
        $this->redirect(base_url('transaksi/masuk/' . $receiptId));
    }

    /** GET /transaksi/masuk/{id} */
    public function show(array $params = []): void
    {
        $receipt = GoodsReceipt::find((int) ($params['id'] ?? 0));
        if ($receipt === null) {
            $this->abort(404, 'Penerimaan tidak ditemukan.');
        }

        $this->view('transactions/receipts/show', [
            'title'        => 'Detail Penerimaan ' . (string) $receipt['receipt_number'],
            'canManage'    => Auth::hasRole('partman'),
            'row'          => $receipt,
            'items'        => GoodsReceipt::items((int) $receipt['id']),
            'movement'     => StockMovement::forReference('GOODS_RECEIPT', (int) $receipt['id']),
            'hasEvidence'  => !empty($receipt['attachment_path']),
            'attachmentUrl' => base_url('transaksi/masuk/' . $receipt['id'] . '/bukti'),
        ]);
    }

    /** GET /transaksi/masuk/{id}/cetak — bukti penerimaan (semua role). */
    public function print(array $params = []): void
    {
        $receipt = GoodsReceipt::find((int) ($params['id'] ?? 0));
        if ($receipt === null) {
            $this->abort(404, 'Penerimaan tidak ditemukan.');
        }

        $this->view('documents/receipt', [
            'title'       => 'Bukti Barang Masuk ' . (string) $receipt['receipt_number'],
            'row'         => $receipt,
            'items'       => GoodsReceipt::items((int) $receipt['id']),
            'movement'    => StockMovement::forReference('GOODS_RECEIPT', (int) $receipt['id']),
            'identity'    => \App\Services\SettingService::identity(),
            'orientation' => 'portrait',
            'backUrl'     => base_url('transaksi/masuk/' . (int) $receipt['id']),
        ], 'layouts/print');
    }

    /** GET /transaksi/masuk/{id}/bukti — mengirim bukti (privat, wajib login). */
    public function attachment(array $params = []): void
    {
        $receipt = GoodsReceipt::find((int) ($params['id'] ?? 0));
        if ($receipt === null || empty($receipt['attachment_path'])) {
            $this->abort(404, 'Bukti penerimaan tidak ditemukan.');
        }

        $path = (string) $receipt['attachment_path'];
        $meta = AttachmentService::describe($path, (string) ($receipt['attachment_name'] ?? ''));
        if ($meta === null) {
            $this->abort(404, 'Berkas bukti tidak ditemukan di penyimpanan.');
        }

        $absolute = AttachmentService::absolutePath($path);
        if ($absolute === null) {
            $this->abort(404, 'Berkas bukti tidak dapat diakses.');
        }

        // Hanya gambar/PDF yang diizinkan; selalu inline + nosniff (tidak dieksekusi).
        if (!headers_sent()) {
            header('Content-Type: ' . $meta['mime']);
            header('Content-Length: ' . $meta['size']);
            header('Content-Disposition: inline; filename="' . rawurlencode($meta['name']) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, no-store');
        }

        readfile($absolute);
        exit;
    }

    /** GET /pembelian/{id}/terima (alias rute Admin) �?" formulir penerimaan berdasarkan PO. */
    public function createFormPo(array $params = []): void
    {
        $poId = (int) ($params['id'] ?? 0);
        $po = PurchaseOrder::find($poId);
        if ($po === null) {
            $this->abort(404, 'Purchase Order tidak ditemukan.');
        }

        $openPos = array_values(array_filter(
            PurchaseOrder::openForReceipt(),
            static fn (array $p): bool => (int) $p['id'] === $poId
        ));

        $this->view('transactions/receipts/form', [
            'title'      => 'Terima Barang dari PO ' . (string) $po['po_number'],
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
            'suppliers'  => SupplierService::options(true),
            'openPos'    => $openPos,
            'formAction' => base_url('pembelian/' . $poId . '/terima'),
        ]);
    }

    /** POST /pembelian/{id}/terima (alias rute Admin) �?" simpan penerimaan berdasarkan PO. */
    public function storePo(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $poId = (int) ($params['id'] ?? 0);
        $input = $this->request->post();
        if ($poId > 0 && (int) ($input['purchase_order_id'] ?? 0) <= 0) {
            $input['purchase_order_id'] = (string) $poId;
        }
        $file = $_FILES['attachment'] ?? null;

        try {
            $receiptId = GoodsReceiptService::create($input, Auth::id(), is_array($file) ? $file : null);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('pembelian/' . $poId . '/terima'));
        }

        $receipt = GoodsReceipt::find($receiptId);

        try {
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'BARANG_MASUK',
                "SmartPartMan\nPenerimaan barang berhasil\nNomor transaksi: " . (string) ($receipt['receipt_number'] ?? '') . "\nTanggal: " . date('Y-m-d'),
                'RECEIPT:' . $receiptId
            );
        } catch (\Throwable $e) {
            // ignore
        }

        Session::flash(
            'success',
            'Penerimaan ' . (string) ($receipt['receipt_number'] ?? '') . ' berhasil disimpan. Stok sudah diperbarui.'
        );
        $this->redirect(base_url('transaksi/masuk/' . $receiptId));
    }
}
