<?php
/**
 * Chatbot SmartPartMan (Fase 8).
 *
 * @var array<string,mixed> $settings
 * @var array<int,array<string,string>> $history
 * @var array<string,mixed> $old
 * @var array<string,mixed>|null $user
 */
use App\Services\ChatbotService;

$settings = is_array($settings ?? null) ? $settings : [];
$history = is_array($history ?? null) ? $history : [];
$role = (string) ($user['role'] ?? '');
$isAdmin = $role === 'admin';
$roleLabel = ChatbotService::roleLabel($role);
$oldQuestion = (string) (($old ?? [])['question'] ?? '');
$enabled = !empty($settings['is_enabled']);
$hasKey = !empty($settings['has_key']);

// Contoh pertanyaan disediakan terpusat agar konsisten dengan Floating AI Assistant.
$suggestions = ChatbotService::suggestionsForRole($role);

$statusLabels = [
    'error'          => ['Layanan AI bermasalah', 'warning'],
    'off_topic'      => ['Di luar topik', 'secondary'],
    'blocked'        => ['Ditolak oleh pengaman', 'danger'],
    'role_scope'     => ['Di luar hak akses', 'warning'],
    'limit'          => ['Batas penggunaan', 'warning'],
    'not_configured' => ['Belum dikonfigurasi', 'warning'],
    'disabled'       => ['Nonaktif', 'secondary'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Chatbot SmartPartMan</h1>
        <p class="text-muted small mb-0">
            Asisten AI untuk membantu memahami stok, transaksi, laporan, reorder, dan prediksi.
            Jawaban memakai ringkasan data server sesuai hak akses <strong><?= e($roleLabel) ?></strong>.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge text-bg-navy align-self-center"><?= e($roleLabel) ?></span>
        <?php if ($isAdmin): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($settings['key_error'])): ?>
    <div class="alert alert-warning small">
        <?= e((string) $settings['key_error']) ?>
        <?php if ($isAdmin): ?>
            Buka <a href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a> untuk menyimpan ulang API key.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$enabled): ?>
    <div class="alert alert-warning">
        <strong>Chatbot sedang dinonaktifkan.</strong>
        <?php if ($isAdmin): ?>
            Aktifkan melalui <a href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a>.
        <?php else: ?>
            Hubungi Admin bila chatbot diperlukan.
        <?php endif; ?>
    </div>
<?php elseif (!$hasKey): ?>
    <div class="alert alert-warning">
        <strong>API key Gemini belum diatur.</strong>
        <?php if ($isAdmin): ?>
            Isi API key melalui <a href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a>, lalu uji koneksi.
        <?php else: ?>
            Hubungi Admin untuk mengatur API key.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="small text-muted">
            Model: <strong><?= e((string) ($settings['model_label'] ?? $settings['model'] ?? '-')) ?></strong>
            &middot; Gaya: <?= e((string) ($settings['answer_style_label'] ?? 'Ringkas')) ?>
        </div>
        <?php if ($history !== []): ?>
            <form method="post" action="<?= e(base_url('chatbot/bersihkan')) ?>" class="m-0"
                  data-confirm="Hapus seluruh riwayat percakapan di layar ini?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm">Bersihkan Riwayat</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <div class="sp-chat-window" id="chatWindow" aria-live="polite">
            <?php if ($history === []): ?>
                <div class="text-center text-muted py-5">
                    <div class="fs-1 mb-2">&#128172;</div>
                    <p class="mb-1">Belum ada percakapan.</p>
                    <p class="small mb-0">Tanyakan kondisi stok, transaksi, laporan, reorder, atau prediksi.</p>
                </div>
            <?php else: ?>
                <?php foreach ($history as $message): ?>
                    <?php
                    $isUser = ($message['role'] ?? '') === 'user';
                    $status = (string) ($message['status'] ?? '');
                    $meta = $statusLabels[$status] ?? null;
                    ?>
                    <div class="sp-chat-row <?= $isUser ? 'is-user' : 'is-assistant' ?>">
                        <div class="sp-chat-bubble <?= $isUser ? 'bubble-user' : 'bubble-assistant' ?>">
                            <div class="sp-chat-meta">
                                <strong><?= $isUser ? 'Anda' : 'Asisten' ?></strong>
                                <span><?= e((string) ($message['at'] ?? '')) ?></span>
                                <?php if (!$isUser && $meta !== null): ?>
                                    <span class="badge text-bg-<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="sp-chat-text"><?= nl2br(e($isUser ? (string) ($message['text'] ?? '') : ChatbotService::cleanAnswer((string) ($message['text'] ?? '')))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($enabled && $hasKey): ?>
            <div class="mt-3">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach ($suggestions as $suggestion): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm sp-chat-suggestion"
                                data-fill="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
                <form method="post" action="<?= e(base_url('chatbot/kirim')) ?>" id="chatForm">
                    <?= csrf_field() ?>
                    <label class="form-label small fw-semibold" for="question">Pertanyaan</label>
                    <div class="input-group">
                        <textarea class="form-control" id="question" name="question" rows="2"
                                  maxlength="<?= ChatbotService::QUESTION_MAX ?>" required
                                  placeholder="Contoh: Stok apa yang perlu segera dibeli?"><?= e($oldQuestion) ?></textarea>
                        <button class="btn btn-navy" type="submit" id="chatSend">Kirim</button>
                    </div>
                    <div class="form-text">
                        Maksimal <?= ChatbotService::QUESTION_MAX ?> karakter. Jangan mengetik password atau API key.
                        Chatbot tidak dapat mengubah transaksi.
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer bg-white small text-muted">
        Jawaban AI dapat keliru. Angka berasal dari ringkasan data server pada saat pertanyaan dikirim;
        periksa menu terkait sebelum mengambil keputusan. Riwayat di layar ini tidak dikirim ulang ke Gemini.
    </div>
</div>

<script>
(function () {
    'use strict';
    var textarea = document.getElementById('question');
    var windowEl = document.getElementById('chatWindow');
    if (windowEl) {
        windowEl.scrollTop = windowEl.scrollHeight;
    }
    document.querySelectorAll('.sp-chat-suggestion').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!textarea) return;
            textarea.value = button.getAttribute('data-fill') || '';
            textarea.focus();
        });
    });
})();
</script>
