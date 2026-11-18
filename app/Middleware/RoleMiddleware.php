<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Router;

/**
 * Pembatasan role per route (RBAC di level server, bukan sekadar sembunyikan tombol).
 *
 * Pemakaian di routes/web.php:
 *   $router->get('/user', [UserController::class, 'index'],
 *       [AuthMiddleware::class, [RoleMiddleware::class, 'admin']]);
 * atau gabungan: [AuthMiddleware::class, [RoleMiddleware::class, 'admin', 'partman']]
 */
final class RoleMiddleware
{
    /** @var string[] */
    private array $allowedRoles;

    public function __construct(string ...$roles)
    {
        $this->allowedRoles = $roles;
    }

    /**
     * Apakah role tertentu diizinkan? Dipisah dari handle() agar bisa
     * diuji langsung tanpa memicu halaman 403 (yang menghentikan proses).
     */
    public function allows(?string $role): bool
    {
        return $role !== null && in_array($role, $this->allowedRoles, true);
    }

    public function handle(Request $request, callable $next): void
    {
        if (!$this->allows(Auth::role())) {
            Router::error(403, 'Anda tidak memiliki hak akses untuk halaman ini.');
            return;
        }
        $next();
    }

    /** @return string[] Role yang diizinkan (untuk pengujian/dokumentasi). */
    public function allowedRoles(): array
    {
        return $this->allowedRoles;
    }
}
