<?php
/**
 * Halaman impor .csv/.xlsx (FR-04, FR-05) — hanya Partman.
 *
 * @var array<string,array{title:string,headers:string[],sample:string[]}> $types
 * @var int $maxRows
 * @var string $maxBytes
 * @var bool $zipReady
 */
$formatRupiah = static function (string $label): bool {
    return str_contains(mb_strtolower($label), 'harga');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Impor Data</h2>
        <p class="text-muted small mb-0">
            Unggah berkas <code>.csv</code> atau <code>.xlsx</code>, periksa pratinjau, lalu simpan.
            Impor bersifat <strong>atomik</strong>: bila ada satu baris bermasalah, tidak ada data yang tersimpan.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('sparepart')) ?>">&laquo; Master Sparepart</a>
</div>

<div class="alert alert-secondary small">
    <ul class="mb-0 ps-3">
        <li>Maksimal <strong><?= (int) $maxRows ?> baris data</strong> dan <strong><?= e($maxBytes) ?></strong> per berkas.</li>
        <li>Kode duplikat <strong>ditolak</strong> — data lama tidak pernah ditimpa otomatis.</li>
        <li>Nilai stok pada impor master tidak ditulis diam-diam: dibuatkan pergerakan <strong>STOK_AWAL</strong>.</li>
        <li>Kolom wajib yang hilang, angka/tanggal salah, kategori atau supplier tidak dikenal akan dilaporkan per baris.</li>
    </ul>
</div>

<?php if (!$zipReady): ?>
    <div class="alert alert-warning small">
        Ekstensi PHP <code>zip</code> belum aktif, sehingga berkas <strong>.xlsx</strong> belum dapat dibaca dan
        template Excel belum dapat dibuat. Gunakan format <strong>.csv</strong> atau minta Admin mengaktifkan
        <code>extension=zip</code> (docs/INSTALL.md §4).
    </div>
<?php endif; ?>

<?php foreach ($types as $key => $info): ?>
    <div class="card border-0 shadow-sm mb-4" id="<?= e($key) ?>">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Impor <?= e($info['title']) ?></span>
            <span class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(base_url('import/template?jenis=' . urlencode($key) . '&format=csv')) ?>">Unduh Template CSV</a>
                <a class="btn btn-sm btn-outline-secondary <?= $zipReady ? '' : 'disabled' ?>"
                   href="<?= e(base_url('import/template?jenis=' . urlencode($key) . '&format=xlsx')) ?>">Unduh Template XLSX</a>
            </span>
        </div>
        <div class="card-body">
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                    <tr><th style="width:16rem">Nama Kolom</th><th style="width:6rem">Wajib</th><th>Contoh isi</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $required = $key === 'stok_awal' ? ['Kode', 'Jumlah'] : ['Kode', 'Nama', 'Kategori'];
                    foreach ($info['headers'] as $index => $header): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($header) ?></td>
                            <td>
                                <?php if (in_array($header, $required, true)): ?>
                                    <span class="badge text-bg-danger">wajib</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">opsional</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= e((string) ($info['sample'][$index] ?? '')) ?>
                                <?php if ($formatRupiah($header)): ?>
                                    <span class="ms-1">(angka, boleh <code>45.000</code>)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?= e(base_url('import/pratinjau')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="jenis" value="<?= e($key) ?>">
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" for="berkas-<?= e($key) ?>">Berkas .csv / .xlsx</label>
                    <input class="form-control form-control-sm" type="file" id="berkas-<?= e($key) ?>" name="berkas"
                           accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-navy btn-sm" type="submit">Pratinjau</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<p class="text-muted small mb-0">
    Contoh pada tabel di atas hanya ilustrasi format — template yang diunduh berisi baris header saja agar
    tidak ada data contoh yang terimpor tanpa sengaja.
</p>
