<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\PurchaseOrder;
use App\Services\PdfService;
use App\Services\ReportService;
use App\Services\SettingService;
use App\Services\Import\XlsxWriter;
use RuntimeException;

/**
 * Laporan Fase 5: tampilan, cetak, PDF, Excel, dan dokumen PO.
 *
 * Hak akses: semua role boleh MELIHAT/mengunduh (tidak ada operasi tulis).
 * Otorisasi tetap diperiksa middleware di route — bukan hanya menu.
 */
final class ReportController extends Controller
{
    /** GET /laporan */
    public function index(array $params = []): void
    {
        $reports = ReportService::allMeta();

        $this->view('reports/index', [
            'title'    => 'Laporan',
            'reports'  => $reports,
            'identity' => SettingService::identity(),
        ]);
    }

    /** GET /laporan/{jenis} */
    public function show(array $params = []): void
    {
        $type = (string) ($params['jenis'] ?? '');
        $report = $this->buildOr404($type);
        $options = ReportService::filterOptions();

        $this->view('reports/show', [
            'title'     => (string) $report['meta']['title'],
            'report'    => $report,
            'categories' => $options['categories'],
            'suppliers' => $options['suppliers'],
            'identity'  => SettingService::identity(),
            'query'     => $this->queryString($report['filters']),
        ]);
    }

    /** GET /laporan/{jenis}/cetak */
    public function print(array $params = []): void
    {
        $type = (string) ($params['jenis'] ?? '');
        $report = $this->buildOr404($type);
        $identity = SettingService::identity();

        $this->view('reports/print', [
            'title'       => (string) $report['meta']['title'],
            'report'      => $report,
            'identity'    => $identity,
            'orientation' => (string) ($report['meta']['orientation'] ?? 'landscape'),
            'backUrl'     => base_url('laporan/' . $type . '?' . $this->queryString($report['filters'])),
            'pdfUrl'      => base_url('laporan/' . $type . '/pdf?' . $this->queryString($report['filters'])),
        ], 'layouts/print');
    }

    /** GET /laporan/{jenis}/pdf */
    public function pdf(array $params = []): void
    {
        $type = (string) ($params['jenis'] ?? '');
        $report = $this->buildOr404($type);
        $identity = SettingService::identity();

        $binary = PdfService::report(ReportService::pdfOptions($report, $identity));

        $this->sendDownload(
            $binary,
            'application/pdf',
            'laporan-' . $type . '-' . date('Ymd') . '.pdf'
        );
    }

    /** GET /laporan/{jenis}/excel */
    public function excel(array $params = []): void
    {
        $type = (string) ($params['jenis'] ?? '');
        $report = $this->buildOr404($type);

        try {
            $binary = XlsxWriter::build(
                ReportService::exportRows($report),
                (string) $report['meta']['short']
            );
        } catch (RuntimeException $e) {
            \App\Core\Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('laporan/' . $type . '?' . $this->queryString($report['filters'])));
        }

        $this->sendDownload(
            $binary,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'laporan-' . $type . '-' . date('Ymd') . '.xlsx'
        );
    }

    /** GET /laporan/po/{id}/cetak — dokumen Purchase Order. */
    public function printPo(array $params = []): void
    {
        $po = $this->poOr404((int) ($params['id'] ?? 0));
        $items = PurchaseOrder::items((int) $po['id']);

        $this->view('documents/po', [
            'title'       => 'Purchase Order ' . (string) $po['po_number'],
            'po'          => $po,
            'items'       => $items,
            'identity'    => SettingService::identity(),
            'orientation' => 'portrait',
            'backUrl'     => base_url('laporan/pembelian'),
            'pdfUrl'      => base_url('laporan/po/' . (int) $po['id'] . '/pdf'),
        ], 'layouts/print');
    }

    /** GET /laporan/po/{id}/pdf */
    public function pdfPo(array $params = []): void
    {
        $po = $this->poOr404((int) ($params['id'] ?? 0));
        $items = PurchaseOrder::items((int) $po['id']);
        $identity = SettingService::identity();

        $columns = [
            ['label' => 'Kode', 'width' => 16],
            ['label' => 'Nama Barang', 'width' => 38],
            ['label' => 'Qty', 'width' => 12, 'align' => 'right'],
            ['label' => 'Satuan', 'width' => 12],
            ['label' => 'Harga', 'width' => 20, 'align' => 'right'],
            ['label' => 'Subtotal', 'width' => 22, 'align' => 'right'],
        ];
        $rows = [];
        $total = 0.0;
        foreach ($items as $item) {
            $subtotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $total += $subtotal;
            $rows[] = [
                (string) $item['sparepart_code'],
                (string) $item['sparepart_name'],
                \App\Services\ReportService::formatCell(['type' => 'decimal'], $item['quantity']),
                (string) $item['unit'],
                rupiah((float) $item['unit_price']),
                rupiah($subtotal),
            ];
        }
        $rows[] = ['', 'TOTAL', '', '', '', rupiah($total)];

        $binary = PdfService::report([
            'identity'    => $identity,
            'title'       => 'Purchase Order',
            'subtitle'    => 'No. ' . (string) $po['po_number'] . ' · ' . tanggal_id((string) $po['created_at']),
            'summary'     => [
                ['label' => 'Supplier', 'value' => (string) $po['supplier_name']],
                ['label' => 'Status', 'value' => \App\Services\ReportService::label('po', $po['status'])],
                ['label' => 'Pembayaran', 'value' => \App\Services\ReportService::label('payment', $po['payment_status'])],
                ['label' => 'Total', 'value' => rupiah($total)],
            ],
            'columns'     => $columns,
            'rows'        => $rows,
            'notes'       => array_filter([
                (string) ($po['note'] ?? '') !== '' ? 'Catatan: ' . (string) $po['note'] : null,
                'Dibuat oleh: ' . (string) ($po['created_by_name'] ?? '-'),
                'Status PO hanya dapat diubah melalui modul pembelian (Fase 6).',
            ]),
            'orientation' => 'portrait',
            'footer_left' => 'SmartPartMan · ' . $identity['name'],
        ]);

        $this->sendDownload(
            $binary,
            'application/pdf',
            'po-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $po['po_number']) . '.pdf'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOr404(string $type): array
    {
        if (!in_array($type, ReportService::TYPES, true)) {
            $this->abort(404, 'Jenis laporan tidak dikenal.');
        }
        try {
            return ReportService::build($type, $this->request->all());
        } catch (RuntimeException $e) {
            $this->abort(404, $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function poOr404(int $id): array
    {
        $po = PurchaseOrder::find($id);
        if ($po === null) {
            $this->abort(404, 'Purchase Order tidak ditemukan.');
        }

        // Cakupan dokumen PO sama dengan detail PO: Partman hanya miliknya,
        // Kepala Bengkel hanya PO yang sudah diajukan (bukan draf).
        $role = (string) Auth::role();
        if ($role === 'partman' && (int) $po['created_by'] !== (int) Auth::id()) {
            $this->abort(403, 'Anda hanya dapat mengakses PO yang Anda ajukan.');
        }
        if ($role === 'kepala_bengkel' && (string) $po['status'] === PurchaseOrder::STATUS_DRAFT) {
            $this->abort(403, 'PO ini masih draf dan belum diajukan.');
        }

        return $po;
    }

    /** @param array<string,mixed> $filters */
    private function queryString(array $filters): string
    {
        $clean = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        return http_build_query($clean);
    }

    private function sendDownload(string $binary, string $mime, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($binary));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo $binary;
        exit;
    }
}
