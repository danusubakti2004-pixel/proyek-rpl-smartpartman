<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\GoodsIssueService;
use App\Services\DocumentNumberService;
use App\Services\StockInitialService;
use App\Services\StockLedger;

/**
 * Pengujian Fase 4 — dua transaksi bersamaan pada sparepart yang sama
 * (PRD §10.2 "cegah race condition", §13.1).
 *
 * Pengujian ini memakai DUA koneksi PDO sungguhan (koneksi aplikasi + koneksi
 * "pengguna kedua") sehingga penguncian baris InnoDB benar-benar diuji:
 *  - baris sparepart yang sedang diubah terkunci → penyimpan lain menunggu
 *    dan gagal dengan "lock wait timeout" (bukannya menimpa nilai lama);
 *  - perubahan yang belum di-commit tidak terlihat oleh koneksi lain;
 *  - setelah commit, penyimpan kedua membaca stok TERBARU sehingga permintaan
 *    yang melebihi sisa ditolak (stok tidak pernah negatif, tidak ada
 *    pembaruan yang hilang/lost update);
 *  - penomoran dokumen memakai kunci baris counter yang sama sehingga dua
 *    penyimpan bersamaan tidak pernah memperoleh nomor yang sama.
 */

/** Koneksi "pengguna kedua" (timeout kunci 1 detik agar pengujian cepat). */
function t_concurrency_pdo(): PDO
{
    $c = Database::config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) ($c['host'] ?? '127.0.0.1'),
        (int) ($c['port'] ?? 3306),
        (string) ($c['name'] ?? ''),
        (string) ($c['charset'] ?? 'utf8mb4')
    );

    $pdo = new PDO($dsn, (string) ($c['user'] ?? 'root'), (string) ($c['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

    return $pdo;
}

/** Pastikan transaksi koneksi ditutup walau pengujian gagal. */
function t_concurrency_rollback(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        // diabaikan
    }
}

/** @return array<string,int> */
function t_concurrency_fixture(float $stock = 0.0): array
{
    $categoryId = t_make_category('Kategori Bersamaan');
    $actorId = t_make_user('uji_bersamaan_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs']);

    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, '2026-10-01', 'Stok awal uji bersamaan', $actorId, 'MANUAL');
    }

    return ['sparepart' => $sparepartId, 'actor' => $actorId];
}

/** Stok sparepart dibaca lewat koneksi tertentu. */
function t_concurrency_stock(PDO $pdo, int $sparepartId): float
{
    $stmt = $pdo->prepare('SELECT stock FROM spareparts WHERE id = ?');
    $stmt->execute([$sparepartId]);

    return (float) $stmt->fetchColumn();
}

function test_transaction_concurrency_kunci_baris_sparepart(): void
{
    $f = t_concurrency_fixture(0);
    $utama = Database::pdo();
    $kedua = t_concurrency_pdo();

    try {
        // Transaksi "pengguna pertama" mengubah stok (baris sparepart terkunci).
        $utama->beginTransaction();
        StockLedger::apply(
            $f['sparepart'],
            StockMovement::TYPE_IN,
            10,
            '2026-10-18',
            'MANUAL',
            null,
            null,
            'Uji bersamaan',
            $f['actor']
        );

        // Penyimpan kedua mencoba mengunci baris yang sama → timeout.
        $kedua->beginTransaction();
        t_throws(
            static fn () => $kedua->query('SELECT stock FROM spareparts WHERE id = ' . (int) $f['sparepart'] . ' FOR UPDATE')->fetchColumn(),
            PDOException::class,
            'Koneksi kedua menunggu kunci baris yang sama lalu gagal (lock wait timeout)'
        );
        t_concurrency_rollback($kedua);

        // Perubahan yang belum di-commit belum terlihat oleh koneksi lain.
        t_eq(0.0, t_concurrency_stock($kedua, $f['sparepart']), 'Perubahan yang belum di-commit tidak terlihat oleh pengguna lain');

        $utama->commit();
    } finally {
        t_concurrency_rollback($utama);
    }

    t_eq(10.0, t_concurrency_stock($kedua, $f['sparepart']), 'Setelah commit, pengguna lain melihat stok terbaru (10)');
}

function test_transaction_concurrency_tidak_ada_stok_negatif(): void
{
    $f = t_concurrency_fixture(10);
    $kedua = t_concurrency_pdo();

    // "Pengguna kedua" mengeluarkan 6 unit lebih dulu (10 → 4) dan commit.
    $kedua->beginTransaction();
    $stmt = $kedua->prepare('SELECT stock FROM spareparts WHERE id = ? FOR UPDATE');
    $stmt->execute([$f['sparepart']]);
    $stokDibaca = (float) $stmt->fetchColumn();
    t_eq(10.0, $stokDibaca, 'Pengguna kedua membaca stok terbaru di bawah kunci');

    $kedua->prepare('UPDATE spareparts SET stock = ? WHERE id = ?')->execute([$stokDibaca - 6, $f['sparepart']]);
    $kedua->prepare(
        'INSERT INTO stock_movements
            (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after, reference_type, note, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $f['sparepart'], 'KELUAR', -6, '2026-10-18', $stokDibaca, $stokDibaca - 6, 'GOODS_ISSUE', 'Pengeluaran pengguna kedua', $f['actor'],
    ]);
    $kedua->commit();

    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Pengeluaran pertama mengurangi stok menjadi 4');

    // Pengguna pertama (proses utama) meminta 6 → harus ditolak karena stok 4.
    t_throws(
        static fn () => GoodsIssueService::create([
            'issue_date'   => '2026-10-18',
            'usage_type'   => 'service',
            'sparepart_id' => $f['sparepart'],
            'quantity'     => 6,
        ], $f['actor']),
        RuntimeException::class,
        'Transaksi kedua ditolak setelah membaca stok terbaru (tidak ada lost update)'
    );

    t_eq(4.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Stok akhir 4 — tidak pernah negatif');

    $ledger = Database::pdo()->prepare('SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ?');
    $ledger->execute([$f['sparepart']]);
    t_eq(2, (int) $ledger->fetchColumn(), 'Ledger hanya berisi stok awal + pengeluaran pertama (tidak ada baris dari transaksi gagal)');
}

function test_transaction_concurrency_penomoran_dokumen_tidak_duplikat(): void
{
    $utama = Database::pdo();
    $kedua = t_concurrency_pdo();
    $period = date('Ym', strtotime('2026-10-18'));

    try {
        // Pengguna pertama mengambil nomor (mengunci baris counter, belum commit).
        $utama->beginTransaction();
        $nomorA = DocumentNumberService::next('GR', '2026-10-18');

        // Pengguna kedua tidak bisa menaikkan counter yang sama secara bersamaan.
        $kedua->beginTransaction();
        t_throws(
            static fn () => $kedua
                ->query("SELECT last_number FROM document_sequences WHERE prefix = 'GR' AND period = '{$period}' FOR UPDATE")
                ->fetchColumn(),
            PDOException::class,
            'Penomoran dokumen mengunci baris counter (penyimpan kedua harus menunggu)'
        );
        t_concurrency_rollback($kedua);

        $utama->commit();

        // Setelah commit, pengguna kedua membaca counter terbaru → nomor berikutnya pasti berbeda.
        $kedua->beginTransaction();
        $lastNumber = (int) $kedua
            ->query("SELECT last_number FROM document_sequences WHERE prefix = 'GR' AND period = '{$period}' FOR UPDATE")
            ->fetchColumn();
        $kedua->commit();
    } finally {
        t_concurrency_rollback($utama);
        t_concurrency_rollback($kedua);
    }

    t_eq(
        (int) substr($nomorA, -4),
        $lastNumber,
        'Counter yang terlihat pengguna kedua = nomor yang baru saja terpakai'
    );

    // Nomor berikutnya dari nomorA pasti berbeda (tanpa duplikasi).
    $nomorB = Database::transaction(static fn () => DocumentNumberService::next('GR', '2026-10-18'));
    t_true($nomorB !== $nomorA, 'Nomor dokumen berikutnya selalu berbeda dari nomor sebelumnya');
    t_eq((int) substr($nomorA, -4) + 1, (int) substr($nomorB, -4), 'Nomor berikutnya berurutan +1 (tidak ada tabrakan)');
}

function test_transaction_concurrency_koreksi_bersamaan_tidak_melebihi_asal(): void
{
    $f = t_concurrency_fixture(0);
    $utama = Database::pdo();

    // Pergerakan asal: MASUK 5 (stok 0 → 5).
    $masukId = Database::transaction(static fn (): int => StockLedger::apply(
        $f['sparepart'],
        StockMovement::TYPE_IN,
        5,
        '2026-10-18',
        'MANUAL',
        null,
        'GR-SERENTAK',
        'Uji koreksi bersamaan',
        $f['actor']
    )['movement_id']);

    try {
        // Pengguna pertama mengoreksi 4 (masih dalam transaksi, baris terkunci).
        $utama->beginTransaction();
        \App\Services\CorrectionService::correct($masukId, ['quantity' => 4, 'reason' => 'Koreksi pengguna pertama'], $f['actor']);

        $kedua = t_concurrency_pdo();
        $kedua->beginTransaction();
        t_throws(
            static fn () => $kedua
                ->query('SELECT stock FROM spareparts WHERE id = ' . (int) $f['sparepart'] . ' FOR UPDATE')
                ->fetchColumn(),
            PDOException::class,
            'Koreksi bersamaan pada sparepart yang sama diserialisasi oleh kunci baris'
        );
        t_concurrency_rollback($kedua);

        $utama->commit();
    } finally {
        t_concurrency_rollback($utama);
    }

    // Stok akhir turun 5 → 1; total pembalikan tepat 4 (tidak melebihi asal).
    t_eq(1.0, (float) Sparepart::find($f['sparepart'])['stock'], 'Setelah koreksi, stok 5 → 1');
    t_eq(1.0, StockLedger::remainingReversible(StockMovement::find($masukId)), 'Sisa yang dapat dibalik = 1 (5 - 4)');

    $total = 0.0;
    foreach (StockMovement::reversalsOf($masukId) as $row) {
        $total += abs((float) $row['quantity']);
    }
    t_eq(4.0, $total, 'Total pembalikan tidak melebihi transaksi asal');
}
