<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\ChatbotSetting;
use App\Models\Sparepart;
use App\Services\ChatbotDataService;
use App\Services\ChatbotService;
use App\Services\ChatbotSettingsService;
use App\Services\DashboardService;

require_once __DIR__ . '/gemini_stub.php';

/**
 * Pengujian revisi chatbot:
 * - angka chatbot = angka Dashboard (sumber query yang sama),
 * - pertanyaan angka/daftar dijawab deterministik dari database,
 * - kategori, stok, transaksi, PO, dan pengguna dapat dijawab,
 * - format jawaban bebas Markdown/escape.
 */

/** @return array<string,mixed> */
function t_cd_settings_snapshot(): array
{
    ChatbotSetting::ensureRow();
    return ChatbotSetting::current() ?? [];
}

/** @param array<string,mixed> $row */
function t_cd_settings_restore(array $row): void
{
    if ($row !== []) {
        Database::pdo()->prepare(
            'UPDATE chatbot_settings SET is_enabled=?, model=?, api_key_encrypted=?, api_key_masked=?,
                    system_prompt=?, topic_limits=?, answer_style=?, usage_limit_daily=?, usage_limit_monthly=?
              WHERE id=1'
        )->execute([
            $row['is_enabled'], $row['model'], $row['api_key_encrypted'], $row['api_key_masked'],
            $row['system_prompt'], $row['topic_limits'], $row['answer_style'],
            $row['usage_limit_daily'], $row['usage_limit_monthly'],
        ]);
    }
}

/** @return array<string,mixed> */
function t_cd_user(string $role): array
{
    static $users = [];
    if (!isset($users[$role])) {
        $users[$role] = [
            'id' => t_make_user('chat_data_' . $role, $role, t_password()),
            'role' => $role,
            'full_name' => 'Uji Data ' . $role,
        ];
    }
    return $users[$role];
}

/** Hitung status stok langsung dari tabel (pembanding independen). */
function t_cd_stock_counts(): array
{
    $rows = Database::pdo()->query('SELECT stock, min_stock FROM spareparts WHERE is_active = 1')->fetchAll();
    $out = ['habis' => 0, 'kritis' => 0, 'menipis' => 0, 'aman' => 0, 'tanpa_minimum' => 0];
    foreach ($rows as $row) {
        $out[Sparepart::stockStatusOf($row)]++;
    }
    return $out;
}

function test_chatbot_data_dashboard_konsisten(): void
{
    $dash = DashboardService::admin();
    $data = ChatbotDataService::dashboardNumbers('admin');

    t_eq((int) ($dash['sparepart']['types'] ?? -1), (int) $data['total_jenis_sparepart_semua'],
        'Total sparepart semua = angka Dashboard Admin');
    t_eq((int) ($dash['sparepart_active']['types'] ?? -1), (int) $data['total_jenis_sparepart_aktif'],
        'Total sparepart aktif = angka Dashboard Admin');
    t_eq((int) ($dash['stock_stats']['habis'] ?? -1), (int) $data['status_stok']['habis'],
        'Jumlah stok habis = angka Dashboard Admin');
    t_eq((int) ($dash['stock_stats']['kritis'] ?? -1), (int) $data['status_stok']['kritis'],
        'Jumlah stok kritis = angka Dashboard Admin');
    t_eq((int) ($dash['stock_stats']['menipis'] ?? -1), (int) $data['status_stok']['menipis'],
        'Jumlah stok menipis = angka Dashboard Admin');
    t_eq((int) ($dash['transactions']['receipts'] ?? -1), (int) $data['transaksi']['total_dokumen_penerimaan'],
        'Dokumen penerimaan = kartu transaksi Dashboard Admin');
    t_eq((int) ($dash['transactions']['issues'] ?? -1), (int) $data['transaksi']['total_dokumen_pengeluaran'],
        'Dokumen pengeluaran = kartu transaksi Dashboard Admin');
    t_eq((int) ($dash['transactions']['opnames'] ?? -1), (int) $data['transaksi']['total_dokumen_opname'],
        'Dokumen opname = statistik transaksi Dashboard Admin');
    t_eq((int) ($dash['transactions']['movements'] ?? -1), (int) $data['transaksi']['total_pergerakan_ledger'],
        'Pergerakan ledger = statistik transaksi Dashboard Admin');
    t_eq((int) ($dash['transactions']['po'] ?? -1), (int) $data['transaksi']['total_purchase_order'],
        'Total PO = statistik transaksi Dashboard Admin');
    t_eq((int) ($dash['users']['total'] ?? -1), (int) ($data['pengguna']['total'] ?? -2),
        'Total pengguna = angka Dashboard Admin');

    $partman = DashboardService::partman();
    $partData = ChatbotDataService::dashboardNumbers('partman');
    t_eq((int) ($partman['summary']['types'] ?? -1), (int) $partData['total_jenis_sparepart_aktif'],
        'Partman: jenis sparepart = angka Dashboard Partman');
    t_eq((int) ($partman['summary']['receipts'] ?? -1), (int) $partData['transaksi']['hari_ini']['dokumen_penerimaan'],
        'Partman: penerimaan hari ini = angka Dashboard Partman');
    t_eq((int) ($partman['summary']['issues'] ?? -1), (int) $partData['transaksi']['hari_ini']['dokumen_pengeluaran'],
        'Partman: pengeluaran hari ini = angka Dashboard Partman');

    $kepala = DashboardService::kepala();
    $kepalaData = ChatbotDataService::dashboardNumbers('kepala_bengkel');
    t_eq((int) ($kepala['summary']['types'] ?? -1), (int) $kepalaData['total_jenis_sparepart_aktif'],
        'Kepala Bengkel: jenis sparepart = angka Dashboard');
    t_eq(round((float) ($kepala['summary']['value_avg'] ?? -1), 2), round((float) $kepalaData['nilai_persediaan_rata_rata'], 2),
        'Kepala Bengkel: nilai persediaan = angka Dashboard');
}

function test_chatbot_data_kategori_dan_stok(): void
{
    $pdo = Database::pdo();
    $categoryId = t_make_category('Kategori Data Uji', true);
    $emptyCategoryId = t_make_category('Kategori Kosong Uji', true);
    $partNames = [];
    $partIds = [];

    try {
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => 'Sparepart Data Habis ' . t_uniq('H'),
            'code' => t_uniq('SPH'),
            'stock' => 0,
            'min_stock' => 2,
        ]);
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => 'Sparepart Data Kritis ' . t_uniq('K'),
            'code' => t_uniq('SPK'),
            'stock' => 1,
            'min_stock' => 5,
        ]);
        $namaAman = 'Sparepart Data Aman ' . t_uniq('A');
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => $namaAman,
            'code' => t_uniq('SPA'),
            'stock' => 12,
            'min_stock' => 4,
        ]);

        foreach ($partIds as $id) {
            $stmt = $pdo->prepare('SELECT name FROM spareparts WHERE id = ?');
            $stmt->execute([$id]);
            $partNames[] = (string) $stmt->fetchColumn();
        }

        $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        $partsAll = (int) $pdo->query('SELECT COUNT(*) FROM spareparts')->fetchColumn();
        $activeParts = (int) $pdo->query('SELECT COUNT(*) FROM spareparts WHERE is_active = 1')->fetchColumn();

        $ans = ChatbotDataService::answer('Berapa jumlah kategori sparepart?', 'admin');
        t_true($ans !== null, 'Pertanyaan jumlah kategori dijawab dari data');
        t_contains('Jumlah kategori sparepart saat ini: ' . number_format($categoryCount, 0, ',', '.'), (string) $ans,
            'Jumlah kategori sesuai database');
        t_contains(number_format($partsAll, 0, ',', '.'), (string) $ans, 'Total sparepart kategori sesuai database');
        t_true(!str_contains((string) $ans, '**'), 'Jawaban kategori tidak memuat Markdown');

        $ans = ChatbotDataService::answer('Apa saja kategori sparepart yang tersedia?', 'admin');
        t_contains('Kategori Data Uji', (string) $ans, 'Daftar kategori memuat kategori aktual');

        $ans = ChatbotDataService::answer('Kategori mana yang memiliki sparepart terbanyak?', 'admin');
        t_true($ans !== null && (str_contains((string) $ans, 'Kategori Data Uji') || str_contains((string) $ans, 'terbanyak')),
            'Kategori terbanyak dijawab dari data');

        $ans = ChatbotDataService::answer('Kategori mana yang belum memiliki sparepart?', 'admin');
        t_contains('Kategori Kosong Uji', (string) $ans, 'Kategori kosong terdeteksi');

        $ans = ChatbotDataService::answer('Berapa jumlah sparepart di setiap kategori?', 'admin');
        t_contains('Jumlah sparepart per kategori', (string) $ans, 'Rincian per kategori tersedia');
        t_contains('Kategori Data Uji', (string) $ans, 'Rincian per kategori memuat kategori aktual');

        $ans = ChatbotDataService::answer('Berapa total sparepart saat ini?', 'admin');
        t_contains(number_format($partsAll, 0, ',', '.'), (string) $ans, 'Total sparepart Admin = seluruh data');

        $ans = ChatbotDataService::answer('Berapa total sparepart saat ini?', 'partman');
        t_contains(number_format($activeParts, 0, ',', '.'), (string) $ans, 'Total sparepart Partman = sparepart aktif');

        $counts = t_cd_stock_counts();
        $ans = ChatbotDataService::answer('Berapa sparepart yang stoknya habis?', 'partman');
        t_contains(number_format((int) $counts['habis'], 0, ',', '.'), (string) $ans, 'Jumlah stok habis sesuai data');
        $ans = ChatbotDataService::answer('Berapa sparepart yang stoknya menipis?', 'partman');
        t_contains(number_format((int) $counts['menipis'], 0, ',', '.'), (string) $ans, 'Jumlah stok menipis sesuai data');
        $ans = ChatbotDataService::answer('Berapa sparepart yang stoknya kritis?', 'partman');
        t_contains(number_format((int) $counts['kritis'], 0, ',', '.'), (string) $ans, 'Jumlah stok kritis sesuai data');

        $ans = ChatbotDataService::answer('Sparepart apa saja yang stoknya habis?', 'partman');
        $habisList = ChatbotDataService::stockLists()['habis'];
        if ($habisList !== []) {
            t_contains((string) $habisList[0]['nama'], (string) $ans, 'Daftar sparepart habis memuat data aktual');
        } else {
            t_contains('Tidak ada sparepart', (string) $ans, 'Daftar sparepart habis menyatakan data kosong dengan benar');
        }

        $ans = ChatbotDataService::answer('Sparepart apa saja yang harus segera direorder?', 'partman');
        $reorderRows = array_merge(
            ChatbotDataService::stockLists()['habis'],
            ChatbotDataService::stockLists()['kritis']
        );
        if ($reorderRows !== []) {
            t_contains((string) $reorderRows[0]['nama'], (string) $ans, 'Daftar reorder memuat sparepart berstatus habis/kritis');
        } else {
            t_contains('Tidak ada sparepart', (string) $ans, 'Daftar reorder menyatakan data kosong dengan benar');
        }

        $ans = ChatbotDataService::answer('Berapa stok ' . $namaAman . '?', 'partman');
        t_contains('12', (string) $ans, 'Stok sparepart tertentu sesuai data');
        $ans = ChatbotDataService::answer('Berapa batas minimum stok ' . $namaAman . '?', 'partman');
        t_contains('4', (string) $ans, 'Stok minimum sparepart tertentu sesuai data');
    } finally {
        foreach ($partIds as $id) {
            $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM categories WHERE id IN (?, ?)')->execute([$categoryId, $emptyCategoryId]);
    }
}

function test_chatbot_data_transaksi_po_pengguna(): void
{
    $pdo = Database::pdo();

    $receipts = (int) $pdo->query('SELECT COUNT(*) FROM goods_receipts')->fetchColumn();
    $issues = (int) $pdo->query('SELECT COUNT(*) FROM goods_issues')->fetchColumn();
    $opnames = (int) $pdo->query('SELECT COUNT(*) FROM stock_opnames')->fetchColumn();
    $adjustmentDocs = (int) $pdo->query(
        "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id)))
           FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
    )->fetchColumn();
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

    $ans = ChatbotDataService::answer('Berapa total transaksi barang masuk?', 'admin');
    t_contains(number_format($receipts, 0, ',', '.'), (string) $ans, 'Transaksi barang masuk sesuai database');
    $ans = ChatbotDataService::answer('Berapa total transaksi barang keluar?', 'admin');
    t_contains(number_format($issues, 0, ',', '.'), (string) $ans, 'Transaksi barang keluar sesuai database');
    $ans = ChatbotDataService::answer('Berapa jumlah transaksi penyesuaian stok?', 'admin');
    if ($adjustmentDocs > 0) {
        t_contains(number_format($adjustmentDocs, 0, ',', '.') . ' dokumen penyesuaian stok', (string) $ans,
            'Transaksi penyesuaian dihitung dari stock_movements tipe PENYESUAIAN');
    } else {
        t_contains('Belum ada dokumen penyesuaian stok', (string) $ans,
            'Tidak ada penyesuaian dinyatakan dari sumber yang benar');
    }
    t_true($ans === null || !str_contains((string) $ans, 'stock opname)'),
        'Transaksi penyesuaian tidak lagi dilabeli stock opname');
    $ans = ChatbotDataService::answer('Berapa jumlah stock opname?', 'admin');
    t_contains(number_format($opnames, 0, ',', '.') . ' sesi stock opname', (string) $ans,
        'Stock opname dihitung dari tabel stock_opnames');

    $ans = ChatbotDataService::answer('Berapa total transaksi hari ini?', 'admin');
    t_true($ans !== null && str_contains((string) $ans, 'hari ini'), 'Total transaksi hari ini menyebut periode');
    t_true($ans !== null && str_contains((string) $ans, date('Y-m-d')), 'Total transaksi hari ini menyebut tanggal');

    $ans = ChatbotDataService::answer('Berapa barang masuk dan barang keluar bulan ini?', 'admin');
    t_true($ans !== null && str_contains((string) $ans, 'Barang masuk'), 'Ringkasan bulan ini memuat barang masuk');
    t_true($ans !== null && str_contains((string) $ans, 'Barang keluar'), 'Ringkasan bulan ini memuat barang keluar');

    $pending = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'MENUNGGU_PERSETUJUAN'")->fetchColumn();
    $ans = ChatbotDataService::answer('Berapa PO yang masih menunggu persetujuan?', 'admin');
    t_contains(number_format($pending, 0, ',', '.'), (string) $ans, 'PO menunggu persetujuan sesuai database');

    $received = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'SELESAI_DITERIMA'")->fetchColumn();
    $ans = ChatbotDataService::answer('Berapa PO yang sudah diterima?', 'admin');
    t_contains(number_format($received, 0, ',', '.'), (string) $ans, 'PO diterima sesuai database');

    $ans = ChatbotDataService::answer('Apa transaksi terbaru yang tercatat?', 'admin');
    t_true($ans !== null, 'Transaksi terbaru dijawab');

    $ans = ChatbotDataService::answer('Siapa saja pengguna aktif?', 'admin');
    t_contains(number_format($activeUsers, 0, ',', '.'), (string) $ans, 'Jumlah pengguna aktif sesuai database');
    t_contains(number_format($users, 0, ',', '.'), (string) $ans, 'Total pengguna sesuai database');

    $ans = ChatbotDataService::answer('Apa role masing-masing pengguna?', 'admin');
    t_true($ans !== null && str_contains((string) $ans, 'Role masing-masing pengguna'), 'Daftar role pengguna dijawab');

    t_eq(null, ChatbotDataService::answer('Berapa jumlah pengguna?', 'partman'),
        'Layanan data tidak membocorkan pengguna untuk Partman');
}

function test_chatbot_data_format_dan_respond(): void
{
    $clean = ChatbotService::cleanAnswer("**Total Kategori:** 8 kategori\n* Baris satu\n* Baris dua\n### Catatan\n`kode` selesai");
    t_true(!str_contains($clean, '*'), 'Markdown ** dan * dihapus');
    t_true(!str_contains($clean, '#'), 'Heading Markdown dihapus');
    t_true(!str_contains($clean, '`'), 'Backtick dihapus');
    t_contains('Total Kategori: 8 kategori', $clean, 'Isi dan angka penting dipertahankan');
    t_contains('- Baris satu', $clean, 'Bullet Markdown dirapikan');

    $escaped = ChatbotService::cleanAnswer('Baris1\\nBaris2');
    t_contains("Baris1\nBaris2", $escaped, 'Escape literal \\n diubah menjadi baris baru');

    $table = ChatbotService::cleanAnswer("| Nama | Jumlah |\n|---|---|\n| Filter | 2 |");
    t_true(!str_contains($table, '---'), 'Baris pemisah tabel Markdown dihapus');
    t_contains('Nama · Jumlah', $table, 'Tabel Markdown diubah menjadi teks');
    t_contains('- Filter · 2', $table, 'Isi tabel tetap terbaca');

    $before = t_cd_settings_snapshot();
    try {
        t_cd_settings_restore($before);
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');

        $s = ChatbotSettingsService::current();
        ChatbotSettingsService::save([
            'is_enabled'          => '1',
            'model'               => 'gemini-3.5-flash-lite',
            'system_prompt'       => $s['system_prompt'],
            'topic_limits'        => $s['topic_limits'],
            'answer_style'        => 'ringkas',
            'usage_limit_daily'   => '',
            'usage_limit_monthly' => '',
            'remove_api_key'      => '1',
        ], t_cd_user('partman')['id']);

        $ans = ChatbotService::respond('Berapa jumlah kategori sparepart?', t_cd_user('partman'));
        t_eq('ok', (string) $ans['status'], 'Pertanyaan kategori dijawab sukses tanpa API key');
        t_eq('data', (string) $ans['source'], 'Sumber jawaban adalah data server');
        t_true(!str_contains((string) $ans['answer'], '**'), 'Jawaban server bersih dari Markdown');

        $scope = ChatbotService::respond('Berapa jumlah pengguna?', t_cd_user('partman'));
        t_eq('role_scope', (string) $scope['status'], 'Role Partman tetap dibatasi untuk data pengguna');
    } finally {
        t_cd_settings_restore($before);
    }
}

function test_chatbot_data_jawaban_gemini_dibersihkan(): void
{
    $before = t_cd_settings_snapshot();
    $stub = null;

    try {
        t_cd_settings_restore($before);
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
        $stub = t_gemini_stub_start();
        $s = ChatbotSettingsService::current();
        ChatbotSettingsService::save([
            'is_enabled'          => '1',
            'model'               => 'gemini-3.5-flash-lite',
            'system_prompt'       => $s['system_prompt'],
            'topic_limits'        => $s['topic_limits'],
            'answer_style'        => 'ringkas',
            'usage_limit_daily'   => '',
            'usage_limit_monthly' => '',
            'api_key'             => 'TESTKEY-DATA-ABCDEFGHIJKLMNOPQRSTUVWXYZ12',
        ], t_cd_user('admin')['id']);

        $res = ChatbotService::respond('Tolong jelaskan MARKDOWN_UJI stok gudang.', t_cd_user('admin'), $stub['base'], 5.0);
        t_eq('ok', (string) $res['status'], 'Jawaban Gemini stub berhasil');
        t_eq('gemini', (string) $res['source'], 'Sumber jawaban tercatat gemini');
        t_true(!str_contains((string) $res['answer'], '**'), 'Markdown Gemini dibersihkan');
        t_true(!str_contains((string) $res['answer'], '\\n'), 'Escape literal pada jawaban Gemini dibersihkan');
        t_contains('Total Kategori: 8 kategori', (string) $res['answer'], 'Isi jawaban Gemini tetap utuh');

        $requests = t_gemini_stub_requests($stub);
        t_eq(1, count($requests), 'Satu panggilan Gemini dilakukan');
        $system = (string) (($requests[0]['body'] ?? [])['system_instruction'] ?? '');
        t_contains('angka_dashboard', $system, 'Konteks angka Dashboard dikirim ke Gemini');
        t_contains('kategori_sparepart', $system, 'Konteks kategori dikirim ke Gemini');
        t_contains('JANGAN memakai Markdown', $system, 'Instruksi format teks biasa dikirim ke Gemini');
    } finally {
        if (is_array($stub)) {
            t_gemini_stub_cleanup($stub);
        }
        t_cd_settings_restore($before);
    }
}

function test_chatbot_data_22_pertanyaan(): void
{
    $part = Database::pdo()->query(
        'SELECT name FROM spareparts WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
    )->fetchColumn();
    $partName = is_string($part) && $part !== '' ? $part : 'sparepart';

    $questions = [
        ['Berapa total sparepart saat ini?', 'admin'],
        ['Berapa jumlah kategori sparepart?', 'admin'],
        ['Berapa jumlah stok tersedia?', 'partman'],
        ['Berapa sparepart yang stoknya menipis?', 'partman'],
        ['Berapa sparepart yang stoknya habis?', 'partman'],
        ['Berapa total transaksi hari ini?', 'admin'],
        ['Berapa barang masuk dan barang keluar bulan ini?', 'kepala_bengkel'],
        ['Apa saja kategori sparepart yang tersedia?', 'partman'],
        ['Berapa jumlah sparepart di setiap kategori?', 'partman'],
        ['Kategori mana yang memiliki sparepart terbanyak?', 'partman'],
        ['Sparepart apa saja yang stoknya habis?', 'partman'],
        ['Sparepart apa saja yang harus segera direorder?', 'partman'],
        ['Berapa stok ' . $partName . '?', 'partman'],
        ['Berapa batas minimum stok ' . $partName . '?', 'partman'],
        ['Berapa total transaksi barang masuk?', 'admin'],
        ['Berapa total transaksi barang keluar?', 'admin'],
        ['Berapa jumlah transaksi penyesuaian stok?', 'admin'],
        ['Berapa PO yang masih menunggu persetujuan?', 'admin'],
        ['Berapa PO yang sudah diterima?', 'admin'],
        ['Apa transaksi terbaru yang tercatat?', 'kepala_bengkel'],
        ['Siapa saja pengguna aktif?', 'admin'],
        ['Apa role masing-masing pengguna?', 'admin'],
    ];

    foreach ($questions as $index => [$question, $role]) {
        $answer = ChatbotDataService::answer($question, $role);
        $label = 'Pertanyaan #' . ($index + 1) . ' "' . $question . '" dijawab dari data';
        t_true($answer !== null && trim((string) $answer) !== '', $label);
        if ($answer !== null) {
            t_true(!str_contains((string) $answer, '*') && !str_contains((string) $answer, '`'),
                'Jawaban #' . ($index + 1) . ' bebas Markdown');
        }
    }
}

/**
 * Supplier dan lokasi/rak: angka dan daftar harus sama dengan database.
 */
function test_chatbot_data_supplier_dan_lokasi(): void
{
    $pdo = Database::pdo();
    $categoryId = t_make_category('Kategori Supplier Rak Uji', true);
    $supplierA = t_make_supplier('Supplier Pasok Uji', true);
    $supplierB = t_make_supplier('Supplier Kosong Uji', true);
    $locationA = t_make_location('Rak Aktif Uji', true);
    $locationB = t_make_location('Rak Nonaktif Uji', false);
    $partIds = [];
    $receiptId = null;

    try {
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => 'Part Rak A ' . t_uniq('X'),
            'code' => t_uniq('PRA'),
            'stock' => 7,
            'min_stock' => 2,
            'supplier_id' => $supplierA,
            'location_id' => $locationA,
        ]);
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => 'Part Rak B ' . t_uniq('Y'),
            'code' => t_uniq('PRB'),
            'stock' => 3,
            'min_stock' => 2,
            'supplier_id' => $supplierA,
            'location_id' => $locationA,
        ]);
        $partIds[] = t_make_sparepart($categoryId, [
            'name' => 'Part Rak Nonaktif ' . t_uniq('Z'),
            'code' => t_uniq('PRC'),
            'stock' => 1,
            'min_stock' => 2,
            'supplier_id' => $supplierB,
            'location_id' => $locationB,
        ]);

        $partName = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partIds[0])->fetchColumn();
        $supplierAName = (string) $pdo->query('SELECT name FROM suppliers WHERE id = ' . (int) $supplierA)->fetchColumn();
        $supplierBName = (string) $pdo->query('SELECT name FROM suppliers WHERE id = ' . (int) $supplierB)->fetchColumn();
        $locationAName = (string) $pdo->query('SELECT name FROM storage_locations WHERE id = ' . (int) $locationA)->fetchColumn();
        $locationBName = (string) $pdo->query('SELECT name FROM storage_locations WHERE id = ' . (int) $locationB)->fetchColumn();
        $locationACode = (string) $pdo->query('SELECT code FROM storage_locations WHERE id = ' . (int) $locationA)->fetchColumn();
        $locationBCode = (string) $pdo->query('SELECT code FROM storage_locations WHERE id = ' . (int) $locationB)->fetchColumn();

        // Penerimaan besar agar supplier A pasti menjadi pemasok terbanyak.
        $pdo->prepare(
            'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, recorded_by)
             VALUES (?, CURDATE(), ?, ?)'
        )->execute([t_uniq('RC-CHAT'), $supplierA, t_cd_user('admin')['id']]);
        $receiptId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price)
             VALUES (?, ?, ?, ?)'
        )->execute([$receiptId, $partIds[0], 9999, 1000]);

        $supplierTotal = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
        $locationTotal = (int) $pdo->query('SELECT COUNT(*) FROM storage_locations')->fetchColumn();
        $inactiveLocations = (int) $pdo->query('SELECT COUNT(*) FROM storage_locations WHERE is_active = 0')->fetchColumn();

        $ans = (string) ChatbotDataService::answer('Ada berapa supplier?', 'admin');
        t_contains(number_format($supplierTotal, 0, ',', '.'), $ans, 'Jumlah supplier = data tabel suppliers');
        t_true(!str_contains($ans, 'tidak tersedia di konteks'), 'Supplier tidak lagi dijawab "tidak tersedia di konteks"');

        $ans = (string) ChatbotDataService::answer('Siapa saja supplier yang terdaftar?', 'admin');
        t_contains($supplierAName, $ans, 'Daftar supplier memuat supplier aktif aktual');
        t_contains($supplierBName, $ans, 'Daftar supplier memuat supplier kedua');

        $ans = (string) ChatbotDataService::answer('Supplier mana yang paling banyak memasok barang?', 'admin');
        $topSupplier = (string) $pdo->query(
            'SELECT s.name FROM suppliers s
              LEFT JOIN goods_receipts gr ON gr.supplier_id = s.id
              LEFT JOIN goods_receipt_items gri ON gri.receipt_id = gr.id
          GROUP BY s.id, s.name
          ORDER BY COALESCE(SUM(gri.quantity), 0) DESC, s.name ASC LIMIT 1'
        )->fetchColumn();
        t_contains($topSupplier, $ans, 'Supplier pemasok terbanyak sesuai agregasi database');

        $ans = (string) ChatbotDataService::answer('Supplier mana yang belum pernah memasok barang?', 'admin');
        t_contains($supplierBName, $ans, 'Supplier tanpa penerimaan terdeteksi');

        $ans = (string) ChatbotDataService::answer('Berapa transaksi pembelian dari setiap supplier?', 'admin');
        t_contains($supplierAName, $ans, 'Transaksi pembelian per supplier memuat supplier A');
        t_contains('1 dokumen penerimaan', $ans, 'Transaksi pembelian per supplier menghitung dokumen');

        $ans = (string) ChatbotDataService::answer('Ada berapa rak?', 'admin');
        t_contains(number_format($locationTotal, 0, ',', '.'), $ans, 'Jumlah rak/lokasi = data storage_locations');
        t_true(!str_contains($ans, 'tidak tersedia di konteks'), 'Rak tidak lagi dijawab "tidak tersedia di konteks"');

        $ans = (string) ChatbotDataService::answer('Tampilkan semua kode rak', 'admin');
        t_contains($locationACode, $ans, 'Daftar kode rak memuat kode aktual');
        t_contains($locationBCode, $ans, 'Daftar kode rak memuat kode rak nonaktif');

        $ans = (string) ChatbotDataService::answer('Rak mana yang digunakan?', 'admin');
        t_contains($locationAName, $ans, 'Rak yang digunakan terdeteksi');

        $ans = (string) ChatbotDataService::answer('Rak mana yang tidak aktif?', 'admin');
        if ($inactiveLocations > 0) {
            t_contains($locationBName, $ans, 'Rak nonaktif terdeteksi');
        } else {
            t_contains('Tidak ada rak nonaktif', $ans, 'Tidak ada rak nonaktif dinyatakan benar');
        }

        $ans = (string) ChatbotDataService::answer('Ada berapa tingkat penyimpanan?', 'admin');
        t_contains('tidak menyimpan tingkat', $ans, 'Tingkat penyimpanan dijelaskan sesuai schema aktual');
        t_contains(number_format($locationTotal, 0, ',', '.'), $ans, 'Jumlah tingkat tidak dihitung ganda dari baris yang sama');

        $ans = (string) ChatbotDataService::answer('Rak mana yang memiliki sparepart terbanyak?', 'admin');
        $topLocation = (string) $pdo->query(
            'SELECT sl.name FROM storage_locations sl
          ORDER BY (SELECT COUNT(*) FROM spareparts sp WHERE sp.location_id = sl.id) DESC, sl.code ASC LIMIT 1'
        )->fetchColumn();
        t_contains($topLocation, $ans, 'Rak dengan sparepart terbanyak sesuai database');

        $ans = (string) ChatbotDataService::answer('Di mana lokasi penyimpanan ' . $partName . '?', 'admin');
        t_contains($locationAName, $ans, 'Lokasi penyimpanan sparepart tertentu sesuai database');
    } finally {
        if ($receiptId !== null) {
            $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id = ?')->execute([$receiptId]);
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$receiptId]);
        }
        foreach ($partIds as $id) {
            $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM suppliers WHERE id IN (?, ?)')->execute([$supplierA, $supplierB]);
        $pdo->prepare('DELETE FROM storage_locations WHERE id IN (?, ?)')->execute([$locationA, $locationB]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
}

/**
 * PO, transaksi, riwayat stok, dan stok per kategori.
 */
function test_chatbot_data_po_transaksi_lengkap(): void
{
    $pdo = Database::pdo();
    $poTotal = (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
    $rejected = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'DITOLAK'")->fetchColumn();
    $receivedQty = (float) $pdo->query('SELECT COALESCE(SUM(received_qty), 0) FROM purchase_order_items')->fetchColumn();
    $orderedQty = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM purchase_order_items')->fetchColumn();

    $ans = (string) ChatbotDataService::answer('Ada berapa PO?', 'admin');
    t_contains(number_format($poTotal, 0, ',', '.'), $ans, 'Jumlah PO sesuai database');

    $ans = (string) ChatbotDataService::answer('Berapa PO yang ditolak?', 'admin');
    t_contains(number_format($rejected, 0, ',', '.'), $ans, 'PO ditolak sesuai database');

    $ans = (string) ChatbotDataService::answer('Berapa jumlah barang yang diterima?', 'admin');
    t_contains(number_format($receivedQty, 0, ',', '.'), $ans, 'Qty barang diterima sesuai purchase_order_items');
    t_contains(number_format($orderedQty, 0, ',', '.'), $ans, 'Qty barang dipesan sesuai purchase_order_items');

    $ans = (string) ChatbotDataService::answer('Tampilkan daftar PO', 'admin');
    $poNumber = (string) $pdo->query('SELECT po_number FROM purchase_orders ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    if ($poNumber !== '') {
        t_contains($poNumber, $ans, 'Daftar PO memuat nomor PO aktual');
    } else {
        t_contains('Belum ada Purchase Order', $ans, 'Daftar PO kosong dinyatakan benar');
    }

    $ans = (string) ChatbotDataService::answer('Supplier mana yang terkait dengan PO tertentu?', 'admin');
    $poSupplier = (string) $pdo->query(
        'SELECT s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC LIMIT 1'
    )->fetchColumn();
    if ($poSupplier !== '') {
        t_contains($poSupplier, $ans, 'Supplier terkait PO sesuai database');
    }

    $ans = (string) ChatbotDataService::answer('Sparepart apa yang paling sering keluar?', 'admin');
    $topOut = $pdo->query(
        'SELECT sp.name FROM goods_issue_items gii
           JOIN spareparts sp ON sp.id = gii.sparepart_id
       GROUP BY sp.id, sp.name
       ORDER BY SUM(gii.quantity) DESC, sp.name ASC LIMIT 1'
    )->fetchColumn();
    if (is_string($topOut) && $topOut !== '') {
        t_contains($topOut, $ans, 'Sparepart paling sering keluar sesuai database');
    } else {
        t_contains('Belum ada barang keluar', $ans, 'Belum ada barang keluar dinyatakan benar');
    }

    $ans = (string) ChatbotDataService::answer('Tampilkan riwayat transaksi berdasarkan periode', 'admin');
    t_true(str_contains($ans, 'Riwayat transaksi') || str_contains($ans, 'Belum ada riwayat transaksi'),
        'Riwayat transaksi per periode dijawab');

    $ans = (string) ChatbotDataService::answer('Berapa jumlah transaksi berdasarkan jenis transaksi?', 'admin');
    t_contains('dokumen barang masuk', $ans, 'Jenis transaksi memuat barang masuk');
    t_contains('dokumen barang keluar', $ans, 'Jenis transaksi memuat barang keluar');

    $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi?', 'admin');
    $actor = (string) $pdo->query(
        'SELECT u.full_name FROM goods_receipts gr JOIN users u ON u.id = gr.recorded_by LIMIT 1'
    )->fetchColumn();
    if ($actor !== '') {
        t_contains($actor, $ans, 'Pelaku transaksi sesuai data pengguna');
    }

    $movements = (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    $ans = (string) ChatbotDataService::answer('Tampilkan riwayat stok', 'admin');
    if ($movements > 0) {
        t_contains('Riwayat pergerakan stok', $ans, 'Riwayat stok dibaca dari ledger stock_movements');
        t_contains(number_format($movements, 0, ',', '.'), $ans, 'Jumlah baris riwayat stok sesuai database');
    } else {
        t_contains('Belum ada riwayat pergerakan stok', $ans, 'Riwayat stok kosong dinyatakan benar');
    }

    $ans = (string) ChatbotDataService::answer('Berapa stok berdasarkan kategori?', 'admin');
    t_contains('per kategori', $ans, 'Stok per kategori dijawab dari database');
}

/**
 * 15 pertanyaan wajib, termasuk saat API eksternal tidak tersedia.
 */
function test_chatbot_data_15_pertanyaan_wajib(): void
{
    $questions = [
        'Ada berapa supplier?',
        'Siapa saja supplier?',
        'Ada berapa rak?',
        'Tampilkan kode semua rak.',
        'Ada berapa kategori?',
        'Apa saja kategorinya?',
        'Ada berapa sparepart?',
        'Berapa total stok?',
        'Berapa transaksi barang masuk?',
        'Berapa transaksi barang keluar?',
        'Ada berapa PO?',
        'Berapa PO yang menunggu persetujuan?',
        'Siapa saja pengguna aktif?',
        'Berapa transaksi bulan ini?',
        'Tampilkan riwayat stok.',
    ];

    foreach ($questions as $index => $question) {
        $role = $index === 12 ? 'admin' : ($index % 2 === 0 ? 'admin' : 'partman');
        $answer = ChatbotDataService::answer($question, $role);
        $label = 'Wajib #' . ($index + 1) . ' "' . $question . '" dijawab dari database';
        t_true($answer !== null && trim((string) $answer) !== '', $label);
        if ($answer !== null) {
            t_true(!str_contains((string) $answer, '**') && !str_contains((string) $answer, '`'),
                'Wajib #' . ($index + 1) . ' bebas Markdown');
            t_true(!str_contains((string) $answer, 'tidak tersedia di konteks'),
                'Wajib #' . ($index + 1) . ' tidak memakai alasan konteks kosong');
        }
    }

    // Partman/Kepala Bengkel tetap dapat membaca supplier, rak, stok, transaksi.
    foreach (['partman', 'kepala_bengkel'] as $role) {
        foreach (['Ada berapa supplier?', 'Ada berapa rak?', 'Ada berapa kategori?', 'Berapa transaksi barang masuk?'] as $q) {
            $ans = ChatbotDataService::answer($q, $role);
            t_true($ans !== null && trim((string) $ans) !== '', $role . ': "' . $q . '" dijawab dari database');
        }
        t_eq(null, ChatbotDataService::answer('Berapa jumlah pengguna?', $role),
            $role . ': data pengguna tetap tertutup');
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi?', $role);
        t_contains('hanya tersedia untuk Admin', $ans, $role . ': nama pelaku transaksi dibatasi');
    }
}

/**
 * Data baru langsung terbaca (tanpa cache) dan jawaban lokal tidak memanggil API.
 */
function test_chatbot_data_freshness_dan_tanpa_api(): void
{
    $pdo = Database::pdo();
    $supplierId = t_make_supplier('Supplier Fresh Uji', true);
    $supplierCode = (string) $pdo->query('SELECT code FROM suppliers WHERE id = ' . (int) $supplierId)->fetchColumn();
    $before = t_cd_settings_snapshot();

    try {
        $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Ada berapa supplier?', 'admin');
        t_contains(number_format($beforeCount, 0, ',', '.'), $ans, 'Hitungan supplier awal sesuai database');

        // Tambah supplier baru → hitungan berikutnya harus naik tanpa cache.
        $newSupplier = t_make_supplier('Supplier Baru Uji', true);
        try {
            $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
            $ans = (string) ChatbotDataService::answer('Ada berapa supplier?', 'admin');
            t_contains(number_format($afterCount, 0, ',', '.'), $ans, 'Supplier baru langsung terbaca chatbot');

            // Ubah nama → daftar harus memuat nama baru.
            $newName = 'Supplier Ganti Nama Uji ' . t_uniq('N');
            $pdo->prepare('UPDATE suppliers SET name = ? WHERE id = ?')->execute([$newName, $newSupplier]);
            $ans = (string) ChatbotDataService::answer('Siapa saja supplier yang terdaftar?', 'admin');
            t_contains($newName, $ans, 'Perubahan nama supplier langsung terbaca');
        } finally {
            $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$newSupplier]);
        }

        // API key dihapus: pertanyaan data lokal tetap dijawab 'data' tanpa provider.
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
        t_cd_settings_restore($before);
        $s = ChatbotSettingsService::current();
        ChatbotSettingsService::save([
            'is_enabled'          => '1',
            'model'               => 'gemini-3.5-flash-lite',
            'system_prompt'       => $s['system_prompt'],
            'topic_limits'        => $s['topic_limits'],
            'answer_style'        => 'ringkas',
            'usage_limit_daily'   => '',
            'usage_limit_monthly' => '',
            'remove_api_key'      => '1',
        ], t_cd_user('admin')['id']);

        foreach (['Ada berapa supplier?', 'Ada berapa rak?', 'Tampilkan riwayat stok.'] as $question) {
            $res = ChatbotService::respond($question, t_cd_user('admin'));
            t_eq('ok', (string) $res['status'], '"' . $question . '" tetap sukses tanpa API key');
            t_eq('data', (string) $res['source'], '"' . $question . '" dijawab sumber data lokal');
        }
        $success = (int) $pdo->query("SELECT COUNT(*) FROM chatbot_usage_logs WHERE status = 'success'")->fetchColumn();
        t_eq(0, $success, 'Tidak ada panggilan API sukses untuk pertanyaan data lokal');

        // Angka chatbot = angka query database langsung.
        $dbCount = (int) $pdo->query('SELECT COUNT(*) FROM storage_locations')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Ada berapa rak?', 'admin');
        t_contains(number_format($dbCount, 0, ',', '.'), $ans, 'Jumlah rak sama dengan query database');
    } finally {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        t_cd_settings_restore($before);
    }
}

/**
 * Audit log, backup, context, dan peta menu modul.
 */
function test_chatbot_data_audit_backup_dan_context(): void
{
    $pdo = Database::pdo();
    $context = ChatbotDataService::context('admin');
    t_true(isset($context['supplier']['total']), 'Konteks Admin memuat ringkasan supplier');
    t_true(isset($context['lokasi_penyimpanan']['total']), 'Konteks Admin memuat ringkasan lokasi/rak');
    t_true(isset($context['audit_dan_backup']['total_audit_log']), 'Konteks Admin memuat ringkasan audit/backup');
    t_true(count((array) ($context['supplier']['contoh_supplier'] ?? [])) <= 10, 'Daftar supplier pada konteks dibatasi');
    t_true(count((array) ($context['lokasi_penyimpanan']['contoh_lokasi'] ?? [])) <= 10, 'Daftar lokasi pada konteks dibatasi');

    $menu = ChatbotDataService::menuMap('partman');
    $labels = array_column($menu, 'modul');
    t_true(in_array('Supplier', $labels, true), 'Peta menu memuat Supplier');
    t_true(in_array('Lokasi Penyimpanan', $labels, true), 'Peta menu memuat Lokasi Penyimpanan');
    t_true(!in_array('Audit Log', $labels, true), 'Peta menu Partman tidak memuat Audit Log');

    $auditTotal = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    if ($auditTotal > 0) {
        $ans = (string) ChatbotDataService::answer('Berapa jumlah audit log?', 'admin');
        t_contains(number_format($auditTotal, 0, ',', '.'), $ans, 'Jumlah audit log sesuai database');
        $ans = (string) ChatbotDataService::answer('Tampilkan audit log terbaru', 'admin');
        t_contains('Aktivitas Audit Log terbaru', $ans, 'Daftar audit log terbaru dijawab dari database');
    } else {
        $ans = (string) ChatbotDataService::answer('Berapa jumlah audit log?', 'admin');
        t_contains('Belum ada aktivitas', $ans, 'Audit log kosong dinyatakan benar');
    }

    $backupTotal = (int) $pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn();
    $ans = (string) ChatbotDataService::answer('Ada berapa backup?', 'admin');
    if ($backupTotal > 0) {
        t_contains(number_format($backupTotal, 0, ',', '.'), $ans, 'Jumlah backup sesuai database');
    } else {
        t_contains('Belum ada berkas backup', $ans, 'Backup kosong dinyatakan benar');
    }

    t_eq(null, ChatbotDataService::answer('Berapa jumlah audit log?', 'partman'),
        'Audit log tidak dijawab untuk Partman');
    t_eq(null, ChatbotDataService::answer('Ada berapa backup?', 'kepala_bengkel'),
        'Backup tidak dijawab untuk Kepala Bengkel');
}

/**
 * Revisi detail transaksi: filter tanggal, rincian dokumen, PO, kategori,
 * rak, stok kritis, dan percakapan lanjutan — tanpa API eksternal.
 */
function test_chatbot_data_detail_transaksi_dan_tindak_lanjut(): void
{
    $pdo = Database::pdo();
    $admin = t_cd_user('admin');
    $partman = t_cd_user('partman');
    $date = '2026-10-18';
    $dateLabel = '18 Oktober 2026';
    $tag = strtoupper(bin2hex(random_bytes(4)));

    $categoryId = t_make_category('Kategori Detail Uji');
    $categoryName = (string) $pdo->query('SELECT name FROM categories WHERE id = ' . (int) $categoryId)->fetchColumn();
    $supplierId = t_make_supplier('Supplier Detail Uji');
    $supplierName = (string) $pdo->query('SELECT name FROM suppliers WHERE id = ' . (int) $supplierId)->fetchColumn();
    $locationId = t_make_location('Rak Detail Uji');
    $locationName = (string) $pdo->query('SELECT name FROM storage_locations WHERE id = ' . (int) $locationId)->fetchColumn();

    $partA = t_make_sparepart($categoryId, [
        'name' => 'Part Detail A ' . $tag, 'stock' => 20, 'min_stock' => 2,
        'supplier_id' => $supplierId, 'location_id' => $locationId,
    ]);
    $partB = t_make_sparepart($categoryId, [
        'name' => 'Part Detail B ' . $tag, 'stock' => 20, 'min_stock' => 2,
        'supplier_id' => $supplierId, 'location_id' => $locationId,
    ]);
    $partCritical = t_make_sparepart($categoryId, [
        'name' => 'AAA Kritis Detail ' . $tag, 'stock' => 1, 'min_stock' => 5,
        'supplier_id' => $supplierId, 'location_id' => $locationId,
    ]);
    $codeA = (string) $pdo->query('SELECT code FROM spareparts WHERE id = ' . (int) $partA)->fetchColumn();
    $codeB = (string) $pdo->query('SELECT code FROM spareparts WHERE id = ' . (int) $partB)->fetchColumn();
    $codeCritical = (string) $pdo->query('SELECT code FROM spareparts WHERE id = ' . (int) $partCritical)->fetchColumn();
    $nameA = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partA)->fetchColumn();
    $nameB = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partB)->fetchColumn();
    $nameCritical = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partCritical)->fetchColumn();

    $issueA = 'GI-DTL-' . $tag . 'A';
    $issueB = 'GI-DTL-' . $tag . 'B';
    $receiptA = 'GR-DTL-' . $tag . 'A';
    $receiptB = 'GR-DTL-' . $tag . 'B';
    $poNumber = 'PO-DTL-' . $tag;

    $issueIds = [];
    $receiptIds = [];
    $poId = 0;

    try {
        foreach ([
            [$issueA, $partA, 4, 'SPK-DTL-' . $tag, 'B 1234 DTL', 'Pengeluaran detail uji'],
            [$issueB, $partB, 4, null, null, null],
        ] as [$number, $partId, $qty, $spk, $plate, $note]) {
            $pdo->prepare(
                'INSERT INTO goods_issues (issue_number, issue_date, usage_type, spk_number, plate_number, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$number, $date, 'service', $spk, $plate, $note, (int) $admin['id']]);
            $issueId = (int) $pdo->lastInsertId();
            $issueIds[] = $issueId;
            $pdo->prepare('INSERT INTO goods_issue_items (issue_id, sparepart_id, quantity) VALUES (?, ?, ?)')
                ->execute([$issueId, $partId, $qty]);
        }

        foreach ([
            [$receiptA, $partA, 5, 'Penerimaan detail uji'],
            [$receiptB, $partB, 6, null],
        ] as [$number, $partId, $qty, $note]) {
            $pdo->prepare(
                'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$number, $date, $supplierId, $note, (int) $admin['id']]);
            $receiptId = (int) $pdo->lastInsertId();
            $receiptIds[] = $receiptId;
            $pdo->prepare('INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price) VALUES (?, ?, ?, ?)')
                ->execute([$receiptId, $partId, $qty, 10000]);
        }

        $pdo->prepare(
            "INSERT INTO purchase_orders (po_number, supplier_id, status, payment_status, total_amount, note, created_by, created_at)
             VALUES (?, ?, 'MENUNGGU_PERSETUJUAN', 'BELUM_DIBAYAR', 100000, 'PO detail uji', ?, ?)"
        )->execute([$poNumber, $supplierId, (int) $admin['id'], $date . ' 09:00:00']);
        $poId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$poId, $partA, 10, 10000, 3]);

        // 1. Rincian barang keluar pada tanggal tertentu.
        $dateIssueQty = (float) $pdo->query(
            "SELECT COALESCE(SUM(gii.quantity), 0) FROM goods_issue_items gii
               JOIN goods_issues gi ON gi.id = gii.issue_id WHERE gi.issue_date = '2026-10-18'"
        )->fetchColumn();
        $dateReceiptQty = (float) $pdo->query(
            "SELECT COALESCE(SUM(gri.quantity), 0) FROM goods_receipt_items gri
               JOIN goods_receipts gr ON gr.id = gri.receipt_id WHERE gr.receipt_date = '2026-10-18'"
        )->fetchColumn();
        $fmtQty = static function (float $value): string {
            $out = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
            return $out === '' ? '0' : $out;
        };
        $firstDoc = (string) $pdo->query(
            "SELECT issue_number FROM goods_issues WHERE issue_date = '2026-10-18' ORDER BY issue_number DESC LIMIT 1"
        )->fetchColumn();
        $adminName = (string) $pdo->query('SELECT full_name FROM users WHERE id = ' . (int) $admin['id'])->fetchColumn();

        $ans = (string) ChatbotDataService::answer('Apa barang keluar pada tanggal ' . $dateLabel . '?', 'admin');
        t_contains($issueA, $ans, 'Detail tanggal memuat dokumen GI pertama');
        t_contains($issueB, $ans, 'Detail tanggal memuat dokumen GI kedua');
        t_contains($nameA, $ans, 'Detail tanggal memuat nama sparepart');
        t_contains($codeB, $ans, 'Detail tanggal memuat kode sparepart');
        t_contains('4 pcs', $ans, 'Detail tanggal memuat jumlah dan satuan');
        t_contains('SPK-DTL-' . $tag, $ans, 'Detail tanggal memuat nomor SPK');
        t_contains('B 1234 DTL', $ans, 'Detail tanggal memuat nomor polisi');
        t_contains('Total pengeluaran: ' . $fmtQty($dateIssueQty) . ' unit.', $ans, 'Total pengeluaran dipisahkan dari rincian');
        t_true(!str_contains($ans, 'seluruh periode'), 'Tanggal tertentu tidak memakai agregat seluruh periode');

        // 2. Total pada tanggal tertentu.
        $ans = (string) ChatbotDataService::answer('Berapa total barang keluar pada tanggal ' . $dateLabel . '?', 'admin');
        t_contains('18 Oktober 2026', $ans, 'Total tanggal memakai label tanggal Indonesia');
        t_contains('dokumen barang keluar', $ans, 'Total tanggal menyebut jenis dokumen');
        t_contains('total ' . $fmtQty($dateIssueQty) . ' unit', $ans, 'Total tanggal menghitung qty filter tanggal');

        // 3. Daftar seluruh dokumen tanggal.
        $ans = (string) ChatbotDataService::answer('Tampilkan seluruh dokumen barang keluar tanggal ' . $dateLabel . '.', 'admin');
        t_contains($issueA, $ans, 'Daftar dokumen tanggal memuat GI pertama');
        t_contains($issueB, $ans, 'Daftar dokumen tanggal memuat GI kedua');

        // 4. Nomor dokumen spesifik.
        $ans = (string) ChatbotDataService::answer('Tampilkan transaksi ' . $issueA, 'admin');
        t_contains($issueA, $ans, 'Pencarian nomor dokumen GI berhasil');
        t_contains($nameA, $ans, 'Rincian dokumen GI memuat sparepart');

        // 4b. Nomor dokumen eksplisit tanpa kata "transaksi/barang keluar".
        $ans = (string) ChatbotDataService::answer('Tampilkan detail dokumen ' . $issueA . '.', 'admin');
        t_true($ans !== '', 'Detail dokumen eksplisit tidak mengembalikan jawaban kosong');
        t_contains('nomor dokumen ' . $issueA, $ans, 'Detail dokumen eksplisit dikenali tanpa kata transaksi');
        t_contains($nameA, $ans, 'Detail dokumen eksplisit memuat sparepart');
        t_contains('Dicatat oleh: ' . $adminName, $ans, 'Detail dokumen eksplisit memuat pencatat untuk Admin');

        // 4c. Rujukan "transaksi tersebut" setelah detail satu dokumen tidak melebar.
        $singleCtx = ChatbotDataService::lastContext();
        t_eq([$issueA], array_values((array) ($singleCtx['doc_numbers'] ?? [])),
            'Konteks detail dokumen hanya memuat dokumen tersebut');
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi tersebut?', 'admin', $singleCtx);
        t_contains($issueA, $ans, 'Lanjutan detail dokumen mengarah ke dokumen yang sama');
        t_true(!str_contains($ans, $issueB), 'Lanjutan detail dokumen tidak melebar ke dokumen lain');

        // 5-6. SPK dan nomor polisi pada konteks tanggal.
        $ctx = ChatbotDataService::lastContext();
        $ans = (string) ChatbotDataService::answer('Apa nomor SPK dan nomor polisi jika tersedia?', 'admin', $ctx);
        t_contains('SPK-DTL-' . $tag, $ans, 'Pertanyaan lanjutan menampilkan SPK');
        t_contains('B 1234 DTL', $ans, 'Pertanyaan lanjutan menampilkan nomor polisi');

        // 8. Barang masuk pada tanggal yang sama.
        $ans = (string) ChatbotDataService::answer('Apa barang masuk pada tanggal ' . $dateLabel . '?', 'admin');
        t_contains($receiptA, $ans, 'Barang masuk tanggal memuat GR pertama');
        t_contains($receiptB, $ans, 'Barang masuk tanggal memuat GR kedua');
        t_contains($supplierName, $ans, 'Barang masuk menampilkan supplier');
        t_contains('Jumlah diterima: 5 pcs', $ans, 'Barang masuk menampilkan jumlah diterima');

        // 9. Bulan Oktober 2026.
        $monthCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN '2026-10-01' AND '2026-10-31'"
        )->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa transaksi barang masuk bulan Oktober 2026?', 'admin');
        t_contains('Oktober 2026', $ans, 'Filter bulan memakai label Indonesia');
        t_contains(number_format($monthCount, 0, ',', '.'), $ans, 'Jumlah transaksi bulan sesuai database');
        t_true(!str_contains($ans, 'seluruh periode'), 'Filter bulan tidak memakai seluruh periode');

        // 10. Rincian PO belum diterima.
        $ans = (string) ChatbotDataService::answer('Tampilkan rincian PO yang belum diterima.', 'admin');
        t_contains($poNumber, $ans, 'Rincian PO belum diterima memuat nomor PO');
        t_contains('dipesan 10 pcs', $ans, 'Rincian PO memuat jumlah dipesan');
        t_contains('diterima 3 pcs', $ans, 'Rincian PO memuat jumlah diterima');
        t_contains('sisa 7 pcs', $ans, 'Rincian PO memuat sisa belum diterima');

        $ans = (string) ChatbotDataService::answer('Tampilkan rincian PO ' . $poNumber, 'admin');
        t_contains('Rincian Purchase Order (PO) ' . $poNumber, $ans, 'Detail PO spesifik dijawab');
        t_contains('Sisa belum diterima: 7 unit', $ans, 'Detail PO menghitung total sisa');

        // 11-12. Kategori dan rak spesifik.
        $ans = (string) ChatbotDataService::answer('Tampilkan semua sparepart pada kategori ' . $categoryName . '.', 'admin');
        t_contains($categoryName, $ans, 'Daftar sparepart kategori menyebut nama kategori');
        t_contains($codeA, $ans, 'Daftar sparepart kategori memuat kode sparepart');
        t_contains('stok 20 pcs', $ans, 'Daftar sparepart kategori memuat stok dan satuan');

        $ans = (string) ChatbotDataService::answer('Tampilkan semua sparepart pada rak ' . $locationName . '.', 'admin');
        t_contains($locationName, $ans, 'Daftar sparepart rak menyebut nama rak');
        t_contains($codeA, $ans, 'Daftar sparepart rak memuat kode sparepart');
        t_contains('minimum 2 pcs', $ans, 'Daftar sparepart rak memuat stok minimum');

        // 13. Stok dan minimum untuk setiap sparepart kritis.
        $ans = (string) ChatbotDataService::answer('Berapa stok dan stok minimum setiap sparepart yang kritis?', 'admin');
        t_contains($nameCritical, $ans, 'Daftar stok kritis memuat sparepart kritis');
        t_contains($codeCritical, $ans, 'Daftar stok kritis memuat kode sparepart');
        t_contains('stok 1 pcs', $ans, 'Daftar stok kritis memuat stok');
        t_contains('minimum 5 pcs', $ans, 'Daftar stok kritis memuat minimum dengan satuan');

        // Percakapan lanjutan dalam satu konteks.
        $ctx = null;
        $ans = (string) ChatbotDataService::answer('Apa barang keluar pada tanggal ' . $dateLabel . '?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_true(is_array($ctx) && ($ctx['date_from'] ?? '') === $date, 'Konteks tanggal tersimpan setelah jawaban rincian');

        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi tersebut?', 'admin', $ctx);
        t_contains($adminName, $ans, 'Lanjutan: pengguna transaksi dari konteks');
        $ans = (string) ChatbotDataService::answer('Apa nomor dokumennya?', 'admin', $ctx);
        t_contains($issueA, $ans, 'Lanjutan: nomor dokumen dari konteks');
        $ans = (string) ChatbotDataService::answer('Apa jenis penggunaannya?', 'admin', $ctx);
        t_contains('jenis penggunaan: service', $ans, 'Lanjutan: jenis penggunaan dari konteks');
        $ans = (string) ChatbotDataService::answer('Berapa jumlah masing-masing?', 'admin', $ctx);
        t_contains($nameA, $ans, 'Lanjutan: rekap per barang memuat nama sparepart');
        t_contains($codeA, $ans, 'Lanjutan: rekap per barang memuat kode sparepart');
        t_contains($fmtQty($dateIssueQty), $ans, 'Lanjutan: total qty konteks tanggal tetap disertakan');
        $ans = (string) ChatbotDataService::answer('Tampilkan detail dokumen pertama.', 'admin', $ctx);
        t_contains($firstDoc, $ans, 'Lanjutan: dokumen pertama mengarah ke dokumen yang benar');
        $ans = (string) ChatbotDataService::answer('Bagaimana dengan barang masuk pada tanggal yang sama?', 'admin', $ctx);
        t_contains($receiptA, $ans, 'Lanjutan: barang masuk pada tanggal yang sama');

        // Role: nama pelaku dibatasi untuk non-admin.
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi tersebut?', 'partman', $ctx);
        t_contains('hanya tersedia untuk Admin', $ans, 'Lanjutan role Partman tidak membocorkan nama pelaku');

        // Tanpa konteks, rujukan "tersebut" harus diklarifikasi, bukan menebak.
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi tersebut?', 'admin');
        t_contains('belum memiliki konteks', $ans, 'Rujukan tanpa konteks meminta klarifikasi');

        // Data lokal dijawab tanpa API key/provider.
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
        $before = t_cd_settings_snapshot();
        try {
            $s = ChatbotSettingsService::current();
            ChatbotSettingsService::save([
                'is_enabled'          => '1',
                'model'               => 'gemini-3.5-flash-lite',
                'system_prompt'       => $s['system_prompt'],
                'topic_limits'        => $s['topic_limits'],
                'answer_style'        => 'ringkas',
                'usage_limit_daily'   => '',
                'usage_limit_monthly' => '',
                'remove_api_key'      => '1',
            ], (int) $admin['id']);

            $res = ChatbotService::respond('Apa barang keluar pada tanggal ' . $dateLabel . '?', $admin);
            t_eq('ok', (string) $res['status'], 'Pertanyaan tanggal dijawab sukses tanpa API key');
            t_eq('data', (string) $res['source'], 'Pertanyaan tanggal memakai sumber data lokal');
            t_contains($issueA, (string) $res['answer'], 'Jawaban tanpa API memuat dokumen yang benar');
            $success = (int) $pdo->query("SELECT COUNT(*) FROM chatbot_usage_logs WHERE status = 'success'")->fetchColumn();
            t_eq(0, $success, 'Tidak ada panggilan API untuk pertanyaan tanggal');
        } finally {
            t_cd_settings_restore($before);
        }
    } finally {
        foreach ($issueIds as $id) {
            $pdo->prepare('DELETE FROM goods_issue_items WHERE issue_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM goods_issues WHERE id = ?')->execute([$id]);
        }
        foreach ($receiptIds as $id) {
            $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$id]);
        }
        if ($poId > 0) {
            $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id = ?')->execute([$poId]);
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        }
        foreach ([$partA, $partB, $partCritical] as $id) {
            $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM storage_locations WHERE id = ?')->execute([$locationId]);
    }
}

/**
 * Modul Manajemen Pengguna: identitas, role, login terakhir, gagal login,
 * riwayat login, aktivitas, konteks lanjutan, dan batas role.
 */
function test_chatbot_data_pengguna_login_dan_aktivitas(): void
{
    $pdo = Database::pdo();
    $tag = t_uniq('UJI');
    $hasanName = 'Hasan Khairi ' . $tag;
    $danuName = 'Danu Subakti ' . $tag;
    $neverName = 'Belum Login ' . $tag;
    $hasanUser = 'uji_hasan_' . strtolower(substr($tag, -7));
    $danuUser = 'uji_danu_' . strtolower(substr($tag, -7));
    $neverUser = 'uji_never_' . strtolower(substr($tag, -7));

    $hash = password_hash(t_password(), PASSWORD_DEFAULT);
    $supplierId = t_make_supplier('Supplier Aktivitas ' . $tag);
    $receiptId = null;
    $issueId = null;
    $opnameId = null;
    $poId = null;
    $adjustmentNumber = null;

    try {
        $pdo->prepare('DELETE FROM users WHERE username IN (?,?,?)')->execute([$hasanUser, $danuUser, $neverUser]);
        $insert = $pdo->prepare(
            'INSERT INTO users (username, full_name, email, password_hash, role_id, is_active,
                                failed_attempts, last_login_at, created_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$hasanUser, $hasanName, $hash, t_role_id('kepala_bengkel'), 1, 2,
            '2026-10-29 14:32:00', '2026-01-05 09:15:00']);
        $hasanId = (int) $pdo->lastInsertId();
        $insert->execute([$danuUser, $danuName, $hash, t_role_id('partman'), 1, 0,
            '2026-10-28 08:05:00', '2026-02-10 11:00:00']);
        $danuId = (int) $pdo->lastInsertId();
        $insert->execute([$neverUser, $neverName, $hash, t_role_id('partman'), 0, 0,
            null, '2026-03-01 10:00:00']);
        $neverId = (int) $pdo->lastInsertId();

        $pdo->prepare('DELETE FROM login_attempts WHERE username IN (?,?,?)')->execute([$hasanUser, $danuUser, $neverUser]);
        $attempt = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)'
        );
        $attempt->execute([$hasanUser, '127.0.0.1', 0, '2026-10-29 14:30:00']);
        $attempt->execute([$hasanUser, '127.0.0.1', 0, '2026-10-29 14:31:00']);
        $attempt->execute([$hasanUser, '127.0.0.1', 1, '2026-10-29 14:32:00']);
        $attempt->execute([$danuUser, '127.0.0.1', 1, '2026-10-28 08:05:00']);

        $pdo->prepare(
            'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, recorded_by)
             VALUES (?, ?, ?, ?)'
        )->execute([t_uniq('GR-UJI'), '2026-10-19', $supplierId, $hasanId]);
        $receiptId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO goods_issues (issue_number, issue_date, usage_type, recorded_by)
             VALUES (?, ?, ?, ?)'
        )->execute([t_uniq('GI-UJI'), '2026-10-18', 'service', $hasanId]);
        $issueId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO stock_opnames (opname_number, opname_date, status, started_by, confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([t_uniq('OP-UJI'), '2026-10-17', 'CONFIRMED', $hasanId, $hasanId, '2026-10-17 10:00:00']);
        $opnameId = (int) $pdo->lastInsertId();

        // Penyesuaian stok manual milik Hasan (sumber berbeda dari opname).
        $adjustmentNumber = t_uniq('ADJ-UJI');
        $sparepartId = (int) $pdo->query('SELECT id FROM spareparts ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after,
                 reference_type, reference_number, note, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $sparepartId, 'PENYESUAIAN', 1, '2026-10-16', 5, 6,
            'STOCK_ADJUSTMENT', $adjustmentNumber, 'Uji aktivitas penyesuaian', $hasanId,
        ]);

        $pdo->prepare(
            'INSERT INTO purchase_orders (po_number, supplier_id, status, total_amount, created_by, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([t_uniq('PO-UJI'), $supplierId, 'DISETUJUI', 0, $hasanId, $hasanId, '2026-10-16 09:00:00']);
        $poId = (int) $pdo->lastInsertId();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $active = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
        $inactive = $total - $active;

        $ans = (string) ChatbotDataService::answer('Ada berapa pengguna?', 'admin');
        t_contains(number_format($total, 0, ',', '.'), $ans, 'Jumlah pengguna sesuai database');
        t_contains(number_format($active, 0, ',', '.'), $ans, 'Jumlah pengguna aktif sesuai database');
        t_contains(number_format($inactive, 0, ',', '.'), $ans, 'Jumlah pengguna nonaktif sesuai database');

        $ans = (string) ChatbotDataService::answer('Siapa saja pengguna aktif?', 'admin');
        t_contains(number_format($active, 0, ',', '.'), $ans, 'Daftar pengguna aktif memuat jumlah sesuai database');
        t_contains($hasanName, $ans, 'Daftar pengguna aktif memuat pengguna aktual');

        $ans = (string) ChatbotDataService::answer('Siapa saja pengguna nonaktif?', 'admin');
        t_contains($neverName, $ans, 'Daftar pengguna nonaktif memuat pengguna aktual');

        $ans = (string) ChatbotDataService::answer('Apa role ' . $hasanName . '?', 'admin');
        t_contains('Kepala Bengkel', $ans, 'Role pengguna sesuai database');
        t_contains('aktif', $ans, 'Status akun pengguna disertakan');

        $ans = (string) ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir kali login?', 'admin');
        t_contains('29 Oktober 2026 pukul 14.32 WIB', $ans, 'Login terakhir memakai last_login_at berformat Indonesia');
        t_contains($hasanName, $ans, 'Login terakhir menyebut nama pengguna, bukan ID internal');
        t_true(!preg_match('/\bid\s*\d+/i', $ans), 'Login terakhir tidak menampilkan ID internal');

        $ans = (string) ChatbotDataService::answer('Kapan ' . $danuName . ' terakhir login?', 'admin');
        t_contains('28 Oktober 2026 pukul 08.05 WIB', $ans, 'Login terakhir pengguna kedua sesuai database');

        $ans = (string) ChatbotDataService::answer('Siapa pengguna yang terakhir login?', 'admin');
        t_true(str_contains($ans, 'Pengguna yang terakhir login adalah'), 'Pengguna dengan login terbaru dijawab');

        $ans = (string) ChatbotDataService::answer('Siapa yang belum pernah login?', 'admin');
        t_contains($neverName, $ans, 'Pengguna yang belum pernah login terdeteksi');
        t_true(!str_contains($ans, $hasanName), 'Pengguna yang sudah login tidak masuk daftar belum login');

        $failed = (int) $pdo->query(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ' . $pdo->quote($hasanUser) . ' AND success = 0'
        )->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa kali ' . $hasanName . ' gagal login?', 'admin');
        t_contains(number_format($failed, 0, ',', '.'), $ans, 'Jumlah gagal login sesuai login_attempts');
        t_contains('2', $ans, 'Percobaan gagal berturut-turut dari users.failed_attempts disertakan');

        $ans = (string) ChatbotDataService::answer('Siapa yang paling sering gagal login?', 'admin');
        t_contains('Pengguna yang paling sering gagal login', $ans, 'Peringkat gagal login dijawab');

        $ans = (string) ChatbotDataService::answer('Tampilkan riwayat login ' . $hasanName . '.', 'admin');
        t_contains($hasanName, $ans, 'Riwayat login menyebut pengguna yang benar');
        t_contains('berhasil', $ans, 'Riwayat login membedakan percobaan berhasil');
        t_contains('gagal', $ans, 'Riwayat login membedakan percobaan gagal');

        $ans = (string) ChatbotDataService::answer('Tampilkan detail ' . $hasanName . '.', 'admin');
        t_contains('Berikut informasi akun ' . $hasanName, $ans, 'Detail akun disusun sebagai kalimat lengkap');
        t_contains('Username: ' . $hasanUser, $ans, 'Detail akun memuat username');
        t_contains('Role: Kepala Bengkel', $ans, 'Detail akun memuat role');
        t_contains('Terakhir login: 29 Oktober 2026 pukul 14.32 WIB', $ans, 'Detail akun memuat login terakhir');
        t_true(!preg_match('/\bid\s*\d+/i', $ans), 'Detail akun tidak menampilkan ID internal');

        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi barang masuk?', 'admin');
        t_contains($hasanName, $ans, 'Aktivitas barang masuk memuat pencatat aktual');
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi barang keluar?', 'admin');
        t_contains($hasanName, $ans, 'Aktivitas barang keluar memuat pencatat aktual');
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan penyesuaian stok?', 'admin');
        t_contains($hasanName, $ans, 'Aktivitas penyesuaian stok memuat pelaku aktual');
        $ans = (string) ChatbotDataService::answer('Siapa yang menyetujui PO?', 'admin');
        t_contains($hasanName, $ans, 'Aktivitas persetujuan PO memuat approver aktual');
        $ans = (string) ChatbotDataService::answer('Siapa yang menerima barang?', 'admin');
        t_contains($hasanName, $ans, 'Aktivitas penerimaan barang memuat pelaku aktual');

        $ans = (string) ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir melakukan transaksi?', 'admin');
        t_contains('19 Oktober 2026', $ans, 'Transaksi terakhir memakai waktu transaksi, bukan waktu login');

        $ans = (string) ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir aktif?', 'admin');
        t_contains('Belum terdapat catatan aktivitas', $ans,
            'Aktivitas terakhir dibedakan dari login dan transaksi bila audit log kosong');

        $ans = (string) ChatbotDataService::answer('Apa username masing-masing?', 'admin');
        t_contains($hasanUser, $ans, 'Daftar username memuat username aktual');
        t_contains($danuUser, $ans, 'Daftar username memuat semua pengguna');

        // Konteks lanjutan: pertanyaan login terakhir diulang untuk pengguna lain.
        ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir kali login?', 'admin');
        $ctx = ChatbotDataService::lastContext();
        t_eq('user', (string) ($ctx['topic'] ?? ''), 'Konteks terakhir bertopik pengguna');
        t_eq('last_login', (string) ($ctx['intent'] ?? ''), 'Konteks menyimpan maksud login terakhir');
        $ans = (string) ChatbotDataService::answer('Bagaimana dengan ' . $danuName . '?', 'admin', $ctx);
        t_contains($danuName, $ans, 'Pertanyaan lanjutan memakai pengguna baru');
        t_contains('28 Oktober 2026 pukul 08.05 WIB', $ans, 'Pertanyaan lanjutan mengulang maksud login terakhir');

        // Batas role: data pengguna tidak boleh bocor ke Partman/Kepala Bengkel.
        t_eq(null, ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir login?', 'partman'),
            'Partman tidak menerima data login pengguna');
        t_eq(null, ChatbotDataService::answer('Kapan ' . $hasanName . ' terakhir login?', 'kepala_bengkel'),
            'Kepala Bengkel tidak menerima data login pengguna');
        $guard = ChatbotService::guard('Kapan ' . $hasanName . ' terakhir login?', 'partman');
        t_eq('role_scope', (string) ($guard['status'] ?? ''), 'Guard menolak pertanyaan login pengguna untuk Partman');
        t_true(!str_contains((string) ($guard['answer'] ?? ''), $hasanName), 'Jawaban role_scope tidak membocorkan nama pengguna');

        // Tanpa API key, pertanyaan login tetap dijawab dari database lokal.
        $before = t_cd_settings_snapshot();
        try {
            $s = ChatbotSettingsService::current();
            ChatbotSettingsService::save([
                'is_enabled'          => '1',
                'model'               => 'gemini-3.5-flash-lite',
                'system_prompt'       => $s['system_prompt'],
                'topic_limits'        => $s['topic_limits'],
                'answer_style'        => 'ringkas',
                'usage_limit_daily'   => '',
                'usage_limit_monthly' => '',
                'remove_api_key'      => '1',
            ], t_cd_user('admin')['id']);

            $res = ChatbotService::respond('Kapan ' . $hasanName . ' terakhir kali login?', t_cd_user('admin'));
            t_eq('ok', (string) $res['status'], 'Pertanyaan login dijawab sukses tanpa API key');
            t_eq('data', (string) $res['source'], 'Pertanyaan login memakai sumber data lokal');
            t_contains('29 Oktober 2026 pukul 14.32 WIB', (string) $res['answer'],
                'Jawaban tanpa API memuat waktu login yang benar');
            $success = (int) $pdo->query("SELECT COUNT(*) FROM chatbot_usage_logs WHERE status = 'success'")->fetchColumn();
            t_eq(0, $success, 'Tidak ada panggilan API sukses untuk pertanyaan login');
        } finally {
            t_cd_settings_restore($before);
        }

        t_true($hasanId > 0 && $danuId > 0 && $neverId > 0, 'Fixture pengguna uji dibuat');
    } finally {
        $pdo->prepare('DELETE FROM login_attempts WHERE username IN (?,?,?)')->execute([$hasanUser, $danuUser, $neverUser]);
        $pdo->prepare('DELETE FROM stock_movements WHERE reference_number = ?')->execute([$adjustmentNumber]);
        if ($poId !== null) {            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        }
        if ($opnameId !== null) {
            $pdo->prepare('DELETE FROM stock_opnames WHERE id = ?')->execute([$opnameId]);
        }
        if ($issueId !== null) {
            $pdo->prepare('DELETE FROM goods_issues WHERE id = ?')->execute([$issueId]);
        }
        if ($receiptId !== null) {
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$receiptId]);
        }
        $pdo->prepare('DELETE FROM users WHERE username IN (?,?,?)')->execute([$hasanUser, $danuUser, $neverUser]);
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
    }
}

/**
 * Regresi konteks percakapan (Skenario A-D).
 *
 * Setiap angka pembanding dihitung ulang langsung dari database agar tes
 * tidak bergantung pada fixture lama. Fixture dibuat sendiri dan dibersihkan.
 */
function test_chatbot_data_konteks_percakapan_skenario_a_sampai_d(): void
{
    $pdo = Database::pdo();
    $admin = t_cd_user('admin');
    $adminName = (string) $pdo->query('SELECT full_name FROM users WHERE id = ' . (int) $admin['id'])->fetchColumn();
    $tag = strtoupper(bin2hex(random_bytes(4)));
    $today = date('Y-m-d');
    $oldDate = '2025-02-10';
    $fmtQty = static function (float $value): string {
        $out = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
        return $out === '' ? '0' : $out;
    };
    $fmtInt = static fn (int $value): string => number_format($value, 0, ',', '.');

    $categoryId = t_make_category('Kategori Konteks Uji');
    $supplierId = t_make_supplier('Supplier Konteks Uji');
    $partA = t_make_sparepart($categoryId, [
        'name' => 'Part Konteks A ' . $tag, 'stock' => 50, 'min_stock' => 2, 'supplier_id' => $supplierId,
    ]);
    $partB = t_make_sparepart($categoryId, [
        'name' => 'Part Konteks B ' . $tag, 'stock' => 50, 'min_stock' => 2, 'supplier_id' => $supplierId,
    ]);
    // Nama berawalan AAA agar berada di urutan awal daftar stok menipis.
    $partC = t_make_sparepart($categoryId, [
        'name' => 'AAA Menipis Konteks ' . $tag, 'stock' => 12, 'min_stock' => 10, 'supplier_id' => $supplierId,
    ]);
    $nameA = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partA)->fetchColumn();
    $nameB = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partB)->fetchColumn();
    $nameC = (string) $pdo->query('SELECT name FROM spareparts WHERE id = ' . (int) $partC)->fetchColumn();
    $codeC = (string) $pdo->query('SELECT code FROM spareparts WHERE id = ' . (int) $partC)->fetchColumn();

    $receiptIds = [];
    $issueIds = [];
    $receiptToday = 'GR-CTX-' . $tag . '-T';
    $receiptOld = 'GR-CTX-' . $tag . '-O';
    $issueToday = 'GI-CTX-' . $tag . '-T';
    $issueOld = 'GI-CTX-' . $tag . '-O';

    try {
        foreach ([[$receiptToday, $today, 5], [$receiptOld, $oldDate, 7]] as [$number, $date, $qty]) {
            $pdo->prepare(
                'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, recorded_by) VALUES (?,?,?,?)'
            )->execute([$number, $date, $supplierId, (int) $admin['id']]);
            $id = (int) $pdo->lastInsertId();
            $receiptIds[] = $id;
            $pdo->prepare(
                'INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price) VALUES (?,?,?,?)'
            )->execute([$id, $partA, $qty, 1000]);
        }
        foreach ([[$issueToday, $today, 4], [$issueOld, $oldDate, 6]] as [$number, $date, $qty]) {
            $pdo->prepare(
                "INSERT INTO goods_issues (issue_number, issue_date, usage_type, recorded_by) VALUES (?,?, 'service', ?)"
            )->execute([$number, $date, (int) $admin['id']]);
            $id = (int) $pdo->lastInsertId();
            $issueIds[] = $id;
            $pdo->prepare('INSERT INTO goods_issue_items (issue_id, sparepart_id, quantity) VALUES (?,?,?)')
                ->execute([$id, $partB, $qty]);
        }

        // Angka pembanding langsung dari database.
        $allReceiptDocs = (int) $pdo->query('SELECT COUNT(*) FROM goods_receipts')->fetchColumn();
        $allReceiptQty = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items')->fetchColumn();
        $allIssueDocs = (int) $pdo->query('SELECT COUNT(*) FROM goods_issues')->fetchColumn();
        $allIssueQty = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items')->fetchColumn();
        $monthFrom = date('Y-m-01');
        $monthTo = date('Y-m-t');
        $monthReceiptQty = (float) $pdo->query(
            "SELECT COALESCE(SUM(gri.quantity), 0) FROM goods_receipt_items gri
               JOIN goods_receipts gr ON gr.id = gri.receipt_id
              WHERE gr.receipt_date BETWEEN '" . $monthFrom . "' AND '" . $monthTo . "'"
        )->fetchColumn();
        $monthReceiptDocs = (int) $pdo->query(
            "SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN '" . $monthFrom . "' AND '" . $monthTo . "'"
        )->fetchColumn();

        // ---------------- Skenario A: barang masuk ----------------
        $ctx = null;
        $ans = (string) ChatbotDataService::answer('Berapa total barang masuk?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($fmtInt($allReceiptDocs) . ' dokumen penerimaan', $ans, 'A1: jumlah dokumen barang masuk sesuai DB');
        t_contains('total qty ' . $fmtQty($allReceiptQty) . ' unit', $ans, 'A1: total qty barang masuk sesuai DB');
        t_eq('transaction', (string) ($ctx['topic'] ?? ''), 'A1: konteks topik transaksi tersimpan');
        t_eq('receipt', (string) ($ctx['module'] ?? ''), 'A1: konteks modul barang masuk tersimpan');

        $ans = (string) ChatbotDataService::answer('Berapa total masuknya bulan ini?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($fmtInt($monthReceiptDocs) . ' dokumen', $ans, 'A2: jumlah dokumen bulan ini sesuai DB');
        t_contains('qty ' . $fmtQty($monthReceiptQty), $ans, 'A2: qty barang masuk bulan ini sesuai DB');
        t_eq('month', (string) ($ctx['date_type'] ?? ''), 'A2: periode bulan ini dipertahankan di konteks');
        $receiptMonthCtx = $ctx;

        $ans = (string) ChatbotDataService::answer('Sebutkan rincian barangnya.', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($receiptToday, $ans, 'A3: rincian memuat dokumen barang masuk bulan ini');
        t_contains($nameA, $ans, 'A3: rincian memuat nama sparepart');
        t_true(!str_contains($ans, $receiptOld), 'A3: rincian tidak keluar dari periode bulan ini');

        $ans = (string) ChatbotDataService::answer('Siapa yang mencatat barang masuk tersebut?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($adminName, $ans, 'A4: pelaku pencatatan berasal dari data transaksi');

        $ans = (string) ChatbotDataService::answer('Kapan transaksi terakhir?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains('barang masuk', $ans, 'A5: transaksi terakhir tetap pada modul barang masuk');
        t_contains($receiptToday, $ans, 'A5: transaksi terakhir adalah dokumen barang masuk terbaru');

        // ---------------- Skenario B: barang keluar ----------------
        $ctx = null;
        $ans = (string) ChatbotDataService::answer('Berapa total barang keluar?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($fmtInt($allIssueDocs) . ' dokumen pengeluaran', $ans, 'B1: jumlah dokumen barang keluar sesuai DB');
        t_contains('total qty ' . $fmtQty($allIssueQty) . ' unit', $ans, 'B1: total qty barang keluar sesuai DB');
        t_eq('issue', (string) ($ctx['module'] ?? ''), 'B1: konteks modul barang keluar tersimpan');

        $ans = (string) ChatbotDataService::answer('Barang apa saja yang keluar?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($issueToday, $ans, 'B2: daftar memuat dokumen barang keluar');
        t_contains($nameB, $ans, 'B2: daftar memuat nama barang yang keluar');

        $ans = (string) ChatbotDataService::answer('Berapa jumlah masing-masing barang?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($nameB, $ans, 'B3: rekap per barang memuat nama sparepart');
        t_contains('10 pcs', $ans, 'B3: rekap menjumlahkan qty per barang dari seluruh periode');
        t_contains('Total keseluruhan barang keluar: ' . $fmtQty($allIssueQty) . ' unit.', $ans,
            'B3: total keseluruhan barang keluar sesuai DB');

        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan transaksi?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($adminName, $ans, 'B4: pelaku transaksi berasal dari data transaksi');
        t_contains($issueToday, $ans, 'B4: pelaku ditampilkan per dokumen barang keluar');

        // ---------------- Skenario C: stok ----------------
        $counts = t_cd_stock_counts();
        $ctx = null;
        $ans = (string) ChatbotDataService::answer('Berapa jumlah sparepart yang stoknya menipis?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($fmtInt((int) $counts['menipis']) . ' sparepart', $ans, 'C1: jumlah stok menipis sesuai DB');
        t_eq('stock', (string) ($ctx['topic'] ?? ''), 'C1: konteks topik stok tersimpan');
        t_eq('menipis', (string) ($ctx['filter'] ?? ''), 'C1: filter status menipis tersimpan');

        $ans = (string) ChatbotDataService::answer('Sebutkan barangnya.', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($nameC, $ans, 'C2: daftar memuat sparepart berstatus menipis');
        t_contains($codeC, $ans, 'C2: daftar memuat kode sparepart');

        $ans = (string) ChatbotDataService::answer('Berapa stok masing-masing?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($nameC, $ans, 'C3: stok per barang tetap pada sparepart yang dibahas');
        t_contains('stok 12 pcs, minimum 10 pcs', $ans, 'C3: stok dan minimum sesuai DB');

        $ans = (string) ChatbotDataService::answer('Mana yang harus segera dipesan?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains('reorder', $ans, 'C4: rekomendasi reorder dari data stok');
        t_eq('reorder', (string) ($ctx['topic'] ?? ''), 'C4: konteks reorder tersimpan');

        // ---------------- Skenario D: perubahan topik ----------------
        $ctx = null;
        $ans = (string) ChatbotDataService::answer('Berapa total barang masuk?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains('total qty ' . $fmtQty($allReceiptQty) . ' unit', $ans, 'D1: total barang masuk sesuai DB');

        $ans = (string) ChatbotDataService::answer('Sekarang berapa total barang keluar?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains('total qty ' . $fmtQty($allIssueQty) . ' unit', $ans, 'D2: perubahan topik memakai data barang keluar');
        t_eq('issue', (string) ($ctx['module'] ?? ''), 'D2: konteks modul berubah ke barang keluar');

        $ans = (string) ChatbotDataService::answer('Bagaimana rincian barang keluar?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains($issueToday, $ans, 'D3: rincian barang keluar memuat dokumen terkait');
        t_contains($nameB, $ans, 'D3: rincian barang keluar memuat nama barang');

        $ans = (string) ChatbotDataService::answer('Kembali ke pembahasan barang masuk, berapa jumlahnya?', 'admin', $ctx);
        $ctx = ChatbotDataService::lastContext();
        t_contains('total qty ' . $fmtQty($allReceiptQty) . ' unit', $ans, 'D4: kembali ke barang masuk dengan angka benar');
        t_eq('receipt', (string) ($ctx['module'] ?? ''), 'D4: konteks kembali ke modul barang masuk');

        // ---------------- Klarifikasi ambigu ----------------
        $ans = (string) ChatbotDataService::answer('Apakah ada yang belum selesai?', 'admin', $ctx);
        t_contains('Maksud Anda', $ans, 'Pertanyaan ambigu meminta klarifikasi, bukan menebak');

        // ---------------- Memori untuk model ----------------
        $history = [];
        for ($i = 1; $i <= 15; $i++) {
            $history[] = ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'text' => 'pesan ' . $i];
        }
        $history[] = ['role' => 'system', 'text' => 'peran tidak valid'];
        $conversation = ChatbotService::conversationForModel($history, 'Ringkasan lama');
        $texts = array_column($conversation, 'text');
        t_eq('Ringkasan percakapan sebelumnya: Ringkasan lama', $texts[0] ?? '', 'Memori: ringkasan dikirim sebagai konteks');
        t_eq(11, count($conversation), 'Memori: ringkasan + maksimal 10 pesan terakhir');
        t_true(!in_array('peran tidak valid', $texts, true), 'Memori: role tidak valid tidak dikirim');
        t_true(in_array('pesan 15', $texts, true), 'Memori: pesan terbaru tetap disertakan');
        t_true(!in_array('pesan 1', $texts, true), 'Memori: pesan lama di luar jendela tidak dikirim mentah');

        $long = ChatbotService::conversationForModel(
            [['role' => 'user', 'text' => str_repeat('x', 900)]],
            ''
        );
        t_eq(ChatbotService::HISTORY_TEXT_MAX, mb_strlen((string) ($long[0]['text'] ?? '')),
            'Memori: panjang pesan dibatasi');

        // ---------------- Tanpa API key tetap menjawab dari DB ----------------
        $before = t_cd_settings_snapshot();
        try {
            $s = ChatbotSettingsService::current();
            ChatbotSettingsService::save([
                'is_enabled'          => '1',
                'model'               => 'gemini-3.5-flash-lite',
                'system_prompt'       => $s['system_prompt'],
                'topic_limits'        => $s['topic_limits'],
                'answer_style'        => 'ringkas',
                'usage_limit_daily'   => '',
                'usage_limit_monthly' => '',
                'remove_api_key'      => '1',
            ], (int) $admin['id']);

            $res = ChatbotService::respond(
                'Berapa total masuknya bulan ini?',
                $admin,
                null,
                null,
                $receiptMonthCtx,
                [['role' => 'user', 'text' => 'Berapa total barang masuk?']],
                ''
            );
            t_eq('ok', (string) $res['status'], 'Tanpa API: pertanyaan lanjutan dijawab sukses');
            t_eq('data', (string) $res['source'], 'Tanpa API: jawaban tetap berasal dari database lokal');
            t_contains($fmtInt($monthReceiptDocs) . ' dokumen', (string) $res['answer'],
                'Tanpa API: angka bulan ini tetap benar');
        } finally {
            t_cd_settings_restore($before);
        }
    } finally {
        foreach ($issueIds as $id) {
            $pdo->prepare('DELETE FROM goods_issue_items WHERE issue_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM goods_issues WHERE id = ?')->execute([$id]);
        }
        foreach ($receiptIds as $id) {
            $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$id]);
        }
        foreach ([$partA, $partB, $partC] as $partId) {
            $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$partId]);
        }
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
}

/**
 * Pemisahan Penyesuaian Stok dan Stock Opname (12 pertanyaan wajib).
 *
 * Angka pembanding dihitung ulang langsung dari database: Penyesuaian Stok
 * dari stock_movements tipe PENYESUAIAN, Stock Opname dari stock_opnames +
 * stock_opname_items. Fixture dibuat dan dibersihkan di dalam tes.
 */
function test_chatbot_data_penyesuaian_dan_opname_terpisah(): void
{
    $pdo = Database::pdo();
    $admin = t_cd_user('admin');
    $adminName = (string) $pdo->query('SELECT full_name FROM users WHERE id = ' . (int) $admin['id'])->fetchColumn();
    $tag = strtoupper(bin2hex(random_bytes(4)));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $partIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $pdo->query('SELECT id FROM spareparts ORDER BY id LIMIT 3')->fetchAll()
    );
    if ($partIds === []) {
        t_true(false, 'Fixture tes pemisahan modul membutuhkan minimal satu sparepart');
        return;
    }

    $adjOld = 'ADJ-CHAT-' . $tag . '-1';
    $adjNew = 'ADJ-CHAT-' . $tag . '-2';
    $adjFresh = 'ADJ-CHAT-' . $tag . '-3';
    $soNumber = 'SO-CHAT-' . $tag;
    $opnameId = null;
    $createdMovements = [$adjOld, $adjNew, $adjFresh];

    $fmtQty = static function (float $value): string {
        $out = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
        return $out === '' ? '0' : $out;
    };
    $fmtInt = static fn (int $value): string => number_format($value, 0, ',', '.');

    try {
        $insertMovement = $pdo->prepare(
            'INSERT INTO stock_movements
                (sparepart_id, movement_type, quantity, movement_date, stock_before, stock_after,
                 reference_type, reference_number, note, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // Dua dokumen penyesuaian: satu penambahan dan satu pengurangan.
        $insertMovement->execute([
            $partIds[0], 'PENYESUAIAN', 4, $yesterday, 10, 14,
            'STOCK_ADJUSTMENT', $adjOld, 'Penambahan uji pemisahan', (int) $admin['id'],
        ]);
        $insertMovement->execute([
            $partIds[0], 'PENYESUAIAN', -2, $today, 14, 12,
            'STOCK_ADJUSTMENT', $adjNew, 'Pengurangan uji pemisahan', (int) $admin['id'],
        ]);

        // Sesi stock opname dengan dua item dihitung dan satu selisih.
        $pdo->prepare(
            'INSERT INTO stock_opnames (opname_number, opname_date, status, note, started_by, confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$soNumber, $today, 'CONFIRMED', 'Uji pemisahan modul', (int) $admin['id'],
            (int) $admin['id'], $today . ' 10:00:00']);
        $opnameId = (int) $pdo->lastInsertId();
        $insertItem = $pdo->prepare(
            'INSERT INTO stock_opname_items
                (opname_id, sparepart_id, system_stock, physical_stock, difference, reason, counted_by, counted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem->execute([$opnameId, $partIds[0], 10, 13, 3, 'Selisih uji', (int) $admin['id'], $today . ' 09:00:00']);
        $insertItem->execute([$opnameId, $partIds[1], 5, 5, 0, null, (int) $admin['id'], $today . ' 09:05:00']);
        if (isset($partIds[2])) {
            $insertItem->execute([$opnameId, $partIds[2], 8, null, null, null, null, null]);
        }

        $adjStats = $pdo->query(
            "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id))) AS docs,
                    COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) AS addition,
                    COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) AS reduction
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        )->fetch();
        $lastAdj = (string) $pdo->query(
            "SELECT reference_number FROM stock_movements WHERE movement_type = 'PENYESUAIAN'
              ORDER BY movement_date DESC, id DESC LIMIT 1"
        )->fetchColumn();

        $soStats = $pdo->query(
            'SELECT COUNT(*) AS sessions,
                    COALESCE(SUM(CASE WHEN physical_stock IS NOT NULL THEN 1 ELSE 0 END), 0) AS checked,
                    COALESCE(SUM(CASE WHEN physical_stock IS NOT NULL AND difference <> 0 THEN 1 ELSE 0 END), 0) AS diffs,
                    COALESCE(SUM(CASE WHEN physical_stock IS NOT NULL THEN ABS(difference) ELSE 0 END), 0) AS abs_diff
               FROM stock_opname_items'
        )->fetch();
        $soSessions = (int) $pdo->query('SELECT COUNT(*) FROM stock_opnames')->fetchColumn();
        $lastSo = (string) $pdo->query(
            'SELECT opname_number FROM stock_opnames
              ORDER BY COALESCE(opname_date, DATE(started_at)) DESC, id DESC LIMIT 1'
        )->fetchColumn();

        // 1. Ada berapa penyesuaian stok?
        $ans = (string) ChatbotDataService::answer('Ada berapa penyesuaian stok?', 'admin');
        t_contains($fmtInt((int) $adjStats['docs']) . ' dokumen penyesuaian stok', $ans,
            'PS1: jumlah dokumen penyesuaian dari stock_movements PENYESUAIAN');
        t_contains($adjOld, $ans, 'PS1: dokumen ADJ lama ditampilkan');
        t_contains($adjNew, $ans, 'PS1: dokumen ADJ baru ditampilkan');

        // 2. Tampilkan semua dokumen penyesuaian stok.
        $ans = (string) ChatbotDataService::answer('Tampilkan semua dokumen penyesuaian stok.', 'admin');
        t_contains($adjOld, $ans, 'PS2: daftar penyesuaian memuat dokumen pertama');
        t_contains($adjNew, $ans, 'PS2: daftar penyesuaian memuat dokumen kedua');
        t_true(!str_contains($ans, $soNumber), 'PS2: daftar penyesuaian tidak mencampur dokumen SO');

        // 3. Apa penyesuaian stok terakhir?
        $ans = (string) ChatbotDataService::answer('Apa penyesuaian stok terakhir?', 'admin');
        t_contains($lastAdj, $ans, 'PS3: penyesuaian terakhir sesuai urutan tanggal/ID');

        // 4. Siapa yang melakukan penyesuaian stok?
        $ans = (string) ChatbotDataService::answer('Siapa yang melakukan penyesuaian stok?', 'admin');
        t_contains($adminName, $ans, 'PS4: pelaku penyesuaian berasal dari stock_movements.user_id');

        // 5-6. Total penambahan dan pengurangan dihitung terpisah.
        $ans = (string) ChatbotDataService::answer('Berapa total penambahan stok?', 'admin');
        t_contains($fmtQty((float) $adjStats['addition']) . ' unit', $ans,
            'PS5: total penambahan sesuai SUM quantity positif');
        $ans = (string) ChatbotDataService::answer('Berapa total pengurangan stok?', 'admin');
        t_contains($fmtQty((float) $adjStats['reduction']) . ' unit', $ans,
            'PS6: total pengurangan sesuai SUM quantity negatif');

        // 7. Ada berapa stock opname?
        $ans = (string) ChatbotDataService::answer('Ada berapa stock opname?', 'admin');
        t_contains($fmtInt($soSessions) . ' sesi stock opname', $ans,
            'SO1: jumlah sesi dari stock_opnames');
        t_contains($soNumber, $ans, 'SO1: sesi SO uji ditampilkan');
        t_true(!str_contains($ans, $adjNew), 'SO1: daftar opname tidak mencampur dokumen ADJ');

        // 8. Tampilkan riwayat stock opname.
        $ans = (string) ChatbotDataService::answer('Tampilkan riwayat stock opname.', 'admin');
        t_contains($soNumber, $ans, 'SO2: riwayat opname memuat sesi SO');
        t_contains('Item diperiksa', $ans, 'SO2: riwayat opname menghitung item diperiksa');

        // 9. Kapan stock opname terakhir?
        $ans = (string) ChatbotDataService::answer('Kapan stock opname terakhir?', 'admin');
        t_contains($lastSo, $ans, 'SO3: opname terakhir sesuai tanggal sesi');

        // 10. Selisih stok fisik dan sistem.
        $ans = (string) ChatbotDataService::answer('Berapa selisih stok fisik dan sistem?', 'admin');
        t_contains('Total item berselisih: ' . $fmtInt((int) $soStats['diffs']), $ans,
            'SO4: jumlah item berselisih sesuai stock_opname_items');
        t_contains('Total selisih absolut yang perlu dikoreksi: ' . $fmtQty((float) $soStats['abs_diff']), $ans,
            'SO4: total selisih absolut sesuai data');

        // 11-12. Perbandingan dua fitur.
        foreach (['Apakah penyesuaian stok sama dengan stock opname?', 'Apa perbedaan kedua fitur tersebut?'] as $i => $question) {
            $ans = (string) ChatbotDataService::answer($question, 'admin');
            t_contains('dua fitur yang berbeda', $ans, 'Banding #' . ($i + 1) . ': kedua fitur dibedakan');
            t_contains('stock_movements', $ans, 'Banding #' . ($i + 1) . ': sumber penyesuaian dijelaskan');
            t_contains('stock_opnames', $ans, 'Banding #' . ($i + 1) . ': sumber opname dijelaskan');
        }

        // Istilah ambigu "koreksi" harus meminta klarifikasi, bukan menebak.
        $ans = (string) ChatbotDataService::answer('Berapa koreksi stok?', 'admin');
        t_contains('perlu memastikan modul', $ans, 'Istilah ambigu meminta klarifikasi modul');

        // Data berubah → jawaban harus mengikuti database terbaru.
        $insertMovement->execute([
            $partIds[0], 'PENYESUAIAN', 1, $today, 12, 13,
            'STOCK_ADJUSTMENT', $adjFresh, 'Dokumen baru uji freshness', (int) $admin['id'],
        ]);
        $afterDocs = (int) $pdo->query(
            "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id)))
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        )->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Ada berapa penyesuaian stok?', 'admin');
        t_contains($fmtInt($afterDocs) . ' dokumen penyesuaian stok', $ans,
            'Freshness: jumlah dokumen mengikuti data terbaru');
        t_contains($adjFresh, $ans, 'Freshness: dokumen baru langsung terbaca');

        // Konteks lanjutan tetap pada modul penyesuaian.
        $ctx = ChatbotDataService::lastContext();
        t_eq('adjustment', (string) ($ctx['module'] ?? ''), 'Konteks modul penyesuaian tersimpan terpisah');
        $ans = (string) ChatbotDataService::answer('Siapa yang mencatat transaksi tersebut?', 'admin', $ctx);
        t_contains($adminName, $ans, 'Konteks lanjutan penyesuaian memakai pelaku yang benar');
    } finally {
        if ($opnameId !== null) {
            $pdo->prepare('DELETE FROM stock_opname_items WHERE opname_id = ?')->execute([$opnameId]);
            $pdo->prepare('DELETE FROM stock_opnames WHERE id = ?')->execute([$opnameId]);
        }
        foreach ($createdMovements as $number) {
            $pdo->prepare('DELETE FROM stock_movements WHERE reference_number = ?')->execute([$number]);
        }
    }
}

/** Format angka ribuan seperti tampilan chatbot. */
function t_cd_fmt_int(int|float $value): string
{
    return number_format((float) $value, 0, ',', '.');
}

/** Format kuantitas seperti tampilan chatbot qty(). */
function t_cd_fmt_qty(float $value): string
{
    $out = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    return $out === '' ? '0' : $out;
}

/**
 * Regresi 16 pertanyaan revisi hybrid (bagian 9).
 *
 * Setiap angka pembanding dihitung langsung dari database, bukan dari jawaban
 * chatbot. Fixture dokumen tanggal/PO dibuat agar modul transaksi, PO, dan
 * penyesuaian tidak bergantung pada data contoh yang mungkin kosong.
 */
function test_chatbot_data_16_pertanyaan_revisi_hybrid(): void
{
    $pdo = Database::pdo();
    $admin = t_cd_user('admin');
    $tag = strtoupper(bin2hex(random_bytes(4)));
    $date = '2031-01-15';
    $dateLabel = '15 Januari 2031';

    $categoryId = t_make_category('Kategori 16 Q ' . $tag);
    $supplierId = t_make_supplier('Supplier 16 Q ' . $tag);
    $partId = t_make_sparepart((int) $categoryId, [
        'name' => 'Sparepart 16 Q ' . $tag,
        'stock' => 25,
        'min_stock' => 3,
        'supplier_id' => $supplierId,
    ]);

    $issueNumber = 'GI-16Q-' . $tag;
    $receiptNumber = 'GR-16Q-' . $tag;
    $poNumber = 'PO-16Q-' . $tag;
    $issueId = 0;
    $receiptId = 0;
    $poId = 0;
    $originalLogin = null;
    $loginUserId = (int) $admin['id'];
    $loginName = (string) $pdo->query('SELECT full_name FROM users WHERE id = ' . $loginUserId)->fetchColumn();
    $originalLogin = $pdo->query('SELECT last_login_at FROM users WHERE id = ' . $loginUserId)->fetchColumn();

    try {
        $pdo->prepare(
            'INSERT INTO goods_issues (issue_number, issue_date, usage_type, note, recorded_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$issueNumber, $date, 'service', 'Uji 16 pertanyaan', $loginUserId]);
        $issueId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO goods_issue_items (issue_id, sparepart_id, quantity) VALUES (?, ?, ?)')
            ->execute([$issueId, $partId, 4]);

        $pdo->prepare(
            'INSERT INTO goods_receipts (receipt_number, receipt_date, supplier_id, note, recorded_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$receiptNumber, $date, $supplierId, 'Uji 16 pertanyaan', $loginUserId]);
        $receiptId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO goods_receipt_items (receipt_id, sparepart_id, quantity, unit_price) VALUES (?, ?, ?, ?)')
            ->execute([$receiptId, $partId, 6, 10000]);

        $pdo->prepare(
            "INSERT INTO purchase_orders (po_number, supplier_id, status, payment_status, total_amount, note, created_by, created_at)
             VALUES (?, ?, 'MENUNGGU_PERSETUJUAN', 'BELUM_DIBAYAR', 50000, 'Uji 16 pertanyaan', ?, ?)"
        )->execute([$poNumber, $supplierId, $loginUserId, $date . ' 08:00:00']);
        $poId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_order_items (po_id, sparepart_id, quantity, unit_price, received_qty)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$poId, $partId, 10, 5000, 0]);

        // 1. Total sparepart.
        $totalParts = (int) $pdo->query('SELECT COUNT(*) FROM spareparts')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa total sparepart?', 'admin');
        t_contains(t_cd_fmt_int($totalParts), $ans, '#1 total sparepart sesuai database');

        // 2. Jumlah kategori.
        $totalCategories = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa jumlah kategori?', 'admin');
        t_contains(t_cd_fmt_int($totalCategories), $ans, '#2 jumlah kategori sesuai database');

        // 3. Jumlah supplier.
        $totalSuppliers = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa jumlah supplier?', 'admin');
        t_contains(t_cd_fmt_int($totalSuppliers), $ans, '#3 jumlah supplier sesuai database');

        // 4. Jumlah lokasi/rak.
        $totalLocations = (int) $pdo->query('SELECT COUNT(*) FROM storage_locations')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa jumlah lokasi dan rak?', 'admin');
        t_contains(t_cd_fmt_int($totalLocations), $ans, '#4 jumlah lokasi/rak sesuai database');

        // 5. Total stok sparepart aktif.
        $totalStock = (float) $pdo->query('SELECT COALESCE(SUM(stock), 0) FROM spareparts WHERE is_active = 1')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa total stok?', 'admin');
        t_contains(t_cd_fmt_int($totalStock), $ans, '#5 total stok sesuai database');

        // 6. Barang masuk (dokumen + baris item + qty).
        $receiptDocs = (int) $pdo->query('SELECT COUNT(*) FROM goods_receipts')->fetchColumn();
        $receiptItems = (int) $pdo->query('SELECT COUNT(*) FROM goods_receipt_items')->fetchColumn();
        $receiptQty = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa barang masuk?', 'admin');
        if ($receiptDocs === 0) {
            t_contains('Belum ada', $ans, '#6 barang masuk kosong dinyatakan benar');
        } else {
            t_contains(t_cd_fmt_int($receiptDocs) . ' dokumen penerimaan', $ans, '#6 dokumen penerimaan sesuai database');
            t_contains(t_cd_fmt_int($receiptItems) . ' baris item', $ans, '#6 baris item penerimaan sesuai database');
            t_contains(t_cd_fmt_qty($receiptQty) . ' unit', $ans, '#6 qty penerimaan sesuai database');
        }

        // 7. Barang keluar.
        $issueDocs = (int) $pdo->query('SELECT COUNT(*) FROM goods_issues')->fetchColumn();
        $issueItems = (int) $pdo->query('SELECT COUNT(*) FROM goods_issue_items')->fetchColumn();
        $issueQty = (float) $pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa barang keluar?', 'admin');
        if ($issueDocs === 0) {
            t_contains('Belum ada', $ans, '#7 barang keluar kosong dinyatakan benar');
        } else {
            t_contains(t_cd_fmt_int($issueDocs) . ' dokumen pengeluaran', $ans, '#7 dokumen pengeluaran sesuai database');
            t_contains(t_cd_fmt_int($issueItems) . ' baris item', $ans, '#7 baris item pengeluaran sesuai database');
            t_contains(t_cd_fmt_qty($issueQty) . ' unit', $ans, '#7 qty pengeluaran sesuai database');
        }

        // 8. Penyesuaian stok (bukan stock opname).
        $adjustmentDocs = (int) $pdo->query(
            "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id)))
               FROM stock_movements WHERE movement_type = 'PENYESUAIAN'"
        )->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa penyesuaian stok?', 'admin');
        if ($adjustmentDocs === 0) {
            t_contains('Belum ada', $ans, '#8 penyesuaian kosong dinyatakan benar');
        } else {
            t_contains(t_cd_fmt_int($adjustmentDocs) . ' dokumen penyesuaian stok', $ans,
                '#8 dokumen penyesuaian dari stock_movements PENYESUAIAN');
        }

        // 9. Stock opname (sesi).
        $opnameSessions = (int) $pdo->query('SELECT COUNT(*) FROM stock_opnames')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Berapa stock opname?', 'admin');
        if ($opnameSessions === 0) {
            t_contains('Belum ada', $ans, '#9 opname kosong dinyatakan benar');
        } else {
            t_contains(t_cd_fmt_int($opnameSessions), $ans, '#9 jumlah sesi opname sesuai database');
        }

        // 10. Rincian transaksi berdasarkan tanggal: klarifikasi lalu dijawab.
        $ans = (string) ChatbotDataService::answer('Tampilkan rincian transaksi berdasarkan tanggal.', 'admin');
        t_contains('Sebutkan tanggal', $ans, '#10 tanpa tanggal meminta klarifikasi');
        t_true(!str_contains($ans, 'seluruh periode'), '#10 tidak menebak periode');
        $ans = (string) ChatbotDataService::answer(
            'Tampilkan rincian transaksi berdasarkan tanggal ' . $dateLabel . '.',
            'admin'
        );
        t_contains($issueNumber, $ans, '#10 rincian tanggal memuat dokumen keluar uji');
        t_contains($receiptNumber, $ans, '#10 rincian tanggal memuat dokumen masuk uji');
        t_contains($dateLabel, $ans, '#10 rincian tanggal memakai label Indonesia');

        // 11. Pengguna terakhir login.
        $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute(['2099-12-31 23:59:59', $loginUserId]);
        $ans = (string) ChatbotDataService::answer('Siapa yang terakhir melakukan login?', 'admin');
        t_contains($loginName, $ans, '#11 pengguna terakhir login sesuai last_login_at');
        t_contains('terakhir login', $ans, '#11 jawaban menyebut waktu login terakhir');

        // 12. Pengguna aktif.
        $activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
        $allUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Siapa saja pengguna aktif?', 'admin');
        if ($activeUsers === 0) {
            t_contains('Tidak ada pengguna aktif', $ans, '#12 pengguna aktif kosong dinyatakan benar');
        } else {
            t_contains(t_cd_fmt_int($activeUsers), $ans, '#12 jumlah pengguna aktif sesuai database');
            t_contains(t_cd_fmt_int($allUsers), $ans, '#12 total pengguna sesuai database');
        }

        // 13. PO yang belum diterima.
        $ans = (string) ChatbotDataService::answer('Tampilkan PO yang belum diterima.', 'admin');
        t_contains($poNumber, $ans, '#13 PO belum diterima memuat PO uji');
        t_contains('dipesan 10', $ans, '#13 rincian PO memuat jumlah dipesan');
        t_contains('diterima 0', $ans, '#13 rincian PO memuat jumlah diterima');
        t_contains('sisa 10', $ans, '#13 rincian PO memuat sisa');

        // 14. Perbedaan penyesuaian stok dan stock opname.
        $ans = (string) ChatbotDataService::answer('Apa perbedaan penyesuaian stok dan stock opname?', 'admin');
        t_contains('dua fitur yang berbeda', $ans, '#14 kedua modul dibedakan');
        t_contains('stock_movements', $ans, '#14 sumber penyesuaian dijelaskan');
        t_contains('stock_opnames', $ans, '#14 sumber opname dijelaskan');

        // 15. Analisis/rekomendasi pemesanan (prediksi + reorder).
        $ans = (string) ChatbotDataService::answer('Analisis sparepart yang perlu segera dipesan.', 'admin');
        t_true(
            str_contains($ans, 'reorder') || str_contains($ans, 'dipesan') || str_contains($ans, 'prediksi'),
            '#15 analisis pemesanan memakai data prediksi/status stok'
        );
        t_true(!str_contains($ans, 'tidak tersedia di konteks'), '#15 tidak memakai alasan konteks kosong');

        // 16. Follow-up supplier: "Tampilkan semuanya."
        $ans = (string) ChatbotDataService::answer('Ada berapa supplier?', 'admin');
        $supplierContext = ChatbotDataService::lastContext();
        t_eq('supplier', (string) ($supplierContext['topic'] ?? ''), '#16 konteks supplier tersimpan');
        $supplierName = (string) $pdo->query(
            'SELECT name FROM suppliers WHERE id = ' . (int) $supplierId
        )->fetchColumn();
        $ans = (string) ChatbotDataService::answer('Tampilkan semuanya.', 'admin', $supplierContext);
        t_contains($supplierName, $ans, '#16 follow-up menampilkan daftar supplier');
        t_true(!str_contains($ans, 'belum memiliki konteks'), '#16 follow-up tidak meminta klarifikasi');
    } finally {
        if ($issueId > 0) {
            $pdo->prepare('DELETE FROM goods_issue_items WHERE issue_id = ?')->execute([$issueId]);
            $pdo->prepare('DELETE FROM goods_issues WHERE id = ?')->execute([$issueId]);
        }
        if ($receiptId > 0) {
            $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id = ?')->execute([$receiptId]);
            $pdo->prepare('DELETE FROM goods_receipts WHERE id = ?')->execute([$receiptId]);
        }
        if ($poId > 0) {
            $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id = ?')->execute([$poId]);
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
        }
        $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([$originalLogin === false ? null : $originalLogin, $loginUserId]);
        $pdo->prepare('DELETE FROM spareparts WHERE id = ?')->execute([$partId]);
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
    }
}

/**
 * Paket grounding per pertanyaan: modul relevan, batas role, tanpa rahasia,
 * dan validasi angka hasil AI terhadap sumber.
 */
function test_chatbot_data_grounding_dan_validasi_ai(): void
{
    // Admin mendapat data pengguna; Partman tidak.
    $adminGround = ChatbotDataService::fallbackContext('Siapa saja pengguna aktif?', 'admin');
    t_true(isset($adminGround['data']['pengguna']), 'Grounding Admin memuat data pengguna');
    t_true(isset($adminGround['data']['pengguna']['ringkasan']), 'Grounding Admin memuat ringkasan pengguna');
    t_contains('Admin', (string) ($adminGround['batas_akses'] ?? ''), 'Batas akses Admin dijelaskan');

    $partmanGround = ChatbotDataService::fallbackContext('Siapa saja pengguna aktif?', 'partman');
    t_true(!isset($partmanGround['data']['pengguna']), 'Grounding Partman TIDAK memuat data pengguna');
    t_contains('tidak tersedia', (string) ($partmanGround['batas_akses'] ?? ''),
        'Batas akses Partman menyatakan data pengguna tidak tersedia');

    // Konteks hanya memuat modul yang relevan.
    $supplierGround = ChatbotDataService::fallbackContext('Ada berapa supplier?', 'partman');
    t_true(in_array('supplier', (array) $supplierGround['topik_terdeteksi'], true),
        'Topik supplier terdeteksi');
    t_true(isset($supplierGround['data']['supplier']), 'Grounding supplier memuat ringkasan supplier');
    t_true(!isset($supplierGround['data']['pengguna']), 'Grounding supplier tidak memuat pengguna');
    t_true(isset($supplierGround['sumber_data']['supplier']), 'Sumber data supplier dijelaskan');
    t_true(isset($supplierGround['angka']['total_jenis_sparepart']), 'Angka Dashboard disertakan untuk validasi');
    t_true(isset($supplierGround['aturan_validasi']), 'Aturan validasi angka dikirim ke AI');

    // Tidak ada rahasia/kredensial di paket.
    $json = json_encode($supplierGround, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    t_true(!str_contains($json, 'api_key'), 'Grounding tidak memuat API key');
    t_true(!str_contains(mb_strtolower($json), 'password'), 'Grounding tidak memuat password');
    t_true(!str_contains($json, 'token'), 'Grounding tidak memuat token');

    // Validasi angka: angka sumber diterima, angka fiktif ditolak.
    $total = (int) ($adminGround['angka']['total_jenis_sparepart'] ?? 0);
    $valid = ChatbotService::validateAiAnswer(
        'Jumlah sparepart aktif saat ini ' . t_cd_fmt_int($total) . ' jenis.',
        $adminGround
    );
    t_true((bool) ($valid['ok'] ?? false), 'Angka sumber lolos validasi AI');

    $invalid = ChatbotService::validateAiAnswer(
        'Jumlah sparepart aktif saat ini 987654 jenis.',
        $adminGround
    );
    t_true(!(bool) ($invalid['ok'] ?? true), 'Angka fiktif ditolak validasi AI');
    t_contains('Ringkasan data yang terverifikasi', (string) ($invalid['answer'] ?? ''),
        'Penolakan memakai ringkasan deterministik dari data sumber');

    // Angka kecil (<=12) tidak dipaksakan ada di sumber.
    $small = ChatbotService::validateAiAnswer('Ada 8 kategori utama.', $adminGround);
    t_true((bool) ($small['ok'] ?? false), 'Angka kecil tidak dianggap halusinasi');
}

/**
 * AI fallback menerima paket_data_pertanyaan yang sesuai role dan tetap
 * tunduk pada validasi angka sebelum jawaban ditampilkan.
 */
function test_chatbot_data_ai_fallback_paket_data(): void
{
    $before = t_cd_settings_snapshot();
    $partman = t_cd_user('partman');
    $stub = null;

    try {
        Database::pdo()->exec('DELETE FROM chatbot_usage_logs');
        $stub = t_gemini_stub_start();
        $key = 'TESTKEY-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
        $s = ChatbotSettingsService::current();
        ChatbotSettingsService::save([
            'is_enabled'          => '1',
            'model'               => 'gemini-3.5-flash-lite',
            'system_prompt'       => $s['system_prompt'],
            'topic_limits'        => $s['topic_limits'],
            'answer_style'        => 'ringkas',
            'usage_limit_daily'   => '',
            'usage_limit_monthly' => '',
            'api_key'             => $key,
        ], (int) $partman['id']);

        $res = ChatbotService::respond(
            'Bagaimana cara mencatat barang masuk?',
            $partman,
            $stub['base'],
            5.0
        );
        t_eq('ok', (string) $res['status'], 'Fallback AI sukses melalui stub');
        t_eq('gemini', (string) $res['source'], 'Sumber jawaban tercatat sebagai provider AI');
        t_eq('verified', (string) ($res['validation'] ?? ''), 'Jawaban AI tanpa angka asing lolos validasi');

        $requests = t_gemini_stub_requests($stub);
        t_eq(1, count($requests), 'Satu permintaan AI tercatat');
        $system = (string) (($requests[0]['body'] ?? [])['system_instruction'] ?? '');
        t_contains('paket_data_pertanyaan', $system, 'Prompt memuat paket_data_pertanyaan');
        t_contains('topik_terdeteksi', $system, 'Prompt memuat topik_terdeteksi');
        t_contains('batas_akses', $system, 'Prompt memuat batas_akses role');
        t_contains('aturan_validasi', $system, 'Prompt memuat aturan validasi angka');
        t_contains('sumber_data', $system, 'Prompt memuat sumber data per modul');
        t_true(!str_contains($system, $key), 'Prompt AI tidak memuat API key');
        t_true(!str_contains(mb_strtolower($system), 'password_hash'), 'Prompt AI tidak memuat hash password');

        // Log tidak menyimpan pertanyaan/jawaban penuh.
        $logs = \App\Models\ChatbotUsageLog::recent(5);
        t_true($logs !== [], 'Panggilan AI tercatat di log penggunaan');
        t_true(!str_contains((string) ($logs[0]['question_preview'] ?? ''), 'Jawaban uji'),
            'Log tidak menyimpan jawaban AI');
    } finally {
        if (is_array($stub)) {
            t_gemini_stub_cleanup($stub);
        }
        t_cd_settings_restore($before);
    }
}
