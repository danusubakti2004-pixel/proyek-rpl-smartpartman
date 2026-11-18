<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\CategoryService;
use App\Services\MasterRules;
use App\Services\SparepartService;
use App\Services\StockInitialService;
use App\Services\StorageLocationService;
use App\Services\SupplierService;
use RuntimeException;

/**
 * Master sparepart (FR-03).
 *
 * Hak akses (PRD §6.4):
 *   - Daftar & detail : semua role.
 *   - Tambah/ubah/status, stok awal: HANYA Partman.
 *
 * Penting: formulir ini TIDAK pernah menulis kolom `stock` secara langsung —
 * stok awal hanya masuk lewat StockInitialService (tercatat di ledger).
 */
final class SparepartController extends Controller
{
    /** GET /sparepart */
    public function index(array $params = []): void
    {
        $filters = [
            'search'       => trim((string) $this->request->input('q', '')),
            'category_id'  => (string) $this->request->input('kategori', ''),
            'supplier_id'  => (string) $this->request->input('supplier', ''),
            'location_id'  => (string) $this->request->input('lokasi', ''),
            'stock_status' => (string) $this->request->input('status_stok', ''),
            'active'       => (string) $this->request->input('status', ''),
        ];

        $this->view('master/spareparts/index', [
            'title'      => 'Master Sparepart',
            'canManage'  => Auth::hasRole('partman'),
            'spareparts' => SparepartService::all($filters),
            'filters'    => $filters,
            'stats'      => SparepartService::countByStatus(),
            'categories' => CategoryService::options(false),
            'suppliers'  => SupplierService::options(false),
            'locations'  => StorageLocationService::options(false),
        ]);
    }

    /** GET /sparepart/tambah */
    public function createForm(array $params = []): void
    {
        $this->view('master/spareparts/form', [
            'title'      => 'Tambah Sparepart',
            'mode'       => 'create',
            'row'        => null,
            'old'        => Session::oldInput(),
            'categories' => CategoryService::options(true),
            'suppliers'  => SupplierService::options(true),
            'locations'  => StorageLocationService::options(true),
        ]);
    }

    /** POST /sparepart */
    public function store(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validated = $this->validateForm(true);
        if ($validated === null) {
            $this->redirect(base_url('sparepart/tambah'));
        }

        try {
            $id = SparepartService::create($validated, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->request->post());
            $this->redirect(base_url('sparepart/tambah'));
        }

        Session::flash('success', 'Sparepart berhasil ditambahkan.');
        $this->redirect(base_url('sparepart/' . $id));
    }

    /** GET /sparepart/{id} */
    public function show(array $params = []): void
    {
        $sparepart = SparepartService::find((int) ($params['id'] ?? 0));
        if ($sparepart === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $this->view('master/spareparts/show', [
            'title'     => 'Detail Sparepart',
            'canManage' => Auth::hasRole('partman'),
            'row'       => $sparepart,
            'status'    => SparepartService::stockStatusOf($sparepart),
            'movements' => StockMovement::latestFor((int) $sparepart['id'], 20),
            'initial'   => StockInitialService::initialFor((int) $sparepart['id']),
            'purchases' => Sparepart::purchasePriceHistory((int) $sparepart['id'], 10),
        ]);
    }

    /** GET /sparepart/{id}/ubah */
    public function editForm(array $params = []): void
    {
        $sparepart = SparepartService::find((int) ($params['id'] ?? 0));
        if ($sparepart === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $this->view('master/spareparts/form', [
            'title'      => 'Ubah Sparepart',
            'mode'       => 'edit',
            'row'        => $sparepart,
            'old'        => [],
            'categories' => self::withCurrent(CategoryService::options(true), $sparepart, 'category'),
            'suppliers'  => self::withCurrent(SupplierService::options(true), $sparepart, 'supplier'),
            'locations'  => self::withCurrent(StorageLocationService::options(true), $sparepart, 'location'),
        ]);
    }

    /** POST /sparepart/{id} */
    public function update(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $id = (int) ($params['id'] ?? 0);
        if (SparepartService::find($id) === null) {
            $this->abort(404, 'Sparepart tidak ditemukan.');
        }

        $validated = $this->validateForm(false);
        if ($validated === null) {
            $this->redirect(base_url('sparepart/' . $id . '/ubah'));
        }

        try {
            SparepartService::update($id, $validated, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('sparepart/' . $id . '/ubah'));
        }

        Session::flash('success', 'Data sparepart berhasil diperbarui.');
        $this->redirect(base_url('sparepart/' . $id));
    }

    /** POST /sparepart/{id}/status */
    public function toggleStatus(array $params = []): void
    {
        $this->requirePost();
        Csrf::verifyOrAbort($this->request->input('_token'));

        $active = (string) $this->request->input('active', '0') === '1';

        try {
            SparepartService::setActive((int) ($params['id'] ?? 0), $active, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('sparepart'));
        }

        Session::flash('success', $active ? 'Sparepart diaktifkan.' : 'Sparepart dinonaktifkan.');
        $this->redirect(base_url('sparepart'));
    }

    // ------------------------------------------------------------------

    /**
     * Validasi + normalisasi formulir sparepart.
     * Mengembalikan null (dan sudah menampilkan pesan) bila ada yang salah.
     *
     * @return array<string,mixed>|null
     */
    private function validateForm(bool $withInitialStock): ?array
    {
        $validator = Validator::make($this->request->post(), [
            'code'          => 'required|max:' . SparepartService::CODE_MAX,
            'name'          => 'required|max:' . SparepartService::NAME_MAX,
            'category_id'   => 'required|integer',
            'brand'         => 'max:100',
            'vehicle_model' => 'max:100',
            'unit'          => 'max:' . SparepartService::UNIT_MAX,
            'supplier_id'   => 'integer',
            'location_id'   => 'integer',
        ], [
            'code'          => 'Kode sparepart',
            'name'          => 'Nama sparepart',
            'category_id'   => 'Kategori',
            'brand'         => 'Brand',
            'vehicle_model' => 'Model kendaraan',
            'unit'          => 'Satuan',
            'supplier_id'   => 'Supplier',
            'location_id'   => 'Lokasi',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            Session::flashInput($this->request->post());
            return null;
        }

        $numbers = [];
        $money = [
            'purchase_price' => 'Harga beli',
            'selling_price'  => 'Harga jual',
            'min_stock'      => 'Stok minimum',
        ];
        if ($withInitialStock) {
            $money['initial_stock'] = 'Stok awal';
        }
        foreach ($money as $field => $label) {
            $raw = trim((string) $this->request->input($field, ''));
            if ($raw === '') {
                $numbers[$field] = 0.0;
                continue;
            }
            $value = MasterRules::parseDecimal($raw);
            if ($value === null) {
                Session::flash('danger', $label . ' harus berupa angka (contoh: 45000 atau 45.000,50).');
                Session::flashInput($this->request->post());
                return null;
            }
            if ($value < 0) {
                Session::flash('danger', $label . ' tidak boleh negatif.');
                Session::flashInput($this->request->post());
                return null;
            }
            $numbers[$field] = $value;
        }

        /** @var array<string,mixed> $input */
        $input = $validator->validated();

        $data = [
            'code'           => (string) $input['code'],
            'name'           => (string) $input['name'],
            'category_id'    => (int) $input['category_id'],
            'brand'          => (string) ($input['brand'] ?? ''),
            'vehicle_model'  => (string) ($input['vehicle_model'] ?? ''),
            'unit'           => (string) ($input['unit'] ?? ''),
            'purchase_price' => (string) $numbers['purchase_price'],
            'selling_price'  => (string) $numbers['selling_price'],
            'min_stock'      => (string) $numbers['min_stock'],
            'supplier_id'    => (int) ($input['supplier_id'] ?? 0) > 0 ? (int) $input['supplier_id'] : null,
            'location_id'    => (int) ($input['location_id'] ?? 0) > 0 ? (int) $input['location_id'] : null,
        ];

        if ($withInitialStock) {
            $data['initial_stock'] = $numbers['initial_stock'] > 0 ? (string) $numbers['initial_stock'] : null;
            $data['initial_stock_date'] = (string) $this->request->input('initial_stock_date', '');
            $data['initial_stock_note'] = (string) $this->request->input('initial_stock_note', '');
        }

        return $data;
    }

    /**
     * Daftar pilihan untuk <select>: hanya yang aktif, TETAPI nilai yang
     * sedang dipakai tetap disertakan walaupun sudah nonaktif — supaya
     * menyimpan perubahan lain tidak diam-diam mengganti kategorinya.
     *
     * @param array<int,array<string,mixed>> $options
     * @param array<string,mixed> $current Baris sparepart (kunci: category_id, category_code, ...)
     * @return array<int,array<string,mixed>>
     */
    private static function withCurrent(array $options, array $current, string $prefix): array
    {
        $id = $current[$prefix . '_id'] ?? null;
        if ($id === null || (int) $current[$prefix . '_active'] === 1) {
            return $options;
        }
        $options[] = [
            'id'        => (int) $id,
            'code'      => (string) ($current[$prefix . '_code'] ?? ''),
            'name'      => (string) ($current[$prefix . '_name'] ?? '') . ' — nonaktif',
            'is_active' => 0,
        ];
        return $options;
    }

    private function requirePost(): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
    }
}
