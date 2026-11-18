<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\ChatbotSetting;
use App\Services\ChatbotSettingsService;

require_once __DIR__ . '/test_chatbot_providers.php';

/**
 * Regresi: provider non-Gemini tanpa API key tidak boleh diaktifkan.
 */
function test_chatbot_provider_tanpa_key_tidak_boleh_aktif(): void
{
    $before = t_chat_provider_snapshot();
    $actor = t_make_user('chat_provider_nokey', 'admin', t_password());

    try {
        t_chat_provider_restore($before);
        Database::pdo()->exec("DELETE FROM chatbot_providers WHERE provider IN ('deepseek','openai')");

        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_provider_input([
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
            ]), $actor),
            RuntimeException::class,
            'DeepSeek tanpa API key ditolak'
        );
        t_true(
            (string) (ChatbotSetting::current()['provider'] ?? '') !== 'deepseek',
            'Provider tetap bukan DeepSeek setelah percobaan tanpa key'
        );
        t_eq(null, ChatbotSetting::providerRow('deepseek'), 'Tidak ada baris provider DeepSeek tanpa key');

        ChatbotSettingsService::save(t_chat_provider_input([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'OPENAI-GUARD-ABCDEFGHIJKLMNOPQRST12',
        ]), $actor);
        t_eq('openai', (string) (ChatbotSetting::current()['provider'] ?? ''), 'OpenAI dengan API key dapat diaktifkan');

        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_provider_input([
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'remove_api_key' => '1',
            ]), $actor),
            RuntimeException::class,
            'Menghapus API key provider aktif ditolak'
        );
        t_true(ChatbotSettingsService::apiKey('openai') !== '', 'API key OpenAI tetap tersimpan setelah percobaan hapus ditolak');
    } finally {
        t_chat_provider_restore($before);
        Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$actor]);
    }
}
