<?php
declare(strict_types=1);

/**
 * Autoloader sederhana (PSR-4 gaya) untuk namespace App\ → folder app/
 * Dipakai oleh front controller (index.php) maupun skrip CLI (migrasi, test).
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once BASE_PATH . '/app/Helpers/helpers.php';
