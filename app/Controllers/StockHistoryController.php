<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Sparepart;
use App\Models\StockMovement;

/**
 * Riwayat pergerakan stok / kartu stok global (FR-08).
 *
 * Semua role dapat MELIHAT (PRD §6.4 "Stok dan riwayat: Lihat/operasional"):
 * Partman operasional, Kepala Bengkel memantau, Admin melihat.
 * Tidak ada route tulis di sini.
 */
final class StockHistoryController extends Controller
{
    private const PER_PAGE = 50;

    /** GET /transaksi/riwayat */
    public function index(array $params = []): void
    {
        $filters = [
            'search'        => trim((string) $this->request->input('q', '')),
            'sparepart_id'  => trim((string) $this->request->input('sparepart', '')),
            'movement_type' => trim((string) $this->request->input('jenis', '')),
            'reference_type' => trim((string) $this->request->input('sumber', '')),
            'date_from'     => trim((string) $this->request->input('dari', '')),
            'date_to'       => trim((string) $this->request->input('sampai', '')),
        ];

        $page = max(1, (int) $this->request->input('page', 1));
        $total = StockMovement::countFiltered($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('transactions/history/index', [
            'title'      => 'Riwayat Pergerakan Stok',
            'canManage'  => Auth::hasRole('partman'),
            'filters'    => $filters,
            'rows'       => StockMovement::filtered($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'totals'     => StockMovement::totals($filters),
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'spareparts' => Sparepart::options(false),
            'types'      => StockMovement::TYPES,
        ]);
    }
}
