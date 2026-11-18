-- ============================================================
-- SmartPartMan — Migrasi 008: Stok awal hanya sekali per sparepart
-- Pranala PRD: FR-05 ("Stok awal dicatat sebagai jenis transaksi/
--              pergerakan STOK_AWAL") dan permintaan Fase 3
--              ("pastikan stok awal tidak bisa diterapkan dua kali
--               untuk item yang sama tanpa prosedur koreksi eksplisit").
--
-- Level database (pertahanan lapis-2):
--  * INSERT pergerakan bertipe STOK_AWAL untuk sparepart yang SUDAH
--    punya STOK_AWAL DITOLAK.
--  * Koreksi tetap mungkin lewat jenis lain (PENYESUAIAN_OPNAME) sesuai
--    FR-10, sehingga tidak ada koreksi "diam-diam".
--
-- Catatan: aturan yang sama juga diperiksa di StockInitialService agar
-- pengguna mendapat pesan yang ramah; trigger ini menjaga integritas
-- walau ada kode lain/bug.
-- ============================================================

DROP TRIGGER IF EXISTS trg_stock_movements_single_initial;

CREATE TRIGGER trg_stock_movements_single_initial BEFORE INSERT ON stock_movements
FOR EACH ROW
BEGIN
    DECLARE v_sudah INT DEFAULT 0;

    IF NEW.movement_type = 'STOK_AWAL' THEN
        SELECT COUNT(*) INTO v_sudah
          FROM stock_movements
         WHERE sparepart_id = NEW.sparepart_id
           AND movement_type = 'STOK_AWAL';

        IF v_sudah > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Stok awal hanya boleh dicatat satu kali per sparepart. Gunakan penyesuaian opname untuk koreksi.';
        END IF;
    END IF;
END;
