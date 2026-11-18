<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Renderer view: partial (template isi) + layout (kerangka halaman).
 * View hanya menampilkan data; tidak berisi logika bisnis.
 */
final class View
{
    /**
     * @param array<string,mixed> $data
     * @param string|null $layout 'layouts/app', 'layouts/auth', atau null (tanpa layout).
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View tidak ditemukan: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
