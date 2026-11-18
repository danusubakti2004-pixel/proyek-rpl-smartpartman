-- ============================================================
-- SmartPartMan — Migrasi 019: Konfigurasi multi-provider AI Chatbot
-- Pranala: Fase 8 (Pengaturan AI Chatbot)
--
-- Perubahan:
--   * chatbot_settings.provider = provider AI aktif (gemini/deepseek/openai).
--   * chatbot_settings.api_base = Base URL khusus Gemini (opsional).
--   * tabel chatbot_providers menyimpan API key terenkripsi, model, dan
--     Base URL untuk provider selain Gemini.
--
-- Catatan:
--   * Kunci Gemini tetap memakai kolom lama api_key_encrypted agar data yang
--     sudah tersimpan tidak hilang dan tetap kompatibel.
--   * Kunci provider lain TIDAK pernah menimpa kunci Gemini.
--   * Tidak ada API key plaintext di migrasi ini.
-- ============================================================

ALTER TABLE chatbot_settings
    ADD COLUMN provider VARCHAR(30) NOT NULL DEFAULT 'gemini' AFTER is_enabled,
    ADD COLUMN api_base VARCHAR(255) NULL AFTER model;

CREATE TABLE IF NOT EXISTS chatbot_providers (
    provider           VARCHAR(30)  NOT NULL,
    api_key_encrypted  TEXT         NULL,
    api_key_masked     VARCHAR(64)  NULL,
    model              VARCHAR(120) NOT NULL DEFAULT '',
    api_base           VARCHAR(255) NULL,
    updated_by         INT UNSIGNED NULL,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider),
    CONSTRAINT fk_chatbot_providers_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
