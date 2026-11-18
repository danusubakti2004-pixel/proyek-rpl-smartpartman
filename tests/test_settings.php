<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Database;
use App\Core\View;
use App\Models\AppSetting;
use App\Services\LogoService;
use App\Services\SettingService;

/**
 * Pengujian Fase 5 — pengaturan aplikasi (identitas bengkel & parameter
 * fast/slow moving). Menjamin validasi backend, audit, dan tidak ada
 * rahasia yang tersimpan/ditampilkan.
 */

/** @return array<string,string> */
function t_settings_input(array $overrides = []): array
{
    return array_merge(SettingService::DEFAULTS, [
        'workshop_name' => 'Bengkel Uji Fase 5',
        'workshop_address' => 'Jl. Pengujian No. 5',
        'workshop_phone' => '021-555-0101',
        'workshop_email' => 'bengkel@example.test',
        'workshop_note' => 'Dokumen uji.',
        'fast_moving_threshold' => '10',
        'slow_moving_threshold' => '2',
        'moving_period_days' => '30',
    ], $overrides);
}

function test_settings_default_dan_identity(): void
{
    $old = AppSetting::all();
    try {
        t_f5_restore_settings([]);

        t_eq(30, SettingService::reportConfig()['period_days'], 'Periode default 30 hari');
        t_eq(10, SettingService::reportConfig()['fast_threshold'], 'Ambang cepat default 10');
        t_eq(2, SettingService::reportConfig()['slow_threshold'], 'Ambang lambat default 2');

        $identity = SettingService::identity();
        t_eq((string) App::config('app.name', 'SmartPartMan'), $identity['name'], 'Nama kosong memakai nama aplikasi');
        t_eq('', $identity['address'], 'Alamat default kosong');
    } finally {
        t_f5_restore_settings($old);
    }
}

function test_settings_validasi_backend(): void
{
    $old = AppSetting::all();
    try {
        t_f5_restore_settings([]);

        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_name' => '   '])),
            RuntimeException::class,
            'Nama bengkel wajib diisi'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_email' => 'bukan-email'])),
            RuntimeException::class,
            'Email tidak valid ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['slow_moving_threshold' => '10'])),
            RuntimeException::class,
            'Ambang lambat harus lebih kecil dari ambang cepat'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['moving_period_days' => '6'])),
            RuntimeException::class,
            'Periode di bawah batas ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['moving_period_days' => '366'])),
            RuntimeException::class,
            'Periode di atas batas ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['fast_moving_threshold' => '-1'])),
            RuntimeException::class,
            'Ambang negatif ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['fast_moving_threshold' => 'abc'])),
            RuntimeException::class,
            'Ambang bukan angka ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['fast_moving_threshold' => '1000001'])),
            RuntimeException::class,
            'Ambang terlalu besar ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_name' => str_repeat('A', SettingService::NAME_MAX + 1)])),
            RuntimeException::class,
            'Nama bengkel terlalu panjang ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_address' => str_repeat('A', SettingService::ADDRESS_MAX + 1)])),
            RuntimeException::class,
            'Alamat terlalu panjang ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_note' => str_repeat('A', SettingService::NOTE_MAX + 1)])),
            RuntimeException::class,
            'Catatan kaki terlalu panjang ditolak'
        );
        t_throws(
            static fn () => SettingService::update(t_settings_input(['workshop_logo' => '../../.env'])),
            RuntimeException::class,
            'Path logo berbahaya ditolak'
        );
    } finally {
        t_f5_restore_settings($old);
    }
}

function test_settings_logo_tersimpan_dan_disajikan_aman(): void
{
    $old = AppSetting::all();
    $created = null;
    $tmp = null;
    try {
        t_f5_restore_settings([]);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        );
        t_true(is_string($png) && $png !== '', 'Data PNG uji valid');

        $tmp = tempnam(sys_get_temp_dir(), 'spm_logo_');
        file_put_contents((string) $tmp, (string) $png);
        $created = LogoService::store([
            'name'     => 'logo.png',
            'tmp_name' => $tmp,
            'size'     => filesize((string) $tmp),
            'error'    => UPLOAD_ERR_OK,
        ]);

        t_true(LogoService::isValidPath($created), 'Path logo mengikuti pola aman');
        t_true(LogoService::absolutePath($created) !== null, 'Berkas logo berada di storage/documents');
        t_eq('image/png', LogoService::describe($created)['mime'] ?? '', 'MIME logo terdeteksi benar');

        SettingService::update(t_settings_input(['workshop_logo' => $created]));
        t_eq($created, SettingService::identity()['logo'], 'Path logo tersimpan pada identitas');

        $badTmp = tempnam(sys_get_temp_dir(), 'spm_logo_bad_');
        file_put_contents((string) $badTmp, (string) $png);
        t_throws(
            static fn () => LogoService::store([
                'name'     => 'logo.svg',
                'tmp_name' => $badTmp,
                'size'     => filesize((string) $badTmp),
                'error'    => UPLOAD_ERR_OK,
            ]),
            RuntimeException::class,
            'Ekstensi logo yang tidak diizinkan ditolak'
        );
        @unlink((string) $badTmp);

        t_throws(
            static fn () => LogoService::store([
                'name'     => 'logo.png',
                'tmp_name' => $tmp,
                'size'     => LogoService::MAX_BYTES + 1,
                'error'    => UPLOAD_ERR_OK,
            ]),
            RuntimeException::class,
            'Logo melebihi batas ukuran ditolak'
        );
    } finally {
        if ($created !== null) {
            LogoService::delete($created);
        }
        if ($tmp !== null) {
            @unlink((string) $tmp);
        }
        t_f5_restore_settings($old);
    }
}

function test_settings_update_audit_dan_idempoten(): void
{
    $old = AppSetting::all();
    $actor = t_make_user('f5setting_' . strtolower(t_uniq('U')), 'admin', t_password());
    try {
        t_f5_restore_settings([]);
        $auditBefore = t_latest_audit('SETTINGS_UPDATE');
        $beforeId = (int) ($auditBefore['id'] ?? 0);

        $changed = SettingService::update(t_settings_input([
            'workshop_name' => '  Bengkel Baru  ',
            'workshop_email' => 'BENGKEL@EXAMPLE.TEST',
            'fast_moving_threshold' => '15',
            'slow_moving_threshold' => '3',
            'moving_period_days' => '45',
        ]), $actor);

        t_true(in_array('workshop_name', $changed, true), 'Nama bengkel tercatat berubah');
        t_true(in_array('workshop_email', $changed, true), 'Email tercatat berubah');
        t_true(in_array('fast_moving_threshold', $changed, true), 'Ambang cepat tercatat berubah');
        t_eq('Bengkel Baru', SettingService::identity()['name'], 'Nama bengkel disimpan tanpa spasi tepi');
        t_eq('bengkel@example.test', SettingService::all()['workshop_email'], 'Email disimpan dalam huruf kecil');
        t_eq(15, SettingService::reportConfig()['fast_threshold'], 'Ambang cepat baru terbaca');
        t_eq(3, SettingService::reportConfig()['slow_threshold'], 'Ambang lambat baru terbaca');
        t_eq(45, SettingService::reportConfig()['period_days'], 'Periode baru terbaca');

        $audit = t_latest_audit('SETTINGS_UPDATE');
        t_true($audit !== null, 'Perubahan pengaturan tercatat di Audit Log');
        t_true((int) ($audit['id'] ?? 0) > $beforeId, 'Audit baru dibuat setelah perubahan');
        t_contains('workshop_name', (string) ($audit['description'] ?? ''), 'Audit menyebut nilai yang diubah');
        $newValues = json_decode((string) ($audit['new_values'] ?? ''), true);
        t_eq('Bengkel Baru', (string) ($newValues['workshop_name'] ?? ''), 'Audit menyimpan nilai baru');

        $auditCountBefore = (int) Database::pdo()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $unchanged = SettingService::update(t_settings_input([
            'workshop_name' => 'Bengkel Baru',
            'workshop_email' => 'bengkel@example.test',
            'fast_moving_threshold' => '15',
            'slow_moving_threshold' => '3',
            'moving_period_days' => '45',
        ]), $actor);
        t_eq([], $unchanged, 'Menyimpan nilai yang sama tidak menghasilkan perubahan');
        t_eq(
            $auditCountBefore,
            (int) Database::pdo()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
            'Tidak ada audit baru bila tidak ada perubahan'
        );
    } finally {
        t_f5_restore_settings($old);
    }
}

function test_settings_tampilan_aman_dan_lengkap(): void
{
    $values = t_settings_input([
        'workshop_name' => 'Bengkel Tampilan',
        'fast_moving_threshold' => '12',
        'slow_moving_threshold' => '4',
        'moving_period_days' => '60',
    ]);

    try {
        $html = View::render('settings/index', [
            'title' => 'Pengaturan Aplikasi',
            'values' => $values,
            'old' => [],
            'isAdmin' => true,
        ], 'layouts/app');
    } catch (Throwable $e) {
        t_true(false, 'View pengaturan gagal dirender: ' . $e->getMessage());
        $html = '';
    }

    t_true($html !== '', 'View pengaturan berhasil dirender');
    t_true(!str_contains($html, '<?php'), 'View pengaturan tidak membocorkan tag PHP');
    t_contains('Pengaturan Aplikasi', $html, 'Judul halaman pengaturan tampil');
    t_contains('name="workshop_name"', $html, 'Form memuat nama bengkel');
    t_contains('name="workshop_address"', $html, 'Form memuat alamat bengkel');
    t_contains('name="workshop_phone"', $html, 'Form memuat telepon bengkel');
    t_contains('name="workshop_email"', $html, 'Form memuat email bengkel');
    t_contains('name="moving_period_days"', $html, 'Form memuat periode analisis');
    t_contains('name="fast_moving_threshold"', $html, 'Form memuat ambang cepat');
    t_contains('name="slow_moving_threshold"', $html, 'Form memuat ambang lambat');
    t_contains('name="_token"', $html, 'Form memuat token CSRF');
    t_contains('Bengkel Tampilan', $html, 'Nilai tersimpan tampil pada form');
    t_contains('12', $html, 'Ambang cepat tampil');
    t_contains('Khusus Admin', $html, 'Halaman menandai akses khusus Admin');
    t_contains('weighted average', $html, 'Halaman menjelaskan metode nilai persediaan');
    t_true(!str_contains($html, 'name="password"'), 'Form tidak meminta/menyimpan password');

    $oldInput = View::render('settings/index', [
        'title' => 'Pengaturan Aplikasi',
        'values' => $values,
        'old' => ['workshop_name' => 'Nama Dari Input Lama'],
        'isAdmin' => true,
    ], 'layouts/app');
    t_contains('Nama Dari Input Lama', $oldInput, 'Nilai input lama ditampilkan kembali setelah validasi gagal');
}
