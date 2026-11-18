<?php
/**
 * Layout khusus cetak/dokumen (tanpa sidebar & navigasi aplikasi).
 *
 * @var string $content
 * @var string $title
 * @var string $orientation 'portrait'|'landscape'
 * @var string|null $backUrl
 * @var string|null $pdfUrl
 * @var array{name:string,address:string,phone:string,email:string,note?:string}|null $identity
 */
$orientation = ($orientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
$backUrl = $backUrl ?? base_url('dashboard');
$pdfUrl = $pdfUrl ?? null;
$identity = $identity ?? null;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Cetak') ?> — SmartPartMan</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
    <style>
        @page { size: A4 <?= $orientation ?>; margin: 12mm; }
    </style>
</head>
<body class="bg-light print-body print-<?= e($orientation) ?>">

<div class="print-toolbar d-print-none">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">&#128424; Cetak</button>
        <?php if ($pdfUrl !== null): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e($pdfUrl) ?>">Unduh PDF</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($backUrl) ?>">Kembali</a>
    </div>
    <div class="small text-muted mt-2">
        Gunakan tombol Cetak, lalu pilih "Simpan sebagai PDF" bila perlu.
        Matikan header/footer browser agar tabel tidak terpotong.
    </div>
</div>

<main class="print-sheet<?= $orientation === 'landscape' ? ' print-landscape-sheet' : '' ?>">
    <?= $content ?>
</main>

</body>
</html>
