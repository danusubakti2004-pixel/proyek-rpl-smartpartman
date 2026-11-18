-- ============================================================
-- SmartPartMan — Migrasi 007: Audit Log bersifat append-only
-- Pranala PRD: FR-20 ("Tidak tersedia fungsi edit/hapus log melalui
--              aplikasi"; "Log harus dilindungi dari perubahan").
--
-- Level database:
--  * UPDATE isi audit_logs DITOLAK.
--    Pengecualian: aksi foreign key ON DELETE SET NULL saat pengguna
--    dihapus — hanya kolom user_id yang berubah menjadi NULL.
--  * DELETE audit_logs DITOLAK (riwayat tidak boleh hilang).
--
-- Catatan: TRUNCATE tidak memicu trigger, sehingga pemulihan/restore
-- (Fase 9) tetap mungkin. Untuk pemeliharaan darurat, DBA dapat
-- menghapus trigger sementara.
-- ============================================================

DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;

CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    IF NOT (
        NEW.id          <=> OLD.id
        AND NEW.user_id IS NULL
        AND OLD.user_id IS NOT NULL
        AND NEW.username    <=> OLD.username
        AND NEW.role        <=> OLD.role
        AND NEW.activity    <=> OLD.activity
        AND NEW.entity_type <=> OLD.entity_type
        AND NEW.entity_id   <=> OLD.entity_id
        AND NEW.description <=> OLD.description
        AND NEW.old_values  <=> OLD.old_values
        AND NEW.new_values  <=> OLD.new_values
        AND NEW.ip_address  <=> OLD.ip_address
        AND NEW.created_at  <=> OLD.created_at
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Audit Log bersifat append-only: UPDATE ditolak.';
    END IF;
END;

CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit Log bersifat append-only: DELETE ditolak.';
