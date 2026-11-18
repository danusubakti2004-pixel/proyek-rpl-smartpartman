<?php
declare(strict_types=1);

/**
 * Router stub Gemini — HANYA untuk pengujian lokal (php -S).
 *
 * Tidak pernah dipakai oleh aplikasi produksi. Mencatat permintaan ke berkas
 * sementara agar test dapat memeriksa header/body tanpa mencetak API key.
 */
$port = (string) ($_SERVER['SERVER_PORT'] ?? '0');
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spm_gemini_stub_' . $port . '.log';

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$key = (string) ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '');
if ($key === '') {
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($authorization, 'Bearer ') === 0) {
        $key = trim(substr($authorization, 7));
    }
}
$rawBody = (string) file_get_contents('php://input');
$body = json_decode($rawBody, true);

$entry = [
    'path'     => $path,
    'method'   => $method,
    'key_len'  => strlen($key),
    'key_tail' => $key !== '' ? substr($key, -4) : '',
    'body'     => is_array($body) ? $body : null,
];
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json; charset=utf-8');

$keyCode = static function (string $key): string {
    foreach (['INVALID', 'NOTFOUND', 'QUOTA', 'UNAVAILABLE', 'SLOW', 'EMPTY'] as $marker) {
        if (str_starts_with($key, $marker)) {
            return $marker;
        }
    }
    return '';
};

$fail = static function (int $status, string $code, string $message): void {
    http_response_code($status);
    echo json_encode(['error' => ['code' => $status, 'status' => $code, 'message' => $message]]);
};

$code = $keyCode($key);
if ($code === 'INVALID') {
    $fail(401, 'UNAUTHENTICATED', 'API key not valid. Please pass a valid API key.');
    exit;
}
if ($code === 'QUOTA') {
    $fail(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded for this project.');
    exit;
}
if ($code === 'UNAVAILABLE') {
    $fail(500, 'INTERNAL', 'Backend unavailable.');
    exit;
}

if (str_contains($path, '/models/')) {
    if ($code === 'NOTFOUND') {
        $fail(404, 'NOT_FOUND', 'Model not found.');
        exit;
    }
    $model = rawurldecode(substr($path, strrpos($path, '/') + 1));
    echo json_encode([
        'name'        => 'models/' . $model,
        'displayName' => 'Stub ' . $model,
    ]);
    exit;
}

// Endpoint OpenAI-compatible (DeepSeek/OpenAI) — GET {base}/models
if ($method === 'GET' && (str_ends_with(rtrim($path, '/'), '/models') || $path === '/models')) {
    if ($code === 'NOTFOUND') {
        $fail(404, 'NOT_FOUND', 'Model not found.');
        exit;
    }
    echo json_encode([
        'object' => 'list',
        'data'   => [
            ['id' => 'deepseek-chat', 'object' => 'model', 'name' => 'Stub DeepSeek Chat'],
            ['id' => 'deepseek-reasoner', 'object' => 'model', 'name' => 'Stub DeepSeek Reasoner'],
            ['id' => 'gpt-4o-mini', 'object' => 'model', 'name' => 'Stub GPT-4o mini'],
            ['id' => 'gpt-4o', 'object' => 'model', 'name' => 'Stub GPT-4o'],
            ['id' => 'gpt-4.1-mini', 'object' => 'model', 'name' => 'Stub GPT-4.1 mini'],
            ['id' => 'gpt-4.1', 'object' => 'model', 'name' => 'Stub GPT-4.1'],
        ],
    ]);
    exit;
}

// Endpoint OpenAI-compatible — POST {base}/chat/completions
if ($method === 'POST' && str_ends_with(rtrim($path, '/'), '/chat/completions')) {
    if ($code === 'NOTFOUND') {
        $fail(404, 'NOT_FOUND', 'Model not found.');
        exit;
    }
    if ($code === 'EMPTY') {
        echo json_encode([
            'id' => 'chatcmpl_stub_empty', 'object' => 'chat.completion',
            'model' => (string) ($body['model'] ?? 'stub'),
            'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0],
        ]);
        exit;
    }

    $input = '';
    if (is_array($body) && is_array($body['messages'] ?? null)) {
        foreach (array_reverse($body['messages']) as $message) {
            if (is_array($message) && (string) ($message['role'] ?? '') === 'user') {
                $input = (string) ($message['content'] ?? '');
                break;
            }
        }
    }
    $model = (string) ($body['model'] ?? 'stub-model');
    $label = str_starts_with($model, 'deepseek') ? 'DeepSeek' : (str_starts_with($model, 'gpt') ? 'OpenAI' : 'Provider');
    $text = 'Jawaban uji ' . $label . ' untuk: ' . $input;
    if (str_contains($input, '<script>')) {
        $text = "<script>alert('xss')</script> Jawaban uji.";
    }
    if (str_contains($input, 'MARKDOWN_UJI')) {
        $text = "**Total Kategori:** 8 kategori\\n* Baris satu\\n### Catatan\n`kode` selesai";
    }

    echo json_encode([
        'id'      => 'chatcmpl_stub_' . bin2hex(random_bytes(4)),
        'object'  => 'chat.completion',
        'model'   => $model,
        'choices' => [[
            'index'         => 0,
            'message'       => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens'     => max(1, (int) ceil(strlen($input) / 4)),
            'completion_tokens' => max(1, (int) ceil(strlen($text) / 4)),
        ],
    ]);
    exit;
}

if (str_contains($path, '/interactions')) {
    if ($code === 'SLOW') {
        sleep(3);
    }
    if ($code === 'EMPTY') {
        echo json_encode(['status' => 'completed', 'steps' => [], 'usage' => ['total_input_tokens' => 1, 'total_output_tokens' => 0]]);
        exit;
    }

    $input = '';
    $system = '';
    if (is_array($body)) {
        $input = (string) ($body['input'] ?? '');
        $system = (string) ($body['system_instruction'] ?? '');
    }
    $text = 'Jawaban uji Gemini untuk: ' . $input;
    if (str_contains($input, '<script>')) {
        $text = "<script>alert('xss')</script> Jawaban uji.";
    }
    if (str_contains($input, 'MARKDOWN_UJI')) {
        $text = "**Total Kategori:** 8 kategori\\n* Baris satu\\n### Catatan\n`kode` selesai";
    }

    echo json_encode([
        'id'     => 'int_stub_' . bin2hex(random_bytes(4)),
        'model'  => (string) ($body['model'] ?? 'gemini-3.5-flash-lite'),
        'status' => 'completed',
        'steps'  => [[
            'type'    => 'model_output',
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
        'usage'  => [
            'total_input_tokens'  => max(1, (int) ceil(strlen($system) / 4)),
            'total_output_tokens' => max(1, (int) ceil(strlen($text) / 4)),
        ],
    ]);
    exit;
}

$fail(404, 'NOT_FOUND', 'Unknown stub path.');
