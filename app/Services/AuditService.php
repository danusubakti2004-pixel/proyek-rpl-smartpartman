<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use Throwable;

/**
 * Audit Log — mencatat aktivitas penting (PRD FR-20).
 *
 * Ketentuan:
 * - Hanya TULIS dari aplikasi; tidak ada edit/hapus.
 * - Password, API key, token, dan rahasia lain TIDAK PERNAH direkam plaintext
 *   (di-redaksi otomatis oleh redact()).
 * - Kegagalan menulis log tidak boleh menjatuhkan request utama
 *   (dicatat ke error_log, transaksi tetap diproses).
 */
final class AuditService
{
    public static function log(
        string $activity,
        ?string $entityType = null,
        string|int|null $entityId = null,
        string $description = '',
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        self::write(Auth::user(), $activity, $entityType, $entityId, $description, $oldValues, $newValues);
    }

    /**
     * Catat audit dengan pelaku eksplisit. Dipakai setelah restore database:
     * sesi lama mungkin tidak lagi ada di database hasil restore, sehingga
     * user_id harus diverifikasi pemanggil (boleh NULL).
     *
     * @param array<string,mixed>|null $actor
     */
    public static function logActor(
        ?array $actor,
        string $activity,
        ?string $entityType = null,
        string|int|null $entityId = null,
        string $description = '',
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        self::write($actor, $activity, $entityType, $entityId, $description, $oldValues, $newValues);
    }

    /** @param array<string,mixed>|null $user */
    private static function write(
        ?array $user,
        string $activity,
        ?string $entityType,
        string|int|null $entityId,
        string $description,
        ?array $oldValues,
        ?array $newValues
    ): void {
        try {
            $sql = 'INSERT INTO audit_logs
                        (user_id, username, role, activity, entity_type, entity_id,
                         description, old_values, new_values, ip_address, created_at)
                    VALUES
                        (:user_id, :username, :role, :activity, :entity_type, :entity_id,
                         :description, :old_values, :new_values, :ip_address, NOW())';
            Database::pdo()->prepare($sql)->execute([
                ':user_id'     => $user['id'] ?? null,
                ':username'    => $user['username'] ?? 'sistem',
                ':role'        => $user['role'] ?? null,
                ':activity'    => $activity,
                ':entity_type' => $entityType,
                ':entity_id'   => $entityId !== null ? (string) $entityId : null,
                ':description' => $description,
                ':old_values'  => self::encode($oldValues),
                ':new_values'  => self::encode($newValues),
                ':ip_address'  => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            ]);
        } catch (Throwable $e) {
            // Jangan gagalkan transaksi utama hanya karena log; catat ke error_log.
            error_log('[AuditService] gagal menulis audit log: ' . $e->getMessage());
        }
    }

    private static function encode(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }
        $encoded = json_encode(self::redact($data), JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function redact(array $data): array
    {
        $sensitive = '/password|pass_hash|passphrase|api_key|apikey|token|secret|credential|authorization|cookie|session|private_key|signature/i';
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($sensitive, $key) === 1) {
                $result[$key] = '[REDAKTIF]';
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
