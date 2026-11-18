<?php
declare(strict_types=1);

use App\Core\View;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Services\ChatbotService;
use App\Services\ChatbotSettingsService;

/**
 * Pengujian Fase 8 — tampilan chatbot, pengaturan, escaping XSS, dan RBAC.
 */

/** Render view tanpa layout + pemeriksaan kebersihan keluaran. */
function t_chatbot_view_render(string $template, array $data, string $label): string
{
    $_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
    try {
        $html = View::render($template, $data, null);
    } catch (Throwable $e) {
        t_true(false, "View {$label} gagal dirender: " . $e->getMessage());
        return '';
    }

    t_true($html !== '', "View {$label} berhasil dirender");
    t_true(!str_contains($html, '<?php'), "View {$label} tidak membocorkan tag PHP");
    t_true(
        !str_contains($html, 'Undefined')
        && !str_contains($html, 'Warning:')
        && !str_contains($html, 'Notice:')
        && !str_contains($html, 'Fatal error'),
        "View {$label} dirender tanpa peringatan/galat PHP"
    );
    return $html;
}

/** @return array<string,mixed> */
function t_chatbot_view_settings(bool $enabled = true, bool $hasKey = true): array
{
    return [
        'is_enabled'         => $enabled,
        'model'              => 'gemini-3.5-flash-lite',
        'model_label'        => ChatbotSettingsService::MODELS['gemini-3.5-flash-lite'],
        'system_prompt'      => ChatbotSettingsService::DEFAULT_PROMPT,
        'topic_limits'       => ChatbotSettingsService::DEFAULT_TOPICS,
        'answer_style'       => 'ringkas',
        'answer_style_label' => ChatbotSettingsService::ANSWER_STYLES['ringkas'],
        'usage_limit_daily'  => 10,
        'usage_limit_monthly'=> 100,
        'has_key'            => $hasKey,
        'api_key_source'     => $hasKey ? 'database' : 'none',
        'api_key_masked'     => $hasKey ? '****1234' : '',
        'key_error'          => null,
        'updated_at'         => '2026-10-19 10:00:00',
    ];
}

function test_chatbot_view_index_aktif_dan_escaping(): void
{
    $settings = t_chatbot_view_settings(true, true);
    $history = [
        ['role' => 'user', 'text' => 'Stok <b>kritis</b>?', 'status' => 'user', 'at' => '10:00'],
        ['role' => 'assistant', 'text' => "<script>alert('xss')</script> Jawaban aman.", 'status' => 'ok', 'at' => '10:01'],
        ['role' => 'assistant', 'text' => 'Maaf, di luar topik.', 'status' => 'off_topic', 'at' => '10:02'],
    ];

    $html = t_chatbot_view_render('chatbot/index', [
        'settings' => $settings,
        'history'  => $history,
        'old'      => ['question' => 'Stok <i>aman</i>?'],
        'user'     => ['id' => 1, 'role' => 'partman', 'full_name' => 'Uji Partman'],
    ], 'chatbot aktif');

    t_contains('Chatbot SmartPartMan', $html, 'Judul chatbot tampil');
    t_contains('Partman', $html, 'Label role tampil');
    t_contains('Gemini 3.5 Flash-Lite', $html, 'Label model tampil');
    t_contains('chatbot/kirim', $html, 'Form kirim mengarah ke route chatbot');
    t_contains('name="_token"', $html, 'Form memuat token CSRF');
    t_contains('maxlength="' . ChatbotService::QUESTION_MAX . '"', $html, 'Batas panjang pertanyaan diterapkan');

    t_contains('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html, 'Jawaban AI di-escape (XSS dicegah)');
    t_true(!str_contains($html, "<script>alert('xss')</script>"), 'Payload XSS tidak tampil mentah');
    t_contains('&lt;b&gt;kritis&lt;/b&gt;', $html, 'Pertanyaan pengguna di-escape');
    t_true(!str_contains($html, '<b>kritis</b>'), 'Tag HTML pertanyaan tidak dieksekusi');
    t_contains('&lt;i&gt;aman&lt;/i&gt;', $html, 'Isi lama input di-escape');
    t_contains('Di luar topik', $html, 'Badge status off-topic tampil');
    t_contains('Bersihkan Riwayat', $html, 'Tombol bersihkan riwayat tersedia');
    t_contains('chatbot/bersihkan', $html, 'Form bersihkan mengarah ke route yang benar');
    t_true(!str_contains($html, '****1234'), 'Halaman chat tidak menampilkan API key tersamarkan');
}

function test_chatbot_view_index_nonaktif_dan_tanpa_key(): void
{
    $disabled = t_chatbot_view_render('chatbot/index', [
        'settings' => t_chatbot_view_settings(false, false),
        'history'  => [],
        'old'      => [],
        'user'     => ['id' => 1, 'role' => 'kepala_bengkel', 'full_name' => 'Uji KB'],
    ], 'chatbot nonaktif');

    t_contains('dinonaktifkan', $disabled, 'Peringatan chatbot nonaktif tampil');
    t_true(!str_contains($disabled, 'action="/smartpartman/chatbot/kirim"'), 'Form kirim disembunyikan saat nonaktif');
    t_true(!str_contains($disabled, 'API key Gemini belum diatur'), 'Peringatan key tidak dobel saat nonaktif');

    $noKey = t_chatbot_view_render('chatbot/index', [
        'settings' => t_chatbot_view_settings(true, false),
        'history'  => [],
        'old'      => [],
        'user'     => ['id' => 1, 'role' => 'partman', 'full_name' => 'Uji Partman'],
    ], 'chatbot tanpa key');

    t_contains('API key Gemini belum diatur', $noKey, 'Peringatan API key belum diatur tampil');
    t_true(!str_contains($noKey, 'action="/smartpartman/chatbot/kirim"'), 'Form kirim disembunyikan tanpa API key');
    t_contains('Hubungi Admin', $noKey, 'Pengguna non-Admin diarahkan menghubungi Admin');
}

function test_chatbot_view_pengaturan_dan_escaping_log(): void
{
    $settings = t_chatbot_view_settings(true, true);
    $usage = [
        'summary' => [
            'today' => [
                'total' => 5, 'success' => 3, 'failed' => 1, 'blocked' => 1,
                'prompt_tokens' => 100, 'response_tokens' => 80,
            ],
            'month' => ['total' => 20, 'prompt_tokens' => 500, 'response_tokens' => 400],
            'last_error' => [
                'message' => "<script>alert('log')</script> API key tidak valid",
                'created_at' => '2026-10-19 10:00:00',
            ],
        ],
        'recent' => [[
            'id' => 1, 'user_id' => 3, 'role' => 'partman', 'model' => 'gemini-3.5-flash-lite',
            'question_preview' => '<script>alert(1)</script> stok?', 'prompt_tokens' => 10,
            'response_tokens' => 5, 'status' => 'success', 'error_message' => null,
            'duration_ms' => 123, 'created_at' => '2026-10-19 10:00:00',
            'username' => 'partman1', 'full_name' => 'Partman <b>Satu</b>',
        ], [
            'id' => 2, 'user_id' => null, 'role' => 'admin', 'model' => 'gemini-3.5-flash-lite',
            'question_preview' => 'uji', 'prompt_tokens' => null, 'response_tokens' => null,
            'status' => 'failed', 'error_message' => '<img src=x onerror=alert(1)>',
            'duration_ms' => 50, 'created_at' => '2026-10-19 10:01:00',
            'username' => null, 'full_name' => null,
        ]],
    ];

    $html = t_chatbot_view_render('settings/chatbot', [
        'settings' => $settings,
        'usage'    => $usage,
        'old'      => [],
        'models'   => ChatbotSettingsService::MODELS,
        'styles'   => ChatbotSettingsService::ANSWER_STYLES,
    ], 'pengaturan chatbot');

    t_contains('Pengaturan AI Chatbot', $html, 'Judul pengaturan tampil');
    t_contains('Status Layanan', $html, 'Card status layanan tampil');
    t_contains('DeepSeek', $html, 'Pilihan provider DeepSeek tampil');
    t_contains('OpenAI', $html, 'Pilihan provider OpenAI tampil');
    t_contains('name="provider"', $html, 'Input pilihan provider tersedia');
    t_contains('data-eye="api_key_gemini"', $html, 'Tombol tampil/sembunyikan API key tersedia');
    t_contains('Biarkan kosong untuk mempertahankan key lama', $html, 'Placeholder key lama tampil');
    t_contains('Base URL', $html, 'Konfigurasi Base URL tersedia');
    t_contains('Mode Hybrid', $html, 'Card mode hybrid & asisten database lokal tampil');
    t_contains('Uji Koneksi', $html, 'Tombol uji koneksi tersedia');
    t_contains('breadcrumb', $html, 'Breadcrumb Admin tampil');
    t_contains('h1 class="h4 text-navy', $html, 'Judul memakai ukuran standar aplikasi');
    t_contains('card border-0 shadow-sm', $html, 'Card memakai komponen standar aplikasi');
    t_contains('btn btn-navy', $html, 'Tombol simpan memakai btn-navy');
    t_contains('form-select', $html, 'Pilihan provider memakai form-select Bootstrap');
    t_true(!str_contains($html, 'sp-ai-page'), 'Tidak memakai container khusus bertema gelap');
    t_true(!str_contains($html, 'radial-gradient'), 'Tidak memakai gradasi gelap');
    t_true(!str_contains($html, '--sp-ai-bg'), 'Tidak memakai variabel CSS khusus halaman');
    t_contains('****1234', $html, 'API key hanya ditampilkan tersamarkan');
    t_contains('Database (terenkripsi)', $html, 'Sumber key database ditampilkan');
    t_contains('value="gemini-3.5-flash-lite"', $html, 'Pilihan model tersedia');
    t_contains('name="system_prompt"', $html, 'Form instruksi sistem tersedia');
    t_contains('name="topic_limits"', $html, 'Form batas topik tersedia');
    t_contains('name="usage_limit_daily"', $html, 'Form batas harian tersedia');
    t_contains('name="remove_api_key"', $html, 'Opsi hapus API key tersedia');
    t_contains('pengaturan/chatbot/uji', $html, 'Form uji mengarah ke route yang benar');
    t_true(!str_contains($html, 'TESTKEY-'), 'Tidak ada plaintext API key pada halaman');
    t_true(!str_contains($html, 'value="****1234"'), 'Masked key tidak dipakai sebagai nilai input');

    t_contains('&lt;script&gt;alert(1)&lt;/script&gt; stok?', $html, 'Potongan pertanyaan di log di-escape');
    t_true(!str_contains($html, '<script>alert(1)</script> stok?'), 'XSS pada log tidak tampil mentah');
    t_contains('&lt;img src=x onerror=alert(1)&gt;', $html, 'Error log di-escape');
    t_true(!str_contains($html, '<img src=x onerror=alert(1)>'), 'Tag error log tidak dieksekusi');
    t_contains('Partman &lt;b&gt;Satu&lt;/b&gt;', $html, 'Nama pengguna di log di-escape');
    t_contains('Total pesan', $html, 'Ringkasan pemakaian tampil');
}

function test_chatbot_route_rbac(): void
{
    $chatGet = t_rbac_find_route('GET /chatbot');
    t_true($chatGet !== null, 'Route GET /chatbot terdaftar');
    t_true(
        $chatGet !== null && t_rbac_has_middleware($chatGet['middleware'], AuthMiddleware::class),
        'GET /chatbot wajib login'
    );
    t_true(
        $chatGet !== null && !t_rbac_has_middleware($chatGet['middleware'], RoleMiddleware::class),
        'GET /chatbot tidak membatasi role (semua role login boleh; konteks disaring server)'
    );

    foreach (['POST /chatbot/kirim', 'POST /chatbot/bersihkan', 'POST /chatbot/api', 'POST /chatbot/api/clear'] as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route {$key} terdaftar");
        t_true(
            $route !== null && t_rbac_has_middleware($route['middleware'], AuthMiddleware::class),
            "{$key} wajib login"
        );
        t_true(
            $route !== null && !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class),
            "{$key} tidak membatasi role (semua role login boleh)"
        );
    }

    foreach (['GET /pengaturan/chatbot', 'POST /pengaturan/chatbot', 'POST /pengaturan/chatbot/uji'] as $key) {
        $route = t_rbac_find_route($key);
        t_true($route !== null, "Route {$key} terdaftar");
        t_true(
            $route !== null && t_rbac_has_middleware($route['middleware'], AuthMiddleware::class),
            "{$key} wajib login"
        );
        t_true(
            $route !== null && t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'admin'),
            "{$key} hanya Admin"
        );
        t_true(
            $route !== null && !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'partman'),
            "{$key} tidak mengizinkan Partman"
        );
        t_true(
            $route !== null && !t_rbac_has_middleware($route['middleware'], RoleMiddleware::class, 'kepala_bengkel'),
            "{$key} tidak mengizinkan Kepala Bengkel"
        );
    }
}

/**
 * Pengujian Floating AI Assistant (revisi UI): struktur, konfigurasi JSON,
 * sambutan, contoh pertanyaan, status nonaktif, dan escaping XSS.
 */
function test_chatbot_widget_render_dan_escaping(): void
{
    $pdo = t_pdo();
    $original = $pdo->query('SELECT is_enabled, api_key_encrypted, api_key_masked FROM chatbot_settings WHERE id = 1')->fetch();
    $encrypted = \App\Core\Crypto::encrypt('TEST-KEY-' . bin2hex(random_bytes(12)));

    try {
        // Status nonaktif: tombol tetap tampil, jendela menjelaskan.
        $pdo->exec('UPDATE chatbot_settings SET is_enabled = 0, api_key_encrypted = NULL, api_key_masked = NULL WHERE id = 1');
        $off = t_chatbot_view_render('chatbot/widget', [
            'history' => [],
            'user'    => ['id' => 1, 'role' => 'partman', 'full_name' => 'Uji Partman'],
        ], 'widget nonaktif');

        t_contains('spAiWidget', $off, 'Widget memuat kontainer');
        t_contains('spAiPanel', $off, 'Widget memuat jendela percakapan');
        t_contains('SmartPartMan AI', $off, 'Widget memuat nama asisten');
        t_contains('dinonaktifkan', $off, 'Widget nonaktif menampilkan info');
        t_contains('spAiConfig', $off, 'Widget memuat konfigurasi JSON');
        $offUrl = str_replace('\\/', '/', $off);
        t_contains('chatbot/api', $offUrl, 'Widget memuat endpoint kirim');
        t_contains('chatbot/api/clear', $offUrl, 'Widget memuat endpoint percakapan baru');
        t_contains('Tulis pertanyaan', $off, 'Widget memuat kolom input');
        t_contains(ChatbotService::WELCOME_MESSAGE, $off, 'Widget memuat sambutan');
        t_contains('sp-ai-chip', $off, 'Widget memuat contoh pertanyaan');
        t_contains('id="spAiInput"', $off, 'Kolom input tersedia untuk pertanyaan');

        // Status aktif + ada API key.
        $pdo->exec('UPDATE chatbot_settings SET is_enabled = 1, api_key_encrypted = '
            . $pdo->quote($encrypted) . ", api_key_masked = '****' WHERE id = 1");
        $history = [
            ['role' => 'user', 'text' => 'Stok <b>kritis</b>?', 'status' => 'user', 'at' => '10:00'],
            ['role' => 'assistant', 'text' => "<script>alert('xss')</script> Aman.", 'status' => 'ok', 'at' => '10:01'],
        ];
        $on = t_chatbot_view_render('chatbot/widget', [
            'history' => $history,
            'user'    => ['id' => 1, 'role' => 'admin', 'full_name' => 'Uji Admin'],
        ], 'widget aktif');

        t_true(!str_contains($on, 'dinonaktifkan'), 'Widget aktif tidak menampilkan info nonaktif');
        t_contains('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $on, 'Jawaban di widget di-escape (XSS dicegah)');
        t_true(!str_contains($on, "<script>alert('xss')</script>"), 'Payload XSS widget tidak tampil mentah');
        t_contains('&lt;b&gt;kritis&lt;/b&gt;', $on, 'Pertanyaan di widget di-escape');
        t_true(!str_contains($on, '****'), 'Widget tidak menampilkan API key');
        t_contains('Siapa saja pengguna aktif', $on, 'Widget memuat saran khusus Admin');
        t_contains('token', $on, 'Konfigurasi widget memuat token CSRF');

        // Tanpa pengguna (dipakai halaman publik) tetap aman dirender.
        $anon = t_chatbot_view_render('chatbot/widget', [
            'history' => [],
            'user'    => null,
        ], 'widget tanpa user');
        t_contains('spAiWidget', $anon, 'Widget tetap aman tanpa sesi login');
    } finally {
        $pdo->exec(
            'UPDATE chatbot_settings SET is_enabled = ' . (int) ($original['is_enabled'] ?? 0)
            . ', api_key_encrypted = ' . ($original['api_key_encrypted'] === null ? 'NULL' : $pdo->quote($original['api_key_encrypted']))
            . ', api_key_masked = ' . ($original['api_key_masked'] === null ? 'NULL' : $pdo->quote($original['api_key_masked']))
            . ' WHERE id = 1'
        );
    }
}
