ALTER TABLE users
    ADD COLUMN whatsapp_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number,
    ADD COLUMN whatsapp_pending_number VARCHAR(30) NULL AFTER whatsapp_verified,
    ADD COLUMN whatsapp_otp_hash VARCHAR(255) NULL AFTER whatsapp_pending_number,
    ADD COLUMN whatsapp_otp_expires_at DATETIME NULL AFTER whatsapp_otp_hash,
    ADD COLUMN whatsapp_otp_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER whatsapp_otp_expires_at,
    ADD COLUMN whatsapp_otp_last_sent_at DATETIME NULL AFTER whatsapp_otp_attempts;

UPDATE users
SET whatsapp_verified = 1
WHERE whatsapp_number IS NOT NULL AND whatsapp_number <> '';