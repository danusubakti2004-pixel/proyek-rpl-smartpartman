<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;

/**
 * Wajib login. Dipasang pada setiap route yang membutuhkan sesi.
 */
final class AuthMiddleware
{
    public function handle(Request $request, callable $next): void
    {
        if (!Auth::check()) {
            Session::flash('warning', 'Silakan login terlebih dahulu.');
            App::redirect(base_url('login'));
        }
        $next();
    }
}
