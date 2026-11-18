<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\GoodsIssue;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\GoodsIssueService;
use App\Services\WhatsAppNotificationService;
use RuntimeException;

/**
 * Barang keluar (FR-07).
 *
 * Hak akses (PRD §6.4):
 *   - Daftar & detail : semua role.
 *   - Catat pengeluaran: HANYA Partman (dipaksa RoleMiddleware di route).
 *
 * Penolakan "qty melebihi stok tersedia" terjadi di dalam transaksi
 * (GoodsIssueService → StockLedger) sehingga stok tidak pernah negatif.
 */
final class GoodsIssueController extends Controller
{
    private const PER_PAGE = 25;

    /** GET /transaksi/keluar */
    public function index(array $params = []): void
    {
        $filters = [
            'search'     => trim((string) $this->request->input('q', '')),
            'usage_type' => trim((string) $this->request->input('jenis', '')),
            'date_from'  => trim((string) $this->request->input('dari', '')),
            'date_to'    => trim((string) $this->request->input('sampai', '')),
        ];

        $result = GoodsIssue::paginate($filters, (int) $this->request->input('page', 1), self::PER_PAGE);

        $this->view('transactions/issues/index', [
            'title'     => 'Barang Keluar',
            'canManage' => Auth::hasRole('partman'),
            'filters'   => $filters,
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $result['page'],
            'pages'     => $result['pages'],
        ]);
    }

    /** GET /transaksi/keluar/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('transactions/issues/form', [
            'title'      => 'Catat Barang Keluar',
            'old'        => Session::oldInput(),
            'spareparts' => Sparepart::options(true),
        ]);
    }

    /** POST /transaksi/keluar */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();

        try {
            $issueId = GoodsIssueService::create($input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('transaksi/keluar/tambah'));
        }

        $issue = GoodsIssue::find($issueId);

        try {
            \App\Services\WhatsAppNotificationService::notifyAllRecipients(
                'BARANG_KELUAR',
                "SmartPartMan\nBarang keluar\nNomor transaksi: " . (string) ($issue['issue_number'] ?? '') . "\nTanggal: " . date('Y-m-d'),
                'ISSUE:' . $issueId
            );
        } catch (\Throwable $e) {
            // ignore
        }

        Session::flash(
            'success',
            'Pengeluaran ' . (string) ($issue['issue_number'] ?? '') . ' berhasil disimpan. Stok sudah diperbarui.'
        );
        $this->redirect(base_url('transaksi/keluar/' . $issueId));
    }

    /** GET /transaksi/keluar/{id} */
    public function show(array $params = []): void
    {
        $issue = GoodsIssue::find((int) ($params['id'] ?? 0));
        if ($issue === null) {
            $this->abort(404, 'Pengeluaran tidak ditemukan.');
        }

        $this->view('transactions/issues/show', [
            'title'     => 'Detail Pengeluaran ' . (string) $issue['issue_number'],
            'canManage' => Auth::hasRole('partman'),
            'row'       => $issue,
            'items'     => GoodsIssue::items((int) $issue['id']),
            'movement'  => StockMovement::forReference('GOODS_ISSUE', (int) $issue['id']),
        ]);
    }

    /** GET /transaksi/keluar/{id}/cetak — bukti pengeluaran (semua role). */
    public function print(array $params = []): void
    {
        $issue = GoodsIssue::find((int) ($params['id'] ?? 0));
        if ($issue === null) {
            $this->abort(404, 'Pengeluaran tidak ditemukan.');
        }

        $this->view('documents/issue', [
            'title'       => 'Bukti Barang Keluar ' . (string) $issue['issue_number'],
            'row'         => $issue,
            'items'       => GoodsIssue::items((int) $issue['id']),
            'movement'    => StockMovement::forReference('GOODS_ISSUE', (int) $issue['id']),
            'identity'    => \App\Services\SettingService::identity(),
            'orientation' => 'portrait',
            'backUrl'     => base_url('transaksi/keluar/' . (int) $issue['id']),
        ], 'layouts/print');
    }
}
