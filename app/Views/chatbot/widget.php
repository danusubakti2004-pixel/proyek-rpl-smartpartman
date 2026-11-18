<?php
/**
 * Floating AI Assistant (revisi UI Fase 8).
 *
 * Widget mengambang yang dirender di layout utama untuk seluruh pengguna
 * yang sudah login. Konfigurasi dibaca dari ChatbotSettingsService/DB yang
 * sama dengan halaman chatbot dan Pengaturan Chatbot (tidak ada konfigurasi
 * terpisah). Riwayat memakai key sesi 'chatbot_history' yang sama.
 *
 * @var array<int,array<string,string>> $history
 * @var array<string,mixed>|null $user
 */

use App\Models\ChatbotSetting;
use App\Services\ChatbotService;
use App\Services\ChatbotSettingsService;

$history = is_array($history ?? null) ? $history : [];
$user = is_array($user ?? null) ? $user : null;
$role = (string) ($user['role'] ?? '');
$roleLabel = ChatbotService::roleLabel($role);

// Baca konfigurasi HANYA-baca (tanpa ensureRow agar tidak menulis DB saat render).
$row = ChatbotSetting::current() ?? [];
$model = (string) ($row['model'] ?? ChatbotSettingsService::MODEL_DEFAULT);
if (!array_key_exists($model, ChatbotSettingsService::MODELS)) {
    $model = ChatbotSettingsService::MODEL_DEFAULT;
}
$modelLabel = ChatbotSettingsService::MODELS[$model];
$enabled = (int) ($row['is_enabled'] ?? 0) === 1;
try {
    $hasKey = ChatbotSettingsService::apiKey() !== '';
} catch (\Throwable $e) {
    $hasKey = false;
}
$canSend = $enabled && $hasKey;
$suggestions = ChatbotService::suggestionsForRole($role);
$welcome = ChatbotService::WELCOME_MESSAGE;

if (!$enabled) {
    $notice = 'Chatbot sedang dinonaktifkan. Hubungi Admin bila diperlukan.';
} elseif (!$hasKey) {
    $notice = 'API key Gemini belum diatur. Hubungi Admin untuk mengatur API key.';
} else {
    $notice = '';
}

$statusLabels = [
    'error'          => ['Layanan AI bermasalah', 'warning'],
    'off_topic'      => ['Di luar topik', 'secondary'],
    'blocked'        => ['Ditolak oleh pengaman', 'danger'],
    'role_scope'     => ['Di luar hak akses', 'warning'],
    'limit'          => ['Batas penggunaan', 'warning'],
    'not_configured' => ['Belum dikonfigurasi', 'warning'],
    'disabled'       => ['Nonaktif', 'secondary'],
];

$subtitle = $canSend
    ? 'Siap membantu · ' . $modelLabel
    : ($enabled ? 'Belum ada API key' : 'Nonaktif');

$config = [
    'enabled'       => $enabled,
    'hasKey'        => $hasKey,
    'canSend'       => $canSend,
    'model'         => $model,
    'modelLabel'    => $modelLabel,
    'role'          => $role,
    'roleLabel'     => $roleLabel,
    'welcome'       => $welcome,
    'notice'        => $notice,
    'suggestions'   => $suggestions,
    'token'         => \App\Core\Csrf::token(),
    'apiUrl'        => base_url('chatbot/api'),
    'clearUrl'      => base_url('chatbot/api/clear'),
    'questionMax'   => ChatbotService::QUESTION_MAX,
    'statusLabels'  => $statusLabels,
];
?>
<div class="sp-ai-widget" id="spAiWidget">
    <button type="button" class="sp-ai-fab" id="spAiFab" aria-label="Buka SmartPartMan AI"
            aria-expanded="false" aria-controls="spAiPanel">
        <span class="sp-ai-fab-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
        </span>
        <span class="sp-ai-fab-dot <?= $canSend ? '' : 'is-off' ?>" id="spAiFabDot" aria-hidden="true"></span>
    </button>

    <section class="sp-ai-panel" id="spAiPanel" role="dialog" aria-modal="false"
             aria-label="SmartPartMan AI" hidden>
        <header class="sp-ai-header">
            <div class="sp-ai-header-brand">
                <span class="sp-ai-avatar" aria-hidden="true">AI</span>
                <div class="sp-ai-header-text">
                    <span class="sp-ai-title">SmartPartMan AI</span>
                    <span class="sp-ai-subtitle" id="spAiSubtitle"><?= e($subtitle) ?></span>
                </div>
            </div>
            <div class="sp-ai-header-actions">
                <button type="button" class="sp-ai-icon-btn" id="spAiNew" title="Percakapan baru"
                        aria-label="Mulai percakapan baru">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>
                    </svg>
                </button>
                <button type="button" class="sp-ai-icon-btn" id="spAiClose" title="Tutup"
                        aria-label="Tutup percakapan">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="sp-ai-notice <?= $canSend ? 'd-none' : '' ?>" id="spAiNotice" role="status">
            <?= e($notice) ?>
        </div>

        <div class="sp-ai-messages" id="spAiMessages" aria-live="polite">
            <?php if ($history === []): ?>
                <div class="sp-ai-welcome" id="spAiWelcome">
                    <div class="sp-ai-welcome-avatar" aria-hidden="true">AI</div>
                    <p class="sp-ai-welcome-text mb-0"><?= e($welcome) ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($history as $message): ?>
                    <?php
                    $isUser = ($message['role'] ?? '') === 'user';
                    $status = (string) ($message['status'] ?? '');
                    $meta = $statusLabels[$status] ?? null;
                    ?>
                    <div class="sp-ai-row <?= $isUser ? 'is-user' : 'is-assistant' ?>">
                        <div class="sp-ai-bubble <?= $isUser ? 'bubble-user' : 'bubble-assistant' ?>">
                            <?php if (!$isUser): ?>
                                <div class="sp-ai-bubble-avatar" aria-hidden="true">AI</div>
                            <?php endif; ?>
                            <div class="sp-ai-bubble-body">
                                <div class="sp-ai-meta">
                                    <span class="sp-ai-sender"><?= $isUser ? 'Anda' : 'Asisten' ?></span>
                                    <span class="sp-ai-time"><?= e((string) ($message['at'] ?? '')) ?></span>
                                    <?php if (!$isUser && $meta !== null): ?>
                                        <span class="badge text-bg-<?= e($meta[1]) ?> sp-ai-status-badge"><?= e($meta[0]) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="sp-ai-text"><?= nl2br(e($isUser ? (string) ($message['text'] ?? '') : ChatbotService::cleanAnswer((string) ($message['text'] ?? '')))) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sp-ai-suggestions <?= $canSend ? '' : 'd-none' ?>" id="spAiSuggestions">
            <?php foreach ($suggestions as $suggestion): ?>
                <button type="button" class="sp-ai-chip" data-suggestion="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
            <?php endforeach; ?>
        </div>

        <form class="sp-ai-composer" id="spAiForm">
            <textarea class="sp-ai-input" id="spAiInput" rows="1"
                      maxlength="<?= (int) ChatbotService::QUESTION_MAX ?>"
                      placeholder="Tulis pertanyaan…" <?= $canSend ? '' : 'disabled' ?>></textarea>
            <button type="submit" class="sp-ai-send" id="spAiSend" aria-label="Kirim pesan" <?= $canSend ? '' : 'disabled' ?>>
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>
                </svg>
            </button>
        </form>
        <div class="sp-ai-footnote">
            Jawaban AI dapat keliru. Periksa menu terkait sebelum mengambil keputusan.
        </div>
    </section>

    <script type="application/json" id="spAiConfig"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
</div>
