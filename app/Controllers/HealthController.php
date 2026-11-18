<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\HealthService;

/**
 * Health Check (Fase 1, butir 11) — verifikasi PHP & database tanpa rahasia.
 *
 * GET /health → JSON
 * - Tanpa login : hanya status boolean/enum (aman dipantau/dibagikan).
 * - Admin login : ditambah diagnostik teknis (versi PHP/DB, ekstensi hilang,
 *                 jumlah migrasi tertunda, pesan error DB).
 *                 Tetap TANPA password DB / APP_KEY / path internal.
 *
 * Kode HTTP: 200 = sehat, 503 = degraded (memudahkan pemantauan).
 */
final class HealthController extends Controller
{
    /** GET /health
     *  @param array<string,string> $params */
    public function index(array $params = []): void
    {
        $detailed = Auth::check() && Auth::hasRole('admin');
        $snapshot = HealthService::check($detailed);

        $this->json($snapshot, $snapshot['status'] === 'ok' ? 200 : 503);
    }
}
