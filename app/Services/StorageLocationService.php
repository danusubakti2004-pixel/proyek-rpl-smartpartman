<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StorageLocation;
use App\Core\Database;
use RuntimeException;

/**
 * Lokasi penyimpanan (FR-09): satu gudang dengan beberapa rak/tingkat.
 * Contoh penamaan konsisten: "RAK-A1-T2" — Gudang Utama / Rak A1 / Tingkat 2.
 */
final class StorageLocationService
{
    public const CODE_MAX = 30;
    public const NAME_MAX = 100;

    /** @return array<int,array<string,mixed>> */
    public static function all(?string $search = null, ?string $status = null): array
    {
        $status = in_array($status, ['aktif', 'nonaktif'], true) ? $status : null;
        return StorageLocation::all($search, $status);
    }

    /** @return array<int,array<string,mixed>> */
    public static function options(bool $onlyActive = true): array
    {
        return StorageLocation::options($onlyActive);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return StorageLocation::find($id);
    }

    /** @return array<string,mixed>|null Untuk import: cocokkan kode ATAU nama. */
    public static function findForImport(string $value): ?array
    {
        $value = MasterRules::text($value);
        return $value === '' ? null : StorageLocation::findByCodeOrName($value);
    }

    /** @param array<string,mixed> $data @return int */
    public static function create(array $data, ?int $actorId = null): int
    {
        $code = MasterRules::code($data['code'] ?? '');
        $name = MasterRules::text($data['name'] ?? '');
        $description = MasterRules::text($data['description'] ?? '');
        self::guard($code, $name);

        if (StorageLocation::codeExists($code)) {
            throw new RuntimeException('Kode lokasi "' . $code . '" sudah dipakai. Gunakan kode lain.');
        }

        return Database::transaction(static function () use ($code, $name, $description, $actorId): int {
            $id = StorageLocation::insert(
                ['code' => $code, 'name' => $name, 'description' => $description ?: null],
                $actorId
            );
            AuditService::log(
                'LOCATION_CREATE',
                'storage_locations',
                (string) $id,
                sprintf('Menambah lokasi penyimpanan "%s" (%s)', $name, $code),
                null,
                ['code' => $code, 'name' => $name, 'description' => $description ?: null, 'is_active' => 1]
            );
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $actorId = null): void
    {
        $existing = self::require($id);
        $code = MasterRules::code($data['code'] ?? '');
        $name = MasterRules::text($data['name'] ?? '');
        $description = MasterRules::text($data['description'] ?? '');
        self::guard($code, $name);

        if (StorageLocation::codeExists($code, $id)) {
            throw new RuntimeException('Kode lokasi "' . $code . '" sudah dipakai. Gunakan kode lain.');
        }

        Database::transaction(static function () use ($id, $code, $name, $description, $existing, $actorId): void {
            StorageLocation::update(
                $id,
                ['code' => $code, 'name' => $name, 'description' => $description ?: null],
                $actorId
            );
            AuditService::log(
                'LOCATION_UPDATE',
                'storage_locations',
                (string) $id,
                sprintf('Mengubah lokasi penyimpanan "%s"', $name),
                [
                    'code'        => (string) $existing['code'],
                    'name'        => (string) $existing['name'],
                    'description' => $existing['description'],
                ],
                ['code' => $code, 'name' => $name, 'description' => $description ?: null]
            );
        });
    }

    /** Aktifkan / nonaktifkan lokasi. */
    public static function setActive(int $id, bool $active, ?int $actorId = null): void
    {
        $existing = self::require($id);
        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            throw new RuntimeException('Lokasi sudah dalam status ' . ($active ? 'aktif' : 'nonaktif') . '.');
        }

        Database::transaction(static function () use ($id, $active, $existing, $actorId): void {
            StorageLocation::setActive($id, $active, $actorId);
            AuditService::log(
                $active ? 'LOCATION_ACTIVATE' : 'LOCATION_DEACTIVATE',
                'storage_locations',
                (string) $id,
                sprintf(
                    '%s lokasi "%s" (dipakai %d sparepart)',
                    $active ? 'Mengaktifkan' : 'Menonaktifkan',
                    (string) $existing['name'],
                    StorageLocation::sparepartCount($id)
                ),
                ['is_active' => (int) $existing['is_active']],
                ['is_active' => $active ? 1 : 0]
            );
        });
    }

    /** @return array<string,mixed> */
    private static function require(int $id): array
    {
        $location = StorageLocation::find($id);
        if ($location === null) {
            throw new RuntimeException('Lokasi penyimpanan tidak ditemukan.');
        }
        return $location;
    }

    private static function guard(string $code, string $name): void
    {
        if ($code === '') {
            throw new RuntimeException('Kode lokasi wajib diisi.');
        }
        if (mb_strlen($code) > self::CODE_MAX) {
            throw new RuntimeException('Kode lokasi maksimal ' . self::CODE_MAX . ' karakter.');
        }
        if ($name === '') {
            throw new RuntimeException('Nama lokasi wajib diisi.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException('Nama lokasi maksimal ' . self::NAME_MAX . ' karakter.');
        }
    }
}
