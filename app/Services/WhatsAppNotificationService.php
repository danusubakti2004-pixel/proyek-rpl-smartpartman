<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Models\Analytics;
use App\Models\Sparepart;
use App\Services\SettingService;
use RuntimeException;
use Throwable;

/**
 * Notifikasi WhatsApp via Fonnte.
 */
final class WhatsAppNotificationService
{
    public const ENDPOINT = 'https://api.fonnte.com/send';

    /**
     * Pengganti transport HTTP khusus pengujian (tidak pernah diisi di produksi).
     * @var callable(string,string,string):array{ok:bool,raw:?string,error:?string}|null
     */
    private static $testTransport = null;

    /**
     * Ganti transport HTTP Fonnte hanya untuk pengujian tanpa jaringan.
     * Kirim null untuk mengembalikan transport asli.
     * @param callable(string,string,string):array{ok:bool,raw:?string,error:?string}|null $transport
     */
    public static function setHttpTransportForTesting(?callable $transport): void
    {
        self::$testTransport = $transport;
    }

    public const USER_NOTIFICATION_TYPES = [
        'PO_SUBMIT' => 'Pengajuan PO',
        'PO_APPROVED' => 'PO disetujui',
        'PO_REJECTED' => 'PO ditolak',
        'BARANG_MASUK' => 'Penerimaan barang',
        'BARANG_KELUAR' => 'Barang keluar',
        'STOCK_ADJUSTMENT' => 'Penyesuaian stok',
        'LOW_STOCK' => 'Peringatan stok menipis',
        'DAILY_REPORT' => 'Laporan harian',
        'MONTHLY_REPORT' => 'Laporan bulanan',
    ];

    /** @return array{ok:bool,status:string,message:string,raw:?string,error:?string} */
    public static function send(string $to, string $message, string $type = 'GENERIC', ?string $idempotencyKey = null): array
    {
        // Normalisasi newline agar tidak tampil literal \n pada WhatsApp.
        $message = str_replace(['\\r\\n', '\\n', '\\r'], ["\r\n", "\n", "\r"], $message);

        $to = self::normalizeNumber($to);
        if ($to === '') {
            return self::log($type, $to, $message, 'GAGAL', null, 1, 'Nomor WhatsApp tidak valid.', $idempotencyKey);
        }
        if (trim($message) === '') {
            return self::log($type, $to, $message, 'GAGAL', null, 1, 'Pesan kosong.', $idempotencyKey);
        }

        $settings = self::settings();
        if (empty($settings['is_enabled'])) {
            return self::log($type, $to, $message, 'GAGAL', null, 0, 'Integrasi WhatsApp nonaktif.', $idempotencyKey);
        }
        $token = self::token();
        if ($token === '') {
            return self::log($type, $to, $message, 'GAGAL', null, 0, 'Token Fonnte belum diatur.', $idempotencyKey);
        }

        // Idempotency: jangan kirim ulang event yang sama jika sudah BERHASIL.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = self::findSuccessfulByIdempotency($idempotencyKey);
            if (is_array($existing)) {
                return [
                    'ok' => true,
                    'status' => 'BERHASIL',
                    'message' => 'Notifikasi sudah pernah diproses.',
                    'raw' => (string) ($existing['provider_response'] ?? ''),
                    'error' => $existing['error_message'] ?? null,
                ];
            }
        }

        $attempts = 0;
        $lastRaw = null;
        $lastError = null;
        $status = 'GAGAL';
        $ok = false;

        for ($i = 0; $i < 2; $i++) {
            $attempts++;
            $result = self::$testTransport !== null
                ? (self::$testTransport)($token, $to, $message)
                : self::httpSend($token, $to, $message);
            $lastRaw = $result['raw'];
            if ($result['ok']) {
                $ok = true;
                $status = 'BERHASIL';
                $lastError = null;
                break;
            }
            $lastError = $result['error'];
            $status = 'GAGAL';
        }

        return self::log($type, $to, $message, $status, $lastRaw, $attempts, $lastError, $idempotencyKey, $ok);
    }

    /** @return array{ok:bool,raw:?string,error:?string} */
    private static function httpSend(string $token, string $to, string $message): array
    {
        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return ['ok' => false, 'raw' => null, 'error' => 'Gagal inisialisasi cURL.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
            CURLOPT_POSTFIELDS => http_build_query(['target' => $to, 'message' => $message]),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'raw' => null, 'error' => 'Kesalahan jaringan: ' . $err];
        }
        $decoded = json_decode((string) $raw, true);
        // Fonnte biasanya mengembalikan status true/false.
        $ok = false;
        if (is_array($decoded)) {
            if (isset($decoded['status']) && ($decoded['status'] === true || $decoded['status'] === 'true' || $decoded['status'] === 1)) {
                $ok = true;
            } elseif (isset($decoded['code']) && (int) $decoded['code'] === 200) {
                $ok = true;
            }
        }
        if ($http >= 400) {
            $ok = false;
        }

        $error = null;
        if (!$ok) {
            $reason = '';
            if (is_array($decoded)) {
                foreach (['reason', 'detail', 'message', 'msg', 'error'] as $field) {
                    if (isset($decoded[$field]) && is_scalar($decoded[$field]) && trim((string) $decoded[$field]) !== '') {
                        $reason = trim((string) $decoded[$field]);
                        break;
                    }
                }
            }
            if ($reason === '' && $raw !== false && trim((string) $raw) !== '') {
                $reason = mb_substr(trim((string) $raw), 0, 300);
            }
            $error = 'Respons API Fonnte tidak berhasil';
            if ($http > 0) {
                $error .= ' (HTTP ' . $http . ')';
            }
            $error .= $reason !== '' ? ': ' . $reason : '.';
        }

        return ['ok' => $ok, 'raw' => (string) $raw, 'error' => $error];
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM whatsapp_settings WHERE id = 1');
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $row = null;
        }
        if (!is_array($row)) {
            $row = [];
        }
        $config = [];
        if (!empty($row['config_json'])) {
            $decoded = json_decode((string) $row['config_json'], true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        return [
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'sender_number' => (string) ($row['sender_number'] ?? ''),
            'timezone' => (string) ($row['timezone'] ?? 'Asia/Jakarta'),
            'report_time' => (string) ($row['report_time'] ?? '08:00:00'),
            'daily_report_enabled' => (int) ($row['daily_report_enabled'] ?? 0) === 1,
            'monthly_report_enabled' => (int) ($row['monthly_report_enabled'] ?? 0) === 1,
            'has_token' => trim((string) ($row['api_token_encrypted'] ?? '')) !== '',
            'token_masked' => (string) ($row['api_token_masked'] ?? ''),
            'config' => $config,
        ];
    }

    public static function token(): string
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT api_token_encrypted FROM whatsapp_settings WHERE id = 1');
            $stmt->execute();
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return '';
        }
        $encrypted = trim((string) ($row['api_token_encrypted'] ?? ''));
        if ($encrypted === '') {
            return '';
        }
        try {
            return trim(Crypto::decrypt($encrypted));
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function saveSettings(array $input, ?int $actorId): void
    {
        $enabled = !empty($input['is_enabled']);
        $sender = trim((string) ($input['sender_number'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Jakarta'));
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Timezone tidak valid.');
        }
        $reportTime = trim((string) ($input['report_time'] ?? '08:00:00'));
        $daily = !empty($input['daily_report_enabled']);
        $monthly = !empty($input['monthly_report_enabled']);

        $values = [
            'is_enabled' => $enabled ? 1 : 0,
            'sender_number' => $sender === '' ? null : $sender,
            'timezone' => $timezone,
            'report_time' => $reportTime,
            'daily_report_enabled' => $daily ? 1 : 0,
            'monthly_report_enabled' => $monthly ? 1 : 0,
            'config_json' => json_encode($input['config'] ?? []),
            'updated_by' => $actorId,
        ];

        $newToken = trim((string) ($input['api_token'] ?? ''));
        if ($newToken !== '') {
            $values['api_token_encrypted'] = Crypto::encrypt($newToken);
            $values['api_token_masked'] = self::mask($newToken);
        }

        $sets = [];
        $params = [];
        foreach ($values as $k => $v) {
            $sets[] = $k . ' = ?';
            $params[] = $v;
        }
        $params[] = 1;
        Database::pdo()->prepare('UPDATE whatsapp_settings SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    public static function testConnection(?string $to = null): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'error' => 'Token belum diatur.'];
        }
        $target = $to !== null && $to !== '' ? self::normalizeNumber($to) : self::normalizeNumber(self::settings()['sender_number']);
        if ($target === '') {
            return ['ok' => false, 'error' => 'Nomor tujuan uji belum diatur.'];
        }
        return self::send($target, 'Pesan uji SmartPartMan WhatsApp.', 'TEST', 'test-' . time());
    }

    /**
     * Kirim notifikasi ke pengguna berdasarkan role yang memiliki nomor WhatsApp.
     *
     * @param list<string> $roles Kode role (mis. admin, partman, kepala_bengkel).
     */
    public static function notifyRoles(array $roles, string $type, string $message, ?string $idempotencyKey = null): void
    {
        $roles = array_values(array_filter($roles));
        if ($roles === []) {
            return;
        }
        self::dispatchRecipients($roles, $type, $message, $idempotencyKey);
    }

    /**
     * Kirim notifikasi ke SEMUA pengguna yang memenuhi kriteria penerima,
     * tanpa membatasi role. Dipakai laporan harian agar Admin, Partman, dan
     * Kepala Bengkel sama-sama menerima selama preferensinya aktif.
     */
    public static function notifyAllRecipients(string $type, string $message, ?string $idempotencyKey = null): void
    {
        self::dispatchRecipients(null, $type, $message, $idempotencyKey);
    }

    /**
     * Ambil penerima yang memenuhi kriteria: akun aktif, nomor tersedia,
     * nomor terverifikasi, dan preferensi tipe notifikasi aktif.
     * Bila $roles null, tidak ada filter role.
     *
     * @param list<string>|null $roles
     * @return list<array{id:int,whatsapp_number:string}>
     */
    public static function eligibleRecipients(?array $roles, string $type): array
    {
        if (empty(self::settings()['is_enabled'])) {
            return [];
        }
        // Cek toggle jenis notifikasi jika tersedia di config.
        $config = self::settings()['config'] ?? [];
        if (is_array($config) && isset($config['types']) && is_array($config['types']) && array_key_exists($type, $config['types']) && empty($config['types'][$type])) {
            return [];
        }

        try {
            $sql = 'SELECT u.id, u.whatsapp_number FROM users u';
            $params = [];
            if ($roles !== null) {
                $placeholders = implode(',', array_fill(0, count($roles), '?'));
                $sql .= " JOIN roles r ON r.id = u.role_id WHERE r.code IN ($placeholders) AND";
                $params = $roles;
            } else {
                $sql .= ' WHERE';
            }
            $sql .= " u.is_active = 1 AND u.whatsapp_number IS NOT NULL AND u.whatsapp_number <> '' AND u.whatsapp_verified = 1";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $eligible = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            $number = (string) ($row['whatsapp_number'] ?? '');
            if ($number === '' || !self::userAllows($userId, $type)) {
                continue;
            }
            $eligible[] = ['id' => $userId, 'whatsapp_number' => $number];
        }

        return $eligible;
    }

    /**
     * Proses setiap penerima secara independen. Kegagalan satu nomor tidak
     * menghentikan penerima lain, dan idempotency memakai kunci per nomor.
     *
     * @param list<string>|null $roles
     */
    private static function dispatchRecipients(?array $roles, string $type, string $message, ?string $idempotencyKey): void
    {
        foreach (self::eligibleRecipients($roles, $type) as $row) {
            $number = (string) ($row['whatsapp_number'] ?? '');
            if ($number === '') {
                continue;
            }
            $key = $idempotencyKey !== null ? $idempotencyKey . ':' . $number : null;
            try {
                self::send($number, $message, $type, $key);
            } catch (Throwable $e) {
                // Satu penerima gagal tidak boleh menghentikan penerima berikutnya.
                error_log('[WhatsAppNotificationService] Gagal memproses ' . $type . ' untuk ' . $number . ': ' . $e->getMessage());
            }
        }
    }

    public static function notifyLowStock(): void
    {
        $rows = Sparepart::needingAttention(100);
        $date = date('Y-m-d');
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $stock = (float) ($row['stock'] ?? 0);
            $min = (float) ($row['min_stock'] ?? 0);
            $message = "SmartPartMan\nPeringatan stok menipis\nSparepart: " . (string) ($row['name'] ?? '') . "\nKode: " . (string) ($row['code'] ?? '') . "\nStok saat ini: " . $stock . "\nMinimum: " . $min . "\nSegera lakukan pemesanan.";
            self::notifyAllRecipients('LOW_STOCK', $message, 'LOW_STOCK:' . $id . ':' . $date);
        }
    }

    /**
     * Kirim laporan harian.
     *
     * @param bool        $force Lewati pengecekan jam jadwal.
     * @param string|null $date  Tanggal laporan (Y-m-d); null = hari ini.
     */
    public static function sendDailyReport(bool $force = false, ?string $date = null): void
    {
        $settings = self::settings();
        if (empty($settings['daily_report_enabled'])) {
            return; // laporan harian nonaktif
        }
        $tz = new \DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Jakarta'));
        $now = new \DateTime('now', $tz);
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $now->format('Y-m-d');
        }

        if (!$force && !empty($settings['daily_report_enabled'])) {
            $reportTime = (string) ($settings['report_time'] ?? '08:00:00');
            $scheduled = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $reportTime, $tz);
            if ($scheduled instanceof \DateTime && $now < $scheduled) {
                return; // belum waktunya
            }
        }
        $workshop = (string) (SettingService::identity()['name'] ?? 'SmartPartMan');
        $totals = Analytics::periodTotals($date, $date);
        $stock = Analytics::stockStatusCounts(true);
        $lowItems = Sparepart::needingAttention(5);

        $message = $workshop . "\n\n"
            . "LAPORAN STOK HARIAN\n"
            . "Tanggal: " . $date . "\n\n"
            . "Total transaksi barang masuk: " . (int) ($totals['receipts'] ?? 0) . " (qty " . (float) ($totals['masuk_qty'] ?? 0) . ")\n"
            . "Total transaksi barang keluar: " . (int) ($totals['issues'] ?? 0) . " (qty " . (float) ($totals['keluar_qty'] ?? 0) . ")\n"
            . "Jumlah sparepart tersedia: " . (int) ($stock['total'] ?? 0) . "\n"
            . "Jumlah sparepart stok menipis: " . (int) ($stock['menipis'] ?? 0) . "\n"
            . "Jumlah sparepart stok habis: " . (int) ($stock['habis'] ?? 0) . "\n";

        if ($lowItems !== []) {
            $message .= "\nSparepart perlu reorder:\n";
            foreach ($lowItems as $item) {
                $message .= "- " . (string) ($item['code'] ?? '') . " " . (string) ($item['name'] ?? '') . " (stok " . (float) ($item['stock'] ?? 0) . ")\n";
            }
        }
        $message .= "\nLaporan dibuat otomatis oleh SmartPartMan.";

        self::notifyAllRecipients('DAILY_REPORT', $message, 'DAILY_REPORT:' . $date);
    }

    public static function sendMonthlyReport(): void
    {
        $settings = self::settings();
        $tz = new \DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Jakarta'));
        $period = (new \DateTime('now', $tz))->format('Y-m');
        $stock = Analytics::stockStatusCounts(true);
        $lowItems = Sparepart::needingAttention(5);

        $message = (string) (SettingService::identity()['name'] ?? 'SmartPartMan') . "\n\n"
            . "LAPORAN STOK BULANAN\n"
            . "Periode: " . $period . "\n\n"
            . "Jumlah sparepart tersedia: " . (int) ($stock['total'] ?? 0) . "\n"
            . "Jumlah sparepart stok menipis: " . (int) ($stock['menipis'] ?? 0) . "\n"
            . "Jumlah sparepart stok habis: " . (int) ($stock['habis'] ?? 0) . "\n";

        if ($lowItems !== []) {
            $message .= "\nSparepart perlu reorder:\n";
            foreach ($lowItems as $item) {
                $message .= "- " . (string) ($item['code'] ?? '') . " " . (string) ($item['name'] ?? '') . " (stok " . (float) ($item['stock'] ?? 0) . ")\n";
            }
        }
        $message .= "\nLaporan dibuat otomatis oleh SmartPartMan.";

        self::notifyAllRecipients('MONTHLY_REPORT', $message, 'MONTHLY_REPORT:' . $period);
    }

    public static function userPreferences(int $userId): array
    {
        $prefs = [];
        $enabled = true;
        try {
            $stmt = Database::pdo()->prepare('SELECT is_enabled, preferences_json FROM whatsapp_user_preferences WHERE user_id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                $enabled = (int) ($row['is_enabled'] ?? 1) === 1;
                $decoded = json_decode((string) ($row['preferences_json'] ?? ''), true);
                if (is_array($decoded)) {
                    $prefs = $decoded;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        // Default: semua tipe aktif.
        foreach (self::USER_NOTIFICATION_TYPES as $type => $label) {
            if (!array_key_exists($type, $prefs)) {
                $prefs[$type] = true;
            }
        }
        // nomor WhatsApp pengguna
        $number = '';
        try {
            $stmt = Database::pdo()->prepare('SELECT whatsapp_number FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $number = (string) ($row['whatsapp_number'] ?? '');
        } catch (Throwable $e) {
            // ignore
        }
        return [
            'user_id' => $userId,
            'is_enabled' => $enabled,
            'whatsapp_number' => $number,
            'preferences' => $prefs,
        ];
    }

    public static function saveUserPreferences(int $userId, array $input): void
    {
        $enabled = !empty($input['is_enabled']);
        $number = trim((string) ($input['whatsapp_number'] ?? ''));
        if ($number !== '') {
            // Cek apakah nomor berubah; jika berubah, reset status verifikasi.
            $stmt = Database::pdo()->prepare('SELECT whatsapp_number FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $oldNumber = is_array($row) ? (string) ($row['whatsapp_number'] ?? '') : '';
            if ($oldNumber !== $number) {
                $stmt = Database::pdo()->prepare(
                    'UPDATE users SET whatsapp_number = ?, whatsapp_verified = 0, whatsapp_pending_number = NULL, whatsapp_otp_hash = NULL, whatsapp_otp_expires_at = NULL, whatsapp_otp_attempts = 0, whatsapp_otp_last_sent_at = NULL WHERE id = ?'
                );
                $stmt->execute([$number, $userId]);
            } else {
                $stmt = Database::pdo()->prepare('UPDATE users SET whatsapp_number = ? WHERE id = ?');
                $stmt->execute([$number, $userId]);
            }
        }
        $prefs = [];
        foreach (self::USER_NOTIFICATION_TYPES as $type => $label) {
            $prefs[$type] = !empty($input['types'][$type]);
        }
        $json = json_encode($prefs);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO whatsapp_user_preferences (user_id, is_enabled, preferences_json)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), preferences_json = VALUES(preferences_json)'
        );
        $stmt->execute([$userId, $enabled ? 1 : 0, $json]);
    }

    public static function userAllows(int $userId, string $type): bool
    {
        $prefs = self::userPreferences($userId);
        if (empty($prefs['is_enabled'])) {
            return false;
        }
        $types = $prefs['preferences'] ?? [];
        if (array_key_exists($type, $types)) {
            return (bool) $types[$type];
        }
        return true;
    }

    /** Kirim OTP verifikasi ke nomor WhatsApp. */
    public static function requestOtp(int $userId, string $number): array
    {
        $number = self::normalizeNumber($number);
        if ($number === '') {
            return ['ok' => false, 'error' => 'Nomor WhatsApp tidak valid.'];
        }
        $settings = self::settings();
        if (empty($settings['is_enabled'])) {
            return ['ok' => false, 'error' => 'Integrasi WhatsApp nonaktif.'];
        }
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'error' => 'Token Fonnte belum diatur.'];
        }

        // Rate limit 60 detik.
        try {
            $stmt = Database::pdo()->prepare('SELECT whatsapp_otp_last_sent_at FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (is_array($row) && !empty($row['whatsapp_otp_last_sent_at'])) {
                $last = strtotime((string) $row['whatsapp_otp_last_sent_at']);
                if ($last !== false && (time() - $last) < 60) {
                    return ['ok' => false, 'error' => 'Tunggu 60 detik sebelum mengirim ulang OTP.'];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + 300);

        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE users SET whatsapp_pending_number = ?, whatsapp_otp_hash = ?, whatsapp_otp_expires_at = ?, whatsapp_otp_attempts = 0, whatsapp_otp_last_sent_at = ? WHERE id = ?'
            );
            $stmt->execute([$number, $hash, $expires, date('Y-m-d H:i:s'), $userId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Gagal menyimpan OTP.'];
        }

        $message = "SmartPartMan\nKode verifikasi WhatsApp: " . $otp . "\nBerlaku 5 menit. Jangan bagikan kode ini.";
        $result = self::httpSend($token, $number, $message);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Gagal mengirim OTP.'];
        }

        // Log riwayat (tanpa OTP)
        self::log('OTP_VERIFY', $number, 'Kode verifikasi WhatsApp dikirim.', 'BERHASIL', $result['raw'], 1, null, 'OTP:' . $userId . ':' . time());

        return ['ok' => true, 'message' => 'OTP berhasil dikirim.'];
    }

    public static function verifyOtp(int $userId, string $otp): array
    {
        $otp = trim($otp);
        if (!preg_match('/^\d{6}$/', $otp)) {
            return ['ok' => false, 'error' => 'OTP harus 6 digit angka.'];
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT whatsapp_pending_number, whatsapp_otp_hash, whatsapp_otp_expires_at, whatsapp_otp_attempts FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Gagal membaca data OTP.'];
        }

        if (!is_array($row) || empty($row['whatsapp_otp_hash']) || empty($row['whatsapp_pending_number'])) {
            return ['ok' => false, 'error' => 'Tidak ada OTP yang menunggu verifikasi.'];
        }

        $attempts = (int) ($row['whatsapp_otp_attempts'] ?? 0);
        if ($attempts >= 5) {
            return ['ok' => false, 'error' => 'Batas percobaan OTP tercapai. Kirim ulang OTP.'];
        }

        $expires = strtotime((string) $row['whatsapp_otp_expires_at']);
        if ($expires === false || time() > $expires) {
            return ['ok' => false, 'error' => 'OTP kedaluwarsa. Kirim ulang OTP.'];
        }

        if (!password_verify($otp, (string) $row['whatsapp_otp_hash'])) {
            $stmt = Database::pdo()->prepare('UPDATE users SET whatsapp_otp_attempts = whatsapp_otp_attempts + 1 WHERE id = ?');
            $stmt->execute([$userId]);
            return ['ok' => false, 'error' => 'OTP salah.'];
        }

        // Success: update number and verified.
        $pending = (string) $row['whatsapp_pending_number'];
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET whatsapp_number = ?, whatsapp_verified = 1, whatsapp_pending_number = NULL, whatsapp_otp_hash = NULL, whatsapp_otp_expires_at = NULL, whatsapp_otp_attempts = 0, whatsapp_otp_last_sent_at = NULL WHERE id = ?'
        );
        $stmt->execute([$pending, $userId]);

        self::log('OTP_VERIFY', $pending, 'Nomor WhatsApp terverifikasi.', 'BERHASIL', null, 1, null, 'OTP_OK:' . $userId);

        return ['ok' => true, 'message' => 'Nomor WhatsApp berhasil diverifikasi.'];
    }

    public static function isVerified(int $userId): bool
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT whatsapp_verified FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return is_array($row) && (int) ($row['whatsapp_verified'] ?? 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Status verifikasi OTP untuk halaman edit penerima.
     * Tidak pernah mengembalikan hash OTP (hanya metadata status).
     */
    public static function verificationState(int $userId): array
    {
        $state = [
            'verified' => false,
            'number' => '',
            'pending_number' => '',
            'expires_at' => null,
            'last_sent_at' => null,
            'attempts' => 0,
            'has_pending' => false,
            'expired' => false,
            'seconds_remaining' => 0,
            'retry_after' => 0,
        ];

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT whatsapp_number, whatsapp_verified, whatsapp_pending_number, whatsapp_otp_hash,
                        whatsapp_otp_expires_at, whatsapp_otp_attempts, whatsapp_otp_last_sent_at
                   FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return $state;
        }
        if (!is_array($row)) {
            return $state;
        }

        $now = time();
        $state['verified'] = (int) ($row['whatsapp_verified'] ?? 0) === 1;
        $state['number'] = (string) ($row['whatsapp_number'] ?? '');
        $state['pending_number'] = (string) ($row['whatsapp_pending_number'] ?? '');
        $state['attempts'] = (int) ($row['whatsapp_otp_attempts'] ?? 0);
        $state['expires_at'] = !empty($row['whatsapp_otp_expires_at']) ? (string) $row['whatsapp_otp_expires_at'] : null;
        $state['last_sent_at'] = !empty($row['whatsapp_otp_last_sent_at']) ? (string) $row['whatsapp_otp_last_sent_at'] : null;

        $hasHash = !empty($row['whatsapp_otp_hash']);
        $state['has_pending'] = $hasHash && $state['pending_number'] !== '';

        if ($state['has_pending'] && $state['expires_at'] !== null) {
            $expires = strtotime($state['expires_at']);
            if ($expires !== false) {
                $state['expired'] = $now > $expires;
                $state['seconds_remaining'] = max(0, $expires - $now);
            }
        }

        if ($state['last_sent_at'] !== null) {
            $last = strtotime($state['last_sent_at']);
            if ($last !== false) {
                $state['retry_after'] = max(0, min(60, 60 - ($now - $last)));
            }
        }

        return $state;
    }

    public static function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        if ($number === null) {
            return '';
        }
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        }
        if (strlen($number) < 10 || strlen($number) > 15) {
            return '';
        }
        return $number;
    }

    public static function mask(string $token): string
    {
        if (strlen($token) <= 6) {
            return str_repeat('*', strlen($token));
        }
        return str_repeat('*', strlen($token) - 4) . substr($token, -4);
    }

    private static function log(string $type, string $to, string $message, string $status, ?string $raw, int $attempts, ?string $error, ?string $idempotencyKey, bool $ok = false): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO whatsapp_notifications (notification_type, recipient_number, message, status, provider_response, attempts, error_message, idempotency_key, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $type,
                $to,
                $message,
                $status,
                $raw,
                $attempts,
                $error,
                $idempotencyKey,
                $status === 'BERHASIL' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
        return ['ok' => $status === 'BERHASIL', 'status' => $status, 'message' => $message, 'raw' => $raw, 'error' => $error];
    }

    private static function findByIdempotency(string $key): ?array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM whatsapp_notifications WHERE idempotency_key = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function findSuccessfulByIdempotency(string $key): ?array
    {
        try {
            $stmt = Database::pdo()->prepare("SELECT * FROM whatsapp_notifications WHERE idempotency_key = ? AND status = 'BERHASIL' LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(int $limit = 50): array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM whatsapp_notifications ORDER BY id DESC LIMIT ?');
            $stmt->execute([$limit]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}