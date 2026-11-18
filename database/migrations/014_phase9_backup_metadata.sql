-- ============================================================
-- SmartPartMan — Migrasi 014: Metadata backup & restore (Fase 9)
-- Pranala: PRD A12 (backups), Fase 9 (backup/restore aman)
--
-- Menambah metadata yang diperlukan untuk memverifikasi berkas backup
-- sebelum dipulihkan:
--   - checksum SHA-256 (deteksi berkas berubah/rusak)
--   - nama database & jumlah migrasi (cegah restore lintas versi)
--   - jenis backup (manual / otomatis sebelum restore)
--   - jejak restore (waktu & pelaku)
--
-- Catatan: data lama tetap dipertahankan. Backup lama tanpa checksum
-- TIDAK dapat dipulihkan lewat aplikasi sampai dibuat ulang.
-- ============================================================

ALTER TABLE backups
    ADD COLUMN checksum_sha256 CHAR(64) NULL AFTER filesize,
    ADD COLUMN database_name VARCHAR(64) NULL AFTER checksum_sha256,
    ADD COLUMN migration_count INT UNSIGNED NULL AFTER database_name,
    ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER status,
    ADD COLUMN restored_at DATETIME NULL AFTER note,
    ADD COLUMN restored_by INT UNSIGNED NULL AFTER restored_at,
    ADD KEY idx_backups_status (status),
    ADD KEY idx_backups_kind (kind),
    ADD CONSTRAINT fk_backups_restored_by
        FOREIGN KEY (restored_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL;
