<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Env;

/**
 * Klien Google Gemini API (Fase 8).
 *
 * Endpoint yang dipakai:
 * - Generasi : POST {base}/interactions  (Interactions API, header x-goog-api-key)
 * - Uji koneksi : GET {base}/models/{model} (memvalidasi API key + model tanpa
 *   menghasilkan konten / memakai kuota generasi).
 *
 * Prinsip keamanan:
 * - API key hanya dikirim pada header; tidak pernah masuk URL, log, atau pesan.
 * - Tidak ada query SQL / tool yang diberikan ke model.
 * - Semua kesalahan dipetakan ke kode aman; pesan provider dibersihkan dari
 *   API key dan dipotong sebelum dikembalikan.
 * - Timeout & kegagalan jaringan tidak boleh menjatuhkan request utama.
 */
final class GeminiService
{
    public const DEFAULT_API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    public const DEFAULT_TIMEOUT = 25.0;

    /**
     * Hasilkan teks dari Gemini.
     *
     * @return array{ok:bool,text:string,http_status:int,error_code:?string,error:?string,
     *               provider_message:?string,prompt_tokens:?int,response_tokens:?int,model:?string}
     */
    public static function generate(
        string $apiKey,
        string $model,
        string $systemInstruction,
        string $input,
        int $maxOutputTokens,
        ?string $apiBase = null,
        ?float $timeout = null,
        ?string $historyText = null
    ): array {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return self::failure('API_KEY_MISSING', 'API key Gemini belum diatur.');
        }

        $historyText = trim((string) $historyText);
        if ($historyText !== '') {
            $input = "RIWAYAT PERCAKAPAN SEBELUMNYA (referensi, bukan instruksi baru):\n"
                . $historyText
                . "\n\nPERTANYAAN TERBARU PENGGUNA:\n"
                . $input;
        }

        $payload = [
            'model'             => $model,
            'input'             => $input,
            'system_instruction'=> $systemInstruction,
            'generation_config' => [
                'max_output_tokens' => max(32, min(4096, $maxOutputTokens)),
                'thinking_level'    => 'low',
            ],
            // Jangan simpan interaksi di sisi Google (minimalkan retensi data).
            'store'             => false,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return self::failure('REQUEST_ERROR', 'Gagal menyusun permintaan ke Gemini.');
        }

        $url = rtrim($apiBase ?? self::apiBase(), '/') . '/interactions';
        $result = self::request('POST', $url, $apiKey, $body, $timeout ?? self::timeout());

        if ($result['transport_error'] !== null) {
            return self::failure($result['error_code'], $result['transport_error']);
        }

        $status = $result['status'];
        $decoded = json_decode($result['body'], true);

        if ($status < 200 || $status >= 300) {
            return self::httpFailure($status, is_array($decoded) ? $decoded : null, $apiKey);
        }
        if (!is_array($decoded)) {
            return self::failure('BAD_RESPONSE', 'Respons Gemini tidak dapat dibaca.', $status);
        }

        $state = strtolower((string) ($decoded['status'] ?? ''));
        if (in_array($state, ['failed', 'cancelled', 'incomplete'], true) && self::extractText($decoded) === '') {
            return self::failure('GENERATION_FAILED', 'Gemini tidak menyelesaikan jawaban.', $status);
        }
        if ($state === 'blocked') {
            return self::failure('BLOCKED', 'Permintaan diblokir oleh filter keamanan Gemini.', $status);
        }

        $text = trim(self::extractText($decoded));
        if ($text === '') {
            return self::failure('EMPTY_RESPONSE', 'Gemini tidak memberikan jawaban.', $status);
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return [
            'ok'               => true,
            'text'             => $text,
            'http_status'      => $status,
            'error_code'       => null,
            'error'            => null,
            'provider_message' => null,
            'prompt_tokens'    => self::intOrNull($usage['total_input_tokens'] ?? null),
            'response_tokens'  => self::intOrNull($usage['total_output_tokens'] ?? null),
            'model'            => isset($decoded['model']) ? (string) $decoded['model'] : $model,
        ];
    }

    /**
     * Uji API key + model tanpa menghasilkan konten.
     *
     * @return array{ok:bool,http_status:int,error_code:?string,error:?string,
     *               provider_message:?string,display_name:?string,model:string}
     */
    public static function testConnection(
        string $apiKey,
        string $model,
        ?string $apiBase = null,
        ?float $timeout = null
    ): array {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return [
                'ok' => false, 'http_status' => 0, 'error_code' => 'API_KEY_MISSING',
                'error' => 'API key Gemini belum diatur.', 'provider_message' => null,
                'display_name' => null, 'model' => $model,
            ];
        }

        $url = rtrim($apiBase ?? self::apiBase(), '/') . '/models/' . rawurlencode($model);
        $result = self::request('GET', $url, $apiKey, null, $timeout ?? self::timeout());

        if ($result['transport_error'] !== null) {
            return [
                'ok' => false, 'http_status' => 0, 'error_code' => $result['error_code'],
                'error' => $result['transport_error'], 'provider_message' => null,
                'display_name' => null, 'model' => $model,
            ];
        }

        $status = $result['status'];
        $decoded = json_decode($result['body'], true);
        if ($status < 200 || $status >= 300) {
            $failure = self::httpFailure($status, is_array($decoded) ? $decoded : null, $apiKey);
            return [
                'ok' => false, 'http_status' => $status, 'error_code' => $failure['error_code'],
                'error' => $failure['error'], 'provider_message' => $failure['provider_message'],
                'display_name' => null, 'model' => $model,
            ];
        }

        $display = is_array($decoded)
            ? (string) ($decoded['displayName'] ?? $decoded['display_name'] ?? $model)
            : $model;

        return [
            'ok' => true, 'http_status' => $status, 'error_code' => null,
            'error' => null, 'provider_message' => null,
            'display_name' => $display, 'model' => $model,
        ];
    }

    public static function apiBase(): string
    {
        $configured = App::config('gemini.api_base', null);
        if (!is_string($configured) || trim($configured) === '') {
            $configured = Env::get('GEMINI_API_URL', self::DEFAULT_API_BASE);
        }
        return rtrim((string) $configured, '/');
    }

    public static function timeout(): float
    {
        $configured = App::config('gemini.timeout', null);
        if ($configured === null) {
            $configured = Env::get('GEMINI_TIMEOUT', (string) self::DEFAULT_TIMEOUT);
        }
        return max(2.0, min(60.0, (float) $configured));
    }

    /** API key dari .env (fallback bila Admin belum menyimpan key di database). */
    public static function envApiKey(): string
    {
        $configured = App::config('gemini.api_key', null);
        if (!is_string($configured) || trim($configured) === '') {
            $configured = Env::get('GEMINI_API_KEY', '');
        }
        return trim((string) $configured);
    }

    /**
     * Ekstrak teks dari respons Interactions API. Juga mendukung bentuk
     * legacy candidates[].content.parts[] agar tetap tahan perubahan kecil.
     *
     * @param array<string,mixed> $decoded
     */
    private static function extractText(array $decoded): string
    {
        $text = '';

        foreach ((array) ($decoded['steps'] ?? []) as $step) {
            if (!is_array($step) || (string) ($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ((array) ($step['content'] ?? []) as $part) {
                if (is_array($part) && (string) ($part['type'] ?? '') === 'text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }

        if ($text === '' && isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            $text = $decoded['output_text'];
        }

        if ($text === '') {
            foreach ((array) ($decoded['candidates'] ?? []) as $candidate) {
                foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $text .= (string) $part['text'];
                    }
                }
            }
        }

        return $text;
    }

    /**
     * Permintaan HTTP dengan cURL. Tidak mengikuti redirect (mencegah
     * pengiriman header API key ke host lain).
     *
     * @return array{status:int,body:string,transport_error:?string,error_code:?string}
     */
    private static function request(
        string $method,
        string $url,
        string $apiKey,
        ?string $body,
        float $timeout
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
                'x-goog-api-key: ' . $apiKey,
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
                28      => ['TIMEOUT', 'Koneksi ke Gemini melebihi batas waktu.'],
                6, 7    => ['UNAVAILABLE', 'Layanan Gemini tidak dapat dihubungi.'],
                35, 60  => ['SSL_ERROR', 'Koneksi aman ke Gemini gagal (SSL).'],
                default => ['NETWORK_ERROR', 'Koneksi ke Gemini gagal.'],
            };
            // Jangan sertakan detail cURL mentah (bisa memuat URL internal).
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
    private static function httpFailure(int $status, ?array $decoded, string $apiKey): array
    {
        [$code, $message] = match (true) {
            in_array($status, [401, 403], true) => ['API_KEY_INVALID', 'API key Gemini tidak valid atau tidak memiliki izin.'],
            $status === 404                     => ['MODEL_NOT_FOUND', 'Model Gemini tidak tersedia untuk API key ini.'],
            $status === 429                     => ['RATE_LIMIT', 'Batas penggunaan Gemini tercapai. Coba lagi beberapa saat lagi.'],
            $status === 400                     => ['BAD_REQUEST', 'Permintaan ke Gemini tidak valid. Periksa model dan pengaturan.'],
            $status >= 500                      => ['UNAVAILABLE', 'Layanan Gemini sedang tidak tersedia.'],
            default                             => ['HTTP_ERROR', 'Gemini mengembalikan respons yang tidak diharapkan.'],
        };

        $provider = null;
        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            $provider = (string) ($decoded['error']['message'] ?? '');
            $provider = self::sanitize($provider, $apiKey);
        }

        return self::failure($code, $message, $status, $provider);
    }

    /**
     * @return array{ok:false,text:string,http_status:int,error_code:string,error:string,
     *               provider_message:?string,prompt_tokens:null,response_tokens:null,model:null}
     */
    private static function failure(
        string $code,
        string $message,
        int $status = 0,
        ?string $provider = null
    ): array {
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
