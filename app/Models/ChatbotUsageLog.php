<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Log penggunaan chatbot (Fase 8).
 *
 * TIDAK menyimpan pertanyaan/jawaban penuh, API key, atau rahasia. Hanya
 * metadata, potongan pertanyaan (maks. 200 karakter), status, token, dan
 * pesan kesalahan generik yang sudah dibersihkan service.
 */
final class ChatbotUsageLog extends Model
{
    /** Status yang dihitung sebagai pemakaian kuota API. */
    public const API_ATTEMPT_STATUSES = ['success', 'failed'];

    /** @param array<string,mixed> $data */
    public static function record(array $data): int
    {
        self::execute(
            'INSERT INTO chatbot_usage_logs
                (user_id, role, model, question_preview, prompt_tokens, response_tokens,
                 status, error_message, duration_ms, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $data['user_id'] ?? null,
                $data['role'] ?? null,
                $data['model'] ?? null,
                $data['question_preview'] ?? null,
                $data['prompt_tokens'] ?? null,
                $data['response_tokens'] ?? null,
                $data['status'] ?? 'success',
                $data['error_message'] ?? null,
                $data['duration_ms'] ?? null,
            ]
        );
        return self::lastId();
    }

    /** Jumlah panggilan API (sukses + gagal) sejak waktu tertentu. */
    public static function countApiCallsSince(string $since): int
    {
        return (int) self::scalar(
            "SELECT COUNT(*) FROM chatbot_usage_logs
              WHERE created_at >= ? AND status IN ('success', 'failed')",
            [$since]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return self::fetchAll(
            'SELECT l.id, l.user_id, l.role, l.model, l.question_preview,
                    l.prompt_tokens, l.response_tokens, l.status, l.error_message,
                    l.duration_ms, l.created_at, u.username, u.full_name
               FROM chatbot_usage_logs l
          LEFT JOIN users u ON u.id = l.user_id
           ORDER BY l.id DESC
              LIMIT ' . $limit
        );
    }

    /**
     * Ringkasan untuk halaman Admin. Tidak memuat pertanyaan/jawaban penuh.
     *
     * @return array<string,mixed>
     */
    public static function summary(): array
    {
        $today = self::fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'success'), 0) AS success,
                    COALESCE(SUM(status = 'failed'), 0) AS failed,
                    COALESCE(SUM(status IN ('off_topic','blocked','role_scope','limit')), 0) AS blocked,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(response_tokens), 0) AS response_tokens
               FROM chatbot_usage_logs
              WHERE created_at >= CURDATE()"
        );
        $month = self::fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(response_tokens), 0) AS response_tokens
               FROM chatbot_usage_logs
              WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $lastError = self::fetchOne(
            "SELECT error_message, created_at
               FROM chatbot_usage_logs
              WHERE status = 'failed' AND error_message IS NOT NULL
           ORDER BY id DESC LIMIT 1"
        );

        return [
            'today' => [
                'total'          => (int) ($today['total'] ?? 0),
                'success'        => (int) ($today['success'] ?? 0),
                'failed'         => (int) ($today['failed'] ?? 0),
                'blocked'        => (int) ($today['blocked'] ?? 0),
                'prompt_tokens'  => (int) ($today['prompt_tokens'] ?? 0),
                'response_tokens'=> (int) ($today['response_tokens'] ?? 0),
            ],
            'month' => [
                'total'          => (int) ($month['total'] ?? 0),
                'prompt_tokens'  => (int) ($month['prompt_tokens'] ?? 0),
                'response_tokens'=> (int) ($month['response_tokens'] ?? 0),
            ],
            'last_error' => $lastError === null ? null : [
                'message'    => (string) ($lastError['error_message'] ?? ''),
                'created_at' => (string) ($lastError['created_at'] ?? ''),
            ],
        ];
    }

    public static function countApiCallsToday(): int
    {
        return self::countApiCallsSince(date('Y-m-d 00:00:00'));
    }

    public static function countApiCallsThisMonth(): int
    {
        return self::countApiCallsSince(date('Y-m-01 00:00:00'));
    }
}
