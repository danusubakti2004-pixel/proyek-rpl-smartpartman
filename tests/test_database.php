<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\MigrationService;

/**
 * Uji skema & transaksi database (PRD §12, §15.2, aturan #7).
 * SEMUA memakai database uji (di-set oleh bootstrap.php).
 *
 * @return array<string,mixed> konfigurasi database uji
 */
function test_database_helper_config(): array
{
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['db'];
    $db['name'] = $config['db']['test_name'];
    return $db;
}

function test_database_migrasi_berjalan_penuh(): void
{
    $service = new MigrationService(test_database_helper_config(), BASE_PATH . '/database/migrations');
    $executed = $service->run();

    $pending = $service->pending();
    t_eq([], $pending, 'Tidak ada migrasi tersisa setelah run()');
    t_true(count($service->files()) >= 6, 'Minimal 6 file migrasi terdaftar');
    t_true(count($service->applied()) >= 6, 'Minimal 6 migrasi tercatat di tabel migrations');

    // Run ulang wajib idempoten (tidak ada error, tidak ada migrasi ganda).
    $again = $service->run();
    t_eq([], $again, 'Run kedua tidak menjalankan apa pun (idempoten)');
}

function test_database_seluruh_tabel_prd_ada(): void
{
    $db = test_database_helper_config();
    $pdo = Database::pdo();

    $expected = [
        'roles', 'users', 'login_attempts', 'app_settings', 'audit_logs', 'backups',
        'categories', 'suppliers', 'storage_locations', 'spareparts',
        'stock_movements', 'goods_receipts', 'goods_receipt_items',
        'goods_issues', 'goods_issue_items', 'stock_opnames', 'stock_opname_items',
        'ai_predictions', 'reorder_requests', 'purchase_orders', 'purchase_order_items',
        'purchase_payment_history', 'chatbot_settings', 'chatbot_usage_logs',
    ];

    $stmt = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"'
    );
    $stmt->execute();
    $actual = array_map(static fn (array $r): string => (string) $r['table_name'], $stmt->fetchAll());

    $missing = array_diff($expected, $actual);
    t_eq([], array_values($missing), 'Seluruh tabel PRD §12 tersedia (' . count($expected) . ' tabel)');

    $fk = $pdo->query(
        "SELECT COUNT(*) c FROM information_schema.table_constraints
          WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
    )->fetch();
    t_true((int) $fk['c'] >= 40, 'Foreign key terpasang (≥40, aktual: ' . (int) $fk['c'] . ')');

    $idx = $pdo->query(
        "SELECT COUNT(*) c FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'spareparts'"
    )->fetch();
    t_true((int) $idx['c'] >= 5, 'Indeks spareparts tersedia (≥5, aktual: ' . (int) $idx['c'] . ')');
}

function test_database_data_awal_terseai(): void
{
    $pdo = Database::pdo();
    $roles = $pdo->query('SELECT code FROM roles ORDER BY id')->fetchAll();
    $codes = array_map(static fn (array $r): string => (string) $r['code'], $roles);
    t_eq(['partman', 'kepala_bengkel', 'admin'], $codes, 'Seeder role: partman, kepala_bengkel, admin');

    $settings = $pdo->query('SELECT COUNT(*) c FROM app_settings')->fetch();
    t_true((int) $settings['c'] >= 5, 'Pengaturan awal bengkel terisi');

    $chatbot = $pdo->query('SELECT id, is_enabled FROM chatbot_settings WHERE id = 1')->fetch();
    t_true($chatbot !== false, 'Baris pengaturan chatbot ada');
    t_eq(0, (int) $chatbot['is_enabled'], 'Chatbot default NONAKTIF (harus diaktifkan Admin)');
}

function test_database_status_stok_tanpa_pembagian_nol(): void
{
    // Aturan status stok (PRD §13.3): min_stock = 0 tidak boleh menyebabkan
    // division-by-zero. Skema mengizinkan min_stock = 0 (CHECK >= 0),
    // keputusan status pengecualian ditangani di Service Fase 4 — di sini
    // dipastikan CHECK constraint-nya aktif dan tidak menghalangi nilai 0.
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO categories (code, name) VALUES (:code, :name)'
    );
    $stmt->execute([':code' => 'TEST-CAT-0', ':name' => 'Kategori Uji Nol']);
    $categoryId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO spareparts (code, name, category_id, unit, stock, min_stock)
         VALUES (:code, :name, :cat, "pcs", 0, 0)'
    );
    $insert->execute([':code' => 'TEST-SP-0', ':name' => 'Sparepart Uji Min Nol', ':cat' => $categoryId]);
    $sparepartId = (int) $pdo->lastInsertId();

    t_true($sparepartId > 0, 'min_stock = 0 dan stock = 0 diizinkan oleh skema');

    // Bersihkan.
    $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$sparepartId]);
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
}

function test_database_check_mencegah_stok_negatif(): void
{
    $pdo = Database::pdo();

    // Siapkan kategori valid agar GAGALAN berasal dari CHECK stok,
    // bukan dari foreign key (agar uji benar-benar menyasar CHECK).
    $pdo->prepare('INSERT INTO categories (code, name) VALUES (?, ?)')
        ->execute(['TEST-CAT-NEG', 'Kategori Uji Negatif']);
    $categoryId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO spareparts (code, name, category_id, unit, stock, min_stock)
         VALUES (:code, :name, :cat, "pcs", -5, 0)'
    );

    $threw = false;
    $message = '';
    try {
        $insert->execute([':code' => 'TEST-SP-NEG', ':name' => 'Stok Negatif', ':cat' => $categoryId]);
    } catch (PDOException $e) {
        $threw = true;
        $message = $e->getMessage();
    }
    t_true($threw, 'CHECK constraint menolak stok negatif di level database');
    t_contains('chk_spareparts_stock', $message, 'Kegagalan berasal dari CHECK stok (bukan penyebab lain)');

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
}

function test_database_foreign_key_ditegakkan(): void
{
    $pdo = Database::pdo();
    $insert = $pdo->prepare(
        'INSERT INTO spareparts (code, name, category_id, unit, stock, min_stock)
         VALUES (:code, :name, 999999, "pcs", 0, 0)'
    );

    $threw = false;
    $message = '';
    try {
        $insert->execute([':code' => 'TEST-SP-FK', ':name' => 'FK Palsu']);
    } catch (PDOException $e) {
        $threw = true;
        $message = $e->getMessage();
    }
    t_true($threw, 'Foreign key menolak category_id yang tidak ada');
    t_contains('foreign key', $message, 'Kegagalan berasal dari foreign key');
}

function test_database_transaksi_rollback_penuh(): void
{
    $pdo = Database::pdo();
    $before = (int) $pdo->query('SELECT COUNT(*) c FROM categories')->fetch()['c'];

    try {
        Database::transaction(static function (PDO $pdo): void {
            $pdo->prepare('INSERT INTO categories (code, name) VALUES (?, ?)')
                ->execute(['TEST-ROLLBACK', 'Uji Rollback']);
            // Sengaja gagal di tengah transaksi.
            throw new RuntimeException('sengaja gagal');
        });
        t_true(false, 'Transaksi gagal harus melempar exception');
    } catch (RuntimeException) {
        // diperkirakan
    }

    $after = (int) $pdo->query('SELECT COUNT(*) c FROM categories')->fetch()['c'];
    t_eq($before, $after, 'Rollback mengembalikan keadaan (tidak ada data setengah jadi)');
}

function test_database_transaksi_commit_berhasil(): void
{
    $pdo = Database::pdo();
    $before = (int) $pdo->query('SELECT COUNT(*) c FROM suppliers')->fetch()['c'];

    $id = Database::transaction(static function (PDO $pdo): int {
        $pdo->prepare('INSERT INTO suppliers (code, name) VALUES (?, ?)')
            ->execute(['TEST-SUP-COMMIT', 'Supplier Uji Commit']);
        return (int) $pdo->lastInsertId();
    });

    $after = (int) $pdo->query('SELECT COUNT(*) c FROM suppliers')->fetch()['c'];
    t_eq($before + 1, $after, 'Commit menyimpan satu baris baru');
    t_true($id > 0, 'Insert dalam transaksi mengembalikan ID');

    $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
}
