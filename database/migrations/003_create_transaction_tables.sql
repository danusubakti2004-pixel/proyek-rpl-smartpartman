-- ============================================================
-- SmartPartMan — Migrasi 003: Transaksi & ledger stok
-- Pranala PRD: §12 (stock_movements, goods_receipts/items,
--                    goods_issues/items, stock_opnames/items)
--            + FR-05..FR-07, FR-10, Aturan Bisnis 1-8
-- Aturan kunci:
--   * stock_movements = LEDGER APP-ONLY (append-only). Tidak pernah
--     di-update/di-delete; koreksi lewat transaksi pembalik.
--   * stok_belah (spareparts.stock) selalu diperbarui DALAM TRANSAKSI
--     yang sama dengan penulisan ledger.
--   * barang keluar > stok ditolak (dicek service + CHECK stock >= 0).
--   * Penerimaan dengan PO ditautkan; tanpa PO wajib alasan.
--   * Nama tabel purchase_receipts* dari PRD DIPADUKAN ke
--     goods_receipts/items (satu jalur penerimaan → tak ada stok ganda).
-- ============================================================

CREATE TABLE IF NOT EXISTS stock_movements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sparepart_id     INT UNSIGNED NOT NULL,
    movement_type    VARCHAR(20)  NOT NULL,
        -- STOK_AWAL | MASUK | KELUAR | PENYESUAIAN_OPNAME
    quantity         DECIMAL(12,2) NOT NULL,   -- bertanda: + masuk, - keluar
    stock_before     DECIMAL(12,2) NOT NULL,
    stock_after      DECIMAL(12,2) NOT NULL,
    reference_type   VARCHAR(30)  NULL,        -- GOODS_RECEIPT | GOODS_ISSUE | OPNAME | MANUAL
    reference_id     INT UNSIGNED NULL,
    reference_number VARCHAR(30)  NULL,        -- nomor dokumen (GR-..., GI-..., SO-...)
    note             TEXT         NULL,
    user_id          INT UNSIGNED NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_movements_sparepart_time (sparepart_id, created_at),
    KEY idx_movements_type (movement_type),
    KEY idx_movements_reference (reference_type, reference_id),
    KEY idx_movements_created (created_at),
    CONSTRAINT chk_movements_stock_after CHECK (stock_after >= 0),
    CONSTRAINT fk_movements_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_movements_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Penerimaan barang (header) — dipakai untuk pembelian DENGAN PO maupun TANPA PO.
CREATE TABLE IF NOT EXISTS goods_receipts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_number    VARCHAR(30)  NOT NULL,
    receipt_date      DATE         NOT NULL,
    supplier_id       INT UNSIGNED NOT NULL,
    purchase_order_id INT UNSIGNED NULL,        -- NULL = tanpa PO
    without_po_reason TEXT         NULL,        -- wajib diisi service bila tanpa PO
    invoice_number    VARCHAR(50)  NULL,
    note              TEXT         NULL,
    attachment_path   VARCHAR(500) NULL,        -- bukti foto/PDF (di storage/private_uploads)
    recorded_by       INT UNSIGNED NOT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_number (receipt_number),
    KEY idx_receipts_date (receipt_date),
    KEY idx_receipts_supplier (supplier_id),
    KEY idx_receipts_po (purchase_order_id),
    CONSTRAINT fk_receipts_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_id    INT UNSIGNED NOT NULL,
    sparepart_id  INT UNSIGNED NOT NULL,
    quantity      DECIMAL(12,2) NOT NULL,
    unit_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
    po_item_id    INT UNSIGNED NULL,             -- tautan ke item PO (penerimaan parsial)
    PRIMARY KEY (id),
    KEY idx_receipt_items_receipt (receipt_id),
    KEY idx_receipt_items_sparepart (sparepart_id),
    KEY idx_receipt_items_po_item (po_item_id),
    CONSTRAINT chk_receipt_items_qty CHECK (quantity > 0),
    CONSTRAINT fk_receipt_items_receipt
        FOREIGN KEY (receipt_id) REFERENCES goods_receipts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_receipt_items_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengeluaran barang (header).
CREATE TABLE IF NOT EXISTS goods_issues (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issue_number VARCHAR(30)  NOT NULL,
    issue_date   DATE         NOT NULL,
    usage_type   VARCHAR(20)  NOT NULL,          -- service | penjualan | lainnya
    spk_number   VARCHAR(50)  NULL,
    plate_number VARCHAR(20)  NULL,
    note         TEXT         NULL,
    recorded_by  INT UNSIGNED NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_issues_number (issue_number),
    KEY idx_issues_date (issue_date),
    KEY idx_issues_usage (usage_type),
    CONSTRAINT chk_issues_usage CHECK (usage_type IN ('service', 'penjualan', 'lainnya')),
    CONSTRAINT fk_issues_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_issue_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issue_id     INT UNSIGNED NOT NULL,
    sparepart_id INT UNSIGNED NOT NULL,
    quantity     DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_issue_items_issue (issue_id),
    KEY idx_issue_items_sparepart (sparepart_id),
    CONSTRAINT chk_issue_items_qty CHECK (quantity > 0),
    CONSTRAINT fk_issue_items_issue
        FOREIGN KEY (issue_id) REFERENCES goods_issues (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_issue_items_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock opname: sesi (header) — stok TIDAK berubah sebelum konfirmasi.
CREATE TABLE IF NOT EXISTS stock_opnames (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    opname_number VARCHAR(30) NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'OPEN',  -- OPEN | CONFIRMED
    note         TEXT         NULL,
    started_by   INT UNSIGNED NOT NULL,
    started_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_by INT UNSIGNED NULL,
    confirmed_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_opnames_number (opname_number),
    KEY idx_opnames_status (status),
    CONSTRAINT chk_opnames_status CHECK (status IN ('OPEN', 'CONFIRMED')),
    CONSTRAINT fk_opnames_started_by
        FOREIGN KEY (started_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_opnames_confirmed_by
        FOREIGN KEY (confirmed_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_opname_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    opname_id      INT UNSIGNED NOT NULL,
    sparepart_id   INT UNSIGNED NOT NULL,
    system_stock   DECIMAL(12,2) NOT NULL,   -- snapshot saat input
    physical_stock DECIMAL(12,2) NULL,       -- NULL = belum dihitung
    difference     DECIMAL(12,2) NULL,       -- physical - system
    reason         TEXT         NULL,        -- WAJIB bila selisih != 0
    PRIMARY KEY (id),
    UNIQUE KEY uq_opname_items (opname_id, sparepart_id),
    KEY idx_opname_items_sparepart (sparepart_id),
    CONSTRAINT fk_opname_items_opname
        FOREIGN KEY (opname_id) REFERENCES stock_opnames (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_opname_items_sparepart
        FOREIGN KEY (sparepart_id) REFERENCES spareparts (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
