<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Core\Database;
use RuntimeException;

/**
 * Kategori sparepart (FR-12).
 *
 * Aturan:
 * - Kode kategori unik; nama juga dijaga unik agar tidak membingungkan.
 * - Kategori TIDAK pernah dihapus (kelas ini sengaja tidak punya delete()).
 * - Kategori nonaktif TIDAK dapat dipakai untuk sparepart baru
 *   (dipaksa oleh SparepartService lewat resolveUsable()).
 * - Setiap perubahan dicatat di Audit Log.
 */
final class CategoryService
{
    public const CODE_MAX = 30;
    public const NAME_MAX = 100;

    /** @return array<int,array<string,mixed>> */
    public static function all(?string $search = null, ?string $status = null): array
    {
        $status = in_array($status, ['aktif', 'nonaktif'], true) ? $status : null;
        return Category::all($search, $status);
    }

    /** @return array<int,array<string,mixed>> */
    public static function options(bool $onlyActive = true): array
    {
        return Category::options($onlyActive);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Category::find($id);
    }

    /**
     * Ambil kategori yang BOLEH dipakai pada sparepart baru.
     * @return array<string,mixed>
     */
    public static function resolveUsable(int $id): array
    {
        $category = Category::find($id);
        if ($category === null) {
            throw new RuntimeException('Kategori tidak ditemukan.');
        }
        if ((int) $category['is_active'] !== 1) {
            throw new RuntimeException(
                'Kategori "' . (string) $category['name'] . '" sedang nonaktif dan tidak dapat dipakai untuk sparepart baru.'
            );
        }
        return $category;
    }

    /** @return array<string,mixed>|null Untuk import: cocokkan kode ATAU nama. */
    public static function findForImport(string $value): ?array
    {
        $value = MasterRules::text($value);
        return $value === '' ? null : Category::findByCodeOrName($value);
    }

    /** @param array<string,mixed> $data @return int ID kategori baru */
    public static function create(array $data, ?int $actorId = null): int
    {
        $code = MasterRules::code($data['code'] ?? '');
        $name = MasterRules::text($data['name'] ?? '');
        $description = MasterRules::text($data['description'] ?? '');
        self::guard($code, $name);

        if (Category::codeExists($code)) {
            throw new RuntimeException('Kode kategori "' . $code . '" sudah dipakai. Gunakan kode lain.');
        }
        if (Category::nameExists($name)) {
            throw new RuntimeException('Nama kategori "' . $name . '" sudah ada.');
        }

        return Database::transaction(static function () use ($code, $name, $description, $actorId): int {
            $id = Category::insert(['code' => $code, 'name' => $name, 'description' => $description ?: null], $actorId);
            AuditService::log(
                'CATEGORY_CREATE',
                'categories',
                (string) $id,
                sprintf('Menambah kategori "%s" (%s)', $name, $code),
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

        if (Category::codeExists($code, $id)) {
            throw new RuntimeException('Kode kategori "' . $code . '" sudah dipakai. Gunakan kode lain.');
        }
        if (Category::nameExists($name, $id)) {
            throw new RuntimeException('Nama kategori "' . $name . '" sudah ada.');
        }

        Database::transaction(static function () use ($id, $code, $name, $description, $existing, $actorId): void {
            Category::update($id, ['code' => $code, 'name' => $name, 'description' => $description ?: null], $actorId);
            AuditService::log(
                'CATEGORY_UPDATE',
                'categories',
                (string) $id,
                sprintf('Mengubah kategori "%s"', $name),
                [
                    'code'        => (string) $existing['code'],
                    'name'        => (string) $existing['name'],
                    'description' => $existing['description'],
                ],
                ['code' => $code, 'name' => $name, 'description' => $description ?: null]
            );
        });
    }

    /** Aktifkan / nonaktifkan kategori (riwayat & data tetap utuh). */
    public static function setActive(int $id, bool $active, ?int $actorId = null): void
    {
        $existing = self::require($id);
        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            throw new RuntimeException('Kategori sudah dalam status ' . ($active ? 'aktif' : 'nonaktif') . '.');
        }

        Database::transaction(static function () use ($id, $active, $existing, $actorId): void {
            Category::setActive($id, $active, $actorId);
            $dipakai = Category::sparepartCount($id);
            AuditService::log(
                $active ? 'CATEGORY_ACTIVATE' : 'CATEGORY_DEACTIVATE',
                'categories',
                (string) $id,
                sprintf(
                    '%s kategori "%s" (dipakai %d sparepart)',
                    $active ? 'Mengaktifkan' : 'Menonaktifkan',
                    (string) $existing['name'],
                    $dipakai
                ),
                ['is_active' => (int) $existing['is_active']],
                ['is_active' => $active ? 1 : 0]
            );
        });
    }

    /** @return array<string,mixed> */
    private static function require(int $id): array
    {
        $category = Category::find($id);
        if ($category === null) {
            throw new RuntimeException('Kategori tidak ditemukan.');
        }
        return $category;
    }

    private static function guard(string $code, string $name): void
    {
        if ($code === '') {
            throw new RuntimeException('Kode kategori wajib diisi.');
        }
        if (mb_strlen($code) > self::CODE_MAX) {
            throw new RuntimeException('Kode kategori maksimal ' . self::CODE_MAX . ' karakter.');
        }
        if ($name === '') {
            throw new RuntimeException('Nama kategori wajib diisi.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RuntimeException('Nama kategori maksimal ' . self::NAME_MAX . ' karakter.');
        }
    }
}
