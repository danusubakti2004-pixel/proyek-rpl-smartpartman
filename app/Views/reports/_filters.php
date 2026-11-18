<?php
/**
 * Form filter laporan (partial).
 *
 * @var array<string,mixed> $report
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $suppliers
 * @var string $action
 */
use App\Services\ReportService;

$report = $report ?? [];
$categories = $categories ?? [];
$suppliers = $suppliers ?? [];
$action = $action ?? base_url('laporan');
$filters = $report['filters'] ?? [];
$meta = $report['meta'] ?? [];
$allowed = $meta['filters'] ?? [];
$type = (string) ($report['type'] ?? '');
$statusGroup = match ($type) {
    'stok', 'stok_minimum' => 'stock',
    'opname' => 'opname',
    'riwayat' => 'movement',
    'pembelian' => 'po',
    'pembayaran' => 'payment',
    default => 'status',
};
$today = date('Y-m-d');
$presetQuery = static function (string $from, string $to) use ($filters): string {
    $merged = array_merge($filters, ['date_from' => $from, 'date_to' => $to]);
    $clean = array_filter($merged, static fn ($v): bool => $v !== null && $v !== '');
    return http_build_query($clean);
};
?>
<form class="card border-0 shadow-sm mb-3 report-filter" method="get" action="<?= e($action) ?>">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <?php if (!empty($allowed['date'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="date_from">Dari tanggal</label>
                    <input class="form-control form-control-sm" type="date" id="date_from" name="date_from"
                           value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="date_to">Sampai tanggal</label>
                    <input class="form-control form-control-sm" type="date" id="date_to" name="date_to"
                           value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['category'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="category_id">Kategori</label>
                    <select class="form-select form-select-sm" id="category_id" name="category_id">
                        <option value="">Semua</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"
                                <?= (int) ($filters['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                                <?= e($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['supplier'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="supplier_id">Supplier</label>
                    <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                        <option value="">Semua</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= (int) $supplier['id'] ?>"
                                <?= (int) ($filters['supplier_id'] ?? 0) === (int) $supplier['id'] ? 'selected' : '' ?>>
                                <?= e($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['search'])): ?>
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label" for="search">Kode / nama / dokumen</label>
                    <input class="form-control form-control-sm" type="search" id="search" name="search"
                           value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Cari…">
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['active'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="active">Status data</label>
                    <select class="form-select form-select-sm" id="active" name="active">
                        <option value="">Semua</option>
                        <option value="aktif" <?= ($filters['active'] ?? '') === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                        <option value="nonaktif" <?= ($filters['active'] ?? '') === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['status'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Semua</option>
                        <?php foreach ($allowed['status'] as $option): ?>
                            <option value="<?= e((string) $option) ?>"
                                <?= ($filters['status'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= e(ReportService::label($statusGroup, $option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['usage'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="usage">Jenis pemakaian</label>
                    <select class="form-select form-select-sm" id="usage" name="usage">
                        <option value="">Semua</option>
                        <?php foreach ($allowed['usage'] as $option): ?>
                            <option value="<?= e((string) $option) ?>"
                                <?= ($filters['usage'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= e(ReportService::label('usage', $option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['payment'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="payment">Pembayaran</label>
                    <select class="form-select form-select-sm" id="payment" name="payment">
                        <option value="">Semua</option>
                        <?php foreach ($allowed['payment'] as $option): ?>
                            <option value="<?= e((string) $option) ?>"
                                <?= ($filters['payment'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= e(ReportService::label('payment', $option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($allowed['classification'])): ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="classification">Klasifikasi</label>
                    <select class="form-select form-select-sm" id="classification" name="classification">
                        <option value="">Semua</option>
                        <?php foreach (['fast', 'medium', 'slow'] as $option): ?>
                            <option value="<?= e($option) ?>"
                                <?= ($filters['classification'] ?? '') === $option ? 'selected' : '' ?>>
                                <?= e(ReportService::label('classification', $option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-12 col-xl-auto d-flex flex-wrap gap-2">
                <button class="btn btn-navy btn-sm" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e($action) ?>">Reset</a>
            </div>
        </div>

        <?php if (!empty($allowed['date'])): ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="small text-muted align-self-center">Cepat:</span>
                <a class="btn btn-outline-secondary btn-sm py-0"
                   href="?<?= e($presetQuery($today, $today)) ?>">Hari ini</a>
                <a class="btn btn-outline-secondary btn-sm py-0"
                   href="?<?= e($presetQuery(date('Y-m-01'), $today)) ?>">Bulan ini</a>
                <a class="btn btn-outline-secondary btn-sm py-0"
                   href="?<?= e($presetQuery(date('Y-m-d', strtotime('-29 days')), $today)) ?>">30 hari</a>
            </div>
        <?php endif; ?>
    </div>
</form>
