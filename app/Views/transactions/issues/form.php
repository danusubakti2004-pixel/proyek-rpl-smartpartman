<?php
/**
 * Form catat barang keluar (FR-07) — hanya Partman.
 *
 * @var array<string,mixed> $old
 * @var array<int,array<string,mixed>> $spareparts
 */
use App\Services\GoodsIssueService;

$value = static function (string $field, string $default = '') use ($old): string {
    return array_key_exists($field, $old) ? (string) ($old[$field] ?? '') : $default;
};

$dateValue = $value('issue_date', date('Y-m-d'));
$sparepartValue = $value('sparepart_id');
$usageValue = $value('usage_type', 'service');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Catat Barang Keluar</h2>
        <p class="text-muted small mb-0">
            Nomor dokumen dibuat otomatis. Sistem menolak bila jumlah melebihi stok tersedia.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('transaksi/keluar')) ?>">&laquo; Kembali</a>
</div>

<div class="row">
    <div class="col-12 col-xl-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(base_url('transaksi/keluar')) ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="issue_date">Tanggal keluar <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="issue_date" name="issue_date" required
                                   value="<?= e($dateValue) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="usage_type">Jenis penggunaan <span class="text-danger">*</span></label>
                            <select class="form-select" id="usage_type" name="usage_type" required>
                                <option value="service" <?= $usageValue === 'service' ? 'selected' : '' ?>>Service</option>
                                <option value="penjualan" <?= $usageValue === 'penjualan' ? 'selected' : '' ?>>Penjualan</option>
                                <option value="lainnya" <?= $usageValue === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="spk_number">Nomor SPK <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control" id="spk_number" name="spk_number"
                                   maxlength="<?= GoodsIssueService::SPK_MAX ?>" value="<?= e($value('spk_number')) ?>">
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="sparepart_id">Sparepart <span class="text-danger">*</span></label>
                            <select class="form-select" id="sparepart_id" name="sparepart_id" required>
                                <option value="">— pilih sparepart —</option>
                                <?php foreach ($spareparts as $sparepart): ?>
                                    <option value="<?= (int) $sparepart['id'] ?>"
                                        <?= $sparepartValue === (string) $sparepart['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $sparepart['code'] . ' — ' . (string) $sparepart['name']) ?>
                                        (stok <?= e(rtrim(rtrim(number_format((float) $sparepart['stock'], 2, ',', '.'), '0'), ',')) ?>
                                        <?= e((string) $sparepart['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="quantity">Jumlah keluar <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" required
                                   min="0.01" step="0.01" value="<?= e($value('quantity')) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="plate_number">Nomor polisi <span class="text-muted small">(opsional)</span></label>
                            <input class="form-control text-uppercase" id="plate_number" name="plate_number"
                                   maxlength="<?= GoodsIssueService::PLATE_MAX ?>" value="<?= e($value('plate_number')) ?>"
                                   placeholder="mis. B 1234 XYZ">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label" for="note">Catatan <span class="text-muted small">(opsional)</span></label>
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  maxlength="<?= GoodsIssueService::NOTE_MAX ?>"><?= e($value('note')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-navy" type="submit">Simpan Pengeluaran</button>
                        <a class="btn btn-outline-secondary" href="<?= e(base_url('transaksi/keluar')) ?>">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <h3 class="h6 text-navy">Aturan pengeluaran</h3>
                <ul class="ps-3 mb-0">
                    <li>Nomor dokumen otomatis &amp; unik (GI-YYYYMM-####).</li>
                    <li>Jumlah keluar tidak boleh melebihi stok tersedia.</li>
                    <li>Stok berkurang hanya setelah penyimpanan berhasil.</li>
                    <li>Dua pengguna yang mengeluarkan item sama secara bersamaan tetap aman (baris dikunci).</li>
                    <li>Transaksi tersimpan tidak dihapus; koreksi lewat transaksi pembalik.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
