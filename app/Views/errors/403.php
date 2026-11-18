<?php
/** @var int $code @var string $message @var string $appName */
$code = $code ?? 403;
$message = $message ?? 'Akses ditolak.';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= (int) $code ?> — <?= e($appName ?? 'SmartPartMan') ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
<div class="text-center p-4" style="max-width: 480px;">
    <div class="brand-mark mx-auto mb-3">SP</div>
    <h1 class="display-5 text-navy fw-bold"><?= (int) $code ?></h1>
    <p class="text-muted"><?= e($message) ?></p>
    <a href="<?= e(base_url('dashboard')) ?>" class="btn btn-navy">Kembali ke Dashboard</a>
    <p class="small text-muted mt-3 mb-0"><?= e($appName ?? 'SmartPartMan') ?></p>
</div>
</body>
</html>
