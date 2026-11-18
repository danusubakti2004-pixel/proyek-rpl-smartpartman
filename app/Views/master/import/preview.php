<?php
/**
 * Pratinjau hasil validasi impor (FR-04) — belum ada data yang tersimpan.
 *
 * @var array<string,mixed> $preview
 * @var string $token
 */
use App\Services\MasterImportService;

$summary = $preview['summary'];
$hasError = (int) $summary['error'] > 0;
$missing = $preview['missing_required'];
$rows = $preview['rows'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 text-navy mb-0">Pratinjau Impor — <?= e(MasterImportService::titleOf((string) $preview['type'])) ?></h2>
        <p class="text-muted small mb-0">
            Berkas: <strong><?= e((string) $preview['file']) ?></strong>
            &middot; dibaca: <?= (int) $summary['total'] ?> baris data
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('import')) ?>">&laquo; Unggah Berkas Lain</a>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Baris terbaca', $summary['total'], 'secondary'],
        ['Valid', $summary['ok'], 'success'],
        ['Bermasalah', $summary['error'], $hasError ? 'danger' : 'secondary'],
    ] as [$label, $value, $color]): ?>
        <div class="col-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small"><?= e($label) ?></div>
                    <div class="fs-4 fw-semibold text-<?= e($color) ?>"><?= (int) $value ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($missing !== []): ?>
    <div class="alert alert-danger">
        Kolom wajib belum ada pada berkas: <strong><?= e(implode(', ', $missing)) ?></strong>.
        Unduh template, isi ulang, lalu unggah kembali.
    </div>
<?php endif; ?>

<?php if (!empty($preview['unknown'])): ?>
    <div class="alert alert-secondary small">
        Kolom berikut diabaikan karena tidak dikenali:
        <?= e(implode(', ', array_map('strval', $preview['unknown']))) ?>.
    </div>
<?php endif; ?>

<?php if ($hasError): ?>
    <div class="alert alert-warning">
        Terdapat <strong><?= (int) $summary['error'] ?></strong> baris bermasalah.
        Proses simpan <strong>dinonaktifkan</strong>: impor hanya dijalankan bila seluruh baris valid, sehingga
        tidak ada data yang tersimpan sebagian. Perbaiki berkas lalu unggah ulang.
    </div>
<?php else: ?>
    <div class="alert alert-success">
        Seluruh <?= (int) $summary['ok'] ?> baris valid. Menekan <strong>Simpan Semua</strong> akan menulis data
        dalam <strong>satu transaksi</strong>.
    </div>
    <form method="post" action="<?= e(base_url('import/komit')) ?>" class="mb-3"
          data-confirm="Simpan <?= (int) $summary['ok'] ?> baris ke database?">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn-navy" type="submit">Simpan Semua (<?= (int) $summary['ok'] ?> baris)</button>
    </form>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th style="width:4.5rem">Baris</th>
                <th style="width:7rem">Status</th>
                <th>Ringkasan Data</th>
                <th>Alasan / Catatan</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $ok = (bool) $row['ok'];
                $cells = $row['cells'];
                $label = trim((string) ($cells['kode'] ?? ''));
                if ((string) $preview['type'] === MasterImportService::TYPE_SPAREPART) {
                    $label .= ($cells['nama'] ?? '') !== '' ? ' — ' . (string) $cells['nama'] : '';
                    $extra = [];
                    if (($cells['kategori'] ?? '') !== '') {
                        $extra[] = 'kategori: ' . (string) $cells['kategori'];
                    }
                    if (($cells['stok'] ?? '') !== '') {
                        $extra[] = 'stok awal: ' . (string) $cells['stok'];
                    }
                    if (($cells['harga_beli'] ?? '') !== '') {
                        $extra[] = 'harga beli: ' . (string) $cells['harga_beli'];
                    }
                    $label .= $extra !== [] ? ' (' . implode('; ', $extra) . ')' : '';
                } else {
                    $extra = [];
                    if (($cells['jumlah'] ?? '') !== '') {
                        $extra[] = 'jumlah: ' . (string) $cells['jumlah'];
                    }
                    if (($cells['tanggal'] ?? '') !== '') {
                        $extra[] = 'tanggal: ' . (string) $cells['tanggal'];
                    }
                    $label .= $extra !== [] ? ' (' . implode('; ', $extra) . ')' : '';
                }
                ?>
                <tr class="<?= $ok ? '' : 'table-danger' ?>">
                    <td class="small text-muted"><?= (int) $row['line'] ?></td>
                    <td>
                        <?php if ($ok): ?>
                            <span class="badge text-bg-success">Valid</span>
                            <?php if (!empty($row['values']['sparepart_id'])): ?>
                                <div class="small text-muted">
                                    <?= e((string) ($row['values']['sparepart_code'] ?? '')) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge text-bg-danger">Bermasalah</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($label) ?></td>
                    <td class="small">
                        <?php if ($ok): ?>
                            <span class="text-muted">siap diimpor</span>
                        <?php else: ?>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($row['errors'] as $error): ?>
                                    <li><?= e((string) $error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
