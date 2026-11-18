-- ============================================================
-- SmartPartMan — Migrasi 009: Tanggal pergerakan stok
-- Pranala PRD: FR-05 ("Wajib menyimpan tanggal pencatatan, pengguna,
--              item, jumlah, dan catatan") + FR-06 (tanggal diterima).
--
-- Alasan: `created_at` adalah waktu SISTEM menyimpan baris, sedangkan
-- tanggal pencatatan/penerimaan adalah tanggal BISNIS yang diisi
-- pengguna (mis. stok awal dihitung kemarin, penerimaan barang tanggal
-- invoice). Keduanya dipisah agar laporan periode (Fase 5) akurat.
-- ============================================================

ALTER TABLE stock_movements
    ADD COLUMN IF NOT EXISTS movement_date DATE NULL AFTER quantity;

-- Baris lama (sebelum kolom ini ada): samakan dengan tanggal sistem.
UPDATE stock_movements
   SET movement_date = DATE(created_at)
 WHERE movement_date IS NULL;

CREATE INDEX IF NOT EXISTS idx_movements_date ON stock_movements (movement_date);
