<?php
declare(strict_types=1);

use App\Models\ReorderRequest;
use App\Models\Sparepart;
use App\Models\StatusHistory;
use App\Services\ReorderRequestService;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 6 — permintaan reorder (FR-15, Aturan Bisnis 14-15).
 *
 * Yang diuji:
 *  - Partman membuat permintaan (nomor RQ otomatis, langsung Menunggu
 *    persetujuan — status DRAFT tidak dipakai);
 *  - saran jumlah dari aturan stok-minimum; mengubah jumlah dari saran
 *    wajib beralasan;
 *  - Kepala Bengkel menyetujui/menolak; penolakan wajib beralasan;
 *  - setiap perubahan status tercatat: aktor, waktu, status lama → baru,
 *    alasan (purchase_status_history);
 *  - persetujuan TIDAK mengubah stok (stok hanya dari penerimaan barang).
 */

/** Fixture per uji: kategori, supplier, Partman, Kepala Bengkel, sparepart. */
function t_rq6_fixture(string $tag, float $stock, float $min): array
{
    // Nama unik per pemanggilan: t_make_user menghapus baris lama lebih dulu,
    // sedangkan baris reorder lama (FK ke users) boleh tersisa antar-pengujian.
    $u = $tag . '_' . bin2hex(random_bytes(3));
    $partmanId = t_make_user('rq6_pm_' . $u, 'partman', t_password());
    $kepalaId = t_make_user('rq6_kb_' . $u, 'kepala_bengkel', t_password());
    $categoryId = t_make_category('RQ6 ' . $tag);
    $sparepartId = t_make_sparepart($categoryId, [
        'stock'     => 0,
        'min_stock' => $min,
        'unit'      => 'pcs',
    ]);
    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-18', 'Stok awal uji reorder', $partmanId);
    }

    return [
        'partmanId'  => $partmanId,
        'kepalaId'   => $kepalaId,
        'categoryId' => $categoryId,
        'sparepart'  => $sparepartId,
    ];
}

/** Jumlah baris audit untuk aktivitas+entitas tertentu. */
function t_rq6_audit(string $activity, int $entityId): int
{
    $st = \App\Core\Database::pdo()->prepare(
        'SELECT COUNT(*) FROM audit_logs WHERE activity = ? AND entity_type = ? AND entity_id = ?'
    );
    $st->execute([$activity, 'reorder_requests', (string) $entityId]);
    return (int) $st->fetchColumn();
}

function test_reorder_partman_membuat_permintaan(): void
{
    $f = t_rq6_fixture('buat', 4, 10); // stok 4 < min 10 → saran 6

    $id = ReorderRequestService::create([
        'sparepart_id'  => $f['sparepart'],
        'requested_qty' => '6',
    ], $f['partmanId']);
    t_true($id > 0, 'Permintaan reorder tersimpan');

    $row = ReorderRequest::find($id);
    t_true($row !== null, 'Baris permintaan dapat dibaca');
    t_contains('RQ-', (string) $row['request_number'], 'Nomor dokumen memakai awalan RQ');
    t_true(
        preg_match('/^RQ-\d{6}-\d{4}$/', (string) $row['request_number']) === 1,
        'Nomor permintaan berformat RQ-YYYYMM-####'
    );
    t_eq(ReorderRequest::STATUS_PENDING, (string) $row['status'], 'Permintaan langsung berstatus Menunggu persetujuan');
    t_eq(6.0, (float) $row['suggested_qty'], 'Saran sistem = kekurangan sampai stok minimum');
    t_eq(6.0, (float) $row['requested_qty'], 'Jumlah diminta sesuai');
    t_eq(null, $row['change_reason'], 'Tanpa perubahan dari saran, alasan tidak diisi');
    t_eq($f['partmanId'], (int) $row['created_by'], 'Pembuat permintaan tercatat');
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Membuat permintaan TIDAK mengubah stok');

    $history = StatusHistory::forDocument(StatusHistory::TYPE_REORDER, $id);
    t_eq(1, count($history), 'Satu baris riwayat status tercatat saat pembuatan');
    t_eq(null, $history[0]['old_status'], 'Riwayat pembuatan: status lama null');
    t_eq(ReorderRequest::STATUS_PENDING, (string) $history[0]['new_status'], 'Riwayat pembuatan: status baru Menunggu');
    t_eq($f['partmanId'], (int) $history[0]['actor_id'], 'Riwayat pembuatan mencatat aktor');
    t_true($history[0]['created_at'] !== null, 'Riwayat pembuatan mencatat waktu');
    t_true(t_rq6_audit('REORDER_CREATE', $id) >= 1, 'Audit log memuat REORDER_CREATE');

    // Mengubah jumlah dari saran wajib beralasan.
    t_throws(
        static fn () => ReorderRequestService::create([
            'sparepart_id'  => $f['sparepart'],
            'requested_qty' => '20',
        ], $f['partmanId']),
        RuntimeException::class,
        'Permintaan dengan jumlah berbeda dari saran tanpa alasan ditolak'
    );

    $id2 = ReorderRequestService::create([
        'sparepart_id'  => $f['sparepart'],
        'requested_qty' => '20',
        'change_reason' => 'Ada proyek besar pekan ini',
    ], $f['partmanId']);
    $row2 = ReorderRequest::find($id2);
    t_eq(20.0, (float) $row2['requested_qty'], 'Jumlah berbeda dari saran diterima bila beralasan');
    t_contains('proyek besar', (string) $row2['change_reason'], 'Alasan perubahan tersimpan');
}

function test_reorder_saran_aturan_minimum(): void
{
    // Stok 4,5 & min 10 → saran dibulatkan ke atas (5,5 → 6).
    $f1 = t_rq6_fixture('saran1', 4.5, 10);
    $s1 = ReorderRequestService::suggestion($f1['sparepart']);
    t_eq(6.0, $s1['suggested_qty'], 'Saran = ceil(minimum − stok)');
    t_eq('aturan_minimum', $s1['source'], 'Sumber saran aturan minimum bila belum ada prediksi untuk sparepart ini');
    t_eq(null, $s1['prediction_id'], 'Belum ada prediksi AI terkait');

    // Stok sudah di atas minimum → tidak ada saran.
    $f2 = t_rq6_fixture('saran2', 12, 10);
    $s2 = ReorderRequestService::suggestion($f2['sparepart']);
    t_eq(null, $s2['suggested_qty'], 'Stok >= minimum tidak memberi saran');
    t_eq('tidak_ada', $s2['source'], 'Sumber saran kosong = tidak_ada');
}

function test_reorder_ubah_jumlah_wajib_alasan(): void
{
    $f = t_rq6_fixture('ubah', 4, 10);
    $id = ReorderRequestService::create([
        'sparepart_id'  => $f['sparepart'],
        'requested_qty' => '6',
    ], $f['partmanId']);

    // Tanpa alasan: ditolak.
    t_throws(
        static fn () => ReorderRequestService::updateQty($id, ['requested_qty' => '12'], $f['partmanId']),
        RuntimeException::class,
        'Mengubah jumlah dari saran tanpa alasan ditolak'
    );

    // Dengan alasan: diterima.
    ReorderRequestService::updateQty(
        $id,
        ['requested_qty' => '12', 'change_reason' => 'Permintaan bengkel cabang'],
        $f['partmanId']
    );
    $row = ReorderRequest::find($id);
    t_eq(12.0, (float) $row['requested_qty'], 'Jumlah baru tersimpan');
    t_contains('bengkel cabang', (string) $row['change_reason'], 'Alasan perubahan tersimpan');
    t_true(t_rq6_audit('REORDER_UPDATE_QTY', $id) >= 1, 'Audit log memuat REORDER_UPDATE_QTY');

    // Kembali ke angka saran: alasan tidak diperlukan.
    ReorderRequestService::updateQty($id, ['requested_qty' => '6'], $f['partmanId']);
    t_eq(6.0, (float) ReorderRequest::find($id)['requested_qty'], 'Kembali ke angka saran tanpa alasan diperbolehkan');

    // Pengaju lain tidak boleh mengubah.
    $other = t_make_user('rq6_pm_lain_' . uniqid(), 'partman', t_password());
    t_throws(
        static fn () => ReorderRequestService::updateQty($id, ['requested_qty' => '7', 'change_reason' => 'uji'], $other),
        RuntimeException::class,
        'Hanya pengaju yang dapat mengubah permintaannya'
    );
}

function test_reorder_kb_menyetujui_tanpa_ubah_stok(): void
{
    $f = t_rq6_fixture('setuju', 4, 10);
    $id = ReorderRequestService::create([
        'sparepart_id'  => $f['sparepart'],
        'requested_qty' => '6',
    ], $f['partmanId']);
    $stockBefore = (float) Sparepart::find($f['sparepart'])['stock'];

    ReorderRequestService::review($id, true, '', $f['kepalaId']);

    $row = ReorderRequest::find($id);
    t_eq(ReorderRequest::STATUS_APPROVED, (string) $row['status'], 'Permintaan disetujui Kepala Bengkel');
    t_eq($f['kepalaId'], (int) $row['reviewed_by'], 'Peninjau tercatat');
    t_true($row['reviewed_at'] !== null, 'Waktu persetujuan tercatat');
    t_eq(
        $stockBefore,
        (float) Sparepart::find($f['sparepart'])['stock'],
        'PERSETUJUAN REORDER TIDAK mengubah stok'
    );

    $history = StatusHistory::forDocument(StatusHistory::TYPE_REORDER, $id);
    t_eq(2, count($history), 'Riwayat memuat pembuatan + persetujuan');
    $last = $history[1];
    t_eq(ReorderRequest::STATUS_PENDING, (string) $last['old_status'], 'Transisi tercatat: status lama Menunggu');
    t_eq(ReorderRequest::STATUS_APPROVED, (string) $last['new_status'], 'Transisi tercatat: status baru Disetujui');
    t_eq($f['kepalaId'], (int) $last['actor_id'], 'Transisi mencatat aktor peninjau');
    t_true($last['created_at'] !== null, 'Transisi mencatat waktu');
    t_true(t_rq6_audit('REORDER_APPROVE', $id) >= 1, 'Audit log memuat REORDER_APPROVE');

    // Tidak bisa ditinjau dua kali, tidak bisa diubah setelah diputuskan.
    t_throws(
        static fn () => ReorderRequestService::review($id, true, '', $f['kepalaId']),
        RuntimeException::class,
        'Persetujuan kedua ditolak'
    );
    t_throws(
        static fn () => ReorderRequestService::updateQty($id, ['requested_qty' => '8'], $f['partmanId']),
        RuntimeException::class,
        'Jumlah tidak dapat diubah setelah diputuskan'
    );
}

function test_reorder_kb_menolak_wajib_alasan(): void
{
    $f = t_rq6_fixture('tolak', 4, 10);
    $id = ReorderRequestService::create([
        'sparepart_id'  => $f['sparepart'],
        'requested_qty' => '6',
    ], $f['partmanId']);

    t_throws(
        static fn () => ReorderRequestService::review($id, false, '', $f['kepalaId']),
        RuntimeException::class,
        'Penolakan tanpa alasan ditolak'
    );
    t_eq(
        ReorderRequest::STATUS_PENDING,
        (string) ReorderRequest::find($id)['status'],
        'Status tidak berubah setelah penolakan tanpa alasan'
    );

    ReorderRequestService::review($id, false, 'Stok penyangga masih cukup', $f['kepalaId']);
    $row = ReorderRequest::find($id);
    t_eq(ReorderRequest::STATUS_REJECTED, (string) $row['status'], 'Permintaan ditolak');
    t_contains('penyangga', (string) $row['review_note'], 'Alasan penolakan tersimpan');
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Penolakan tidak mengubah stok');

    $history = StatusHistory::forDocument(StatusHistory::TYPE_REORDER, $id);
    $last = $history[count($history) - 1];
    t_eq(ReorderRequest::STATUS_REJECTED, (string) $last['new_status'], 'Riwayat mencatat penolakan');
    t_contains('penyangga', (string) $last['reason'], 'Riwayat mencatat alasan penolakan');
    t_true(t_rq6_audit('REORDER_REJECT', $id) >= 1, 'Audit log memuat REORDER_REJECT');

    t_throws(
        static fn () => ReorderRequestService::review($id, false, 'Alasan lain', $f['kepalaId']),
        RuntimeException::class,
        'Permintaan yang sudah ditolak tidak dapat ditinjau ulang'
    );
}
