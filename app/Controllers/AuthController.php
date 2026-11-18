<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\AuthService;

/**
 * Autentikasi: login & logout (PRD FR-01).
 * Penguncian 5x gagal, pesan aman, dan audit ditangani AuthService.
 */
final class AuthController extends Controller
{
    /** GET /login */
    public function showLogin(array $params = []): void
    {
        $this->view('auth/login', [], 'layouts/auth');
    }

    /** POST /login */
    public function login(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $username = trim((string) $this->request->input('username', ''));
        $password = (string) $this->request->input('password', '');

        if ($username === '' || $password === '') {
            Session::flash('danger', 'Username dan password wajib diisi.');
            $this->redirect(base_url('login'));
        }

        $result = AuthService::attempt($username, $password, $this->request->ip());

        if ($result['ok']) {
            Session::flash('success', $result['message']);
            $this->redirect(base_url('dashboard'));
        }

        // reason: locked/inactive → warning (informatif), selain itu danger (generik).
        $type = in_array($result['reason'], [AuthService::REASON_LOCKED, AuthService::REASON_INACTIVE], true)
            ? 'warning'
            : 'danger';
        Session::flash($type, $result['message']);
        $this->redirect(base_url('login'));
    }

    /** POST /logout */
    public function logout(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        AuthService::logout();
        Session::flash('info', 'Anda telah logout. Sampai jumpa.');
        $this->redirect(base_url('login'));
    }
}
