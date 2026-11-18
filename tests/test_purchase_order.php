<?php
declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\ReorderRequest;
use App\Models\Sparepart;
use App\Models\StatusHistory;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\ReorderRequestService;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 6 — Purchase Order (FR-16, Aturan Bisnis 13-17).
 *
 * Yang diuji:
 *  - Partman membuat draft PO (nomor PO otomatis, total dihitung sistem);
 *  - alur persetujuan: diajukan Partman → disetujui/ditolak Kepala Bengkel
 *    (penolakan wajib alasan) → diproses Admin;
 *  - persetujuan/proses TIDAK mengubah stok — stok hanya dari penerimaan;
 *  - pembayaran terpisah dari persetujuan (status + catatan, ada riwayat);
 *  - pembatalan dengan alasan menggantikan penghapusan (tidak ada hard
 *    delete — baris PO & riwayat tetap tersimpan);
 *  - rekomendasi supplier dari riwayat pembelian + supplier default;
 *  - PO terkait permintaan reorder yang sudah disetujui (sekali jadi);
 *  - penerimaan parsial menghitung sisa, penerimaan penuh menutup PO,
 *    kelebihan penerimaan SELALU ditolak.
 */

/** Fixture per uji: Partman, Kepala, Admin, kategori, supplier aktif. */
function t_po6_fixture(string $tag): array
{
    // Nama unik per pemanggilan: t_make_user menghapus baris lama lebih dulu,
    // sedangkan baris PO/reorder lama (FK ke users) boleh tersisa antar-pengujian.
    $u = $tag . '_' . bin2hex(random_bytes(3));

    return [
        'partmanId'  => t_make_user('po6_pm_' . $u, 'partman', t_password()),
        'kepalaId'   => t_make_user('po6_kb_' . $u, 'kepala_bengkel', t_password()),
        'adminId'    => t_make_user('po6_ad_' . $u, 'admin', t_password()),
        'categoryId' => t_make_category('PO6 ' . $tag),
        'supplierId' => t_make_supplier('PO6 ' . $tag),
    ];
}

/** Sparepart uji (stok 0; bila $initial > 0 dicatat lewat ledger). */
function t_po6_part(array $f, array $overrides = [], float $initial = 0): int
{
    $id = t_make_sparepart($f['categoryId'], $overrides + ['stock' => 0, 'min_stock' => 2, 'unit' => 'pcs']);
    if ($initial > 0) {
        StockInitialService::apply($id, $initial, '2026-10-18', 'Stok awal uji PO', $f['partmanId']);
    }
    return $id;
}

/** Draft PO satu item sebagai titik awal uji. */
function t_po6_draft(array $f, int $sparepartId, float $qty = 6, float $price = 12000, array $extra = []): int
{
    return PurchaseOrderService::create($extra + [
        'supplier_id' => $f['supplierId'],
        'note'        => 'PO uji Fase 6',
        'items'       => [['sparepart_id' => $sparepartId, 'quantity' => $qty, 'unit_price' => $price]],
    ], $f['partmanId']);
}

/** Catat penerimaan barang terkait PO (Partman). */
function t_po6_receive(array $f, int $poId, int $sparepartId, float $qty, float $price = 12000): int
{
    return GoodsReceiptService::create([
        'receipt_date'      => '2026-10-19',
        'receipt_type'      => 'pembelian',
        'supplier_id'       => $f['supplierId'],
        'sparepart_id'      => $sparepartId,
        'quantity'          => $qty,
        'unit_price'        => $price,
        'purchase_order_id' => $poId,
    ], $f['partmanId']);
}

/** Jumlah baris audit untuk aktivitas+entitas PO tertentu. */
function t_po6_audit(string $activity, int $poId): int
{
    $st = \App\Core\Database::pdo()->prepare(
        'SELECT COUNT(*) FROM audit_logs WHERE activity = ? AND entity_type = ? AND entity_id = ?'
    );
    $st->execute([$activity, 'purchase_orders', (string) $poId]);
    return (int) $st->fetchColumn();
}

function test_po_partman_membuat_draft(): void
{
    $f = t_po6_fixture('draft');
    $sp1 = t_po6_part($f, ['min_stock' => 5], 8);
    $sp2 = t_po6_part($f);

    $poId = PurchaseOrderService::create([
        'supplier_id' => $f['supplierId'],
        'note'        => 'Pengadaan rutin',
        'items'       => [
            ['sparepart_id' => $sp1, 'quantity' => 10, 'unit_price' => 15000],
            ['sparepart_id' => $sp2, 'quantity' => 5, 'unit_price' => 20000],
        ],
    ], $f['partmanId']);
    t_true($poId > 0, 'Draft PO tersimpan');

    $po = PurchaseOrder::find($poId);
    t_true(
        preg_match('/^PO-\d{6}-\d{4}$/', (string) $po['po_number']) === 1,
        'Nomor PO berformat PO-YYYYMM-####'
    );
    t_eq(PurchaseOrder::STATUS_DRAFT, (string) $po['status'], 'PO baru berstatus Draf');
    t_eq(PurchaseOrder::PAYMENT_UNPAID, (string) $po['payment_status'], 'Pembayaran awal Belum Dibayar');
    t_eq(250000.0, (float) $po['total_amount'], 'Total = Σ(jumlah × harga) dihitung sistem');
    t_eq($f['partmanId'], (int) $po['created_by'], 'Pembuat PO tercatat');

    $items = PurchaseOrder::items($poId);
    t_eq(2, count($items), 'Dua item PO tersimpan');
    t_eq(0.0, (float) $items[0]['received_qty'], 'Item mulai dengan diterima 0');

    $history = StatusHistory::forDocument(StatusHistory::TYPE_PO, $poId);
    t_eq(1, count($history), 'Riwayat status tercatat saat pembuatan');
    t_eq(null, $history[0]['old_status'], 'Riwayat pembuatan: status lama null');
    t_eq(PurchaseOrder::STATUS_DRAFT, (string) $history[0]['new_status'], 'Riwayat pembuatan: status baru Draf');
    t_eq($f['partmanId'], (int) $history[0]['actor_id'], 'Riwayat mencatat aktor pembuat');
    t_true(t_po6_audit('PO_CREATE', $poId) >= 1, 'Audit log memuat PO_CREATE');

    t_eq(8.0, (float) Sparepart::find($sp1)['stock'], 'Membuat PO tidak mengubah stok');
    t_eq(0.0, (float) Sparepart::find($sp2)['stock'], 'Membuat PO tidak mengubah stok (item kedua)');
}

function test_po_validasi_item_dan_supplier(): void
{
    $f = t_po6_fixture('valid');
    $sp = t_po6_part($f);
    $inactive = t_po6_part($f, ['is_active' => 0]);
    $inactiveSupplier = t_make_supplier('PO6 Nonaktif ' . $f['partmanId'], false);

    t_throws(
        static fn () => PurchaseOrderService::create(
            ['supplier_id' => $f['supplierId'], 'items' => []],
            $f['partmanId']
        ),
        RuntimeException::class,
        'PO tanpa item ditolak'
    );
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id' => $f['supplierId'],
            'items'       => [
                ['sparepart_id' => $sp, 'quantity' => 2, 'unit_price' => 1000],
                ['sparepart_id' => $sp, 'quantity' => 3, 'unit_price' => 1000],
            ],
        ], $f['partmanId']),
        RuntimeException::class,
        'Sparepart terduplikasi dalam satu PO ditolak'
    );
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id' => $f['supplierId'],
            'items'       => [['sparepart_id' => $sp, 'quantity' => 0, 'unit_price' => 1000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'Jumlah item 0 ditolak'
    );
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id' => $f['supplierId'],
            'items'       => [['sparepart_id' => $inactive, 'quantity' => 1, 'unit_price' => 1000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'Sparepart nonaktif ditolak'
    );
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id' => $inactiveSupplier,
            'items'       => [['sparepart_id' => $sp, 'quantity' => 1, 'unit_price' => 1000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'Supplier nonaktif ditolak'
    );
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id' => 99999999,
            'items'       => [['sparepart_id' => $sp, 'quantity' => 1, 'unit_price' => 1000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'Supplier tidak ditemukan ditolak'
    );
}

function test_po_alur_persetujuan_dan_proses(): void
{
    $f = t_po6_fixture('alur');
    $sp = t_po6_part($f, [], 5);
    $poId = t_po6_draft($f, $sp);

    // Pengaju lain tidak boleh mengajukan.
    $other = t_make_user('po6_pm_lain_' . uniqid(), 'partman', t_password());
    t_throws(
        static fn () => PurchaseOrderService::submit($poId, $other),
        RuntimeException::class,
        'Hanya pembuat PO yang dapat mengajukannya'
    );

    PurchaseOrderService::submit($poId, $f['partmanId']);
    t_eq(
        PurchaseOrder::STATUS_PENDING,
        (string) PurchaseOrder::find($poId)['status'],
        'PO diajukan ke Kepala Bengkel'
    );
    t_throws(
        static fn () => PurchaseOrderService::process($poId, $f['adminId']),
        RuntimeException::class,
        'Proses administrasi sebelum persetujuan ditolak'
    );

    PurchaseOrderService::approve($poId, $f['kepalaId']);
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_APPROVED, (string) $po['status'], 'PO disetujui Kepala Bengkel');
    t_eq($f['kepalaId'], (int) $po['approved_by'], 'Penyetuju tercatat');
    t_true($po['approved_at'] !== null, 'Waktu persetujuan tercatat');
    t_eq(
        5.0,
        (float) Sparepart::find($sp)['stock'],
        'PERSETUJUAN PO TIDAK mengubah stok'
    );
    t_true(t_po6_audit('PO_APPROVE', $poId) >= 1, 'Audit log memuat PO_APPROVE');

    $history = StatusHistory::forDocument(StatusHistory::TYPE_PO, $poId);
    $last = $history[count($history) - 1];
    t_eq(PurchaseOrder::STATUS_PENDING, (string) $last['old_status'], 'Transisi: status lama Menunggu');
    t_eq(PurchaseOrder::STATUS_APPROVED, (string) $last['new_status'], 'Transisi: status baru Disetujui');
    t_eq($f['kepalaId'], (int) $last['actor_id'], 'Transisi mencatat aktor');

    t_throws(
        static fn () => PurchaseOrderService::approve($poId, $f['kepalaId']),
        RuntimeException::class,
        'Persetujuan kedua ditolak'
    );

    PurchaseOrderService::process($poId, $f['adminId']);
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_PROCESSED, (string) $po['status'], 'Admin memproses PO disetujui');
    t_eq($f['adminId'], (int) $po['processed_by'], 'Pelaku proses tercatat');
    t_true($po['processed_at'] !== null, 'Waktu proses tercatat');
    t_eq(
        5.0,
        (float) Sparepart::find($sp)['stock'],
        'Proses administrasi TIDAK mengubah stok'
    );
    t_true(t_po6_audit('PO_PROCESS', $poId) >= 1, 'Audit log memuat PO_PROCESS');
    t_throws(
        static fn () => PurchaseOrderService::submit($poId, $f['partmanId']),
        RuntimeException::class,
        'Pengajuan ulang pada status lain ditolak'
    );
}

function test_po_tolak_wajib_alasan(): void
{
    $f = t_po6_fixture('tolak');
    $sp = t_po6_part($f);
    $poId = t_po6_draft($f, $sp);
    PurchaseOrderService::submit($poId, $f['partmanId']);

    t_throws(
        static fn () => PurchaseOrderService::reject($poId, '  ', $f['kepalaId']),
        RuntimeException::class,
        'Penolakan PO tanpa alasan ditolak'
    );
    t_eq(
        PurchaseOrder::STATUS_PENDING,
        (string) PurchaseOrder::find($poId)['status'],
        'Status tidak berubah setelah penolakan tanpa alasan'
    );

    PurchaseOrderService::reject($poId, 'Harga beli terlalu mahal', $f['kepalaId']);
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_REJECTED, (string) $po['status'], 'PO ditolak');
    t_contains('terlalu mahal', (string) $po['rejected_reason'], 'Alasan penolakan tersimpan');
    t_true(t_po6_audit('PO_REJECT', $poId) >= 1, 'Audit log memuat PO_REJECT');

    $history = StatusHistory::forDocument(StatusHistory::TYPE_PO, $poId);
    $last = $history[count($history) - 1];
    t_contains('terlalu mahal', (string) $last['reason'], 'Riwayat mencatat alasan penolakan');

    t_throws(
        static fn () => PurchaseOrderService::approve($poId, $f['kepalaId']),
        RuntimeException::class,
        'PO yang sudah ditolak tidak dapat disetujui'
    );
    t_throws(
        static fn () => PurchaseOrderService::reject($poId, 'Alasan lain', $f['kepalaId']),
        RuntimeException::class,
        'PO yang sudah ditolak tidak dapat ditolak ulang'
    );
}

function test_po_pembayaran_terpisah_dan_beriwayat(): void
{
    $f = t_po6_fixture('bayar');
    $sp = t_po6_part($f);

    // Pembayaran PO yang belum disetujui ditolak.
    $draftId = t_po6_draft($f, $sp);
    t_throws(
        static fn () => PurchaseOrderService::updatePayment(
            $draftId, PurchaseOrder::PAYMENT_PARTIAL, 'DP', $f['adminId']
        ),
        RuntimeException::class,
        'Pembayaran sebelum persetujuan ditolak'
    );

    $poId = t_po6_draft($f, $sp);
    PurchaseOrderService::submit($poId, $f['partmanId']);
    PurchaseOrderService::approve($poId, $f['kepalaId']);

    t_throws(
        static fn () => PurchaseOrderService::updatePayment($poId, 'LUNAS_BANGET', '', $f['adminId']),
        RuntimeException::class,
        'Status pembayaran asing ditolak'
    );

    PurchaseOrderService::updatePayment(
        $poId, PurchaseOrder::PAYMENT_PARTIAL, 'DP 50% transfer', $f['adminId']
    );
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::PAYMENT_PARTIAL, (string) $po['payment_status'], 'Status pembayaran Dibayar Sebagian');
    t_eq(
        PurchaseOrder::STATUS_APPROVED,
        (string) $po['status'],
        'Status persetujuan TIDAK berubah oleh pembayaran (dipisah)'
    );
    $payHistory = PurchaseOrder::paymentHistory($poId);
    t_eq(1, count($payHistory), 'Riwayat pembayaran mencatat satu perubahan');
    t_contains('DP 50%', (string) $payHistory[0]['note'], 'Catatan pembayaran tersimpan');
    t_eq($f['adminId'], (int) $payHistory[0]['changed_by'], 'Pelaku perubahan pembayaran tercatat');
    t_true(t_po6_audit('PO_PAYMENT_UPDATE', $poId) >= 1, 'Audit log memuat PO_PAYMENT_UPDATE');

    t_throws(
        static fn () => PurchaseOrderService::updatePayment(
            $poId, PurchaseOrder::PAYMENT_PARTIAL, 'ulang', $f['adminId']
        ),
        RuntimeException::class,
        'Perubahan ke status yang sama ditolak'
    );

    PurchaseOrderService::updatePayment($poId, PurchaseOrder::PAYMENT_PAID, '', $f['adminId']);
    t_eq(PurchaseOrder::PAYMENT_PAID, (string) PurchaseOrder::find($poId)['payment_status'], 'PO berstatus Lunas');
    t_eq(2, count(PurchaseOrder::paymentHistory($poId)), 'Riwayat pembayaran bertambah lengkap');
}

function test_po_pembatalan_bukan_hapus(): void
{
    $f = t_po6_fixture('batal');
    $sp = t_po6_part($f);

    // Pembatalan wajib alasan.
    $poId = t_po6_draft($f, $sp);
    t_throws(
        static fn () => PurchaseOrderService::cancel($poId, '', $f['partmanId'], false),
        RuntimeException::class,
        'Pembatalan tanpa alasan ditolak'
    );

    // Orang lain (bukan pembuat, bukan Admin) tidak boleh membatalkan draft.
    $other = t_make_user('po6_pm_lain_' . uniqid(), 'partman', t_password());
    t_throws(
        static fn () => PurchaseOrderService::cancel($poId, 'Uji otorisasi', $other, false),
        RuntimeException::class,
        'Hanya pembuat atau Admin yang dapat membatalkan draft'
    );

    PurchaseOrderService::cancel($poId, 'Rencana pengadaan dibatalkan', $f['partmanId'], false);
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_CANCELLED, (string) $po['status'], 'PO dibatalkan dengan alasan');
    t_eq($f['partmanId'], (int) $po['cancelled_by'], 'Pelaku pembatalan tercatat');
    t_true($po['cancelled_at'] !== null, 'Waktu pembatalan tercatat');
    t_contains('dibatalkan', (string) $po['cancel_reason'], 'Alasan pembatalan tersimpan');

    // Tidak ada hard delete: baris PO & item tetap ada.
    $pdo = \App\Core\Database::pdo();
    $st = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
    $st->execute([$poId]);
    t_eq(1, (int) $st->fetchColumn(), 'PO yang dibatalkan TIDAK dihapus');
    t_true(count(PurchaseOrder::items($poId)) > 0, 'Item PO tetap tersimpan setelah pembatalan');
    t_true(t_po6_audit('PO_CANCEL', $poId) >= 1, 'Audit log memuat PO_CANCEL');

    // Admin membatalkan PO disetujui & diproses yang belum diterima.
    $poA = t_po6_draft($f, $sp);
    PurchaseOrderService::submit($poA, $f['partmanId']);
    PurchaseOrderService::approve($poA, $f['kepalaId']);
    PurchaseOrderService::cancel($poA, 'Supplier tidak dapat memasok', $f['adminId'], true);
    t_eq(PurchaseOrder::STATUS_CANCELLED, (string) PurchaseOrder::find($poA)['status'], 'Admin membatalkan PO disetujui');

    // PO yang sudah selesai diterima tidak dapat dibatalkan.
    $poC = t_po6_draft($f, $sp, 2, 10000);
    PurchaseOrderService::submit($poC, $f['partmanId']);
    PurchaseOrderService::approve($poC, $f['kepalaId']);
    t_po6_receive($f, $poC, $sp, 2, 10000);
    t_eq(
        PurchaseOrder::STATUS_COMPLETE,
        (string) PurchaseOrder::find($poC)['status'],
        'Penerimaan penuh menutup PO'
    );
    t_throws(
        static fn () => PurchaseOrderService::cancel($poC, 'Uji batal PO selesai', $f['adminId'], true),
        RuntimeException::class,
        'PO yang sudah diterima penuh tidak dapat dibatalkan'
    );
}

function test_po_rekomendasi_supplier_dari_riwayat(): void
{
    $f = t_po6_fixture('rec');
    $supplierA = t_make_supplier('PO6 Rec A');
    $supplierB = t_make_supplier('PO6 Rec B');
    $defaultSupplier = t_make_supplier('PO6 Rec Default');
    $sp = t_po6_part($f, ['supplier_id' => $defaultSupplier]);

    $beli = static function (int $supplierId, float $qty, float $price, string $date) use ($f, $sp): void {
        GoodsReceiptService::create([
            'receipt_date'      => $date,
            'receipt_type'      => 'pembelian',
            'supplier_id'       => $supplierId,
            'sparepart_id'      => $sp,
            'quantity'          => $qty,
            'unit_price'        => $price,
            'without_po_reason' => 'Pembelian langsung uji rekomendasi',
        ], $f['partmanId']);
    };
    $beli($supplierA, 1, 25000, '2026-10-10');
    $beli($supplierB, 1, 24000, '2026-10-11');
    $beli($supplierA, 2, 26000, '2026-10-12');

    $recs = PurchaseOrderService::supplierRecommendations($sp);
    t_true(count($recs) >= 3, 'Rekomendasi memuat supplier riwayat + supplier default');
    t_eq($supplierA, (int) $recs[0]['supplier_id'], 'Supplier dengan riwayat terbanyak di peringkat teratas');
    t_eq(2, (int) $recs[0]['times'], 'Jumlah kali pembelian ikut dinilai');
    t_eq('2026-10-12', (string) $recs[0]['last_date'], 'Tanggal pembelian terakhir ikut dinilai');
    t_eq(26000.0, (float) $recs[0]['last_price'], 'Harga beli terakhir ikut dinilai');
    t_eq($supplierB, (int) $recs[1]['supplier_id'], 'Supplier dengan riwayat lebih sedikit di bawahnya');
    $lastRec = $recs[count($recs) - 1];
    t_eq($defaultSupplier, (int) $lastRec['supplier_id'], 'Supplier default sparepart ikut direkomendasikan');
    t_true((bool) $lastRec['is_default'], 'Supplier default ditandai');

    // Tanpa riwayat & tanpa supplier default → rekomendasi kosong.
    $spKosong = t_po6_part($f);
    t_eq([], PurchaseOrderService::supplierRecommendations($spKosong), 'Rekomendasi kosong tanpa data');
}

function test_po_dari_permintaan_reorder(): void
{
    $f = t_po6_fixture('rqlink');
    $sp = t_po6_part($f, ['min_stock' => 10], 4); // stok 4, min 10 → saran 6
    $otherPart = t_po6_part($f);

    $rqId = ReorderRequestService::create([
        'sparepart_id'  => $sp,
        'requested_qty' => '6',
    ], $f['partmanId']);

    // Permintaan yang belum disetujui tidak boleh dijadikan PO.
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id'         => $f['supplierId'],
            'reorder_request_id'  => $rqId,
            'items'               => [['sparepart_id' => $sp, 'quantity' => 6, 'unit_price' => 12000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'PO dari permintaan yang belum disetujui ditolak'
    );

    ReorderRequestService::review($rqId, true, '', $f['kepalaId']);

    // Item PO harus memuat sparepart yang sama dengan permintaan.
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id'         => $f['supplierId'],
            'reorder_request_id'  => $rqId,
            'items'               => [['sparepart_id' => $otherPart, 'quantity' => 6, 'unit_price' => 12000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'PO dari reorder tanpa sparepart yang sama ditolak'
    );

    $poId = PurchaseOrderService::create([
        'supplier_id'         => $f['supplierId'],
        'reorder_request_id'  => $rqId,
        'items'               => [['sparepart_id' => $sp, 'quantity' => 6, 'unit_price' => 12000]],
    ], $f['partmanId']);
    $st = \App\Core\Database::pdo()->prepare('SELECT reorder_request_id FROM purchase_orders WHERE id = ?');
    $st->execute([$poId]);
    t_eq($rqId, (int) $st->fetchColumn(), 'PO tertaut dengan permintaan reorder asalnya');

    // Satu permintaan hanya boleh melahirkan satu PO.
    t_throws(
        static fn () => PurchaseOrderService::create([
            'supplier_id'         => $f['supplierId'],
            'reorder_request_id'  => $rqId,
            'items'               => [['sparepart_id' => $sp, 'quantity' => 6, 'unit_price' => 12000]],
        ], $f['partmanId']),
        RuntimeException::class,
        'Permintaan yang sudah pernah dijadikan PO tidak dipakai ulang'
    );
}

function test_po_alur_lengkap_reorder_ke_penerimaan(): void
{
    $f = t_po6_fixture('lengkap');
    $sp = t_po6_part($f, ['min_stock' => 10], 4); // stok 4, min 10 → saran 6

    // 1. Reorder diajukan Partman, disetujui Kepala Bengkel — stok tidak berubah.
    $rqId = ReorderRequestService::create([
        'sparepart_id'  => $sp,
        'requested_qty' => '6',
    ], $f['partmanId']);
    ReorderRequestService::review($rqId, true, '', $f['kepalaId']);
    t_eq(4.0, (float) Sparepart::find($sp)['stock'], 'Persetujuan reorder tidak mengubah stok');

    // 2. PO dari reorder: diajukan, disetujui, diproses Admin — stok TETAP.
    $poId = t_po6_draft($f, $sp, 6, 12000, ['reorder_request_id' => $rqId]);
    PurchaseOrderService::submit($poId, $f['partmanId']);
    PurchaseOrderService::approve($poId, $f['kepalaId']);
    t_eq(4.0, (float) Sparepart::find($sp)['stock'], 'Persetujuan PO tidak mengubah stok');
    PurchaseOrderService::process($poId, $f['adminId']);
    t_eq(4.0, (float) Sparepart::find($sp)['stock'], 'Proses administrasi tidak mengubah stok');

    // 3. Penerimaan parsial: stok naik hanya sebesar yang diterima; sisa benar.
    t_po6_receive($f, $poId, $sp, 4);
    t_eq(8.0, (float) Sparepart::find($sp)['stock'], 'Stok bertambah sesuai penerimaan parsial (4 → 8)');
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_PARTIAL, (string) $po['status'], 'PO berstatus Sebagian Diterima');
    $item = PurchaseOrder::items($poId)[0];
    t_eq(4.0, (float) $item['received_qty'], 'Akumulasi diterima = 4');
    t_eq(2.0, (float) $item['remaining_qty'], 'Sisa PO = 6 − 4 = 2');

    // 4. Kelebihan penerimaan di atas sisa SELALU ditolak; stok tak berubah.
    t_throws(
        static fn () => t_po6_receive($f, $poId, $sp, 3),
        RuntimeException::class,
        'Penerimaan melebihi sisa PO ditolak'
    );
    t_eq(8.0, (float) Sparepart::find($sp)['stock'], 'Stok tetap 8 setelah penerimaan berlebih ditolak');

    // 5. Penerimaan sisanya menutup PO.
    t_po6_receive($f, $poId, $sp, 2);
    t_eq(10.0, (float) Sparepart::find($sp)['stock'], 'Stok penuh 10 setelah sisa diterima');
    $po = PurchaseOrder::find($poId);
    t_eq(PurchaseOrder::STATUS_COMPLETE, (string) $po['status'], 'PO berstatus Selesai Diterima');
    $item = PurchaseOrder::items($poId)[0];
    t_eq(6.0, (float) $item['received_qty'], 'Total diterima = 6');
    t_eq(0.0, (float) $item['remaining_qty'], 'Sisa PO = 0');

    // 6. PO yang sudah selesai tidak dapat menerima lagi.
    t_throws(
        static fn () => t_po6_receive($f, $poId, $sp, 1),
        RuntimeException::class,
        'Penerimaan tambahan pada PO selesai ditolak'
    );
    t_eq(10.0, (float) Sparepart::find($sp)['stock'], 'Stok tetap 10');

    // 7. Pembayaran tetap terpisah: tidak ikut berubah oleh penerimaan.
    t_eq(
        PurchaseOrder::PAYMENT_UNPAID,
        (string) PurchaseOrder::find($poId)['payment_status'],
        'Status pembayaran tetap Belum Dibayar sampai Admin mengubahnya'
    );

    // Riwayat status PO lengkap: pembuatan → menunggu → disetujui →
    // diproses → sebagian → selesai.
    $history = StatusHistory::forDocument(StatusHistory::TYPE_PO, $poId);
    t_eq(6, count($history), 'Riwayat status PO lengkap enam transisi');
    t_eq(PurchaseOrder::STATUS_COMPLETE, (string) $history[count($history) - 1]['new_status'], 'Transisi terakhir Selesai Diterima');
}

function test_penerimaan_po_belum_disetujui_ditolak(): void
{
    $f = t_po6_fixture('terima');
    $sp = t_po6_part($f, [], 3);

    // PO DRAFT & MENUNGGU_PERSETUJUAN: penerimaan ditolak, stok tak berubah.
    $poDraft = t_po6_draft($f, $sp, 5);
    t_throws(
        static fn () => t_po6_receive($f, $poDraft, $sp, 2),
        RuntimeException::class,
        'Penerimaan pada PO Draf ditolak'
    );
    PurchaseOrderService::submit($poDraft, $f['partmanId']);
    t_throws(
        static fn () => t_po6_receive($f, $poDraft, $sp, 2),
        RuntimeException::class,
        'Penerimaan pada PO Menunggu persetujuan ditolak'
    );
    t_eq(3.0, (float) Sparepart::find($sp)['stock'], 'Stok tidak berubah setelah penerimaan ditolak');

    // PO DITOLAK & DIBATALKAN: penerimaan ditolak.
    $poTolak = t_po6_draft($f, $sp, 5);
    PurchaseOrderService::submit($poTolak, $f['partmanId']);
    PurchaseOrderService::reject($poTolak, 'Tidak relevan', $f['kepalaId']);
    t_throws(
        static fn () => t_po6_receive($f, $poTolak, $sp, 2),
        RuntimeException::class,
        'Penerimaan pada PO ditolak ditolak'
    );
    $poBatal = t_po6_draft($f, $sp, 5);
    PurchaseOrderService::cancel($poBatal, 'Tidak jadi diproses', $f['partmanId'], false);
    t_throws(
        static fn () => t_po6_receive($f, $poBatal, $sp, 2),
        RuntimeException::class,
        'Penerimaan pada PO dibatalkan ditolak'
    );
    t_eq(3.0, (float) Sparepart::find($sp)['stock'], 'Stok tetap 3 setelah semua penerimaan ditolak');

    // Penerimaan tanpa PO wajib beralasan.
    t_throws(
        static fn () => GoodsReceiptService::create([
            'receipt_date' => '2026-10-19',
            'receipt_type' => 'pembelian',
            'supplier_id'  => $f['supplierId'],
            'sparepart_id' => $sp,
            'quantity'     => 2,
            'unit_price'   => 10000,
        ], $f['partmanId']),
        RuntimeException::class,
        'Penerimaan tanpa PO wajib mencantumkan alasan'
    );
    t_eq(3.0, (float) Sparepart::find($sp)['stock'], 'Stok tidak berubah setelah penerimaan tanpa alasan ditolak');
}
