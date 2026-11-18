<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Services\StockInitialService;
use App\Services\StockLedger;

/**
 * Pengujian Fase 4 — inti pengelolaan stok (StockLedger).
 *
 * Yang diuji (PRD §9 aturan 1-4, §10.2, §13.1):
 *  - perubahan stok & penulisan ledger terjadi dalam SATU transaksi;
 *  - stok TIDAK PERNAH negatif (permintaan berlebih ditolak & dibatalkan);
 *  - jenis pergerakan/jumlah tidak sah ditolak;
 *  - ledger bersifat append-only (tidak ada metode ubah/hapus);
 *  - pergerakan di luar transaksi database ditolak mentah-mentah.
 */

/** Jumlah baris ledger sebuah sparepart (opsional difilter jenis). */
function t_ledger_count(int $sparepartId, ?string $type = null): int
{
    $sql = 'SELECT COUNT(*) FROM stock_movements WHERE sparepart_id = ?';
    $params = [$sparepartId];
    if ($type !== null) {
        $sql .= ' AND movement_type = ?';
        $params[] = $type;
    }
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Sparepart uji dengan stok awal tertentu. @return array{0:int,1:int} [sparepartId, actorId] */
function t_ledger_fixture(float $stock = 10.0): array
{
    $categoryId = t_make_category('Kategori Ledger');
    $actorId = t_make_user('uji_ledger_' . bin2hex(random_bytes(2)), 'partman', t_password());
    $sparepartId = t_make_sparepart($categoryId, ['unit' => 'pcs']);
    if ($stock > 0) {
        StockInitialService::apply($sparepartId, $stock, null, 'Stok awal uji ledger', $actorId, 'MANUAL');
    }
    return [$sparepartId, $actorId];
}

function test_stock_ledger_wajib_di_dalam_transaksi(): void
{
    [$sparepartId] = t_ledger_fixture(5);

    t_throws(
        static fn () => StockLedger::apply(
            $sparepartId,
            StockMovement::TYPE_IN,
            1,
            null,
            'MANUAL',
            null,
            null,
            'tanpa transaksi',
            null
        ),
        LogicException::class,
        'Perubahan stok di luar transaksi database DITOLAK (pertahanan mutlak)'
    );

    t_eq(5.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok tidak berubah karena permintaan di luar transaksi ditolak');
}

function test_stock_ledger_masuk_menambah_dan_mencatat(): void
{
    [$sparepartId, $actorId] = t_ledger_fixture(10);
    $sebelum = t_ledger_count($sparepartId);

    $result = Database::transaction(static fn (): array => StockLedger::apply(
        $sparepartId,
        StockMovement::TYPE_IN,
        2.5,
        '2026-10-18',
        'MANUAL',
        null,
        null,
        'Uji masuk',
        $actorId
    ));

    t_eq(10.0, $result['stock_before'], 'Stok sebelum tercatat benar');
    t_eq(12.5, $result['stock_after'], 'Stok sesudah = sebelum + jumlah');
    t_eq(12.5, (float) Sparepart::find($sparepartId)['stock'], 'Stok sparepart bertambah di database');
    t_eq($sebelum + 1, t_ledger_count($sparepartId), 'Tepat satu baris ledger baru ditulis');

    $movement = StockMovement::find($result['movement_id']);
    t_eq(StockMovement::TYPE_IN, (string) $movement['movement_type'], 'Jenis pergerakan = MASUK');
    t_eq(2.5, (float) $movement['quantity'], 'Jumlah positif (menambah)');
    t_eq('2026-10-18', (string) $movement['movement_date'], 'Tanggal bisnis tersimpan');
    t_eq($actorId, (int) $movement['user_id'], 'Pengguna pencatat tersimpan');
}

function test_stock_ledger_keluar_mengurangi(): void
{
    [$sparepartId, $actorId] = t_ledger_fixture(12);

    $result = Database::transaction(static fn (): array => StockLedger::apply(
        $sparepartId,
        StockMovement::TYPE_OUT,
        -5,
        null,
        'MANUAL',
        null,
        null,
        'Uji keluar',
        $actorId
    ));

    t_eq(7.0, $result['stock_after'], 'Stok berkurang sesuai jumlah keluar');
    t_eq(7.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok sparepart berkurang di database');
    t_eq(-5.0, (float) StockMovement::find($result['movement_id'])['quantity'], 'Jumlah negatif (mengurangi)');
}

function test_stock_ledger_menolak_stok_negatif(): void
{
    [$sparepartId, $actorId] = t_ledger_fixture(3);
    $sebelum = t_ledger_count($sparepartId);

    t_throws(
        static fn () => Database::transaction(static fn (): array => StockLedger::apply(
            $sparepartId,
            StockMovement::TYPE_OUT,
            -5,
            null,
            'MANUAL',
            null,
            null,
            'Minta lebih dari stok',
            $actorId
        )),
        RuntimeException::class,
        'Pengeluaran melebihi stok DITOLAK'
    );

    t_eq(3.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok TIDAK berubah setelah penolakan (rollback)');
    t_eq($sebelum, t_ledger_count($sparepartId), 'Tidak ada baris ledger tertinggal dari transaksi yang gagal');
}

function test_stock_ledger_menolak_masukan_tidak_sah(): void
{
    [$sparepartId, $actorId] = t_ledger_fixture(4);
    $sebelum = t_ledger_count($sparepartId);

    t_throws(
        static fn () => Database::transaction(static fn (): array => StockLedger::apply(
            $sparepartId,
            'JENIS_NGAWUR',
            1,
            null,
            'MANUAL',
            null,
            null,
            null,
            $actorId
        )),
        RuntimeException::class,
        'Jenis pergerakan tidak dikenal ditolak'
    );

    t_throws(
        static fn () => Database::transaction(static fn (): array => StockLedger::apply(
            $sparepartId,
            StockMovement::TYPE_IN,
            0,
            null,
            'MANUAL',
            null,
            null,
            null,
            $actorId
        )),
        RuntimeException::class,
        'Jumlah pergerakan 0 ditolak'
    );

    t_throws(
        static fn () => Database::transaction(static fn (): array => StockLedger::apply(
            99999999,
            StockMovement::TYPE_IN,
            1,
            null,
            'MANUAL',
            null,
            null,
            null,
            $actorId
        )),
        RuntimeException::class,
        'Sparepart tidak ditemukan ditolak'
    );

    t_eq(4.0, (float) Sparepart::find($sparepartId)['stock'], 'Stok tetap 4 setelah semua masukan tidak sah ditolak');
    t_eq($sebelum, t_ledger_count($sparepartId), 'Tidak ada baris ledger tambahan dari masukan tidak sah');
}

function test_stock_ledger_append_only_tanpa_metode_ubah_hapus(): void
{
    $methods = array_map(
        static fn (ReflectionMethod $m): string => strtolower($m->getName()),
        (new ReflectionClass(StockMovement::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    );

    foreach (['update', 'delete', 'destroy', 'save'] as $terlarang) {
        t_true(
            !in_array($terlarang, $methods, true),
            "Model ledger tidak menyediakan metode {$terlarang}() (append-only)"
        );
    }

    t_eq('Rp 12.345', rupiah(12345), 'Format Rupiah konsisten untuk nilai transaksi');
    t_eq('12,5', StockLedger::formatQty(12.50), 'formatQty merapikan angka (12,50 → 12,5)');
    t_eq('0', StockLedger::formatQty(0), 'formatQty untuk nilai nol');
}
