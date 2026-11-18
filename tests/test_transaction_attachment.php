<?php
declare(strict_types=1);

use App\Core\App;
use App\Services\AttachmentService;

/**
 * Pengujian Fase 4 — bukti transaksi (unggahan foto/PDF, FR-06).
 *
 * Yang diuji (PRD §10.1):
 *  - hanya JPG/JPEG/PNG/PDF yang diterima; isi berkas diperiksa (bukan hanya nama);
 *  - ukuran dibatasi;
 *  - berkas disimpan DI LUAR web root dengan nama acak (tidak memakai nama kiriman);
 *  - hanya dapat diambil lewat jalur yang divalidasi (anti path traversal);
 *  - berkas dapat dihapus bila transaksi gagal.
 */

function test_transaction_attachment_menyimpan_berkas_valid(): void
{
    $upload = t_upload('Nota Pembelian.png', t_png_bytes());
    $stored = AttachmentService::store($upload['file'], 'receipts');

    t_true($stored !== null, 'Unggahan PNG disimpan');
    t_eq('Nota Pembelian.png', $stored['name'], 'Nama asli disimpan hanya sebagai keterangan');
    t_true(str_starts_with($stored['path'], 'receipts/'), 'Berkas disimpan pada subfolder kategori');
    t_true(basename($stored['path']) !== 'Nota Pembelian.png', 'Nama berkas di disk diacak (bukan nama kiriman)');
    t_true(preg_match('/^[0-9a-f]{32}\.png$/', basename($stored['path'])) === 1, 'Nama berkas acak 32 hex + ekstensi');

    $absolute = AttachmentService::absolutePath($stored['path']);
    t_true($absolute !== null && is_file($absolute), 'Berkas benar-benar ada di penyimpanan privat');

    $base = realpath((string) App::config('paths.uploads', BASE_PATH . '/storage/private_uploads'));
    t_true($base !== false, 'Folder penyimpanan privat dapat diselesaikan (realpath)');
    t_true(
        str_starts_with(str_replace('\\', '/', (string) $absolute), str_replace('\\', '/', (string) $base) . '/'),
        'Berkas tersimpan DI LUAR web root (di storage/private_uploads)'
    );

    $meta = AttachmentService::describe($stored['path'], $stored['name']);
    t_true($meta !== null, 'Metadata berkas dapat dibaca untuk pengiriman ke peramban');
    t_eq('image/png', (string) $meta['mime'], 'MIME terdeteksi dari isi berkas');
    t_true((int) $meta['size'] > 0, 'Ukuran berkas terbaca');

    // PDF juga diterima.
    $pdfUpload = t_upload('invoice.pdf', t_pdf_bytes());
    $pdf = AttachmentService::store($pdfUpload['file'], 'receipts');
    t_true($pdf !== null && str_ends_with($pdf['path'], '.pdf'), 'Unggahan PDF diterima dan disimpan');

    // Bersihkan.
    AttachmentService::delete($stored['path']);
    AttachmentService::delete($pdf['path']);
    @unlink($upload['tmp']);
    @unlink($pdfUpload['tmp']);
    t_eq(null, AttachmentService::absolutePath($stored['path']), 'Berkas terhapus bila transaksi gagal');
}

function test_transaction_attachment_tanpa_berkas_diabaikan(): void
{
    t_eq(null, AttachmentService::store(null), 'Tanpa berkas → tidak ada bukti (opsional)');
    t_eq(
        null,
        AttachmentService::store(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]),
        'Berkas kosong pada form → tidak ada bukti'
    );
    t_eq('5 MB', AttachmentService::maxSizeLabel(), 'Batas ukuran bukti dijelaskan ke pengguna');
}

function test_transaction_attachment_menolak_berkas_tidak_sesuai(): void
{
    $txt = t_upload('catatan.txt', 'ini bukan gambar');
    t_throws(
        static fn () => AttachmentService::store($txt['file'], 'receipts'),
        RuntimeException::class,
        'Ekstensi di luar daftar putih DITOLAK'
    );

    // Nama .png tapi isinya teks → MIME tidak cocok.
    $fake = t_upload('palsu.png', 'bukan gambar sama sekali');
    t_throws(
        static fn () => AttachmentService::store($fake['file'], 'receipts'),
        RuntimeException::class,
        'Isi berkas yang tidak sesuai ekstensi DITOLAK'
    );

    // PDF palsu (nama .pdf, isi teks) → juga ditolak.
    $fakePdf = t_upload('palsu.pdf', 'teks biasa');
    t_throws(
        static fn () => AttachmentService::store($fakePdf['file'], 'receipts'),
        RuntimeException::class,
        'PDF palsu DITOLAK'
    );

    foreach ([$txt, $fake, $fakePdf] as $u) {
        @unlink($u['tmp']);
    }
}

function test_transaction_attachment_batas_ukuran_dan_galat_unggah(): void
{
    $upload = t_upload('besar.png', t_png_bytes(), null, AttachmentService::MAX_BYTES + 1);
    t_throws(
        static fn () => AttachmentService::store($upload['file'], 'receipts'),
        RuntimeException::class,
        'Berkas melebihi batas ukuran DITOLAK'
    );
    @unlink($upload['tmp']);

    $kosong = t_upload('kosong.png', '', null, 0);
    t_throws(
        static fn () => AttachmentService::store($kosong['file'], 'receipts'),
        RuntimeException::class,
        'Berkas kosong (0 byte) DITOLAK'
    );
    @unlink($kosong['tmp']);

    $gagal = t_upload('putus.png', t_png_bytes(), UPLOAD_ERR_PARTIAL);
    t_throws(
        static fn () => AttachmentService::store($gagal['file'], 'receipts'),
        RuntimeException::class,
        'Unggahan yang terputus DITOLAK dengan pesan jelas'
    );
    @unlink($gagal['tmp']);

    $terlaluBesar = t_upload('x.png', t_png_bytes(), UPLOAD_ERR_INI_SIZE);
    t_throws(
        static fn () => AttachmentService::store($terlaluBesar['file'], 'receipts'),
        RuntimeException::class,
        'Unggahan melebihi batas server DITOLAK'
    );
    @unlink($terlaluBesar['tmp']);
}

function test_transaction_attachment_anti_path_traversal(): void
{
    t_eq(null, AttachmentService::absolutePath(''), 'Path kosong ditolak');
    t_eq(null, AttachmentService::absolutePath('../../config/config.php'), 'Path traversal (../) ditolak');
    t_eq(null, AttachmentService::absolutePath('receipts/../../.env'), 'Percobaan keluar dari folder unggahan ditolak');
    t_eq(null, AttachmentService::absolutePath('receipts/2026/10/tidak-ada.png'), 'Berkas yang tidak ada ditolak');
    t_eq(null, AttachmentService::describe('../../.env'), 'Metadata berkas di luar folder unggahan ditolak');

    // Nama kiriman dengan path juga tidak dipakai sebagai lokasi.
    $upload = t_upload('..\\..\\evil.png', t_png_bytes());
    $stored = AttachmentService::store($upload['file'], 'receipts');
    t_true($stored !== null, 'Nama kiriman berbahaya tidak menghentikan penyimpanan');
    t_true(!str_contains($stored['path'], '..'), 'Path hasil penyimpanan bersih dari traversal');
    AttachmentService::delete($stored['path']);
    @unlink($upload['tmp']);
}
