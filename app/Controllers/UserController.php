<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\UserService;
use RuntimeException;

/**
 * Manajemen pengguna — HANYA Admin (dipaksa oleh RoleMiddleware pada route).
 *
 * Fitur (PRD §6.3, FR-01): daftar + filter, tambah, ubah, aktif/nonaktif,
 * reset password, dan buka kunci akun. Setiap perubahan tercatat di Audit Log.
 */
final class UserController extends Controller
{
    /** GET /pengguna */
    public function index(array $params = []): void
    {
        $filters = [
            'search' => trim((string) $this->request->input('q', '')),
            'role'   => (string) $this->request->input('role', ''),
            'status' => (string) $this->request->input('status', ''),
        ];

        $this->view('users/index', [
            'title'   => 'Manajemen Pengguna',
            'users'   => UserService::all(
                $filters['search'] !== '' ? $filters['search'] : null,
                $filters['role'] !== '' ? $filters['role'] : null,
                in_array($filters['status'], UserService::STATUSES, true) ? $filters['status'] : null
            ),
            'filters' => $filters,
            'roles'   => UserService::roles(),
            'stats'   => UserService::countByStatus(),
        ]);
    }

    /** GET /pengguna/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('users/form', [
            'title'  => 'Tambah Pengguna',
            'mode'   => 'create',
            'user'   => null,
            'roles'  => UserService::roles(),
            'old'    => Session::oldInput(),
        ]);
    }

    /** POST /pengguna */
    public function store(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'username'  => 'required|min:4|max:50|regex:^[A-Za-z0-9._-]+$',
            'full_name' => 'required|min:3|max:100',
            'email'     => 'email|max:100',
            'role'      => 'required|in:' . implode(',', UserService::ROLE_CODES),
            'password'  => 'required|min:' . UserService::PASSWORD_MIN . '|confirmed',
        ], [
            'username'  => 'Username',
            'full_name' => 'Nama lengkap',
            'email'     => 'Email',
            'role'      => 'Role',
            'password'  => 'Password',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('pengguna/tambah'));
        }

        $input = $validator->validated();

        try {
            UserService::create([
                'username'  => (string) $input['username'],
                'full_name' => (string) $input['full_name'],
                'email'     => $input['email'] ?? null,
                'role'      => (string) $input['role'],
                'password'  => (string) $input['password'],
            ], Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('pengguna/tambah'));
        }

        Session::flash('success', 'Pengguna "' . (string) $input['username'] . '" berhasil dibuat.');
        $this->redirect(base_url('pengguna'));
    }

    /** GET /pengguna/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $user = UserService::find((int) ($params['id'] ?? 0));
        if ($user === null) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }

        $this->view('users/form', [
            'title' => 'Ubah Pengguna',
            'mode'  => 'edit',
            'user'  => $user,
            'roles' => UserService::roles(),
            'old'   => [],
        ]);
    }

    /** POST /pengguna/{id} */
    public function update(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (UserService::find($id) === null) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }

        $validator = Validator::make($this->request->post(), [
            'username'  => 'required|min:4|max:50|regex:^[A-Za-z0-9._-]+$',
            'full_name' => 'required|min:3|max:100',
            'email'     => 'email|max:100',
            'role'      => 'required|in:' . implode(',', UserService::ROLE_CODES),
        ], [
            'username'  => 'Username',
            'full_name' => 'Nama lengkap',
            'email'     => 'Email',
            'role'      => 'Role',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('pengguna/' . $id . '/ubah'));
        }

        $input = $validator->validated();

        try {
            UserService::update($id, [
                'username'  => (string) $input['username'],
                'full_name' => (string) $input['full_name'],
                'email'     => $input['email'] ?? null,
                'role'      => (string) $input['role'],
            ]);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengguna/' . $id . '/ubah'));
        }

        Session::flash('success', 'Data pengguna berhasil diperbarui.');
        $this->redirect(base_url('pengguna'));
    }

    /** POST /pengguna/{id}/status — aktifkan / nonaktifkan */
    public function toggleStatus(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        $active = (string) $this->request->input('active', '0') === '1';

        try {
            UserService::setActive($id, $active, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengguna'));
        }

        Session::flash('success', $active ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.');
        $this->redirect(base_url('pengguna'));
    }

    /** GET /pengguna/{id}/reset-password */
    public function resetPasswordForm(array $params = []): void
    {
        $user = UserService::find((int) ($params['id'] ?? 0));
        if ($user === null) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }

        $this->view('users/reset_password', [
            'title' => 'Reset Password',
            'user'  => $user,
        ]);
    }

    /** POST /pengguna/{id}/reset-password */
    public function resetPassword(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        $user = UserService::find($id);
        if ($user === null) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }

        $validator = Validator::make($this->request->post(), [
            'password' => 'required|min:' . UserService::PASSWORD_MIN . '|confirmed',
        ], ['password' => 'Password baru']);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('pengguna/' . $id . '/reset-password'));
        }

        $input = $validator->validated();

        try {
            UserService::resetPassword($id, (string) $input['password']);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengguna/' . $id . '/reset-password'));
        }

        Session::flash('success', 'Password pengguna berhasil direset dan akun dibuka kuncinya (bila terkunci).');
        $this->redirect(base_url('pengguna'));
    }

    /** POST /pengguna/{id}/unlock */
    public function unlock(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        try {
            UserService::unlock((int) ($params['id'] ?? 0));
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengguna'));
        }

        Session::flash('success', 'Kunci akun berhasil dibuka.');
        $this->redirect(base_url('pengguna'));
    }

    private function requirePost(): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
    }
}
