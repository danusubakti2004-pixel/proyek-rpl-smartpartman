-- ============================================================
-- SmartPartMan — Migrasi 006: Data awal (seeder)
-- Pranala PRD: §6 (role), §12 (app_settings), FR-14 (chatbot)
-- Idempoten: hanya mengisi jika belum ada.
-- ============================================================

INSERT INTO roles (code, name)
SELECT * FROM (
    SELECT 'partman' AS code, 'Partman' AS name
    UNION ALL SELECT 'kepala_bengkel', 'Kepala Bengkel'
    UNION ALL SELECT 'admin', 'Admin'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM roles);

INSERT INTO app_settings (setting_key, setting_value)
SELECT * FROM (
    SELECT 'workshop_name' AS setting_key, 'Nama Bengkel (ubah di Pengaturan)' AS setting_value
    UNION ALL SELECT 'workshop_address', ''
    UNION ALL SELECT 'workshop_phone', ''
    UNION ALL SELECT 'workshop_email', ''
    UNION ALL SELECT 'workshop_logo', ''
    UNION ALL SELECT 'currency', 'IDR'
    UNION ALL SELECT 'installed_at', NULL
) AS seed
WHERE setting_key NOT IN (SELECT setting_key FROM app_settings);

INSERT INTO chatbot_settings (id, is_enabled, model, system_prompt, topic_limits, answer_style)
SELECT * FROM (
    SELECT 1 AS id, 0 AS is_enabled, 'gemini-1.5-flash' AS model,
           'Anda adalah asisten SmartPartMan. Jawab HANYA dalam Bahasa Indonesia, singkat, dan berbasis data yang diberikan backend. Jangan mengarang data. Jika data tidak tersedia, katakan data tidak tersedia.' AS system_prompt,
           'Pertanyaan hanya seputar SmartPartMan, inventory sparepart, dan data sesuai hak akses pengguna.' AS topic_limits,
           'ringkas' AS answer_style
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM chatbot_settings);
