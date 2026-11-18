<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Services\WhatsAppNotificationService;

/**
 * Pengujian laporan harian WhatsApp untuk semua role.
 *
 * Fokus:
 * - Admin, Partman, dan Kepala Bengkel sama-sama masuk penerima.
 * - Kriteria penerima dihormati (aktif, nomor ada, terverifikasi, pref aktif).
 * - Kegagalan satu penerima tidak menghentikan penerima lain.
 * - Idempotency per nomor: tidak duplikasi ke penerima sukses, penerima gagal
 *   tetap dicoba ulang.
 * - Laporan tetap terkirim saat tidak ada transaksi harian.
 * - Preferensi tipe notifikasi lain tidak berubah.
 *
 * Tidak mengirim pesan WhatsApp sungguhan: transport HTTP diganti stub.
 */

/** @return array<string,mixed> */
function t_wa_daily_snapshot(): array
{
    $pdo = Database::pdo();
    return [
        'settings' => $pdo->query('SELECT * FROM whatsapp_settings WHERE id = 1')->fetch() ?: [],
        'max_notification_id' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_notifications')->fetchColumn(),
        'user_ids' => [],
    ];
}

/** @param array<string,mixed> $snapshot */
function t_wa_daily_restore(array $snapshot): void
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

/** Aktifkan integrasi + laporan harian memakai token palsu (DB uji saja). */
function t_wa_daily_enable_settings(): void
{
    Database::pdo()->prepare(
        'UPDATE whatsapp_settings
            SET is_enabled = 1,
                api_token_encrypted = ?,
                api_token_masked = ?,
                timezone = ?,
                report_time = ?,
                daily_report_enabled = 1,
                config_json = NULL
          WHERE id = 1'
    )->execute([Crypto::encrypt('token-uji-tidak-nyata'), '****nyata', 'Asia/Jakarta', '00:00:00']);
}

/**
 * Buat penerima uji.
 * @param array<string,bool> $types
 */
function t_wa_daily_recipient(string $username, string $role, string $number, bool $active = true, bool $verified = true, bool $prefsEnabled = true, array $types = ['DAILY_REPORT' => true]): int
{
    $userId = t_make_user($username, $role, t_password(), $active);

    WhatsAppNotificationService::saveUserPreferences($userId, [
        'whatsapp_number' => $number,
        'is_enabled' => $prefsEnabled ? '1' : '',
        'types' => $types,
    ]);
    Database::pdo()->prepare('UPDATE users SET whatsapp_verified = ? WHERE id = ?')
        ->execute([$verified ? 1 : 0, $userId]);

    return $userId;
}

/** @return list<array<string,mixed>> */
function t_wa_daily_rows(int $afterId, array $numbers): array
{
    if ($numbers === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($numbers), '?'));
    $sql = "SELECT * FROM whatsapp_notifications
             WHERE id > ? AND recipient_number IN ($in)
             ORDER BY id ASC";
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute(array_merge([$afterId], $numbers));
    return $stmt->fetchAll() ?: [];
}

function test_whatsapp_daily_report_semua_role_dan_kriteria(): void
{
    $pdo = Database::pdo();
    $snapshot = t_wa_daily_snapshot();
    $prefix = t_uniq('wa_daily');
    $numbers = [
        'admin' => '62811100001',
        'partman' => '62811100002',
        'kepala' => '62811100003',
        'nonaktif' => '62811100004',
        'belum_verifikasi' => '62811100005',
        'pref_nonaktif' => '62811100006',
        'optout' => '62811100007',
    ];
    $allTypes = [];
    foreach (array_keys(WhatsAppNotificationService::USER_NOTIFICATION_TYPES) as $type) {
        $allTypes[$type] = true;
    }

    try {
        t_wa_daily_enable_settings();

        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_admin', 'admin', $numbers['admin'], true, true, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_partman', 'partman', $numbers['partman'], true, true, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_kepala', 'kepala_bengkel', $numbers['kepala'], true, true, true, $allTypes);
        // Pengecualian kriteria: tidak boleh ikut menerima.
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_nonaktif', 'admin', $numbers['nonaktif'], false, true, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_belum', 'partman', $numbers['belum_verifikasi'], true, false, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_prefoff', 'kepala_bengkel', $numbers['pref_nonaktif'], true, true, false, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_optout', 'admin', $numbers['optout'], true, true, true, array_merge($allTypes, ['DAILY_REPORT' => false]));

        $eligible = WhatsAppNotificationService::eligibleRecipients(null, 'DAILY_REPORT');
        $eligibleNumbers = array_column($eligible, 'whatsapp_number');
        t_true(in_array($numbers['admin'], $eligibleNumbers, true), 'Admin masuk penerima laporan harian');
        t_true(in_array($numbers['partman'], $eligibleNumbers, true), 'Partman masuk penerima laporan harian');
        t_true(in_array($numbers['kepala'], $eligibleNumbers, true), 'Kepala Bengkel masuk penerima laporan harian');
        t_true(!in_array($numbers['nonaktif'], $eligibleNumbers, true), 'Akun nonaktif tidak menjadi penerima');
        t_true(!in_array($numbers['belum_verifikasi'], $eligibleNumbers, true), 'Nomor belum terverifikasi tidak menjadi penerima');
        t_true(!in_array($numbers['pref_nonaktif'], $eligibleNumbers, true), 'Penerimaan notifikasi nonaktif tidak menjadi penerima');
        t_true(!in_array($numbers['optout'], $eligibleNumbers, true), 'Preferensi DAILY_REPORT tidak dicentang tidak menjadi penerima');

        $calls = [];
        WhatsAppNotificationService::setHttpTransportForTesting(function (string $token, string $to, string $message) use (&$calls): array {
            $calls[] = ['to' => $to, 'message' => $message];
            return ['ok' => true, 'raw' => '{"status":true}', 'error' => null];
        });

        WhatsAppNotificationService::sendDailyReport(true, '2001-01-01');

        $sentNumbers = array_column($calls, 'to');
        t_true(in_array($numbers['admin'], $sentNumbers, true), 'Laporan diproses untuk Admin');
        t_true(in_array($numbers['partman'], $sentNumbers, true), 'Laporan diproses untuk Partman');
        t_true(in_array($numbers['kepala'], $sentNumbers, true), 'Laporan diproses untuk Kepala Bengkel');
        foreach (['nonaktif', 'belum_verifikasi', 'pref_nonaktif', 'optout'] as $excluded) {
            t_true(!in_array($numbers[$excluded], $sentNumbers, true), 'Laporan tidak diproses untuk ' . $excluded);
        }

        $rows = t_wa_daily_rows((int) $snapshot['max_notification_id'], [$numbers['admin'], $numbers['partman'], $numbers['kepala']]);
        t_eq(3, count($rows), 'Tiga penerima tercatat pada riwayat pengiriman');
        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[(string) $row['recipient_number']] = $row;
        }
        foreach (['admin', 'partman', 'kepala'] as $role) {
            $row = $byNumber[$numbers[$role]] ?? null;
            t_true(is_array($row) && (string) $row['status'] === 'BERHASIL', 'Status pengiriman ' . $role . ' BERHASIL');
            t_true(is_array($row) && (string) $row['notification_type'] === 'DAILY_REPORT', 'Jenis notifikasi ' . $role . ' = DAILY_REPORT');
        }

        $messages = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['message'], $rows)));
        t_eq(1, count($messages), 'Isi laporan identik untuk semua penerima');
        $message = $messages[0] ?? '';
        foreach ([
            'LAPORAN STOK HARIAN',
            'Tanggal: 2001-01-01',
            'Total transaksi barang masuk: 0',
            'Total transaksi barang keluar: 0',
            'Jumlah sparepart tersedia:',
            'Jumlah sparepart stok menipis:',
            'Jumlah sparepart stok habis:',
        ] as $needle) {
            t_contains($needle, $message, 'Laporan memuat "' . $needle . '"');
        }
        t_true(trim($message) !== '', 'Laporan tetap dikirim meskipun tidak ada transaksi harian');
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_daily_restore($snapshot);
    }
}

function test_whatsapp_daily_report_kegagalan_independen_dan_idempotent(): void
{
    $snapshot = t_wa_daily_snapshot();
    $prefix = t_uniq('wa_fail');
    $numbers = [
        'admin' => '62812200001',
        'partman' => '62812200002',
        'kepala' => '62812200003',
    ];
    $allTypes = [];
    foreach (array_keys(WhatsAppNotificationService::USER_NOTIFICATION_TYPES) as $type) {
        $allTypes[$type] = true;
    }

    try {
        t_wa_daily_enable_settings();

        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_admin', 'admin', $numbers['admin'], true, true, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_partman', 'partman', $numbers['partman'], true, true, true, $allTypes);
        $snapshot['user_ids'][] = t_wa_daily_recipient($prefix . '_kepala', 'kepala_bengkel', $numbers['kepala'], true, true, true, $allTypes);

        $failKepala = true;
        $calls = [];
        WhatsAppNotificationService::setHttpTransportForTesting(function (string $token, string $to, string $message) use (&$calls, &$failKepala, $numbers): array {
            $calls[] = $to;
            if ($failKepala && $to === $numbers['kepala']) {
                return ['ok' => false, 'raw' => '{"status":false}', 'error' => 'Simulasi kegagalan Kepala Bengkel.'];
            }
            return ['ok' => true, 'raw' => '{"status":true}', 'error' => null];
        });

        // Pengiriman pertama: Kepala Bengkel gagal, dua lainnya harus tetap sukses.
        WhatsAppNotificationService::sendDailyReport(true, '2001-01-02');
        t_true(in_array($numbers['admin'], $calls, true) && in_array($numbers['partman'], $calls, true) && in_array($numbers['kepala'], $calls, true), 'Ketiga penerima diproses pada pengiriman pertama');

        $rows = t_wa_daily_rows((int) $snapshot['max_notification_id'], array_values($numbers));
        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[(string) $row['recipient_number']][] = $row;
        }
        t_true(isset($byNumber[$numbers['admin']][0]) && (string) $byNumber[$numbers['admin']][0]['status'] === 'BERHASIL', 'Admin tetap BERHASIL saat Kepala Bengkel gagal');
        t_true(isset($byNumber[$numbers['partman']][0]) && (string) $byNumber[$numbers['partman']][0]['status'] === 'BERHASIL', 'Partman tetap BERHASIL saat Kepala Bengkel gagal');
        t_true(isset($byNumber[$numbers['kepala']][0]) && (string) $byNumber[$numbers['kepala']][0]['status'] === 'GAGAL', 'Kegagalan Kepala Bengkel tercatat');
        t_true(isset($byNumber[$numbers['kepala']][0]) && (int) $byNumber[$numbers['kepala']][0]['attempts'] === 2, 'Kepala Bengkel dicoba ulang sebelum dicatat gagal');

        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row['recipient_number']] = (string) $row['idempotency_key'];
        }
        t_eq(3, count(array_unique($keys)), 'Idempotency key berbeda per nomor penerima');
        foreach ($numbers as $role => $number) {
            t_contains($number, $keys[$number] ?? '', 'Idempotency key memuat nomor ' . $role);
        }

        $successBefore = [];
        foreach ($numbers as $role => $number) {
            $successBefore[$number] = count(array_filter($byNumber[$number] ?? [], static fn (array $r): bool => (string) $r['status'] === 'BERHASIL'));
        }

        // Pengiriman kedua: penerima sukses tidak boleh dikirimi ulang; yang gagal dicoba lagi.
        $calls = [];
        $failKepala = false;
        WhatsAppNotificationService::sendDailyReport(true, '2001-01-02');
        t_true(!in_array($numbers['admin'], $calls, true), 'Admin yang sudah sukses tidak dikirimi ulang');
        t_true(!in_array($numbers['partman'], $calls, true), 'Partman yang sudah sukses tidak dikirimi ulang');
        t_true(in_array($numbers['kepala'], $calls, true), 'Kepala Bengkel yang gagal dicoba ulang');

        $rowsAfter = t_wa_daily_rows((int) $snapshot['max_notification_id'], array_values($numbers));
        $successAfter = [];
        $statusAfter = [];
        foreach ($rowsAfter as $row) {
            $number = (string) $row['recipient_number'];
            $statusAfter[$number] = (string) $row['status'];
            $successAfter[$number] = ($successAfter[$number] ?? 0) + ((string) $row['status'] === 'BERHASIL' ? 1 : 0);
        }
        t_eq($successBefore[$numbers['admin']], $successAfter[$numbers['admin']], 'Riwayat sukses Admin tidak terduplikasi');
        t_eq($successBefore[$numbers['partman']], $successAfter[$numbers['partman']], 'Riwayat sukses Partman tidak terduplikasi');
        t_eq('BERHASIL', $statusAfter[$numbers['kepala']] ?? '', 'Kepala Bengkel berhasil pada percobaan ulang');
        t_eq(1, $successAfter[$numbers['kepala']], 'Kepala Bengkel hanya punya satu catatan BERHASIL');
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_daily_restore($snapshot);
    }
}

function test_whatsapp_daily_report_tidak_mengubah_preferensi_lain(): void
{
    $snapshot = t_wa_daily_snapshot();
    $prefix = t_uniq('wa_pref');
    $adminNumber = '62813300001';
    $types = [
        'DAILY_REPORT' => true,
        'LOW_STOCK' => false,
        'BARANG_MASUK' => true,
        'MONTHLY_REPORT' => false,
    ];

    try {
        t_wa_daily_enable_settings();
        $userId = t_wa_daily_recipient($prefix . '_pref', 'kepala_bengkel', $adminNumber, true, true, true, $types);
        $snapshot['user_ids'][] = $userId;

        $before = WhatsAppNotificationService::userPreferences($userId)['preferences'];
        WhatsAppNotificationService::setHttpTransportForTesting(static fn (): array => ['ok' => true, 'raw' => '{"status":true}', 'error' => null]);
        WhatsAppNotificationService::sendDailyReport(true, '2001-01-03');
        $after = WhatsAppNotificationService::userPreferences($userId)['preferences'];

        t_eq(false, (bool) ($after['LOW_STOCK'] ?? true), 'Preferensi LOW_STOCK tetap nonaktif');
        t_eq(true, (bool) ($after['BARANG_MASUK'] ?? false), 'Preferensi BARANG_MASUK tetap aktif');
        t_eq(false, (bool) ($after['MONTHLY_REPORT'] ?? true), 'Preferensi MONTHLY_REPORT tetap nonaktif');
        t_eq($before, $after, 'Seluruh preferensi lain tidak berubah setelah laporan harian');

        $lowStockRows = t_wa_daily_rows((int) $snapshot['max_notification_id'], [$adminNumber]);
        $lowStockRows = array_values(array_filter($lowStockRows, static fn (array $r): bool => (string) $r['notification_type'] === 'LOW_STOCK'));
        t_eq(0, count($lowStockRows), 'Laporan harian tidak memicu notifikasi LOW_STOCK');
    } finally {
        WhatsAppNotificationService::setHttpTransportForTesting(null);
        t_wa_daily_restore($snapshot);
    }
}
