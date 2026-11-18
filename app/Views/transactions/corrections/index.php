<?php
/**
 * Daftar transaksi yang masih dapat dikoreksi (PRD §7.4) — Partman.
 *
 * @var bool $canManage
 * @var array<int,array<string,mixed>> $rows
 */
use App\Models\StockMovement;
use App\Services\StockLedger;

$typeLabel = static function (string $type): string {
    return $type === StockMovement::TYPE_IN ? 'Masuk' : 'Keluar';
};

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Koreksi Transaksi</h2>
        <p class="text-muted small mb-0">
            Transaksi tersimpan <strong>tidak dihapus</strong>. Koreksi dibuat sebagai pergerakan pembalik
            baru dengan alasan, pengguna, waktu, dan referensi transaksi asal.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/riwayat')) ?>">Riwayat Stok</a>
</div>

<div class="alert alert-info small" role="status">
    Hanya pergerakan <strong>MASUK</strong> dan <strong>KELUAR</strong> yang dapat dikoreksi di sini.
    Untuk menyesuaikan stok karena hasil hitung fisik, gunakan <strong>Stock Opname</strong>.
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Tgl bisnis</th>
                <th>Sparepart</th>
                <th>Jenis</th>
                <th class="text-end">Jumlah asli</th>
                <th class="text-end">Sudah dibalik</th>
                <th class="text-end">Sisa</th>
                <th>Dokumen</th>
                <th class="text-end">Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-center text-muted small py-4">
                    Tidak ada transaksi yang masih dapat dikoreksi.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $original = abs(round((float) $row['quantity'], 2));
                $remaining = StockLedger::remainingReversible($row);
                $reversed = round($original - $remaining, 2);
                ?>
                <tr>
                    <td class="small text-muted"><?= (int) $row['id'] ?></td>
                    <td class="small"><?= e(tanggal_id((string) ($row['movement_date'] ?? $row['created_at']))) ?></td>
                    <td class="small">
                        <span class="fw-semibold"><?= e((string) $row['sparepart_code']) ?></span>
                        <div class="text-muted"><?= e((string) $row['sparepart_name']) ?></div>
                    </td>
                    <td><span class="badge text-bg-<?= (string) $row['movement_type'] === StockMovement::TYPE_IN ? 'success' : 'danger' ?>">
                        <?= e($typeLabel((string) $row['movement_type'])) ?>
                    </span></td>
                    <td class="text-end"><?= e($qty($original)) ?> <?= e((string) $row['unit']) ?></td>
                    <td class="text-end small"><?= e($qty($reversed)) ?></td>
                    <td class="text-end fw-semibold"><?= e($qty($remaining)) ?></td>
                    <td class="small"><?= e((string) ($row['reference_number'] ?? '-')) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-danger"
                           href="<?= e(base_url('transaksi/koreksi/' . (int) $row['id'])) ?>">Koreksi</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
