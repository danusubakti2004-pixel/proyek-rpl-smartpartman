<?php
/** @var string $content */
/** @var string $appName */
use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

$flash = Session::flashPop();
$user = Auth::user();
$role = (string) ($user['role'] ?? '');
$isAdmin = $role === 'admin';
$pageTitle = $title ?? 'Dashboard';
$currentPath = (new Request())->path();

/** Tandai menu aktif berdasarkan path saat ini. */
$isActive = static function (string $prefix) use ($currentPath): bool {
    if ($prefix === '/dashboard') {
        return $currentPath === '/' || $currentPath === '/dashboard';
    }
    if ($prefix === '/pengaturan') {
        return $currentPath === '/pengaturan';
    }
    return $currentPath === $prefix || str_starts_with($currentPath, $prefix . '/');
};

$waOpen = str_starts_with($currentPath, '/pengaturan/whatsapp');

// Menu sesuai role (PRD §6.4), dikelompokkan agar navigasi lebih mudah dipahami.
$groups = [
    [
        'label' => 'Utama',
        'items' => [
            ['label' => 'Dashboard', 'url' => base_url('dashboard'), 'path' => '/dashboard', 'icon' => 'dashboard'],
        ],
    ],
    [
        'label' => 'Data Master',
        'items' => [
            // Fase 3 — data master: semua role boleh MELIHAT; hanya Partman boleh mengelola.
            ['label' => 'Sparepart', 'url' => base_url('sparepart'), 'path' => '/sparepart', 'icon' => 'box'],
            ['label' => 'Kategori', 'url' => base_url('kategori'), 'path' => '/kategori', 'icon' => 'layers'],
            ['label' => 'Supplier', 'url' => base_url('supplier'), 'path' => '/supplier', 'icon' => 'truck'],
            ['label' => 'Lokasi', 'url' => base_url('lokasi'), 'path' => '/lokasi', 'icon' => 'location'],
        ],
    ],
    [
        'label' => 'Transaksi',
        'items' => [
            // Fase 4 — transaksi: semua role boleh MELIHAT; hanya Partman boleh mencatat.
            ['label' => 'Barang Masuk', 'url' => base_url('transaksi/masuk'), 'path' => '/transaksi/masuk', 'icon' => 'in'],
            ['label' => 'Barang Keluar', 'url' => base_url('transaksi/keluar'), 'path' => '/transaksi/keluar', 'icon' => 'out'],
            ['label' => 'Stock Opname', 'url' => base_url('transaksi/opname'), 'path' => '/transaksi/opname', 'icon' => 'clipboard'],
            ['label' => 'Riwayat Stok', 'url' => base_url('transaksi/riwayat'), 'path' => '/transaksi/riwayat', 'icon' => 'history'],
            ['label' => 'Penyesuaian Stok', 'url' => base_url('transaksi/penyesuaian'), 'path' => '/transaksi/penyesuaian', 'icon' => 'adjust'],
        ],
    ],
    [
        'label' => 'Analitik',
        'items' => [
            // Fase 5 — laporan & dokumen: semua role boleh melihat/mengunduh.
            ['label' => 'Laporan', 'url' => base_url('laporan'), 'path' => '/laporan', 'icon' => 'chart'],
            // Fase 6 — reorder & pembelian: semua role membaca; pengelolaan sesuai peran.
            ['label' => 'Reorder', 'url' => base_url('reorder'), 'path' => '/reorder', 'icon' => 'reorder'],
            ['label' => 'Pembelian / PO', 'url' => base_url('pembelian'), 'path' => '/pembelian', 'icon' => 'file'],
            // Fase 7 — prediksi stok: semua role membaca; analisis dijalankan Partman.
            ['label' => 'Prediksi Stok', 'url' => base_url('prediksi'), 'path' => '/prediksi', 'icon' => 'trending'],
        ],
    ],
    [
        'label' => 'Asisten',
        'items' => [
            // Fase 8 — chatbot: semua role; konteks disaring server sesuai hak akses.
            ['label' => 'Chatbot', 'url' => base_url('chatbot'), 'path' => '/chatbot', 'icon' => 'message'],
        ],
    ],
];

if ($role === 'partman') {
    $groups[2]['items'][] = ['label' => 'Koreksi Transaksi', 'url' => base_url('transaksi/koreksi'), 'path' => '/transaksi/koreksi', 'icon' => 'undo'];
    $groups[2]['items'][] = ['label' => 'Impor Data', 'url' => base_url('import'), 'path' => '/import', 'icon' => 'upload'];
}

if ($isAdmin) {
    $groups[] = [
        'label' => 'Administrasi',
        'items' => [
            ['label' => 'Pengguna', 'url' => base_url('pengguna'), 'path' => '/pengguna', 'icon' => 'users'],
            ['label' => 'Audit Log', 'url' => base_url('audit-log'), 'path' => '/audit-log', 'icon' => 'audit'],
            ['label' => 'Backup & Restore', 'url' => base_url('pengaturan/backup'), 'path' => '/pengaturan/backup', 'icon' => 'database'],
            ['label' => 'Pengaturan', 'url' => base_url('pengaturan'), 'path' => '/pengaturan', 'icon' => 'settings'],
            ['label' => 'Pengaturan AI', 'url' => base_url('pengaturan/chatbot'), 'path' => '/pengaturan/chatbot', 'icon' => 'bot'],
            [
                'label' => 'WhatsApp Gateway',
                'url' => base_url('pengaturan/whatsapp'),
                'path' => '/pengaturan/whatsapp',
                'icon' => 'whatsapp',
                'children' => [
                    ['label' => 'Gateway', 'url' => base_url('pengaturan/whatsapp'), 'path' => '/pengaturan/whatsapp'],
                    ['label' => 'Notifikasi WhatsApp', 'url' => base_url('pengaturan/whatsapp/notifikasi'), 'path' => '/pengaturan/whatsapp/notifikasi'],
                    ['label' => 'Laporan WhatsApp', 'url' => base_url('pengaturan/whatsapp/riwayat'), 'path' => '/pengaturan/whatsapp/riwayat'],
                ],
            ],
            ['label' => 'Monitoring Penggunaan API', 'url' => base_url('monitoring/api'), 'path' => '/monitoring/api', 'icon' => 'activity'],
            ['label' => 'Ganti Password', 'url' => base_url('profil/password'), 'path' => '/profil/password', 'icon' => 'key'],
        ],
    ];
}

$upcoming = [];

/**
 * Render satu tautan menu. $child = true untuk submenu.
 */
$renderLink = static function (array $item, bool $child = false, string $scope = 'desktop') use ($isActive, $currentPath, $waOpen): void {
    $hasChildren = isset($item['children']) && is_array($item['children']) && $item['children'] !== [];
    $active = $isActive($item['path']);
    $cls = 'nav-link' . ($active ? ' active' : '') . ($child ? ' nav-link-sub' : '');
    if (!$hasChildren) {
        echo '<li class="nav-item">';
        echo '<a class="' . $cls . '" href="' . e($item['url']) . '" title="' . e($item['label']) . '">';
        echo '<svg class="nav-ico" width="18" height="18" aria-hidden="true"><use href="#sp-ico-' . e($item['icon'] ?? 'dot') . '"></use></svg>';
        echo '<span class="nav-text">' . e($item['label']) . '</span>';
        echo '</a></li>';
        return;
    }

    // Menu dengan submenu (WhatsApp Gateway). ID unik per instance (desktop/mobile).
    $open = $waOpen;
    $subId = $scope === 'mobile' ? 'waGatewayMenuMobile' : 'waGatewayMenu';
    echo '<li class="nav-item">';
    echo '<a class="nav-link' . ($open ? ' active' : '') . ' collapsed" data-bs-toggle="collapse" href="#' . $subId . '" role="button"'
        . ' aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $subId . '" title="' . e($item['label']) . '">';
    echo '<svg class="nav-ico" width="18" height="18" aria-hidden="true"><use href="#sp-ico-' . e($item['icon'] ?? 'dot') . '"></use></svg>';
    echo '<span class="nav-text">' . e($item['label']) . '</span>';
    echo '<svg class="nav-caret" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>';
    echo '</a>';
    echo '<div class="collapse ' . ($open ? 'show' : '') . '" id="' . $subId . '">';
    echo '<ul class="nav flex-column nav-sub">';
    foreach ($item['children'] as $childItem) {
        $childActive = $childItem['path'] === '/pengaturan/whatsapp'
            ? $currentPath === '/pengaturan/whatsapp'
            : $isActive($childItem['path']);
        echo '<li class="nav-item"><a class="nav-link' . ($childActive ? ' active' : '') . '" href="' . e($childItem['url']) . '">';
        echo '<span class="nav-text">' . e($childItem['label']) . '</span></a></li>';
    }
    echo '</ul></div></li>';
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($appName ?? 'SmartPartMan') ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script>
    // Terapkan state sidebar lebih awal agar tidak berkedip saat halaman dimuat.
    try {
        if (localStorage.getItem('smartpartman_sidebar_collapsed') === '1') {
            document.documentElement.classList.add('sidebar-collapsed-early');
        }
    } catch (e) {}
    </script>
</head>
<body class="bg-light" data-user-id="<?= (int) ($user['id'] ?? 0) ?>">

<!-- ===== Sprite ikon SVG (konsisten, tanpa emoji) ===== -->
<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <symbol id="sp-ico-dashboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
        <symbol id="sp-ico-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></symbol>
        <symbol id="sp-ico-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></symbol>
        <symbol id="sp-ico-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h13v13H1z"/><path d="M14 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="17.5" cy="18.5" r="2"/></symbol>
        <symbol id="sp-ico-location" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></symbol>
        <symbol id="sp-ico-in" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="m8 12 4 4 4-4"/></symbol>
        <symbol id="sp-ico-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16V8"/><path d="m8 12 4-4 4 4"/></symbol>
        <symbol id="sp-ico-clipboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></symbol>
        <symbol id="sp-ico-history" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="sp-ico-adjust" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10"/><path d="M18 6h2"/><circle cx="16" cy="6" r="2"/><path d="M4 12h4"/><path d="M12 12h8"/><circle cx="10" cy="12" r="2"/><path d="M4 18h10"/><path d="M18 18h2"/><circle cx="16" cy="18" r="2"/></symbol>
        <symbol id="sp-ico-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15v3"/><path d="M12 9v9"/><path d="M17 5v13"/></symbol>
        <symbol id="sp-ico-reorder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7"/><path d="M18 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7"/><path d="M6 21v-5h5"/></symbol>
        <symbol id="sp-ico-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></symbol>
        <symbol id="sp-ico-trending" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/></symbol>
        <symbol id="sp-ico-message" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></symbol>
        <symbol id="sp-ico-undo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></symbol>
        <symbol id="sp-ico-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></symbol>
        <symbol id="sp-ico-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="sp-ico-audit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="sp-ico-database" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></symbol>
        <symbol id="sp-ico-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></symbol>
        <symbol id="sp-ico-bot" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><path d="M8 13h.01"/><path d="M16 13h.01"/><path d="M9 17h6"/></symbol>
        <symbol id="sp-ico-whatsapp" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></symbol>
        <symbol id="sp-ico-activity" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></symbol>
        <symbol id="sp-ico-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.5 12.5 9-9"/><path d="m16 7 3 3"/><path d="m13 10 2 2"/></symbol>
        <symbol id="sp-ico-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></symbol>
        <symbol id="sp-ico-panel" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></symbol>
        <symbol id="sp-ico-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></symbol>
        <symbol id="sp-ico-dot" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/></symbol>
    </defs>
</svg>

<!-- ===== Sidebar (desktop) ===== -->
<nav class="sidebar d-print-none" id="sidebarMenu" aria-label="Navigasi utama">
    <a href="<?= e(base_url('dashboard')) ?>" class="sidebar-brand">
        <span class="brand-mark brand-mark-sm">SP</span>
        <span class="brand-text">
            <span class="brand-name"><?= e($appName ?? 'SmartPartMan') ?></span>
            <span class="brand-sub">Inventory System</span>
        </span>
    </a>
    <div class="sidebar-body">
        <?php foreach ($groups as $group): ?>
            <?php if ($group['items'] === []) { continue; } ?>
            <div class="nav-group-label"><?= e($group['label']) ?></div>
            <ul class="nav flex-column">
                <?php foreach ($group['items'] as $item) { $renderLink($item); } ?>
                <?php if ($group['label'] === 'Asisten'): ?>
                    <?php foreach ($upcoming as [$label, $phase]): ?>
                        <li class="nav-item disabled-placeholder">
                            <span class="nav-link disabled"><?= e($label) ?>
                                <span class="badge text-bg-secondary ms-1"><?= e($phase) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">v1.0 &middot; Fase 9</div>
</nav>

<!-- ===== Konten utama ===== -->
<div class="main-wrap">
    <header class="topbar d-print-none">
        <button class="icon-btn d-none d-lg-inline-flex" type="button" id="sidebarToggleDesktop"
                aria-label="Ciutkan atau bentangkan sidebar" aria-controls="sidebarMenu" aria-expanded="true">
            <svg aria-hidden="true"><use href="#sp-ico-panel"></use></svg>
        </button>
        <button class="icon-btn d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-label="Buka menu">
            <svg aria-hidden="true"><use href="#sp-ico-menu"></use></svg>
        </button>

        <div class="page-heading flex-grow-1">
            <span class="page-title"><?= e($pageTitle) ?></span>
            <span class="page-sub d-none d-sm-block"><?= e($user['role_name'] ?? '') ?> &middot; SmartPartMan</span>
        </div>

        <div class="topbar-user">
            <span class="avatar" aria-hidden="true"><?= e(strtoupper(substr((string) ($user['full_name'] ?? $user['username'] ?? 'U'), 0, 1))) ?></span>
            <span class="user-meta d-none d-md-flex">
                <span class="user-name"><?= e($user['full_name'] ?? $user['username'] ?? '') ?></span>
                <span class="user-role"><?= e($user['role_name'] ?? '') ?></span>
            </span>
        </div>
        <span class="topbar-divider d-none d-md-block"></span>
        <form action="<?= e(base_url('logout')) ?>" method="post" class="m-0"
              data-confirm="Keluar dari aplikasi?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Logout">
                <svg width="16" height="16" aria-hidden="true"><use href="#sp-ico-logout"></use></svg>
                <span class="d-none d-sm-inline">Logout</span>
            </button>
        </form>
    </header>

    <main class="app-main">
        <?php foreach ($flash as $type => $message): ?>
            <div class="alert alert-<?= e(in_array($type, ['success', 'danger', 'warning'], true) ? $type : 'info') ?> alert-dismissible fade show" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

    <footer class="text-center text-muted small py-3 border-top d-print-none">
        &copy; <?= date('Y') ?> <?= e($appName ?? 'SmartPartMan') ?> &middot; Bahasa: Indonesia &middot; Mata uang: Rupiah
    </footer>
</div>

<!-- Offcanvas mobile menu -->
<div class="offcanvas offcanvas-start offcanvas-sidebar-wrap text-white d-lg-none d-print-none" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
    <div class="offcanvas-header">
        <span class="brand-mark brand-mark-sm">SP</span>
        <span class="brand-text flex-grow-1">
            <span class="brand-name" id="mobileMenuLabel"><?= e($appName ?? 'SmartPartMan') ?></span>
            <span class="brand-sub">Inventory System</span>
        </span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body p-0">
        <nav class="sidebar" aria-label="Navigasi seluler">
            <div class="sidebar-body">
                <?php foreach ($groups as $group): ?>
                    <?php if ($group['items'] === []) { continue; } ?>
                    <div class="nav-group-label"><?= e($group['label']) ?></div>
                    <ul class="nav flex-column">
                <?php foreach ($group['items'] as $item) { $renderLink($item, false, 'mobile'); } ?>
                    </ul>
                <?php endforeach; ?>
            </div>
            <div class="sidebar-footer">v1.0 &middot; Fase 9</div>
        </nav>
    </div>
</div>

<?php
// Floating AI Assistant: hanya untuk pengguna yang sudah login (bukan login/installer).
// Dirender sekali per halaman; konfigurasi dibaca dari DB lewat partial.
if ($user !== null): ?>
    <?= View::partial('chatbot/widget', [
        'history' => Session::get('chatbot_history', []),
        'user'    => $user,
    ]) ?>
<?php endif; ?>

<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($user !== null): ?>
<script src="<?= e(asset('js/chatbot-widget.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
