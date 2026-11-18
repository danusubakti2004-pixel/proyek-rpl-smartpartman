<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuditLogService;
use App\Services\AuditService;

/**
 * Audit Log — HANYA Admin (PRD FR-20).
 *
 * Hanya baca: tidak ada tombol/route ubah atau hapus. Database juga
 * menolak UPDATE/DELETE pada tabel audit_logs (trigger migrasi 007).
 */
final class AuditLogController extends Controller
{
    /** GET /audit-log */
    public function index(array $params = []): void
    {
        $filters = [
            'username'    => trim((string) $this->request->input('username', '')),
            'activity'    => trim((string) $this->request->input('activity', '')),
            'entity_type' => trim((string) $this->request->input('entity_type', '')),
            'entity_id'   => trim((string) $this->request->input('entity_id', '')),
            'keyword'     => trim((string) $this->request->input('keyword', '')),
            'date_from'   => trim((string) $this->request->input('date_from', '')),
            'date_to'     => trim((string) $this->request->input('date_to', '')),
        ];
        $page = max(1, (int) $this->request->input('page', 1));

        $this->view('audit_log/index', [
            'title'       => 'Audit Log',
            'result'      => AuditLogService::paginate($filters, $page),
            'filters'     => $filters,
            'activities'  => AuditLogService::activities(),
            'entityTypes' => AuditLogService::entityTypes(),
        ]);
    }

    /** GET /audit-log/export — CSV sesuai filter, khusus Admin. */
    public function export(array $params = []): void
    {
        $filters = [
            'username'    => trim((string) $this->request->input('username', '')),
            'activity'    => trim((string) $this->request->input('activity', '')),
            'entity_type' => trim((string) $this->request->input('entity_type', '')),
            'entity_id'   => trim((string) $this->request->input('entity_id', '')),
            'keyword'     => trim((string) $this->request->input('keyword', '')),
            'date_from'   => trim((string) $this->request->input('date_from', '')),
            'date_to'     => trim((string) $this->request->input('date_to', '')),
        ];
        $rows = AuditLogService::exportRows($filters);
        $csv = AuditLogService::csv($rows);

        AuditService::log(
            'AUDIT_LOG_EXPORT',
            'audit_logs',
            null,
            'Mengekspor Audit Log ke CSV (' . count($rows) . ' baris, maksimal ' . AuditLogService::EXPORT_MAX . ').'
        );

        $name = 'audit-log-smartpartman-' . date('Ymd-His') . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($csv));
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
        }
        echo $csv;
        exit;
    }

    /** GET /audit-log/{id} */
    public function show(array $params = []): void
    {
        $log = AuditLogService::find((int) ($params['id'] ?? 0));
        if ($log === null) {
            $this->abort(404, 'Catatan audit tidak ditemukan.');
        }

        $this->view('audit_log/show', [
            'title' => 'Detail Audit Log #' . (int) $log['id'],
            'log'   => $log,
        ]);
    }
}
