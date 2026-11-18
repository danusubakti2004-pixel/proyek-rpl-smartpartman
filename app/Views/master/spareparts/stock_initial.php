<?php
/**
 * Form input stok awal manual (FR-05) — hanya Partman.
 *
 * @var array<string,mixed> $row sparepart
 * @var array<string,mixed>|null $initial catatan stok awal (bila sudah ada)
 * @var array<string,mixed> $old
 */
$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};
$value = static function (string $field) use ($old): string {
    return (string) ($old[$field] ?? '');
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Input Stok Awal</h2>
        <p class="text-muted small mb-0">
            <?= e((string) $row['code']) ?> — <?= e((string) $row['name']) ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('sparepart/' . (int) $row['id'])) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <?php if ($initial !== null): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        Stok awal sparepart ini <strong>sudah pernah dicatat</strong> pada
                        <?= e(tanggal_id((string) ($initial['movement_date'] ?? $initial['created_at']))) ?>
                        sejumlah <?= e($qty($initial['quantity'])) ?> <?= e((string) $row['unit']) ?>.
                        Stok awal tidak dapat diterapkan dua kali untuk item yang sama.
                    </div>
                    <p class="small text-muted mb-0">
                        Bila angka perlu dikoreksi, gunakan <strong>penyesuaian opname</strong> (FR-10, tersedia pada
                        Fase 4) supaya selisihnya tetap tercatat sebagai pergerakan stok yang sah — bukan dengan
                        menimpa catatan lama.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="alert alert-secondary small">
                        Stok awal dicatat sebagai pergerakan <strong>STOK_AWAL</strong> (bukan pembelian) dan
                        langsung menambah stok sparepart dalam satu transaksi. Setelah tersimpan, pencatatan hanya
                        dapat dilakukan <strong>sekali</strong>.
                    </div>

                    <form method="post" action="<?= e(base_url('sparepart/' . (int) $row['id'] . '/stok-awal')) ?>" autocomplete="off">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label" for="quantity">Jumlah Stok Awal <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input class="form-control" id="quantity" name="quantity" required inputmode="decimal"
                                       value="<?= e($value('quantity')) ?>" placeholder="mis. 24">
                                <span class="input-group-text"><?= e((string) $row['unit']) ?></span>
                            </div>
                            <div class="form-text">Harus lebih dari 0. Stok saat ini: <?= e($qty($row['stock'])) ?>.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="movement_date">Tanggal Pencatatan</label>
                            <input class="form-control" id="movement_date" name="movement_date"
                                   value="<?= e($value('movement_date')) ?>" placeholder="<?= e(date('d/m/Y')) ?>">
                            <div class="form-text">Tanggal bisnis (mis. tanggal hitung fisik). Kosongkan untuk hari ini.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="note">Catatan <span class="text-muted small">(opsional)</span></label>
                            <textarea class="form-control" id="note" name="note" rows="2" maxlength="255"
                                      placeholder="mis. hasil hitung fisik awal gudang"><?= e($value('note')) ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-navy" type="submit">Catat Stok Awal</button>
                            <a class="btn btn-outline-secondary" href="<?= e(base_url('sparepart/' . (int) $row['id'])) ?>">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
