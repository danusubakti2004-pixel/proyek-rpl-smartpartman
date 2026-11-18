<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Services\ChatbotService;
use App\Services\ChatbotSettingsService;
use App\Services\HealthService;
use RuntimeException;
use Throwable;

/**
 * Chatbot Gemini (Fase 8).
 *
 * Hak akses:
 * - /chatbot            : semua role yang login (konteks disaring per role).
 * - /pengaturan/chatbot : HANYA Admin.
 *
 * Riwayat percakapan disimpan di session per pengguna (maks. 12 pesan untuk
 * tampilan). Riwayat terakhir IKUT dikirim ke model pada setiap permintaan
 * (maks. 10 pesan, dipotong panjangnya); pesan yang lebih lama dipadatkan
 * menjadi ringkasan agar prompt tidak tumbuh tanpa batas.
 */
final class ChatbotController extends Controller
{
    private const HISTORY_KEY = 'chatbot_history';
    private const HISTORY_MAX = 12;
    private const CONTEXT_KEY = 'chatbot_context';
    private const SUMMARY_KEY = 'chatbot_summary';
    private const OWNER_KEY = 'chatbot_history_user';
    private const SUMMARY_MAX = 2400;
    private const AI_HISTORY_MAX = 10;

    /** GET /chatbot */
    public function index(array $params = []): void
    {
        $settings = ChatbotSettingsService::current();

        $this->view('chatbot/index', [
            'title'    => 'Chatbot SmartPartMan',
            'settings' => $settings,
            'history'  => $this->history(),
            'old'      => Session::oldInput(),
            'user'     => Auth::user(),
        ]);
    }

    /** POST /chatbot/kirim */
    public function send(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $question = (string) $this->request->input('question', '');
        $user = Auth::user();
        if ($user === null) {
            $this->redirect(base_url('login'));
        }

        try {
            $result = ChatbotService::respond(
                $question,
                $user,
                null,
                null,
                $this->context(),
                $this->aiHistory(),
                $this->aiSummary()
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput(['question' => mb_substr(trim($question), 0, ChatbotService::QUESTION_MAX)]);
            $this->redirect(base_url('chatbot'));
        }

        $this->rememberContext($result);

        $this->append('user', mb_substr(trim($question), 0, ChatbotService::QUESTION_MAX), 'user');
        $this->append('assistant', (string) $result['answer'], (string) $result['status']);

        if ($result['status'] === 'error') {
            Session::flash('warning', 'Layanan AI sedang bermasalah. Pesan kesalahan ditampilkan di percakapan; fitur inventory tetap dapat digunakan.');
        }

        $this->redirect(base_url('chatbot'));
    }

    /** POST /chatbot/bersihkan */
    public function clear(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        Session::forget(self::HISTORY_KEY);
        Session::forget(self::CONTEXT_KEY);
        Session::forget(self::SUMMARY_KEY);
        Session::forget(self::OWNER_KEY);
        Session::flash('success', 'Riwayat percakapan chatbot dibersihkan.');
        $this->redirect(base_url('chatbot'));
    }

    /**
     * POST /chatbot/api — kirim pertanyaan dari Floating AI Assistant.
     *
     * Mengembalikan JSON (tanpa redirect) agar widget bisa memperbarui
     * percakapan tanpa memuat ulang halaman. Memakai key riwayat sesi yang
     * sama dengan halaman chatbot lama sehingga riwayat tetap tersimpan.
     */
    public function apiSend(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->json(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $user = Auth::user();
        if ($user === null) {
            $this->json(['ok' => false, 'error' => 'Sesi login berakhir. Muat ulang halaman.'], 401);
        }

        $question = trim((string) $this->request->input('question', ''));
        try {
            $result = ChatbotService::respond(
                $question,
                $user,
                null,
                null,
                $this->context(),
                $this->aiHistory(),
                $this->aiSummary()
            );
        } catch (RuntimeException $e) {
            $this->json([
                'ok'       => false,
                'appended' => false,
                'status'   => 'error',
                'answer'   => $e->getMessage(),
                'messages' => $this->history(),
            ]);
        }

        $this->rememberContext($result);
        $status = (string) ($result['status'] ?? '');
        $this->append('user', mb_substr($question, 0, ChatbotService::QUESTION_MAX), 'user');
        $this->append('assistant', (string) ($result['answer'] ?? ''), $status);

        $this->json([
            'ok'       => $status === 'ok',
            'appended' => true,
            'status'   => $status,
            'answer'   => (string) ($result['answer'] ?? ''),
            'model'    => (string) ($result['model'] ?? ''),
            'messages' => $this->history(),
        ]);
    }

    /** POST /chatbot/api/clear — mulai percakapan baru (hapus riwayat sesi). */
    public function apiClear(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->json(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        Session::forget(self::HISTORY_KEY);
        Session::forget(self::CONTEXT_KEY);
        Session::forget(self::SUMMARY_KEY);
        Session::forget(self::OWNER_KEY);
        $this->json(['ok' => true, 'messages' => []]);
    }

    /** GET /pengaturan/chatbot */
    public function settings(array $params = []): void
    {
        $settings = ChatbotSettingsService::current();
        $providers = is_array($settings['providers'] ?? null) ? $settings['providers'] : [];
        $db = Database::config();

        try {
            $health = HealthService::check(true);
        } catch (Throwable) {
            $health = ['status' => 'unknown', 'database' => 'unknown', 'migrations' => 'unknown', 'database_version' => null];
        }

        $this->view('settings/chatbot', [
            'title'     => 'Pengaturan AI Chatbot',
            'settings'  => $settings,
            'providers' => $providers,
            'usage'     => ChatbotSettingsService::usage(),
            'old'       => Session::oldInput(),
            'models'    => ChatbotSettingsService::MODELS,
            'styles'    => ChatbotSettingsService::ANSWER_STYLES,
            'local'     => [
                'assistant_active' => true,
                'hybrid_active'    => true,
                'context_chars'    => ChatbotService::CONTEXT_MAX_CHARS,
                'role_scope'       => 'Admin, Partman, dan Kepala Bengkel — data disaring sesuai role login.',
                'read_only'        => true,
                'db_name'          => (string) ($db['name'] ?? ''),
                'db_status'        => (string) ($health['database'] ?? 'unknown'),
                'db_version'       => (string) ($health['database_version'] ?? ''),
                'migrations'       => (string) ($health['migrations'] ?? 'unknown'),
                'time'             => date('Y-m-d H:i'),
            ],
        ]);
    }

    /** POST /pengaturan/chatbot */
    public function saveSettings(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();
        try {
            $changed = ChatbotSettingsService::save($input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($this->safeInput($input));
            $this->redirect(base_url('pengaturan/chatbot'));
        }

        Session::flash(
            'success',
            $changed === []
                ? 'Tidak ada perubahan pengaturan chatbot.'
                : 'Pengaturan chatbot disimpan (' . count($changed) . ' nilai diperbarui).'
        );
        $this->redirect(base_url('pengaturan/chatbot'));
    }

    /** POST /pengaturan/chatbot/uji */
    public function testConnection(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        try {
            $result = ChatbotSettingsService::testConnection($this->request->post(), Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengaturan/chatbot'));
        }

        if (!empty($result['ok'])) {
            Session::flash(
                'success',
                'Koneksi ke ' . (string) ($result['provider_label'] ?? 'Gemini') . ' berhasil (model ' . (string) $result['model']
                . ', ' . (string) ($result['display_name'] ?? '') . ').'
            );
        } else {
            Session::flash(
                'danger',
                'Uji koneksi gagal (' . (string) ($result['provider_label'] ?? 'Gemini') . '): ' . (string) ($result['error'] ?? 'Kesalahan tidak diketahui.')
                . ' (kode: ' . (string) ($result['error_code'] ?? '-') . ')'
            );
        }
        $this->redirect(base_url('pengaturan/chatbot'));
    }

    /** @return array<int,array<string,string>> */
    private function history(): array
    {
        // Riwayat terikat ke pengguna yang login. Bila sesi berganti akun,
        // riwayat lama TIDAK boleh tercampur.
        $owner = Session::get(self::OWNER_KEY, null);
        $current = Auth::id();
        if ($owner !== null && $current !== null && (int) $owner !== (int) $current) {
            Session::forget(self::HISTORY_KEY);
            Session::forget(self::CONTEXT_KEY);
            Session::forget(self::SUMMARY_KEY);
            Session::forget(self::OWNER_KEY);
            return [];
        }

        $history = Session::get(self::HISTORY_KEY, []);
        if (!is_array($history)) {
            return [];
        }

        // Riwayat lama mungkin masih memuat Markdown; bersihkan saat dibaca
        // agar tampilan halaman dan widget selalu konsisten.
        foreach ($history as &$message) {
            if (is_array($message) && ($message['role'] ?? '') !== 'user') {
                $message['text'] = ChatbotService::cleanAnswer((string) ($message['text'] ?? ''));
            }
        }
        unset($message);

        return $history;
    }

    /**
     * Riwayat yang dikirim ke model (tanpa metadata tampilan).
     *
     * @return array<int,array{role:string,text:string}>
     */
    private function aiHistory(): array
    {
        $history = array_slice($this->history(), -self::AI_HISTORY_MAX);
        $out = [];
        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $text = trim((string) ($message['text'] ?? ''));
            if (($role === 'user' || $role === 'assistant') && $text !== '') {
                $out[] = ['role' => $role, 'text' => $text];
            }
        }
        return $out;
    }

    private function aiSummary(): string
    {
        $summary = Session::get(self::SUMMARY_KEY, '');
        return is_string($summary) ? $summary : '';
    }

    /**
     * Konteks transaksi terakhir yang disimpan di sesi. Hanya berisi modul,
     * tanggal, dan nomor dokumen — bukan data sensitif.
     *
     * @return array<string,mixed>|null
     */
    private function context(): ?array
    {
        $context = Session::get(self::CONTEXT_KEY, null);
        return is_array($context) ? $context : null;
    }

    /** @param array<string,mixed> $result */
    private function rememberContext(array $result): void
    {
        $context = $result['context'] ?? null;
        if (is_array($context) && $context !== []) {
            Session::put(self::CONTEXT_KEY, $context);
        }
    }

    private function append(string $role, string $text, string $status): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $owner = Auth::id();
        if ($owner !== null) {
            $storedOwner = Session::get(self::OWNER_KEY, null);
            if ($storedOwner !== null && (int) $storedOwner !== (int) $owner) {
                Session::forget(self::HISTORY_KEY);
                Session::forget(self::SUMMARY_KEY);
            }
            Session::put(self::OWNER_KEY, (int) $owner);
        }

        $history = $this->history();
        $history[] = [
            'role'   => $role === 'user' ? 'user' : 'assistant',
            'text'   => $text,
            'status' => $status,
            'at'     => date('H:i'),
        ];
        if (count($history) > self::HISTORY_MAX) {
            // Pesan yang keluar dari jendela tampilan dipadatkan menjadi
            // ringkasan, bukan dibuang, agar konteks lama tetap terbaca model.
            $evicted = array_slice($history, 0, count($history) - self::HISTORY_MAX);
            $this->appendSummary($evicted);
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        Session::put(self::HISTORY_KEY, $history);
    }

    /** @param array<int,array<string,mixed>> $messages */
    private function appendSummary(array $messages): void
    {
        $lines = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $label = ($message['role'] ?? '') === 'user' ? 'Pengguna' : 'Asisten';
            $lines[] = $label . ': ' . mb_substr($text, 0, 220);
        }
        if ($lines === []) {
            return;
        }

        $existing = trim((string) Session::get(self::SUMMARY_KEY, ''));
        $summary = trim($existing . "\n" . implode("\n", $lines));
        if (mb_strlen($summary) > self::SUMMARY_MAX) {
            $summary = mb_substr($summary, -self::SUMMARY_MAX);
        }
        Session::put(self::SUMMARY_KEY, $summary);
    }

    /**
     * Input yang aman disimpan untuk mengisi ulang form setelah validasi gagal.
     * Field API key SELALU dibuang agar plaintext tidak pernah masuk session.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function safeInput(array $input): array
    {
        $allowed = [
            'is_enabled', 'provider', 'model', 'api_base', 'system_prompt', 'topic_limits', 'answer_style',
            'usage_limit_daily', 'usage_limit_monthly', 'remove_api_key',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $safe[$key] = $input[$key];
            }
        }
        return $safe;
    }
}
