<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\ChatbotSetting;
use App\Models\ChatbotUsageLog;
use App\Services\ChatbotSettingsService;

require_once __DIR__ . '/gemini_stub.php';

/**
 * Pengujian Fase 8 — pengaturan chatbot, enkripsi API key, audit, dan uji koneksi.
 */

/** @return array<string,mixed> */
function t_chat_settings_snapshot(): array
{
    ChatbotSetting::ensureRow();
    return ChatbotSetting::current() ?? [];
}

/** @param array<string,mixed> $row */
function t_chat_settings_restore(array $row): void
{
    if ($row === []) {
        return;
    }
    Database::pdo()->prepare(
        'UPDATE chatbot_settings SET is_enabled=?, model=?, api_key_encrypted=?, api_key_masked=?,
                system_prompt=?, topic_limits=?, answer_style=?, usage_limit_daily=?, usage_limit_monthly=?
          WHERE id=1'
    )->execute([
        $row['is_enabled'], $row['model'], $row['api_key_encrypted'], $row['api_key_masked'],
        $row['system_prompt'], $row['topic_limits'], $row['answer_style'],
        $row['usage_limit_daily'], $row['usage_limit_monthly'],
    ]);
    Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
}

/** @return array<string,mixed> */
function t_chat_settings_input(array $overrides = []): array
{
    $s = ChatbotSettingsService::current();
    return array_merge([
        'is_enabled'          => '1',
        'model'               => 'gemini-3.5-flash-lite',
        'system_prompt'       => $s['system_prompt'],
        'topic_limits'        => $s['topic_limits'],
        'answer_style'        => 'ringkas',
        'usage_limit_daily'   => '10',
        'usage_limit_monthly' => '100',
    ], $overrides);
}

function test_chatbot_settings_simpan_enkripsi_dan_audit(): void
{
    $before = t_chat_settings_snapshot();
    $actor = t_make_user('chat_settings_admin', 'admin', t_password());
    $key = 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';

    try {
        t_chat_settings_restore($before);
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');

        $changed = ChatbotSettingsService::save(t_chat_settings_input(['api_key' => $key]), $actor);
        t_true(in_array('api_key', $changed, true), 'Perubahan API key terdeteksi');
        t_true(in_array('is_enabled', $changed, true), 'Perubahan status aktif terdeteksi');
        t_true(in_array('usage_limit_daily', $changed, true), 'Perubahan batas harian terdeteksi');

        $row = ChatbotSetting::current() ?? [];
        t_eq(1, (int) ($row['is_enabled'] ?? 0), 'Status chatbot aktif tersimpan');
        t_eq('gemini-3.5-flash-lite', (string) ($row['model'] ?? ''), 'Model tersimpan');
        t_true(str_starts_with((string) ($row['api_key_encrypted'] ?? ''), 'v1:'), 'API key disimpan sebagai ciphertext v1');
        t_true(!str_contains((string) ($row['api_key_encrypted'] ?? ''), $key), 'Plaintext API key TIDAK ada di database');
        t_eq('****1234', (string) ($row['api_key_masked'] ?? ''), 'Hanya 4 karakter terakhir yang disamarkan');
        t_eq('10', (string) ($row['usage_limit_daily'] ?? ''), 'Batas harian tersimpan');
        t_eq('100', (string) ($row['usage_limit_monthly'] ?? ''), 'Batas bulanan tersimpan');

        $current = ChatbotSettingsService::current();
        t_true((bool) $current['has_key'], 'Pengaturan melaporkan API key tersedia');
        t_eq('database', (string) $current['api_key_source'], 'Sumber API key = database');
        t_eq('****1234', (string) $current['api_key_masked'], 'Masked key ditampilkan');
        t_true(!str_contains(json_encode($current) ?: '', $key), 'Struktur pengaturan TIDAK memuat plaintext API key');
        t_eq($key, ChatbotSettingsService::apiKey(), 'Service dapat mendekripsi API key untuk memanggil Gemini');

        $audit = t_latest_audit('CHATBOT_SETTINGS_UPDATE');
        t_true($audit !== null, 'Perubahan pengaturan chatbot tercatat di Audit Log');
        t_true(
            $audit !== null && !str_contains((string) ($audit['new_values'] ?? ''), $key),
            'Audit Log TIDAK memuat plaintext API key'
        );
        t_true(
            $audit !== null && str_contains((string) ($audit['new_values'] ?? ''), 'kredensial_diubah'),
            'Audit mencatat flag kredensial diubah tanpa nilainya'
        );

        $removed = ChatbotSettingsService::save(t_chat_settings_input(['remove_api_key' => '1']), $actor);
        t_true(in_array('api_key', $removed, true), 'Penghapusan API key terdeteksi');
        $row = ChatbotSetting::current() ?? [];
        t_true(($row['api_key_encrypted'] ?? null) === null, 'API key terenkripsi dihapus');
        t_true(($row['api_key_masked'] ?? null) === null, 'Masked key dihapus');
        t_true(!ChatbotSettingsService::current()['has_key'], 'Pengaturan melaporkan key tidak ada setelah dihapus');
    } finally {
        t_chat_settings_restore($before);
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
    }
}

function test_chatbot_settings_validasi_input(): void
{
    $before = t_chat_settings_snapshot();
    $actor = t_make_user('chat_settings_validator', 'admin', t_password());

    try {
        t_chat_settings_restore($before);

        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['model' => 'model-palsu']), $actor),
            RuntimeException::class,
            'Model di luar daftar ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['answer_style' => 'panjang']), $actor),
            RuntimeException::class,
            'Gaya jawaban tidak valid ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['system_prompt' => 'pendek']), $actor),
            RuntimeException::class,
            'Instruksi sistem terlalu pendek ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['system_prompt' => str_repeat('a', 4001)]), $actor),
            RuntimeException::class,
            'Instruksi sistem melebihi batas ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['usage_limit_daily' => 'abc']), $actor),
            RuntimeException::class,
            'Batas harian bukan angka ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['usage_limit_monthly' => '100001']), $actor),
            RuntimeException::class,
            'Batas bulanan melebihi maksimum ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['api_key' => 'kunci dengan spasi']), $actor),
            RuntimeException::class,
            'API key mengandung spasi ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(t_chat_settings_input(['api_key' => 'pendek']), $actor),
            RuntimeException::class,
            'API key terlalu pendek ditolak'
        );
        t_throws(
            fn () => ChatbotSettingsService::save(
                t_chat_settings_input(['api_key' => 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234', 'remove_api_key' => '1']),
                $actor
            ),
            RuntimeException::class,
            'Hapus dan isi API key sekaligus ditolak'
        );

        $after = ChatbotSetting::current() ?? [];
        t_eq((string) ($before['model'] ?? ''), (string) ($after['model'] ?? ''), 'Validasi gagal tidak mengubah model');
        t_eq((string) ($before['api_key_encrypted'] ?? ''), (string) ($after['api_key_encrypted'] ?? ''), 'Validasi gagal tidak mengubah API key');
    } finally {
        t_chat_settings_restore($before);
    }
}

function test_chatbot_settings_normalisasi_model_lama(): void
{
    $before = t_chat_settings_snapshot();
    try {
        Database::pdo()->prepare('UPDATE chatbot_settings SET model = ? WHERE id = 1')->execute(['gemini-1.5-flash']);
        $current = ChatbotSettingsService::current();
        t_eq('gemini-3.5-flash-lite', (string) $current['model'], 'Model lama yang tidak tersedia dinormalisasi ke default');
        t_true(array_key_exists($current['model'], ChatbotSettingsService::MODELS), 'Model efektif selalu ada di daftar model');
    } finally {
        t_chat_settings_restore($before);
    }
}

function test_chatbot_settings_uji_koneksi_stub(): void
{
    $before = t_chat_settings_snapshot();
    $actor = t_make_user('chat_settings_testconn', 'admin', t_password());
    $stub = null;

    try {
        t_chat_settings_restore($before);
        $stub = t_gemini_stub_start();
        $key = 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';

        $ok = ChatbotSettingsService::testConnection(
            ['model' => 'gemini-3.5-flash-lite', 'api_key_test' => $key],
            $actor,
            $stub['base'],
            5.0
        );
        t_true((bool) $ok['ok'], 'Uji koneksi dengan stub berhasil');
        t_eq(200, (int) $ok['http_status'], 'Uji koneksi mengembalikan HTTP 200');
        t_true(str_contains((string) ($ok['display_name'] ?? ''), 'gemini-3.5-flash-lite'), 'Nama model dari respons stub dibaca');
        t_true(!str_contains(json_encode($ok) ?: '', $key), 'Hasil uji TIDAK memuat API key');

        $requests = t_gemini_stub_requests($stub);
        t_eq(1, count($requests), 'Stub menerima tepat satu permintaan uji');
        $req = $requests[0] ?? [];
        t_eq('GET', (string) ($req['method'] ?? ''), 'Uji koneksi memakai GET');
        t_true(str_contains((string) ($req['path'] ?? ''), '/v1beta/models/gemini-3.5-flash-lite'), 'Path model sesuai');
        t_eq(strlen($key), (int) ($req['key_len'] ?? 0), 'API key dikirim lewat header (panjang sesuai)');
        t_eq(substr($key, -4), (string) ($req['key_tail'] ?? ''), 'Header memuat API key yang benar (4 karakter akhir)');

        $invalid = ChatbotSettingsService::testConnection(
            ['model' => 'gemini-3.5-flash-lite', 'api_key_test' => 'INVALID-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'],
            $actor,
            $stub['base'],
            5.0
        );
        t_true(!$invalid['ok'], 'API key tidak valid terdeteksi');
        t_eq('API_KEY_INVALID', (string) $invalid['error_code'], 'Kode error API key tidak valid');
        t_eq(401, (int) $invalid['http_status'], 'HTTP 401 dipetakan dengan benar');

        $notFound = ChatbotSettingsService::testConnection(
            ['model' => 'gemini-3.5-flash-lite', 'api_key_test' => 'NOTFOUND-ABCDEFGHIJKLMNOPQRSTUVWXYZ12'],
            $actor,
            $stub['base'],
            5.0
        );
        t_true(!$notFound['ok'], 'Model tidak ditemukan terdeteksi');
        t_eq('MODEL_NOT_FOUND', (string) $notFound['error_code'], 'Kode error model tidak ditemukan');

        $audit = t_latest_audit('CHATBOT_TEST');
        t_true($audit !== null, 'Uji koneksi tercatat di Audit Log');
        t_true(
            $audit !== null && !str_contains((string) ($audit['new_values'] ?? ''), 'INVALID-'),
            'Audit uji koneksi tidak memuat API key'
        );
    } finally {
        if (is_array($stub)) {
            t_gemini_stub_cleanup($stub);
        }
        t_chat_settings_restore($before);
    }
}
