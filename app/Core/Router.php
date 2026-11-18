<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router: pendaftaran route + middleware per route + dispatch.
 * Pola route mendukung parameter {nama} (mis. /sparepart/{id}).
 */
final class Router
{
    /**
     * @var array<int, array{
     *     method: string,
     *     path: string,
     *     regex: string,
     *     handler: array{0:class-string,1:string},
     *     middleware: array<int, array{0:class-string, 1:mixed...}>
     * }>
     */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler @param array<int,array> $middleware */
    public function get(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    /** @param array{0:class-string,1:string} $handler @param array<int,array> $middleware */
    public function post(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array{0:class-string,1:string} $handler @param array<int,array> $middleware */
    public function add(string $method, string $path, array $handler, array $middleware = []): self
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'regex'      => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    /**
     * Seluruh route terdaftar (dipakai pengujian RBAC & dokumentasi).
     *
     * @return array<int,array{method:string, path:string, regex:string, handler:array, middleware:array}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /** Kompilasi pola path → regex; segmen {nama} menjadi parameter dinamis. */

    private function compile(string $path): string
    {
        $segments = explode('/', $path);
        $parts = [];
        foreach ($segments as $segment) {
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$#', $segment, $m) === 1) {
                $parts[] = '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }
        return '#^' . implode('/', $parts) . '$#';
    }

    /**
     * @return array{route: array, params: array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $params[$key] = $value;
                    }
                }
                return ['route' => $route, 'params' => $params];
            }
        }
        return null;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        $match = $this->match($method, $path);
        if ($match === null) {
            // Bedakan "method tidak diizinkan" vs "halaman tidak ada".
            $existsForOtherMethod = false;
            foreach ($this->routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $existsForOtherMethod = true;
                    break;
                }
            }
            self::error(
                $existsForOtherMethod ? 405 : 404,
                $existsForOtherMethod
                    ? 'Metode request tidak diizinkan untuk halaman ini.'
                    : 'Halaman yang Anda minta tidak ditemukan.'
            );
            return;
        }

        $route = $match['route'];
        $params = $match['params'];

        $handler = static function () use ($route, $params, $request): void {
            [$class, $action] = $route['handler'];
            $controller = new $class($request);
            $controller->{$action}($params);
        };

        // Bangun rantai middleware (terakhir didaftarkan = terluar).
        // Bentuk entry: string 'App\Middleware\X' ATAU array ['App\Middleware\X', ...argumenCtor].
        $next = $handler;
        foreach (array_reverse($route['middleware']) as $definition) {
            if (is_string($definition)) {
                $definition = [$definition];
            }
            $className = $definition[0];
            $args = array_slice($definition, 1);
            $instance = new $className(...$args);
            $previous = $next;
            $next = static function () use ($instance, $request, $previous): void {
                $instance->handle($request, $previous);
            };
        }

        $next();
    }

    /** Tampilkan halaman error (tanpa layout utama). */
    public static function error(int $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }
        $template = match ($code) {
            403     => 'errors/403',
            405     => 'errors/403',
            default => 'errors/404',
        };
        echo View::render($template, [
            'appName' => App::config('app.name', 'SmartPartMan'),
            'code'    => $code,
            'message' => $message,
        ], null);
        exit;
    }
}
