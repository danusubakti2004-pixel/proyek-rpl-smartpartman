<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Services\WhatsAppNotificationService;

/**
 * Pengujian notifikasi WhatsApp untuk SEMUA role dan SEMUA jenis notifikasi.
 *
 * Fokus:
 * - Admin, Partman, dan Kepala Bengkel menerima seluruh tipe notifikasi
 *   (PO_SUBMIT, PO_APPROVED, PO_REJECTED, BARANG_MASUK, BARANG_KELUAR,
 *   STOCK_ADJUSTMENT, LOW_STOCK, DAILY_REPORT, MONTHLY_REPORT).
 * - Pengiriman mengikuti preferensi per pengguna, bukan daftar role hardcoded.
 * - Kegagalan satu penerima tidak menghentikan penerima lain dan alasannya
 *   tersimpan pada riwayat.
 * - Idempotency per nomor mencegah pengiriman ganda.
 * - Pemanggil di aplikasi tidak lagi membatasi role secara hardcoded.
 *
 * Tidak mengirim pesan WhatsApp sungguhan: transport HTTP diganti stub.
 */

/** @return array<string,mixed> */
function t_wa_all_snapshot(): array
{
    $pdo = Database::pdo();
    return [
        'settings' => $pdo->query('SELECT * FROM whatsapp_settings WHERE id = 1')->fetch() ?: [],
        'max_notification_id' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_notifications')->fetchColumn(),
        'user_ids' => [],
    ];
}

/** @param array<string,mixed> $snapshot */
function t_wa_all_restore(array $snapshot): void
{
    $pdo = Database::pdo();
    $settings = $snapshot['settings'] ?? [];
    if (is_array($settings) && $settings !== []) {
        $columns = ['is_enabled', 'api_token_encrypted', 'api_token_masked', 'sender_number', 'timezone', 'report_time', 'daily_report_enabled', 'monthly_report_enabled', 'config_json', 'updated_by'];
        $sets = [];
        $params = [];
        foreach ($columns as $column) {
            $sets[] = $column . ' = ?';
            $params[] = $settings[$column] ?? null;
        }
        $params[] = 1;
        $pdo->prepare('UPDATE whatsapp_settings SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    $userIds = array_values(array_filter(array_map('intval', (array) ($snapshot['user_ids'] ?? []))));
    if ($userIds !== []) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $pdo->prepare('DELETE FROM whatsapp_user_preferences WHERE user_id IN (' . $in . ')')->execute($userIds);
        $pdo->prepare('DELETE FROM users WHERE id IN (' . $in . ')')->execute($userIds);
    }

    $pdo->prepare('DELETE FROM whatsapp_notifications WHERE id > ?')->execute([(int) ($snapshot['max_notification_id'] ?? 0)]);
}

/** Aktifkan integrasi memakai token palsu (DB uji saja). */
function t_wa_all_enable_settings(): void
{
    Database::pdo()->prepare(
        'UPDATE whatsapp_settings
            SET is_enabled = 1,
                api_token_encrypted = ?,
                api_token_masked = ?,
                timezone = ?,
                report_time = ?,
                daily_report_enabled = 1,
                monthly_report_enabled = 1,
                config_json = NULL
          WHERE id = 1'
    )->execute([Crypto::encrypt('token-uji-tidak-nyata'), '****nyata', 'Asia/Jakarta', '00:00:00']);
}

/**
 * @param array<string,bool> $types
 */
function t_wa_all_recipient(string $username, string $role, string $number, bool $prefsEnabled = true, array $types = []): int
{
    if ($types === []) {
        foreach (array_keys(WhatsAppNotificationService::USER_NOTIFICATION_TYPES) as $type) {
            $types[$type] = true;
        }
    }
    $userId = t_make_user($username, $role, t_password(), true);
    WhatsAppNotificationService::saveUserPreferences($userId, [
        'whatsapp_number' => $number,
        'is_enabled' => $prefsEnabled ? '1' : '',
        'types' => $types,
    ]);
    Database::pdo()->prepare('UPDATE users SET whatsapp_verified = 1 WHERE id = ?')->execute([$userId]);
    return $userId;
}

/** @return list<array<string,mixed>> */
function t_wa_all_rows(int $afterId, array $numbers, ?string $type = null): array
{
    if ($numbers === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($numbers), '?'));
    $sql = "SELECT * FROM whatsapp_notifications
             WHERE id > ? AND recipient_number IN ($in)";
    $params = array_merge([$afterId], $numbers);
    if ($type !== null) {
        $sql .= ' AND notification_type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function test_whatsapp_semua_jenis_notifikasi_untuk_semua_role(): void
{
    $snapshot = t_wa_all_snapshot();
    $prefix = t_uniq('wa_all');
    $numbers = [
        'admin' => '62815500001',
        'partman' => '62815500002',
        'kepala_bengkel' => '62815500003',
    ];
    $types = array_keys(WhatsAppNotificationService::USER_NOTIFICATION_TYPES);
    $expectedTypes = [
        'PO_SUBMIT', 'PO_APPROVED', 'PO_REJECTED', 'BARANG_MASUK', 'BARANG_KELUAR',
        'STOCK_ADJUSTMENT', 'LOW_STOCK', 'DAILY_REPORT', 'MONTHLY_REPORT',
    ];

    try {
        t_wa_all_enable_settings();
        foreach ($numbers as $role => $number) {
            $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_' . $role, $role, $number);
        }

        t_eq($expectedTypes, $types, 'Daftar tipe notifikasi lengkap sesuai kebutuhan');

        $calls = [];
        WhatsAppNotificationService::setHttpTransportForTesting(function (string $token, string $to, string $message) use (&$calls): array {
            $calls[] = ['to' => $to, 'message' => $message];
            return ['ok' => true, 'raw' => '{"status":true,"detail":"success! message received"}', 'error' => null];
        });

        foreach ($types as $type) {
            $calls = [];
            WhatsAppNotificationService::notifyAllRecipients($type, 'Uji ' . $type, 'ALL:' . $type);

            $sent = array_column($calls, 'to');
            foreach ($numbers as $role => $number) {
                t_true(in_array($number, $sent, true), 'Notifikasi ' . $type . ' diproses untuk ' . $role);
            }
            t_eq(3, count($calls), 'Notifikasi ' . $type . ' diproses tepat untuk tiga penerima');

            $rows = t_wa_all_rows((int) $snapshot['max_notification_id'], array_values($numbers), $type);
            t_eq(3, count($rows), 'Riwayat ' . $type . ' mencatat tiga penerima');
            foreach ($rows as $row) {
                t_eq('BERHASIL', (string) $row['status'], 'Status ' . $type . ' untuk ' . $row['recipient_number'] . ' BERHASIL');
                t_eq($type, (string) $row['notification_type'], 'Jenis riwayat sesuai untuk ' . $row['recipient_number']);
            }
        }
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_all_restore($snapshot);
    }
}

function test_whatsapp_kegagalan_satu_penerima_tidak_menghentikan_lainnya(): void
{
    $snapshot = t_wa_all_snapshot();
    $prefix = t_uniq('wa_iso');
    $numbers = [
        'admin' => '62815510001',
        'partman' => '62815510002',
        'kepala_bengkel' => '62815510003',
    ];

    try {
        t_wa_all_enable_settings();
        foreach ($numbers as $role => $number) {
            $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_' . $role, $role, $number);
        }

        $calls = [];
        WhatsAppNotificationService::setHttpTransportForTesting(function (string $token, string $to, string $message) use (&$calls, $numbers): array {
            $calls[] = $to;
            if ($to === $numbers['admin']) {
                return ['ok' => false, 'raw' => '{"status":false,"reason":"Nomor tidak terdaftar"}', 'error' => 'Respons API Fonnte tidak berhasil: Nomor tidak terdaftar'];
            }
            return ['ok' => true, 'raw' => '{"status":true}', 'error' => null];
        });

        WhatsAppNotificationService::notifyAllRecipients('PO_SUBMIT', 'Uji kegagalan', 'ISO:PO_SUBMIT');

        t_true(in_array($numbers['admin'], $calls, true), 'Admin tetap diproses');
        t_true(in_array($numbers['partman'], $calls, true), 'Partman tetap diproses setelah Admin gagal');
        t_true(in_array($numbers['kepala_bengkel'], $calls, true), 'Kepala Bengkel tetap diproses setelah Admin gagal');

        $rows = t_wa_all_rows((int) $snapshot['max_notification_id'], array_values($numbers), 'PO_SUBMIT');
        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[(string) $row['recipient_number']] = $row;
        }
        t_eq('GAGAL', (string) ($byNumber[$numbers['admin']]['status'] ?? ''), 'Status Admin tercatat GAGAL');
        t_contains('Nomor tidak terdaftar', (string) ($byNumber[$numbers['admin']]['error_message'] ?? ''), 'Alasan kegagalan Admin tersimpan');
        t_eq('BERHASIL', (string) ($byNumber[$numbers['partman']]['status'] ?? ''), 'Status Partman BERHASIL');
        t_eq('BERHASIL', (string) ($byNumber[$numbers['kepala_bengkel']]['status'] ?? ''), 'Status Kepala Bengkel BERHASIL');

        $history = WhatsAppNotificationService::history(200);
        $found = false;
        foreach ($history as $row) {
            if ((string) ($row['recipient_number'] ?? '') === $numbers['admin'] && (string) ($row['error_message'] ?? '') !== '') {
                $found = true;
                break;
            }
        }
        t_true($found, 'Riwayat menampilkan alasan kegagalan per penerima');
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_all_restore($snapshot);
    }
}

function test_whatsapp_preferensi_nonaktif_dan_idempotensi(): void
{
    $snapshot = t_wa_all_snapshot();
    $prefix = t_uniq('wa_pref_all');
    $numbers = [
        'admin' => '62815520001',
        'partman' => '62815520002',
        'kepala_bengkel' => '62815520003',
        'optout' => '62815520004',
    ];

    try {
        t_wa_all_enable_settings();
        $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_admin', 'admin', $numbers['admin']);
        $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_partman', 'partman', $numbers['partman']);
        $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_kepala', 'kepala_bengkel', $numbers['kepala_bengkel']);
        $snapshot['user_ids'][] = t_wa_all_recipient($prefix . '_optout', 'admin', $numbers['optout'], false);

        $calls = [];
        WhatsAppNotificationService::setHttpTransportForTesting(function (string $token, string $to, string $message) use (&$calls): array {
            $calls[] = $to;
            return ['ok' => true, 'raw' => '{"status":true}', 'error' => null];
        });

        WhatsAppNotificationService::notifyAllRecipients('BARANG_KELUAR', 'Uji preferensi', 'PREF:BARANG_KELUAR');
        t_true(!in_array($numbers['optout'], $calls, true), 'Pengguna yang menonaktifkan notifikasi tidak dikirimi');
        t_eq(3, count($calls), 'Hanya tiga penerima aktif yang diproses');

        // Pengiriman ganda dengan kunci idempotency yang sama tidak boleh mengirim ulang.
        $calls = [];
        WhatsAppNotificationService::notifyAllRecipients('BARANG_KELUAR', 'Uji preferensi', 'PREF:BARANG_KELUAR');
        t_eq(0, count($calls), 'Pengiriman ulang dengan kunci sama tidak menduplikasi pesan');

        $rows = t_wa_all_rows((int) $snapshot['max_notification_id'], array_values($numbers), 'BARANG_KELUAR');
        t_eq(3, count($rows), 'Riwayat tidak bertambah pada pengiriman ulang');
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_all_restore($snapshot);
    }
}

function test_whatsapp_pemanggil_tidak_membatasi_role_hardcoded(): void
{
    $base = dirname(__DIR__) . '/app/Controllers/';
    $callers = [
        'PurchaseOrderController.php',
        'GoodsReceiptController.php',
        'GoodsIssueController.php',
        'StockAdjustmentController.php',
    ];

    foreach ($callers as $file) {
        $source = (string) file_get_contents($base . $file);
        t_true(!str_contains($source, 'notifyRoles('), $file . ' tidak lagi memakai notifyRoles dengan daftar role hardcoded');
        t_true(str_contains($source, 'notifyAllRecipients('), $file . ' memakai notifyAllRecipients sesuai preferensi pengguna');
    }

    $adjustment = (string) file_get_contents($base . 'StockAdjustmentController.php');
    t_true(str_contains($adjustment, "'STOCK_ADJUSTMENT'"), 'StockAdjustmentController memakai tipe STOCK_ADJUSTMENT');
    t_true(!str_contains($adjustment, "'PENYESUAIAN_STOK'"), 'StockAdjustmentController tidak lagi memakai tipe PENYESUAIAN_STOK');

    $service = (string) file_get_contents(dirname(__DIR__) . '/app/Services/WhatsAppNotificationService.php');
    t_true(!str_contains($service, "notifyRoles(['admin', 'partman']"), 'LOW_STOCK tidak lagi dibatasi admin+partman');
    t_true(!str_contains($service, "notifyRoles(['admin']"), 'Laporan bulanan tidak lagi dibatasi admin');
    t_true(str_contains($service, "Respons API Fonnte tidak berhasil"), 'Pesan kegagalan Fonnte menyertakan alasan provider');
}
