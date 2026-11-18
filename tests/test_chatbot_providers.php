<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\ChatbotSetting;
use App\Services\AiProviderService;
use App\Services\ChatbotService;
use App\Services\ChatbotSettingsService;

require_once __DIR__ . '/gemini_stub.php';

/**
 * Pengujian multi-provider Pengaturan AI Chatbot (migrasi 019).
 *
 * Memastikan API key setiap provider tersimpan terpisah, pergantian provider
 * tidak menghapus kunci lain, Base URL wajib HTTPS, kunci tidak bocor, dan
 * chatbot memakai provider aktif.
 */

/** @return array{settings:array<string,mixed>,providers:array<int,array<string,mixed>>} */
function t_chat_provider_snapshot(): array
{
    ChatbotSetting::ensureRow();
    return [
        'settings'  => ChatbotSetting::current() ?? [],
        'providers' => ChatbotSetting::hasProviderTable()
            ? Database::pdo()->query('SELECT * FROM chatbot_providers')->fetchAll()
            : [],
    ];
}

/** @param array{settings:array<string,mixed>,providers:array<int,array<string,mixed>>} $snapshot */
function t_chat_provider_restore(array $snapshot): void
{
    $row = $snapshot['settings'] ?? [];
    if ($row !== []) {
        $hasProvider = ChatbotSetting::hasProviderSchema();
        $sql = 'UPDATE chatbot_settings SET is_enabled=?, model=?, api_key_encrypted=?, api_key_masked=?,
                    system_prompt=?, topic_limits=?, answer_style=?, usage_limit_daily=?, usage_limit_monthly=?'
            . ($hasProvider ? ', provider=?, api_base=?' : '')
            . ' WHERE id=1';
        $params = [
            $row['is_enabled'], $row['model'], $row['api_key_encrypted'], $row['api_key_masked'],
            $row['system_prompt'], $row['topic_limits'], $row['answer_style'],
            $row['usage_limit_daily'], $row['usage_limit_monthly'],
        ];
        if ($hasProvider) {
            $params[] = (string) ($row['provider'] ?? 'gemini');
            $params[] = $row['api_base'] ?? null;
        }
        Database::pdo()->prepare($sql)->execute($params);
    }

    if (ChatbotSetting::hasProviderTable()) {
        Database::pdo()->exec('DELETE FROM chatbot_providers');
        $insert = Database::pdo()->prepare(
            'INSERT INTO chatbot_providers (provider, api_key_encrypted, api_key_masked, model, api_base, updated_by)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        foreach (($snapshot['providers'] ?? []) as $providerRow) {
            $insert->execute([
                $providerRow['provider'], $providerRow['api_key_encrypted'], $providerRow['api_key_masked'],
                $providerRow['model'], $providerRow['api_base'],
            ]);
        }
    }

    Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
}

/** @return array<string,mixed> */
function t_chat_provider_input(array $overrides = []): array
{
    $s = ChatbotSettingsService::current();
    return array_merge([
        'is_enabled'          => '1',
        'provider'            => 'gemini',
        'model'               => 'gemini-3.5-flash-lite',
        'api_base'            => '',
        'system_prompt'       => $s['system_prompt'],
        'topic_limits'        => $s['topic_limits'],
        'answer_style'        => 'ringkas',
        'usage_limit_daily'   => '',
        'usage_limit_monthly' => '',
    ], $overrides);
}

function test_chatbot_provider_penyimpanan_terpisah(): void
{
    $before = t_chat_provider_snapshot();
    $actor = t_make_user('chat_provider_admin', 'admin', t_password());
    $geminiKey = 'GEMINI-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
    $deepseekKey = 'DEEPSEEK-ABCDEFGHIJKLMNOPQRSTUVWXYZ12';
    $openaiKey = 'OPENAI-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';

    try {
        t_chat_provider_restore($before);

        ChatbotSettingsService::save(t_chat_provider_input(['api_key' => $geminiKey]), $actor);
        $geminiBefore = ChatbotSetting::current() ?? [];
        t_true(str_starts_with((string) ($geminiBefore['api_key_encrypted'] ?? ''), 'v1:'), 'Kunci Gemini tersimpan terenkripsi');

        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'api_key' => $deepseekKey,
        ]), $actor);
        $deepseekRow = ChatbotSetting::providerRow('deepseek') ?? [];
        t_eq('deepseek', (string) (ChatbotSetting::current()['provider'] ?? ''), 'Provider aktif tersimpan = deepseek');
        t_eq('deepseek-chat', (string) ($deepseekRow['model'] ?? ''), 'Model DeepSeek tersimpan pada baris provider');
        t_true(str_starts_with((string) ($deepseekRow['api_key_encrypted'] ?? ''), 'v1:'), 'Kunci DeepSeek terenkripsi');
        t_eq('****' . substr($deepseekKey, -4), (string) ($deepseekRow['api_key_masked'] ?? ''), 'Mask DeepSeek memakai 4 karakter akhir');
        t_true(!str_contains((string) ($deepseekRow['api_key_encrypted'] ?? ''), $deepseekKey), 'Plaintext DeepSeek tidak ada di database');
        t_eq(
            (string) ($geminiBefore['api_key_encrypted'] ?? ''),
            (string) (ChatbotSetting::current()['api_key_encrypted'] ?? ''),
            'Menyimpan DeepSeek tidak mengubah kunci Gemini'
        );

        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $openaiKey,
        ]), $actor);
        $openaiRow = ChatbotSetting::providerRow('openai') ?? [];
        t_eq('openai', (string) (ChatbotSetting::current()['provider'] ?? ''), 'Provider aktif tersimpan = openai');
        t_eq('gpt-4o-mini', (string) ($openaiRow['model'] ?? ''), 'Model OpenAI tersimpan');
        t_true(str_starts_with((string) ($openaiRow['api_key_encrypted'] ?? ''), 'v1:'), 'Kunci OpenAI terenkripsi');
        t_eq(
            (string) ($deepseekRow['api_key_encrypted'] ?? ''),
            (string) ((ChatbotSetting::providerRow('deepseek')['api_key_encrypted'] ?? '')),
            'Menyimpan OpenAI tidak mengubah kunci DeepSeek'
        );
        t_eq(
            (string) ($geminiBefore['api_key_encrypted'] ?? ''),
            (string) (ChatbotSetting::current()['api_key_encrypted'] ?? ''),
            'Menyimpan OpenAI tidak mengubah kunci Gemini'
        );

        $state = ChatbotSettingsService::current();
        t_true((bool) ($state['providers']['gemini']['has_key'] ?? false), 'Status Gemini tetap memiliki kunci');
        t_true((bool) ($state['providers']['deepseek']['has_key'] ?? false), 'Status DeepSeek memiliki kunci');
        t_true((bool) ($state['providers']['openai']['has_key'] ?? false), 'Status OpenAI memiliki kunci');
        t_true(!str_contains(json_encode($state) ?: '', $geminiKey), 'Struktur pengaturan tidak memuat kunci Gemini');
        t_true(!str_contains(json_encode($state) ?: '', $deepseekKey), 'Struktur pengaturan tidak memuat kunci DeepSeek');
        t_true(!str_contains(json_encode($state) ?: '', $openaiKey), 'Struktur pengaturan tidak memuat kunci OpenAI');
    } finally {
        t_chat_provider_restore($before);
    }
}

function test_chatbot_provider_switch_tidak_menghapus_key(): void
{
    $before = t_chat_provider_snapshot();
    $actor = t_make_user('chat_provider_switch', 'admin', t_password());
    $geminiKey = 'GEMINI-SWITCH-ABCDEFGHIJKLMNOPQRSTU12';
    $deepseekKey = 'DEEPSEEK-SWITCH-ABCDEFGHIJKLMNOPQRST12';

    try {
        t_chat_provider_restore($before);
        ChatbotSettingsService::save(t_chat_provider_input(['api_key' => $geminiKey]), $actor);
        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'api_key' => $deepseekKey,
        ]), $actor);

        $geminiEncrypted = (string) (ChatbotSetting::current()['api_key_encrypted'] ?? '');
        $deepseekEncrypted = (string) (ChatbotSetting::providerRow('deepseek')['api_key_encrypted'] ?? '');

        // Pindah kembali ke Gemini tanpa mengisi key baru.
        ChatbotSettingsService::save(t_chat_provider_input(['provider' => 'gemini']), $actor);
        t_eq('gemini', (string) (ChatbotSetting::current()['provider'] ?? ''), 'Pindah ke Gemini tanpa key baru berhasil');
        t_eq($geminiEncrypted, (string) (ChatbotSetting::current()['api_key_encrypted'] ?? ''), 'Kunci Gemini lama dipertahankan');
        t_eq($deepseekEncrypted, (string) (ChatbotSetting::providerRow('deepseek')['api_key_encrypted'] ?? ''), 'Kunci DeepSeek tidak terhapus saat pindah provider');

        // Pindah lagi ke DeepSeek tanpa mengisi key baru.
        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
        ]), $actor);
        t_eq('deepseek', (string) (ChatbotSetting::current()['provider'] ?? ''), 'Pindah kembali ke DeepSeek berhasil');
        t_eq($deepseekEncrypted, (string) (ChatbotSetting::providerRow('deepseek')['api_key_encrypted'] ?? ''), 'Kunci DeepSeek lama dipertahankan');
        t_eq($geminiEncrypted, (string) (ChatbotSetting::current()['api_key_encrypted'] ?? ''), 'Kunci Gemini tetap utuh');
        t_true(ChatbotSettingsService::apiKey('deepseek') !== '', 'Service dapat mendekripsi kunci DeepSeek');
        t_true(ChatbotSettingsService::apiKey('gemini') !== '', 'Service dapat mendekripsi kunci Gemini');
    } finally {
        t_chat_provider_restore($before);
    }
}

function test_chatbot_provider_uji_koneksi_dan_dispatch(): void
{
    $before = t_chat_provider_snapshot();
    $actor = t_make_user('chat_provider_conn', 'admin', t_password());
    $stub = null;
    $deepseekKey = 'DEEPSEEK-CONN-ABCDEFGHIJKLMNOPQRSTUV12';

    try {
        t_chat_provider_restore($before);
        $stub = t_gemini_stub_start();
        $base = $stub['base'];

        $conn = ChatbotSettingsService::testConnection([
            'provider'     => 'deepseek',
            'model'        => 'deepseek-chat',
            'api_key_test' => $deepseekKey,
        ], $actor, $base, 5.0);

        t_true((bool) ($conn['ok'] ?? false), 'Uji koneksi DeepSeek dengan stub berhasil');
        t_eq('deepseek', (string) ($conn['provider'] ?? ''), 'Hasil uji mencatat provider DeepSeek');
        t_true(!str_contains(json_encode($conn) ?: '', $deepseekKey), 'Hasil uji DeepSeek tidak membocorkan API key');

        $requests = t_gemini_stub_requests($stub);
        $last = end($requests) ?: [];
        t_eq('GET', (string) ($last['method'] ?? ''), 'Uji koneksi DeepSeek memakai GET /models');
        t_true(str_ends_with(rtrim((string) ($last['path'] ?? ''), '/'), '/models'), 'Path uji DeepSeek berakhir /models');
        t_eq(strlen($deepseekKey), (int) ($last['key_len'] ?? 0), 'Kunci DeepSeek dikirim lewat header Authorization');

        // Simpan konfigurasi DeepSeek lalu arahkan Base URL ke stub (fixture uji).
        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'api_key' => $deepseekKey,
        ]), $actor);
        Database::pdo()->prepare('UPDATE chatbot_providers SET api_base = ? WHERE provider = ?')
            ->execute([$base, 'deepseek']);
        Database::pdo()->prepare('UPDATE chatbot_settings SET usage_limit_daily = NULL, usage_limit_monthly = NULL WHERE id = 1')
            ->execute();

        $answer = ChatbotService::respond('Bagaimana cara menambah sparepart baru?', ['id' => $actor, 'role' => 'admin']);
        t_eq('ok', (string) ($answer['status'] ?? ''), 'Chatbot menjawab melalui provider DeepSeek aktif');
        t_eq('deepseek', (string) ($answer['source'] ?? ''), 'Sumber jawaban tercatat deepseek');
        t_true(str_contains((string) ($answer['answer'] ?? ''), 'Jawaban uji DeepSeek'), 'Jawaban berasal dari endpoint OpenAI-compatible stub');

        $requests = t_gemini_stub_requests($stub);
        $last = end($requests) ?: [];
        t_eq('POST', (string) ($last['method'] ?? ''), 'Generate DeepSeek memakai POST /chat/completions');
        t_true(str_ends_with(rtrim((string) ($last['path'] ?? ''), '/'), '/chat/completions'), 'Path generate berakhir /chat/completions');
    } finally {
        if (is_array($stub)) {
            t_gemini_stub_cleanup($stub);
        }
        t_chat_provider_restore($before);
    }
}

function test_chatbot_provider_base_url_dan_kerahasiaan(): void
{
    $before = t_chat_provider_snapshot();
    $actor = t_make_user('chat_provider_base', 'admin', t_password());

    try {
        t_chat_provider_restore($before);
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_provider_input([
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'api_base' => 'http://api.deepseek.com/v1',
                'api_key' => 'DEEPSEEK-HTTP-ABCDEFGHIJKLMNOPQRSTUV12',
            ]), $actor),
            RuntimeException::class,
            'Base URL HTTP ditolak (wajib HTTPS)'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_provider_input([
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_base' => 'bukan-url',
                'api_key' => 'OPENAI-BAD-ABCDEFGHIJKLMNOPQRSTUV123',
            ]), $actor),
            RuntimeException::class,
            'Base URL tidak valid ditolak'
        );

        $state = ChatbotSettingsService::current();
        t_eq('gemini', (string) ($state['provider'] ?? ''), 'Validasi gagal tidak mengganti provider aktif');
        t_true(!str_contains(json_encode($state) ?: '', 'GEMINI-'), 'Struktur pengaturan tidak memuat plaintext kunci');
        t_true(array_key_exists('gemini', AiProviderService::PROVIDERS), 'Katalog provider memuat Gemini');
        t_true(array_key_exists('deepseek', AiProviderService::PROVIDERS), 'Katalog provider memuat DeepSeek');
        t_true(array_key_exists('openai', AiProviderService::PROVIDERS), 'Katalog provider memuat OpenAI');
        t_eq('https://api.deepseek.com/v1', AiProviderService::defaultBase('deepseek'), 'Default Base URL DeepSeek berupa HTTPS');
    } finally {
        t_chat_provider_restore($before);
    }
}
