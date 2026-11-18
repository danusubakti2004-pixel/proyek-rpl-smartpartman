-- ============================================================
-- SmartPartMan — Migrasi 010: Penunjang transaksi & inventory (Fase 4)
-- Pranala PRD: FR-06 (barang masuk), FR-07 (barang keluar),
--              FR-08 (status stok), FR-10 (stock opname),
--              §7.4 (alur koreksi transaksi), §9 aturan 1-8, 10-12,
--              §10.2 (atomik + cegah race condition), §13.1-13.3.
--
-- Isi migrasi:
--   1. document_sequences  → penomoran dokumen otomatis & unik yang aman
--      dari dua pengguna bersamaan (row lock pada satu baris counter).
--   2. stock_movements.reversal_of_id → referensi transaksi asal untuk
--      KOREKSI (transaksi pembalik). Ledger tetap append-only.
--   3. goods_receipts.receipt_type → hanya penerimaan bertipe "pembelian"
--      yang memperbarui tanggal pembelian terakhir (FR-06).
--   4. goods_receipts.attachment_name → nama berkas asli bukti (foto/PDF).
--   5. stock_opnames.opname_date → tanggal bisnis perhitungan fisik.
--   6. stock_opname_items.counted_by/counted_at → jejak siapa & kapan
--      memasukkan jumlah fisik (FR-10 butir 1-6).
--
-- Idempoten: CREATE TABLE IF NOT EXISTS + ALTER ... IF NOT EXISTS dan
-- penambahan constraint dijaga lewat informasi_schema (aman diulang).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Penomoran dokumen (GR-/GI-/SO-) per periode, aman konkuren
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_sequences (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prefix      VARCHAR(10)  NOT NULL,   -- GR | GI | SO
    period      CHAR(8)      NOT NULL,   -- YYYYMM (periode penomoran)
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_sequences (prefix, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Ledger: referensi transaksi asal (untuk KOREKSI)
-- ------------------------------------------------------------
ALTER TABLE stock_movements
    ADD COLUMN IF NOT EXISTS reversal_of_id BIGINT UNSIGNED NULL AFTER reference_number;

CREATE INDEX IF NOT EXISTS idx_movements_reversal ON stock_movements (reversal_of_id);

-- Foreign key ke baris ledger asal (baris lama TIDAK pernah dihapus,
-- sehingga ON DELETE RESTRICT tidak pernah menghalangi operasi normal).
SET @ada_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'stock_movements'
       AND CONSTRAINT_NAME = 'fk_movements_reversal'
);
SET @sql_fk := IF(@ada_fk = 0,
    'ALTER TABLE stock_movements ADD CONSTRAINT fk_movements_reversal FOREIGN KEY (reversal_of_id) REFERENCES stock_movements (id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- ------------------------------------------------------------
-- 3. Penerimaan: jenis penerimaan (pembelian | lainnya)
-- ------------------------------------------------------------
ALTER TABLE goods_receipts
    ADD COLUMN IF NOT EXISTS receipt_type VARCHAR(20) NOT NULL DEFAULT 'pembelian' AFTER receipt_date;

SET @ada_chk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'goods_receipts'
       AND CONSTRAINT_NAME = 'chk_receipts_type'
);
SET @sql_chk := IF(@ada_chk = 0,
    "ALTER TABLE goods_receipts ADD CONSTRAINT chk_receipts_type CHECK (receipt_type IN ('pembelian','lainnya'))",
    'SELECT 1'
);
PREPARE stmt_chk FROM @sql_chk;
EXECUTE stmt_chk;
DEALLOCATE PREPARE stmt_chk;

-- ------------------------------------------------------------
-- 4. Bukti penerimaan: simpan nama berkas asli (untuk ditampilkan)
-- ------------------------------------------------------------
ALTER TABLE goods_receipts
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) NULL AFTER attachment_path;

-- ------------------------------------------------------------
-- 5. Stock opname: tanggal bisnis perhitungan fisik
-- ------------------------------------------------------------
ALTER TABLE stock_opnames
    ADD COLUMN IF NOT EXISTS opname_date DATE NULL AFTER opname_number;

-- Baris lama: samakan dengan tanggal mulai sesi.
UPDATE stock_opnames SET opname_date = DATE(started_at) WHERE opname_date IS NULL;

-- ------------------------------------------------------------
-- 6. Item opname: jejak pengguna & waktu input jumlah fisik
-- ------------------------------------------------------------
ALTER TABLE stock_opname_items
    ADD COLUMN IF NOT EXISTS counted_by INT UNSIGNED NULL AFTER reason;

ALTER TABLE stock_opname_items
    ADD COLUMN IF NOT EXISTS counted_at DATETIME NULL AFTER counted_by;

SET @ada_fk2 := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'stock_opname_items'
       AND CONSTRAINT_NAME = 'fk_opname_items_counted_by'
);
SET @sql_fk2 := IF(@ada_fk2 = 0,
    'ALTER TABLE stock_opname_items ADD CONSTRAINT fk_opname_items_counted_by FOREIGN KEY (counted_by) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_fk2 FROM @sql_fk2;
EXECUTE stmt_fk2;
DEALLOCATE PREPARE stmt_fk2;

-- ------------------------------------------------------------
-- 7. Indeks pencarian transaksi (PRD §10.2)
-- ------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_receipts_invoice ON goods_receipts (invoice_number);
CREATE INDEX IF NOT EXISTS idx_receipts_type ON goods_receipts (receipt_type);
CREATE INDEX IF NOT EXISTS idx_issues_created ON goods_issues (created_at);
CREATE INDEX IF NOT EXISTS idx_opnames_date ON stock_opnames (opname_date);
