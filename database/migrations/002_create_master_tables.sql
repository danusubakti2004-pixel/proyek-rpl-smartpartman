-- ============================================================
-- SmartPartMan — Migrasi 002: Master data
-- Pranala PRD: §12 (categories, suppliers, storage_locations,
--                    spareparts) + FR-03, FR-11, FR-12, FR-09
-- Aturan:
--   * Kode sparepart unik.
--   * Supplier/kategori yang pernah dipakai TIDAK dihapus permanen
--     → hanya is_active = 0 (FK memakai RESTRICT).
--   * Stok & stok minimum per sparepart; stok minimum > 0 disarankan
--     agar status stok berbasis persentase terdefinisi.
-- ============================================================

CREATE TABLE IF NOT EXISTS categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by  INT UNSIGNED NULL,
    updated_at  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_code (code),
    KEY idx_categories_active (is_active),
    CONSTRAINT fk_categories_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_categories_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(30)  NOT NULL,
    name           VARCHAR(150) NOT NULL,
    address        TEXT         NULL,
    phone          VARCHAR(30)  NULL,
    email          VARCHAR(100) NULL,
    contact_person VARCHAR(100) NULL,
    lead_time_days INT UNSIGNED NULL,     -- estimasi lead time (asumsi, bisa kosong)
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT UNSIGNED NULL,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_code (code),
    KEY idx_suppliers_active (is_active),
    CONSTRAINT fk_suppliers_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_suppliers_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lokasi penyimpanan: satu gudang, beberapa rak/tingkat (FR-09).
CREATE TABLE IF NOT EXISTS storage_locations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by  INT UNSIGNED NULL,
    updated_at  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_code (code),
    KEY idx_locations_active (is_active),
    CONSTRAINT fk_locations_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_locations_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spareparts (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code               VARCHAR(50)  NOT NULL,
    name               VARCHAR(200) NOT NULL,
    category_id        INT UNSIGNED NOT NULL,
    brand              VARCHAR(100) NULL,   -- merek (mis. Suzuki)
    vehicle_model      VARCHAR(100) NULL,   -- model kendaraan
    unit               VARCHAR(30)  NOT NULL DEFAULT 'pcs',
    purchase_price     DECIMAL(15,2) NOT NULL DEFAULT 0,   -- harga beli
    selling_price      DECIMAL(15,2) NOT NULL DEFAULT 0,   -- harga jual
    avg_purchase_price DECIMAL(15,2) NULL,   -- harga beli rata-rata (weighted average)
    stock              DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_stock          DECIMAL(12,2) NOT NULL DEFAULT 0,
    location_id        INT UNSIGNED NULL,
    supplier_id        INT UNSIGNED NULL,
    last_purchase_date DATE          NULL,
    is_active          TINYINT(1)    NOT NULL DEFAULT 1,
    created_by         INT UNSIGNED  NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by         INT UNSIGNED  NULL,
    updated_at         TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_spareparts_code (code),
    KEY idx_spareparts_name (name),
    KEY idx_spareparts_category (category_id),
    KEY idx_spareparts_supplier (supplier_id),
    KEY idx_spareparts_location (location_id),
    KEY idx_spareparts_stock (stock),
    CONSTRAINT chk_spareparts_stock CHECK (stock >= 0),
    CONSTRAINT chk_spareparts_min_stock CHECK (min_stock >= 0),
    CONSTRAINT fk_spareparts_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_spareparts_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_spareparts_location
        FOREIGN KEY (location_id) REFERENCES storage_locations (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_spareparts_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_spareparts_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
