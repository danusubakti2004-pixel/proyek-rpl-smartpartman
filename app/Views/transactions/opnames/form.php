<?php
/**
 * Form memulai sesi stock opname (FR-10) — hanya Partman.
 *
 * @var array<string,mixed> $old
 * @var bool $hasOpenSession
 */
use App\Services\StockOpnameService;

$value = static function (string $field, string $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) ($old[$field] ?? '') : $default;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Mulai Stock Opname</h2>
        <p class="text-muted small mb-0">
            Sistem membuat nomor opname unik dan menyimpan <strong>snapshot stok sistem</strong> seluruh sparepart aktif.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/opname')) ?>">&laquo; Kembali</a>
</div>

<?php if ($hasOpenSession): ?>
    <div class="alert alert-warning" role="alert">
        Masih ada sesi stock opname yang <strong>belum dikonfirmasi</strong>.
        Konfirmasi sesi tersebut sebelum memulai sesi baru.
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12 col-lg-8 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(base_url('transaksi/opname')) ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="opname_date">Tanggal opname <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="opname_date" name="opname_date" required
                               value="<?= e($value('opname_date', date('Y-m-d'))) ?>" <?= $hasOpenSession ? 'disabled' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="note">Catatan sesi <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="note" name="note" rows="3"
                                  maxlength="<?= StockOpnameService::NOTE_MAX ?>"
                                  <?= $hasOpenSession ? 'disabled' : '' ?>><?= e($value('note')) ?></textarea>
                        <div class="form-text">Catatan sesi dipakai sebagai alasan cadangan bila ada penyesuaian tanpa alasan item.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-navy" type="submit" <?= $hasOpenSession ? 'disabled' : '' ?>>Mulai Sesi</button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('transaksi/opname')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4 col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Alur stock opname</h3>
                <ol class="ps-3 mb-0">
                    <li>Buat sesi (nomor otomatis, snapshot stok sistem disimpan).</li>
                    <li>Isi jumlah fisik per item — selisih dihitung otomatis.</li>
                    <li>Alasan <strong>wajib</strong> diisi untuk setiap item berselisih.</li>
                    <li>Simpan hasil hitung (stok belum berubah).</li>
                    <li>Konfirmasi → sistem membuat pergerakan penyesuaian &amp; memperbarui stok.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
