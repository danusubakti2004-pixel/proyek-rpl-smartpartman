<?php
declare(strict_types=1);

use App\Services\GeminiService;

require_once __DIR__ . '/gemini_stub.php';

/**
 * Pengujian Fase 8 — klien Gemini (cURL nyata ke stub lokal).
 * Memastikan endpoint, header, parsing respons, dan pemetaan error benar.
 */

function test_chatbot_gemini_generate_sukses(): void
{
    $stub = t_gemini_stub_start();
    try {
        $key = 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
        $result = GeminiService::generate(
            $key,
            'gemini-3.5-flash-lite',
            'Instruksi sistem uji.',
            'Berapa stok kritis?',
            256,
            $stub['base'],
            5.0
        );

        t_true($result['ok'], 'Generate Gemini berhasil melalui stub');
        t_contains('Berapa stok kritis?', (string) $result['text'], 'Teks jawaban diekstrak dari steps[].content[]');
        t_eq(200, (int) $result['http_status'], 'HTTP 200 diterima');
        t_true((int) $result['prompt_tokens'] > 0, 'Token input dibaca dari usage');
        t_true((int) $result['response_tokens'] > 0, 'Token output dibaca dari usage');
        t_true(!str_contains(json_encode($result) ?: '', $key), 'Hasil generate tidak memuat API key');

        $requests = t_gemini_stub_requests($stub);
        t_eq(1, count($requests), 'Stub menerima satu permintaan generate');
        $req = $requests[0] ?? [];
        t_eq('POST', (string) ($req['method'] ?? ''), 'Generate memakai POST');
        t_true(str_ends_with((string) ($req['path'] ?? ''), '/v1beta/interactions'), 'Endpoint Interactions dipakai');
        t_eq(strlen($key), (int) ($req['key_len'] ?? 0), 'API key dikirim lewat header x-goog-api-key');
        t_eq(
            'gemini-3.5-flash-lite',
            (string) (($req['body'] ?? [])['model'] ?? ''),
            'Body memuat model yang dipilih'
        );
        t_eq(
            'Berapa stok kritis?',
            (string) (($req['body'] ?? [])['input'] ?? ''),
            'Body memuat pertanyaan pengguna'
        );
        t_contains('Instruksi sistem uji', (string) (($req['body'] ?? [])['system_instruction'] ?? ''), 'Body memuat instruksi sistem');
        t_eq(256, (int) (($req['body'] ?? [])['generation_config']['max_output_tokens'] ?? 0), 'Batas token dikirim');
        t_eq(false, (bool) (($req['body'] ?? [])['store'] ?? true), 'Permintaan meminta store=false');
    } finally {
        t_gemini_stub_cleanup($stub);
    }
}

function test_chatbot_gemini_pemetaan_error(): void
{
    $stub = t_gemini_stub_start();
    try {
        $cases = [
            ['INVALID-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234', 'API_KEY_INVALID', 401],
            ['QUOTA-ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', 'RATE_LIMIT', 429],
            ['UNAVAILABLE-ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'UNAVAILABLE', 500],
            ['EMPTY-ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', 'EMPTY_RESPONSE', 200],
        ];
        foreach ($cases as [$key, $code, $status]) {
            $result = GeminiService::generate(
                $key,
                'gemini-3.5-flash-lite',
                'Instruksi uji.',
                'Pertanyaan uji.',
                128,
                $stub['base'],
                5.0
            );
            t_true(!$result['ok'], "Kasus {$code}: generate gagal");
            t_eq($code, (string) $result['error_code'], "Kasus {$code}: kode error benar");
            t_eq($status, (int) $result['http_status'], "Kasus {$code}: HTTP status benar");
            t_true(!str_contains(json_encode($result) ?: '', $key), "Kasus {$code}: API key tidak bocor ke hasil");
        }

        $notFound = GeminiService::generate(
            'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234',
            'model-tidak-ada',
            'Instruksi uji.',
            'Pertanyaan uji.',
            128,
            $stub['base'],
            5.0
        );
        // Stub interactions tidak memeriksa model; model tidak ditemukan diuji pada testConnection.
        t_true($notFound['ok'], 'Stub interactions menerima model apa pun (kontrol)');

        $conn = GeminiService::testConnection(
            'NOTFOUND-ABCDEFGHIJKLMNOPQRSTUVWXYZ12',
            'model-tidak-ada',
            $stub['base'],
            5.0
        );
        t_true(!$conn['ok'], 'Test connection model tidak ditemukan gagal');
        t_eq('MODEL_NOT_FOUND', (string) $conn['error_code'], 'Test connection memetakan 404 ke MODEL_NOT_FOUND');
    } finally {
        t_gemini_stub_cleanup($stub);
    }
}

function test_chatbot_gemini_timeout(): void
{
    $stub = t_gemini_stub_start();
    try {
        $start = microtime(true);
        $result = GeminiService::generate(
            'SLOW-ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
            'gemini-3.5-flash-lite',
            'Instruksi uji.',
            'Pertanyaan lambat.',
            128,
            $stub['base'],
            2.0
        );
        $elapsed = microtime(true) - $start;

        t_true(!$result['ok'], 'Timeout menghasilkan kegagalan');
        t_eq('TIMEOUT', (string) $result['error_code'], 'Kode error TIMEOUT');
        t_true($elapsed < 4.5, 'Timeout tidak menggantung melebihi batas wajar (aktual ' . round($elapsed, 2) . 's)');
    } finally {
        t_gemini_stub_cleanup($stub);
    }
}
