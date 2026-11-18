<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\GoodsIssue;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\GoodsIssueService;
use App\Services\StockInitialService;

/**
 * Pengujian Fase 4 — barang keluar / pengeluaran (FR-07).
 *
 * Yang diuji:
 *  - nomor dokumen otomatis unik (GI-YYYYMM-####);
 *  - stok berkurang + ledger KELUAR dalam satu transaksi;
 *  - pengeluaran melebihi stok tersedia DITOLAK dan seluruh transaksi
 *    dibatalkan (dokumen, item, ledger, nomor dokumen ikut kembali);
 *  - jenis penggunaan wajib salah satu dari service/penjualan/lainnya;
 *  - SPK & nomor polisi opsional (nomor polisi diseragamkan huruf besar);
 *  - sparepart nonaktif tidak dapat dikeluarkan.
 */

function t_issue_count(): int
{
    return (int) Database::pdo()->query('SELECT COUNT(*) FROM goods_issues')->fetchColumn();
}

/** @return array<string,int> */
function t_issue_fixture(float $stock = 10.0): array
{
    $categoryId = t_make_category('Kategori Pengeluaran');
    $actorId = t_make_user('uji_issue_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs']);

    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-01', 'Stok awal uji pengeluaran', $actorId, 'MANUAL');
    }

    return ['sparepart' => $sparepartId, 'actor' => $actorId, 'category' => $categoryId];
}

function test_transaction_issue_mengurangi_stok_dan_mencatat_ledger(): void
{
    $f = t_issue_fixture(10);

    $issueId = GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 4,
        'spk_number'   => 'SPK-2026-77',
        'plate_number' => 'b 1234 xyz',
        'note'         => 'Ganti oli pelanggan',
    ], $f['actor']);

    $issue = GoodsIssue::find($issueId);
    t_true($issue !== null, 'Dokumen pengeluaran tersimpan');
    t_true(
        preg_match('/^GI-\d{6}-\d{4}$/', (string) $issue['issue_number']) === 1,
        'Nomor dokumen otomatis berformat GI-YYYYMM-####'
    );
    t_eq('service', (string) $issue['usage_type'], 'Jenis penggunaan tersimpan');
    t_eq('SPK-2026-77', (string) $issue['spk_number'], 'Nomor SPK tersimpan');
    t_eq('B 1234 XYZ', (string) $issue['plate_number'], 'Nomor polisi diseragamkan HURUF BESAR');
    t_eq($f['actor'], (int) $issue['recorded_by'], 'Partman pencatat tersimpan');

    t_eq(6.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok berkurang 10 → 6');

    $movement = StockMovement::forReference('GOODS_ISSUE', $issueId);
    t_true($movement !== null, 'Pergerakan ledger KELUAR tercatat');
    t_eq(StockMovement::TYPE_OUT, (string) $movement['movement_type'], 'Jenis pergerakan = KELUAR');
    t_eq(-4.0, (float) $movement['quantity'], 'Kuantitas ledger bertanda negatif (mengurangi)');
    t_eq(10.0, (float) $movement['stock_before'], 'Ledger mencatat stok sebelum');
    t_eq(6.0, (float) $movement['stock_after'], 'Ledger mencatat stok sesudah');
    t_eq((string) $issue['issue_number'], (string) $movement['reference_number'], 'Ledger menautkan nomor dokumen');
    t_eq($f['actor'], (int) $movement['user_id'], 'Pengguna tersimpan di ledger');

    t_true(t_latest_audit('GOODS_ISSUE_CREATE', $issueId) !== null, 'Audit Log mencatat GOODS_ISSUE_CREATE');
}

function test_transaction_issue_menolak_melebihi_stok_tanpa_mengubah_apa_pun(): void
{
    $f = t_issue_fixture(3);
    $sebelumDokumen = t_issue_count();

    $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ?');
    $stmt->execute([$f['sparepart']]);
    $sebelumLedger = (int) $stmt->fetchColumn();

    $period = date('Ym', strtotime('2026-10-18'));
    $seq = Database::pdo()->prepare('SELECT last_number FROM document_sequences WHERE prefix = ? AND period = ?');
    $seq->execute(['GI', $period]);
    $counterSebelum = (int) ($seq->fetchColumn() ?: 0);

    t_throws(
        static fn () => GoodsIssueService::create([
            'issue_date'   => '2026-10-18',
            'usage_type'   => 'penjualan',
            'sparepart_id' => $f['sparepart'],
            'quantity'     => 4,
            'note'         => 'Minta lebih dari stok',
        ], $f['actor']),
        RuntimeException::class,
        'Pengeluaran melebihi stok tersedia DITOLAK'
    );

    t_eq(3.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok TIDAK berubah (rollback penuh)');
    t_eq($sebelumDokumen, t_issue_count(), 'Dokumen pengeluaran TIDAK tersimpan');

    $stmt->execute([$f['sparepart']]);
    t_eq($sebelumLedger, (int) $stmt->fetchColumn(), 'Baris ledger tidak tertinggal dari transaksi yang gagal');

    $seq->execute(['GI', $period]);
    t_eq($counterSebelum, (int) ($seq->fetchColumn() ?: 0), 'Nomor dokumen ikut dibatalkan (tidak ada nomor bolong)');

    $itemRows = (int) Database::pdo()->query('SELECT COUNT(*) FROM goods_issue_items WHERE sparepart_id = ' . (int) $f['sparepart'])->fetchColumn();
    t_eq(0, $itemRows, 'Baris item pengeluaran tidak tertinggal');
}

function test_transaction_issue_stok_tepat_habis_dan_berikutnya_ditolak(): void
{
    $f = t_issue_fixture(5);

    GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'lainnya',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 5,
    ], $f['actor']);

    t_eq(0.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Pengeluaran tepat menghabiskan stok diperbolehkan (stok = 0)');
    t_eq(Sparepart::STATUS_HABIS, Sparepart::stockStatusOf(Sparepart::find($f['sparepart'])), 'Status berubah menjadi Habis');

    t_throws(
        static fn () => GoodsIssueService::create([
            'issue_date'   => '2026-10-18',
            'usage_type'   => 'service',
            'sparepart_id' => $f['sparepart'],
            'quantity'     => 1,
        ], $f['actor']),
        RuntimeException::class,
        'Pengeluaran dari stok 0 DITOLAK (stok tidak boleh negatif)'
    );

    t_eq(0.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tetap 0 setelah penolakan');
}

function test_transaction_issue_dua_penyimpanan_berurutan_tidak_negatif(): void
{
    $f = t_issue_fixture(10);

    GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 6,
    ], $f['actor']);
    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Pengeluaran pertama menyisakan 4');

    // Pengguna kedua "melihat" sisa 4 → permintaan 6 ditolak, bukan menghasilkan -2.
    t_throws(
        static fn () => GoodsIssueService::create([
            'issue_date'   => '2026-10-18',
            'usage_type'   => 'service',
            'sparepart_id' => $f['sparepart'],
            'quantity'     => 6,
        ], $f['actor']),
        RuntimeException::class,
        'Penyimpanan kedua yang melebihi sisa stok DITOLAK'
    );

    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok akhir tetap 4, tidak pernah negatif');

    $ledger = Database::pdo()->prepare(
        "SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ? AND movement_type = 'KELUAR'"
    );
    $ledger->execute([$f['sparepart']]);
    t_eq(1, (int) $ledger->fetchColumn(), 'Hanya satu pergerakan KELUAR yang tercatat');
}

function test_transaction_issue_validasi_masukan(): void
{
    $f = t_issue_fixture(4);
    $base = [
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'service',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 1,
    ];

    $kasus = [
        'Jenis penggunaan kosong ditolak'   => ['usage_type' => ''],
        'Jenis penggunaan asing ditolak'    => ['usage_type' => 'jual_bebas'],
        'Jumlah 0 ditolak'                  => ['quantity' => 0],
        'Jumlah negatif ditolak'            => ['quantity' => -2],
        'Jumlah melebihi batas ditolak'     => ['quantity' => GoodsIssueService::QTY_MAX + 1],
        'Tanggal tidak valid ditolak'       => ['issue_date' => '31/02/2026'],
        'Tanggal jauh ke depan ditolak'     => ['issue_date' => '2099-05-05'],
        'Sparepart kosong ditolak'          => ['sparepart_id' => 0],
        'SPK terlalu panjang ditolak'       => ['spk_number' => str_repeat('S', GoodsIssueService::SPK_MAX + 1)],
        'Nomor polisi terlalu panjang'      => ['plate_number' => str_repeat('B', GoodsIssueService::PLATE_MAX + 1)],
        'Catatan terlalu panjang ditolak'   => ['note' => str_repeat('N', GoodsIssueService::NOTE_MAX + 1)],
    ];

    foreach ($kasus as $label => $override) {
        t_throws(
            static fn () => GoodsIssueService::create($override + $base, $f['actor']),
            RuntimeException::class,
            $label
        );
    }

    t_throws(
        static fn () => GoodsIssueService::create(['sparepart_id' => 99999999] + $base, $f['actor']),
        RuntimeException::class,
        'Sparepart yang tidak ada ditolak'
    );

    $nonaktif = t_make_sparepart($f['category'], ['is_active' => 0, 'stock' => 5]);
    t_throws(
        static fn () => GoodsIssueService::create(['sparepart_id' => $nonaktif] + $base, $f['actor']),
        RuntimeException::class,
        'Sparepart nonaktif tidak dapat dikeluarkan'
    );

    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok tidak berubah setelah semua masukan tidak sah ditolak');
}

function test_transaction_issue_semua_jenis_penggunaan_diterima(): void
{
    foreach ([GoodsIssue::USAGE_SERVICE, GoodsIssue::USAGE_SALE, GoodsIssue::USAGE_OTHER] as $usage) {
        $f = t_issue_fixture(2);
        $issueId = GoodsIssueService::create([
            'issue_date'   => '2026-10-18',
            'usage_type'   => $usage,
            'sparepart_id' => $f['sparepart'],
            'quantity'     => 1,
        ], $f['actor']);

        t_eq($usage, (string) GoodsIssue::find($issueId)['usage_type'], "Jenis penggunaan '{$usage}' diterima & tersimpan");
        t_eq(1.0, (float) Sparepart::find($f['sparepart'])['stock'], "Stok berkurang untuk jenis '{$usage}'");
    }
}

function test_transaction_issue_daftar_dan_filter(): void
{
    $f = t_issue_fixture(20);
    $actor = $f['actor'];

    $issueId = GoodsIssueService::create([
        'issue_date'   => '2026-10-18',
        'usage_type'   => 'penjualan',
        'sparepart_id' => $f['sparepart'],
        'quantity'     => 2,
        'plate_number' => 'D 9999 XX',
    ], $actor);

    $byUsage = GoodsIssue::paginate(['usage_type' => 'penjualan'], 1, 5);
    t_true($byUsage['total'] >= 1, 'Filter jenis penggunaan mengembalikan data');
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $byUsage['rows']);
    t_true(in_array($issueId, $ids, true), 'Dokumen yang baru dibuat muncul pada filter jenisnya');

    $bySearch = GoodsIssue::paginate(['search' => 'D 9999 XX'], 1, 5);
    $searchIds = array_map(static fn (array $r): int => (int) $r['id'], $bySearch['rows']);
    t_true(in_array($issueId, $searchIds, true), 'Pencarian berdasarkan nomor polisi menemukan dokumen');

    $byDate = GoodsIssue::paginate(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], 1, 100);
    $dateIds = array_map(static fn (array $r): int => (int) $r['id'], $byDate['rows']);
    t_true(in_array($issueId, $dateIds, true), 'Filter rentang tanggal menemukan dokumen');

    $farPast = GoodsIssue::paginate(['date_from' => '2001-01-01', 'date_to' => '2001-01-02'], 1, 5);
    $farIds = array_map(static fn (array $r): int => (int) $r['id'], $farPast['rows']);
    t_true(!in_array($issueId, $farIds, true), 'Dokumen di luar rentang tanggal tidak muncul');

    $row = GoodsIssue::find($issueId);
    t_eq(2.0, (float) $row['total_quantity'], 'Daftar menampilkan total kuantitas dokumen');
    t_eq((string) Sparepart::find($f['sparepart'])['code'], (string) $row['sparepart_code'], 'Daftar menampilkan kode sparepart');
}
