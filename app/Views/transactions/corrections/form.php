<?php
/**
 * Form koreksi transaksi (PRD §7.4) — Partman.
 *
 * @var bool $canManage
 * @var array<string,mixed> $row
 * @var float $remaining
 * @var bool $correctable
 * @var array<string,mixed> $old
 */
use App\Models\StockMovement;
use App\Services\CorrectionService;
use App\Services\StockLedger;

$value = static function (string $field, string $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) ($old[$field] ?? '') : $default;
};

$isIn = (string) $row['movement_type'] === StockMovement::TYPE_IN;
$original = abs((float) $row['quantity']);

$qty = static function (float|int|string|null $value): string {
    return rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Koreksi Transaksi #<?= (int) $row['id'] ?></h2>
        <p class="text-muted small mb-0">
            Membuat pergerakan pembalik bertipe <strong>KOREKSI</strong>. Transaksi asli tetap tersimpan.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/koreksi')) ?>">&laquo; Kembali</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Transaksi Asal</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 col-md-4">Baris ledger</dt>
                    <dd class="col-7 col-md-8">#<?= (int) $row['id'] ?></dd>

                    <dt class="col-5 col-md-4">Sparepart</dt>
                    <dd class="col-7 col-md-8"><?= e((string) $row['sparepart_code'] . ' — ' . (string) $row['sparepart_name']) ?></dd>

                    <dt class="col-5 col-md-4">Jenis</dt>
                    <dd class="col-7 col-md-8"><?= $isIn ? 'Masuk (menambah stok)' : 'Keluar (mengurangi stok)' ?></dd>

                    <dt class="col-5 col-md-4">Jumlah asli</dt>
                    <dd class="col-7 col-md-8"><?= e($qty($original)) ?> <?= e((string) $row['unit']) ?></dd>

                    <dt class="col-5 col-md-4">Tanggal bisnis</dt>
                    <dd class="col-7 col-md-8"><?= e(tanggal_id((string) ($row['movement_date'] ?? $row['created_at']))) ?></dd>

                    <dt class="col-5 col-md-4">Dokumen sumber</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['reference_number'] ?? '-')) ?></dd>

                    <dt class="col-5 col-md-4">Stok saat itu</dt>
                    <dd class="col-7 col-md-8">
                        <?= e($qty($row['stock_before'])) ?> &rarr; <?= e($qty($row['stock_after'])) ?>
                    </dd>

                    <dt class="col-5 col-md-4">Sudah dibalik</dt>
                    <dd class="col-7 col-md-8"><?= e(StockLedger::formatQty($original - $remaining)) ?> <?= e((string) $row['unit']) ?></dd>

                    <dt class="col-5 col-md-4">Sisa dapat dibalik</dt>
                    <dd class="col-7 col-md-8 fw-semibold"><?= e(StockLedger::formatQty($remaining)) ?> <?= e((string) $row['unit']) ?></dd>

                    <dt class="col-5 col-md-4">Catatan asli</dt>
                    <dd class="col-7 col-md-8"><?= e((string) ($row['note'] ?? '-')) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($correctable): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold text-navy">Data Koreksi</div>
                <div class="card-body">
                    <form method="post" action="<?= e(base_url('transaksi/koreksi/' . (int) $row['id'])) ?>"
                          autocomplete="off"
                          data-confirm="Simpan koreksi ini? Stok akan berubah dan tercatat di Audit Log.">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="quantity">Jumlah yang dikoreksi <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" required
                                   min="0.01" step="0.01" value="<?= e($value('quantity', StockLedger::formatQty($remaining))) ?>">
                            <div class="form-text">
                                Maksimal <?= e(StockLedger::formatQty($remaining)) ?> <?= e((string) $row['unit']) ?>.
                                <?= $isIn ? 'Koreksi akan mengurangi stok.' : 'Koreksi akan menambah stok.' ?>
                                Stok tidak boleh menjadi negatif.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="reason">Alasan koreksi <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required
                                      minlength="<?= CorrectionService::REASON_MIN ?>"
                                      maxlength="<?= CorrectionService::REASON_MAX ?>"><?= e($value('reason')) ?></textarea>
                            <div class="form-text">Minimal <?= CorrectionService::REASON_MIN ?> karakter; tersimpan di ledger &amp; Audit Log.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-danger" type="submit">Simpan Koreksi</button>
                            <a class="btn btn-outline-secondary" href="<?= e(base_url('transaksi/koreksi')) ?>">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning" role="alert">
                Transaksi ini tidak dapat dikoreksi lewat pembalik
                <?= $remaining <= 0 ? '(sudah dibalik seluruhnya)' : '(jenis pergerakan tidak mendukung koreksi)' ?>.
                Gunakan <strong>Stock Opname</strong> untuk menyesuaikan stok.
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-5">
        <?php $reversals = StockMovement::reversalsOf((int) $row['id']); ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy">Riwayat Koreksi Transaksi Ini</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Waktu</th>
                        <th class="text-end">Jumlah</th>
                        <th>Oleh</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($reversals === []): ?>
                        <tr><td colspan="4" class="text-center text-muted small py-3">Belum ada koreksi.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($reversals as $reversal): ?>
                        <tr>
                            <td class="small"><?= (int) $reversal['id'] ?></td>
                            <td class="small"><?= e(tanggal_id((string) $reversal['created_at'])) ?></td>
                            <td class="text-end small"><?= e($qty($reversal['quantity'])) ?></td>
                            <td class="small"><?= e((string) ($reversal['user_name'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Koreksi tercatat di Audit Log dengan nilai sebelum/sesudah.
            </div>
        </div>
    </div>
</div>
