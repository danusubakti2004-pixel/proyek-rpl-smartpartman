<?php
declare(strict_types=1);

use App\Core\Router;

/**
 * Uji router: pencocokan path statis, parameter dinamis, dan metode.
 * (Dispatch HTTP penuh diuji lewat pengujian manual/HTTP di akhir fase.)
 */
function test_router_cocok_path_statis(): void
{
    $router = new Router();
    $router->get('/login', [\App\Controllers\AuthController::class, 'showLogin']);

    $match = $router->match('GET', '/login');
    t_true($match !== null, 'Path statis /login cocok (GET)');
    t_eq([], $match['params'] ?? null, 'Path statis tanpa parameter');

    t_true($router->match('POST', '/login') === null, 'Metode berbeda tidak cocok');
    t_true($router->match('GET', '/login/extra') === null, 'Path tambahan tidak cocok');
    t_true($router->match('GET', '/halaman-tidak-ada') === null, 'Path tak dikenal tidak cocok');
}

function test_router_parameter_dinamis(): void
{
    $router = new Router();
    $router->get('/sparepart/{id}/edit', [\App\Controllers\HomeController::class, 'index']);

    $match = $router->match('GET', '/sparepart/42/edit');
    t_true($match !== null, 'Path berparameter cocok');
    t_eq('42', $match['params']['id'] ?? null, 'Parameter {id} tertangkap dengan benar');

    t_true($router->match('GET', '/sparepart/42') === null, 'Path tanpa segmen /edit tidak cocok');
    t_true($router->match('GET', '/sparepart/a/b/edit') === null, 'Segmen ganda tidak cocok');
}

function test_router_metode_post(): void
{
    $router = new Router();
    $router->post('/login', [\App\Controllers\AuthController::class, 'login']);
    t_true($router->match('POST', '/login') !== null, 'POST /login cocok');
    t_true($router->match('GET', '/login') === null, 'GET tidak cocok dengan route POST');
}

function test_router_middleware_terdaftar(): void
{
    $router = new Router();
    $router->get(
        '/dashboard',
        [\App\Controllers\HomeController::class, 'index'],
        [[\App\Middleware\AuthMiddleware::class]]
    );

    $match = $router->match('GET', '/dashboard');
    t_true($match !== null, 'Route dengan middleware cocok');
    t_eq(
        \App\Middleware\AuthMiddleware::class,
        $match['route']['middleware'][0][0] ?? null,
        'Middleware Auth terdaftar pada route dashboard'
    );
}

function test_router_middleware_bentuk_string_dan_array(): void
{
    // Bentuk string tunggal (dipakai routes/web.php) harus diterima dispatch.
    $router = new Router();
    $router->get('/login', [\App\Controllers\AuthController::class, 'showLogin'], [\App\Middleware\GuestMiddleware::class]);
    $match = $router->match('GET', '/login');
    t_true($match !== null, 'Route dengan middleware string cocok');
    t_eq(\App\Middleware\GuestMiddleware::class, $match['route']['middleware'][0], 'Entry middleware string tersimpan apa adanya');

    // Bentuk array dengan argumen constructor (RoleMiddleware) juga diterima.
    $router2 = new Router();
    $router2->get('/uji-role', [\App\Controllers\HomeController::class, 'index'], [
        [\App\Middleware\AuthMiddleware::class],
        [\App\Middleware\RoleMiddleware::class, 'admin', 'partman'],
    ]);
    $match2 = $router2->match('GET', '/uji-role');
    t_eq(2, count($match2['route']['middleware']), 'Dua middleware terdaftar');
    t_eq('admin', $match2['route']['middleware'][1][1], 'Argumen ctor role pertama tercatat');
}
