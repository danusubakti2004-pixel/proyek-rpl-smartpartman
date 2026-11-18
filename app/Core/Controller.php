<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Controller dasar: render view, redirect, dan akses request.
 * Controller tidak menulis query SQL langsung — logika bisnis di Service,
 * akses data di Model.
 */
abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        $data['appName'] = App::config('app.name', 'SmartPartMan');
        echo View::render($template, $data, $layout);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Kirim respons JSON (dipakai endpoint health check/API).
     *
     * @param array<string,mixed> $data
     */
    protected function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    protected function abort(int $code, string $message = ''): void
    {
        Router::error($code, $message);
        exit;
    }
}
