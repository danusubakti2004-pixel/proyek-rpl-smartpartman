-- ============================================================
-- SmartPartMan — Migrasi 005: Chatbot Gemini
-- Pranala PRD: §12 (chatbot_settings, chatbot_usage_logs) + FR-14
-- Aturan:
--   * API key disimpan TERENKRIPSI (kunci enkripsi dari APP_KEY di .env)
--     dan hanya ditampilkan tersamarkan (api_key_masked).
--   * Log penggunaan TIDAK menyimpan rahasia; hanya metadata & potongan
--     pertanyaan yang sudah dipotong (max 200 karakter).
-- ============================================================

CREATE TABLE IF NOT EXISTS chatbot_settings (
    id                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    api_key_encrypted  TEXT         NULL,
    api_key_masked     VARCHAR(64)  NULL,     -- mis. ****abcd1234
    model              VARCHAR(60)  NOT NULL DEFAULT 'gemini-1.5-flash',
    system_prompt      TEXT         NULL,
    topic_limits       TEXT         NULL,
    answer_style       VARCHAR(30)  NOT NULL DEFAULT 'ringkas',  -- ringkas | normal | detail
    usage_limit_daily  INT UNSIGNED NULL,
    usage_limit_monthly INT UNSIGNED NULL,
    updated_by         INT UNSIGNED NULL,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_chatbot_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_usage_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL,
    role            VARCHAR(30)  NULL,
    model           VARCHAR(60)  NULL,
    question_preview VARCHAR(200) NULL,      -- potongan pertanyaan (bukan isi penuh)
    prompt_tokens   INT UNSIGNED NULL,
    response_tokens INT UNSIGNED NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'success',  -- success | failed | disabled | limit
    error_message   VARCHAR(255) NULL,      -- pesan kegagalan generik (tanpa rahasia)
    duration_ms     INT UNSIGNED NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chatbot_usage_user (user_id),
    KEY idx_chatbot_usage_time (created_at),
    KEY idx_chatbot_usage_status (status),
    CONSTRAINT fk_chatbot_usage_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
