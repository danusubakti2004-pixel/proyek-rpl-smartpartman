<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\Backup;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\BackupService;
use RuntimeException;

/**
 * Backup & restore database — HANYA Admin (Fase 9).
 *
 * Restore adalah aksi paling berisiko di aplikasi. Selain CSRF dan role,
 * pengguna wajib mengetik PULIHKAN dan memasukkan password sendiri.
 * Setelah restore, sesi login lama diakhiri karena isi database (termasuk
 * akun) sudah diganti oleh snapshot.
 */
final class BackupController extends Controller
{
    private const THROTTLE_KEY = 'backup_restore_guard';
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 900;

    /** GET /pengaturan/backup */
    public function index(array $params = []): void
    {
        $service = BackupService::fromConfig();
        $rows = Backup::all(200);
        foreach ($rows as &$row) {
            $row['file_available'] = $service->isAvailable($row);
        }
        unset($row);

        $this->view('settings/backup', [
            'title'    => 'Backup & Restore',
            'backups'  => $rows,
            'database' => $service->databaseName(),
            'maxLabel' => (string) round((int) \App\Core\App::config('backup.max_bytes', 104857600) / 1048576) . ' MB',
        ]);
    }

    /** POST /pengaturan/backup */
    public function create(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        try {
            $backup = BackupService::fromConfig()->create('Backup manual dari halaman Admin', Auth::id());
            Session::flash('success', 'Backup berhasil dibuat: ' . (string) $backup['filename']);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
        }

        $this->redirect(base_url('pengaturan/backup'));
    }

    /** GET /pengaturan/backup/{id}/unduh */
    public function download(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $file = BackupService::fromConfig()->download($id);
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengaturan/backup'));
        }

        AuditService::log(
            'BACKUP_DOWNLOAD',
            'backups',
            (string) $id,
            'Mengunduh berkas backup: ' . (string) $file['row']['filename']
        );

        $path = $file['path'];
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['row']['filename']) ?? 'backup.sql';
        if (!headers_sent()) {
            header('Content-Type: application/sql');
            header('Content-Length: ' . (int) filesize($path));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
        }
        readfile($path);
        exit;
    }

    /** POST /pengaturan/backup/{id}/restore */
    public function restore(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        if ($this->isThrottled()) {
            Session::flash('danger', 'Terlalu banyak percobaan restore. Coba lagi setelah 15 menit.');
            $this->redirect(base_url('pengaturan/backup'));
        }

        $id = (int) ($params['id'] ?? 0);
        $password = (string) $this->request->input('password', '');
        $confirmation = trim((string) $this->request->input('confirmation', ''));

        if ($password === '' || mb_strlen($password) > 200) {
            $this->denyRestore($id, 'Password Admin wajib diisi dengan benar.');
        }
        if (!AuthService::verifyCurrentPassword($password)) {
            $this->denyRestore($id, 'Password Admin salah. Restore dibatalkan.');
        }
        if ($confirmation !== BackupService::CONFIRMATION) {
            $this->denyRestore($id, 'Ketik PULIHKAN (huruf besar) untuk mengonfirmasi restore.');
        }

        try {
            $user = Auth::user();
            $result = BackupService::fromConfig()->restore(
                $id,
                $confirmation,
                Auth::id(),
                (string) ($user['username'] ?? 'sistem'),
                Auth::role()
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('pengaturan/backup'));
        }

        $this->clearThrottle();
        Session::flash(
            'success',
            'Restore berhasil dari backup #' . $id
            . '. Backup keselamatan #' . (int) $result['safety_id']
            . ' tetap tersimpan. Silakan login kembali.'
        );

        // Database sudah diganti: jangan pertahankan identitas sesi lama.
        Auth::logout(true);
        $this->redirect(base_url('login'));
    }

    private function denyRestore(int $id, string $message): void
    {
        $this->registerFailure();
        AuditService::log(
            'BACKUP_RESTORE_DENIED',
            'backups',
            $id > 0 ? (string) $id : null,
            'Restore ditolak: ' . $message
        );
        Session::flash('danger', $message);
        $this->redirect(base_url('pengaturan/backup'));
    }

    /** @return array{count:int,until:int} */
    private function throttle(): array
    {
        $state = Session::get(self::THROTTLE_KEY, []);
        return [
            'count' => is_array($state) ? max(0, (int) ($state['count'] ?? 0)) : 0,
            'until' => is_array($state) ? max(0, (int) ($state['until'] ?? 0)) : 0,
        ];
    }

    private function isThrottled(): bool
    {
        return $this->throttle()['until'] > time();
    }

    private function registerFailure(): void
    {
        $state = $this->throttle();
        $count = $state['count'] + 1;
        $until = $count >= self::MAX_FAILURES ? time() + self::LOCK_SECONDS : 0;
        Session::put(self::THROTTLE_KEY, [
            'count' => $until > 0 ? 0 : $count,
            'until' => $until,
        ]);
    }

    private function clearThrottle(): void
    {
        Session::forget(self::THROTTLE_KEY);
    }
}
