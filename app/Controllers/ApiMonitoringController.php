<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Services\ChatbotSettingsService;
use App\Services\ChatbotService;
use App\Services\WhatsAppNotificationService;
use App\Models\ChatbotUsageLog;

final class ApiMonitoringController extends Controller
{
    public function index(array $params = []): void
    {
        $waSettings = WhatsAppNotificationService::settings();
        $waHistory = WhatsAppNotificationService::history(100);
        $waTotal = count($waHistory);
        $waSuccess = 0;
        $waFailed = 0;
        foreach ($waHistory as $row) {
            if ((string) ($row['status'] ?? '') === 'BERHASIL') {
                $waSuccess++;
            } elseif ((string) ($row['status'] ?? '') === 'GAGAL') {
                $waFailed++;
            }
        }
        $waRate = $waTotal > 0 ? round(($waSuccess / $waTotal) * 100, 1) : 0;

        $chatSettings = ChatbotSettingsService::current();
        $chatSummary = ChatbotUsageLog::summary();
        $chatRecent = ChatbotUsageLog::recent(20);

        $this->view('monitoring/api', [
            'title' => 'Monitoring Penggunaan API',
            'waSettings' => $waSettings,
            'waHistory' => $waHistory,
            'waTotal' => $waTotal,
            'waSuccess' => $waSuccess,
            'waFailed' => $waFailed,
            'waRate' => $waRate,
            'chatSettings' => $chatSettings,
            'chatSummary' => $chatSummary,
            'chatRecent' => $chatRecent,
        ]);
    }
}