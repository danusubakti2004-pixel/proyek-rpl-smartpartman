<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\WhatsAppNotificationService;

/**
 * Pengujian alur simpan nomor WhatsApp + verifikasi OTP penerima notifikasi.
 *
 * Fokus: persistensi nomor/preferensi, reset status verifikasi saat nomor
 * berubah, metadata status OTP (tanpa membocorkan hash), serta penolakan
 * OTP salah/kedaluwarsa/batas percobaan. Tidak mengirim pesan sungguhan.
 */

function t_wa_scalar(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function test_whatsapp_recipient_state_and_otp(): void
{
    $pdo = Database::pdo();
    $username = t_uniq('wa_recipient');
    $userId = t_make_user($username, 'admin', t_password());
    $number = '6281234567890';
    $changed = '6281299998888';
    $logMaxId = (int) ($pdo->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_notifications')->fetchColumn() ?: 0);

    $types = WhatsAppNotificationService::USER_NOTIFICATION_TYPES;
    $typesInput = [];
    foreach (array_keys($types) as $type) {
        $typesInput[$type] = '1';
    }

    try {
        // 1. Simpan nomor + preferensi.
        WhatsAppNotificationService::saveUserPreferences($userId, [
            'whatsapp_number' => $number,
            'is_enabled' => '1',
            'types' => $typesInput,
        ]);

        t_eq($number, (string) t_wa_scalar('SELECT whatsapp_number FROM users WHERE id = ?', [$userId]), 'Nomor WhatsApp tersimpan');
        t_eq(1, (int) t_wa_scalar('SELECT is_enabled FROM whatsapp_user_preferences WHERE user_id = ?', [$userId]), 'Preferensi penerimaan aktif tersimpan');
        $savedPrefs = json_decode((string) t_wa_scalar('SELECT preferences_json FROM whatsapp_user_preferences WHERE user_id = ?', [$userId]), true);
        t_true(is_array($savedPrefs) && !empty($savedPrefs[array_key_first($types)]), 'Jenis notifikasi tersimpan');

        $prefs = WhatsAppNotificationService::userPreferences($userId);
        t_eq($number, (string) $prefs['whatsapp_number'], 'userPreferences mengembalikan nomor terbaru');
        t_true(!empty($prefs['is_enabled']), 'userPreferences mengembalikan status aktif');

        // 2. Belum ada OTP => belum terverifikasi, tanpa metadata pending.
        $state = WhatsAppNotificationService::verificationState($userId);
        t_true(empty($state['verified']), 'Nomor baru belum terverifikasi');
        t_true(empty($state['has_pending']), 'Tidak ada OTP pending setelah simpan nomor');
        t_true(!array_key_exists('whatsapp_otp_hash', $state) && !array_key_exists('otp_hash', $state), 'Status verifikasi tidak membocorkan hash OTP');

        // 2b. Permintaan OTP dengan nomor tidak valid ditolak sebelum mengirim (tanpa jaringan).
        $invalid = WhatsAppNotificationService::requestOtp($userId, '12');
        t_true(empty($invalid['ok']), 'Permintaan OTP nomor tidak valid ditolak');
        t_true(str_contains((string) ($invalid['error'] ?? ''), 'tidak valid'), 'Pesan nomor OTP tidak valid jelas');
        t_eq(null, t_wa_scalar('SELECT whatsapp_pending_number FROM users WHERE id = ?', [$userId]), 'Nomor tidak valid tidak membuat OTP pending');

        // 3. Simulasikan OTP terkirim (tanpa jaringan).
        $otp = '123456';
        $pdo->prepare(
            'UPDATE users SET whatsapp_pending_number = ?, whatsapp_otp_hash = ?, whatsapp_otp_expires_at = ?, whatsapp_otp_attempts = 0, whatsapp_otp_last_sent_at = ? WHERE id = ?'
        )->execute([$number, password_hash($otp, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 300), date('Y-m-d H:i:s'), $userId]);

        $state = WhatsAppNotificationService::verificationState($userId);
        t_true(!empty($state['has_pending']), 'Status OTP pending terdeteksi');
        t_eq($number, (string) $state['pending_number'], 'Nomor tujuan OTP tampil pada status');
        t_true((int) $state['seconds_remaining'] > 0 && (int) $state['seconds_remaining'] <= 300, 'Sisa waktu OTP dihitung');
        t_true((int) $state['retry_after'] > 0 && (int) $state['retry_after'] <= 60, 'Batas kirim ulang 60 detik terdeteksi');

        // 4. OTP salah ditolak dan menambah percobaan.
        $wrong = WhatsAppNotificationService::verifyOtp($userId, '000000');
        t_true(empty($wrong['ok']), 'OTP salah ditolak');
        t_eq(1, (int) t_wa_scalar('SELECT whatsapp_otp_attempts FROM users WHERE id = ?', [$userId]), 'Percobaan OTP salah tercatat');

        $malformed = WhatsAppNotificationService::verifyOtp($userId, 'abc');
        t_true(empty($malformed['ok']), 'OTP bukan 6 digit ditolak');

        // 5. OTP kedaluwarsa ditolak.
        $pdo->prepare('UPDATE users SET whatsapp_otp_expires_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 10), $userId]);
        $expired = WhatsAppNotificationService::verifyOtp($userId, $otp);
        t_true(empty($expired['ok']), 'OTP kedaluwarsa ditolak');
        t_true(str_contains((string) ($expired['error'] ?? ''), 'kedaluwarsa'), 'Pesan OTP kedaluwarsa jelas');

        // 6. Batas percobaan tercapai ditolak.
        $pdo->prepare(
            'UPDATE users SET whatsapp_otp_expires_at = ?, whatsapp_otp_attempts = 5 WHERE id = ?'
        )->execute([date('Y-m-d H:i:s', time() + 300), $userId]);
        $limited = WhatsAppNotificationService::verifyOtp($userId, $otp);
        t_true(empty($limited['ok']), 'OTP melebihi 5 percobaan ditolak');

        // 7. OTP benar => nomor terverifikasi.
        $pdo->prepare(
            'UPDATE users SET whatsapp_otp_hash = ?, whatsapp_otp_expires_at = ?, whatsapp_otp_attempts = 0 WHERE id = ?'
        )->execute([password_hash($otp, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 300), $userId]);
        $ok = WhatsAppNotificationService::verifyOtp($userId, $otp);
        t_true(!empty($ok['ok']), 'OTP benar diterima');
        $state = WhatsAppNotificationService::verificationState($userId);
        t_true(!empty($state['verified']), 'Status nomor menjadi terverifikasi');
        t_true(empty($state['has_pending']), 'Status OTP pending dibersihkan setelah verifikasi');
        t_eq($number, (string) t_wa_scalar('SELECT whatsapp_number FROM users WHERE id = ?', [$userId]), 'Nomor tetap tersimpan setelah verifikasi');
        t_true((int) t_wa_scalar('SELECT whatsapp_otp_attempts FROM users WHERE id = ?', [$userId]) === 0, 'Percobaan OTP direset setelah verifikasi');

        // 8. Simpan ulang nomor yang sama tidak mereset verifikasi.
        WhatsAppNotificationService::saveUserPreferences($userId, [
            'whatsapp_number' => $number,
            'is_enabled' => '1',
            'types' => $typesInput,
        ]);
        t_eq(1, (int) t_wa_scalar('SELECT whatsapp_verified FROM users WHERE id = ?', [$userId]), 'Nomor sama tetap terverifikasi setelah simpan');

        // 9. Nomor berubah => verifikasi dan OTP direset.
        $pdo->prepare('UPDATE users SET whatsapp_pending_number = ?, whatsapp_otp_hash = ?, whatsapp_otp_expires_at = ? WHERE id = ?')
            ->execute([$number, password_hash('654321', PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 300), $userId]);
        WhatsAppNotificationService::saveUserPreferences($userId, [
            'whatsapp_number' => $changed,
            'is_enabled' => '0',
            'types' => $typesInput,
        ]);
        $state = WhatsAppNotificationService::verificationState($userId);
        t_eq($changed, (string) $state['number'], 'Nomor baru menggantikan nomor lama');
        t_true(empty($state['verified']), 'Verifikasi direset ketika nomor berubah');
        t_true(empty($state['has_pending']), 'OTP pending direset ketika nomor berubah');
        t_eq(0, (int) t_wa_scalar('SELECT is_enabled FROM whatsapp_user_preferences WHERE user_id = ?', [$userId]), 'Preferensi nonaktif tersimpan');
    } finally {
        $pdo->prepare('DELETE FROM whatsapp_user_preferences WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM whatsapp_notifications WHERE id > ?')->execute([$logMaxId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
}
