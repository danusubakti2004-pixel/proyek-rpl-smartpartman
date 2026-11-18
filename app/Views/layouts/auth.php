<?php
/** @var string $content */
/** @var string $appName */
$flash = \App\Core\Session::flashPop();
$brand = $appName ?? 'SmartPartMan';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($brand) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">

<div class="auth-shell">
    <!-- Panel identitas merek -->
    <aside class="auth-aside">
        <a href="<?= e(base_url('login')) ?>" class="auth-brand">
            <span class="brand-mark">SP</span>
            <span>
                <strong class="d-block"><?= e($brand) ?></strong>
                <small class="text-white-50">Inventory System</small>
            </span>
        </a>

        <div class="auth-aside-copy">
            <h1>Kelola sparepart bengkel dengan rapi dan terukur.</h1>
            <p class="lead mb-0">
                Pantau stok, transaksi, laporan, dan prediksi kebutuhan sparepart
                dalam satu dashboard yang terintegrasi.
            </p>
            <ul class="auth-features">
                <li>
                    <span class="auth-check" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>
                    </span>
                    <span>Stok akurat dengan pencatatan barang masuk dan keluar.</span>
                </li>
                <li>
                    <span class="auth-check" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>
                    </span>
                    <span>Laporan, reorder, dan prediksi stok berbasis data.</span>
                </li>
                <li>
                    <span class="auth-check" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>
                    </span>
                    <span>Asisten AI untuk membantu analisis operasional bengkel.</span>
                </li>
            </ul>
        </div>

        <div class="auth-foot">&copy; <?= date('Y') ?> <?= e($brand) ?> &middot; Sistem Manajemen Inventory Sparepart</div>
    </aside>

    <!-- Panel formulir -->
    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-logo-mobile">
                <span class="brand-mark brand-mark-sm" style="background: var(--sp-navy); color:#fff;">SP</span>
                <span><?= e($brand) ?></span>
            </div>

            <?php foreach ($flash as $type => $message): ?>
                <div class="alert alert-<?= e(in_array($type, ['success', 'danger', 'warning'], true) ? $type : 'info') ?> alert-dismissible fade show" role="alert">
                    <?= e($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </div>

        <footer class="text-center text-muted small mt-4">
            &copy; <?= date('Y') ?> <?= e($brand) ?> &middot; Tahap lokal (XAMPP)
        </footer>
    </main>
</div>

<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
