<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

/**
 * Uji RBAC (PRD §6.4, §15.1):
 * - Setiap route menyebut middleware (tidak ada route "telanjang").
 * - Route sensitif dibatasi AuthMiddleware + RoleMiddleware('admin').
 * - Middleware benar-benar mengizinkan/menolak role (diuji langsung,
 *   tanpa harus memicu halaman 403 yang menghentikan proses).
 *
 * Catatan: mekanisme yang sama dipakai untuk modul transaksi Fase 4
 * (barang masuk/keluar, stock opname, koreksi) — hanya Partman yang boleh
 * menulis, semua peran boleh melihat — dan diuji di fungsi
 * test_rbac_transaksi_* di bawah.
 */

/** @return array<int,array<string,mixed>> */
function t_routes(): array
{
    $router = new Router();
    $register = require BASE_PATH . '/routes/web.php';
    $register($router);
    return $router->routes();
}

function test_rbac_semua_route_punya_middleware(): void
{
    $routes = t_routes();
    t_true(count($routes) >= 15, 'Route terdaftar memadai (aktual: ' . count($routes) . ')');

    // Route publik yang memang tidak membutuhkan login.
    // '/' hanya mengarahkan (redirect) ke /login atau /dashboard.
    $public = ['/health', '/install', '/login', '/'];
    $checked = 0;

    foreach ($routes as $route) {
        if (in_array($route['path'], $public, true)) {
            continue;
        }
        $checked++;
        t_true($route['middleware'] !== [], "Route {$route['method']} {$route['path']} memiliki middleware");
    }
    t_true($checked > 0, 'Ada route terlindungi yang diperiksa (' . $checked . ')');
}

function test_rbac_route_sensitif_dibatasi_admin(): void
{
    $sensitive = [
        '/pengguna',
        '/pengguna/tambah',
        '/pengguna/{id}',
        '/pengguna/{id}/ubah',
        '/pengguna/{id}/status',
        '/pengguna/{id}/reset-password',
        '/pengguna/{id}/unlock',
        '/audit-log',
        '/audit-log/{id}',
        '/profil/password',
    ];

    $foundPaths = [];
    foreach (t_routes() as $route) {
        if (!in_array($route['path'], $sensitive, true)) {
            continue;
        }
        $foundPaths[$route['path']] = true;

        $hasAuth = false;
        $hasAdminRole = false;
        foreach ($route['middleware'] as $definition) {
            $definition = is_string($definition) ? [$definition] : $definition;
            if (($definition[0] ?? null) === AuthMiddleware::class) {
                $hasAuth = true;
            }
            if (($definition[0] ?? null) === RoleMiddleware::class
                && in_array('admin', array_slice($definition, 1), true)) {
                $hasAdminRole = true;
            }
        }
        t_true($hasAuth, "Route {$route['method']} {$route['path']} memakai AuthMiddleware");
        t_true($hasAdminRole, "Route {$route['method']} {$route['path']} dibatasi role admin");
    }

    $missing = array_values(array_diff($sensitive, array_keys($foundPaths)));
    t_eq([], $missing, 'Semua path sensitif terdaftar di tabel route');
}

function test_rbac_middleware_mengizinkan_dan_menolak(): void
{
    $adminOnly = new RoleMiddleware('admin');
    t_true($adminOnly->allows('admin'), 'Admin diizinkan pada route khusus Admin');
    t_true(!$adminOnly->allows('partman'), 'Partman DITOLAK pada route khusus Admin');
    t_true(!$adminOnly->allows('kepala_bengkel'), 'Kepala Bengkel DITOLAK pada route khusus Admin');
    t_true(!$adminOnly->allows(null), 'Tanpa login DITOLAK pada route khusus Admin');

    // Contoh fungsi operasional inventory (Fase 4): edit transaksi hanya Partman.
    $operational = new RoleMiddleware('partman');
    t_true($operational->allows('partman'), 'Partman diizinkan mengedit transaksi');
    t_true(!$operational->allows('kepala_bengkel'), 'Kepala Bengkel DITOLAK mengedit transaksi');
    t_true(!$operational->allows('admin'), 'Admin tidak otomatis melewati batas role modul');

    $multi = new RoleMiddleware('partman', 'admin');
    t_eq(['partman', 'admin'], $multi->allowedRoles(), 'Daftar role yang diizinkan tercatat');
}

function test_rbac_auth_hasrole(): void
{
    t_logout();

    Auth::login([
        'id'        => 1,
        'username'  => 'uji_rbac',
        'full_name' => 'Uji RBAC',
        'role'      => 'partman',
        'role_name' => 'Partman',
    ]);
    t_true(Auth::hasRole('partman'), 'hasRole(partman) true untuk Partman');
    t_true(!Auth::hasRole('admin'), 'hasRole(admin) false untuk Partman');
    t_true(Auth::hasRole('partman', 'admin'), 'hasRole dengan beberapa role tetap true');

    t_logout();
    t_true(!Auth::hasRole('admin'), 'Tanpa sesi: hasRole false');
}

function test_rbac_deny_memakai_halaman_403(): void
{
    // Router::error(403) memuat view error tanpa membocorkan isi halaman.
    $rendered = App\Core\View::render('errors/403', [
        'code'    => 403,
        'message' => 'Anda tidak memiliki hak akses untuk halaman ini.',
        'appName' => 'SmartPartMan',
    ], null);
    t_contains('403', $rendered, 'Halaman 403 memuat kode status');
    t_contains('hak akses', $rendered, 'Halaman 403 menjelaskan akses ditolak');
}

/**
 * Rute master data Fase 3 yang boleh DILIHAT semua role.
 *
 * @return string[] Format "METHOD /path"
 */
function t_rbac_master_read_routes(): array
{
    return ['GET /sparepart', 'GET /sparepart/{id}', 'GET /kategori', 'GET /supplier', 'GET /lokasi'];
}

/**
 * Rute master data Fase 3 yang hanya boleh ditulis Partman.
 *
 * @return string[] Format "METHOD /path"
 */
function t_rbac_master_write_routes(): array
{
    return [
        // Kategori (FR-12)
        'GET /kategori/tambah', 'POST /kategori', 'GET /kategori/{id}/ubah', 'POST /kategori/{id}', 'POST /kategori/{id}/status',
        // Supplier (FR-11)
        'GET /supplier/tambah', 'POST /supplier', 'GET /supplier/{id}/ubah', 'POST /supplier/{id}', 'POST /supplier/{id}/status',
        // Lokasi penyimpanan (FR-09)
        'GET /lokasi/tambah', 'POST /lokasi', 'GET /lokasi/{id}/ubah', 'POST /lokasi/{id}', 'POST /lokasi/{id}/status',
        // Master sparepart (FR-03)
        'GET /sparepart/tambah', 'POST /sparepart', 'GET /sparepart/{id}/ubah', 'POST /sparepart/{id}', 'POST /sparepart/{id}/status',
        // Stok awal manual (FR-05)
        'GET /sparepart/{id}/stok-awal', 'POST /sparepart/{id}/stok-awal',
        // Impor .csv/.xlsx (FR-04)
        'GET /import', 'GET /import/template', 'POST /import/pratinjau', 'POST /import/komit',
    ];
}

/** @return array{method:string, path:string, middleware:array}|null */
function t_rbac_find_route(string $key): ?array
{
    foreach (t_routes() as $route) {
        if ($route['method'] . ' ' . $route['path'] === $key) {
            return $route;
        }
    }
    return null;
}

/** @param array<int,mixed> $middleware */
function t_rbac_has_middleware(array $middleware, string $class, ?string $role = null): bool
{
    foreach ($middleware as $definition) {
        $definition = is_string($definition) ? [$definition] : $definition;
        if (($definition[0] ?? null) !== $class) {
            continue;
        }
        if ($role === null) {
            return true;
        }
        if (in_array($role, array_slice($definition, 1), true)) {
            return true;
        }
    }
    return false;
}

function test_rbac_master_data_hanya_partman_yang_menulis(): void
{
    foreach (t_rbac_master_write_routes() as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], AuthMiddleware::class), "Route {$key} wajib login");
        t_true(
            t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'partman'),
            "Route {$key} dibatasi role partman"
        );
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'admin'),
            "Route {$key} TIDAK membuka akses tulis untuk Admin"
        );
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'kepala_bengkel'),
            "Route {$key} TIDAK membuka akses tulis untuk Kepala Bengkel"
        );
    }
}

function test_rbac_master_data_dapat_dilihat_semua_role(): void
{
    foreach (t_rbac_master_read_routes() as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route baca {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], AuthMiddleware::class), "Route baca {$key} wajib login");
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class),
            "Route baca {$key} tidak dibatasi role tertentu (semua peran dapat melihat)"
        );
    }

    // "Admin tidak mengubah data master operasional" (PRD §6.3).
    $operational = new RoleMiddleware('partman');
    t_true($operational->allows('partman'), 'Partman boleh mengelola data master');
    t_true(!$operational->allows('kepala_bengkel'), 'Kepala Bengkel tidak boleh mengelola data master');
    t_true(!$operational->allows('admin'), 'Admin TIDAK boleh mengubah data master operasional');
}

function test_rbac_urutan_route_sparepart_tidak_tertukar(): void
{
    // '/sparepart/tambah' harus dipakai sebagai form, bukan terbaca sebagai id.
    $router = new Router();
    $register = require BASE_PATH . '/routes/web.php';
    $register($router);

    $tambah = $router->match('GET', '/sparepart/tambah');
    t_true($tambah !== null, 'GET /sparepart/tambah cocok dengan sebuah route');
    t_eq(
        [App\Controllers\SparepartController::class, 'createForm'],
        $tambah['route']['handler'] ?? null,
        'GET /sparepart/tambah diarahkan ke form tambah (bukan detail id)'
    );

    $detail = $router->match('GET', '/sparepart/12');
    t_eq(
        [App\Controllers\SparepartController::class, 'show'],
        $detail['route']['handler'] ?? null,
        'GET /sparepart/{id} diarahkan ke detail sparepart'
    );
    t_eq(['id' => '12'], $detail['params'] ?? null, 'Parameter id terbaca dari URL');

    $stokAwal = $router->match('GET', '/sparepart/12/stok-awal');
    t_eq(
        [App\Controllers\StockInitialController::class, 'form'],
        $stokAwal['route']['handler'] ?? null,
        'GET /sparepart/{id}/stok-awal diarahkan ke form stok awal'
    );

    $template = $router->match('GET', '/import/template');
    t_eq(
        [App\Controllers\ImportController::class, 'template'],
        $template['route']['handler'] ?? null,
        'GET /import/template diarahkan ke unduhan template'
    );
}

// ------------------------------------------------------------------
// Fase 4 — RBAC modul transaksi (FR-06, FR-07, FR-10, §7.4, §6.4)
// ------------------------------------------------------------------

/**
 * Rute transaksi Fase 4 yang boleh DILIHAT semua role (Kepala Bengkel
 * boleh memantau, tapi tidak boleh mengubah).
 *
 * @return string[]
 */
function t_rbac_transaction_read_routes(): array
{
    return [
        'GET /transaksi/masuk', 'GET /transaksi/masuk/{id}', 'GET /transaksi/masuk/{id}/bukti',
        'GET /transaksi/keluar', 'GET /transaksi/keluar/{id}',
        'GET /transaksi/opname', 'GET /transaksi/opname/{id}',
        'GET /transaksi/riwayat',
    ];
}

/**
 * Rute transaksi Fase 4 yang hanya boleh DIJALANKAN Partman.
 *
 * @return string[]
 */
function t_rbac_transaction_write_routes(): array
{
    return [
        // Barang masuk (FR-06)
        'GET /transaksi/masuk/tambah', 'POST /transaksi/masuk',
        // Barang keluar (FR-07)
        'GET /transaksi/keluar/tambah', 'POST /transaksi/keluar',
        // Stock opname (FR-10)
        'GET /transaksi/opname/mulai', 'POST /transaksi/opname',
        'POST /transaksi/opname/{id}/simpan', 'POST /transaksi/opname/{id}/konfirmasi',
        // Koreksi transaksi (PRD §7.4, Aturan 8)
        'GET /transaksi/koreksi', 'GET /transaksi/koreksi/{id}', 'POST /transaksi/koreksi/{id}',
    ];
}

function test_rbac_transaksi_hanya_partman_yang_menulis(): void
{
    foreach (t_rbac_transaction_write_routes() as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route transaksi {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], AuthMiddleware::class), "Route {$key} wajib login");
        t_true(
            t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'partman'),
            "Route {$key} dibatasi role partman"
        );
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'admin'),
            "Route {$key} TIDAK membuka akses tulis untuk Admin"
        );
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'kepala_bengkel'),
            "Route {$key} TIDAK membuka akses tulis untuk Kepala Bengkel"
        );
    }
}

function test_rbac_transaksi_dapat_dilihat_semua_role(): void
{
    foreach (t_rbac_transaction_read_routes() as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route baca transaksi {$key} terdaftar");
        if ($route === null) {
            continue;
        }
        t_true(t_rbac_has_middleware($route['middleware'], AuthMiddleware::class), "Route baca {$key} wajib login");
        t_true(
            !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class),
            "Route baca {$key} tidak dibatasi role tertentu (semua peran dapat melihat)"
        );
    }
}

function test_rbac_kepala_bengkel_hanya_melihat_opname(): void
{
    // FR-10: Kepala Bengkel dapat MELIHAT hasil opname, tidak dapat mengubah.
    $partmanOnly = new RoleMiddleware('partman');
    t_true($partmanOnly->allows('partman'), 'Partman boleh menjalankan opname');
    t_true(!$partmanOnly->allows('kepala_bengkel'), 'Kepala Bengkel TIDAK boleh mengubah opname');
    t_true(!$partmanOnly->allows('admin'), 'Admin TIDAK boleh mengubah opname (bukan tugasnya)');

    foreach (['GET /transaksi/opname', 'GET /transaksi/opname/{id}'] as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route pantau {$key} tersedia untuk Kepala Bengkel");
        t_true(
            $route !== null && !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class),
            "Route pantau {$key} dapat diakses Kepala Bengkel"
        );
    }

    foreach (['POST /transaksi/opname', 'POST /transaksi/opname/{id}/simpan', 'POST /transaksi/opname/{id}/konfirmasi'] as $key) {
        $route = t_rbac_find_route($key);
        t_true(
            $route !== null && !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'kepala_bengkel'),
            "Kepala Bengkel TIDAK dapat memanggil {$key}"
        );
    }
}

function test_rbac_urutan_route_transaksi_tidak_tertukar(): void
{
    $router = new Router();
    $register = require BASE_PATH . '/routes/web.php';
    $register($router);

    // '/tambah' & '/mulai' harus dipakai sebagai form, bukan terbaca sebagai id.
    $masukTambah = $router->match('GET', '/transaksi/masuk/tambah');
    t_eq(
        [App\Controllers\GoodsReceiptController::class, 'createForm'],
        $masukTambah['route']['handler'] ?? null,
        'GET /transaksi/masuk/tambah diarahkan ke form penerimaan (bukan detail id)'
    );

    $masukDetail = $router->match('GET', '/transaksi/masuk/12');
    t_eq(
        [App\Controllers\GoodsReceiptController::class, 'show'],
        $masukDetail['route']['handler'] ?? null,
        'GET /transaksi/masuk/{id} diarahkan ke detail penerimaan'
    );
    t_eq(['id' => '12'], $masukDetail['params'] ?? null, 'Parameter id penerimaan terbaca dari URL');

    $bukti = $router->match('GET', '/transaksi/masuk/12/bukti');
    t_eq(
        [App\Controllers\GoodsReceiptController::class, 'attachment'],
        $bukti['route']['handler'] ?? null,
        'GET /transaksi/masuk/{id}/bukti diarahkan ke pengunduhan bukti (bukan publik)'
    );

    $keluarTambah = $router->match('GET', '/transaksi/keluar/tambah');
    t_eq(
        [App\Controllers\GoodsIssueController::class, 'createForm'],
        $keluarTambah['route']['handler'] ?? null,
        'GET /transaksi/keluar/tambah diarahkan ke form pengeluaran'
    );

    $opnameMulai = $router->match('GET', '/transaksi/opname/mulai');
    t_eq(
        [App\Controllers\StockOpnameController::class, 'createForm'],
        $opnameMulai['route']['handler'] ?? null,
        'GET /transaksi/opname/mulai diarahkan ke form mulai opname'
    );

    $opnameDetail = $router->match('GET', '/transaksi/opname/7');
    t_eq(
        [App\Controllers\StockOpnameController::class, 'show'],
        $opnameDetail['route']['handler'] ?? null,
        'GET /transaksi/opname/{id} diarahkan ke detail sesi opname'
    );

    $konfirmasi = $router->match('POST', '/transaksi/opname/7/konfirmasi');
    t_eq(
        [App\Controllers\StockOpnameController::class, 'confirm'],
        $konfirmasi['route']['handler'] ?? null,
        'POST /transaksi/opname/{id}/konfirmasi diarahkan ke konfirmasi opname'
    );

    $koreksi = $router->match('GET', '/transaksi/koreksi/9');
    t_eq(
        [App\Controllers\CorrectionController::class, 'form'],
        $koreksi['route']['handler'] ?? null,
        'GET /transaksi/koreksi/{id} diarahkan ke form koreksi'
    );

    $riwayat = $router->match('GET', '/transaksi/riwayat');
    t_eq(
        [App\Controllers\StockHistoryController::class, 'index'],
        $riwayat['route']['handler'] ?? null,
        'GET /transaksi/riwayat diarahkan ke riwayat pergerakan stok'
    );
}
