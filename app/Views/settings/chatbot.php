<?php
/**
 * Pengaturan AI Chatbot — khusus Admin (Fase 8, multi-provider).
 *
 * Tampilan mengikuti komponen standar aplikasi (Bootstrap 5 + app.css):
 * judul h4 text-navy, card border-0 shadow-sm, card-header bg-white,
 * form Bootstrap, badge text-bg-*, tombol btn-navy.
 *
 * @var array<string,mixed> $settings
 * @var array<string,array<string,mixed>> $providers
 * @var array<string,mixed> $usage
 * @var array<string,mixed> $old
 * @var array<string,string> $models
 * @var array<string,string> $styles
 * @var array<string,mixed> $local
 */
use App\Services\ChatbotSettingsService;

$settings = is_array($settings ?? null) ? $settings : [];
$old = is_array($old ?? null) ? $old : [];
$models = is_array($models ?? null) ? $models : ChatbotSettingsService::MODELS;
$styles = is_array($styles ?? null) ? $styles : ChatbotSettingsService::ANSWER_STYLES;
$usage = is_array($usage ?? null) ? $usage : ['summary' => [], 'recent' => []];
$providers = is_array($providers ?? null) ? $providers : (is_array($settings['providers'] ?? null) ? $settings['providers'] : []);
$local = is_array($local ?? null) ? $local : [];
$summary = is_array($usage['summary'] ?? null) ? $usage['summary'] : [];
$recent = is_array($usage['recent'] ?? null) ? $usage['recent'] : [];
$today = is_array($summary['today'] ?? null) ? $summary['today'] : [];
$month = is_array($summary['month'] ?? null) ? $summary['month'] : [];

$hasOld = $old !== [];
$value = static function (string $key, mixed $default = '') use ($old, $settings, $hasOld): mixed {
    if ($hasOld && array_key_exists($key, $old)) {
        return $old[$key];
    }
    return $settings[$key] ?? $default;
};
$enabled = $hasOld ? !empty($old['is_enabled']) : !empty($settings['is_enabled']);
$activeProvider = (string) $value('provider', $settings['provider'] ?? 'gemini');
if ($providers === []) {
    foreach (ChatbotSettingsService::providerCatalog() as $code => $catalog) {
        $isActive = $code === $activeProvider;
        $providers[$code] = [
            'code'           => $code,
            'label'          => (string) $catalog['label'],
            'protocol'       => (string) $catalog['protocol'],
            'models'         => (array) $catalog['models'],
            'default_model'  => (string) $catalog['default_model'],
            'default_base'   => (string) $catalog['default_base'],
            'docs_url'       => (string) $catalog['docs_url'],
            'key_hint'       => (string) $catalog['key_hint'],
            'has_key'        => $isActive ? !empty($settings['has_key']) : false,
            'api_key_source' => $isActive ? (string) ($settings['api_key_source'] ?? 'none') : 'none',
            'api_key_masked' => $isActive ? (string) ($settings['api_key_masked'] ?? '') : '',
            'key_error'      => null,
            'model'          => $isActive ? (string) ($settings['model'] ?? $catalog['default_model']) : (string) $catalog['default_model'],
            'model_label'    => $isActive ? (string) ($settings['model_label'] ?? $catalog['default_model']) : (string) $catalog['default_model'],
            'api_base'       => $isActive ? (string) ($settings['api_base'] ?? '') : '',
            'updated_at'     => $isActive ? ($settings['updated_at'] ?? null) : null,
        ];
    }
}
if (!array_key_exists($activeProvider, $providers)) {
    $activeProvider = array_key_first($providers) ?: 'gemini';
}
$multiProvider = (bool) ($settings['multi_provider'] ?? true);
$model = (string) $value('model', $settings['model'] ?? ChatbotSettingsService::MODEL_DEFAULT);
$style = (string) $value('answer_style', 'ringkas');
if (!array_key_exists($style, $styles)) {
    $style = 'ringkas';
}
$prompt = (string) $value('system_prompt', ChatbotSettingsService::DEFAULT_PROMPT);
$topics = (string) $value('topic_limits', ChatbotSettingsService::DEFAULT_TOPICS);
$daily = $value('usage_limit_daily', '');
$monthly = $value('usage_limit_monthly', '');
$activeState = $providers[$activeProvider] ?? [];

$sourceLabels = [
    'database' => 'Database (terenkripsi)',
    'env'      => 'File .env (GEMINI_API_KEY)',
    'none'     => 'Belum diatur',
];
$statusBadges = [
    'success'        => ['Berhasil', 'success'],
    'data'           => ['Data lokal', 'info'],
    'failed'         => ['Gagal', 'danger'],
    'disabled'       => ['Nonaktif', 'secondary'],
    'limit'          => ['Batas', 'warning'],
    'off_topic'      => ['Luar topik', 'secondary'],
    'blocked'        => ['Ditolak', 'danger'],
    'role_scope'     => ['Hak akses', 'warning'],
    'not_configured' => ['Belum diatur', 'warning'],
];
?>
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a class="text-decoration-none" href="<?= e(base_url('pengaturan')) ?>">Admin</a></li>
        <li class="breadcrumb-item active" aria-current="page">Pengaturan AI Chatbot</li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 text-navy mb-1">Pengaturan AI Chatbot</h1>
        <p class="text-muted small mb-0">
            Kelola provider AI, API key terenkripsi, model, koneksi database lokal, dan penggunaan chatbot.
            Hanya Admin yang dapat membuka halaman ini.
        </p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge text-bg-dark">Khusus Admin</span>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('monitoring/api')) ?>">Monitoring API</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('chatbot')) ?>">Buka Chatbot</a>
    </div>
</div>

<?php if (!empty($settings['key_error'])): ?>
    <div class="alert alert-warning border small mb-3" role="alert"><?= e((string) $settings['key_error']) ?></div>
<?php endif; ?>
<?php if (!$multiProvider): ?>
    <div class="alert alert-warning border small mb-3" role="alert">
        Penyimpanan beberapa provider belum siap. Jalankan migrasi <code>019_chatbot_multi_provider.sql</code>
        agar pilihan DeepSeek dan OpenAI dapat digunakan. Gemini tetap berfungsi seperti biasa.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(base_url('pengaturan/chatbot')) ?>" id="spAiSettingsForm">
    <?= csrf_field() ?>

    <div class="row g-3">
        <!-- ================= Status layanan & provider ================= -->
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>Status Layanan &amp; Provider</span>
                    <span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>" id="spAiServiceBadge">
                        <?= $enabled ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_enabled"
                               name="is_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_enabled">
                            Aktifkan layanan AI
                            <span class="d-block form-text">
                                <?= $enabled ? 'Chatbot dapat digunakan sesuai role pengguna.' : 'Chatbot menolak pertanyaan sampai layanan diaktifkan.' ?>
                            </span>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="provider">Provider AI aktif</label>
                        <select class="form-select" id="provider" name="provider">
                            <?php foreach ($providers as $code => $p): ?>
                                <?php $sourceKey = (string) ($p['api_key_source'] ?? 'none'); ?>
                                <option value="<?= e($code) ?>"
                                        <?= $code === $activeProvider ? 'selected' : '' ?>
                                        <?= (!$multiProvider && $code !== 'gemini') ? 'disabled' : '' ?>
                                        data-label="<?= e((string) ($p['label'] ?? $code)) ?>"
                                        data-model="<?= e((string) ($p['model'] ?? '')) ?>"
                                        data-masked="<?= e((string) ($p['api_key_masked'] ?? '')) ?>"
                                        data-has-key="<?= !empty($p['has_key']) ? '1' : '0' ?>"
                                        data-source="<?= e($sourceLabels[$sourceKey] ?? 'Tidak diketahui') ?>"
                                        data-updated="<?= e((string) ($p['updated_at'] ?? '-')) ?>">
                                    <?= e((string) ($p['label'] ?? $code)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Mengganti provider tidak menghapus API key provider lain. Setiap provider menyimpan
                            kunci, model, dan Base URL sendiri.
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th scope="col">Provider</th>
                                <th scope="col">Model</th>
                                <th scope="col">API key</th>
                                <th scope="col">Sumber</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($providers as $code => $p): ?>
                                <tr<?= $code === $activeProvider ? ' class="table-light"' : '' ?>>
                                    <td class="small fw-semibold text-navy">
                                        <?= e((string) ($p['label'] ?? $code)) ?>
                                        <?php if ($code === $activeProvider): ?>
                                            <span class="badge text-bg-primary ms-1">Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= e((string) ($p['model'] ?? '-')) ?></td>
                                    <td class="small">
                                        <?php if (!empty($p['has_key'])): ?>
                                            <span class="badge text-bg-success">API Key Tersimpan</span>
                                            <span class="text-muted"><?= e((string) ($p['api_key_masked'] ?? '')) ?></span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Belum diatur</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= e($sourceLabels[(string) ($p['api_key_source'] ?? 'none')] ?? 'Tidak diketahui') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                            <strong>Provider aktif: <span id="spAiActiveProvider"><?= e((string) ($activeState['label'] ?? 'Gemini')) ?></span></strong>
                            <span class="badge <?= !empty($activeState['has_key']) ? 'text-bg-success' : 'text-bg-warning' ?>" id="spAiKeyBadge">
                                <?= !empty($activeState['has_key']) ? 'API Key Tersimpan' : 'Belum Tersimpan' ?>
                            </span>
                        </div>
                        <div>Key tersamarkan: <span id="spAiKeyMasked"><?= !empty($activeState['api_key_masked']) ? e((string) $activeState['api_key_masked']) : 'Belum ada' ?></span></div>
                        <div>Sumber kunci: <span id="spAiKeySource"><?= e($sourceLabels[(string) ($activeState['api_key_source'] ?? 'none')] ?? 'Tidak diketahui') ?></span></div>
                        <div>Terakhir diperbarui: <span id="spAiUpdated"><?= e((string) ($activeState['updated_at'] ?? '-')) ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= API key & model ================= -->
        <div class="col-12 col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>API Key &amp; Model</span>
                    <span class="badge text-bg-secondary" id="spAiPanelProvider"><?= e((string) ($activeState['label'] ?? 'Gemini')) ?></span>
                </div>
                <div class="card-body">
                    <?php foreach ($providers as $code => $p): ?>
                        <?php
                        $isActivePanel = $code === $activeProvider;
                        $pModels = is_array($p['models'] ?? null) ? $p['models'] : [];
                        $pModel = (string) ($p['model'] ?? '');
                        $pBase = (string) ($p['api_base'] ?? '');
                        $pDisabled = !$isActivePanel;
                        $pLabel = (string) ($p['label'] ?? $code);
                        ?>
                        <div data-provider-panel="<?= e($code) ?>" <?= $isActivePanel ? '' : 'hidden' ?>>
                            <div class="mb-3">
                                <label class="form-label" for="api_key_<?= e($code) ?>">API Key <?= e($pLabel) ?></label>
                                <div class="input-group">
                                    <input class="form-control" type="password" id="api_key_<?= e($code) ?>" name="api_key"
                                           autocomplete="new-password" spellcheck="false"
                                           maxlength="<?= ChatbotSettingsService::KEY_MAX ?>"
                                           placeholder="Biarkan kosong untuk mempertahankan key lama"
                                           <?= $pDisabled ? 'disabled' : '' ?>>
                                    <button class="btn btn-outline-secondary" type="button" data-eye="api_key_<?= e($code) ?>"
                                            aria-label="Tampilkan atau sembunyikan API key <?= e($pLabel) ?>"
                                            title="Tampilkan / sembunyikan API key" <?= $pDisabled ? 'disabled' : '' ?>>👁</button>
                                </div>
                                <div class="form-text">
                                    Status:
                                    <?php if (!empty($p['has_key'])): ?>
                                        <span class="badge text-bg-success">API Key Tersimpan</span>
                                        <?= e((string) ($p['api_key_masked'] ?? '')) ?>
                                        &middot; <?= e($sourceLabels[(string) ($p['api_key_source'] ?? 'none')] ?? 'Tidak diketahui') ?>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Belum tersimpan</span>
                                    <?php endif; ?>
                                    <br>
                                    Minimal <?= ChatbotSettingsService::KEY_MIN ?> karakter. Kosongkan bila tidak ingin mengubah key.
                                    Kunci tidak dikirim ke browser setelah disimpan.
                                </div>
                                <?php if (!empty($p['has_key'])): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="remove_api_key_<?= e($code) ?>"
                                               name="remove_api_key" value="1" <?= $pDisabled ? 'disabled' : '' ?>>
                                        <label class="form-check-label small text-danger" for="remove_api_key_<?= e($code) ?>">
                                            Hapus API key <?= e($pLabel) ?> tersimpan
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="model_<?= e($code) ?>">Nama model</label>
                                <?php if ($code === 'gemini'): ?>
                                    <select class="form-select" id="model_<?= e($code) ?>" name="model" <?= $pDisabled ? 'disabled' : '' ?>>
                                        <?php foreach ($pModels as $id => $label): ?>
                                            <option value="<?= e($id) ?>" <?= $id === $pModel ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control" type="text" id="model_<?= e($code) ?>" name="model"
                                           list="models_<?= e($code) ?>" value="<?= e($pModel) ?>"
                                           maxlength="120" spellcheck="false" <?= $pDisabled ? 'disabled' : '' ?>>
                                    <datalist id="models_<?= e($code) ?>">
                                        <?php foreach ($pModels as $id => $label): ?>
                                            <option value="<?= e($id) ?>"><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>
                                <div class="form-text">Nama model harus tersedia untuk akun/API key <?= e($pLabel) ?>.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="api_base_<?= e($code) ?>">Base URL</label>
                                <input class="form-control" type="url" id="api_base_<?= e($code) ?>" name="api_base"
                                       value="<?= e($pBase) ?>" maxlength="255" spellcheck="false"
                                       placeholder="<?= e((string) ($p['default_base'] ?? '')) ?>"
                                       <?= $pDisabled ? 'disabled' : '' ?>>
                                <div class="form-text">
                                    Wajib HTTPS. Kosongkan untuk memakai default:
                                    <code><?= e((string) ($p['default_base'] ?? '')) ?></code>
                                </div>
                            </div>

                            <div class="alert alert-light border small mb-2">
                                <strong>API key dienkripsi AES-256-GCM</strong> sebelum disimpan. Setelah tersimpan,
                                key tidak dikirim ke browser, tidak ditulis ke HTML/JavaScript, dan tidak pernah muncul di log.
                            </div>
                            <div class="form-text">
                                Butuh API key?
                                <a href="<?= e((string) ($p['docs_url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e((string) ($p['key_hint'] ?? 'Buka halaman provider')) ?>
                                </a>.
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    <!-- ================= Mode hybrid & asisten lokal ================= -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Mode Hybrid &amp; Asisten Database Lokal</span>
                <span class="badge text-bg-success">Aktif</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-5">
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Asisten database lokal</span>
                                <span class="badge <?= !empty($local['assistant_active']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= !empty($local['assistant_active']) ? 'Aktif' : 'Nonaktif' ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Mode hybrid</span>
                                <span class="badge <?= !empty($local['hybrid_active']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= !empty($local['hybrid_active']) ? 'Aktif' : 'Nonaktif' ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Batas konteks</span>
                                <span><?= number_format((int) ($local['context_chars'] ?? 0), 0, ',', '.') ?> karakter</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Koneksi database</span>
                                <span>
                                    <span class="badge <?= ($local['db_status'] ?? '') === 'ok' ? 'text-bg-success' : 'text-bg-warning' ?>">
                                        <?= e(strtoupper((string) ($local['db_status'] ?? 'unknown'))) ?>
                                    </span>
                                    <?= e((string) ($local['db_name'] ?? '-')) ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Status migrasi</span>
                                <span class="text-muted"><?= e((string) ($local['migrations'] ?? 'unknown')) ?></span>
                            </li>
                        </ul>
                    </div>
                    <div class="col-12 col-lg-7">
                        <p class="fw-semibold text-navy small mb-1">Cara kerja asisten lokal</p>
                        <ul class="small text-muted ps-3 mb-3">
                            <li class="mb-1">Pertanyaan angka, daftar, stok, kategori, dan transaksi dijawab <strong>langsung dari database</strong> agar sama dengan Dashboard.</li>
                            <li class="mb-1">Mode hybrid memakai <strong>data lokal deterministik</strong> untuk angka dan model AI hanya untuk penjelasan/prosedur.</li>
                            <li class="mb-1">Konteks dibatasi <strong><?= number_format((int) ($local['context_chars'] ?? 0), 0, ',', '.') ?> karakter</strong> dan disaring sesuai role login.</li>
                            <li><?= e((string) ($local['role_scope'] ?? '')) ?></li>
                        </ul>
                        <div class="row g-2 small">
                            <div class="col-12 col-md-4">
                                <div class="fw-semibold text-navy">Dashboard &amp; stok</div>
                                <div class="text-muted">Angka transaksi, status stok, kategori, dan sparepart mengikuti filter periode Dashboard.</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="fw-semibold text-navy">Transaksi &amp; pembelian</div>
                                <div class="text-muted">Barang masuk, barang keluar, opname, koreksi, reorder, PO, dan penerimaan dibaca dari data tersimpan.</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="fw-semibold text-navy">Laporan, pengguna &amp; notifikasi</div>
                                <div class="text-muted">Laporan dan prediksi lokal; pengguna serta pengaturan WhatsApp hanya untuk Admin sesuai role.</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Hak akses data: baca-saja, tanpa SQL dan tanpa perubahan transaksi.
                            Versi server: <?= e((string) (($local['db_version'] ?? '') !== '' ? $local['db_version'] : '-')) ?>.
                            Ditampilkan: <?= e((string) ($local['time'] ?? '-')) ?>.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <!-- ================= Pengaturan penggunaan AI ================= -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold text-navy">Pengaturan Penggunaan AI</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="system_prompt">Instruksi sistem <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="system_prompt" name="system_prompt" rows="4"
                                  maxlength="<?= ChatbotSettingsService::PROMPT_MAX ?>" required><?= e($prompt) ?></textarea>
                        <div class="form-text">
                            Aturan keras (topik, keamanan, larangan SQL/rahasia) tetap ditambahkan server.
                            Maksimal <?= ChatbotSettingsService::PROMPT_MAX ?> karakter.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="topic_limits">Batas topik</label>
                        <textarea class="form-control" id="topic_limits" name="topic_limits" rows="2"
                                  maxlength="<?= ChatbotSettingsService::TOPICS_MAX ?>"><?= e($topics) ?></textarea>
                        <div class="form-text">Dapat dikosongkan. Topik di luar SmartPartMan tetap ditolak oleh pengaman server.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="answer_style">Gaya jawaban</label>
                            <select class="form-select" id="answer_style" name="answer_style">
                                <?php foreach ($styles as $id => $label): ?>
                                    <option value="<?= e($id) ?>" <?= $id === $style ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="usage_limit_daily">Batas harian</label>
                            <input class="form-control" type="number" id="usage_limit_daily" name="usage_limit_daily"
                                   min="0" max="<?= ChatbotSettingsService::LIMIT_MAX ?>" value="<?= e((string) $daily) ?>"
                                   placeholder="Tanpa batas">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="usage_limit_monthly">Batas bulanan</label>
                            <input class="form-control" type="number" id="usage_limit_monthly" name="usage_limit_monthly"
                                   min="0" max="<?= ChatbotSettingsService::LIMIT_MAX ?>" value="<?= e((string) $monthly) ?>"
                                   placeholder="Tanpa batas">
                        </div>
                    </div>
                    <div class="form-text">0 atau kosong berarti tanpa batas. Kuota dihitung dari panggilan API sukses + gagal.</div>
                </div>
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small text-muted">Perubahan dicatat di Audit Log tanpa nilai API key.</span>
                    <button class="btn btn-navy" type="submit">Simpan Pengaturan</button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mt-0">
    <!-- ================= Monitoring penggunaan ================= -->
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Pemakaian AI</span>
                <span class="badge text-bg-secondary">Hari ini</span>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="card card-stat shadow-sm h-100">
                            <div class="card-body py-2">
                                <div class="stat-label">Total pesan</div>
                                <div class="stat-value"><?= (int) ($today['total'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card card-stat shadow-sm h-100">
                            <div class="card-body py-2">
                                <div class="stat-label">Berhasil</div>
                                <div class="stat-value"><?= (int) ($today['success'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card card-stat shadow-sm h-100">
                            <div class="card-body py-2">
                                <div class="stat-label">Gagal API</div>
                                <div class="stat-value"><?= (int) ($today['failed'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card card-stat shadow-sm h-100">
                            <div class="card-body py-2">
                                <div class="stat-label">Ditolak / dibatasi</div>
                                <div class="stat-value"><?= (int) ($today['blocked'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-2">
                    Bulan ini: <strong><?= (int) ($month['total'] ?? 0) ?></strong> pesan,
                    token masuk <strong><?= (int) ($month['prompt_tokens'] ?? 0) ?></strong>,
                    token keluar <strong><?= (int) ($month['response_tokens'] ?? 0) ?></strong>.
                </p>
                <?php if (is_array($summary['last_error'] ?? null)): ?>
                    <div class="alert alert-danger small mb-0">
                        Error terakhir: <?= e((string) ($summary['last_error']['message'] ?? '')) ?>
                        <span class="text-muted">(<?= e((string) ($summary['last_error']['created_at'] ?? '')) ?>)</span>
                    </div>
                <?php endif; ?>
                <a class="btn btn-outline-secondary btn-sm mt-3" href="<?= e(base_url('monitoring/api')) ?>">Lihat Monitoring API</a>
            </div>
        </div>
    </div>

    <!-- ================= Uji koneksi ================= -->
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Uji Koneksi Provider</span>
                <span class="badge text-bg-secondary">Tanpa menyimpan key</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Menguji API key dan model tanpa menghasilkan jawaban (tidak memakai kuota generasi).
                    Bila kolom key dikosongkan, key tersimpan atau konfigurasi .env yang diuji. Key yang diuji tidak disimpan.
                </p>
                <form method="post" action="<?= e(base_url('pengaturan/chatbot/uji')) ?>">
                    <?= csrf_field() ?>
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="test_provider">Provider</label>
                            <select class="form-select" id="test_provider" name="provider">
                                <?php foreach ($providers as $code => $p): ?>
                                    <option value="<?= e($code) ?>" <?= $code === $activeProvider ? 'selected' : '' ?>><?= e((string) ($p['label'] ?? $code)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="test_model">Model</label>
                            <input class="form-control" type="text" id="test_model" name="model"
                                   value="<?= e($model) ?>" maxlength="120" spellcheck="false" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="api_key_test">API key untuk diuji</label>
                            <input class="form-control" type="password" id="api_key_test" name="api_key_test"
                                   autocomplete="new-password" spellcheck="false"
                                   maxlength="<?= ChatbotSettingsService::KEY_MAX ?>"
                                   placeholder="Kosongkan untuk key tersimpan">
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button class="btn btn-navy" type="submit">Uji Koneksi</button>
                        </div>
                    </div>
                </form>
                <div class="alert alert-light border small mb-0">
                    Provider aktif: <strong><?= e((string) ($activeState['label'] ?? 'Gemini')) ?></strong>
                    &middot; Model: <code><?= e($model) ?></code>.
                    Hasil uji tampil sebagai notifikasi di bagian atas halaman.
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Privasi & keamanan ================= -->
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold text-navy">Privasi &amp; Keamanan</div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li class="mb-1">Konteks yang dikirim ke provider hanya <strong>ringkasan agregat</strong> sesuai role; tanpa password, API key, atau data pengguna mentah.</li>
                    <li class="mb-1">API key disimpan terenkripsi dan hanya ditampilkan tersamarkan. Plaintext tidak pernah dikirim kembali ke browser.</li>
                    <li class="mb-1">Chatbot tidak memiliki akses SQL dan tidak dapat mengubah transaksi.</li>
                    <li class="mb-1">Provider pihak ketiga dapat memakai konten untuk peningkatan produk sesuai kebijakannya. Jangan memasukkan data pribadi ke pertanyaan.</li>
                    <li class="mb-1">Riwayat percakapan di layar tidak dikirim ulang ke provider.</li>
                    <li>Token Fonnte dan konfigurasi layanan lain tidak diubah oleh halaman ini.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ================= FAQ & catatan ================= -->
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold text-navy">FAQ &amp; Catatan Konfigurasi</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-navy">Biarkan key kosong</dt>
                    <dd class="col-sm-8 text-muted">Key lama tetap dipakai; hanya key yang diisi yang diganti.</dd>
                    <dt class="col-sm-4 text-navy">Ganti provider</dt>
                    <dd class="col-sm-8 text-muted">Hanya provider aktif yang disimpan. Key provider lain tidak berubah.</dd>
                    <dt class="col-sm-4 text-navy">Base URL</dt>
                    <dd class="col-sm-8 text-muted">Wajib HTTPS. Kosongkan untuk default resmi provider.</dd>
                    <dt class="col-sm-4 text-navy">Batas pemakaian</dt>
                    <dd class="col-sm-8 text-muted">Kosong atau 0 berarti tanpa batas. Hitungan per panggilan API.</dd>
                    <dt class="col-sm-4 text-navy">Data lokal</dt>
                    <dd class="col-sm-8 text-muted">Angka dashboard dan daftar selalu diambil server dari database.</dd>
                </dl>
                <hr>
                <p class="fw-semibold text-navy mb-1">Prediksi stok (lokal)</p>
                <p class="text-muted mb-2">
                    Prediksi memakai Python, Pandas, dan Scikit-learn secara lokal tanpa API key eksternal.
                    Algoritma Moving Average dan Linear Regression; periode 7/30/90 hari dengan riwayat 30–365 hari.
                </p>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('prediksi')) ?>">Buka Modul Prediksi Stok</a>
            </div>
        </div>
    </div>

    <!-- ================= Riwayat penggunaan ================= -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold text-navy d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Riwayat Penggunaan &amp; Error</span>
                <span class="small text-muted">20 terakhir &middot; tanpa isi jawaban &amp; tanpa rahasia</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Waktu</th>
                            <th>Pengguna</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th>Potongan pertanyaan</th>
                            <th>Token</th>
                            <th>Durasi</th>
                            <th>Error</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($recent === []): ?>
                            <tr><td colspan="8" class="text-center text-muted small py-4">Belum ada penggunaan chatbot.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $log): ?>
                                <?php
                                $status = (string) ($log['status'] ?? '');
                                $badge = $statusBadges[$status] ?? ['Tidak dikenal', 'secondary'];
                                $userLabel = trim((string) ($log['full_name'] ?? '')) !== ''
                                    ? (string) $log['full_name']
                                    : (string) ($log['username'] ?? '-');
                                ?>
                                <tr>
                                    <td class="small text-nowrap"><?= e((string) ($log['created_at'] ?? '')) ?></td>
                                    <td class="small">
                                        <?= e($userLabel) ?>
                                        <div class="text-muted"><?= e((string) ($log['role'] ?? '')) ?></div>
                                    </td>
                                    <td class="small"><?= e((string) ($log['model'] ?? '-')) ?></td>
                                    <td><span class="badge text-bg-<?= e($badge[1]) ?>"><?= e($badge[0]) ?></span></td>
                                    <td class="small"><?= e((string) ($log['question_preview'] ?? '')) ?></td>
                                    <td class="small text-nowrap">
                                        <?= $log['prompt_tokens'] !== null ? (int) $log['prompt_tokens'] : '-' ?>
                                        /
                                        <?= $log['response_tokens'] !== null ? (int) $log['response_tokens'] : '-' ?>
                                    </td>
                                    <td class="small text-nowrap"><?= $log['duration_ms'] !== null ? (int) $log['duration_ms'] . ' ms' : '-' ?></td>
                                    <td class="small text-danger"><?= e((string) ($log['error_message'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var form = document.getElementById('spAiSettingsForm');
    if (!form) { return; }

    var providerSelect = document.getElementById('provider');
    var panels = document.querySelectorAll('[data-provider-panel]');
    var activeLabel = document.getElementById('spAiActiveProvider');
    var panelLabel = document.getElementById('spAiPanelProvider');
    var keyBadge = document.getElementById('spAiKeyBadge');
    var keyMasked = document.getElementById('spAiKeyMasked');
    var keySource = document.getElementById('spAiKeySource');
    var updated = document.getElementById('spAiUpdated');
    var serviceBadge = document.getElementById('spAiServiceBadge');
    var serviceToggle = document.getElementById('is_enabled');

    function setActive(provider) {
        panels.forEach(function (panel) {
            var on = panel.getAttribute('data-provider-panel') === provider;
            panel.hidden = !on;
            panel.querySelectorAll('input, select, textarea, button').forEach(function (el) {
                el.disabled = !on;
            });
        });

        if (!providerSelect) { return; }
        var option = providerSelect.options[providerSelect.selectedIndex];
        if (!option) { return; }

        if (activeLabel) { activeLabel.textContent = option.getAttribute('data-label') || option.text; }
        if (panelLabel) { panelLabel.textContent = option.getAttribute('data-label') || option.text; }

        var hasKey = option.getAttribute('data-has-key') === '1';
        if (keyBadge) {
            keyBadge.textContent = hasKey ? 'API Key Tersimpan' : 'Belum Tersimpan';
            keyBadge.classList.toggle('text-bg-success', hasKey);
            keyBadge.classList.toggle('text-bg-warning', !hasKey);
        }
        if (keyMasked) {
            var masked = option.getAttribute('data-masked') || '';
            keyMasked.textContent = hasKey ? (masked !== '' ? masked : 'Tersimpan') : 'Belum ada';
        }
        if (keySource) { keySource.textContent = option.getAttribute('data-source') || '-'; }
        if (updated) { updated.textContent = option.getAttribute('data-updated') || '-'; }
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            setActive(providerSelect.value);
        });
        setActive(providerSelect.value);
    }

    document.querySelectorAll('[data-eye]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-eye'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.textContent = show ? '🙈' : '👁';
        });
    });

    if (serviceToggle && serviceBadge) {
        serviceToggle.addEventListener('change', function () {
            serviceBadge.textContent = serviceToggle.checked ? 'Aktif' : 'Nonaktif';
            serviceBadge.classList.toggle('text-bg-success', serviceToggle.checked);
            serviceBadge.classList.toggle('text-bg-secondary', !serviceToggle.checked);
        });
    }
})();
</script>
