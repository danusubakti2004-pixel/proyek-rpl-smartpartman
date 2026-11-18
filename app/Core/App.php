<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\InstallService;
use ErrorException;
use Throwable;

/**
 * Bootstrap aplikasi: konfigurasi, penanganan error, dan dispatch request.
 */
final class App
{
    /** @var array<string,mixed> */
    private static array $config = [];

    /** @param array<string,mixed> $config */
    public static function boot(array $config): void
    {
        self::$config = $config;

        date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Jakarta'));
        Database::configure($config['db']);

        $debug = (bool) ($config['app']['debug'] ?? false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', (string) ($config['paths']['logs'] ?? sys_get_temp_dir()) . '/php-error.log');

        self::registerErrorHandlers();
        self::sendSecurityHeaders();
        Session::start();
    }

    /**
     * Header keamanan dasar untuk seluruh respons PHP.
     *
     * Pemakaian nilai yang tidak membatasi aset lokal (Bootstrap/CSS/JS)
     * namun mencegah clickjacking, MIME sniffing, dan pengiriman referrer
     * berlebihan. HSTS tidak dipasang di HTTP lokal.
     *
     * @return array<string,string>
     */
    public static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'",
            'X-Robots-Tag'            => 'noindex, nofollow',
            'Cache-Control'           => 'no-store, private',
        ];
    }

    private static function sendSecurityHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        foreach (self::securityHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Ambil konfigurasi berdot, mis. App::config('db.name').
     * @template T
     * @param T $default
     * @return T|mixed
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        $ref = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return $default;
            }
            $ref = $ref[$segment];
        }
        return $ref;
    }

    public static function run(): void
    {
        $request = new Request();
        $path = $request->path();

        $router = new Router();
        $routes = require BASE_PATH . '/routes/web.php';
        $routes($router);

        $installed = InstallService::isInstalled();

        // Belum ada admin → paksa installer. Setelah terpasang → installer terkunci.
        // Pengecualian: /health tetap boleh diakses agar diagnostik berguna
        // meski instalasi belum selesai (mis. database belum siap).
        if (!$installed && !in_array($path, ['/install', '/health'], true)) {
            self::redirect(base_url('install'));
        }
        if ($installed && $path === '/install') {
            self::redirect(base_url('login'));
        }

        $router->dispatch($request);
    }

    public static function redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
        }
        exit;
    }

    private static function registerErrorHandlers(): void
    {
        set_error_handler(static function (int $errno, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $errno)) {
                return false; // hormati @
            }
            throw new ErrorException($message, 0, $errno, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::logThrowable($e);
            if (!headers_sent()) {
                http_response_code(500);
            }
            $debug = (bool) self::config('app.debug', false);
            $message = $debug
                ? $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine()
                : 'Terjadi kesalahan pada server. Silakan coba lagi atau hubungi administrator.';
            try {
                echo View::render('errors/500', [
                    'appName' => (string) self::config('app.name', 'SmartPartMan'),
                    'code'    => 500,
                    'message' => $message,
                ], null);
            } catch (Throwable) {
                echo 'Terjadi kesalahan pada server.';
            }
        });
    }

    public static function logThrowable(Throwable $e): void
    {
        $dir = (string) self::config('paths.logs', sys_get_temp_dir());
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
