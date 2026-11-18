<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Klien provider AI untuk chatbot SmartPartMan.
 *
 * Gemini memakai GeminiService (Interactions API). DeepSeek dan OpenAI memakai
 * protokol OpenAI-compatible: POST {base}/chat/completions dan GET {base}/models.
 *
 * Prinsip keamanan:
 * - API key hanya dikirim lewat header Authorization / x-goog-api-key.
 * - Tidak pernah masuk URL, log, Audit Log, atau pesan error.
 * - Redirect tidak diikuti agar header rahasia tidak berpindah host.
 */
final class AiProviderService
{
    public const PROVIDERS = [
        'gemini' => [
            'label'        => 'Gemini',
            'protocol'     => 'gemini',
            'default_model'=> 'gemini-3.5-flash-lite',
            'default_base' => 'https://generativelanguage.googleapis.com/v1beta',
            'models'       => [
                'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite — cepat & hemat',
                'gemini-3.8-flash'      => 'Gemini 3.8 Flash — lebih cerdas',
            ],
            'key_hint'     => 'API key dari Google AI Studio',
            'docs_url'     => 'https://aistudio.google.com/app/apikey',
        ],
        'deepseek' => [
            'label'        => 'DeepSeek',
            'protocol'     => 'openai',
            'default_model'=> 'deepseek-chat',
            'default_base' => 'https://api.deepseek.com/v1',
            'models'       => [
                'deepseek-chat'     => 'DeepSeek Chat',
                'deepseek-reasoner' => 'DeepSeek Reasoner',
            ],
            'key_hint'     => 'API key dari platform DeepSeek',
            'docs_url'     => 'https://platform.deepseek.com/api_keys',
        ],
        'openai' => [
            'label'        => 'OpenAI',
            'protocol'     => 'openai',
            'default_model'=> 'gpt-4o-mini',
            'default_base' => 'https://api.openai.com/v1',
            'models'       => [
                'gpt-4o-mini'  => 'GPT-4o mini — hemat',
                'gpt-4o'       => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 mini',
                'gpt-4.1'      => 'GPT-4.1',
            ],
            'key_hint'     => 'API key dari platform OpenAI',
            'docs_url'     => 'https://platform.openai.com/api-keys',
        ],
    ];

    public const DEFAULT_TIMEOUT = 25.0;

    /** @return array<string,mixed> */
    public static function provider(string $provider): array
    {
        $provider = self::normalizeProvider($provider);
        return self::PROVIDERS[$provider] + ['code' => $provider];
    }

    public static function label(string $provider): string
    {
        return (string) self::provider($provider)['label'];
    }

    public static function defaultModel(string $provider): string
    {
        return (string) self::provider($provider)['default_model'];
    }

    public static function defaultBase(string $provider): string
    {
        return (string) self::provider($provider)['default_base'];
    }

    /** @return array<string,string> */
    public static function models(string $provider): array
    {
        return (array) self::provider($provider)['models'];
    }

    public static function normalizeProvider(?string $provider): string
    {
        $provider = strtolower(trim((string) $provider));
        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'gemini';
    }

    /**
     * Hasilkan teks dari provider aktif.
     *
     * @return array{ok:bool,text:string,http_status:int,error_code:?string,error:?string,
     *               provider_message:?string,prompt_tokens:?int,response_tokens:?int,model:?string}
     */
    public static function generate(
        string $provider,
        string $apiKey,
        string $model,
        string $systemInstruction,
        string $input,
        int $maxOutputTokens,
        ?string $apiBase = null,
        ?float $timeout = null,
        ?array $conversation = null
    ): array {
        $provider = self::normalizeProvider($provider);
        if ((string) self::provider($provider)['protocol'] === 'gemini') {
            return GeminiService::generate(
                $apiKey,
                $model,
                $systemInstruction,
                $input,
                $maxOutputTokens,
                $apiBase,
                $timeout,
                self::conversationText($conversation)
            );
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return self::failure('API_KEY_MISSING', 'API key ' . self::label($provider) . ' belum diatur.');
        }

        $messages = [['role' => 'system', 'content' => $systemInstruction]];
        foreach ((array) $conversation as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($role === 'context') {
                $messages[] = ['role' => 'system', 'content' => $text];
            } elseif ($role === 'user' || $role === 'assistant') {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $input];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2,
            'max_tokens'  => max(32, min(4096, $maxOutputTokens)),
            'stream'      => false,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return self::failure('REQUEST_ERROR', 'Gagal menyusun permintaan ke ' . self::label($provider) . '.');
        }

        $url = self::endpoint($provider, $apiBase, '/chat/completions');
        $result = self::request('POST', $url, $apiKey, $body, $timeout ?? self::DEFAULT_TIMEOUT, $provider);
        if ($result['transport_error'] !== null) {
            return self::failure($result['error_code'], $result['transport_error']);
        }

        $status = $result['status'];
        $decoded = json_decode($result['body'], true);
        if ($status < 200 || $status >= 300) {
            return self::httpFailure($provider, $status, is_array($decoded) ? $decoded : null, $apiKey);
        }
        if (!is_array($decoded)) {
            return self::failure('BAD_RESPONSE', 'Respons ' . self::label($provider) . ' tidak dapat dibaca.', $status);
        }

        $text = self::extractText($decoded);
        if ($text === '') {
            return self::failure('EMPTY_RESPONSE', self::label($provider) . ' tidak memberikan jawaban.', $status);
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        return [
            'ok'               => true,
            'text'             => $text,
            'http_status'      => $status,
            'error_code'       => null,
            'error'            => null,
            'provider_message' => null,
            'prompt_tokens'    => self::intOrNull($usage['prompt_tokens'] ?? null),
            'response_tokens'  => self::intOrNull($usage['completion_tokens'] ?? null),
            'model'            => isset($decoded['model']) ? (string) $decoded['model'] : $model,
        ];
    }

    /**
     * Uji API key + model tanpa menghasilkan konten.
     *
     * @return array{ok:bool,http_status:int,error_code:?string,error:?string,
     *               provider_message:?string,display_name:?string,model:string,
     *               provider:string,provider_label:string}
     */
    public static function testConnection(
        string $provider,
        string $apiKey,
        string $model,
        ?string $apiBase = null,
        ?float $timeout = null
    ): array {
        $provider = self::normalizeProvider($provider);
        if ((string) self::provider($provider)['protocol'] === 'gemini') {
            $result = GeminiService::testConnection($apiKey, $model, $apiBase, $timeout);
            $result['provider'] = $provider;
            $result['provider_label'] = self::label($provider);
            return $result;
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return self::testFailure($provider, 'API_KEY_MISSING', 'API key ' . self::label($provider) . ' belum diatur.', $model);
        }

        $url = self::endpoint($provider, $apiBase, '/models');
        $result = self::request('GET', $url, $apiKey, null, $timeout ?? self::DEFAULT_TIMEOUT, $provider);
        if ($result['transport_error'] !== null) {
            return self::testFailure($provider, $result['error_code'], $result['transport_error'], $model);
        }

        $status = $result['status'];
        $decoded = json_decode($result['body'], true);
        if ($status < 200 || $status >= 300) {
            $failure = self::httpFailure($provider, $status, is_array($decoded) ? $decoded : null, $apiKey);
            return self::testFailure($provider, (string) $failure['error_code'], (string) $failure['error'], $model, $status, $failure['provider_message']);
        }

        $display = $model;
        if (is_array($decoded) && is_array($decoded['data'] ?? null)) {
            foreach ($decoded['data'] as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $model) {
                    $display = (string) ($row['name'] ?? $row['id'] ?? $model);
                    break;
                }
            }
        }

        return [
            'ok' => true, 'http_status' => $status, 'error_code' => null,
            'error' => null, 'provider_message' => null,
            'display_name' => $display, 'model' => $model,
            'provider' => $provider, 'provider_label' => self::label($provider),
        ];
    }

    public static function endpoint(string $provider, ?string $apiBase, string $suffix): string
    {
        $base = trim((string) $apiBase);
        if ($base === '') {
            $base = self::defaultBase($provider);
        }
        return rtrim($base, '/') . $suffix;
    }

    /**
     * Format riwayat menjadi teks untuk Interactions API Gemini (input string).
     *
     * @param array<int,array<string,mixed>>|null $conversation
     */
    private static function conversationText(?array $conversation): string
    {
        $lines = [];
        foreach ((array) $conversation as $message) {
            if (!is_array($message)) {
                continue;
            }
            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $label = $role === 'user' ? 'Pengguna' : ($role === 'assistant' ? 'Asisten' : 'Konteks');
            $lines[] = $label . ': ' . $text;
        }
        return implode("\n\n", $lines);
    }

    /** @param array<string,mixed> $decoded */
    private static function extractText(array $decoded): string
    {
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            return trim($content);
        }
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && (string) ($part['type'] ?? '') === 'text') {
                    $text .= (string) ($part['text'] ?? '');
                } elseif (is_string($part)) {
                    $text .= $part;
                }
            }
            return trim($text);
        }
        return '';
    }

    /**
     * @return array{status:int,body:string,transport_error:?string,error_code:?string}
     */
    private static function request(
        string $method,
        string $url,
        string $apiKey,
        ?string $body,
        float $timeout,
        string $provider
    ): array {
        if (!function_exists('curl_init')) {
            return [
                'status' => 0, 'body' => '',
                'transport_error' => 'Ekstensi cURL PHP tidak tersedia. Hubungi Admin.',
                'error_code' => 'CURL_MISSING',
            ];
        }

        $seconds = max(2, (int) ceil($timeout));
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT => min(10, $seconds),
            CURLOPT_TIMEOUT        => $seconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SmartPartMan/1.0',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($response === false || $errno !== 0) {
            [$code, $message] = match ($errno) {
                28      => ['TIMEOUT', 'Koneksi ke ' . self::label($provider) . ' melebihi batas waktu.'],
                6, 7    => ['UNAVAILABLE', 'Layanan ' . self::label($provider) . ' tidak dapat dihubungi.'],
                35, 60  => ['SSL_ERROR', 'Koneksi aman ke ' . self::label($provider) . ' gagal (SSL).'],
                default => ['NETWORK_ERROR', 'Koneksi ke ' . self::label($provider) . ' gagal.'],
            };
            unset($error);
            return [
                'status' => $status, 'body' => '',
                'transport_error' => $message, 'error_code' => $code,
            ];
        }

        return [
            'status' => $status,
            'body' => (string) $response,
            'transport_error' => null,
            'error_code' => null,
        ];
    }

    /**
     * @param array<string,mixed>|null $decoded
     * @return array{ok:false,text:string,http_status:int,error_code:string,error:string,
     *               provider_message:?string,prompt_tokens:null,response_tokens:null,model:null}
     */
    private static function httpFailure(string $provider, int $status, ?array $decoded, string $apiKey): array
    {
        $label = self::label($provider);
        [$code, $message] = match (true) {
            in_array($status, [401, 403], true) => ['API_KEY_INVALID', 'API key ' . $label . ' tidak valid atau tidak memiliki izin.'],
            $status === 404                     => ['MODEL_NOT_FOUND', 'Model ' . $label . ' tidak tersedia untuk API key ini.'],
            $status === 429                     => ['RATE_LIMIT', 'Batas penggunaan ' . $label . ' tercapai. Coba lagi beberapa saat lagi.'],
            $status === 400                     => ['BAD_REQUEST', 'Permintaan ke ' . $label . ' tidak valid. Periksa model dan pengaturan.'],
            $status >= 500                      => ['UNAVAILABLE', 'Layanan ' . $label . ' sedang tidak tersedia.'],
            default                             => ['HTTP_ERROR', $label . ' mengembalikan respons yang tidak diharapkan.'],
        };

        $providerMessage = null;
        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            $providerMessage = self::sanitize((string) ($decoded['error']['message'] ?? ''), $apiKey);
        }

        return self::failure($code, $message, $status, $providerMessage);
    }

    /**
     * @return array{ok:false,text:string,http_status:int,error_code:string,error:string,
     *               provider_message:?string,prompt_tokens:null,response_tokens:null,model:null}
     */
    private static function failure(string $code, string $message, int $status = 0, ?string $provider = null): array
    {
        return [
            'ok'               => false,
            'text'             => '',
            'http_status'      => $status,
            'error_code'       => $code,
            'error'            => $message,
            'provider_message' => $provider,
            'prompt_tokens'    => null,
            'response_tokens'  => null,
            'model'            => null,
        ];
    }

    /**
     * @return array{ok:false,http_status:int,error_code:string,error:string,
     *               provider_message:?string,display_name:null,model:string,
     *               provider:string,provider_label:string}
     */
    private static function testFailure(
        string $provider,
        string $code,
        string $message,
        string $model,
        int $status = 0,
        ?string $providerMessage = null
    ): array {
        return [
            'ok' => false, 'http_status' => $status, 'error_code' => $code,
            'error' => $message, 'provider_message' => $providerMessage,
            'display_name' => null, 'model' => $model,
            'provider' => $provider, 'provider_label' => self::label($provider),
        ];
    }

    private static function sanitize(string $value, string $apiKey): string
    {
        if ($apiKey !== '' && strlen($apiKey) >= 6) {
            $value = str_replace($apiKey, '[REDACTED]', $value);
        }
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 200);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
