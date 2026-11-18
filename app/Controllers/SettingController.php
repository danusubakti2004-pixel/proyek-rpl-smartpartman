<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\LogoService;
use App\Services\SettingService;
use App\Services\ChatbotSettingsService;
use RuntimeException;

/**
 * Pengaturan aplikasi — HANYA Admin (PRD §6.3: Admin mengelola
 * konfigurasi sistem, bukan data master operasional).
 *
 * Berisi identitas bengkel untuk kop dokumen cetak dan parameter
 * fast/slow moving. Tidak ada rahasia yang disimpan/ditampilkan di sini.
 */
final class SettingController extends Controller
{
    /** GET /pengaturan */
    public function index(array $params = []): void
    {
        $this->view('settings/index', [
            'title'   => 'Pengaturan Aplikasi',
            'values'  => array_merge(SettingService::DEFAULTS, SettingService::all()),
            'old'     => Session::oldInput(),
            'isAdmin' => true,
            'chatbot' => ChatbotSettingsService::summary(),
        ]);
    }

    /** POST /pengaturan */
    public function update(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $input = $this->request->post();
        $currentLogo = (string) (SettingService::all()['workshop_logo'] ?? '');
        $input['workshop_logo'] = $currentLogo;
        $newLogo = null;

        try {
            $file = $_FILES['workshop_logo'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newLogo = LogoService::store($file);
                $input['workshop_logo'] = $newLogo;
            } elseif (!empty($input['remove_logo'])) {
                $input['workshop_logo'] = '';
            }

            $changed = SettingService::update($input, Auth::id());
        } catch (RuntimeException $e) {
            if ($newLogo !== null) {
                LogoService::delete($newLogo);
            }
            Session::flash('danger', $e->getMessage());
            Session::flashInput($input);
            $this->redirect(base_url('pengaturan'));
        }

        if ($input['workshop_logo'] !== $currentLogo) {
            LogoService::delete($currentLogo);
        }

        Session::flash(
            'success',
            $changed === []
                ? 'Tidak ada perubahan pengaturan.'
                : 'Pengaturan disimpan (' . count($changed) . ' nilai diperbarui).'
        );
        $this->redirect(base_url('pengaturan'));
    }

    /** GET /pengaturan/logo — menyajikan logo lewat route ber-otorisasi. */
    public function logo(array $params = []): void
    {
        $relative = (string) (SettingService::all()['workshop_logo'] ?? '');
        $file = LogoService::describe($relative);
        if ($file === null) {
            $this->abort(404, 'Logo bengkel belum diatur.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $file['mime']);
            header('Content-Length: ' . (int) $file['size']);
            header('Content-Disposition: inline; filename="' . basename($file['name']) . '"');
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
        }
        readfile(LogoService::absolutePath($relative) ?? '');
        exit;
    }
}
