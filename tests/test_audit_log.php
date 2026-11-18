<?php
declare(strict_types=1);

use App\Services\AuditLogService;
use App\Services\AuditService;

/**
 * Uji Audit Log (PRD FR-20): append-only + pembacaan hanya oleh Admin.
 * Berjalan di database uji.
 */

function test_audit_log_append_only_ditolak_database(): void
{
    $pdo = t_pdo();
    $id = (int) $pdo->query('SELECT id FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($id <= 0) {
        // DB uji baru direset → buat satu catatan agar trigger append-only tetap teruji.
        AuditService::log('UJI_APPEND_ONLY', 'audit_logs', null, 'Catatan uji append-only (dibuat otomatis).');
        $id = (int) $pdo->query('SELECT id FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
    t_true($id > 0, 'Ada catatan audit untuk diuji');

    t_throws(
        static fn () => $pdo->exec('UPDATE audit_logs SET description = \'DIUBAH PAKSA\' WHERE id = ' . $id),
        PDOException::class,
        'Database menolak UPDATE isi Audit Log'
    );
    t_throws(
        static fn () => $pdo->exec('DELETE FROM audit_logs WHERE id = ' . $id),
        PDOException::class,
        'Database menolak DELETE Audit Log'
    );

    t_true(AuditLogService::find($id) !== null, 'Catatan tetap utuh setelah percobaan ubah/hapus');
}

function test_audit_log_layanan_hanya_membaca(): void
{
    $methods = array_map('strtolower', get_class_methods(AuditLogService::class));

    foreach (['delete', 'destroy', 'remove', 'update', 'edit', 'truncate', 'purge'] as $forbidden) {
        t_true(!in_array($forbidden, $methods, true), "AuditLogService tidak menyediakan metode '{$forbidden}'");
    }
    t_true(
        in_array('find', $methods, true) && in_array('paginate', $methods, true),
        'AuditLogService hanya menyediakan pembacaan (find/paginate)'
    );
}

function test_audit_log_filter_dan_paginasi(): void
{
    // Penanda unik agar hasil tidak terpengaruh sisa data dari run sebelumnya.
    $marker = 'UJI_AUDIT_FILTER_' . bin2hex(random_bytes(4));

    for ($i = 1; $i <= 3; $i++) {
        AuditService::log($marker, 'uji', (string) $i, 'catatan uji ' . $i);
    }

    $page1 = AuditLogService::paginate(['activity' => $marker], 1, 2);
    t_eq(3, $page1['total'], 'Filter aktivitas menghitung 3 catatan');
    t_eq(2, count($page1['rows']), 'Halaman 1 memuat 2 baris');
    t_eq(2, $page1['pages'], 'Total 2 halaman');

    $page2 = AuditLogService::paginate(['activity' => $marker], 2, 2);
    t_eq(1, count($page2['rows']), 'Halaman 2 memuat sisa 1 baris');

    $row = $page1['rows'][0];
    $byUser = AuditLogService::paginate(['username' => (string) $row['username']], 1, 50);
    t_true($byUser['total'] >= 1, 'Filter username bekerja');

    $noMatch = AuditLogService::paginate(['activity' => 'AKTIVITAS_TIDAK_ADA_XYZ'], 1);
    t_eq(0, $noMatch['total'], 'Filter tanpa hasil → total 0');

    $range = AuditLogService::paginate([
        'activity'  => $marker,
        'date_from' => date('Y-m-d'),
        'date_to'   => date('Y-m-d'),
    ], 1, 10);
    t_eq(3, $range['total'], 'Filter rentang tanggal bekerja');

    $badPage = AuditLogService::paginate(['activity' => $marker], 999, 2);
    t_true($badPage['page'] <= $badPage['pages'], 'Nomor halaman di luar rentang ditarik ke halaman terakhir');
}

function test_audit_log_meredaksi_rahasia(): void
{
    // FR-20: password/API key/token tidak boleh masuk Audit Log.
    AuditService::log(
        'UJI_AUDIT_REDAKSI',
        'uji',
        '1',
        'Uji redaksi nilai sensitif',
        ['password' => 'SangatRahasia123', 'api_key' => 'AIzaSyCONTOH'],
        ['token' => 'token-rahasia', 'nama' => 'Nilai Biasa']
    );

    $row = t_latest_audit('UJI_AUDIT_REDAKSI');
    t_true($row !== null, 'Catatan audit uji redaksi tersimpan');

    $seluruh = (string) $row['old_values'] . (string) $row['new_values'];
    t_contains('[REDAKTIF]', $seluruh, 'Nilai sensitif diganti penanda [REDAKTIF]');
    t_true(!str_contains($seluruh, 'SangatRahasia123'), 'Password tidak tersimpan di audit');
    t_true(!str_contains($seluruh, 'AIzaSyCONTOH'), 'API key tidak tersimpan di audit');
    t_true(!str_contains($seluruh, 'token-rahasia'), 'Token tidak tersimpan di audit');
    t_contains('Nilai Biasa', $seluruh, 'Nilai non-sensitif tetap tercatat apa adanya');
    t_eq('sistem', (string) $row['username'], 'Tanpa sesi login, pelaku dicatat sebagai sistem');
}

function test_audit_log_filter_lanjutan_dan_ekspor_csv_aman(): void
{
    $marker = 'UJI_AUDIT_EKSPOR_' . bin2hex(random_bytes(4));
    AuditService::log(
        $marker,
        'uji_ekspor',
        '77',
        '=2+3 deskripsi berbahaya untuk spreadsheet',
        ['catatan' => 'nilai lama'],
        ['catatan' => 'nilai baru']
    );
    AuditService::log($marker, 'uji_ekspor', '78', 'Catatan kedua untuk kata kunci unik');

    $byEntity = AuditLogService::paginate([
        'activity'    => $marker,
        'entity_type' => 'uji_ekspor',
        'entity_id'   => '77',
    ], 1, 10);
    t_eq(1, $byEntity['total'], 'Filter jenis + ID entitas bekerja');
    t_eq('77', (string) $byEntity['rows'][0]['entity_id'], 'Baris entity_id sesuai');

    $byKeyword = AuditLogService::paginate([
        'activity' => $marker,
        'keyword'  => 'kata kunci unik',
    ], 1, 10);
    t_eq(1, $byKeyword['total'], 'Filter kata kunci bekerja');

    $invalidDate = AuditLogService::paginate([
        'activity'  => $marker,
        'date_from' => '2026-02-31',
    ], 1, 10);
    t_eq(2, $invalidDate['total'], 'Tanggal tidak valid diabaikan (tidak error)');

    t_true(in_array('uji_ekspor', AuditLogService::entityTypes(), true), 'Daftar jenis entitas memuat data uji');

    $rows = AuditLogService::exportRows(['activity' => $marker], 10);
    t_eq(2, count($rows), 'Ekspor mengambil baris sesuai filter');
    $csv = AuditLogService::csv($rows);
    t_true(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV memakai BOM UTF-8 untuk Excel');
    t_contains('ID,Waktu,Pengguna,Role,Aktivitas', $csv, 'CSV memiliki header kolom');
    t_contains("'=2+3", $csv, 'Sel CSV yang dimulai = diberi kutip agar tidak dieksekusi spreadsheet');
    t_contains('uji_ekspor', $csv, 'CSV memuat jenis entitas');
}

