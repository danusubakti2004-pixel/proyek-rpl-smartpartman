<?php
declare(strict_types=1);

require __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\WhatsAppNotificationService;

Env::load(__DIR__ . '/../.env');
$config = require __DIR__ . '/../config/config.php';
Database::configure($config['db']);

$waSettings = \App\Services\WhatsAppNotificationService::settings();
$timezone = $waSettings['timezone'] ?? ($config['app']['timezone'] ?? 'Asia/Jakarta');
date_default_timezone_set((string) $timezone);

$mode = $argv[1] ?? 'daily';

if ($mode === 'daily') {
    $now = new DateTime('now', new DateTimeZone((string) $timezone));
    $reportTime = (string) ($waSettings['report_time'] ?? '08:00:00');
    $scheduled = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' ' . $reportTime, new DateTimeZone((string) $timezone));
    if (!($waSettings['daily_report_enabled'] ?? false)) {
        echo "Laporan harian nonaktif.\n";
        exit(0);
    }
    if ($scheduled instanceof DateTime && $now < $scheduled) {
        echo "Belum waktunya laporan harian. Jadwal: " . $reportTime . " " . $timezone . ", sekarang: " . $now->format('H:i:s') . "\n";
        exit(0);
    }
    WhatsAppNotificationService::notifyLowStock();
    WhatsAppNotificationService::sendDailyReport(false);
    echo "Daily report processed at " . $now->format('Y-m-d H:i:s') . " " . $timezone . "\n";
} elseif ($mode === 'monthly') {
    WhatsAppNotificationService::sendMonthlyReport();
    echo "Monthly report processed.\n";
} elseif ($mode === 'lowstock') {
    WhatsAppNotificationService::notifyLowStock();
    echo "Low stock processed.\n";
} else {
    fwrite(STDERR, "Usage: php whatsapp_scheduler.php [daily|monthly|lowstock]\n");
    exit(1);
}