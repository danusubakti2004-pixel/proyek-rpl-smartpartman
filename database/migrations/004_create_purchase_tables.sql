-- ============================================================
-- SmartPartMan — Migrasi 004: AI prediction, reorder & pembelian
-- Pranala PRD: §12 (ai_predictions, reorder_requests,
--                    purchase_orders, purchase_order_items,
--                    purchase_payment_status)
--            + FR-13, FR-15..FR-17, Aturan Bisnis 11-17
-- Catatan desain:
--   * Tabel riwayat pembayaran diberi nama purchase_payment_history
--     (pengganti purchase_payment_status) karena isinya RIWAYAT
--     perubahan status — dicatat di docs/ASSUMPTIONS.md.
--   * Persetujuan PO TIDAK menambah stok; stok hanya dari
--     goods_receipts (FR-16 ayat 9, Aturan 13).
--   * received_qty per item PO = agregat penerimaan; diperbarui dalam
--     transaksi DB bersamaan dengan penulisan penerimaan.
-- ============================================================

CREATE TABLE IF NOT EXISTS ai_predictions (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sparepart_id         INT UNSIGNED NOT NULL,
    period_days          INT UNSIGNED NOT NULL,        -- 7 | 30 | 90
    history_range_start  DATE         NULL,
    history_range_end    DATE         NULL,
    method               VARCHAR(40)  NOT NULL,        -- moving_average | linear_regression | safety_stock | rop | gabungan
    predicted_demand     DECIMAL(12,2) NULL,
    forecast_days_to_min INT UNSIGNED NULL,            -- perkiraan hari ke stok minimum
    forecast_days_to_zero INT UNSIGNED NULL,           -- perkiraan hari ke stok habis
    stockout_risk        VARCHAR(20)  NULL,            -- rendah | sedang | tinggi
    recommended_qty      DECIMAL(12,2) NULL,
    moving_classification VARCHAR(20) NULL,            -- fast | slow | medium
    data_sufficiency     VARCHAR(20)  NOT NULL,        -- cukup | terbatas | tidak_cukup
    warnings             TEXT         NULL,
    model_version        VARCHAR(30)  NULL,
    details              LONGTEXT     NULL,            -- JSON: metrik per metode, parameter, evaluasi
    processed_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_predictions_sparepart_time (sparepart_id, processed_at),
    KEY idx_predictions_period (period_days),
    CONSTRAINT chk_predictions_period CHECK (period_days IN (7, 30, 90)),
    CONSTRAINT fk_predictions_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reorder_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_number VARCHAR(30)  NOT NULL,
    sparepart_id   INT UNSIGNED NOT NULL,
    suggested_qty  DECIMAL(12,2) NULL,       -- rekomendasi AI (boleh NULL bila manual)
    requested_qty  DECIMAL(12,2) NOT NULL,   -- jumlah yang diajukan Partman
    change_reason  TEXT         NULL,        -- WAJIB bila requested != suggested
    status         VARCHAR(30)  NOT NULL DEFAULT 'DRAFT',
        -- DRAFT | MENUNGGU_PERSETUJUAN | DISETUJUI | DITOLAK
    prediction_id  INT UNSIGNED NULL,
    supplier_id    INT UNSIGNED NULL,        -- rekomendasi supplier (Partman yang memilih final)
    created_by     INT UNSIGNED NOT NULL,
    reviewed_by    INT UNSIGNED NULL,
    reviewed_at    DATETIME     NULL,
    review_note    TEXT         NULL,        -- WAJIB saat menolak
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reorder_number (request_number),
    KEY idx_reorder_status (status),
    KEY idx_reorder_sparepart (sparepart_id),
    CONSTRAINT chk_reorder_status
        CHECK (status IN ('DRAFT', 'MENUNGGU_PERSETUJUAN', 'DISETUJUI', 'DITOLAK')),
    CONSTRAINT chk_reorder_qty CHECK (requested_qty > 0),
    CONSTRAINT fk_reorder_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reorder_prediction
        FOREIGN KEY (prediction_id) REFERENCES ai_predictions (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_reorder_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_reorder_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reorder_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_number           VARCHAR(30)  NOT NULL,
    supplier_id         INT UNSIGNED NOT NULL,
    reorder_request_id  INT UNSIGNED NULL,
    status              VARCHAR(30)  NOT NULL DEFAULT 'DRAFT',
        -- DRAFT | MENUNGGU_PERSETUJUAN | DISETUJUI | DITOLAK | DIPROSES_ADMIN
        -- | SEBAGIAN_DITERIMA | SELESAI_DITERIMA | DIBATALKAN
    payment_status      VARCHAR(20)  NOT NULL DEFAULT 'BELUM_DIBAYAR',
        -- BELUM_DIBAYAR | DIBAYAR_SEBAGIAN | LUNAS
    total_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
    note                TEXT         NULL,
    created_by          INT UNSIGNED NOT NULL,
    approved_by         INT UNSIGNED NULL,
    approved_at         DATETIME     NULL,
    rejected_reason     TEXT         NULL,   -- WAJIB saat ditolak
    processed_by        INT UNSIGNED NULL,   -- Admin yang memproses administrasi PO
    processed_at        DATETIME     NULL,
    cancelled_by        INT UNSIGNED NULL,
    cancelled_at        DATETIME     NULL,
    cancel_reason       TEXT         NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_number (po_number),
    KEY idx_po_status (status),
    KEY idx_po_payment (payment_status),
    KEY idx_po_supplier (supplier_id),
    KEY idx_po_created (created_at),
    CONSTRAINT chk_po_status CHECK (status IN (
        'DRAFT', 'MENUNGGU_PERSETUJUAN', 'DISETUJUI', 'DITOLAK', 'DIPROSES_ADMIN',
        'SEBAGIAN_DITERIMA', 'SELESAI_DITERIMA', 'DIBATALKAN'
    )),
    CONSTRAINT chk_po_payment CHECK (payment_status IN ('BELUM_DIBAYAR', 'DIBAYAR_SEBAGIAN', 'LUNAS')),
    CONSTRAINT fk_po_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_po_reorder
        FOREIGN KEY (reorder_request_id) REFERENCES reorder_requests (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_po_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_po_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_po_processed_by
        FOREIGN KEY (processed_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_po_cancelled_by
        FOREIGN KEY (cancelled_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_id        INT UNSIGNED NOT NULL,
    sparepart_id INT UNSIGNED NOT NULL,
    quantity     DECIMAL(12,2) NOT NULL,          -- kuantitas disetujui
    unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0,
    received_qty DECIMAL(12,2) NOT NULL DEFAULT 0, -- akumulasi penerimaan bertahap
    PRIMARY KEY (id),
    KEY idx_po_items_po (po_id),
    KEY idx_po_items_sparepart (sparepart_id),
    CONSTRAINT chk_po_items_qty CHECK (quantity > 0 AND received_qty >= 0),
    CONSTRAINT fk_po_items_po
        FOREIGN KEY (po_id) REFERENCES purchase_orders (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_po_items_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat perubahan status pembayaran (PRD §12: purchase_payment_status).
-- Admin satu-satunya yang mengubah (Aturan Bisnis 17).
CREATE TABLE IF NOT EXISTS purchase_payment_history (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_order_id INT UNSIGNED NOT NULL,
    status            VARCHAR(20) NOT NULL,     -- BELUM_DIBAYAR | DIBAYAR_SEBAGIAN | LUNAS
    note              TEXT        NULL,
    changed_by        INT UNSIGNED NOT NULL,
    changed_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_history_po (purchase_order_id),
    CONSTRAINT chk_payment_status CHECK (status IN ('BELUM_DIBAYAR', 'DIBAYAR_SEBAGIAN', 'LUNAS')),
    CONSTRAINT fk_payment_history_po
        FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payment_history_user
        FOREIGN KEY (changed_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tautan penerimaan ↔ item PO: FK baru bisa dibuat setelah tabel
-- purchase_order_items tersedia (dibuat di migrasi 003 tanpa FK).
ALTER TABLE goods_receipt_items
    ADD CONSTRAINT fk_receipt_items_po_item
        FOREIGN KEY (po_item_id) REFERENCES purchase_order_items (id)
        ON UPDATE CASCADE ON DELETE SET NULL;
