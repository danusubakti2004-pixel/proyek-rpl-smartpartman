<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;

/**
 * Hanya untuk pengguna yang BELUM login (halaman login/installer).
 */
final class GuestMiddleware
{
    public function handle(Request $request, callable $next): void
    {
        if (Auth::check()) {
            App::redirect(base_url('dashboard'));
        }
        $next();
    }
}
