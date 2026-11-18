<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Pembacaan Audit Log (PRD FR-20) — hanya untuk Admin.
 *
 * Sifat append-only: kelas ini HANYA menyediakan baca (find/paginate).
 * Tidak ada metode ubah/hapus; database juga menolak UPDATE/DELETE
 * lewat trigger (migrasi 007).
 */
final class AuditLogService
{
    public const PER_PAGE = 25;
    public const EXPORT_MAX = 10000;

    /**
     * @param array{username?:string, activity?:string, entity_type?:string, entity_id?:string, keyword?:string, date_from?:string, date_to?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function paginate(array $filters = [], int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = self::buildWhere($filters);

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare('SELECT a.* FROM audit_logs a' . $where . ' ORDER BY a.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM audit_logs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return string[] Daftar jenis aktivitas yang pernah tercatat (untuk filter). */
    public static function activities(): array
    {
        $rows = Database::pdo()->query('SELECT DISTINCT activity FROM audit_logs ORDER BY activity ASC')->fetchAll();
        return array_map(static fn (array $row): string => (string) $row['activity'], $rows);
    }

    /** @return string[] Daftar jenis entitas yang pernah tercatat (untuk filter). */
    public static function entityTypes(): array
    {
        $rows = Database::pdo()->query(
            "SELECT DISTINCT entity_type FROM audit_logs
              WHERE entity_type IS NOT NULL AND entity_type <> ''
              ORDER BY entity_type ASC"
        )->fetchAll();
        return array_map(static fn (array $row): string => (string) $row['entity_type'], $rows);
    }

    /**
     * Ambil baris untuk ekspor CSV (maksimal EXPORT_MAX, hanya Admin).
     *
     * @param array<string,string> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function exportRows(array $filters = [], int $limit = self::EXPORT_MAX): array
    {
        [$where, $params] = self::buildWhere($filters);
        $limit = max(1, min(self::EXPORT_MAX, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT a.* FROM audit_logs a' . $where . ' ORDER BY a.id DESC LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * CSV aman: UTF-8 BOM untuk Excel + cegah formula injection.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'ID', 'Waktu', 'Pengguna', 'Role', 'Aktivitas', 'Entitas', 'ID Entitas',
            'Deskripsi', 'IP', 'Nilai Sebelum', 'Nilai Sesudah',
        ], ',', '"', '\\');

        $safe = static function (mixed $value): string {
            $text = (string) ($value ?? '');
            if (preg_match('/^[=+\-@\t\r]/', $text) === 1) {
                return "'" . $text;
            }
            return $text;
        };

        foreach ($rows as $row) {
            fputcsv($handle, [
                $safe($row['id'] ?? ''),
                $safe($row['created_at'] ?? ''),
                $safe($row['username'] ?? ''),
                $safe($row['role'] ?? ''),
                $safe($row['activity'] ?? ''),
                $safe($row['entity_type'] ?? ''),
                $safe($row['entity_id'] ?? ''),
                $safe($row['description'] ?? ''),
                $safe($row['ip_address'] ?? ''),
                $safe($row['old_values'] ?? ''),
                $safe($row['new_values'] ?? ''),
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return is_string($csv) ? $csv : '';
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string, 1:array<string,string>}
     */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        $username = trim((string) ($filters['username'] ?? ''));
        if ($username !== '') {
            $clauses[] = 'a.username LIKE :username';
            $params[':username'] = '%' . $username . '%';
        }

        $activity = trim((string) ($filters['activity'] ?? ''));
        if ($activity !== '') {
            $clauses[] = 'a.activity = :activity';
            $params[':activity'] = $activity;
        }

        $entityType = trim((string) ($filters['entity_type'] ?? ''));
        if ($entityType !== '') {
            $clauses[] = 'a.entity_type = :entity_type';
            $params[':entity_type'] = $entityType;
        }

        $entityId = trim((string) ($filters['entity_id'] ?? ''));
        if ($entityId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $entityId) === 1) {
            $clauses[] = 'a.entity_id = :entity_id';
            $params[':entity_id'] = $entityId;
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $keyword = mb_substr($keyword, 0, 100);
            $clauses[] = '(a.description LIKE :keyword_desc OR a.username LIKE :keyword_user)';
            $params[':keyword_desc'] = '%' . $keyword . '%';
            $params[':keyword_user'] = '%' . $keyword . '%';
        }

        $dateFrom = self::validDate((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== null) {
            $clauses[] = 'a.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = self::validDate((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== null) {
            $clauses[] = 'a.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private static function validDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        return checkdate($month, $day, $year) ? $value : null;
    }
}
