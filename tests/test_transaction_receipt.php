<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\GoodsReceipt;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\AttachmentService;
use App\Services\GoodsReceiptService;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 4 — barang masuk / penerimaan (FR-06).
 *
 * Yang diuji:
 *  - nomor dokumen otomatis unik (GR-YYYYMM-####);
 *  - stok bertambah HANYA setelah transaksi berhasil (+ ledger MASUK);
 *  - penerimaan tanpa PO wajib beralasan;
 *  - hanya penerimaan bertipe "pembelian" yang memperbarui tanggal pembelian
 *    terakhir, harga beli terakhir, & harga beli rata-rata (weighted average);
 *  - penerimaan terkait PO memvalidasi supplier/item/sisa kuantitas dan
 *    memperbarui akumulasi received_qty + status PO;
 *  - supplier/sparepart nonaktif ditolak;
 *  - transaksi gagal TIDAK mengubah stok, tidak menyimpan dokumen, dan
 *    tidak meninggalkan berkas bukti yang menyampah.
 */

/** Header dokumen kosong: tabel goods_receipts. */
function t_receipt_count(): int
{
    return (int) Database::pdo()->query('SELECT COUNT(*) FROM goods_receipts')->fetchColumn();
}

/** Sparepart + supplier + aktor uji untuk alur penerimaan. @return array<string,int> */
function t_receipt_fixture(float $stock = 10.0): array
{
    $categoryId = t_make_category('Kategori Penerimaan');
    $supplierId = t_make_supplier('Supplier Penerimaan');
    $actorId = t_make_user('uji_receipt_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs', 'purchase_price' => 10000]);

    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-01', 'Stok awal uji penerimaan', $actorId, 'MANUAL');
    }

    return [
        'sparepart' => $sparepartId,
        'supplier'  => $supplierId,
        'actor'     => $actorId,
        'category'  => $categoryId,
    ];
}

/** Jumlah berkas bukti yang tersimpan pada penyimpanan privat. */
function t_receipt_upload_count(): int
{
    $base = (string) App\Core\App::config('paths.uploads', BASE_PATH . '/storage/private_uploads');
    if (!is_dir($base)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }
    return $count;
}

/** Buat PO + satu item langsung di DB uji. @return array{po:int,item:int} */
function t_receipt_make_po(int $supplierId, int $sparepartId, float $quantity, int $actorId, string $status = 'DISETUJUI'): array
{
    $pdo = Database::pdo();
    $pdo->prepare(
        'INSERT INTO purchase_orders (po_number, supplier_id, status, total_amount, created_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([t_uniq('PO'), $supplierId, $status, $quantity * 20000, $actorId]);
    $poId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
         VALUES (?, ?, ?, ?, 0)'
    )->execute([$poId, $sparepartId, $quantity, 20000]);

    return ['po' => $poId, 'item' => (int) $pdo->lastInsertId()];
}

function test_transaction_receipt_menambah_stok_dan_mencatat_ledger(): void
{
    $f = t_receipt_fixture(10);

    $receiptId = GoodsReceiptService::create([
        'receipt_date'       => '2026-10-18',
        'receipt_type'       => 'pembelian',
        'supplier_id'        => $f['supplier'],
        'sparepart_id'       => $f['sparepart'],
        'quantity'           => 5,
        'unit_price'         => 20000,
        'invoice_number'     => 'INV-2026-001',
        'without_po_reason'  => 'Barang datang mendadak tanpa PO',
        'note'               => 'Penerimaan uji',
    ], $f['actor']);

    t_true($receiptId > 0, 'Penerimaan tersimpan dan mengembalikan ID');

    $receipt = GoodsReceipt::find($receiptId);
    t_true($receipt !== null, 'Dokumen penerimaan dapat dibaca kembali');
    t_true(
        preg_match('/^GR-\d{6}-\d{4}$/', (string) $receipt['receipt_number']) === 1,
        'Nomor dokumen otomatis berformat GR-YYYYMM-####'
    );
    t_eq('pembelian', (string) $receipt['receipt_type'], 'Jenis penerimaan tersimpan');
    t_eq('INV-2026-001', (string) $receipt['invoice_number'], 'Nomor invoice tersimpan');
    t_eq($f['actor'], (int) $receipt['recorded_by'], 'Partman pencatat tersimpan');

    t_eq(15.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok bertambah 10 → 15 setelah penyimpanan berhasil');

    $items = GoodsReceipt::items($receiptId);
    t_eq(1, count($items), 'Penerimaan memiliki satu item');
    t_eq(5.0, (float) $items[0]['quantity'], 'Kuantitas item tersimpan');
    t_eq(20000.0, (float) $items[0]['unit_price'], 'Harga beli per unit tersimpan');

    $movement = StockMovement::forReference('GOODS_RECEIPT', $receiptId);
    t_true($movement !== null, 'Pergerakan ledger MASUK tercatat untuk penerimaan ini');
    t_eq(StockMovement::TYPE_IN, (string) $movement['movement_type'], 'Jenis pergerakan = MASUK');
    t_eq(5.0, (float) $movement['quantity'], 'Ledger mencatat penambahan 5');
    t_eq(10.0, (float) $movement['stock_before'], 'Ledger mencatat stok sebelum');
    t_eq(15.0, (float) $movement['stock_after'], 'Ledger mencatat stok sesudah');
    t_eq((string) $receipt['receipt_number'], (string) $movement['reference_number'], 'Ledger menautkan nomor dokumen');
    t_eq('2026-10-18', (string) $movement['movement_date'], 'Tanggal bisnis ledger = tanggal penerimaan');
    t_eq($f['actor'], (int) $movement['user_id'], 'Pengguna pencatat tersimpan di ledger');

    $afterPurchase = Sparepart::find($f['sparepart']);
    t_eq('2026-10-18', (string) $afterPurchase['last_purchase_date'], 'Tanggal pembelian terakhir diperbarui (jenis pembelian)');
    t_eq(6666.67, (float) $afterPurchase['avg_purchase_price'], 'Harga beli rata-rata tertimbang dihitung (10×0 + 5×20000)/15');
    t_eq(20000.0, (float) $afterPurchase['purchase_price'], 'Harga beli terakhir mengikuti penerimaan terbaru (keputusan pemilik produk)');

    // Riwayat harga: pembelian kedua dengan harga berbeda → harga terakhir menang,
    // rata-rata tertimbang tetap dihitung terpisah, riwayat tetap dapat ditelusuri.
    $secondId = GoodsReceiptService::create([
        'receipt_date'       => '2026-10-19',
        'receipt_type'       => 'pembelian',
        'supplier_id'        => $f['supplier'],
        'sparepart_id'       => $f['sparepart'],
        'quantity'           => 5,
        'unit_price'         => 30000,
        'invoice_number'     => 'INV-2026-002',
        'without_po_reason'  => 'Pembelian kedua untuk uji riwayat harga',
    ], $f['actor']);
    t_true($secondId > 0, 'Pembelian kedua tersimpan');

    $afterSecond = Sparepart::find($f['sparepart']);
    t_eq(30000.0, (float) $afterSecond['purchase_price'], 'Harga beli terakhir = harga pembelian kedua (30000)');
    t_eq(
        12500.0,
        (float) $afterSecond['avg_purchase_price'],
        'Harga rata-rata tertimbang tetap dihitung terpisah: (15×6666,67 + 5×30000)/20'
    );
    t_eq('2026-10-19', (string) $afterSecond['last_purchase_date'], 'Tanggal pembelian terakhir ikut pembelian kedua');
    t_eq(20.0, (float) $afterSecond['stock'], 'Stok menambah 5 lagi (15 → 20)');

    $priceHistory = Sparepart::purchasePriceHistory($f['sparepart']);
    t_eq(2, count($priceHistory), 'Riwayat harga memuat dua pembelian');
    t_eq(30000.0, (float) $priceHistory[0]['unit_price'], 'Riwayat terbaru tampil lebih dulu (harga 30000)');
    t_eq(20000.0, (float) $priceHistory[1]['unit_price'], 'Riwayat sebelumnya tetap tersimpan (harga 20000)');
    t_eq('2026-10-18', (string) $priceHistory[1]['receipt_date'], 'Tanggal riwayat harga akurat');
    t_true(
        (string) $priceHistory[0]['receipt_number'] !== '',
        'Riwayat harga menautkan nomor dokumen penerimaan'
    );

    $audit = t_latest_audit('GOODS_RECEIPT_CREATE', $receiptId);
    t_true($audit !== null, 'Audit Log mencatat GOODS_RECEIPT_CREATE');
    t_contains('stok', (string) $audit['description'], 'Audit Log menjelaskan perubahan stok');
}

function test_transaction_receipt_tanpa_po_butuh_alasan(): void
{
    $f = t_receipt_fixture(4);
    $sebelum = t_receipt_count();

    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'       => '2026-10-18',
            'supplier_id'        => $f['supplier'],
            'sparepart_id'       => $f['sparepart'],
            'quantity'           => 2,
            'unit_price'         => 5000,
            'without_po_reason'  => '',
        ], $f['actor']),
        RuntimeException::class,
        'Penerimaan tanpa PO dan tanpa alasan DITOLAK'
    );

    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah penolakan');
    t_eq($sebelum, t_receipt_count(), 'Tidak ada dokumen tersimpan dari permintaan yang ditolak');
}

function test_transaction_receipt_jenis_lainnya_tidak_mengubah_tanggal_pembelian(): void
{
    $f = t_receipt_fixture(2);

    $receiptId = GoodsReceiptService::create([
        'receipt_date'       => '2026-10-18',
        'receipt_type'       => 'lainnya',
        'supplier_id'        => $f['supplier'],
        'sparepart_id'       => $f['sparepart'],
        'quantity'           => 3,
        'unit_price'         => 1000,
        'without_po_reason'  => 'Retur dari bengkel lain',
    ], $f['actor']);

    $after = Sparepart::find($f['sparepart']);
    t_eq(5.0, (float) $after['stock'], 'Stok tetap bertambah walau jenisnya "lainnya"');
    t_eq(null, $after['last_purchase_date'], 'Tanggal pembelian terakhir TIDAK diperbarui untuk penerimaan non-pembelian');
    t_eq(
        null,
        $after['avg_purchase_price'],
        'Harga beli rata-rata TIDAK dihitung untuk penerimaan non-pembelian'
    );
    t_true(GoodsReceipt::find($receiptId) !== null, 'Dokumen jenis "lainnya" tetap tersimpan');
    t_eq(
        10000.0,
        (float) $after['purchase_price'],
        'Harga beli terakhir TIDAK berubah untuk penerimaan non-pembelian'
    );
    t_eq([], Sparepart::purchasePriceHistory($f['sparepart']), 'Riwayat harga tidak mencatat penerimaan non-pembelian');
}

function test_transaction_receipt_menolak_supplier_dan_sparepart_nonaktif(): void
{
    $f = t_receipt_fixture(3);
    $supplierNonaktif = t_make_supplier('Supplier Nonaktif', false);

    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $supplierNonaktif,
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 1,
            'unit_price'        => 1000,
            'without_po_reason' => 'Uji supplier nonaktif',
        ], $f['actor']),
        RuntimeException::class,
        'Supplier nonaktif DITOLAK untuk transaksi baru (FR-11)'
    );

    $sparepartNonaktif = t_make_sparepart($f['category'], ['is_active' => 0]);
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $sparepartNonaktif,
            'quantity'          => 1,
            'unit_price'        => 1000,
            'without_po_reason' => 'Uji sparepart nonaktif',
        ], $f['actor']),
        RuntimeException::class,
        'Sparepart nonaktif DITOLAK menerima stok baru'
    );

    t_eq(3.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah penolakan');
}

function test_transaction_receipt_validasi_masukan(): void
{
    $f = t_receipt_fixture(1);
    $base = [
        'receipt_date'      => '2026-10-18',
        'supplier_id'       => $f['supplier'],
        'sparepart_id'      => $f['sparepart'],
        'quantity'          => 1,
        'unit_price'        => 1000,
        'without_po_reason' => 'Alasan uji validasi',
    ];

    $kasus = [
        'Jumlah 0 ditolak'                 => ['quantity' => 0],
        'Jumlah negatif ditolak'           => ['quantity' => -3],
        'Harga negatif ditolak'            => ['unit_price' => -1],
        'Tanggal tidak valid ditolak'      => ['receipt_date' => '31/02/2026'],
        'Tanggal jauh ke depan ditolak'    => ['receipt_date' => '2099-01-01'],
        'Supplier kosong ditolak'          => ['supplier_id' => 0],
        'Sparepart kosong ditolak'         => ['sparepart_id' => 0],
        'Jenis penerimaan asing ditolak'   => ['receipt_type' => 'ngawur'],
        'Invoice terlalu panjang ditolak'  => ['invoice_number' => str_repeat('X', GoodsReceiptService::INVOICE_MAX + 1)],
        'Catatan terlalu panjang ditolak'  => ['note' => str_repeat('X', GoodsReceiptService::NOTE_MAX + 1)],
        'Alasan tanpa PO kepanjangan'      => ['without_po_reason' => str_repeat('X', GoodsReceiptService::REASON_MAX + 1)],
        'Jumlah melebihi batas ditolak'    => ['quantity' => GoodsReceiptService::QTY_MAX + 1],
    ];

    foreach ($kasus as $label => $override) {
        $data = $override + $base;
        // Sparepart/kategori yang tidak ada pun harus ditolak.
        t_throws(
            static fn () => GoodsReceiptService::create($data, $f['actor']),
            RuntimeException::class,
            $label
        );
    }

    t_throws(
        static fn () => GoodsReceiptService::create(['sparepart_id' => 99999999] + $base, $f['actor']),
        RuntimeException::class,
        'Sparepart yang tidak ada ditolak'
    );

    t_eq(1.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah semua masukan tidak sah ditolak');
}

function test_transaction_receipt_menyimpan_bukti(): void
{
    $f = t_receipt_fixture(1);
    $sebelum = t_receipt_upload_count();

    $upload = t_upload('Nota Pembelian.png', t_png_bytes());
    $receiptId = GoodsReceiptService::create([
        'receipt_date'      => '2026-10-18',
        'supplier_id'       => $f['supplier'],
        'sparepart_id'      => $f['sparepart'],
        'quantity'          => 2,
        'unit_price'        => 3000,
        'without_po_reason' => 'Barang tanpa PO',
    ], $f['actor'], $upload['file']);

    $receipt = GoodsReceipt::find($receiptId);
    t_true($receipt['attachment_path'] !== null, 'Path bukti tersimpan di dokumen penerimaan');
    t_eq('Nota Pembelian.png', (string) $receipt['attachment_name'], 'Nama berkas asli tersimpan sebagai keterangan');
    t_eq($sebelum + 1, t_receipt_upload_count(), 'Tepat satu berkas bukti baru disimpan');

    $absolute = AttachmentService::absolutePath((string) $receipt['attachment_path']);
    t_true($absolute !== null && is_file($absolute), 'Berkas bukti ada di penyimpanan privat');

    AttachmentService::delete((string) $receipt['attachment_path']);
    t_eq($sebelum, t_receipt_upload_count(), 'Bukti dapat dihapus dengan aman');
    @unlink($upload['tmp']);
}

function test_transaction_receipt_gagal_tidak_mengubah_stok_dan_tidak_menyampah(): void
{
    $f = t_receipt_fixture(6);
    $sebelumStok = 6.0;
    $sebelumDokumen = t_receipt_count();
    $sebelumBerkas = t_receipt_upload_count();

    // Paksa kegagalan DI DALAM transaksi: nomor dokumen yang akan dibuat
    // sudah dipakai baris lain (melanggar UNIQUE receipt_number).
    $period = date('Ym', strtotime('2026-10-18'));
    $stmt = Database::pdo()->prepare('SELECT last_number FROM document_sequences WHERE prefix = ? AND period = ?');
    $stmt->execute(['GR', $period]);
    $lastNumber = (int) ($stmt->fetchColumn() ?: 0);
    $bentrok = sprintf('GR-%s-%04d', $period, $lastNumber + 1);

    Database::pdo()->prepare(
        'INSERT INTO goods_receipts (receipt_number, receipt_date, receipt_type, supplier_id, recorded_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$bentrok, '2026-10-18', 'pembelian', $f['supplier'], $f['actor']]);

    $upload = t_upload('bukti-gagal.png', t_png_bytes());

    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 4,
            'unit_price'        => 2000,
            'without_po_reason' => 'Uji pembatalan transaksi',
        ], $f['actor'], $upload['file']),
        RuntimeException::class,
        'Penyimpanan yang gagal di tengah transaksi melempar galat'
    );

    t_eq($sebelumStok, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok TIDAK berubah ketika penyimpanan gagal');
    t_eq($sebelumDokumen + 1, t_receipt_count(), 'Tidak ada dokumen/item tersimpan dari transaksi yang gagal (hanya baris pemicu)');
    t_eq($sebelumBerkas, t_receipt_upload_count(), 'Berkas bukti yatim dihapus saat transaksi gagal');
    @unlink($upload['tmp']);

    // Pembersihan baris pemicu supaya tidak mengganggu penomoran uji lain.
    Database::pdo()->prepare('DELETE FROM goods_receipts WHERE receipt_number = ?')->execute([$bentrok]);
}

function test_transaction_receipt_terkait_po(): void
{
    $f = t_receipt_fixture(0);
    $po = t_receipt_make_po($f['supplier'], $f['sparepart'], 10, $f['actor']);

    // Terima sebagian (4 dari 10).
    $partialId = GoodsReceiptService::create([
        'receipt_date'       => '2026-10-18',
        'supplier_id'        => $f['supplier'],
        'sparepart_id'       => $f['sparepart'],
        'quantity'           => 4,
        'unit_price'         => 20000,
        'purchase_order_id'  => $po['po'],
    ], $f['actor']);

    $poRow = Database::pdo()->prepare('SELECT status FROM purchase_orders WHERE id = ?');
    $poRow->execute([$po['po']]);
    t_eq('SEBAGIAN_DITERIMA', (string) $poRow->fetchColumn(), 'PO berstatus SEBAGIAN_DITERIMA setelah penerimaan parsial');

    $itemStmt = Database::pdo()->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
    $itemStmt->execute([$po['item']]);
    t_eq(4.0, (float) $itemStmt->fetchColumn(), 'received_qty PO bertambah 4');
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok bertambah dari penerimaan terkait PO');

    // Melebihi sisa PO ditolak (sisa 6).
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 7,
            'unit_price'        => 20000,
            'purchase_order_id' => $po['po'],
        ], $f['actor']),
        RuntimeException::class,
        'Penerimaan melebihi sisa kuantitas PO DITOLAK'
    );
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah penolakan PO');

    // Supplier berbeda ditolak.
    $supplierLain = t_make_supplier('Supplier Lain');
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $supplierLain,
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 1,
            'unit_price'        => 20000,
            'purchase_order_id' => $po['po'],
        ], $f['actor']),
        RuntimeException::class,
        'Supplier penerimaan berbeda dengan PO DITOLAK'
    );

    // Sparepart bukan item PO ditolak.
    $sparepartLain = t_make_sparepart($f['category']);
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $sparepartLain,
            'quantity'          => 1,
            'unit_price'        => 20000,
            'purchase_order_id' => $po['po'],
        ], $f['actor']),
        RuntimeException::class,
        'Sparepart yang bukan item PO DITOLAK'
    );

    // PO dibatalkan tidak dapat diterima.
    $poBatal = t_receipt_make_po($f['supplier'], $f['sparepart'], 5, $f['actor'], 'DIBATALKAN');
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 1,
            'unit_price'        => 20000,
            'purchase_order_id' => $poBatal['po'],
        ], $f['actor']),
        RuntimeException::class,
        'PO yang dibatalkan DITOLAK'
    );

    // PO yang tidak ada ditolak.
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date'      => '2026-10-18',
            'supplier_id'       => $f['supplier'],
            'sparepart_id'      => $f['sparepart'],
            'quantity'          => 1,
            'unit_price'        => 20000,
            'purchase_order_id' => 99999999,
        ], $f['actor']),
        RuntimeException::class,
        'PO yang tidak ada DITOLAK'
    );

    // Lunasi sisa PO (6) → status SELESAI_DITERIMA.
    GoodsReceiptService::create([
        'receipt_date'      => '2026-10-18',
        'supplier_id'       => $f['supplier'],
        'sparepart_id'      => $f['sparepart'],
        'quantity'          => 6,
        'unit_price'        => 20000,
        'purchase_order_id' => $po['po'],
    ], $f['actor']);

    $poRow->execute([$po['po']]);
    t_eq('SELESAI_DITERIMA', (string) $poRow->fetchColumn(), 'PO berstatus SELESAI_DITERIMA setelah penerimaan penuh');
    t_eq(10.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok bertambah sesuai total penerimaan PO');
    t_true(GoodsReceipt::find($partialId) !== null, 'Dokumen penerimaan parsial tetap tersimpan');
}
