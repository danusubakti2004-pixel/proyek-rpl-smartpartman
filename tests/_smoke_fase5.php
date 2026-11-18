<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\View;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Services\SettingService;

$fail = 0;
foreach (ReportService::TYPES as $type) {
    try {
        $r = ReportService::build($type);
        echo "REPORT {$type}: rows=" . count($r['rows']) . " summary=" . count($r['summary'])
            . " chart=" . (is_array($r['chart']) ? count($r['chart']['labels'] ?? []) : 0) . "\n";
        $options = ReportService::filterOptions();
        $html = View::render('reports/show', [
            'title' => $r['meta']['title'],
            'report' => $r,
            'categories' => $options['categories'],
            'suppliers' => $options['suppliers'],
            'identity' => SettingService::identity(),
            'query' => '',
        ], 'layouts/app');
        echo "  view show: " . strlen($html) . " bytes\n";
        $html = View::render('reports/print', [
            'title' => $r['meta']['title'],
            'report' => $r,
            'identity' => SettingService::identity(),
            'orientation' => $r['meta']['orientation'],
            'backUrl' => '#',
            'pdfUrl' => '#',
        ], 'layouts/print');
        echo "  view print: " . strlen($html) . " bytes\n";
    } catch (Throwable $e) {
        $fail++;
        echo "REPORT {$type} FAILED: " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

foreach (['partman', 'kepala_bengkel', 'admin'] as $role) {
    try {
        $d = DashboardService::forRole($role);
        echo "DASH {$role}: keys=" . implode(',', array_keys($d)) . "\n";
        $html = View::render('dashboard/index', array_merge($d, ['title' => 'Dashboard', 'role' => $role]), 'layouts/app');
        echo "  view: " . strlen($html) . " bytes\n";
    } catch (Throwable $e) {
        $fail++;
        echo "DASH {$role} FAILED: " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

try {
    $html = View::render('settings/index', [
        'title' => 'Pengaturan',
        'values' => array_merge(SettingService::DEFAULTS, SettingService::all()),
        'old' => [],
        'isAdmin' => true,
    ], 'layouts/app');
    echo "SETTINGS view: " . strlen($html) . " bytes\n";
} catch (Throwable $e) {
    $fail++;
    echo "SETTINGS FAILED: " . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail > 0 ? 1 : 0);
