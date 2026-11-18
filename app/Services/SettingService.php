<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Models\AppSetting;
use RuntimeException;

/**
 * Pengaturan aplikasi (Fase 5).
 *
 * Dua kelompok:
 * 1. Identitas bengkel — dipakai pada kop dokumen cetak (laporan, PO,
 *    bukti barang masuk/keluar).
 * 2. Parameter laporan — ambang fast/slow moving yang dapat dikonfigurasi
 *    Admin. Aturannya ditampilkan sebagai label pada laporan agar pembaca
 *    tidak menebak definisinya.
 *
 * Rahasia (password, API key, token) TIDAK disimpan di sini.
 * Setiap perubahan dicatat pada Audit Log (nilai lama/baru, tanpa rahasia).
 */
final class SettingService
{
    public const NAME_MAX = 150;
    public const ADDRESS_MAX = 255;
    public const PHONE_MAX = 50;
    public const EMAIL_MAX = 100;
    public const NOTE_MAX = 255;
    public const THRESHOLD_MAX = 1000000;
    public const PERIOD_MIN = 7;
    public const PERIOD_MAX = 365;

    /**
     * Nilai bawaan bila belum pernah disimpan.
     *
     * @var array<string,string>
     */
    public const DEFAULTS = [
        'workshop_name'         => '',
        'workshop_address'      => '',
        'workshop_phone'        => '',
        'workshop_email'        => '',
        'workshop_note'         => '',
        'workshop_logo'         => '',
        'fast_moving_threshold' => '10',
        'slow_moving_threshold' => '2',
        'moving_period_days'    => '30',
    ];

    /**
     * Semua pengaturan (default digabung dengan nilai tersimpan).
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        return array_merge(self::DEFAULTS, AppSetting::all());
    }

    /**
     * Identitas bengkel untuk kop dokumen. Nama kosong → nama aplikasi.
     *
     * @return array{name:string,address:string,phone:string,email:string,note:string,logo:string}
     */
    public static function identity(): array
    {
        $all = self::all();
        $name = trim((string) $all['workshop_name']);
        if ($name === '') {
            $name = (string) App::config('app.name', 'SmartPartMan');
        }

        return [
            'name'    => $name,
            'address' => (string) $all['workshop_address'],
            'phone'   => (string) $all['workshop_phone'],
            'email'   => (string) $all['workshop_email'],
            'note'    => (string) $all['workshop_note'],
            'logo'    => (string) $all['workshop_logo'],
        ];
    }

    /**
     * Parameter laporan fast/slow moving.
     *
     * Aturan yang dipakai (ditampilkan di laporan):
     * - Periode analisis  : N hari terakhir.
     * - Fast moving       : total qty keluar >= ambang cepat.
     * - Slow moving       : total qty keluar <= ambang lambat.
     * - Selain itu        : sedang.
     *
     * @return array{fast_threshold:int,slow_threshold:int,period_days:int}
     */
    public static function reportConfig(): array
    {
        $all = self::all();
        return [
            'fast_threshold' => max(0, (int) $all['fast_moving_threshold']),
            'slow_threshold' => max(0, (int) $all['slow_moving_threshold']),
            'period_days'    => max(self::PERIOD_MIN, min(self::PERIOD_MAX, (int) $all['moving_period_days'])),
        ];
    }

    /**
     * Simpan pengaturan. Hanya nilai yang BERUBAH yang ditulis & diaudit.
     *
     * @param array<string,mixed> $data
     * @return array<int,string> Daftar kunci yang berubah (untuk pesan).
     */
    public static function update(array $data, ?int $actorId = null): array
    {
        $new = self::normalize($data);
        $old = self::all();

        $changed = [];
        foreach ($new as $key => $value) {
            if ((string) ($old[$key] ?? '') !== (string) $value) {
                $changed[$key] = $value;
            }
        }
        if ($changed === []) {
            return [];
        }

        $oldValues = [];
        foreach (array_keys($changed) as $key) {
            $oldValues[$key] = $old[$key] ?? null;
        }

        Database::transaction(static function () use ($changed, $oldValues, $actorId): void {
            AppSetting::putMany($changed, $actorId);
            AuditService::log(
                'SETTINGS_UPDATE',
                'app_settings',
                'global',
                'Mengubah pengaturan aplikasi: ' . implode(', ', array_keys($changed)),
                $oldValues,
                $changed
            );
        });

        return array_keys($changed);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function normalize(array $data): array
    {
        $name = MasterRules::text($data['workshop_name'] ?? '');
        $address = MasterRules::text($data['workshop_address'] ?? '');
        $phone = MasterRules::text($data['workshop_phone'] ?? '');
        $email = strtolower(MasterRules::text($data['workshop_email'] ?? ''));
        $note = MasterRules::text($data['workshop_note'] ?? '');
        $logo = str_replace('\\', '/', MasterRules::text($data['workshop_logo'] ?? ''));

        if ($logo !== '' && !LogoService::isValidPath($logo)) {
            throw new RuntimeException('Path logo tidak valid. Unggah ulang logo melalui form.');
        }

        if ($name === '') {
            throw new RuntimeException('Nama bengkel wajib diisi (dipakai pada kop dokumen cetak).');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException('Nama bengkel maksimal ' . self::NAME_MAX . ' karakter.');
        }
        if (mb_strlen($address) > self::ADDRESS_MAX) {
            throw new RuntimeException('Alamat maksimal ' . self::ADDRESS_MAX . ' karakter.');
        }
        if (mb_strlen($phone) > self::PHONE_MAX) {
            throw new RuntimeException('Telepon maksimal ' . self::PHONE_MAX . ' karakter.');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > self::EMAIL_MAX)) {
            throw new RuntimeException('Format email bengkel tidak valid.');
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('Catatan kaki maksimal ' . self::NOTE_MAX . ' karakter.');
        }

        $fast = self::intValue($data['fast_moving_threshold'] ?? '', 'Ambang fast moving');
        $slow = self::intValue($data['slow_moving_threshold'] ?? '', 'Ambang slow moving');
        $period = self::intValue($data['moving_period_days'] ?? '', 'Periode analisis');

        if ($slow >= $fast) {
            throw new RuntimeException('Ambang slow moving harus lebih kecil daripada ambang fast moving.');
        }
        if ($period < self::PERIOD_MIN || $period > self::PERIOD_MAX) {
            throw new RuntimeException(
                'Periode analisis harus antara ' . self::PERIOD_MIN . ' dan ' . self::PERIOD_MAX . ' hari.'
            );
        }

        return [
            'workshop_name'         => $name,
            'workshop_address'      => $address,
            'workshop_phone'        => $phone,
            'workshop_email'        => $email,
            'workshop_note'         => $note,
            'workshop_logo'         => $logo,
            'fast_moving_threshold' => (string) $fast,
            'slow_moving_threshold' => (string) $slow,
            'moving_period_days'    => (string) $period,
        ];
    }

    private static function intValue(mixed $value, string $label): int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d{1,7}$/', $raw) !== 1) {
            throw new RuntimeException($label . ' harus berupa angka bulat 0–' . self::THRESHOLD_MAX . '.');
        }
        $number = (int) $raw;
        if ($number > self::THRESHOLD_MAX) {
            throw new RuntimeException($label . ' maksimal ' . self::THRESHOLD_MAX . '.');
        }
        return $number;
    }
}
