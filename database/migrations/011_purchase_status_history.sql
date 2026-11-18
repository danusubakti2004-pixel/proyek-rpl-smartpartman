-- ============================================================
-- SmartPartMan — Migrasi 011: Riwayat status reorder & PO (Fase 6)
-- Pranala PRD: FR-15 (reorder), FR-16 (PO), §7.5 (alur persetujuan),
--              Aturan Bisnis 13-17.
--
-- Isi: satu tabel riwayat generik untuk perubahan status dokumen
-- pembelian. Dipakai BERSAMA oleh reorder_requests dan
-- purchase_orders sehingga setiap perubahan status mencatat:
--   aktor, waktu, status SEBELUMNYA, status BERIKUTNYA, dan alasan.
--
-- Catatan desain:
--   * `document_type` + `document_id` bersifat polimorfik (tanpa FK)
--     karena satu tabel melayani dua jenis dokumen; validasi
--     keberadaan dokumen dilakukan di service (dalam transaksi).
--   * Tabel ini meniru sifat ledger: TIDAK ada update/hapus dari
--     aplikasi — hanya INSERT (append-only).
--   * Penerimaan parsial tersimpan di goods_receipts +
--     purchase_order_items.received_qty (sudah ada sejak migrasi 004).
-- ============================================================

CREATE TABLE IF NOT EXISTS purchase_status_history (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_type VARCHAR(20)  NOT NULL,        -- 'REORDER' | 'PO'
    document_id   INT UNSIGNED NOT NULL,
    old_status    VARCHAR(30)  NULL,            -- NULL = status awal (dokumen baru)
    new_status    VARCHAR(30)  NOT NULL,
    actor_id      INT UNSIGNED NULL,
    actor_name    VARCHAR(100) NULL,            -- salinan nama aktor saat kejadian
    reason        TEXT         NULL,            -- alasan (wajib saat penolakan/pembatalan)
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_history_doc (document_type, document_id),
    CONSTRAINT chk_status_history_type
        CHECK (document_type IN ('REORDER', 'PO')),
    CONSTRAINT fk_status_history_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
