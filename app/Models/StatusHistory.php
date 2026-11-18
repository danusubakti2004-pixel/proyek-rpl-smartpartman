<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Riwayat status dokumen pembelian (Fase 6) — reorder & Purchase Order.
 *
 * Setiap perubahan status dokumen mencatat aktor, waktu, status SEBELUMNYA,
 * status BERIKUTNYA, dan alasan (wajib saat penolakan/pembatalan).
 *
 * Sifatnya append-only seperti ledger: aplikasi hanya menulis baris baru,
 * tidak pernah mengubah/menghapus baris lama.
 */
final class StatusHistory extends Model
{
    public const TYPE_REORDER = 'REORDER';
    public const TYPE_PO = 'PO';

    /** @var string[] */
    public const TYPES = [self::TYPE_REORDER, self::TYPE_PO];

    /**
     * Catat satu transisi status.
     *
     * @param string|null $oldStatus null = dokumen baru (belum punya status)
     */
    public static function record(
        string $documentType,
        int $documentId,
        ?string $oldStatus,
        string $newStatus,
        ?int $actorId,
        ?string $actorName,
        ?string $reason = null
    ): int {
        self::execute(
            'INSERT INTO purchase_status_history
                (document_type, document_id, old_status, new_status, actor_id, actor_name, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $documentType,
                $documentId,
                $oldStatus,
                $newStatus,
                $actorId,
                $actorName,
                $reason,
            ]
        );
        return self::lastId();
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(string $documentType, int $documentId): array
    {
        return self::fetchAll(
            'SELECT h.*, u.full_name AS actor_full_name, u.username AS actor_username
               FROM purchase_status_history h
          LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.document_type = ? AND h.document_id = ?
           ORDER BY h.id ASC',
            [$documentType, $documentId]
        );
    }

    /** Transisi terakhir sebuah dokumen (null bila belum ada riwayat). */
    public static function latestStatus(string $documentType, int $documentId): ?string
    {
        $row = self::fetchOne(
            'SELECT new_status FROM purchase_status_history
              WHERE document_type = ? AND document_id = ?
           ORDER BY id DESC LIMIT 1',
            [$documentType, $documentId]
        );
        return $row === null ? null : (string) $row['new_status'];
    }
}
