<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\CategoryService;
use RuntimeException;

/**
 * Kategori sparepart (FR-12).
 *
 * Hak akses (PRD §6.4):
 *   - Melihat daftar  : semua role (Partman, Kepala Bengkel, Admin).
 *   - Tambah/ubah/status: HANYA Partman (dipaksa RoleMiddleware di route).
 *
 * Kategori TIDAK pernah dihapus — hanya diaktifkan/dinonaktifkan.
 */
final class CategoryController extends Controller
{
    /** GET /kategori */
    public function index(array $params = []): void
    {
        $filters = [
            'search' => trim((string) $this->request->input('q', '')),
            'status' => (string) $this->request->input('status', ''),
        ];

        $this->view('master/categories/index', [
            'title'      => 'Kategori Sparepart',
            'canManage'  => Auth::hasRole('partman'),
            'categories' => CategoryService::all(
                $filters['search'] !== '' ? $filters['search'] : null,
                in_array($filters['status'], ['aktif', 'nonaktif'], true) ? $filters['status'] : null
            ),
            'filters'    => $filters,
        ]);
    }

    /** GET /kategori/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('master/categories/form', [
            'title' => 'Tambah Kategori',
            'mode'  => 'create',
            'row'   => null,
            'old'   => Session::oldInput(),
        ]);
    }

    /** POST /kategori */
    public function store(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'code'        => 'required|max:' . CategoryService::CODE_MAX,
            'name'        => 'required|max:' . CategoryService::NAME_MAX,
            'description' => 'max:255',
        ], ['code' => 'Kode kategori', 'name' => 'Nama kategori', 'description' => 'Keterangan']);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('kategori/tambah'));
        }

        $input = $validator->validated();

        try {
            CategoryService::create([
                'code'        => (string) $input['code'],
                'name'        => (string) $input['name'],
                'description' => (string) ($input['description'] ?? ''),
            ], Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('kategori/tambah'));
        }

        Session::flash('success', 'Kategori berhasil ditambahkan.');
        $this->redirect(base_url('kategori'));
    }

    /** GET /kategori/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $category = CategoryService::find((int) ($params['id'] ?? 0));
        if ($category === null) {
            $this->abort(404, 'Kategori tidak ditemukan.');
        }

        $this->view('master/categories/form', [
            'title' => 'Ubah Kategori',
            'mode'  => 'edit',
            'row'   => $category,
            'old'   => [],
        ]);
    }

    /** POST /kategori/{id} */
    public function update(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (CategoryService::find($id) === null) {
            $this->abort(404, 'Kategori tidak ditemukan.');
        }

        $validator = Validator::make($this->request->post(), [
            'code'        => 'required|max:' . CategoryService::CODE_MAX,
            'name'        => 'required|max:' . CategoryService::NAME_MAX,
            'description' => 'max:255',
        ], ['code' => 'Kode kategori', 'name' => 'Nama kategori', 'description' => 'Keterangan']);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('kategori/' . $id . '/ubah'));
        }

        $input = $validator->validated();

        try {
            CategoryService::update($id, [
                'code'        => (string) $input['code'],
                'name'        => (string) $input['name'],
                'description' => (string) ($input['description'] ?? ''),
            ], Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('kategori/' . $id . '/ubah'));
        }

        Session::flash('success', 'Kategori berhasil diperbarui.');
        $this->redirect(base_url('kategori'));
    }

    /** POST /kategori/{id}/status */
    public function toggleStatus(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $active = (string) $this->request->input('active', '0') === '1';

        try {
            CategoryService::setActive((int) ($params['id'] ?? 0), $active, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('kategori'));
        }

        Session::flash('success', $active ? 'Kategori diaktifkan.' : 'Kategori dinonaktifkan.');
        $this->redirect(base_url('kategori'));
    }

    private function requirePost(): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
    }
}
