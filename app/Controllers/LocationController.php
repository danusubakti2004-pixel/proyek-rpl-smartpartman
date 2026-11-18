<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\StorageLocationService;
use RuntimeException;

/**
 * Lokasi penyimpanan (FR-09): satu gudang dengan banyak rak/tingkat.
 *
 * Hak akses (PRD §6.4): melihat = semua role; menambah/mengubah = HANYA Partman.
 */
final class LocationController extends Controller
{
    /** GET /lokasi */
    public function index(array $params = []): void
    {
        $filters = [
            'search' => trim((string) $this->request->input('q', '')),
            'status' => (string) $this->request->input('status', ''),
        ];

        $this->view('master/locations/index', [
            'title'     => 'Lokasi Penyimpanan',
            'canManage' => Auth::hasRole('partman'),
            'locations' => StorageLocationService::all(
                $filters['search'] !== '' ? $filters['search'] : null,
                in_array($filters['status'], ['aktif', 'nonaktif'], true) ? $filters['status'] : null
            ),
            'filters'   => $filters,
        ]);
    }

    /** GET /lokasi/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('master/locations/form', [
            'title' => 'Tambah Lokasi',
            'mode'  => 'create',
            'row'   => null,
            'old'   => Session::oldInput(),
        ]);
    }

    /** POST /lokasi */
    public function store(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'code'        => 'required|max:' . StorageLocationService::CODE_MAX,
            'name'        => 'required|max:' . StorageLocationService::NAME_MAX,
            'description' => 'max:255',
        ], ['code' => 'Kode lokasi', 'name' => 'Nama lokasi', 'description' => 'Keterangan']);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('lokasi/tambah'));
        }

        $input = $validator->validated();

        try {
            StorageLocationService::create([
                'code'        => (string) $input['code'],
                'name'        => (string) $input['name'],
                'description' => (string) ($input['description'] ?? ''),
            ], Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('lokasi/tambah'));
        }

        Session::flash('success', 'Lokasi penyimpanan berhasil ditambahkan.');
        $this->redirect(base_url('lokasi'));
    }

    /** GET /lokasi/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $location = StorageLocationService::find((int) ($params['id'] ?? 0));
        if ($location === null) {
            $this->abort(404, 'Lokasi penyimpanan tidak ditemukan.');
        }

        $this->view('master/locations/form', [
            'title' => 'Ubah Lokasi',
            'mode'  => 'edit',
            'row'   => $location,
            'old'   => [],
        ]);
    }

    /** POST /lokasi/{id} */
    public function update(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (StorageLocationService::find($id) === null) {
            $this->abort(404, 'Lokasi penyimpanan tidak ditemukan.');
        }

        $validator = Validator::make($this->request->post(), [
            'code'        => 'required|max:' . StorageLocationService::CODE_MAX,
            'name'        => 'required|max:' . StorageLocationService::NAME_MAX,
            'description' => 'max:255',
        ], ['code' => 'Kode lokasi', 'name' => 'Nama lokasi', 'description' => 'Keterangan']);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('lokasi/' . $id . '/ubah'));
        }

        $input = $validator->validated();

        try {
            StorageLocationService::update($id, [
                'code'        => (string) $input['code'],
                'name'        => (string) $input['name'],
                'description' => (string) ($input['description'] ?? ''),
            ], Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('lokasi/' . $id . '/ubah'));
        }

        Session::flash('success', 'Lokasi penyimpanan berhasil diperbarui.');
        $this->redirect(base_url('lokasi'));
    }

    /** POST /lokasi/{id}/status */
    public function toggleStatus(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $active = (string) $this->request->input('active', '0') === '1';

        try {
            StorageLocationService::setActive((int) ($params['id'] ?? 0), $active, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('lokasi'));
        }

        Session::flash('success', $active ? 'Lokasi diaktifkan.' : 'Lokasi dinonaktifkan.');
        $this->redirect(base_url('lokasi'));
    }

    private function requirePost(): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
    }
}
