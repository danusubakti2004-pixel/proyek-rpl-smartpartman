<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\SupplierService;
use RuntimeException;

/**
 * Supplier (FR-11).
 *
 * Hak akses (PRD §6.4):
 *   - Melihat daftar  : semua role.
 *   - Tambah/ubah/status: HANYA Partman (dipaksa RoleMiddleware di route).
 *
 * Supplier yang pernah dipakai TIDAK dihapus permanen — hanya dinonaktifkan,
 * dan supplier nonaktif tidak dapat dipakai untuk transaksi baru (Fase 4).
 */
final class SupplierController extends Controller
{
    /** GET /supplier */
    public function index(array $params = []): void
    {
        $filters = [
            'search' => trim((string) $this->request->input('q', '')),
            'status' => (string) $this->request->input('status', ''),
        ];

        $this->view('master/suppliers/index', [
            'title'     => 'Supplier',
            'canManage' => Auth::hasRole('partman'),
            'suppliers' => SupplierService::all(
                $filters['search'] !== '' ? $filters['search'] : null,
                in_array($filters['status'], ['aktif', 'nonaktif'], true) ? $filters['status'] : null
            ),
            'filters'   => $filters,
        ]);
    }

    /** GET /supplier/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('master/suppliers/form', [
            'title' => 'Tambah Supplier',
            'mode'  => 'create',
            'row'   => null,
            'old'   => Session::oldInput(),
        ]);
    }

    /** POST /supplier */
    public function store(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'code'           => 'required|max:' . SupplierService::CODE_MAX,
            'name'           => 'required|max:' . SupplierService::NAME_MAX,
            'address'        => 'max:255',
            'phone'          => 'max:30',
            'email'          => 'email|max:100',
            'contact_person' => 'max:100',
            'lead_time_days' => 'integer|min:0|max:3650',
        ], [
            'code'           => 'Kode supplier',
            'name'           => 'Nama perusahaan',
            'address'        => 'Alamat',
            'phone'          => 'Telepon',
            'email'          => 'Email',
            'contact_person' => 'Contact person',
            'lead_time_days' => 'Lead time',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('supplier/tambah'));
        }

        try {
            SupplierService::create($this->clean($validator->validated()), Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('supplier/tambah'));
        }

        Session::flash('success', 'Supplier berhasil ditambahkan.');
        $this->redirect(base_url('supplier'));
    }

    /** GET /supplier/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $supplier = SupplierService::find((int) ($params['id'] ?? 0));
        if ($supplier === null) {
            $this->abort(404, 'Supplier tidak ditemukan.');
        }

        $this->view('master/suppliers/form', [
            'title' => 'Ubah Supplier',
            'mode'  => 'edit',
            'row'   => $supplier,
            'old'   => [],
        ]);
    }

    /** POST /supplier/{id} */
    public function update(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (SupplierService::find($id) === null) {
            $this->abort(404, 'Supplier tidak ditemukan.');
        }

        $validator = Validator::make($this->request->post(), [
            'code'           => 'required|max:' . SupplierService::CODE_MAX,
            'name'           => 'required|max:' . SupplierService::NAME_MAX,
            'address'        => 'max:255',
            'phone'          => 'max:30',
            'email'          => 'email|max:100',
            'contact_person' => 'max:100',
            'lead_time_days' => 'integer|min:0|max:3650',
        ], [
            'code'           => 'Kode supplier',
            'name'           => 'Nama perusahaan',
            'address'        => 'Alamat',
            'phone'          => 'Telepon',
            'email'          => 'Email',
            'contact_person' => 'Contact person',
            'lead_time_days' => 'Lead time',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('supplier/' . $id . '/ubah'));
        }

        try {
            SupplierService::update($id, $this->clean($validator->validated()), Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('supplier/' . $id . '/ubah'));
        }

        Session::flash('success', 'Data supplier berhasil diperbarui.');
        $this->redirect(base_url('supplier'));
    }

    /** POST /supplier/{id}/status */
    public function toggleStatus(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $active = (string) $this->request->input('active', '0') === '1';

        try {
            SupplierService::setActive((int) ($params['id'] ?? 0), $active, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('supplier'));
        }

        Session::flash('success', $active ? 'Supplier diaktifkan.' : 'Supplier dinonaktifkan.');
        $this->redirect(base_url('supplier'));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function clean(array $input): array
    {
        return [
            'code'           => (string) $input['code'],
            'name'           => (string) $input['name'],
            'address'        => (string) ($input['address'] ?? ''),
            'phone'          => (string) ($input['phone'] ?? ''),
            'email'          => (string) ($input['email'] ?? ''),
            'contact_person' => (string) ($input['contact_person'] ?? ''),
            'lead_time_days' => $input['lead_time_days'] ?? null,
        ];
    }

    private function requirePost(): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
    }
}
