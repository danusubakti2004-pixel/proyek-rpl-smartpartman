<?php
declare(strict_types=1);

use App\Core\Database;

/**
 * Pengujian Fase 4 — skema penunjang transaksi (migrasi 010).
 *
 * Memastikan objek database yang menjadi fondasi transaksi benar-benar ada:
 * tabel penomoran dokumen, kolom referensi koreksi, jenis penerimaan,
 * nama berkas bukti, tanggal opname, dan jejak penghitung opname.
 */

/** Apakah kolom ada di tabel tertentu pada database aktif? */
function t_column_exists(string $table, string $column): bool
{
    $stmt = Database::pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Apakah nama constraint (FK/CHECK/UNIQUE) ada? */
function t_constraint_exists(string $table, string $name): bool
{
    $stmt = Database::pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $stmt->execute([$table, $name]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Apakah indeks bernama ada? */
function t_index_exists(string $table, string $name): bool
{
    $stmt = Database::pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $name]);
    return (int) $stmt->fetchColumn() > 0;
}

function test_transaction_schema_tabel_penomoran_dokumen(): void
{
    t_true(t_column_exists('document_sequences', 'prefix'), 'Tabel document_sequences.prefix ada');
    t_true(t_column_exists('document_sequences', 'period'), 'Tabel document_sequences.period ada');
    t_true(t_column_exists('document_sequences', 'last_number'), 'Tabel document_sequences.last_number ada');
    t_true(
        t_index_exists('document_sequences', 'uq_document_sequences'),
        'Penomoran dokumen dijaga UNIQUE (prefix, period) agar tidak ada nomor ganda'
    );
}

function test_transaction_schema_referensi_koreksi_ledger(): void
{
    t_true(t_column_exists('stock_movements', 'reversal_of_id'), 'Ledger memiliki kolom reversal_of_id (transaksi pembalik)');
    t_true(t_constraint_exists('stock_movements', 'fk_movements_reversal'), 'reversal_of_id punya foreign key ke baris ledger asal');
    t_true(t_index_exists('stock_movements', 'idx_movements_reversal'), 'reversal_of_id terindeks untuk pencarian koreksi');
    t_true(t_column_exists('stock_movements', 'movement_date'), 'Ledger memiliki tanggal bisnis (movement_date)');
}

function test_transaction_schema_penerimaan_dan_opname(): void
{
    t_true(t_column_exists('goods_receipts', 'receipt_type'), 'Penerimaan memiliki jenis (receipt_type)');
    t_true(t_constraint_exists('goods_receipts', 'chk_receipts_type'), 'Jenis penerimaan dibatasi CHECK (pembelian/lainnya)');
    t_true(t_column_exists('goods_receipts', 'attachment_name'), 'Penerimaan menyimpan nama berkas bukti asli');
    t_true(t_column_exists('goods_receipts', 'attachment_path'), 'Penerimaan menyimpan path bukti di penyimpanan privat');
    t_true(t_column_exists('stock_opnames', 'opname_date'), 'Sesi opname memiliki tanggal bisnis');
    t_true(t_column_exists('stock_opname_items', 'counted_by'), 'Item opname menyimpan siapa yang menghitung');
    t_true(t_column_exists('stock_opname_items', 'counted_at'), 'Item opname menyimpan kapan dihitung');
    t_true(
        t_constraint_exists('stock_opname_items', 'fk_opname_items_counted_by'),
        'Penghitung opname tertaut ke tabel users'
    );
}

function test_transaction_schema_kolom_lama_tetap_terisi(): void
{
    // Migrasi 010 mengisi opname_date untuk baris lama agar tampilan tidak kosong.
    $kosong = (int) Database::pdo()->query(
        'SELECT COUNT(*) FROM stock_opnames WHERE opname_date IS NULL'
    )->fetchColumn();
    t_eq(0, $kosong, 'Semua sesi opname memiliki tanggal bisnis (baris lama diisi otomatis)');
}
