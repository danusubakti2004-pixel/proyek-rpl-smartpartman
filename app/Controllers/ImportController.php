<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\Import\Spreadsheet;
use App\Services\MasterImportService;
use RuntimeException;

/**
 * Impor master data & stok awal dari berkas .csv / .xlsx (FR-04, FR-05).
 *
 * Alur dua langkah yang disengaja:
 *   1. Unggah → PRATINJAU: setiap baris diperiksa, nomor baris + alasan
 *      kegagalan ditampilkan, dan BELUM ada data yang ditulis.
 *   2. KOMIT (satu transaksi): hanya dijalankan bila seluruh baris valid.
 *      Bila ada satu saja yang bermasalah, TIDAK ADA data yang tersimpan.
 *
 * Kode duplikat DITOLAK (tidak menimpa data lama) dan nilai stok tidak
 * pernah ditulis diam-diam: nilai stok menghasilkan pergerakan STOK_AWAL.
 *
 * HANYA Partman (dipaksa RoleMiddleware di route).
 */
final class ImportController extends Controller
{
    /** GET /import */
    public function index(array $params = []): void
    {
        $types = [];
        foreach (MasterImportService::types() as $type) {
            $types[$type] = [
                'title'   => MasterImportService::titleOf($type),
                'headers' => MasterImportService::headers($type),
                'sample'  => MasterImportService::sampleRow($type),
            ];
        }

        $this->view('master/import/index', [
            'title'      => 'Impor Data',
            'types'      => $types,
            'maxRows'    => MasterImportService::MAX_ROWS,
            'maxBytes'   => Spreadsheet::formatBytes(MasterImportService::MAX_BYTES),
            'zipReady'   => class_exists(\ZipArchive::class),
        ]);
    }

    /** POST /import/pratinjau */
    public function preview(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $type = (string) $this->request->input('jenis', '');
        if (!MasterImportService::isKnownType($type)) {
            Session::flash('danger', 'Pilih jenis data yang akan diimpor.');
            $this->redirect(base_url('import'));
        }

        $file = $_FILES['berkas'] ?? null;

        try {
            if (!is_array($file)) {
                throw new RuntimeException('Pilih berkas .csv atau .xlsx terlebih dahulu.');
            }
            $data = Spreadsheet::readUploaded($file);
            $preview = MasterImportService::preview(
                $type,
                $data['headers'],
                $data['rows'],
                (string) $data['name'],
                (string) $data['extension']
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('import'));
        }

        if ((int) $preview['summary']['total'] === 0) {
            Session::flash('danger', 'Berkas tidak berisi baris data yang dapat diimpor.');
            $this->redirect(base_url('import'));
        }

        $token = bin2hex(random_bytes(16));
        MasterImportService::stash($token, $preview);

        $this->view('master/import/preview', [
            'title'   => 'Pratinjau Impor — ' . MasterImportService::titleOf($type),
            'preview' => $preview,
            'token'   => $token,
        ]);
    }

    /** POST /import/komit */
    public function commit(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $token = (string) $this->request->input('token', '');
        $stashed = MasterImportService::pull($token);
        if ($stashed === null) {
            Session::flash('danger', 'Data pratinjau tidak ditemukan atau sudah kedaluwarsa. Silakan unggah ulang berkas.');
            $this->redirect(base_url('import'));
        }

        $type = (string) $stashed['type'];
        /** @var array<int,array<string,mixed>> $rows */
        $rows = is_array($stashed['rows'] ?? null) ? $stashed['rows'] : [];

        try {
            $result = MasterImportService::commit(
                $type,
                $rows,
                Auth::id(),
                (string) ($stashed['file'] ?? 'berkas')
            );
        } catch (RuntimeException $e) {
            MasterImportService::forget();
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('import'));
        }

        MasterImportService::forget();

        Session::flash('success', sprintf(
            'Impor %s berhasil: %d baris tersimpan, %d pergerakan stok tercatat pada kartu stok.',
            strtolower(MasterImportService::titleOf($type)),
            (int) $result['created'],
            (int) $result['movements']
        ));
        $this->redirect(base_url('sparepart'));
    }

    /** GET /import/template?jenis=sparepart&format=csv */
    public function template(array $params = []): void
    {
        $type = (string) $this->request->input('jenis', '');
        $format = strtolower((string) $this->request->input('format', 'csv'));

        if (!MasterImportService::isKnownType($type)) {
            $this->abort(404, 'Jenis template tidak dikenal.');
        }
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            $this->abort(404, 'Format template tidak dikenal.');
        }

        try {
            if ($format === 'csv') {
                $content = MasterImportService::csvTemplate($type);
                $mime = 'text/csv; charset=utf-8';
            } else {
                $content = MasterImportService::xlsxTemplate($type);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('import'));
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . MasterImportService::fileNameOf($type) . '.' . $format . '"');
            header('Content-Length: ' . strlen($content));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo $content;
        exit;
    }
}
