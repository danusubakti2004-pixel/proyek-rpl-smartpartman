<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\DashboardService;
use App\Services\NotificationService;
use App\Services\SettingService;

/**
 * Dashboard per role (Fase 5).
 *
 * - Partman        : ringkasan operasional harian, stok kritis, grafik,
 *                    transaksi terbaru, rekomendasi reorder (placeholder AI).
 * - Kepala Bengkel : nilai persediaan, pemakaian, stok kritis, grafik,
 *                    ringkasan laporan/pembelian, placeholder AI.
 * - Admin          : pengguna, sparepart, statistik transaksi, aktivitas,
 *                    status sistem, akses pengaturan & chatbot.
 *
 * Semua data dihitung DashboardService langsung dari database. Notifikasi
 * hanya ditampilkan di dashboard dan disesuaikan dengan hak akses.
 */
final class HomeController extends Controller
{
    /** GET / → arahkan sesuai status login. */
    public function root(array $params = []): void
    {
        if (!Auth::check()) {
            $this->redirect(base_url('login'));
        }
        $this->redirect(base_url('dashboard'));
    }

    /** GET /dashboard */
    public function index(array $params = []): void
    {
        $user = Auth::user();
        $role = (string) ($user['role'] ?? '');

        $this->view('dashboard/index', [
            'title'         => 'Dashboard',
            'user'          => $user,
            'role'          => $role,
            'dashboard'     => DashboardService::forRole($role),
            'notifications' => NotificationService::forRole($role),
            'identity'      => SettingService::identity(),
        ]);
    }
}
