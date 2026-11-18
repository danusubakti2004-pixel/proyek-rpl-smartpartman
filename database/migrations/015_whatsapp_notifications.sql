-- ============================================================
-- SmartPartMan — Migrasi 015: Notifikasi WhatsApp (Fonnte)
-- ============================================================

ALTER TABLE users
    ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email,
    ADD KEY idx_users_whatsapp (whatsapp_number);

CREATE TABLE IF NOT EXISTS whatsapp_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    api_token_encrypted TEXT NULL,
    api_token_masked VARCHAR(64) NULL,
    sender_number VARCHAR(30) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Makassar',
    report_time TIME NULL DEFAULT '08:00:00',
    daily_report_enabled TINYINT(1) NOT NULL DEFAULT 0,
    monthly_report_enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json TEXT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_whatsapp_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO whatsapp_settings (id) VALUES (1)
    ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS whatsapp_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(50) NOT NULL,
    recipient_number VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'MENUNGGU',
    provider_response TEXT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    idempotency_key VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_wa_type (notification_type),
    KEY idx_wa_status (status),
    KEY idx_wa_created (created_at),
    UNIQUE KEY uq_wa_idempotency (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;