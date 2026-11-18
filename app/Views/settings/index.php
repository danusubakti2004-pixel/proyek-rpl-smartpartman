<?php
/**
 * Pengaturan aplikasi — khusus Admin (Fase 5).
 *
 * @var array<string,string> $values
 * @var array<string,mixed> $old
 */
use App\Services\SettingService;

$values = array_merge($values ?? SettingService::DEFAULTS, is_array($old ?? null) ? $old : []);
$field = static fn (string $key): string => (string) ($values[$key] ?? '');
$chatbot = is_array($chatbot ?? null) ? $chatbot : null;
$errors = [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Pengaturan Aplikasi</h1>
        <p class="text-muted small mb-0">
            Identitas bengkel dipakai pada kop laporan, Purchase Order, dan bukti transaksi.
            Parameter fast/slow moving dipakai pada laporan dan dashboard Kepala Bengkel.
        </p>
    </div>
    <span class="badge text-bg-dark align-self-center">Khusus Admin</span>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <form method="post" action="<?= e(base_url('pengaturan')) ?>" enctype="multipart/form-data"
              class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold text-navy">Identitas bengkel</div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="workshop_name">Nama bengkel <span class="text-danger">*</span></label>
                        <input class="form-control" id="workshop_name" name="workshop_name" maxlength="<?= SettingService::NAME_MAX ?>"
                               required value="<?= e($field('workshop_name')) ?>">
                        <div class="form-text">Wajib. Bila kosong, dokumen memakai nama aplikasi.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="workshop_logo">Logo bengkel</label>
                        <input class="form-control" type="file" id="workshop_logo" name="workshop_logo"
                               accept="image/png,image/jpeg">
                        <div class="form-text">PNG/JPEG, maks. 1 MB. Opsional.</div>
                    </div>
                </div>
                <?php if ($field('workshop_logo') !== ''): ?>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="<?= e(logo_url($field('workshop_logo'))) ?>" alt="Logo bengkel"
                             style="max-height:56px;max-width:120px;object-fit:contain;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                            <label class="form-check-label small" for="remove_logo">Hapus logo saat menyimpan</label>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="workshop_address">Alamat</label>
                    <textarea class="form-control" id="workshop_address" name="workshop_address"
                              rows="2" maxlength="<?= SettingService::ADDRESS_MAX ?>"><?= e($field('workshop_address')) ?></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="workshop_phone">Telepon</label>
                        <input class="form-control" id="workshop_phone" name="workshop_phone"
                               maxlength="<?= SettingService::PHONE_MAX ?>" value="<?= e($field('workshop_phone')) ?>">
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label" for="workshop_email">Email</label>
                        <input class="form-control" type="email" id="workshop_email" name="workshop_email"
                               maxlength="<?= SettingService::EMAIL_MAX ?>" value="<?= e($field('workshop_email')) ?>">
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="workshop_note">Catatan kaki dokumen</label>
                    <input class="form-control" id="workshop_note" name="workshop_note"
                           maxlength="<?= SettingService::NOTE_MAX ?>" value="<?= e($field('workshop_note')) ?>"
                           placeholder="Contoh: Barang yang sudah dibeli tidak dapat dikembalikan.">
                    <div class="form-text">Opsional, dicetak kecil di bagian bawah dokumen.</div>
                </div>
            </div>

            <div class="card-header bg-white fw-semibold text-navy border-top">Parameter laporan fast / slow moving</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="moving_period_days">Periode analisis (hari)</label>
                        <input class="form-control" type="number" id="moving_period_days" name="moving_period_days"
                               min="<?= SettingService::PERIOD_MIN ?>" max="<?= SettingService::PERIOD_MAX ?>"
                               value="<?= e($field('moving_period_days')) ?>" required>
                        <div class="form-text"><?= SettingService::PERIOD_MIN ?>–<?= SettingService::PERIOD_MAX ?> hari.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="fast_moving_threshold">Ambang cepat (qty)</label>
                        <input class="form-control" type="number" id="fast_moving_threshold" name="fast_moving_threshold"
                               min="0" max="<?= SettingService::THRESHOLD_MAX ?>"
                               value="<?= e($field('fast_moving_threshold')) ?>" required>
                        <div class="form-text">Total qty keluar ≥ nilai ini → Cepat.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="slow_moving_threshold">Ambang lambat (qty)</label>
                        <input class="form-control" type="number" id="slow_moving_threshold" name="slow_moving_threshold"
                               min="0" max="<?= SettingService::THRESHOLD_MAX ?>"
                               value="<?= e($field('slow_moving_threshold')) ?>" required>
                        <div class="form-text">Total qty keluar ≤ nilai ini → Lambat. Harus lebih kecil dari ambang cepat.</div>
                    </div>
                </div>
                <div class="alert alert-light border small mt-3 mb-0">
                    Aturan yang akan dicantumkan pada laporan:
                    <strong>Cepat</strong> ≥ <?= (int) $field('fast_moving_threshold') ?>,
                    <strong>Lambat</strong> ≤ <?= (int) $field('slow_moving_threshold') ?>,
                    selain itu <strong>Sedang</strong>, dalam <strong><?= (int) $field('moving_period_days') ?> hari</strong>.
                    Perubahan berlaku untuk laporan berikutnya dan dicatat di Audit Log.
                </div>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <span class="small text-muted">Password/API key tidak disimpan di halaman ini.</span>
                <button class="btn btn-navy" type="submit">Simpan Pengaturan</button>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Catatan keamanan</div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li class="mb-1">Hanya Admin yang dapat membuka dan mengubah pengaturan.</li>
                    <li class="mb-1">Setiap perubahan dicatat di Audit Log (nilai lama dan baru).</li>
                    <li class="mb-1">Nilai rahasia (password, API key, token) tidak pernah disimpan di sini.</li>
                    <li class="mb-1">Logo disimpan di luar web root dan disajikan lewat route ber-otorisasi.</li>
                    <li>Identitas bengkel dipakai pada semua dokumen cetak tanpa menampilkan kredensial.</li>
                </ul>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Contoh kop dokumen</div>
            <div class="card-body">
                <div class="border rounded p-2">
                    <?php if ($field('workshop_logo') !== ''): ?>
                        <img src="<?= e(logo_url($field('workshop_logo'))) ?>" alt="Logo bengkel"
                             class="mb-2" style="max-height:52px;max-width:110px;object-fit:contain;">
                    <?php endif; ?>
                    <div class="fw-bold text-navy"><?= e($field('workshop_name') !== '' ? $field('workshop_name') : 'SmartPartMan') ?></div>
                    <div class="small text-muted"><?= e($field('workshop_address') !== '' ? $field('workshop_address') : 'Alamat bengkel belum diisi') ?></div>
                    <div class="small text-muted">
                        <?= e($field('workshop_phone') !== '' ? 'Telp. ' . $field('workshop_phone') : '') ?>
                        <?= e($field('workshop_email') !== '' ? ' · ' . $field('workshop_email') : '') ?>
                    </div>
                </div>
                <div class="form-text mt-2">Pratinjau sederhana; kop sebenarnya mengikuti tata letak dokumen.</div>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Chatbot Gemini (Fase 8)</div>
            <div class="card-body small">
                <?php if ($chatbot !== null): ?>
                    <p class="mb-2">
                        Status:
                        <?php if (!empty($chatbot['is_enabled'])): ?>
                            <span class="badge text-bg-success">Aktif</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Nonaktif</span>
                        <?php endif; ?>
                        &middot; <?= e((string) ($chatbot['model_label'] ?? $chatbot['model'] ?? '-')) ?>
                    </p>
                    <p class="text-muted mb-2">
                        API key:
                        <?php if (!empty($chatbot['has_key'])): ?>
                            tersedia (<?= e((string) ($chatbot['api_key_masked'] ?? '')) ?>)
                        <?php else: ?>
                            belum diatur
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-2">Kelola API key, model, instruksi, dan batas penggunaan chatbot.</p>
                <?php endif; ?>
                <a class="btn btn-navy btn-sm w-100 mb-2" href="<?= e(base_url('pengaturan/chatbot')) ?>">Pengaturan Chatbot</a>
                <a class="btn btn-outline-secondary btn-sm w-100" href="<?= e(base_url('chatbot')) ?>">Buka Chatbot</a>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Backup &amp; keamanan data (Fase 9)</div>
            <div class="card-body small">
                <p class="text-muted mb-2">
                    Buat backup database sebelum perubahan besar. Restore hanya untuk Admin
                    dan memerlukan password ulang.
                </p>
                <a class="btn btn-outline-danger btn-sm w-100" href="<?= e(base_url('pengaturan/backup')) ?>">Backup &amp; Restore</a>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold text-navy">Tentang laporan</div>
            <div class="card-body small text-muted">
                Nilai persediaan memakai harga beli rata-rata tertimbang (weighted average) dengan fallback
                harga beli terakhir. Prediksi AI aktif melalui menu <strong>Prediksi Stok</strong>
                (dijalankan Partman, memakai ambang fast/slow moving di halaman ini); chatbot tersedia
                melalui menu <strong>Chatbot</strong>.
            </div>
        </div>
    </div>
</div>
