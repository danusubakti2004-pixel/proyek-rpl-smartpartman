<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\WhatsAppNotificationService;
use RuntimeException;

final class WhatsAppController extends Controller
{
    public function settings(array $params = []): void
    {
        $users = $this->notificationUsers();

        $prefsMap = [];
        foreach ($users as $u) {
            $prefsMap[(int) $u['id']] = WhatsAppNotificationService::userPreferences((int) $u['id']);
        }

        $this->view('settings/whatsapp', [
            'title' => 'Pengaturan Integrasi WhatsApp',
            'settings' => WhatsAppNotificationService::settings(),
            'users' => $users,
            'prefsMap' => $prefsMap,
            'types' => WhatsAppNotificationService::USER_NOTIFICATION_TYPES,
            'old' => Session::oldInput(),
        ]);
    }

    public function save(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $input = $this->request->post();
        try {
            WhatsAppNotificationService::saveSettings($input, Auth::id());
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('pengaturan/whatsapp'));
        }
        Session::flash('success', 'Pengaturan WhatsApp disimpan.');
        $this->redirect(base_url('pengaturan/whatsapp'));
    }

    public function test(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $to = trim((string) $this->request->input('test_number', ''));
        $result = WhatsAppNotificationService::testConnection($to !== '' ? $to : null);
        if (!empty($result['ok'])) {
            Session::flash('success', 'Pesan uji berhasil dikirim.');
        } else {
            Session::flash('danger', 'Pesan uji gagal: ' . (string) ($result['error'] ?? 'Unknown'));
        }
        $this->redirect(base_url('pengaturan/whatsapp'));
    }

    public function history(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $this->view('settings/whatsapp_history', [
            'title' => 'Riwayat Notifikasi WhatsApp',
            'rows' => WhatsAppNotificationService::history(100),
        ]);
    }

    public function recipientEdit(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $userId = (int) ($params['id'] ?? 0);
        $user = $this->userWithRole($userId);
        if (!is_array($user)) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }
        $prefs = WhatsAppNotificationService::userPreferences($userId);
        $this->view('settings/whatsapp_recipient', [
            'title' => 'Edit Penerima Notifikasi WhatsApp',
            'user' => $user,
            'prefs' => $prefs,
            'types' => WhatsAppNotificationService::USER_NOTIFICATION_TYPES,
            'verification' => WhatsAppNotificationService::verificationState($userId),
        ]);
    }

    public function recipientSave(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $userId = (int) ($params['id'] ?? 0);
        $input = $this->request->post();
        $input['is_enabled'] = !empty($input['is_enabled']);
        try {
            WhatsAppNotificationService::saveUserPreferences($userId, $input);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengaturan/whatsapp/penerima/' . $userId));
            return;
        }
        Session::flash('success', 'Preferensi penerima disimpan.');
        // POST-Redirect-GET: kembali ke halaman edit penerima yang sama, bukan
        // ke WhatsApp Gateway, agar nomor terbaru dan OTP langsung terlihat.
        $this->redirect(base_url('pengaturan/whatsapp/penerima/' . $userId));
    }

    public function sendOtp(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $userId = (int) ($params['id'] ?? $this->request->input('user_id', 0));
        $number = trim((string) $this->request->input('whatsapp_number', ''));
        $result = WhatsAppNotificationService::requestOtp($userId, $number);
        if (!empty($result['ok'])) {
            Session::flash('success', 'OTP berhasil dikirim.');
        } else {
            Session::flash('danger', 'Gagal mengirim OTP: ' . (string) ($result['error'] ?? 'Unknown'));
        }
        $this->redirect(base_url('pengaturan/whatsapp/penerima/' . $userId));
    }

    public function verifyOtp(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $userId = (int) ($params['id'] ?? $this->request->input('user_id', 0));
        $otp = trim((string) $this->request->input('otp', ''));
        $result = WhatsAppNotificationService::verifyOtp($userId, $otp);
        if (!empty($result['ok'])) {
            Session::flash('success', 'Nomor WhatsApp berhasil diverifikasi.');
        } else {
            Session::flash('danger', 'Verifikasi gagal: ' . (string) ($result['error'] ?? 'Unknown'));
        }
        $this->redirect(base_url('pengaturan/whatsapp/penerima/' . $userId));
    }

    public function recipients(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $users = $this->notificationUsers();
        $prefsMap = [];
        foreach ($users as $u) {
            $prefsMap[(int) $u['id']] = WhatsAppNotificationService::userPreferences((int) $u['id']);
        }
        $this->view('settings/whatsapp_recipients', [
            'title' => 'Manajemen Penerima Notifikasi',
            'users' => $users,
            'prefsMap' => $prefsMap,
        ]);
    }

    public function selfOtp(array $params = []): void
    {
        if (Auth::role() !== 'admin') {
            $this->abort(403, 'Akses ditolak.');
        }
        $userId = (int) Auth::id();
        $user = $this->userWithRole($userId);
        if (!is_array($user)) {
            $this->abort(404, 'Pengguna tidak ditemukan.');
        }
        $this->view('settings/whatsapp_otp', [
            'title' => 'Verifikasi Nomor WhatsApp (OTP)',
            'user' => $user,
        ]);
    }

    public function runScheduler(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $mode = trim((string) $this->request->input('mode', 'daily'));
        if ($mode === 'daily') {
            WhatsAppNotificationService::notifyLowStock();
            WhatsAppNotificationService::sendDailyReport(true);
        } elseif ($mode === 'monthly') {
            WhatsAppNotificationService::sendMonthlyReport();
        } elseif ($mode === 'lowstock') {
            WhatsAppNotificationService::notifyLowStock();
        }

        Session::flash('success', 'Scheduler WhatsApp dijalankan: ' . $mode);
        $this->redirect(base_url('pengaturan/whatsapp'));
    }

    /**
     * Pengguna aktif yang berhak menjadi penerima notifikasi (3 role).
     * @return array<int,array<string,mixed>>
     */
    private function notificationUsers(): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT u.id, u.full_name, u.email, u.whatsapp_number, r.code AS role_code, r.name AS role_name
                   FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE r.code IN ('admin','partman','kepala_bengkel') AND u.is_active = 1
               ORDER BY r.code, u.full_name"
            );
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Pengguna + role berdasarkan ID (untuk halaman edit/verifikasi).
     * @return array<string,mixed>|null
     */
    private function userWithRole(int $userId): ?array
    {
        try {
            $stmt = Database::pdo()->prepare(
                "SELECT u.id, u.full_name, u.email, u.whatsapp_number, r.code AS role_code, r.name AS role_name
                   FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}