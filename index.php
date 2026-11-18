<?php
declare(strict_types=1);

/**
 * SmartPartMan — Front Controller.
 *
 * Seluruh request HTTP diproses melalui file ini.
 * Jangan akses file aplikasi lain secara langsung dari browser.
 */

if (!defined('SMARTPARTMAN')) {
    define('SMARTPARTMAN', true);
}

define('BASE_PATH', __DIR__);

require BASE_PATH . '/app/autoload.php';

use App\Core\App;
use App\Core\Env;

// Muat konfigurasi lingkungan dari .env (tidak pernah ditulis di source code).
Env::load(BASE_PATH . '/.env');

$config = require BASE_PATH . '/config/config.php';

App::boot($config);
App::run();
