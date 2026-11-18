<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Supplier;
use App\Core\Database;
use RuntimeException;

/**
 * Supplier (FR-11).
 *
 * Aturan:
 * - Kode supplier unik; supplier TIDAK pernah dihapus permanen.
 * - Supplier nonaktif TIDAK dapat dipakai untuk transaksi baru
 *   (ensureUsableForTransaction() dipakai Fase 4: barang masuk & PO).
 * - Setiap perubahan dicatat di Audit Log.
 */
final class SupplierService
{
    public const CODE_MAX = 30;
    public const NAME_MAX = 150;

    /** @return array<int,array<string,mixed>> */
    public static function all(?string $search = null, ?string $status = null): array
    {
        $status = in_array($status, ['aktif', 'nonaktif'], true) ? $status : null;
        return Supplier::all($search, $status);
    }

    /** @return array<int,array<string,mixed>> */
    public static function options(bool $onlyActive = true): array
    {
        return Supplier::options($onlyActive);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Supplier::find($id);
    }

    /**
     * Pastikan supplier boleh dipakai pada TRANSAKSI BARU (FR-11).
     * Dipakai barang masuk (Fase 4) & Purchase Order (Fase 6).
     *
     * @return array<string,mixed>
     */
    public static function ensureUsableForTransaction(int $id): array
    {
        $supplier = Supplier::find($id);
        if ($supplier === null) {
            throw new RuntimeException('Supplier tidak ditemukan.');
        }
        if ((int) $supplier['is_active'] !== 1) {
            throw new RuntimeException(
                'Supplier "' . (string) $supplier['name'] . '" nonaktif dan tidak dapat dipakai untuk transaksi baru.'
            );
        }
        return $supplier;
    }

    /** @return array<string,mixed>|null Untuk import: cocokkan kode ATAU nama. */
    public static function findForImport(string $value): ?array
    {
        $value = MasterRules::text($value);
        return $value === '' ? null : Supplier::findByCodeOrName($value);
    }

    /** @param array<string,mixed> $data @return int ID supplier baru */
    public static function create(array $data, ?int $actorId = null): int
    {
        $clean = self::normalize($data);
        self::guard($clean);

        if (Supplier::codeExists($clean['code'])) {
            throw new RuntimeException('Kode supplier "' . $clean['code'] . '" sudah dipakai. Gunakan kode lain.');
        }

        return Database::transaction(static function () use ($clean, $actorId): int {
            $id = Supplier::insert($clean, $actorId);
            AuditService::log(
                'SUPPLIER_CREATE',
                'suppliers',
                (string) $id,
                sprintf('Menambah supplier "%s" (%s)', $clean['name'], $clean['code']),
                null,
                self::auditValues($clean) + ['is_active' => 1]
            );
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $actorId = null): void
    {
        $existing = self::require($id);
        $clean = self::normalize($data);
        self::guard($clean);

        if (Supplier::codeExists($clean['code'], $id)) {
            throw new RuntimeException('Kode supplier "' . $clean['code'] . '" sudah dipakai. Gunakan kode lain.');
        }

        Database::transaction(static function () use ($id, $clean, $existing, $actorId): void {
            Supplier::update($id, $clean, $actorId);
            AuditService::log(
                'SUPPLIER_UPDATE',
                'suppliers',
                (string) $id,
                sprintf('Mengubah supplier "%s"', $clean['name']),
                self::auditValues($existing),
                self::auditValues($clean)
            );
        });
    }

    /** Aktifkan / nonaktifkan supplier (data & riwayat tetap utuh). */
    public static function setActive(int $id, bool $active, ?int $actorId = null): void
    {
        $existing = self::require($id);
        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            throw new RuntimeException('Supplier sudah dalam status ' . ($active ? 'aktif' : 'nonaktif') . '.');
        }

        Database::transaction(static function () use ($id, $active, $existing, $actorId): void {
            Supplier::setActive($id, $active, $actorId);
            AuditService::log(
                $active ? 'SUPPLIER_ACTIVATE' : 'SUPPLIER_DEACTIVATE',
                'suppliers',
                (string) $id,
                sprintf(
                    '%s supplier "%s" (dipakai %d sparepart, %d penerimaan)',
                    $active ? 'Mengaktifkan' : 'Menonaktifkan',
                    (string) $existing['name'],
                    Supplier::sparepartCount($id),
                    Supplier::receiptCount($id)
                ),
                ['is_active' => (int) $existing['is_active']],
                ['is_active' => $active ? 1 : 0]
            );
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalize(array $data): array
    {
        $email = MasterRules::text($data['email'] ?? '');
        $leadTime = MasterRules::parseDecimal($data['lead_time_days'] ?? null);

        return [
            'code'           => MasterRules::code($data['code'] ?? ''),
            'name'           => MasterRules::text($data['name'] ?? ''),
            'address'        => MasterRules::text($data['address'] ?? '') ?: null,
            'phone'          => MasterRules::text($data['phone'] ?? '') ?: null,
            'email'          => $email === '' ? null : mb_strtolower($email),
            'contact_person' => MasterRules::text($data['contact_person'] ?? '') ?: null,
            'lead_time_days' => $leadTime === null ? null : max(0, (int) round($leadTime)),
        ];
    }

    /** @param array<string,mixed> $clean */
    private static function guard(array $clean): void
    {
        if ($clean['code'] === '') {
            throw new RuntimeException('Kode supplier wajib diisi.');
        }
        if (mb_strlen($clean['code']) > self::CODE_MAX) {
            throw new RuntimeException('Kode supplier maksimal ' . self::CODE_MAX . ' karakter.');
        }
        if ($clean['name'] === '') {
            throw new RuntimeException('Nama supplier wajib diisi.');
        }
        if (mb_strlen($clean['name']) > self::NAME_MAX) {
            throw new RuntimeException('Nama supplier maksimal ' . self::NAME_MAX . ' karakter.');
        }
        if ($clean['email'] !== null && filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email supplier tidak valid.');
        }
        if ($clean['lead_time_days'] !== null && $clean['lead_time_days'] > 3650) {
            throw new RuntimeException('Lead time supplier tidak masuk akal (maksimal 3650 hari).');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function auditValues(array $row): array
    {
        return [
            'code'           => $row['code'] ?? null,
            'name'           => $row['name'] ?? null,
            'address'        => $row['address'] ?? null,
            'phone'          => $row['phone'] ?? null,
            'email'          => $row['email'] ?? null,
            'contact_person' => $row['contact_person'] ?? null,
            'lead_time_days' => $row['lead_time_days'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function require(int $id): array
    {
        $supplier = Supplier::find($id);
        if ($supplier === null) {
            throw new RuntimeException('Supplier tidak ditemukan.');
        }
        return $supplier;
    }
}
