<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\InstallService;

/**
 * Halaman instalasi awal: membuat Admin pertama (PRD FR-01).
 * Setelah admin dibuat, App::run() mengunci route ini.
 */
final class InstallController extends Controller
{
    /** GET /install */
    public function index(array $params = []): void
    {
        $dbStatus = InstallService::dbStatus();
        $this->view('install/index', [
            'dbStatus'   => $dbStatus,
            'tablesReady' => InstallService::tablesReady(),
        ], 'layouts/auth');
    }

    /** POST /install */
    public function store(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'username' => 'required|min:4|max:50',
            'full_name' => 'required|min:3|max:100',
            'email' => 'email|max:100',
            'password' => 'required|min:8|confirmed',
        ], [
            'username' => 'Username',
            'full_name' => 'Nama lengkap',
            'email' => 'Email',
            'password' => 'Password',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('install'));
        }

        $input = $validator->validated();

        try {
            InstallService::createAdmin([
                'username'  => (string) $input['username'],
                'full_name' => (string) $input['full_name'],
                'email'     => $input['email'] ?? null,
                'password'  => (string) $input['password'],
            ]);
        } catch (\RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('install'));
        }

        Session::flash('success', 'Admin pertama berhasil dibuat. Silakan login.');
        $this->redirect(base_url('login'));
    }
}
