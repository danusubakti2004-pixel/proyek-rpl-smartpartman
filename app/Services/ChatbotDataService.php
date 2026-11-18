<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AiPrediction;
use App\Models\Analytics;
use App\Models\Model;
use App\Models\Sparepart;
use App\Models\StockMovement;
use Throwable;

/**
 * Sumber data terstruktur untuk chatbot SmartPartMan.
 *
 * Prinsip:
 * - Angka diambil dari query/model yang SAMA dengan Dashboard (Analytics +
 *   DashboardService), bukan rumus baru.
 * - Chatbot tidak perlu "menebak" untuk pertanyaan angka/daftar: layanan ini
 *   dapat menyusun jawaban deterministik langsung dari database.
 * - Semua data dibatasi sesuai role. Data pengguna, audit, backup, dan
 *   pengaturan hanya tersedia untuk Admin.
 */
final class ChatbotDataService extends Model
{
    public const PERIOD_LABELS = [
        'hari'   => 'hari ini',
        'minggu' => 'minggu ini',
        'bulan'  => 'bulan ini',
        'tahun'  => 'tahun ini',
        'semua'  => 'seluruh data yang tersedia',
    ];

    /** Nama bulan Indonesia → nomor bulan. */
    private const MONTHS_ID = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
        'jul' => 7, 'agu' => 8, 'agt' => 8, 'sep' => 9, 'okt' => 10,
        'nov' => 11, 'des' => 12,
    ];

    /**
     * Kegagalan query TIDAK boleh dianggap "data kosong". Pesan ini dipakai
     * agar pengguna tahu pengambilan data gagal, bukan bahwa datanya nol.
     */
    public const ANSWER_DATA_ERROR = 'Maaf, terjadi kendala saat mengambil data dari database SmartPartMan. '
        . 'Data tidak ditampilkan agar tidak keliru. Silakan coba lagi atau buka menu aplikasi terkait.';

    public const ANSWER_CLARIFY = 'Saya belum memiliki konteks transaksi sebelumnya pada percakapan ini. '
        . 'Sebutkan tanggal, bulan, nomor dokumen, atau nama sparepart agar saya dapat menampilkan data yang tepat. '
        . 'Contoh: "Apa barang keluar pada tanggal 18 Oktober 2026?"';

    /**
     * Konteks tambahan yang dikirim ke Gemini.
     *
     * @return array<string,mixed>
     */
    public static function context(string $role): array
    {
        $category = self::categorySummary();
        $stock = self::stockLists();
        $dashboard = self::dashboardNumbers($role);

        // Konteks untuk Gemini sengaja DIPADATKAN: daftar lengkap tetap
        // dijawab deterministik dari database. Mengirim ratusan baris ke
        // model justru membuat prompt melebihi batas dan memotong konteks
        // role yang penting.
        $categoriesBySize = $category['daftar'];
        usort($categoriesBySize, static fn (array $a, array $b): int => ($b['jumlah_sparepart'] ?? 0) <=> ($a['jumlah_sparepart'] ?? 0));

        $supplier = self::supplierSummary();
        $location = self::locationSummary();

        $context = [
            'angka_dashboard' => $dashboard,
            'kategori_sparepart' => [
                'total_kategori' => $category['total_kategori'],
                'total_kategori_aktif' => $category['total_kategori_aktif'],
                'total_sparepart_semua' => $category['total_sparepart_semua_kategori'],
                'total_sparepart_aktif' => $category['total_sparepart_aktif_semua_kategori'],
                'kategori_terbanyak' => $category['kategori_terbanyak'],
                'jumlah_kategori_tanpa_sparepart' => count($category['kategori_tanpa_sparepart']),
                'contoh_kategori_terbesar' => array_slice($categoriesBySize, 0, 10),
                'contoh_kategori_tanpa_sparepart' => array_slice($category['kategori_tanpa_sparepart'], 0, 10),
            ],
            'stok_perlu_perhatian' => [
                'jumlah' => $dashboard['status_stok'],
                'contoh_habis' => array_slice($stock['habis'], 0, 5),
                'contoh_kritis' => array_slice($stock['kritis'], 0, 5),
                'contoh_menipis' => array_slice($stock['menipis'], 0, 5),
                'contoh_tanpa_minimum' => array_slice($stock['tanpa_minimum'], 0, 5),
            ],
            'supplier' => [
                'total' => $supplier['total'],
                'total_aktif' => $supplier['total_aktif'],
                'total_nonaktif' => $supplier['total_nonaktif'],
                'jumlah_belum_pernah_memasok' => count($supplier['belum_memasok']),
                'supplier_pasokan_terbanyak' => $supplier['terbanyak'],
                'contoh_supplier' => array_slice($supplier['daftar'], 0, 10),
            ],
            'lokasi_penyimpanan' => [
                'total' => $location['total'],
                'total_aktif' => $location['total_aktif'],
                'total_nonaktif' => $location['total_nonaktif'],
                'jumlah_digunakan' => $location['digunakan'],
                'rak_sparepart_terbanyak' => $location['terbanyak'],
                'contoh_lokasi' => array_slice($location['daftar'], 0, 10),
                'catatan_tingkat' => 'Tingkat penyimpanan tidak memiliki tabel terpisah; satu baris storage_locations '
                    . 'mewakili satu lokasi/rak. Jangan menghitung baris yang sama sebagai lokasi, rak, dan tingkat sekaligus.',
            ],
            'menu_aplikasi' => self::menuMap($role),
            'aturan_angka' => 'Untuk pertanyaan angka, gunakan PERSIS nilai pada angka_dashboard/kategori_sparepart. '
                . 'Jangan menghitung ulang dari daftar mentah. Bedakan jumlah dokumen/transaksi, jumlah item, '
                . 'dan total kuantitas barang.',
        ];

        if ($role === 'admin') {
            $context['notifikasi_whatsapp'] = self::whatsappSummary();
            $context['audit_dan_backup'] = [
                'total_audit_log' => (int) (self::fetchOne('SELECT COUNT(*) AS total FROM audit_logs')['total'] ?? 0),
                'total_backup' => (int) (self::fetchOne('SELECT COUNT(*) AS total FROM backups')['total'] ?? 0),
                'catatan' => 'Rincian audit dan backup hanya untuk Admin; jangan tampilkan nilai lama/baru mentah.',
            ];
        }

        return $context;
    }

    /**
     * Paket data spesifik pertanyaan untuk AI fallback.
     *
     * Berbeda dari context() yang bersifat agregat per role, paket ini hanya
     * memuat modul yang relevan dengan pertanyaan, periode/filter yang
     * terdeteksi, dan potongan data aktual. Tujuannya agar AI dapat menyusun
     * analisis tanpa pernah mengganti angka database dengan perkiraan.
     *
     * @param array<string,mixed>|null $context Konteks percakapan lanjutan.
     * @return array<string,mixed>
     */
    public static function fallbackContext(string $question, string $role, ?array $context = null): array
    {
        $base = [
            'pertanyaan_pengguna' => mb_substr($question, 0, 500),
            'role' => self::roleLabel($role),
            'batas_akses' => self::roleDataScope($role),
            'topik_terdeteksi' => [],
            'periode' => [],
            'konteks_percakapan' => null,
            'angka' => [],
            'data' => [],
            'sumber_data' => [],
            'aturan_validasi' => [
                'Gunakan PERSIS angka pada bagian angka/data. Jangan mengarang, membulatkan sembarang, atau mengganti dengan perkiraan.',
                'Jumlah dokumen, jumlah baris item, kuantitas barang, dan baris ledger adalah angka berbeda; jangan dicampur.',
                'Jangan memakai data di luar batas_akses role atau di luar topik yang tersedia.',
                'Bila data yang diminta tidak ada pada paket ini, nyatakan keterbatasan informasi dan arahkan ke menu aplikasi.',
                'Prediksi dan reorder bersifat rekomendasi, bukan pesanan otomatis.',
            ],
            'catatan_data' => null,
        ];

        try {
            $q = mb_strtolower(trim($question));
            $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
            $ctx = is_array($context) && $context !== [] ? $context : null;
            $expanded = self::expandContextualQuestion($q, $ctx);

            $period = self::detectPeriod($expanded);
            $periodLabel = self::periodLabel($period);
            $dateFilter = self::parseDateFilter($expanded);
            $range = self::featureDateRange($dateFilter, $period, $periodLabel);
            $topics = self::groundingTopics($expanded, $role, $ctx);

            $base['topik_terdeteksi'] = $topics;
            $base['periode'] = [
                'kode' => $period,
                'label' => $range['label'],
                'dari' => $range['from'],
                'sampai' => $range['to'],
                'filter_tanggal' => ($dateFilter['type'] ?? 'none') !== 'none'
                    ? $dateFilter['label']
                    : null,
            ];

            if ($ctx !== null) {
                $base['konteks_percakapan'] = [
                    'topik' => (string) ($ctx['topic'] ?? ''),
                    'modul' => (string) ($ctx['module'] ?? ''),
                    'filter' => (string) ($ctx['filter'] ?? ''),
                    'periode_label' => (string) ($ctx['date_label'] ?? ''),
                    'nomor_dokumen' => array_slice(array_map(
                        'strval',
                        array_values((array) ($ctx['doc_numbers'] ?? []))
                    ), 0, 10),
                ];
            }

            $base['angka'] = self::dashboardNumbers($role);
            // Untuk paket grounding, periode "semua" tetap memakai rentang
            // penuh agar query contoh dokumen tidak kosong karena NULL.
            $groundRange = $range;
            if ($groundRange['from'] === null) {
                $groundRange['from'] = '1970-01-01';
            }
            if ($groundRange['to'] === null) {
                $groundRange['to'] = date('Y-m-d');
            }
            $base['data'] = self::groundingData($topics, $role, $groundRange);
            $base['sumber_data'] = self::groundingSources($topics);
        } catch (Throwable $e) {
            error_log('[ChatbotDataService] fallbackContext: ' . get_class($e) . ': ' . $e->getMessage());
            $base['catatan_data'] = 'Sebagian data pendukung gagal diambil; gunakan hanya data yang tersedia.';
        }

        return $base;
    }

    /**
     * Ringkasan deterministik saat jawaban AI tidak lolos validasi angka.
     * Hanya memakai data yang sudah ada pada paket grounding.
     *
     * @param array<string,mixed> $grounding
     */
    public static function groundingSummary(array $grounding): string
    {
        $lines = [
            'Saya sudah mengambil data aktual SmartPartMan, tetapi jawaban AI tidak dapat sepenuhnya diverifikasi terhadap angka sumber sehingga tidak ditampilkan.',
            'Ringkasan data yang terverifikasi:',
        ];

        $angka = is_array($grounding['angka'] ?? null) ? $grounding['angka'] : [];
        $data = is_array($grounding['data'] ?? null) ? $grounding['data'] : [];

        if (isset($angka['total_jenis_sparepart'])) {
            $lines[] = '- Sparepart aktif: ' . self::n((int) $angka['total_jenis_sparepart']) . ' jenis'
                . (isset($angka['total_jenis_sparepart_semua'])
                    ? '; total semua jenis: ' . self::n((int) $angka['total_jenis_sparepart_semua']) . '.'
                    : '.');
            $lines[] = '- Total stok sparepart aktif: '
                . self::qty((float) ($angka['total_stok_sparepart_aktif'] ?? 0)) . ' unit.';
        }
        if (isset($angka['status_stok']) && is_array($angka['status_stok'])) {
            $status = $angka['status_stok'];
            $lines[] = '- Status stok: ' . self::n((int) ($status['habis'] ?? 0)) . ' habis, '
                . self::n((int) ($status['kritis'] ?? 0)) . ' kritis, '
                . self::n((int) ($status['menipis'] ?? 0)) . ' menipis, '
                . self::n((int) ($status['aman'] ?? 0)) . ' aman, '
                . self::n((int) ($status['tanpa_minimum'] ?? 0)) . ' tanpa minimum.';
        }
        if (isset($data['supplier']['total'])) {
            $lines[] = '- Supplier: ' . self::n((int) $data['supplier']['total']) . ' total, '
                . self::n((int) ($data['supplier']['total_aktif'] ?? 0)) . ' aktif.';
        }
        if (isset($data['kategori']['total_kategori'])) {
            $lines[] = '- Kategori: ' . self::n((int) $data['kategori']['total_kategori']) . ' total, '
                . self::n((int) ($data['kategori']['total_kategori_aktif'] ?? 0)) . ' aktif.';
        }
        if (isset($data['lokasi']['total'])) {
            $lines[] = '- Lokasi/rak: ' . self::n((int) $data['lokasi']['total']) . ' total, '
                . self::n((int) ($data['lokasi']['total_aktif'] ?? 0)) . ' aktif.';
        }
        if (isset($data['transaksi'])) {
            $trx = $data['transaksi'];
            $lines[] = '- Transaksi (' . (string) ($trx['periode'] ?? 'periode terpilih') . '): '
                . self::n((int) ($trx['jumlah_dokumen_penerimaan'] ?? 0)) . ' dokumen masuk, '
                . self::n((int) ($trx['jumlah_dokumen_pengeluaran'] ?? 0)) . ' dokumen keluar.';
            $lines[] = '  Kuantitas: ' . self::qty((float) ($trx['qty_masuk_unit'] ?? 0)) . ' unit masuk, '
                . self::qty((float) ($trx['qty_keluar_unit'] ?? 0)) . ' unit keluar.';
        }
        if (isset($data['penyesuaian']['jumlah_dokumen'])) {
            $adj = $data['penyesuaian'];
            $lines[] = '- Penyesuaian stok: ' . self::n((int) $adj['jumlah_dokumen']) . ' dokumen, '
                . self::n((int) ($adj['jumlah_baris_item'] ?? 0)) . ' baris item, penambahan '
                . self::qty((float) ($adj['total_penambahan_unit'] ?? 0)) . ' unit, pengurangan '
                . self::qty((float) ($adj['total_pengurangan_unit'] ?? 0)) . ' unit.';
        }
        if (isset($data['opname']['jumlah_sesi'])) {
            $op = $data['opname'];
            $lines[] = '- Stock opname: ' . self::n((int) $op['jumlah_sesi']) . ' sesi, '
                . self::n((int) ($op['jumlah_item_diperiksa'] ?? 0)) . ' item diperiksa, '
                . self::n((int) ($op['jumlah_item_berselisih'] ?? 0)) . ' item berselisih, selisih absolut '
                . self::qty((float) ($op['total_selisih_absolut_unit'] ?? 0)) . ' unit.';
        }
        if (isset($data['po']['total_po'])) {
            $po = $data['po'];
            $lines[] = '- Purchase order: ' . self::n((int) $po['total_po']) . ' dokumen.';
            if (is_array($po['per_status'] ?? null)) {
                foreach ($po['per_status'] as $row) {
                    $statusName = self::poStatusLabel((string) ($row['status'] ?? ''));
                    $lines[] = '  ' . ucfirst($statusName) . ': ' . self::n((int) ($row['total'] ?? 0)) . ' dokumen.';
                }
            }
        }
        if (isset($data['prediksi']['total_dianalisis'])) {
            $pred = $data['prediksi'];
            $lines[] = '- Prediksi: ' . self::n((int) $pred['total_dianalisis']) . ' sparepart dianalisis, '
                . self::n((int) ($pred['belum_dianalisis'] ?? 0)) . ' belum dianalisis, risiko tinggi '
                . self::n((int) ($pred['risiko_tinggi'] ?? 0)) . '.';
            foreach (array_slice((array) ($pred['rekomendasi_pemesanan'] ?? []), 0, 5) as $rec) {
                $lines[] = '  ' . (string) ($rec['nama'] ?? '-') . ' ('
                    . (string) ($rec['kode'] ?? '-') . '): saran pesan '
                    . self::qty((float) ($rec['saran_pesan'] ?? 0)) . ' '
                    . (string) ($rec['satuan'] ?? 'unit') . '.';
            }
        }
        if (isset($data['reorder']['status_permintaan'])) {
            $reorder = $data['reorder']['status_permintaan'];
            $lines[] = '- Reorder: ' . self::n((int) ($reorder['menunggu_persetujuan'] ?? 0))
                . ' menunggu persetujuan, ' . self::n((int) ($reorder['disetujui'] ?? 0))
                . ' disetujui, ' . self::n((int) ($reorder['ditolak'] ?? 0)) . ' ditolak.';
        }
        if (isset($data['pengguna']['ringkasan'])) {
            $user = $data['pengguna']['ringkasan'];
            $lines[] = '- Pengguna: ' . self::n((int) ($user['total'] ?? 0)) . ' total, '
                . self::n((int) ($user['active'] ?? 0)) . ' aktif, '
                . self::n((int) ($user['inactive'] ?? 0)) . ' nonaktif.';
        }

        if (count($lines) === 2) {
            $lines[] = '- Belum ada angka ringkas yang dapat ditampilkan untuk pertanyaan ini.';
        }
        $periode = (string) ($grounding['periode']['label'] ?? '');
        if ($periode !== '') {
            $lines[] = 'Periode/filter yang digunakan: ' . $periode . '.';
        }
        $lines[] = 'Silakan buka menu aplikasi terkait untuk rincian lengkap. '
            . 'Jawaban AI tidak ditampilkan agar tidak ada angka yang keliru.';

        return implode("\n", $lines);
    }

    /** Batas data yang boleh diterima AI sesuai role. */
    private static function roleDataScope(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin: ringkasan persediaan, transaksi, penyesuaian, opname, PO, reorder, prediksi, pengguna, audit/backup, dan pengaturan aplikasi. Rahasia (API key, password, token) tidak pernah disertakan.',
            'partman' => 'Partman: persediaan, transaksi, penyesuaian, opname, reorder, PO, supplier, lokasi/rak, laporan, dan prediksi. Data pengguna, audit log, backup, dan pengaturan tidak tersedia.',
            'kepala_bengkel' => 'Kepala Bengkel: laporan, kondisi stok, transaksi, penyesuaian, opname, PO, reorder, dan prediksi. Data pengguna, audit log, backup, dan pengaturan tidak tersedia.',
            default => 'Hanya informasi umum aplikasi yang tersedia.',
        };
    }

    /**
     * Tentukan modul yang relevan dengan pertanyaan.
     *
     * @param array<string,mixed>|null $context
     * @return string[]
     */
    private static function groundingTopics(string $q, string $role, ?array $context): array
    {
        $topics = [];
        $add = static function (string $topic) use (&$topics): void {
            if (!in_array($topic, $topics, true)) {
                $topics[] = $topic;
            }
        };

        if (preg_match('/\b(supplier|pemasok|vendor|penyuplai)\b/u', $q) === 1) {
            $add('supplier');
        }
        if (str_contains($q, 'kategori')) {
            $add('kategori');
        }
        if (self::isLocationQuestion($q)) {
            $add('lokasi');
        }
        if (preg_match('/\b(penyesuaian|adjustment|adjust|adj-\d+)\b/u', $q) === 1) {
            $add('penyesuaian');
        }
        if (preg_match('/\b(opname|stock\s*opname|stok\s*opname)\b/u', $q) === 1) {
            $add('opname');
        }
        if (preg_match('/\b(po|purchase\s*order|pembelian|pesanan)\b/u', $q) === 1) {
            $add('po');
        }
        if (preg_match('/\b(prediksi|forecast|ramalan|perkiraan|analisis|analisa|rekomendasi)\b/u', $q) === 1) {
            $add('prediksi');
        }
        if (preg_match('/\b(reorder|re-order|restock|dipesan|dibeli|pembelian\s+ulang)\b/u', $q) === 1) {
            $add('reorder');
        }
        if (preg_match('/\b(transaksi|masuk|keluar|penerimaan|pengeluaran|riwayat|spk|nomor\s*polisi|plat)\b/u', $q) === 1) {
            $add('transaksi');
        }
        if (preg_match('/\b(stok|stock|minimum|minimal|kritis|menipis|habis|aman)\b/u', $q) === 1) {
            $add('stok');
        }
        if (preg_match('/\b(laporan|report)\b/u', $q) === 1) {
            $add('laporan');
        }
        if ($role === 'admin'
            && preg_match('/\b(pengguna|user|akun|login|aktivitas|audit|backup)\b/u', $q) === 1
        ) {
            $add('pengguna');
        }

        // Pertanyaan lanjutan yang singkat tetap memakai Topik konteks.
        if ($topics === [] && $context !== null) {
            $map = [
                'transaction' => 'transaksi',
                'stock'       => 'stok',
                'reorder'     => 'reorder',
                'user'        => 'pengguna',
                'po'          => 'po',
            ];
            $topic = (string) ($context['topic'] ?? '');
            if (isset($map[$topic])) {
                $add($map[$topic]);
            }
        }
        if ($topics === []) {
            $add('umum');
        }

        return $topics;
    }

    /**
     * @param string[] $topics
     * @param array{from:?string,to:?string,label:string} $range
     * @return array<string,mixed>
     */
    private static function groundingData(array $topics, string $role, array $range): array
    {
        $data = [];
        foreach ($topics as $topic) {
            switch ($topic) {
                case 'supplier':
                    $supplier = self::supplierSummary();
                    $data['supplier'] = [
                        'total' => $supplier['total'],
                        'total_aktif' => $supplier['total_aktif'],
                        'total_nonaktif' => $supplier['total_nonaktif'],
                        'jumlah_belum_pernah_memasok' => count($supplier['belum_memasok']),
                        'supplier_pasokan_terbanyak' => $supplier['terbanyak'],
                        'daftar' => array_slice($supplier['daftar'], 0, 15),
                    ];
                    break;

                case 'kategori':
                    $category = self::categorySummary();
                    $list = $category['daftar'];
                    usort($list, static fn (array $a, array $b): int =>
                        (int) ($b['jumlah_sparepart'] ?? 0) <=> (int) ($a['jumlah_sparepart'] ?? 0));
                    $data['kategori'] = [
                        'total_kategori' => $category['total_kategori'],
                        'total_kategori_aktif' => $category['total_kategori_aktif'],
                        'total_sparepart_semua' => $category['total_sparepart_semua_kategori'],
                        'total_sparepart_aktif' => $category['total_sparepart_aktif_semua_kategori'],
                        'kategori_terbanyak' => $category['kategori_terbanyak'],
                        'jumlah_kategori_tanpa_sparepart' => count($category['kategori_tanpa_sparepart']),
                        'daftar_terbesar' => array_slice($list, 0, 15),
                    ];
                    break;

                case 'lokasi':
                    $location = self::locationSummary();
                    $data['lokasi'] = [
                        'total' => $location['total'],
                        'total_aktif' => $location['total_aktif'],
                        'total_nonaktif' => $location['total_nonaktif'],
                        'jumlah_digunakan' => $location['digunakan'],
                        'rak_sparepart_terbanyak' => $location['terbanyak'],
                        'daftar' => array_slice($location['daftar'], 0, 15),
                        'catatan' => 'Satu baris storage_locations mewakili satu lokasi/rak; tidak ada tabel tingkat terpisah.',
                    ];
                    break;

                case 'stok':
                    $dash = self::dashboardNumbers($role);
                    $lists = self::stockLists();
                    $data['stok'] = [
                        'status_stok' => $dash['status_stok'],
                        'total_stok_sparepart_aktif' => $dash['total_stok_sparepart_aktif'],
                        'contoh_habis' => array_slice($lists['habis'], 0, 8),
                        'contoh_kritis' => array_slice($lists['kritis'], 0, 8),
                        'contoh_menipis' => array_slice($lists['menipis'], 0, 8),
                        'contoh_tanpa_minimum' => array_slice($lists['tanpa_minimum'], 0, 8),
                    ];
                    break;

                case 'penyesuaian':
                    $data['penyesuaian'] = self::adjustmentGrounding($range);
                    break;

                case 'opname':
                    $data['opname'] = self::opnameGrounding($range);
                    break;

                case 'po':
                    $data['po'] = self::poGrounding();
                    break;

                case 'prediksi':
                    $data['prediksi'] = self::predictionGrounding();
                    break;

                case 'reorder':
                    $data['reorder'] = self::reorderGrounding();
                    break;

                case 'transaksi':
                    $data['transaksi'] = self::transactionGrounding($range);
                    break;

                case 'pengguna':
                    if ($role === 'admin') {
                        $data['pengguna'] = self::userGrounding();
                    }
                    break;

                case 'laporan':
                    $meta = ReportService::allMeta();
                    $data['laporan'] = [
                        'daftar_jenis' => array_map(static fn (array $item): array => [
                            'kode' => (string) ($item['code'] ?? ($item['type'] ?? '')),
                            'nama' => (string) ($item['title'] ?? ($item['name'] ?? '')),
                        ], array_slice(array_values($meta), 0, 20)),
                        'catatan' => 'Angka laporan memakai ReportService yang sama dengan halaman Laporan.',
                    ];
                    break;

                case 'umum':
                default:
                    $data['menu'] = self::menuMap($role);
                    break;
            }
        }

        return $data;
    }

    /**
     * Peta sumber tabel per topik agar AI menjelaskan asal data dengan benar.
     *
     * @param string[] $topics
     * @return array<string,string>
     */
    private static function groundingSources(array $topics): array
    {
        $map = [
            'supplier'    => 'suppliers',
            'kategori'    => 'categories + spareparts',
            'lokasi'      => 'storage_locations + spareparts',
            'stok'        => 'spareparts (stock, min_stock) melalui Analytics::stockStatusCounts',
            'penyesuaian' => 'stock_movements dengan movement_type = PENYESUAIAN',
            'opname'      => 'stock_opnames + stock_opname_items',
            'po'          => 'purchase_orders + purchase_order_items + suppliers',
            'prediksi'    => 'ai_predictions + spareparts',
            'reorder'     => 'reorder_requests + ai_predictions + spareparts',
            'transaksi'   => 'goods_receipts/goods_receipt_items, goods_issues/goods_issue_items, stock_movements',
            'pengguna'    => 'users + roles + login_attempts + audit_logs (khusus Admin)',
            'laporan'     => 'ReportService (sumber sama dengan halaman Laporan)',
            'umum'        => 'menu aplikasi dan Dashboard sesuai role',
        ];

        $out = [];
        foreach ($topics as $topic) {
            if (isset($map[$topic])) {
                $out[$topic] = $map[$topic];
            }
        }
        return $out;
    }

    /**
     * Ringkasan penyesuaian stok untuk paket grounding (bukan jawaban).
     *
     * @param array{from:?string,to:?string,label:string} $range
     * @return array<string,mixed>
     */
    private static function adjustmentGrounding(array $range): array
    {
        $where = ' WHERE m.movement_type = ?';
        $params = [StockMovement::TYPE_ADJUSTMENT];
        if ($range['from'] !== null) {
            $where .= ' AND m.movement_date BETWEEN ? AND ?';
            $params[] = $range['from'];
            $params[] = $range['to'];
        }

        $rows = self::fetchAll(
            'SELECT m.reference_number, m.movement_date, m.quantity,
                    s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    u.full_name AS user_name
               FROM stock_movements m
               JOIN spareparts s ON s.id = m.sparepart_id
          LEFT JOIN users u ON u.id = m.user_id' . $where . '
           ORDER BY m.movement_date DESC, m.id DESC',
            $params
        );

        $docs = [];
        $addition = 0.0;
        $reduction = 0.0;
        foreach ($rows as $row) {
            $number = trim((string) ($row['reference_number'] ?? ''));
            if ($number !== '') {
                $docs[$number] = true;
            }
            $qty = (float) ($row['quantity'] ?? 0);
            if ($qty >= 0) {
                $addition += $qty;
            } else {
                $reduction += abs($qty);
            }
        }

        return [
            'jumlah_dokumen' => $docs !== [] ? count($docs) : count($rows),
            'jumlah_baris_item' => count($rows),
            'total_penambahan_unit' => round($addition, 2),
            'total_pengurangan_unit' => round($reduction, 2),
            'periode' => $range['label'],
            'contoh_terbaru' => array_slice(array_map(static fn (array $row): array => [
                'nomor' => (string) ($row['reference_number'] ?? ''),
                'tanggal' => (string) ($row['movement_date'] ?? ''),
                'sparepart' => (string) ($row['sparepart_name'] ?? ''),
                'kode' => (string) ($row['sparepart_code'] ?? ''),
                'satuan' => (string) ($row['unit'] ?? ''),
                'qty' => (float) ($row['quantity'] ?? 0),
                'dicatat_oleh' => (string) ($row['user_name'] ?? ''),
            ], $rows), 0, 10),
        ];
    }

    /**
     * Ringkasan sesi stock opname untuk paket grounding (bukan jawaban).
     *
     * @param array{from:?string,to:?string,label:string} $range
     * @return array<string,mixed>
     */
    private static function opnameGrounding(array $range): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];
        if ($range['from'] !== null) {
            $where .= ' AND COALESCE(o.opname_date, DATE(o.started_at)) BETWEEN ? AND ?';
            $params[] = $range['from'];
            $params[] = $range['to'];
        }

        $stats = self::fetchOne(
            'SELECT COUNT(*) AS sessions,
                    COALESCE(SUM((SELECT COUNT(*) FROM stock_opname_items i
                                   WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL)), 0) AS checked_items,
                    COALESCE(SUM((SELECT COUNT(*) FROM stock_opname_items i
                                   WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL
                                     AND i.difference <> 0)), 0) AS diff_items,
                    COALESCE(SUM((SELECT COALESCE(SUM(ABS(i.difference)), 0)
                                    FROM stock_opname_items i
                                   WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL)), 0) AS abs_difference
               FROM stock_opnames o' . $where,
            $params
        ) ?? [];

        $sessions = self::fetchAll(
            'SELECT o.opname_number, o.opname_date, o.started_at, o.status,
                    su.full_name AS started_by_name,
                    (SELECT COUNT(*) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL) AS counted_count,
                    (SELECT COUNT(*) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL
                        AND i.difference <> 0) AS diff_count
               FROM stock_opnames o
               JOIN users su ON su.id = o.started_by' . $where . '
           ORDER BY COALESCE(o.opname_date, DATE(o.started_at)) DESC, o.id DESC
              LIMIT 10',
            $params
        );

        return [
            'jumlah_sesi' => (int) ($stats['sessions'] ?? 0),
            'jumlah_item_diperiksa' => (int) ($stats['checked_items'] ?? 0),
            'jumlah_item_berselisih' => (int) ($stats['diff_items'] ?? 0),
            'total_selisih_absolut_unit' => round((float) ($stats['abs_difference'] ?? 0), 2),
            'periode' => $range['label'],
            'contoh_sesi' => $sessions,
            'catatan' => 'Stock opname memakai stock_opnames/stock_opname_items, bukan stock_movements PENYESUAIAN.',
        ];
    }

    /** @return array<string,mixed> */
    private static function poGrounding(): array
    {
        $counts = self::fetchAll(
            'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS amount
               FROM purchase_orders GROUP BY status ORDER BY status ASC'
        );
        $rows = self::fetchAll(
            'SELECT po.po_number, po.order_date, po.status, po.payment_status, po.total_amount,
                    s.name AS supplier_name
               FROM purchase_orders po
          LEFT JOIN suppliers s ON s.id = po.supplier_id
           ORDER BY po.order_date DESC, po.id DESC
              LIMIT 10'
        );

        $total = 0;
        foreach ($counts as $row) {
            $total += (int) ($row['total'] ?? 0);
        }

        return [
            'total_po' => $total,
            'per_status' => $counts,
            'contoh_terbaru' => $rows,
            'catatan' => 'Status PO dan status pembayaran adalah dua hal berbeda; penerimaan barang dicatat di menu Barang Masuk.',
        ];
    }

    /** @return array<string,mixed> */
    private static function predictionGrounding(): array
    {
        $summary = AiPrediction::summary();
        $recommendations = Analytics::aiRecommendations(15);

        return [
            'total_dianalisis' => (int) ($summary['total'] ?? 0),
            'belum_dianalisis' => (int) ($summary['pending'] ?? 0),
            'sparepart_aktif' => (int) ($summary['active'] ?? 0),
            'risiko_tinggi' => (int) ($summary['risk_high'] ?? 0),
            'risiko_sedang' => (int) ($summary['risk_medium'] ?? 0),
            'terakhir_diproses' => $summary['latest_at'] ?? null,
            'rekomendasi_pemesanan' => array_map(static fn (array $row): array => [
                'kode' => (string) ($row['sparepart_code'] ?? ''),
                'nama' => (string) ($row['sparepart_name'] ?? ''),
                'satuan' => (string) ($row['unit'] ?? ''),
                'stok' => (float) ($row['stock'] ?? 0),
                'minimum' => (float) ($row['min_stock'] ?? 0),
                'prediksi_kebutuhan' => (float) ($row['predicted_demand'] ?? 0),
                'saran_pesan' => (float) ($row['recommended_qty'] ?? 0),
                'risiko' => (string) ($row['stockout_risk'] ?? ''),
                'kecukupan_data' => (string) ($row['data_sufficiency'] ?? ''),
                'diproses_pada' => (string) ($row['processed_at'] ?? ''),
            ], $recommendations),
            'catatan' => 'Prediksi bersifat rekomendasi, bukan pesanan otomatis.',
        ];
    }

    /** @return array<string,mixed> */
    private static function reorderGrounding(): array
    {
        $status = Analytics::reorderStatusCounts();
        $lists = self::stockLists();
        $needs = [];
        $seen = [];
        foreach (array_merge($lists['habis'], $lists['kritis'], $lists['menipis']) as $row) {
            $key = (string) ($row['kode'] ?? '');
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $needs[] = $row;
            }
        }

        return [
            'status_permintaan' => [
                'menunggu_persetujuan' => (int) ($status['MENUNGGU_PERSETUJUAN'] ?? 0),
                'disetujui' => (int) ($status['DISETUJUI'] ?? 0),
                'ditolak' => (int) ($status['DITOLAK'] ?? 0),
            ],
            'sparepart_perlu_reorder' => array_slice($needs, 0, 15),
            'jumlah_sparepart_perlu_reorder' => count($needs),
            'rekomendasi_ai' => array_slice(self::predictionGrounding()['rekomendasi_pemesanan'], 0, 10),
            'catatan' => 'Persetujuan reorder tidak menambah stok; stok bertambah lewat penerimaan barang.',
        ];
    }

    /**
     * @param array{from:?string,to:?string,label:string} $range
     * @return array<string,mixed>
     */
    private static function transactionGrounding(array $range): array
    {
        $counts = self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN ? AND ?) AS receipts,
                    (SELECT COUNT(*) FROM goods_issues WHERE issue_date BETWEEN ? AND ?) AS issues,
                    (SELECT COUNT(*) FROM stock_opnames WHERE opname_date BETWEEN ? AND ?) AS opnames,
                    (SELECT COUNT(*) FROM stock_movements WHERE movement_date BETWEEN ? AND ?) AS movements,
                    (SELECT COUNT(*) FROM goods_receipt_items gri
                       JOIN goods_receipts gr ON gr.id = gri.receipt_id
                      WHERE gr.receipt_date BETWEEN ? AND ?) AS receipt_items,
                    (SELECT COUNT(*) FROM goods_issue_items gii
                       JOIN goods_issues gi ON gi.id = gii.issue_id
                      WHERE gi.issue_date BETWEEN ? AND ?) AS issue_items,
                    (SELECT COALESCE(SUM(gri.quantity), 0) FROM goods_receipt_items gri
                       JOIN goods_receipts gr ON gr.id = gri.receipt_id
                      WHERE gr.receipt_date BETWEEN ? AND ?) AS receipt_qty,
                    (SELECT COALESCE(SUM(gii.quantity), 0) FROM goods_issue_items gii
                       JOIN goods_issues gi ON gi.id = gii.issue_id
                      WHERE gi.issue_date BETWEEN ? AND ?) AS issue_qty',
            [
                $range['from'], $range['to'], $range['from'], $range['to'],
                $range['from'], $range['to'], $range['from'], $range['to'],
                $range['from'], $range['to'], $range['from'], $range['to'],
                $range['from'], $range['to'], $range['from'], $range['to'],
            ]
        ) ?? [];

        $latest = self::fetchAll(
            'SELECT "MASUK" AS kind, gr.receipt_number AS number, gr.receipt_date AS doc_date,
                    (SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items i
                      WHERE i.receipt_id = gr.id) AS qty
               FROM goods_receipts gr WHERE gr.receipt_date BETWEEN ? AND ?
              UNION ALL
             SELECT "KELUAR" AS kind, gi.issue_number AS number, gi.issue_date AS doc_date,
                    (SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items i
                      WHERE i.issue_id = gi.id) AS qty
               FROM goods_issues gi WHERE gi.issue_date BETWEEN ? AND ?
             ORDER BY doc_date DESC, number DESC LIMIT 10',
            [
                $range['from'], $range['to'],
                $range['from'], $range['to'],
            ]
        );

        return [
            'periode' => $range['label'],
            'jumlah_dokumen_penerimaan' => (int) ($counts['receipts'] ?? 0),
            'jumlah_dokumen_pengeluaran' => (int) ($counts['issues'] ?? 0),
            'jumlah_dokumen_opname' => (int) ($counts['opnames'] ?? 0),
            'jumlah_baris_ledger' => (int) ($counts['movements'] ?? 0),
            'jumlah_baris_item_penerimaan' => (int) ($counts['receipt_items'] ?? 0),
            'jumlah_baris_item_pengeluaran' => (int) ($counts['issue_items'] ?? 0),
            'qty_masuk_unit' => round((float) ($counts['receipt_qty'] ?? 0), 2),
            'qty_keluar_unit' => round((float) ($counts['issue_qty'] ?? 0), 2),
            'contoh_dokumen_terbaru' => $latest,
            'catatan' => 'Jumlah dokumen, baris item, kuantitas, dan baris ledger tidak boleh dicampur.',
        ];
    }

    /** @return array<string,mixed> */
    private static function userGrounding(): array
    {
        $summary = Analytics::userSummary();
        $users = self::fetchAll(
            'SELECT u.full_name, u.username, u.is_active, u.last_login_at,
                    r.code AS role_code, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
           ORDER BY u.is_active DESC, u.full_name ASC LIMIT 15'
        );
        $logins = self::fetchAll(
            'SELECT u.full_name, u.username, u.last_login_at
               FROM users u
              WHERE u.last_login_at IS NOT NULL
           ORDER BY u.last_login_at DESC LIMIT 5'
        );

        return [
            'ringkasan' => $summary,
            'daftar_pengguna' => $users,
            'login_terakhir' => $logins,
        ];
    }

    /**
     * Konteks transaksi terakhir dari jawaban deterministik (untuk pertanyaan
     * lanjutan). Hanya berisi modul, rentang tanggal, dan nomor dokumen —
     * tidak pernah memuat data sensitif atau SQL.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $lastContext = null;

    /** @return array<string,mixed>|null */
    public static function lastContext(): ?array
    {
        return self::$lastContext;
    }

    /**
     * Jawaban deterministik dari database untuk pertanyaan angka/daftar.
     * Mengembalikan null bila pertanyaan tidak dikenali (dilanjutkan ke Gemini).
     *
     * @param array<string,mixed>|null $context Konteks transaksi dari sesi.
     */
    public static function answer(string $question, string $role, ?array $context = null): ?string
    {
        self::$lastContext = null;
        try {
            return self::answerInternal($question, $role, $context);
        } catch (Throwable $e) {
            error_log('[ChatbotDataService] ' . get_class($e) . ': ' . $e->getMessage());
            // Jangan kembalikan null: itu membuat pertanyaan data jatuh ke
            // provider dan berpotensi dijawab "tidak tersedia" seolah kosong.
            return self::ANSWER_DATA_ERROR;
        }
    }

    /** @param array<string,mixed>|null $context */
    private static function answerInternal(string $question, string $role, ?array $context = null): ?string
    {
        $q = mb_strtolower(trim($question));
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        if ($q === '') {
            return null;
        }

        // --- Konteks percakapan: lengkapi pertanyaan lanjutan yang memakai
        // kata ganti/kalimat pendek SEBELUM intent dideteksi. Tanpa ini,
        // "Berapa total masuknya bulan ini?" dianggap topik baru.
        $context = is_array($context) && $context !== [] ? $context : null;
        $q = self::expandContextualQuestion($q, $context);

        if ($context !== null
            && preg_match('/\bbelum selesai\b/u', $q) === 1
            && in_array((string) ($context['topic'] ?? ''), ['transaction', 'po'], true)
        ) {
            return 'Maksud Anda transaksi yang belum selesai (misalnya PO yang belum diterima) '
                . 'atau dokumen barang masuk/keluar tertentu? Sebutkan jenisnya agar saya tidak menebak.';
        }

        // Lanjutan daftar supplier: "Tampilkan semuanya." setelah pertanyaan
        // jumlah supplier harus menampilkan daftar supplier, bukan klarifikasi.
        // Jangan menangkap pertanyaan topik lain yang kebetulan memakai kata
        // "tampilkan" (mis. "Tampilkan riwayat stok.").
        if ($context !== null
            && (string) ($context['topic'] ?? '') === 'supplier'
            && preg_match('/\b(semuanya|semua|seluruhnya|daftar)\b/u', $q) === 1
            && preg_match('/\b(supplier|pemasok|vendor|penyuplai)\b/u', $q) !== 1
            && preg_match(
                '/\b(stok|riwayat|barang|transaksi|kategori|rak|lokasi|pengguna|user|po|purchase|'
                . 'opname|penyesuaian|prediksi|reorder|masuk|keluar|tambah|hapus|ubah|laporan)\b/u',
                $q
            ) !== 1
        ) {
            return self::answerSuppliers('tampilkan semua supplier');
        }

        $period = self::detectPeriod($q);
        $periodLabel = self::periodLabel($period);
        $dash = self::dashboardNumbers($role);

        // --- Pertanyaan lanjutan memakai konteks transaksi sebelumnya -------
        // Diletakkan sebelum aturan prosedural agar "bagaimana dengan ..."
        // tetap memakai konteks, bukan jatuh ke provider.
        if (self::isFollowUpQuestion($q)) {
            $follow = self::answerFollowUp($q, $role, $context);
            if ($follow !== null) {
                return $follow;
            }
        }

        // Pertanyaan prosedural ("bagaimana cara ...") lebih tepat dijawab
        // Gemini dengan panduan menu, bukan dengan angka. Namun permintaan
        // rincian data ("bagaimana rincian barang keluar?") tetap dijawab
        // deterministik dari database.
        $asksProcedure = preg_match('/\b(cara|langkah|prosedur|tutorial|panduan)\b/u', $q) === 1;
        $asksNumber = preg_match('/\b(berapa|jumlah|total|banyak|persen|persentase)\b/u', $q) === 1;
        $asksList = preg_match('/\b(apa saja|sebutkan|daftar|mana saja|siapa saja|terbaru|terakhir)\b/u', $q) === 1;
        if ($asksProcedure && !$asksNumber && !$asksList) {
            return null;
        }

        // --- Transaksi dengan tanggal/nomor dokumen/permintaan rincian ------
        // Jangan menunggu sampai cabang agregat: pertanyaan tanggal tertentu
        // tidak boleh dijawab memakai total seluruh periode.
        $dateFilter = self::parseDateFilter($q);
        $docNumber = self::extractDocumentNumber($question);
        $transactionWords = preg_match('/\b(barang masuk|barang keluar|penerimaan|pengeluaran|transaksi)\b/u', $q) === 1;
        $detailHint = preg_match('/\b(rincian|detail|apa|bagaimana|tampilkan|daftar|sebutkan|siapa|spk|nomor polisi|plat)\b/u', $q) === 1;
        $hasFlow = preg_match('/\b(masuk|keluar|penerimaan|pengeluaran)\b/u', $q) === 1;

        // --- Penyesuaian Stok vs Stock Opname --------------------------------
        // Kedua modul memakai tabel yang berbeda. Deteksi dilakukan sebelum
        // cabang pengguna/transaksi generik agar pertanyaan seperti
        // "Siapa yang melakukan penyesuaian stok?" tidak dianggap aktivitas
        // pengguna dan agar angka tidak diambil dari tabel stock_opnames.
        $featureIntent = self::detectStockFeatureIntent($q);
        if ($featureIntent === 'compare') {
            return self::answerStockFeatureComparison();
        }
        if ($featureIntent === 'ambiguous') {
            return self::answerStockFeatureClarification();
        }
        if ($featureIntent === 'adjustment') {
            return self::answerStockAdjustments($q, $role, $period, $periodLabel, $dateFilter, $docNumber);
        }
        if ($featureIntent === 'opname') {
            return self::answerStockOpnames($q, $role, $period, $periodLabel, $dateFilter, $docNumber);
        }

        // Permintaan rincian "berdasarkan tanggal" tanpa tanggal konkret:
        // minta satu klarifikasi, jangan menebak periode.
        if ($dateFilter['type'] === 'none'
            && $period === 'semua'
            && $detailHint
            && preg_match('/\b(tanggal|tanggalnya)\b/u', $q) === 1
            && preg_match('/\b(transaksi|barang masuk|barang keluar|penerimaan|pengeluaran)\b/u', $q) === 1
        ) {
            return 'Sebutkan tanggal atau rentang tanggal yang ingin ditampilkan agar saya tidak menebak periode. '
                . 'Contoh: "Tampilkan rincian transaksi tanggal 18 Oktober 2026" atau '
                . '"Tampilkan rincian transaksi 1 sampai 18 Oktober 2026". '
                . 'Anda juga dapat menyebutkan nomor dokumen, misalnya "Tampilkan rincian dokumen GI-..."';
        }

        // --- Data pengguna / role / login ------------------------------------
        // Pertanyaan pelaku aktivitas ("siapa yang menerima barang?") dijawab
        // modul pengguna. Bila ada tanggal/nomor dokumen, pertanyaan tetap
        // diarahkan ke rincian transaksi agar tidak kehilangan filter.
        $userQuestion = self::isUserQuestion($q);
        $userRows = null;
        if (!$userQuestion && preg_match(
            '/\b(detail|profil|kapan|siapa|tampilkan|apa|terakhir|transaksi|username)\b/u',
            $q
        ) === 1) {
            // Nama pengguna yang disebut tanpa kata "pengguna" (mis.
            // "Tampilkan detail Hasan Khairi") tetap harus dikenali.
            $userRows = self::userRows();
            if (self::findUsersInQuestion($q, $userRows) !== []) {
                $userQuestion = true;
            }
        }

        if ($userQuestion
            && !($transactionWords && ($dateFilter['type'] !== 'none' || $docNumber !== null))
        ) {
            if ($role !== 'admin') {
                // Pertanyaan pelaku aktivitas tetap dijawab dengan pesan aman,
                // bukan diteruskan ke provider.
                if (preg_match('/\b(siapa|pelaku|petugas)\b/u', $q) === 1
                    && self::detectActivityModule($q) !== ''
                ) {
                    return 'Maaf, data pengguna yang melakukan transaksi hanya tersedia untuk Admin.';
                }
                return null; // guard role sudah menolak; jangan bocorkan data.
            }
            return self::answerUsers($q, $role, $userRows);
        }

        // Nomor dokumen GR/GI yang eksplisit selalu diarahkan ke rincian
        // transaksi, meskipun pertanyaan tidak memuat kata "barang masuk/keluar".
        if ($docNumber !== null
            || ($transactionWords
                && ($dateFilter['type'] !== 'none' || ($detailHint && $hasFlow)))
        ) {
            return self::answerTransactions($q, $role, $period, $periodLabel, $dash);
        }

        // --- Notifikasi WhatsApp (Admin) ------------------------------------
        if (preg_match('/\b(whatsapp|fonnte|notifikasi)\b/u', $q) === 1) {
            if ($role !== 'admin') {
                return null;
            }
            return self::answerWhatsapp($q);
        }

        // --- Audit log & backup (Admin) --------------------------------------
        if (preg_match('/\b(audit\s*log|log aktivitas|riwayat aktivitas)\b/u', $q) === 1) {
            if ($role !== 'admin') {
                return null;
            }
            return self::answerAuditLog($q);
        }
        if (preg_match('/\b(backup|restore|cadangan)\b/u', $q) === 1) {
            if ($role !== 'admin') {
                return null;
            }
            return self::answerBackups($q);
        }

        // --- Supplier / pemasok ---------------------------------------------
        if (preg_match('/\b(supplier|pemasok|vendor|penyuplai)\b/u', $q) === 1) {
            return self::answerSuppliers($q);
        }

        // --- Lokasi penyimpanan / rak / tingkat ------------------------------
        if (self::isLocationQuestion($q)) {
            $answer = self::answerLocations($q);
            if ($answer !== null) {
                return $answer;
            }
        }

        // --- Riwayat/mutasi stok (ledger) ------------------------------------
        if (preg_match('/\b(riwayat stok|mutasi stok|pergerakan stok|riwayat pergerakan)\b/u', $q) === 1) {
            return self::answerStockHistory($q, $periodLabel);
        }

        // --- Kategori --------------------------------------------------------
        if (str_contains($q, 'kategori')) {
            return self::answerCategories($q, $role);
        }

        // --- Sparepart tertentu (nama/kode) ---------------------------------
        $matches = self::findSparepartsInQuestion($q);
        if ($matches !== []) {
            return self::answerSpecificSparepart($q, $matches);
        }

        // --- Prediksi stok (ai_predictions) ----------------------------------
        // Pertanyaan prediksi/analisis dijawab dari snapshot prediksi terbaru,
        // bukan dari perkiraan model bahasa.
        if (preg_match('/\b(prediksi|forecast|ramalan|perkiraan\s+(stok|kebutuhan)|analisis\s+(stok|persediaan|kebutuhan))\b/u', $q) === 1) {
            return self::answerPredictions($q, $role);
        }

        // --- Reorder ---------------------------------------------------------
        if (preg_match('/reorder|re-order|restock|pembelian ulang|segera (dibeli|dipesan|beli|pesan)/u', $q) === 1) {
            return self::answerReorder($q, $role);
        }

        // --- PO / pembelian ---------------------------------------------------
        if (preg_match('/\b(po|purchase order|pesanan pembelian)\b/u', $q) === 1
            || (str_contains($q, 'pembelian') && preg_match('/\b(menunggu|disetujui|diterima|dibatalkan|status)\b/u', $q) === 1)
            || (preg_match('/\b(diterima|penerimaan)\b/u', $q) === 1
                && preg_match('/\b(barang|item|qty|kuantitas)\b/u', $q) === 1)
        ) {
            return self::answerPurchaseOrders($q, $dash);
        }

        // --- Transaksi --------------------------------------------------------
        if (preg_match('/\b(transaksi|barang masuk|barang keluar|keluar|penerimaan|pengeluaran|opname|penyesuaian|koreksi|riwayat)\b/u', $q) === 1) {
            return self::answerTransactions($q, $role, $period, $periodLabel, $dash);
        }

        // --- Status / daftar stok -------------------------------------------
        if (self::isStockQuestion($q)) {
            $answer = self::answerStock($q, $role, $periodLabel);
            if ($answer !== null) {
                return $answer;
            }
        }

        // --- Total sparepart tanpa kata "stok" -------------------------------
        if (str_contains($q, 'sparepart')
            && preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1
            && preg_match('/\b(stok|stock|masuk|keluar|transaksi|po|kategori)\b/u', $q) !== 1
        ) {
            return self::answerPartTotal($role, $dash);
        }

        return null;
    }

    // =====================================================================
    // Konteks terstruktur
    // =====================================================================

    /** @return array<string,mixed> */
    public static function dashboardNumbers(string $role): array
    {
        $all = Analytics::sparepartSummary(false);
        $active = Analytics::sparepartSummary(true);
        $stats = Analytics::stockStatusCounts(true);
        $today = Analytics::todayTotals();
        $counts = self::documentCounts();
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $purchase = Analytics::purchaseSummary($monthStart, $monthEnd);
        $issue = Analytics::issueSummary($monthStart, $monthEnd);
        $opname = Analytics::opnameSummary($monthStart, $monthEnd);
        $po = Analytics::poStatusCounts();
        $reorder = Analytics::reorderStatusCounts();

        $poTotal = 0;
        foreach ($po as $row) {
            $poTotal += (int) ($row['count'] ?? 0);
        }

        $out = [
            'sumber' => 'Dashboard ' . (ChatbotService::ROLE_LABELS[$role] ?? $role) . ' — angka aktual saat permintaan',
            'role' => $role,
            'satuan_angka' => 'jumlah dokumen/transaksi, bukan jumlah item atau kuantitas',
            'total_jenis_sparepart' => $role === 'admin' ? (int) $all['types'] : (int) $active['types'],
            'total_jenis_sparepart_semua' => (int) $all['types'],
            'total_jenis_sparepart_aktif' => (int) $active['types'],
            'total_stok_sparepart_aktif' => round((float) $active['stock'], 2),
            'nilai_persediaan_rata_rata' => round((float) $active['value_avg'], 2),
            'nilai_persediaan_harga_terakhir' => round((float) $active['value_last'], 2),
            'status_stok' => $stats,
            'transaksi' => [
                'total_dokumen_penerimaan' => (int) $counts['receipts'],
                'total_dokumen_pengeluaran' => (int) $counts['issues'],
                'total_dokumen_opname' => (int) $counts['opnames'],
                'total_pergerakan_ledger' => (int) $counts['movements'],
                'total_purchase_order' => (int) $counts['po'],
                'total_transaksi_penerimaan_dan_pengeluaran' => (int) $counts['receipts'] + (int) $counts['issues'],
                'hari_ini' => [
                    'tanggal' => date('Y-m-d'),
                    'dokumen_penerimaan' => (int) $today['receipts'],
                    'dokumen_pengeluaran' => (int) $today['issues'],
                    'total_transaksi' => (int) $today['receipts'] + (int) $today['issues'],
                    'qty_masuk' => (float) $today['masuk_qty'],
                    'qty_keluar' => (float) $today['keluar_qty'],
                ],
                'bulan_ini' => [
                    'periode' => ['dari' => $monthStart, 'sampai' => $monthEnd],
                    'pembelian_dokumen' => (int) $purchase['docs'],
                    'pembelian_qty' => (float) $purchase['qty'],
                    'pembelian_nilai' => (float) $purchase['value'],
                    'pengeluaran_dokumen' => (int) $issue['docs'],
                    'pengeluaran_qty' => (float) $issue['qty'],
                    'opname_total' => (int) $opname['total'],
                    'opname_terkonfirmasi' => (int) $opname['confirmed'],
                ],
            ],
            'po' => [
                'total' => $poTotal,
                'menunggu_persetujuan' => (int) ($po['MENUNGGU_PERSETUJUAN']['count'] ?? 0),
                'disetujui' => (int) ($po['DISETUJUI']['count'] ?? 0),
                'diproses_admin' => (int) ($po['DIPROSES_ADMIN']['count'] ?? 0),
                'sebagian_diterima' => (int) ($po['SEBAGIAN_DITERIMA']['count'] ?? 0),
                'selesai_diterima' => (int) ($po['SELESAI_DITERIMA']['count'] ?? 0),
                'ditolak' => (int) ($po['DITOLAK']['count'] ?? 0),
                'dibatalkan' => (int) ($po['DIBATALKAN']['count'] ?? 0),
            ],
            'reorder' => [
                'menunggu_persetujuan' => (int) ($reorder['MENUNGGU_PERSETUJUAN'] ?? 0),
                'disetujui' => (int) ($reorder['DISETUJUI'] ?? 0),
                'ditolak' => (int) ($reorder['DITOLAK'] ?? 0),
            ],
        ];

        if ($role === 'admin') {
            $out['pengguna'] = Analytics::userSummary();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function categorySummary(): array
    {
        $rows = self::fetchAll(
            'SELECT c.id, c.code, c.name, c.is_active,
                    COUNT(s.id) AS total_sparepart,
                    COALESCE(SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END), 0) AS sparepart_aktif
               FROM categories c
          LEFT JOIN spareparts s ON s.category_id = c.id
           GROUP BY c.id, c.code, c.name, c.is_active
           ORDER BY c.name ASC'
        );

        $list = [];
        $empty = [];
        $top = ['nama' => '', 'jumlah' => -1];
        $totalParts = 0;
        $totalActive = 0;
        $activeCategories = 0;

        foreach ($rows as $row) {
            $count = (int) ($row['total_sparepart'] ?? 0);
            $active = (int) ($row['sparepart_aktif'] ?? 0);
            $item = [
                'kode' => (string) ($row['code'] ?? ''),
                'nama' => (string) ($row['name'] ?? ''),
                'status' => ((int) ($row['is_active'] ?? 0) === 1) ? 'aktif' : 'nonaktif',
                'jumlah_sparepart' => $count,
                'jumlah_sparepart_aktif' => $active,
            ];
            $list[] = $item;
            $totalParts += $count;
            $totalActive += $active;
            if ((int) ($row['is_active'] ?? 0) === 1) {
                $activeCategories++;
            }
            if ($count > $top['jumlah']) {
                $top = ['nama' => $item['nama'], 'jumlah' => $count];
            }
            if ($count === 0) {
                $empty[] = $item['nama'];
            }
        }

        return [
            'total_kategori' => count($list),
            'total_kategori_aktif' => $activeCategories,
            'total_sparepart_semua_kategori' => $totalParts,
            'total_sparepart_aktif_semua_kategori' => $totalActive,
            'kategori_terbanyak' => $top['jumlah'] >= 0 ? $top : null,
            'kategori_tanpa_sparepart' => $empty,
            'daftar' => $list,
        ];
    }

    /** @return array<string,mixed> */
    public static function supplierSummary(): array
    {
        $rows = self::fetchAll(
            'SELECT s.id, s.code, s.name, s.is_active,
                    (SELECT COUNT(*) FROM spareparts sp WHERE sp.supplier_id = s.id) AS sparepart_count,
                    (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id = s.id) AS receipt_count,
                    (SELECT COALESCE(SUM(gri.quantity), 0)
                       FROM goods_receipts gr
                       JOIN goods_receipt_items gri ON gri.receipt_id = gr.id
                      WHERE gr.supplier_id = s.id) AS receipt_qty,
                    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count
               FROM suppliers s
           ORDER BY s.name ASC'
        );

        $list = [];
        $never = [];
        $active = 0;
        $top = ['nama' => '', 'dokumen' => 0, 'qty' => 0.0];

        foreach ($rows as $row) {
            $receipts = (int) ($row['receipt_count'] ?? 0);
            $qty = (float) ($row['receipt_qty'] ?? 0);
            $item = [
                'kode' => (string) ($row['code'] ?? ''),
                'nama' => (string) ($row['name'] ?? ''),
                'status' => ((int) ($row['is_active'] ?? 0) === 1) ? 'aktif' : 'nonaktif',
                'jumlah_sparepart' => (int) ($row['sparepart_count'] ?? 0),
                'jumlah_penerimaan' => $receipts,
                'qty_diterima' => $qty,
                'jumlah_po' => (int) ($row['po_count'] ?? 0),
            ];
            $list[] = $item;
            if ($item['status'] === 'aktif') {
                $active++;
            }
            if ($receipts === 0) {
                $never[] = $item['nama'];
            }
            if ($qty > $top['qty'] || ($qty === $top['qty'] && $receipts > $top['dokumen'] && $receipts > 0)) {
                $top = ['nama' => $item['nama'], 'dokumen' => $receipts, 'qty' => $qty];
            }
        }

        return [
            'total' => count($list),
            'total_aktif' => $active,
            'total_nonaktif' => count($list) - $active,
            'belum_memasok' => $never,
            'terbanyak' => $top['nama'] !== '' ? $top : null,
            'daftar' => $list,
        ];
    }

    /** @return array<string,mixed> */
    public static function locationSummary(): array
    {
        $rows = self::fetchAll(
            'SELECT sl.id, sl.code, sl.name, sl.description, sl.is_active,
                    (SELECT COUNT(*) FROM spareparts sp WHERE sp.location_id = sl.id) AS sparepart_count,
                    (SELECT COUNT(*) FROM spareparts sp WHERE sp.location_id = sl.id AND sp.is_active = 1) AS sparepart_aktif,
                    (SELECT COALESCE(SUM(sp.stock), 0) FROM spareparts sp
                      WHERE sp.location_id = sl.id AND sp.is_active = 1) AS total_stok
               FROM storage_locations sl
           ORDER BY sl.code ASC'
        );

        $list = [];
        $active = 0;
        $used = 0;
        $top = ['nama' => '', 'jumlah' => -1];

        foreach ($rows as $row) {
            $count = (int) ($row['sparepart_count'] ?? 0);
            $item = [
                'kode' => (string) ($row['code'] ?? ''),
                'nama' => (string) ($row['name'] ?? ''),
                'deskripsi' => (string) ($row['description'] ?? ''),
                'status' => ((int) ($row['is_active'] ?? 0) === 1) ? 'aktif' : 'nonaktif',
                'jumlah_sparepart' => $count,
                'jumlah_sparepart_aktif' => (int) ($row['sparepart_aktif'] ?? 0),
                'total_stok' => (float) ($row['total_stok'] ?? 0),
            ];
            $list[] = $item;
            if ($item['status'] === 'aktif') {
                $active++;
            }
            if ($count > 0) {
                $used++;
            }
            if ($count > $top['jumlah']) {
                $top = ['nama' => $item['nama'], 'kode' => $item['kode'], 'jumlah' => $count];
            }
        }

        return [
            'total' => count($list),
            'total_aktif' => $active,
            'total_nonaktif' => count($list) - $active,
            'digunakan' => $used,
            'terbanyak' => $top['jumlah'] >= 0 ? $top : null,
            'daftar' => $list,
        ];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public static function stockLists(): array
    {
        $rows = self::fetchAll(
            'SELECT id, code, name, unit, stock, min_stock
               FROM spareparts
              WHERE is_active = 1
           ORDER BY name ASC'
        );

        $lists = [
            'habis' => [],
            'kritis' => [],
            'menipis' => [],
            'tanpa_minimum' => [],
            'aman' => [],
        ];

        foreach ($rows as $row) {
            $status = Sparepart::stockStatusOf($row);
            if (isset($lists[$status]) && count($lists[$status]) < 100) {
                $lists[$status][] = [
                    'kode' => (string) ($row['code'] ?? ''),
                    'nama' => (string) ($row['name'] ?? ''),
                    'satuan' => (string) ($row['unit'] ?? ''),
                    'stok' => (float) ($row['stock'] ?? 0),
                    'minimum' => (float) ($row['min_stock'] ?? 0),
                ];
            }
        }

        return $lists;
    }

    /** @return array<int,array<string,mixed>> */
    public static function menuMap(string $role): array
    {
        $all = [
            ['modul' => 'Dashboard', 'jalur' => '/dashboard', 'fungsi' => 'Ringkasan stok, transaksi hari ini, peringatan, dan aktivitas.'],
            ['modul' => 'Data Sparepart', 'jalur' => '/sparepart', 'fungsi' => 'Daftar sparepart, stok, harga, kategori, dan status stok.'],
            ['modul' => 'Kategori', 'jalur' => '/kategori', 'fungsi' => 'Daftar kategori sparepart beserta jumlah sparepart.'],
            ['modul' => 'Supplier', 'jalur' => '/supplier', 'fungsi' => 'Daftar supplier, kontak, dan jumlah pasokan.'],
            ['modul' => 'Lokasi Penyimpanan', 'jalur' => '/lokasi', 'fungsi' => 'Daftar lokasi/rak penyimpanan dan sparepart di dalamnya.'],
            ['modul' => 'Barang Masuk', 'jalur' => '/transaksi/masuk', 'fungsi' => 'Penerimaan barang dan penambahan stok.'],
            ['modul' => 'Barang Keluar', 'jalur' => '/transaksi/keluar', 'fungsi' => 'Pengeluaran barang dan pengurangan stok.'],
            ['modul' => 'Stock Opname', 'jalur' => '/transaksi/opname', 'fungsi' => 'Penyesuaian stok berdasarkan hasil hitung fisik.'],
            ['modul' => 'Riwayat Transaksi', 'jalur' => '/transaksi/riwayat', 'fungsi' => 'Riwayat penerimaan, pengeluaran, dan penyesuaian.'],
            ['modul' => 'Reorder', 'jalur' => '/reorder', 'fungsi' => 'Permintaan reorder dan persetujuannya.'],
            ['modul' => 'Pembelian / PO', 'jalur' => '/pembelian', 'fungsi' => 'Purchase Order, persetujuan, penerimaan, dan pembayaran.'],
            ['modul' => 'Laporan', 'jalur' => '/laporan', 'fungsi' => 'Laporan persediaan, masuk/keluar, pembelian, dan stok minimum.'],
            ['modul' => 'Prediksi Stok', 'jalur' => '/prediksi', 'fungsi' => 'Rekomendasi reorder berbasis prediksi pemakaian.'],
        ];

        if ($role === 'admin') {
            $all[] = ['modul' => 'Pengguna', 'jalur' => '/pengguna', 'fungsi' => 'Daftar pengguna dan role.'];
            $all[] = ['modul' => 'Audit Log', 'jalur' => '/audit-log', 'fungsi' => 'Aktivitas pengguna dan perubahan data.'];
            $all[] = ['modul' => 'Pengaturan', 'jalur' => '/pengaturan', 'fungsi' => 'Identitas bengkel, parameter laporan, dan pengaturan chatbot.'];
            $all[] = ['modul' => 'WhatsApp Gateway', 'jalur' => '/pengaturan/whatsapp', 'fungsi' => 'Konfigurasi Fonnte dan penerima notifikasi.'];
            $all[] = ['modul' => 'Monitoring API', 'jalur' => '/monitoring/api', 'fungsi' => 'Pemakaian WhatsApp dan Gemini.'];
        }

        return $all;
    }

    /** @return array<string,mixed> */
    public static function whatsappSummary(): array
    {
        try {
            $status = self::fetchAll(
                'SELECT status, COUNT(*) AS total FROM whatsapp_notifications GROUP BY status'
            );
            $types = self::fetchAll(
                'SELECT notification_type, COUNT(*) AS total FROM whatsapp_notifications GROUP BY notification_type'
            );
            $counts = [];
            foreach ($status as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
            $byType = [];
            foreach ($types as $row) {
                $byType[(string) $row['notification_type']] = (int) $row['total'];
            }
            return [
                'total' => array_sum($counts),
                'per_status' => $counts,
                'per_jenis' => $byType,
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'per_status' => [], 'per_jenis' => []];
        }
    }

    // =====================================================================
    // Penyusun jawaban deterministik
    // =====================================================================

    private static function answerSuppliers(string $q): ?string
    {
        self::$lastContext = [
            'topic'      => 'supplier',
            'filter'     => 'supplier',
            'date_type'  => 'none',
            'date_from'  => '',
            'date_to'    => '',
            'date_label' => 'data supplier saat ini',
            'doc_numbers'=> [],
        ];
        $data = self::supplierSummary();
        $list = $data['daftar'];

        // Hubungan PO ↔ supplier (daftar ringkas, bukan data mentah massal).
        if (preg_match('/\b(po|purchase order|pesanan pembelian)\b/u', $q) === 1) {
            $rows = self::fetchAll(
                'SELECT po.po_number, po.status, po.created_at, s.name AS supplier_name,
                        COALESCE(SUM(poi.quantity), 0) AS ordered_qty,
                        COALESCE(SUM(poi.received_qty), 0) AS received_qty
                   FROM purchase_orders po
                   JOIN suppliers s ON s.id = po.supplier_id
              LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
               GROUP BY po.id, po.po_number, po.status, po.created_at, s.name
               ORDER BY po.created_at DESC
                  LIMIT 20'
            );
            if ($rows === []) {
                return 'Belum ada Purchase Order (PO) yang tercatat, sehingga belum ada supplier yang terkait dengan PO.';
            }
            $lines = ['Supplier yang terkait dengan Purchase Order (terbaru):'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['po_number'] . ': ' . $row['supplier_name']
                    . ' — status ' . self::poStatusLabel((string) $row['status'])
                    . ', qty dipesan ' . self::qty((float) $row['ordered_qty'])
                    . ', qty diterima ' . self::qty((float) $row['received_qty']);
            }
            return implode("\n", $lines);
        }

        if ($list === []) {
            return 'Data supplier belum ditemukan pada database SmartPartMan.';
        }

        // Jumlah dokumen pembelian/penerimaan per supplier.
        if (preg_match('/\b(setiap|masing-masing|tiap|per supplier|transaksi pembelian|riwayat pembelian)\b/u', $q) === 1) {
            $lines = ['Transaksi pembelian (penerimaan barang) per supplier:'];
            foreach ($list as $row) {
                $lines[] = '- ' . $row['nama'] . ': ' . self::n($row['jumlah_penerimaan'])
                    . ' dokumen penerimaan, qty ' . self::qty($row['qty_diterima'])
                    . ', ' . self::n($row['jumlah_po']) . ' PO';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(belum|tidak pernah)\b/u', $q) === 1 && preg_match('/\b(memasok|memasok|supply|penerimaan|pembelian)\b/u', $q) === 1) {
            $never = $data['belum_memasok'];
            if ($never === []) {
                return 'Semua supplier terdaftar sudah pernah memasok barang. Tidak ada supplier tanpa penerimaan.';
            }
            return 'Supplier yang belum pernah memasok barang (belum memiliki dokumen penerimaan): '
                . implode(', ', $never) . '.';
        }

        if (preg_match('/\b(terbanyak|paling banyak|tertinggi|terbesar)\b/u', $q) === 1) {
            $top = $data['terbanyak'];
            if (!is_array($top) || $top['dokumen'] <= 0) {
                return 'Belum ada supplier yang memiliki dokumen penerimaan barang, sehingga belum dapat ditentukan supplier dengan pasokan terbanyak.';
            }
            return 'Supplier dengan pasokan barang terbanyak berdasarkan penerimaan: ' . $top['nama']
                . ' dengan ' . self::n((int) $top['dokumen']) . ' dokumen penerimaan dan total qty '
                . self::qty((float) $top['qty']) . '.';
        }

        if (preg_match('/\b(apa saja|sebutkan|daftar|siapa saja|tampilkan|nama)\b/u', $q) === 1) {
            $lines = ['Supplier yang terdaftar (' . self::n($data['total']) . ' supplier):'];
            foreach ($list as $row) {
                $lines[] = '- ' . $row['nama'] . ' (' . $row['kode'] . ') — ' . $row['status']
                    . ', ' . self::n($row['jumlah_sparepart']) . ' sparepart';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1) {
            return 'Berdasarkan database SmartPartMan, terdapat ' . self::n($data['total'])
                . ' supplier terdaftar, terdiri dari ' . self::n($data['total_aktif']) . ' supplier aktif dan '
                . self::n($data['total_nonaktif']) . ' supplier nonaktif. '
                . self::n(count($data['belum_memasok'])) . ' supplier belum pernah memasok barang.';
        }

        return null;
    }

    private static function isLocationQuestion(string $q): bool
    {
        return preg_match('/\b(lokasi|rak|penyimpanan|disimpan|tingkat)\b/u', $q) === 1;
    }

    private static function answerLocations(string $q): ?string
    {
        $data = self::locationSummary();
        $list = $data['daftar'];

        // Daftar sparepart pada rak/lokasi yang disebutkan.
        $specific = self::matchLocation($q);
        if ($specific !== null) {
            return self::formatLocationParts($specific);
        }
        if (preg_match('/\b(rak|lokasi)\s+tertentu\b/u', $q) === 1
            && preg_match('/\b(sparepart|barang|isi|daftar|tampilkan)\b/u', $q) === 1
        ) {
            $names = array_map(static fn (array $row): string => $row['nama'] . ' (' . $row['kode'] . ')', $list);
            return 'Sebutkan kode atau nama rak/lokasi yang ingin dilihat. Lokasi/rak yang tersedia: '
                . implode(', ', $names) . '.';
        }

        // Lokasi penyimpanan sparepart tertentu.
        if (preg_match('/\b(di mana|dimana|lokasi|disimpan|penyimpanan|tempat)\b/u', $q) === 1) {
            $parts = self::findMentionedParts($q);
            if (count($parts) === 1) {
                $part = $parts[0];
                $location = trim((string) ($part['loc_name'] ?? '')) !== ''
                    ? (string) $part['loc_name'] . ' (' . (string) $part['loc_code'] . ')'
                    : '';
                if ($location === '') {
                    return 'Sparepart ' . $part['name'] . ' (' . $part['code'] . ') belum ditempatkan pada lokasi penyimpanan mana pun.';
                }
                return 'Sparepart ' . $part['name'] . ' (' . $part['code'] . ') disimpan pada lokasi '
                    . $location . '. Stok saat ini ' . self::qty((float) $part['stock']) . ' ' . (string) $part['unit'] . '.';
            }
            if (count($parts) > 1) {
                $lines = ['Ada beberapa sparepart yang cocok. Sebutkan kode/nama lebih spesifik:'];
                foreach (array_slice($parts, 0, 8) as $row) {
                    $loc = trim((string) ($row['loc_name'] ?? '')) !== ''
                        ? (string) $row['loc_name'] . ' (' . (string) $row['loc_code'] . ')'
                        : 'belum ditempatkan';
                    $lines[] = '- ' . $row['name'] . ' (' . $row['code'] . '): ' . $loc;
                }
                return implode("\n", $lines);
            }
        }

        if ($list === []) {
            return 'Data lokasi penyimpanan belum ditemukan pada database SmartPartMan.';
        }

        if (preg_match('/\b(tidak aktif|nonaktif|non aktif)\b/u', $q) === 1) {
            $inactive = array_values(array_filter($list, static fn (array $row): bool => $row['status'] === 'nonaktif'));
            if ($inactive === []) {
                return 'Semua lokasi/rak penyimpanan saat ini berstatus aktif. Tidak ada rak nonaktif.';
            }
            $names = array_map(static fn (array $row): string => $row['nama'] . ' (' . $row['kode'] . ')', $inactive);
            return 'Lokasi/rak yang tidak aktif: ' . implode(', ', $names) . '.';
        }

        if (preg_match('/\b(digunakan|terpakai|berisi|memiliki sparepart)\b/u', $q) === 1
            && preg_match('/\b(terbanyak|paling banyak|tertinggi)\b/u', $q) !== 1) {
            $used = array_values(array_filter($list, static fn (array $row): bool => $row['jumlah_sparepart'] > 0));
            if ($used === []) {
                return 'Belum ada lokasi/rak yang digunakan untuk menyimpan sparepart.';
            }
            $lines = ['Lokasi/rak yang digunakan (' . self::n(count($used)) . ' dari ' . self::n($data['total']) . '):'];
            foreach ($used as $row) {
                $lines[] = '- ' . $row['nama'] . ' (' . $row['kode'] . '): ' . self::n($row['jumlah_sparepart']) . ' sparepart';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(terbanyak|paling banyak|tertinggi)\b/u', $q) === 1) {
            $top = $data['terbanyak'];
            if (!is_array($top) || $top['jumlah'] <= 0) {
                return 'Belum ada lokasi/rak yang memiliki sparepart, sehingga belum dapat ditentukan rak dengan sparepart terbanyak.';
            }
            return 'Lokasi/rak dengan sparepart terbanyak: ' . $top['nama'] . ' (' . $top['kode'] . ') sebanyak '
                . self::n((int) $top['jumlah']) . ' sparepart.';
        }

        if (preg_match('/\b(tingkat)\b/u', $q) === 1) {
            return 'Database SmartPartMan tidak menyimpan tingkat penyimpanan sebagai tabel terpisah. '
                . 'Setiap baris pada menu Lokasi Penyimpanan mewakili satu lokasi/rak (kolom kode dan nama), '
                . 'sehingga jumlah tingkat tidak dihitung terpisah dari jumlah lokasi/rak. '
                . 'Saat ini terdapat ' . self::n($data['total']) . ' lokasi/rak terdaftar ('
                . self::n($data['total_aktif']) . ' aktif).';
        }

        if (preg_match('/\b(kode|daftar|semua|tampilkan|apa saja|sebutkan)\b/u', $q) === 1) {
            $lines = ['Kode lokasi/rak penyimpanan (' . self::n($data['total']) . ' terdaftar):'];
            foreach ($list as $row) {
                $lines[] = '- ' . $row['kode'] . ' — ' . $row['nama'] . ' (' . $row['status']
                    . ', ' . self::n($row['jumlah_sparepart']) . ' sparepart)';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1) {
            return 'Pada menu Lokasi Penyimpanan terdapat ' . self::n($data['total'])
                . ' lokasi/rak terdaftar (' . self::n($data['total_aktif']) . ' aktif dan '
                . self::n($data['total_nonaktif']) . ' nonaktif). '
                . self::n($data['digunakan']) . ' lokasi/rak sudah digunakan untuk menyimpan sparepart.';
        }

        return null;
    }

    /**
     * Cari lokasi/rak yang kode atau namanya disebut pada pertanyaan.
     *
     * @return array<string,mixed>|null
     */
    private static function matchLocation(string $q): ?array
    {
        $rows = self::fetchAll('SELECT id, code, name, description, is_active FROM storage_locations ORDER BY code ASC');
        $best = null;
        $bestLen = 0;
        foreach ($rows as $row) {
            $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
            $code = mb_strtolower(trim((string) ($row['code'] ?? '')));
            if ($name !== '' && mb_strlen($name) >= 3 && str_contains($q, $name) && mb_strlen($name) > $bestLen) {
                $best = $row;
                $bestLen = mb_strlen($name);
            }
            if ($code !== '' && mb_strlen($code) >= 2
                && preg_match('/\b' . preg_quote($code, '/') . '\b/u', $q) === 1
                && mb_strlen($code) > $bestLen
            ) {
                $best = $row;
                $bestLen = mb_strlen($code);
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $location */
    private static function formatLocationParts(array $location): string
    {
        $rows = self::fetchAll(
            'SELECT sp.code, sp.name, sp.unit, sp.stock, sp.min_stock, sp.is_active,
                    c.name AS category_name
               FROM spareparts sp
          LEFT JOIN categories c ON c.id = sp.category_id
              WHERE sp.location_id = ?
           ORDER BY sp.name ASC',
            [(int) $location['id']]
        );

        $name = (string) $location['name'];
        $code = (string) $location['code'];
        $status = ((int) ($location['is_active'] ?? 0) === 1) ? 'aktif' : 'nonaktif';
        if ($rows === []) {
            return 'Lokasi/rak ' . $name . ' (' . $code . ', ' . $status . ') belum memiliki sparepart.';
        }

        $totalStock = 0.0;
        foreach ($rows as $row) {
            $totalStock += (float) $row['stock'];
        }

        $lines = ['Sparepart pada lokasi/rak ' . $name . ' (' . $code . ', status ' . $status . '): '
            . self::n(count($rows)) . ' sparepart, total stok ' . self::qty($totalStock) . ' unit.'];
        foreach ($rows as $i => $row) {
            $rowStatus = (int) ($row['is_active'] ?? 0) === 1
                ? self::statusLabel(Sparepart::stockStatusOf($row))
                : 'nonaktif';
            $lines[] = ($i + 1) . '. ' . $row['name'] . ' (' . $row['code'] . ')'
                . ' · kategori ' . (trim((string) ($row['category_name'] ?? '')) !== '' ? $row['category_name'] : '-')
                . ' · stok ' . self::qty((float) $row['stock']) . ' ' . (string) $row['unit']
                . ' · minimum ' . self::qty((float) $row['min_stock']) . ' ' . (string) $row['unit']
                . ' · status ' . $rowStatus;
        }

        $lines[] = 'Catatan: database SmartPartMan tidak memiliki tabel tingkat penyimpanan terpisah; '
            . 'satu baris lokasi mewakili satu rak/lokasi.';
        return implode("\n", $lines);
    }

    private static function answerStockHistory(string $q, string $periodLabel): string
    {
        $period = self::detectPeriod($q);

        if ($period === 'semua') {
            $total = (int) (self::fetchOne('SELECT COUNT(*) AS total FROM stock_movements')['total'] ?? 0);
            $rows = $total === 0 ? [] : self::fetchAll(
                'SELECT sm.movement_type, sm.quantity, sm.stock_before, sm.stock_after,
                        sm.reference_number, sm.movement_date,
                        sp.code, sp.name, sp.unit, u.full_name
                   FROM stock_movements sm
                   JOIN spareparts sp ON sp.id = sm.sparepart_id
              LEFT JOIN users u ON u.id = sm.user_id
               ORDER BY sm.movement_date DESC, sm.id DESC
                  LIMIT 15'
            );
        } else {
            [$from, $to] = self::range($period);
            $total = (int) (self::fetchOne(
                'SELECT COUNT(*) AS total FROM stock_movements WHERE movement_date BETWEEN ? AND ?',
                [$from, $to]
            )['total'] ?? 0);
            $rows = $total === 0 ? [] : self::fetchAll(
                'SELECT sm.movement_type, sm.quantity, sm.stock_before, sm.stock_after,
                        sm.reference_number, sm.movement_date,
                        sp.code, sp.name, sp.unit, u.full_name
                   FROM stock_movements sm
                   JOIN spareparts sp ON sp.id = sm.sparepart_id
              LEFT JOIN users u ON u.id = sm.user_id
                  WHERE sm.movement_date BETWEEN ? AND ?
               ORDER BY sm.movement_date DESC, sm.id DESC
                  LIMIT 15',
                [$from, $to]
            );
        }

        if ($total === 0) {
            return 'Belum ada riwayat pergerakan stok pada ' . $periodLabel . '.';
        }

        $lines = ['Riwayat pergerakan stok ' . $periodLabel . ' (total ' . self::n($total) . ' baris, 15 terbaru):'];
        foreach ($rows as $row) {
            $qty = (float) $row['quantity'];
            $sign = $qty >= 0 ? '+' : '';
            $lines[] = '- ' . $row['movement_date'] . ' · ' . $row['name'] . ' (' . $row['code'] . ') · '
                . self::movementLabel((string) $row['movement_type']) . ' '
                . $sign . self::qty($qty) . ' ' . (string) $row['unit']
                . ' · stok ' . self::qty((float) $row['stock_before']) . ' → ' . self::qty((float) $row['stock_after'])
                . (trim((string) ($row['reference_number'] ?? '')) !== '' ? ' · ' . $row['reference_number'] : '')
                . (trim((string) ($row['full_name'] ?? '')) !== '' ? ' · oleh ' . $row['full_name'] : '');
        }
        if ($total > 15) {
            $lines[] = '- dan ' . self::n($total - 15) . ' baris lainnya.';
        }
        return implode("\n", $lines);
    }

    private static function answerPartTotal(string $role, array $dash): string    {
        $active = (int) $dash['total_jenis_sparepart_aktif'];
        $all = (int) $dash['total_jenis_sparepart_semua'];

        if ($role === 'admin') {
            return 'Total sparepart yang ditampilkan Dashboard Admin saat ini: ' . self::n($all)
                . ' sparepart (seluruh data), terdiri dari ' . self::n($active) . ' sparepart aktif.'
                . "\n" . 'Sparepart nonaktif tetap dihitung pada total Dashboard Admin, tetapi tidak masuk status stok aktif.';
        }

        return 'Total jenis sparepart aktif yang ditampilkan Dashboard ' . self::roleLabel($role)
            . ' saat ini: ' . self::n($active) . ' sparepart.';
    }

    private static function answerCategories(string $q, string $role): string
    {
        $data = self::categorySummary();
        $list = $data['daftar'];

        // Daftar sparepart pada kategori yang disebutkan namanya.
        $specific = self::matchCategory($q);
        if ($specific !== null) {
            return self::formatCategoryParts($specific);
        }
        if (preg_match('/\b(kategori tertentu|kategori apa|kategori mana)\b/u', $q) === 1
            && preg_match('/\b(sparepart|barang|isi|daftar|tampilkan)\b/u', $q) === 1
        ) {
            $names = array_map(static fn (array $row): string => $row['nama'], $list);
            return 'Sebutkan nama kategori yang ingin dilihat. Kategori yang tersedia: '
                . implode(', ', $names) . '.';
        }

        if (preg_match('/\b(terbanyak|paling banyak|tertinggi|paling besar)\b/u', $q) === 1) {
            $top = $data['kategori_terbanyak'];
            if (!is_array($top) || $top['jumlah'] <= 0) {
                return 'Belum ada kategori yang memiliki sparepart.';
            }
            return 'Kategori dengan sparepart terbanyak saat ini: ' . $top['nama']
                . ' sebanyak ' . self::n($top['jumlah']) . ' sparepart (seluruh data).';
        }

        if (preg_match('/\b(belum|tanpa|kosong|tidak memiliki|tidak punya)\b/u', $q) === 1) {
            $empty = $data['kategori_tanpa_sparepart'];
            if ($empty === []) {
                return 'Semua kategori saat ini sudah memiliki sparepart. Tidak ada kategori kosong.';
            }
            return 'Kategori yang belum memiliki sparepart: ' . implode(', ', $empty) . '.';
        }

        if (str_contains($q, 'total sparepart') && preg_match('/\b(setiap|masing|tiap|per kategori)\b/u', $q) !== 1) {
            return 'Total sparepart dari seluruh kategori: ' . self::n($data['total_sparepart_semua_kategori'])
                . ' sparepart (seluruh data), terdiri dari ' . self::n($data['total_sparepart_aktif_semua_kategori'])
                . ' sparepart aktif. Jumlah kategori: ' . self::n($data['total_kategori']) . '.';
        }

        if (preg_match('/\b(setiap|masing|tiap|per kategori|berdasarkan kategori|menurut kategori)\b/u', $q) === 1) {
            if (preg_match('/\b(stok|stock|persediaan)\b/u', $q) === 1) {
                $rows = self::fetchAll(
                    'SELECT c.name,
                            COUNT(s.id) AS jumlah_sparepart,
                            COALESCE(SUM(CASE WHEN s.is_active = 1 THEN s.stock ELSE 0 END), 0) AS total_stok
                       FROM categories c
                  LEFT JOIN spareparts s ON s.category_id = c.id
                   GROUP BY c.id, c.name
                   ORDER BY c.name ASC'
                );
                $lines = ['Stok sparepart aktif per kategori (seluruh data):'];
                $grand = 0.0;
                foreach ($rows as $row) {
                    $stock = (float) $row['total_stok'];
                    $grand += $stock;
                    $lines[] = '- ' . $row['name'] . ': ' . self::qty($stock)
                        . ' unit dari ' . self::n((int) $row['jumlah_sparepart']) . ' sparepart';
                }
                $lines[] = 'Total stok sparepart aktif: ' . self::qty($grand) . ' unit.';
                return implode("\n", $lines);
            }

            $lines = ['Jumlah sparepart per kategori (seluruh data):'];
            foreach ($list as $row) {
                $lines[] = '- ' . $row['nama'] . ': ' . self::n($row['jumlah_sparepart'])
                    . ' sparepart (' . self::n($row['jumlah_sparepart_aktif']) . ' aktif)';
            }
            $lines[] = 'Total: ' . self::n($data['total_kategori']) . ' kategori, '
                . self::n($data['total_sparepart_semua_kategori']) . ' sparepart.';
            return implode("\n", $lines);
        }

        if (preg_match('/\b(apa saja|sebutkan|daftar|mana saja|tersedia)\b/u', $q) === 1) {
            if ($list === []) {
                return 'Data kategori sparepart belum tersedia.';
            }
            $lines = ['Kategori sparepart yang tersedia (' . self::n($data['total_kategori']) . ' kategori):'];
            foreach ($list as $row) {
                $lines[] = '- ' . $row['nama'] . ': ' . self::n($row['jumlah_sparepart']) . ' sparepart';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1) {
            return 'Jumlah kategori sparepart saat ini: ' . self::n($data['total_kategori']) . ' kategori ('
                . self::n($data['total_kategori_aktif']) . ' aktif), dengan total '
                . self::n($data['total_sparepart_semua_kategori']) . ' sparepart.';
        }

        return null;
    }

    /**
     * Cari kategori yang namanya (atau kodenya) disebut pada pertanyaan.
     *
     * @return array<string,mixed>|null
     */
    private static function matchCategory(string $q): ?array
    {
        $rows = self::fetchAll('SELECT id, code, name, is_active FROM categories ORDER BY name ASC');
        $best = null;
        $bestLen = 0;
        foreach ($rows as $row) {
            $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
            $code = mb_strtolower(trim((string) ($row['code'] ?? '')));
            if ($name !== '' && mb_strlen($name) >= 3 && str_contains($q, $name) && mb_strlen($name) > $bestLen) {
                $best = $row;
                $bestLen = mb_strlen($name);
            }
            if ($code !== '' && mb_strlen($code) >= 2
                && preg_match('/\b' . preg_quote($code, '/') . '\b/u', $q) === 1
                && mb_strlen($code) > $bestLen
            ) {
                $best = $row;
                $bestLen = mb_strlen($code);
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $category */
    private static function formatCategoryParts(array $category): string
    {
        $rows = self::fetchAll(
            'SELECT sp.code, sp.name, sp.unit, sp.stock, sp.min_stock, sp.is_active,
                    sl.code AS loc_code, sl.name AS loc_name
               FROM spareparts sp
          LEFT JOIN storage_locations sl ON sl.id = sp.location_id
              WHERE sp.category_id = ?
           ORDER BY sp.name ASC',
            [(int) $category['id']]
        );

        $name = (string) $category['name'];
        $status = ((int) ($category['is_active'] ?? 0) === 1) ? 'aktif' : 'nonaktif';
        if ($rows === []) {
            return 'Kategori ' . $name . ' (' . $status . ') belum memiliki sparepart.';
        }

        $activeCount = 0;
        foreach ($rows as $row) {
            if ((int) ($row['is_active'] ?? 0) === 1) {
                $activeCount++;
            }
        }

        $lines = ['Sparepart pada kategori ' . $name . ' (kode ' . (string) $category['code']
            . ', status ' . $status . '): ' . self::n(count($rows)) . ' sparepart, '
            . self::n($activeCount) . ' aktif.'];
        foreach ($rows as $i => $row) {
            $where = trim((string) ($row['loc_name'] ?? '')) !== ''
                ? (string) $row['loc_name'] . ' (' . (string) $row['loc_code'] . ')'
                : 'belum ditempatkan';
            $rowStatus = (int) ($row['is_active'] ?? 0) === 1
                ? self::statusLabel(Sparepart::stockStatusOf($row))
                : 'nonaktif';
            $lines[] = ($i + 1) . '. ' . $row['name'] . ' (' . $row['code'] . ')'
                . ' · stok ' . self::qty((float) $row['stock']) . ' ' . (string) $row['unit']
                . ' · minimum ' . self::qty((float) $row['min_stock']) . ' ' . (string) $row['unit']
                . ' · status ' . $rowStatus
                . ' · lokasi ' . $where;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $matches
     */
    private static function answerSpecificSparepart(string $q, array $matches): ?string
    {
        $part = $matches[0];
        $name = (string) ($part['name'] ?? '');
        $unit = (string) ($part['unit'] ?? '');
        $stock = (float) ($part['stock'] ?? 0);
        $min = (float) ($part['min_stock'] ?? 0);
        $status = Sparepart::stockStatusOf($part);

        if (count($matches) > 1) {
            $lines = ['Ada beberapa sparepart yang cocok. Sebutkan kode/nama lebih spesifik:', ];
            foreach (array_slice($matches, 0, 8) as $row) {
                $lines[] = '- ' . $row['code'] . ' — ' . $row['name']
                    . ' (stok ' . self::qty((float) $row['stock']) . ' ' . (string) $row['unit']
                    . ', minimum ' . self::qty((float) $row['min_stock']) . ')';
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(minimum|minimal|batas)\b/u', $q) === 1) {
            if ($min <= 0) {
                return 'Batas minimum stok ' . $name . ' (' . (string) $part['code'] . ') belum diatur (0).'
                    . ' Dashboard menandainya sebagai "tanpa minimum"; sebaiknya isi stok minimum agar status stok terdefinisi.';
            }
            return 'Batas minimum stok ' . $name . ' (' . (string) $part['code'] . ') adalah '
                . self::qty($min) . ' ' . $unit . '. Stok saat ini ' . self::qty($stock) . ' ' . $unit
                . ' dengan status ' . self::statusLabel($status) . '.';
        }

        return 'Stok ' . $name . ' (' . (string) $part['code'] . ') saat ini: ' . self::qty($stock) . ' ' . $unit
            . '. Stok minimum ' . self::qty($min) . ' ' . $unit . '. Status: ' . self::statusLabel($status) . '.';
    }

    private static function isStockQuestion(string $q): bool
    {
        return preg_match('/\b(stok|stock|tersedia|menipis|kritis|habis|aman|minimum|persediaan)\b/u', $q) === 1;
    }

    private static function answerStock(string $q, string $role, string $periodLabel): ?string
    {
        $dash = self::dashboardNumbers($role);
        $stats = $dash['status_stok'];
        $lists = self::stockLists();

        $wantsList = preg_match('/\b(apa saja|sebutkan|daftar|mana saja|mana yang|siapa|setiap|masing-masing|tiap)\b/u', $q) === 1;
        $wantsCount = preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1;

        if (preg_match('/\b(total stok|stok tersedia|jumlah stok|seluruh stok)\b/u', $q) === 1) {
            self::rememberStockContext('total');
            return 'Total stok seluruh sparepart aktif saat ini: ' . self::n((float) $dash['total_stok_sparepart_aktif'])
                . ' unit (dari ' . self::n((int) $dash['total_jenis_sparepart_aktif']) . ' jenis sparepart aktif).';
        }

        foreach (['habis', 'kritis', 'menipis', 'aman'] as $status) {
            if (preg_match('/\b' . $status . '\b/u', $q) !== 1) {
                continue;
            }
            self::rememberStockContext($status);
            $count = (int) ($stats[$status] ?? 0);
            if ($wantsList || preg_match('/\b(sparepart apa|apa saja|sebutkan|daftar)\b/u', $q) === 1) {
                $rows = $lists[$status] ?? [];
                if ($rows === []) {
                    return 'Tidak ada sparepart aktif dengan status ' . self::statusLabel($status) . ' saat ini.';
                }
                $lines = ['Sparepart aktif dengan status ' . self::statusLabel($status)
                    . ' (' . self::n($count) . ' sparepart):'];
                foreach (array_slice($rows, 0, 20) as $row) {
                    $lines[] = '- ' . $row['nama'] . ' (' . $row['kode'] . '): stok '
                        . self::qty((float) $row['stok']) . ' ' . $row['satuan']
                        . ', minimum ' . self::qty((float) $row['minimum']) . ' ' . $row['satuan'];
                }
                if ($count > 20) {
                    $lines[] = '- dan ' . self::n($count - 20) . ' sparepart lainnya.';
                }
                return implode("\n", $lines);
            }
            return 'Jumlah sparepart aktif dengan status ' . self::statusLabel($status) . ' saat ini: '
                . self::n($count) . ' sparepart.';
        }

        if (preg_match('/\b(tanpa minimum|belum ada minimum|minimum belum)\b/u', $q) === 1) {
            self::rememberStockContext('tanpa_minimum');
            return 'Jumlah sparepart aktif tanpa stok minimum valid: ' . self::n((int) ($stats['tanpa_minimum'] ?? 0))
                . ' sparepart.';
        }

        if ($wantsCount) {
            self::rememberStockContext('ringkasan');
            return 'Status stok sparepart aktif saat ini: ' . self::n((int) ($stats['habis'] ?? 0)) . ' habis, '
                . self::n((int) ($stats['kritis'] ?? 0)) . ' kritis, '
                . self::n((int) ($stats['menipis'] ?? 0)) . ' menipis, '
                . self::n((int) ($stats['aman'] ?? 0)) . ' aman, dan '
                . self::n((int) ($stats['tanpa_minimum'] ?? 0)) . ' tanpa minimum.';
        }

        return null;
    }

    /** Simpan filter stok terakhir agar "sebutkan barangnya" tetap satu topik. */
    private static function rememberStockContext(string $filter): void
    {
        self::$lastContext = [
            'topic'      => 'stock',
            'filter'     => $filter,
            'date_type'  => 'none',
            'date_from'  => '',
            'date_to'    => '',
            'date_label' => 'kondisi stok saat ini',
            'doc_numbers'=> [],
        ];
    }

    /**
     * Prediksi stok dari tabel ai_predictions (snapshot terbaru per sparepart).
     * Angka rekomendasi berasal dari pipeline prediksi, bukan dari model bahasa.
     */
    private static function answerPredictions(string $q, string $role): string
    {
        self::$lastContext = [
            'topic'      => 'prediction',
            'filter'     => 'prediction',
            'date_type'  => 'none',
            'date_from'  => '',
            'date_to'    => '',
            'date_label' => 'snapshot prediksi terbaru',
            'doc_numbers'=> [],
        ];

        $summary = AiPrediction::summary();
        $recommendations = Analytics::aiRecommendations(15);
        $wantsList = preg_match('/\b(daftar|tampilkan|rincian|detail|apa saja|sebutkan|siapa|mana)\b/u', $q) === 1;

        $lines = ['Ringkasan prediksi stok SmartPartMan:'];
        $lines[] = '- Sparepart aktif: ' . self::n((int) ($summary['active'] ?? 0)) . '.';
        $lines[] = '- Sudah dianalisis: ' . self::n((int) ($summary['total'] ?? 0))
            . '; belum dianalisis: ' . self::n((int) ($summary['pending'] ?? 0)) . '.';
        $lines[] = '- Risiko stok habis tinggi: ' . self::n((int) ($summary['risk_high'] ?? 0))
            . '; risiko sedang: ' . self::n((int) ($summary['risk_medium'] ?? 0)) . '.';
        $latest = (string) ($summary['latest_at'] ?? '');
        if ($latest !== '') {
            $lines[] = '- Analisis terakhir diproses: ' . (self::formatDateTimeId($latest) ?? $latest) . '.';
        }

        if ($recommendations === []) {
            $lines[] = '';
            $lines[] = 'Belum ada rekomendasi pemesanan pada tabel prediksi. '
                . 'Buka menu Prediksi Stok untuk menjalankan analisis (khusus Partman).';
            $lines[] = 'Prediksi bersifat rekomendasi, bukan pesanan otomatis.';
            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = $wantsList
            ? 'Rekomendasi pemesanan berdasarkan prediksi terbaru:'
            : 'Rekomendasi utama berdasarkan prediksi terbaru:';
        $limit = $wantsList ? 15 : 8;
        foreach (array_slice($recommendations, 0, $limit) as $index => $row) {
            $unit = trim((string) ($row['unit'] ?? ''));
            $lines[] = ($index + 1) . '. ' . (string) ($row['sparepart_name'] ?? '-')
                . ' (' . (string) ($row['sparepart_code'] ?? '-') . ')'
                . "\n   Stok saat ini: " . self::qty((float) ($row['stock'] ?? 0))
                . ($unit !== '' ? ' ' . $unit : '')
                . '; minimum: ' . self::qty((float) ($row['min_stock'] ?? 0))
                . ($unit !== '' ? ' ' . $unit : '')
                . "\n   Prediksi kebutuhan: " . self::qty((float) ($row['predicted_demand'] ?? 0))
                . ($unit !== '' ? ' ' . $unit : '')
                . '; saran pesan: ' . self::qty((float) ($row['recommended_qty'] ?? 0))
                . ($unit !== '' ? ' ' . $unit : '')
                . "\n   Risiko: " . self::riskLabel((string) ($row['stockout_risk'] ?? ''))
                . '; kecukupan data: ' . self::sufficiencyLabel((string) ($row['data_sufficiency'] ?? ''));
        }
        if (count($recommendations) > $limit) {
            $lines[] = '- dan ' . self::n(count($recommendations) - $limit) . ' rekomendasi lainnya.';
        }
        $lines[] = '';
        $lines[] = 'Prediksi bersifat rekomendasi, bukan pesanan otomatis. '
            . 'Data terbatas bila riwayat pemakaian belum cukup.';
        return implode("\n", $lines);
    }

    /**
     * Sparepart yang perlu segera dipesan: gabungan status stok aktual dan
     * rekomendasi ai_predictions, ditambah status permintaan reorder.
     */
    private static function answerReorder(string $q, string $role): string
    {
        self::$lastContext = [
            'topic'      => 'reorder',
            'filter'     => 'reorder',
            'date_type'  => 'none',
            'date_from'  => '',
            'date_to'    => '',
            'date_label' => 'kondisi stok dan prediksi saat ini',
            'doc_numbers'=> [],
        ];
        $lists = self::stockLists();
        $rows = array_merge($lists['habis'], $lists['kritis'], $lists['menipis']);
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $key = (string) $row['kode'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $row;
            }
        }

        $recommendations = Analytics::aiRecommendations(10);
        $status = Analytics::reorderStatusCounts();
        $pending = (int) ($status['MENUNGGU_PERSETUJUAN'] ?? 0);
        $approved = (int) ($status['DISETUJUI'] ?? 0);
        $rejected = (int) ($status['DITOLAK'] ?? 0);

        if ($unique === [] && $recommendations === []) {
            return 'Tidak ada sparepart aktif yang perlu segera dipesan berdasarkan status stok maupun prediksi saat ini. '
                . 'Permintaan reorder saat ini: ' . self::n($pending) . ' menunggu persetujuan, '
                . self::n($approved) . ' disetujui, ' . self::n($rejected) . ' ditolak.';
        }

        $lines = ['Sparepart yang perlu segera dipesan berdasarkan data SmartPartMan:'];

        if ($recommendations !== []) {
            $lines[] = '';
            $lines[] = 'Berdasarkan prediksi AI terbaru:';
            foreach (array_slice($recommendations, 0, 10) as $row) {
                $unit = trim((string) ($row['unit'] ?? ''));
                $lines[] = '- ' . (string) ($row['sparepart_name'] ?? '-')
                    . ' (' . (string) ($row['sparepart_code'] ?? '-') . '): stok '
                    . self::qty((float) ($row['stock'] ?? 0)) . ($unit !== '' ? ' ' . $unit : '')
                    . ', minimum ' . self::qty((float) ($row['min_stock'] ?? 0))
                    . ($unit !== '' ? ' ' . $unit : '')
                    . ', saran pesan ' . self::qty((float) ($row['recommended_qty'] ?? 0))
                    . ($unit !== '' ? ' ' . $unit : '')
                    . ', risiko ' . self::riskLabel((string) ($row['stockout_risk'] ?? '')) . '.';
            }
        } else {
            $prediction = AiPrediction::summary();
            $lines[] = '';
            if ((int) ($prediction['total'] ?? 0) > 0) {
                $lines[] = 'Prediksi AI: ' . self::n((int) ($prediction['total'] ?? 0))
                    . ' sparepart sudah dianalisis dan ' . self::n((int) ($prediction['pending'] ?? 0))
                    . ' belum dianalisis; belum ada saran jumlah pesan pada snapshot terbaru. '
                    . 'Rekomendasi berikut memakai status stok aktual dan stok minimum.';
            } else {
                $lines[] = 'Prediksi AI belum tersedia untuk sparepart aktif; '
                    . 'rekomendasi berikut memakai status stok aktual dan stok minimum.';
            }
        }

        if ($unique !== []) {
            $lines[] = '';
            $lines[] = 'Berdasarkan status stok (habis/kritis/menipis):';
            foreach (array_slice($unique, 0, 20) as $row) {
                $lines[] = '- ' . $row['nama'] . ' (' . $row['kode'] . '): stok '
                    . self::qty((float) $row['stok']) . ' ' . $row['satuan']
                    . ', minimum ' . self::qty((float) $row['minimum']) . ' ' . $row['satuan']
                    . ' (' . self::statusLabel(Sparepart::stockStatusOf([
                        'stock' => $row['stok'],
                        'min_stock' => $row['minimum'],
                    ])) . ').';
            }
            if (count($unique) > 20) {
                $lines[] = '- dan ' . self::n(count($unique) - 20) . ' sparepart lainnya.';
            }
        }

        $lines[] = '';
        $lines[] = 'Permintaan reorder saat ini: ' . self::n($pending) . ' menunggu persetujuan, '
            . self::n($approved) . ' disetujui, ' . self::n($rejected) . ' ditolak.';
        $lines[] = 'Rekomendasi bersifat saran, bukan pesanan otomatis. '
            . 'Buat permintaan di menu Reorder dan tinjau hasilnya di menu Prediksi Stok.';
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $dash */
    private static function answerPurchaseOrders(string $q, array $dash): ?string
    {
        $po = $dash['po'];
        $wantsList = preg_match('/\b(mana|daftar|tampilkan|sebutkan|apa saja)\b/u', $q) === 1;

        $poNumber = self::extractPoNumber($q);
        if ($poNumber !== null) {
            return self::formatPurchaseOrderDetail($poNumber);
        }

        if (preg_match('/\b(jumlah|total|berapa|kuantitas|qty)\b/u', $q) === 1
            && preg_match('/\b(barang|item|qty|kuantitas)\b/u', $q) === 1
            && preg_match('/\b(diterima|penerimaan)\b/u', $q) === 1
        ) {
            $row = self::fetchOne(
                'SELECT COUNT(*) AS po_count,
                        COALESCE(SUM(quantity), 0) AS ordered_qty,
                        COALESCE(SUM(received_qty), 0) AS received_qty
                   FROM purchase_order_items'
            );
            return 'Jumlah barang pada PO: ' . self::qty((float) ($row['ordered_qty'] ?? 0))
                . ' unit dipesan dan ' . self::qty((float) ($row['received_qty'] ?? 0))
                . ' unit sudah diterima, dari ' . self::n((int) ($row['po_count'] ?? 0)) . ' baris item PO.';
        }

        if (preg_match('/\b(belum|lum|tidak)\b/u', $q) === 1 && preg_match('/\b(diterima|penerimaan)\b/u', $q) === 1) {
            $rows = self::fetchAll(
                "SELECT po.id, po.po_number, po.status, po.created_at, s.name AS supplier_name,
                        COALESCE(SUM(poi.quantity), 0) AS ordered_qty,
                        COALESCE(SUM(poi.received_qty), 0) AS received_qty
                   FROM purchase_orders po
                   JOIN suppliers s ON s.id = po.supplier_id
              LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
                  WHERE po.status NOT IN ('SELESAI_DITERIMA', 'DITOLAK', 'DIBATALKAN')
               GROUP BY po.id, po.po_number, po.status, po.created_at, s.name
               ORDER BY po.created_at DESC
                  LIMIT 20"
            );
            if ($rows === []) {
                return 'Tidak ada PO yang belum diterima. Semua PO aktif sudah selesai diterima.';
            }
            $lines = ['PO yang belum diterima lengkap (' . self::n(count($rows)) . ' PO):'];
            $number = 1;
            foreach ($rows as $row) {
                $lines[] = $number . '. ' . $row['po_number'] . ' · ' . $row['supplier_name']
                    . ' · status ' . self::poStatusLabel((string) $row['status'])
                    . ' · diterima ' . self::qty((float) $row['received_qty']) . '/'
                    . self::qty((float) $row['ordered_qty']);
                $items = self::fetchAll(
                    'SELECT poi.quantity, poi.received_qty, sp.code, sp.name, sp.unit
                       FROM purchase_order_items poi
                       JOIN spareparts sp ON sp.id = poi.sparepart_id
                      WHERE poi.po_id = ?
                   ORDER BY poi.id ASC',
                    [(int) $row['id']]
                );
                foreach ($items as $item) {
                    $ordered = (float) $item['quantity'];
                    $received = (float) $item['received_qty'];
                    $remaining = max(0, $ordered - $received);
                    $lines[] = '   - ' . $item['name'] . ' (' . $item['code'] . '): dipesan '
                        . self::qty($ordered) . ' ' . (string) $item['unit']
                        . ', diterima ' . self::qty($received) . ' ' . (string) $item['unit']
                        . ', sisa ' . self::qty($remaining) . ' ' . (string) $item['unit'];
                }
                $number++;
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(ditolak|reject)\b/u', $q) === 1) {
            return 'PO yang ditolak: ' . self::n((int) $po['ditolak']) . ' PO.';
        }

        if (preg_match('/\b(menunggu|pending|persetujuan)\b/u', $q) === 1) {
            return 'Jumlah Purchase Order (PO) yang masih menunggu persetujuan: '
                . self::n((int) $po['menunggu_persetujuan']) . ' PO.';
        }

        if (preg_match('/\b(diterima|penerimaan|selesai)\b/u', $q) === 1) {
            return 'PO yang sudah diterima: ' . self::n((int) $po['selesai_diterima'])
                . ' PO selesai diterima dan ' . self::n((int) $po['sebagian_diterima'])
                . ' PO diterima sebagian.';
        }

        if (preg_match('/\b(disetujui|approve)\b/u', $q) === 1) {
            return 'PO yang sudah disetujui: ' . self::n((int) $po['disetujui']) . ' PO.';
        }

        if (preg_match('/\b(dibatalkan|batal)\b/u', $q) === 1) {
            return 'PO yang dibatalkan: ' . self::n((int) $po['dibatalkan']) . ' PO.';
        }

        if ($wantsList) {
            $rows = self::fetchAll(
                'SELECT po.po_number, po.status, po.total_amount, po.created_at, s.name AS supplier_name,
                        COALESCE(SUM(poi.quantity), 0) AS ordered_qty,
                        COALESCE(SUM(poi.received_qty), 0) AS received_qty
                   FROM purchase_orders po
                   JOIN suppliers s ON s.id = po.supplier_id
              LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
               GROUP BY po.id, po.po_number, po.status, po.total_amount, po.created_at, s.name
               ORDER BY po.created_at DESC
                  LIMIT 20'
            );
            if ($rows === []) {
                return 'Belum ada Purchase Order (PO) yang tercatat.';
            }
            $lines = ['Daftar Purchase Order (' . self::n((int) $po['total']) . ' PO):'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['po_number'] . ' · ' . $row['supplier_name']
                    . ' · ' . self::poStatusLabel((string) $row['status'])
                    . ' · nilai ' . self::money((float) $row['total_amount'])
                    . ' · diterima ' . self::qty((float) $row['received_qty']) . '/'
                    . self::qty((float) $row['ordered_qty']);
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1) {
            return 'Total Purchase Order (PO) saat ini: ' . self::n((int) $po['total'])
                . ' PO, terdiri dari ' . self::n((int) $po['menunggu_persetujuan']) . ' menunggu persetujuan, '
                . self::n((int) $po['disetujui']) . ' disetujui, '
                . self::n((int) $po['sebagian_diterima']) . ' diterima sebagian, '
                . self::n((int) $po['selesai_diterima']) . ' selesai diterima, '
                . self::n((int) $po['ditolak']) . ' ditolak, dan '
                . self::n((int) $po['dibatalkan']) . ' dibatalkan.';
        }
        return null;
    }

    private static function extractPoNumber(string $q): ?string
    {
        if (preg_match('/\b((?:[A-Z0-9]+-)*PO-?[A-Z0-9][A-Z0-9\-\/]*)\b/i', $q, $m) === 1) {
            $candidate = strtoupper(rtrim($m[1], '.,;:'));
            return $candidate === 'PO' ? null : $candidate;
        }
        return null;
    }

    private static function formatPurchaseOrderDetail(string $poNumber): string
    {
        $po = self::fetchOne(
            'SELECT po.id, po.po_number, po.status, po.total_amount, po.created_at,
                    po.note, s.name AS supplier_name
               FROM purchase_orders po
               JOIN suppliers s ON s.id = po.supplier_id
              WHERE UPPER(po.po_number) = ?
              LIMIT 1',
            [strtoupper($poNumber)]
        );
        if ($po === []) {
            return 'Purchase Order (PO) dengan nomor ' . $poNumber . ' tidak ditemukan pada database.';
        }

        $items = self::fetchAll(
            'SELECT poi.quantity, poi.received_qty, poi.unit_price, sp.code, sp.name, sp.unit
               FROM purchase_order_items poi
               JOIN spareparts sp ON sp.id = poi.sparepart_id
              WHERE poi.po_id = ?
           ORDER BY poi.id ASC',
            [(int) $po['id']]
        );

        $lines = ['Rincian Purchase Order (PO) ' . $po['po_number'] . ':'];
        $lines[] = '- Tanggal: ' . self::formatDateId((string) ($po['created_at'] ?? ''));
        $lines[] = '- Supplier: ' . $po['supplier_name'];
        $lines[] = '- Status: ' . self::poStatusLabel((string) $po['status']);
        $lines[] = '- Nilai PO: ' . self::money((float) ($po['total_amount'] ?? 0));
        if (trim((string) ($po['note'] ?? '')) !== '') {
            $lines[] = '- Catatan: ' . $po['note'];
        }

        if ($items === []) {
            $lines[] = 'PO ini belum memiliki item barang.';
            return implode("\n", $lines);
        }

        $orderedTotal = 0.0;
        $receivedTotal = 0.0;
        $remainingTotal = 0.0;
        $lines[] = '';
        $lines[] = 'Daftar item:';
        foreach ($items as $i => $item) {
            $ordered = (float) $item['quantity'];
            $received = (float) $item['received_qty'];
            $remaining = max(0, $ordered - $received);
            $orderedTotal += $ordered;
            $receivedTotal += $received;
            $remainingTotal += $remaining;
            $lines[] = ($i + 1) . '. ' . $item['name'] . ' (' . $item['code'] . ')'
                . ' · dipesan ' . self::qty($ordered) . ' ' . (string) $item['unit']
                . ' · diterima ' . self::qty($received) . ' ' . (string) $item['unit']
                . ' · sisa ' . self::qty($remaining) . ' ' . (string) $item['unit'];
        }
        $lines[] = '';
        $lines[] = 'Total dipesan: ' . self::qty($orderedTotal) . ' unit. '
            . 'Total diterima: ' . self::qty($receivedTotal) . ' unit. '
            . 'Sisa belum diterima: ' . self::qty($remainingTotal) . ' unit.';

        return implode("\n", $lines);
    }

    private static function isUserQuestion(string $q): bool
    {
        if (preg_match(
            '/\b(pengguna|user|akun|role|peran|login|log masuk|masuk log|username|'
            . 'terakhir aktif|aktivitas terakhir|terakhir beraktivitas)\b/u',
            $q
        ) === 1) {
            return true;
        }

        // Pertanyaan pelaku aktivitas tanpa kata "pengguna", misalnya
        // "Siapa yang menerima barang?" atau "Siapa yang menyetujui PO?".
        if (preg_match('/\b(siapa|pelaku|petugas)\b/u', $q) !== 1) {
            return false;
        }

        $activityVerb = preg_match(
            '/\b(melakukan|mencatat|membuat|menyetujui|menerima|memproses|menginput|menyesuaikan|menyusun)\b/u',
            $q
        ) === 1;

        return $activityVerb && self::detectActivityModule($q) !== '';
    }

    /**
     * Lanjutan pertanyaan modul pengguna. Mewarisi maksud pertanyaan terakhir
     * (mis. login terakhir) saat pengguna menyebut nama pengguna lain.
     *
     * @param array<string,mixed> $ctx
     */
    private static function answerUserFollowUp(string $q, string $role, array $ctx): ?string
    {
        if ($role !== 'admin') {
            return null;
        }

        $rows = self::userRows();
        $matches = self::findUsersInQuestion($q, $rows);
        $user = count($matches) === 1 ? $matches[0] : null;

        if ($user === null && !empty($ctx['user_id'])) {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $ctx['user_id']) {
                    $user = $row;
                    break;
                }
            }
        }
        if ($user === null && count($matches) > 1) {
            return self::userClarification($matches);
        }

        $previous = (string) ($ctx['intent'] ?? 'list');
        $detected = self::detectUserIntent($q, $user !== null);

        if ($previous === 'activity' || preg_match('/\b(kapan aktivitas|aktivitas tersebut|aktivitas itu)\b/u', $q) === 1) {
            $synthetic = $q;
            $activity = (string) ($ctx['activity'] ?? '');
            $map = [
                'receipt' => ' barang masuk',
                'issue'   => ' barang keluar',
                'adjustment' => ' penyesuaian stok',
                'opname'  => ' stock opname',
                'po'      => ' purchase order',
            ];
            if ($activity !== '' && isset($map[$activity]) && self::detectActivityModule($q) === '') {
                $synthetic .= $map[$activity];
            }
            return self::composeUserAnswer($synthetic, 'activity', $user, $matches, $rows);
        }

        // "Bagaimana dengan Danu?" terdeteksi sebagai daftar umum; ulangi
        // maksud pertanyaan pengguna sebelumnya (mis. login terakhir).
        $repeatable = in_array($previous, [
            'last_login', 'last_active', 'last_transaction', 'failed_login',
            'login_history', 'detail', 'role', 'created',
        ], true);
        $intent = ($repeatable && $detected === 'list') ? $previous : $detected;

        return self::composeUserAnswer($q, $intent, $user, $matches, $rows);
    }

    private static function isFollowUpQuestion(string $q): bool
    {
        if (preg_match(
            '/\b(tersebut|tadi|sebelumnya|itu|masing-masing|nomornya|nomor dokumennya|'
            . 'jenis penggunaannya|penggunaannya|tanggal yang sama|hari yang sama|tanggal tersebut|'
            . 'dokumen (pertama|kedua|ketiga|keempat|kelima)|transaksi (pertama|kedua|ketiga|keempat|kelima)|'
            . 'siapa yang (melakukan|mencatat|memproses)|pengguna yang melakukan|'
            . 'bagaimana dengan)\b/u',
            $q
        ) === 1) {
            return true;
        }

        // "jika tersedia" hanya menandakan pertanyaan lanjutan bila mengacu
        // pada atribut transaksi (SPK/nomor polisi), bukan daftar stok.
        return preg_match('/\bjika tersedia\b/u', $q) === 1
            && preg_match('/\b(spk|nomor polisi|plat|nopol)\b/u', $q) === 1;
    }

    /**
     * Jawab pertanyaan lanjutan dari konteks transaksi sebelumnya.
     * Mengembalikan null bila bukan pertanyaan transaksi (biar diproses
     * cabang lain), atau pesan klarifikasi bila konteks tidak cukup.
     *
     * @param array<string,mixed>|null $context
     */
    private static function answerFollowUp(string $q, string $role, ?array $context): ?string
    {
        $ctx = is_array($context) ? $context : [];

        // Pertanyaan yang menyebut modul secara eksplisit (Penyesuaian Stok /
        // Stock Opname/perbandingan) ditangani cabang modul, bukan konteks
        // transaksi generik. Ini mencegah "kedua fitur tersebut" dianggap
        // lanjutan stock opname.
        if (self::detectStockFeatureIntent($q) !== '') {
            return null;
        }

        // Konteks modul pengguna (login, role, aktivitas) punya jalur sendiri
        // agar "Bagaimana dengan Danu?" melanjutkan maksud pertanyaan terakhir.
        if ((string) ($ctx['topic'] ?? '') === 'user') {
            $userFollow = self::answerUserFollowUp($q, $role, $ctx);
            if ($userFollow !== null) {
                return $userFollow;
            }
        }

        $docs = is_array($ctx['doc_numbers'] ?? null) ? array_values($ctx['doc_numbers']) : [];
        $hasDate = trim((string) ($ctx['date_from'] ?? '')) !== ''
            && trim((string) ($ctx['date_to'] ?? '')) !== '';

        // Pertanyaan lanjutan hanya relevan untuk transaksi.
        $transactionHint = preg_match(
            '/\b(transaksi|barang|masuk|keluar|dokumen|spk|polisi|plat|nomor|jenis penggunaan|supplier)\b/u',
            $q
        ) === 1;

        $hasContext = $hasDate || $docs !== [] || trim((string) ($ctx['module'] ?? '')) !== '';
        if (!$hasContext) {
            if (!$transactionHint) {
                return null;
            }
            $hasReference = preg_match(
                '/\b(tersebut|tadi|sebelumnya|tanggal tersebut|hari yang sama|tanggal yang sama|'
                . 'dokumen (pertama|kedua|ketiga|keempat|kelima)|nomornya|nomor dokumennya|masing-masing)\b/u',
                $q
            ) === 1;
            // Tanpa konteks dan tanpa rujukan eksplisit, biarkan pertanyaan
            // umum (mis. "Siapa yang melakukan transaksi?") diproses cabang
            // transaksi biasa agar perilaku lama tetap berjalan.
            return $hasReference ? self::ANSWER_CLARIFY : null;
        }

        $date = $hasDate
            ? [
                'type'  => (string) ($ctx['date_type'] ?? 'day'),
                'from'  => (string) $ctx['date_from'],
                'to'    => (string) $ctx['date_to'],
                'label' => (string) ($ctx['date_label'] ?? self::formatDateId((string) $ctx['date_from'])),
            ]
            : ['type' => 'none', 'from' => '', 'to' => '', 'label' => 'seluruh periode'];

        $docNumber = self::extractDocumentNumber($q);
        if ($docNumber === null
            && preg_match('/\b(dokumen|transaksi)\s+(pertama|kedua|ketiga|keempat|kelima)\b/u', $q, $m) === 1
        ) {
            $map = ['pertama' => 0, 'kedua' => 1, 'ketiga' => 2, 'keempat' => 3, 'kelima' => 4];
            $index = $map[$m[2]] ?? 0;
            $docNumber = $docs[$index] ?? null;
            if ($docNumber === null) {
                return 'Saya belum memiliki daftar dokumen yang cukup pada konteks percakapan ini. '
                    . 'Sebutkan nomor dokumen atau tanggal transaksi agar saya dapat menampilkan rinciannya.';
            }
        }

        // Bila konteks hanya memuat satu dokumen, rujukan "transaksi tersebut"
        // harus tetap ke dokumen itu, bukan melebar ke seluruh tanggal.
        if ($docNumber === null
            && count($docs) === 1
            && preg_match('/\b(tersebut|tadi|itu|dokumen ini|transaksi ini)\b/u', $q) === 1
        ) {
            $docNumber = (string) $docs[0];
        }

        // Pertanyaan khusus nomor dokumen (tanpa SPK/nomor polisi).
        if ($docNumber === null
            && preg_match('/\b(nomor dokumennya|nomornya|nomor dokumen|apa nomor)\b/u', $q) === 1
            && preg_match('/\b(spk|polisi|plat)\b/u', $q) !== 1
        ) {
            if ($docs === []) {
                return self::ANSWER_CLARIFY;
            }
            $lines = ['Nomor dokumen pada konteks transaksi sebelumnya:'];
            foreach ($docs as $i => $number) {
                $lines[] = ($i + 1) . '. ' . $number;
            }
            return implode("\n", $lines);
        }

        // Lengkapi kata modul agar deteksi intent rincian bekerja.
        $ctModule = (string) ($ctx['module'] ?? '');
        if (in_array($ctModule, ['adjustment', 'opname'], true)) {
            $synthetic = $q;
            if (self::detectStockFeatureIntent($q) === '') {
                $synthetic .= $ctModule === 'adjustment' ? ' penyesuaian stok' : ' stock opname';
            }
            return $ctModule === 'adjustment'
                ? self::answerStockAdjustments($synthetic, $role, 'semua', 'sejak data tersedia (seluruh periode)', $date, $docNumber)
                : self::answerStockOpnames($synthetic, $role, 'semua', 'sejak data tersedia (seluruh periode)', $date, $docNumber);
        }

        $synthetic = $q;
        if (preg_match('/\b(masuk|keluar|penerimaan|pengeluaran)\b/u', $q) !== 1) {
            $module = (string) ($ctx['module'] ?? '');
            if ($module === 'receipt') {
                $synthetic .= ' barang masuk';
            } elseif ($module === 'issue') {
                $synthetic .= ' barang keluar';
            } elseif ($module === 'both') {
                $synthetic .= ' barang masuk dan barang keluar';
            }
        }

        return self::answerTransactionDetail($synthetic, $role, $date, $docNumber);
    }

    /**
     * Lengkapi pertanyaan lanjutan yang hanya memakai kata ganti atau kalimat
     * pendek dengan topik dan periode dari konteks sesi.
     *
     * Contoh: setelah "Berapa total barang masuk?", pertanyaan
     * "Berapa total masuknya bulan ini?" menjadi "berapa total barang masuk
     * bulan ini", dan "Bagaimana rinciannya?" menjadi rincian barang masuk
     * pada periode yang sama.
     *
     * @param array<string,mixed>|null $context
     */
    private static function expandContextualQuestion(string $q, ?array $context): string
    {
        if ($context === null) {
            return $q;
        }
        $topic = (string) ($context['topic'] ?? '');
        if (!in_array($topic, ['transaction', 'stock', 'reorder'], true)) {
            return $q;
        }

        $module = (string) ($context['module'] ?? '');
        $moduleWord = match ($module) {
            'receipt' => 'barang masuk',
            'issue'   => 'barang keluar',
            'adjustment' => 'penyesuaian stok',
            'opname'  => 'stock opname',
            default   => '',
        };

        // Bentuk posesif/kata ganti → kata benda eksplisit.
        $mapped = preg_replace(
            ['/\bmasuknya\b/u', '/\bkeluarnya\b/u', '/\bpenerimaannya\b/u', '/\bpengeluarannya\b/u'],
            ['barang masuk', 'barang keluar', 'barang masuk', 'barang keluar'],
            $q
        ) ?? $q;
        $mapped = preg_replace('/\btransaksinya\b/u', 'transaksi', $mapped) ?? $mapped;

        // Pertanyaan yang hanya menyebut periode ("kalau bulan ini?").
        $periodOnly = preg_match(
            '/^(?:(?:kalau|sekarang|dan|untuk|pada|di|bagaimana(?:\s+dengan)?)\s+)*'
            . '(?:bulan ini|bulan lalu|tahun ini|tahun \d{4}|hari ini|minggu ini|pekan ini|'
            . 'bulan [a-z]+ \d{4})[\s?.!]*$/u',
            trim($mapped)
        ) === 1;

        // Rujukan ke pembahasan sebelumnya.
        $anaphora = preg_match(
            '/\b(rinciannya|rincian|barangnya|totalnya|jumlahnya|keseluruhan(?:nya)?|detail|'
            . 'lebih detail|masing-masing|barang apa saja|sebutkan barangnya|siapa yang mencatat|'
            . 'siapa yang melakukan|kapan transaksi terakhir|segera dipesan|mana yang harus)\b/u',
            $mapped
        ) === 1;

        // Pertanyaan jumlah yang masih berada pada topik transaksi, misalnya
        // "berapa total masuknya" atau "kalau bulan ini berapa?".
        $asksQuantity = preg_match('/\b(berapa|total|jumlah|banyak)\b/u', $mapped) === 1
            && preg_match(
                '/\b(stok|sparepart|kategori|supplier|pemasok|rak|lokasi|po|purchase order|pengguna|user|akun|'
                . 'dashboard|laporan|backup|audit)\b/u',
                $mapped
            ) !== 1;

        if (!$periodOnly && !$anaphora && !$asksQuantity) {
            return $q;
        }

        if ($topic === 'stock') {
            if (preg_match('/\bbarangnya\b/u', $mapped) === 1) {
                $mapped = preg_replace('/\bbarangnya\b/u', 'sparepart', $mapped) ?? $mapped;
            }
            if (preg_match('/\b(stok|sparepart|barang)\b/u', $mapped) !== 1) {
                $mapped .= ' stok';
            }
            $filter = (string) ($context['filter'] ?? '');
            if (in_array($filter, ['habis', 'kritis', 'menipis', 'aman'], true)
                && preg_match('/\b(habis|kritis|menipis|aman)\b/u', $mapped) !== 1
            ) {
                $mapped .= ' ' . $filter;
            }
            return trim($mapped);
        }

        if ($topic === 'reorder') {
            if (preg_match('/\b(sparepart|reorder|dipesan|dibeli)\b/u', $mapped) !== 1) {
                $mapped .= ' sparepart yang harus segera dipesan';
            }
            return trim($mapped);
        }

        // Topik transaksi: kembalikan objek yang sedang dibahas.
        if (preg_match('/\bbarangnya\b/u', $mapped) === 1) {
            $mapped = preg_replace('/\bbarangnya\b/u', $moduleWord !== '' ? $moduleWord : 'barang', $mapped) ?? $mapped;
        }
        if ($moduleWord !== ''
            && preg_match('/\b(barang masuk|barang keluar|penerimaan|pengeluaran|masuk|keluar|opname|penyesuaian)\b/u', $mapped) !== 1
        ) {
            if (preg_match('/\bbarang\b/u', $mapped) === 1) {
                $mapped = preg_replace('/\bbarang\b/u', $moduleWord, $mapped, 1) ?? $mapped;
            } else {
                $mapped .= ' ' . $moduleWord;
            }
        }

        // Pertahankan periode yang sedang dibahas bila pengguna tidak
        // menyebutkan periode baru.
        if (self::parseDateFilter($mapped)['type'] === 'none') {
            $hint = self::contextPeriodHint($context);
            if ($hint !== '') {
                $mapped .= ' ' . $hint;
            }
        }

        return trim($mapped);
    }

    /**
     * Ubah rentang tanggal konteks menjadi frasa yang dapat dibaca parser
     * tanggal, misalnya "tanggal 18 Oktober 2026" atau "bulan Oktober 2026".
     *
     * @param array<string,mixed> $context
     */
    private static function contextPeriodHint(array $context): string
    {
        $type = (string) ($context['date_type'] ?? 'none');
        $from = (string) ($context['date_from'] ?? '');
        $to = (string) ($context['date_to'] ?? '');
        if ($type === 'none' || $from === '') {
            return '';
        }
        if ($type === 'day') {
            return 'tanggal ' . self::formatDateId($from);
        }
        if ($type === 'month') {
            return 'bulan ' . self::monthName((int) substr($from, 5, 2)) . ' ' . substr($from, 0, 4);
        }
        if ($type === 'year') {
            return 'tahun ' . substr($from, 0, 4);
        }
        if ($type === 'range' && $to !== '') {
            return 'tanggal ' . self::formatDateId($from) . ' sampai ' . self::formatDateId($to);
        }
        return '';
    }

    /**
     * Filter tanggal eksplisit dari pertanyaan.
     *
     * @return array{type:string,from:string,to:string,label:string}
     */
    private static function parseDateFilter(string $q): array
    {
        $q = mb_strtolower($q);
        $today = date('Y-m-d');
        $none = ['type' => 'none', 'from' => '', 'to' => '', 'label' => ''];
        $months = self::MONTHS_ID;
        $monthPattern = implode('|', array_keys($months));

        if (preg_match('/\bhari ini\b/u', $q) === 1) {
            return ['type' => 'day', 'from' => $today, 'to' => $today, 'label' => self::formatDateId($today)];
        }
        if (preg_match('/\bkemarin\b/u', $q) === 1) {
            $y = date('Y-m-d', strtotime('-1 day'));
            return ['type' => 'day', 'from' => $y, 'to' => $y, 'label' => self::formatDateId($y)];
        }
        if (preg_match('/\b(minggu ini|pekan ini)\b/u', $q) === 1) {
            $from = date('Y-m-d', strtotime('monday this week'));
            return ['type' => 'range', 'from' => $from, 'to' => $today,
                'label' => self::formatDateId($from) . ' sampai ' . self::formatDateId($today)];
        }

        // Rentang lintas bulan: "1 Oktober 2026 sampai 18 November 2026".
        if (preg_match(
            '/\b(?:dari\s+)?(?:tanggal\s+)?(\d{1,2})\s+(' . $monthPattern . ')\s+(\d{4})\s*'
            . '(?:sampai|s\.?d\.?|hingga|s\/d|-|–)\s*(?:tanggal\s+)?(\d{1,2})\s+'
            . '(' . $monthPattern . ')\s+(\d{4})\b/u',
            $q,
            $m
        ) === 1) {
            $fromMonth = $months[$m[2]];
            $fromYear = (int) $m[3];
            $toMonth = $months[$m[5]];
            $toYear = (int) $m[6];
            $fromDay = (int) $m[1];
            $toDay = (int) $m[4];
            if (checkdate($fromMonth, $fromDay, $fromYear) && checkdate($toMonth, $toDay, $toYear)) {
                $from = sprintf('%04d-%02d-%02d', $fromYear, $fromMonth, $fromDay);
                $to = sprintf('%04d-%02d-%02d', $toYear, $toMonth, $toDay);
                if ($from <= $to) {
                    return ['type' => 'range', 'from' => $from, 'to' => $to,
                        'label' => self::formatDateId($from) . ' sampai ' . self::formatDateId($to)];
                }
            }
        }

        // Rentang tanggal dengan nama bulan: "1 sampai 18 Oktober 2026".
        if (preg_match(
            '/\b(?:dari\s+)?(?:tanggal\s+)?(\d{1,2})\s*(?:sampai|s\.?d\.?|hingga|s\/d|-|–)\s*(?:tanggal\s+)?(\d{1,2})\s+(' . $monthPattern . ')\s+(\d{4})\b/u',
            $q,
            $m
        ) === 1) {
            $month = $months[$m[3]];
            $year = (int) $m[4];
            $fromDay = (int) $m[1];
            $toDay = (int) $m[2];
            if (checkdate($month, $fromDay, $year) && checkdate($month, $toDay, $year) && $fromDay <= $toDay) {
                $from = sprintf('%04d-%02d-%02d', $year, $month, $fromDay);
                $to = sprintf('%04d-%02d-%02d', $year, $month, $toDay);
                return ['type' => 'range', 'from' => $from, 'to' => $to,
                    'label' => self::formatDateId($from) . ' sampai ' . self::formatDateId($to)];
            }
        }

        // Tanggal tunggal: "tanggal 18 Oktober 2026" / "18 Oktober 2026".
        if (preg_match('/\b(?:tanggal\s+)?(\d{1,2})\s+(' . $monthPattern . ')\s+(\d{4})\b/u', $q, $m) === 1) {
            $month = $months[$m[2]];
            $year = (int) $m[3];
            $day = (int) $m[1];
            if (checkdate($month, $day, $year)) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ['type' => 'day', 'from' => $date, 'to' => $date, 'label' => self::formatDateId($date)];
            }
        }

        // Format ISO dan dd-mm-yyyy.
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $q, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ['type' => 'day', 'from' => $date, 'to' => $date, 'label' => self::formatDateId($date)];
            }
        }
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/u', $q, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                return ['type' => 'day', 'from' => $date, 'to' => $date, 'label' => self::formatDateId($date)];
            }
        }

        // Bulan tertentu: "bulan Oktober 2026" / "Oktober 2026".
        if (preg_match('/\b(?:bulan\s+)?(' . $monthPattern . ')\s+(\d{4})\b/u', $q, $m) === 1) {
            $month = $months[$m[1]];
            $year = (int) $m[2];
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = date('Y-m-t', strtotime($from));
            return ['type' => 'month', 'from' => $from, 'to' => $to,
                'label' => self::monthName($month) . ' ' . $year];
        }
        if (preg_match('/\bbulan ini\b/u', $q) === 1) {
            $from = date('Y-m-01');
            $to = date('Y-m-t');
            return ['type' => 'month', 'from' => $from, 'to' => $to,
                'label' => self::monthName((int) date('n')) . ' ' . date('Y')];
        }

        // Tahun tertentu: "tahun 2026".
        if (preg_match('/\b(?:tahun\s+)(\d{4})\b/u', $q, $m) === 1) {
            $year = (int) $m[1];
            return ['type' => 'year', 'from' => $year . '-01-01', 'to' => $year . '-12-31',
                'label' => 'tahun ' . $year];
        }
        if (preg_match('/\btahun ini\b/u', $q) === 1) {
            $year = (int) date('Y');
            return ['type' => 'year', 'from' => $year . '-01-01', 'to' => $year . '-12-31',
                'label' => 'tahun ' . $year];
        }

        return $none;
    }

    private static function monthName(int $month): string
    {
        $names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return $names[$month] ?? (string) $month;
    }

    private static function formatDateId(string $date): string
    {
        $date = trim($date);
        if ($date === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return $date;
        }
        return (int) $m[3] . ' ' . self::monthName((int) $m[2]) . ' ' . $m[1];
    }

    private static function extractDocumentNumber(string $q): ?string
    {
        if (preg_match('/\b((?:GI|GR)[-\/][A-Z0-9\-\/]+)\b/i', $q, $m) === 1) {
            return strtoupper(rtrim($m[1], '.,;:'));
        }
        // Dokumen Penyesuaian Stok (ADJ-…) dan Stock Opname (SO-…).
        if (preg_match('/\b((?:ADJ|SO)[-\/][A-Z0-9\-\/]+)\b/i', $q, $m) === 1) {
            return strtoupper(rtrim($m[1], '.,;:'));
        }
        return null;
    }

    /**
     * Rincian transaksi barang masuk/keluar berdasarkan tanggal atau nomor dokumen.
     *
     * @param array{type:string,from:string,to:string,label:string} $date
     */
    private static function answerTransactionDetail(string $q, string $role, array $date, ?string $docNumber): ?string
    {
        $wantsIn = preg_match('/\b(masuk|penerimaan|receipt)\b/u', $q) === 1;
        $wantsOut = preg_match('/\b(keluar|pengeluaran|issue)\b/u', $q) === 1;
        if ($docNumber !== null) {
            if (str_starts_with($docNumber, 'GR')) {
                $wantsIn = true;
            } elseif (str_starts_with($docNumber, 'GI')) {
                $wantsOut = true;
            }
        }
        $both = !$wantsIn && !$wantsOut;

        $from = $date['type'] === 'none' ? null : $date['from'];
        $to = $date['type'] === 'none' ? null : $date['to'];
        $label = $date['type'] === 'none'
            ? ($docNumber !== null ? 'nomor dokumen ' . $docNumber : 'seluruh periode')
            : $date['label'];

        $withUser = $role === 'admin';
        $issues = ($wantsOut || $both) ? self::issueDocuments($from, $to, $docNumber, $withUser) : [];
        $receipts = ($wantsIn || $both) ? self::receiptDocuments($from, $to, $docNumber, $withUser) : [];

        // Simpan konteks transaksi terakhir agar pertanyaan lanjutan
        // ("transaksi tersebut", "dokumen pertama") mengarah ke data yang sama.
        $allDocs = array_merge($issues, $receipts);
        $ctxType = $date['type'];
        $ctxFrom = $date['from'];
        $ctxTo = $date['to'];
        $ctxLabel = $date['type'] === 'none'
            ? ($docNumber !== null ? 'nomor dokumen ' . $docNumber : 'seluruh periode')
            : $date['label'];
        if ($ctxType === 'none' && $allDocs !== []) {
            $dates = array_values(array_unique(array_map(
                static fn (array $doc): string => (string) ($doc['doc_date'] ?? ''),
                $allDocs
            )));
            if (count($dates) === 1 && $dates[0] !== '') {
                $ctxType = 'day';
                $ctxFrom = $dates[0];
                $ctxTo = $dates[0];
                $ctxLabel = self::formatDateId($dates[0]);
            }
        }
        self::$lastContext = [
            'topic'       => 'transaction',
            'module'      => $both ? 'both' : ($wantsIn ? 'receipt' : 'issue'),
            'date_type'   => $ctxType,
            'date_from'   => $ctxFrom,
            'date_to'     => $ctxTo,
            'date_label'  => $ctxLabel,
            'doc_numbers' => array_values(array_map(
                static fn (array $doc): string => (string) $doc['number'],
                $allDocs
            )),
        ];

        if ($issues === [] && $receipts === []) {
            if ($both) {
                return 'Tidak ada transaksi barang masuk maupun barang keluar pada ' . $label . '.';
            }
            return $wantsIn
                ? 'Tidak ada transaksi barang masuk pada ' . $label . '.'
                : 'Tidak ada transaksi barang keluar pada ' . $label . '.';
        }

        $asksUser = preg_match('/\b(siapa|oleh siapa|pengguna|petugas|dicatat oleh)\b/u', $q) === 1;
        $asksDoc = preg_match('/\b(nomor dokumen|no dokumen|nomor transaksi|kode dokumen|nomor nya|nomornya)\b/u', $q) === 1;
        $asksExtra = preg_match('/\b(spk|nomor polisi|plat|nopol|jenis penggunaan|penggunaan(nya)?|keterangan|note)\b/u', $q) === 1;
        $asksCount = preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1;
        $asksFullDetail = preg_match('/\b(rincian|detail|tampilkan|daftar|seluruh|apa saja|sebutkan)\b/u', $q) === 1;

        if ($asksUser) {
            $lines = ['Pengguna yang melakukan transaksi pada ' . $label . ':'];
            $seen = [];
            foreach (array_merge($issues, $receipts) as $doc) {
                $user = (string) ($doc['user_name'] ?? '');
                $key = $doc['number'] . '|' . $user;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $lines[] = '- ' . $doc['number'] . ': ' . ($withUser && $user !== '' ? $user : 'nama pengguna hanya tersedia untuk Admin');
            }
            return implode("\n", $lines);
        }

        if ($asksDoc && !$asksFullDetail) {
            $lines = ['Nomor dokumen transaksi pada ' . $label . ':'];
            foreach (array_merge($issues, $receipts) as $doc) {
                $lines[] = '- ' . $doc['number'] . ' (' . self::formatDateId((string) $doc['doc_date']) . ')';
            }
            return implode("\n", $lines);
        }

        if ($asksExtra && !$asksCount && !$asksFullDetail) {
            $lines = ['Keterangan tambahan transaksi pada ' . $label . ':'];
            foreach ($issues as $doc) {
                $lines[] = '- ' . $doc['number'] . ' · jenis penggunaan: ' . self::usageLabel((string) $doc['usage_type'])
                    . ' · SPK: ' . (trim((string) ($doc['spk_number'] ?? '')) !== '' ? $doc['spk_number'] : '-')
                    . ' · nomor polisi: ' . (trim((string) ($doc['plate_number'] ?? '')) !== '' ? $doc['plate_number'] : '-')
                    . (trim((string) ($doc['note'] ?? '')) !== '' ? ' · keterangan: ' . $doc['note'] : '');
            }
            foreach ($receipts as $doc) {
                $lines[] = '- ' . $doc['number'] . ' · supplier: ' . $doc['supplier_name']
                    . ' · PO: ' . (trim((string) ($doc['po_number'] ?? '')) !== '' ? $doc['po_number'] : 'tanpa PO')
                    . (trim((string) ($doc['note'] ?? '')) !== '' ? ' · keterangan: ' . $doc['note'] : '');
            }
            return implode("\n", $lines);
        }

        // Rekap jumlah per barang ("berapa jumlah masing-masing?").
        if (preg_match('/\b(masing-masing|setiap|tiap|per barang|per item)\b/u', $q) === 1) {
            return self::formatTransactionItemTotals($issues, $receipts, $label, $both);
        }

        if ($asksCount && !$asksFullDetail) {
            return self::summarizeTransactionDocs($issues, $receipts, $label, $both);
        }

        return self::formatTransactionDocs($issues, $receipts, $label, $both, $withUser);
    }

    /**
     * Rekap jumlah per sparepart untuk periode/modul yang sedang dibahas.
     *
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>> $receipts
     */
    private static function formatTransactionItemTotals(array $issues, array $receipts, string $label, bool $both): string
    {
        $lines = [];
        $number = 1;

        foreach ([['dokumen' => $issues, 'judul' => 'Barang keluar'],
                  ['dokumen' => $receipts, 'judul' => 'Barang masuk']] as $group) {
            if ($group['dokumen'] === []) {
                continue;
            }
            $totals = [];
            $grand = 0.0;
            foreach ($group['dokumen'] as $doc) {
                foreach ($doc['items'] as $item) {
                    $code = (string) ($item['code'] ?? '');
                    if (!isset($totals[$code])) {
                        $totals[$code] = [
                            'name' => (string) ($item['name'] ?? ''),
                            'unit' => (string) ($item['unit'] ?? ''),
                            'qty'  => 0.0,
                        ];
                    }
                    $totals[$code]['qty'] += (float) ($item['quantity'] ?? 0);
                    $grand += (float) ($item['quantity'] ?? 0);
                }
            }
            if ($totals === []) {
                continue;
            }
            $lines[] = $group['judul'] . ' pada ' . $label . ' — jumlah per barang:';
            foreach ($totals as $code => $item) {
                $lines[] = $number . '. ' . $item['name'] . ' (' . $code . '): '
                    . self::qty($item['qty']) . ' ' . $item['unit'];
                $number++;
            }
            $lines[] = 'Total keseluruhan ' . strtolower($group['judul']) . ': ' . self::qty($grand) . ' unit.';
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>> $receipts
     */
    private static function summarizeTransactionDocs(array $issues, array $receipts, string $label, bool $both): string
    {
        $parts = [];
        if ($issues !== []) {
            $qty = 0.0;
            $items = 0;
            foreach ($issues as $doc) {
                foreach ($doc['items'] as $item) {
                    $qty += (float) $item['quantity'];
                    $items++;
                }
            }
            $parts[] = count($issues) . ' dokumen barang keluar, ' . self::n($items)
                . ' baris item, total ' . self::qty($qty) . ' unit';
        }
        if ($receipts !== []) {
            $qty = 0.0;
            $items = 0;
            foreach ($receipts as $doc) {
                foreach ($doc['items'] as $item) {
                    $qty += (float) $item['quantity'];
                    $items++;
                }
            }
            $parts[] = count($receipts) . ' dokumen barang masuk, ' . self::n($items)
                . ' baris item, total ' . self::qty($qty) . ' unit';
        }

        return 'Pada ' . $label . ' terdapat ' . implode(' dan ', $parts) . '.';
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>> $receipts
     */
    private static function formatTransactionDocs(array $issues, array $receipts, string $label, bool $both, bool $withUser): string
    {
        $lines = [];
        if ($both) {
            $lines[] = 'Rincian transaksi pada ' . $label . ':';
        }

        $number = 1;
        if ($issues !== []) {
            $totalQty = 0.0;
            foreach ($issues as $doc) {
                foreach ($doc['items'] as $item) {
                    $totalQty += (float) $item['quantity'];
                }
            }
            $lines[] = 'Barang keluar pada ' . $label . ': ' . count($issues) . ' dokumen, total ' . self::qty($totalQty) . ' unit.';
            $lines[] = '';
            $lines[] = 'Rincian transaksi:';
            foreach ($issues as $doc) {
                $lines[] = $number . '. Dokumen ' . $doc['number'];
                $lines[] = '   - Tanggal: ' . self::formatDateId((string) $doc['doc_date']);
                foreach ($doc['items'] as $item) {
                    $lines[] = '   - Sparepart: ' . $item['name'];
                    $lines[] = '   - Kode: ' . $item['code'];
                    $lines[] = '   - Jumlah: ' . self::qty((float) $item['quantity']) . ' ' . (string) $item['unit'];
                }
                $lines[] = '   - Jenis penggunaan: ' . self::usageLabel((string) $doc['usage_type']);
                if (trim((string) ($doc['spk_number'] ?? '')) !== '') {
                    $lines[] = '   - Nomor SPK: ' . $doc['spk_number'];
                }
                if (trim((string) ($doc['plate_number'] ?? '')) !== '') {
                    $lines[] = '   - Nomor polisi: ' . $doc['plate_number'];
                }
                if ($withUser && trim((string) ($doc['user_name'] ?? '')) !== '') {
                    $lines[] = '   - Dicatat oleh: ' . $doc['user_name'];
                }
                if (trim((string) ($doc['note'] ?? '')) !== '') {
                    $lines[] = '   - Keterangan: ' . $doc['note'];
                }
                $lines[] = '';
                $number++;
            }
            $lines[] = 'Total pengeluaran: ' . self::qty($totalQty) . ' unit.';
        }

        if ($receipts !== []) {
            $totalQty = 0.0;
            foreach ($receipts as $doc) {
                foreach ($doc['items'] as $item) {
                    $totalQty += (float) $item['quantity'];
                }
            }
            if ($issues !== []) {
                $lines[] = '';
            }
            $lines[] = 'Barang masuk pada ' . $label . ': ' . count($receipts) . ' dokumen, total ' . self::qty($totalQty) . ' unit.';
            $lines[] = '';
            $lines[] = 'Rincian transaksi:';
            foreach ($receipts as $doc) {
                $lines[] = $number . '. Dokumen ' . $doc['number'];
                $lines[] = '   - Tanggal: ' . self::formatDateId((string) $doc['doc_date']);
                $lines[] = '   - Supplier: ' . $doc['supplier_name'];
                if (trim((string) ($doc['po_number'] ?? '')) !== '') {
                    $lines[] = '   - Nomor PO: ' . $doc['po_number'];
                }
                foreach ($doc['items'] as $item) {
                    $lines[] = '   - Sparepart: ' . $item['name'];
                    $lines[] = '   - Kode: ' . $item['code'];
                    $lines[] = '   - Jumlah diterima: ' . self::qty((float) $item['quantity']) . ' ' . (string) $item['unit'];
                }
                if ($withUser && trim((string) ($doc['user_name'] ?? '')) !== '') {
                    $lines[] = '   - Diproses oleh: ' . $doc['user_name'];
                }
                if (trim((string) ($doc['note'] ?? '')) !== '') {
                    $lines[] = '   - Keterangan: ' . $doc['note'];
                }
                $lines[] = '';
                $number++;
            }
            $lines[] = 'Total penerimaan: ' . self::qty($totalQty) . ' unit.';
        }

        return trim(implode("\n", $lines));
    }

    private static function usageLabel(string $usage): string
    {
        return match (mb_strtolower($usage)) {
            'service' => 'service',
            'penjualan' => 'penjualan',
            'lainnya' => 'lainnya',
            default => $usage !== '' ? $usage : '-',
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function issueDocuments(?string $from, ?string $to, ?string $number, bool $withUser): array
    {
        $userSelect = $withUser ? 'u.full_name AS user_name' : 'NULL AS user_name';
        $sql = 'SELECT gi.id, gi.issue_number AS number, gi.issue_date AS doc_date, gi.usage_type,
                       gi.spk_number, gi.plate_number, gi.note, ' . $userSelect . ',
                       gii.quantity, sp.code, sp.name, sp.unit
                  FROM goods_issues gi
                  JOIN goods_issue_items gii ON gii.issue_id = gi.id
                  JOIN spareparts sp ON sp.id = gii.sparepart_id
             LEFT JOIN users u ON u.id = gi.recorded_by
                 WHERE 1 = 1';
        $params = [];
        if ($number !== null) {
            $sql .= ' AND UPPER(gi.issue_number) = ?';
            $params[] = strtoupper($number);
        } elseif ($from !== null && $to !== null) {
            $sql .= ' AND gi.issue_date BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }
        $sql .= ' ORDER BY gi.issue_date DESC, gi.issue_number DESC, gii.id ASC';

        return self::groupTransactionRows(self::fetchAll($sql, $params));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function receiptDocuments(?string $from, ?string $to, ?string $number, bool $withUser): array
    {
        $userSelect = $withUser ? 'u.full_name AS user_name' : 'NULL AS user_name';
        $sql = 'SELECT gr.id, gr.receipt_number AS number, gr.receipt_date AS doc_date,
                       s.name AS supplier_name, po.po_number, gr.note, ' . $userSelect . ',
                       gri.quantity, sp.code, sp.name, sp.unit
                  FROM goods_receipts gr
                  JOIN goods_receipt_items gri ON gri.receipt_id = gr.id
                  JOIN spareparts sp ON sp.id = gri.sparepart_id
                  JOIN suppliers s ON s.id = gr.supplier_id
             LEFT JOIN purchase_orders po ON po.id = gr.purchase_order_id
             LEFT JOIN users u ON u.id = gr.recorded_by
                 WHERE 1 = 1';
        $params = [];
        if ($number !== null) {
            $sql .= ' AND UPPER(gr.receipt_number) = ?';
            $params[] = strtoupper($number);
        } elseif ($from !== null && $to !== null) {
            $sql .= ' AND gr.receipt_date BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }
        $sql .= ' ORDER BY gr.receipt_date DESC, gr.receipt_number DESC, gri.id ASC';

        return self::groupTransactionRows(self::fetchAll($sql, $params));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function groupTransactionRows(array $rows): array
    {
        $docs = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($docs[$id])) {
                $docs[$id] = $row;
                $docs[$id]['items'] = [];
            }
            $docs[$id]['items'][] = [
                'quantity' => (float) ($row['quantity'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'unit' => (string) ($row['unit'] ?? ''),
            ];
        }
        return array_values($docs);
    }

    /** @param array<string,mixed> $dash */
    private static function answerTransactions(string $q, string $role, string $period, string $periodLabel, array $dash): ?string
    {
        $wantsIn = preg_match('/\b(masuk|penerimaan|receipt)\b/u', $q) === 1;
        $wantsOut = preg_match('/\b(keluar|pengeluaran|issue)\b/u', $q) === 1;

        // Rincian transaksi: tanggal tertentu, nomor dokumen, atau permintaan
        // daftar/detail (bukan sekadar jumlah).
        $dateFilter = self::parseDateFilter($q);
        $docNumber = self::extractDocumentNumber($q);
        $asksCount = preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1;
        $asksDetail = preg_match('/\b(apa|apa saja|rincian|detail|tampilkan|daftar|sebutkan|siapa|bagaimana|masing-masing|setiap)\b/u', $q) === 1;
        $isTrend = preg_match('/\b(terbaru|terakhir|riwayat berdasarkan periode)\b/u', $q) === 1;

        // Simpan topik + periode untuk pertanyaan lanjutan. Rincian dokumen
        // dapat menimpanya dengan daftar nomor dokumen yang lebih spesifik.
        self::rememberTransactionContext($q, $dateFilter, $period, $periodLabel, $wantsIn, $wantsOut);
        // Periode relatif tanpa tanggal eksplisit tetap memakai ringkasan
        // periode bila pengguna hanya meminta jumlah/total (bukan rincian).
        $relativePeriod = preg_match('/\b(hari ini|bulan ini|minggu ini|tahun ini)\b/u', $q) === 1;
        $dateNeedsDetail = $dateFilter['type'] !== 'none'
            && (!$relativePeriod || !$asksCount || $asksDetail);
        if (!$isTrend
            && ($dateNeedsDetail
                || $docNumber !== null
                || ($asksDetail && !$asksCount && ($wantsIn || $wantsOut)))
        ) {
            return self::answerTransactionDetail($q, $role, $dateFilter, $docNumber);
        }

        // Pelaku transaksi (nama pengguna hanya untuk Admin).
        if (preg_match('/\b(siapa|oleh siapa|pelaku|petugas)\b/u', $q) === 1) {
            if ($role !== 'admin') {
                return 'Maaf, data pengguna yang melakukan transaksi hanya tersedia untuk Admin.';
            }
            [$from, $to] = self::range($period);
            $sources = [];
            $params = [];
            if ($wantsIn || (!$wantsIn && !$wantsOut)) {
                $sources[] = 'SELECT recorded_by AS uid FROM goods_receipts WHERE receipt_date BETWEEN ? AND ?';
                array_push($params, $from, $to);
            }
            if ($wantsOut || (!$wantsIn && !$wantsOut)) {
                $sources[] = 'SELECT recorded_by AS uid FROM goods_issues WHERE issue_date BETWEEN ? AND ?';
                array_push($params, $from, $to);
            }
            if (!$wantsIn && !$wantsOut) {
                $sources[] = 'SELECT started_by AS uid FROM stock_opnames
                               WHERE COALESCE(opname_date, DATE(started_at)) BETWEEN ? AND ?';
                array_push($params, $from, $to);
            }
            $rows = self::fetchAll(
                'SELECT u.full_name, u.username, COUNT(*) AS total
                   FROM (' . implode(' UNION ALL ', $sources) . ') t
                   JOIN users u ON u.id = t.uid
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC',
                $params
            );
            if ($rows === []) {
                return 'Belum ada pengguna yang mencatat transaksi pada ' . $periodLabel . '.';
            }
            $topicLabel = $wantsIn && !$wantsOut
                ? 'barang masuk '
                : ($wantsOut && !$wantsIn ? 'barang keluar ' : '');
            $lines = ['Pengguna yang melakukan transaksi ' . $topicLabel . $periodLabel . ':'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . '): '
                    . self::n((int) $row['total']) . ' dokumen transaksi';
            }
            return implode("\n", $lines);
        }

        // Sparepart paling sering keluar.
        if (preg_match('/\b(paling sering|paling banyak|terbanyak|tertinggi)\b/u', $q) === 1 && $wantsOut) {
            [$from, $to] = self::range($period);
            $rows = self::fetchAll(
                'SELECT sp.name, sp.code, sp.unit, SUM(gii.quantity) AS total
                   FROM goods_issue_items gii
                   JOIN goods_issues gi ON gi.id = gii.issue_id
                   JOIN spareparts sp ON sp.id = gii.sparepart_id
                  WHERE gi.issue_date BETWEEN ? AND ?
               GROUP BY sp.id, sp.name, sp.code, sp.unit
               ORDER BY total DESC
                  LIMIT 10',
                [$from, $to]
            );
            if ($rows === []) {
                return 'Belum ada barang keluar pada ' . $periodLabel . '.';
            }
            $lines = ['Sparepart yang paling sering keluar ' . $periodLabel . ':'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['name'] . ' (' . $row['code'] . '): '
                    . self::qty((float) $row['total']) . ' ' . (string) $row['unit'];
            }
            return implode("\n", $lines);
        }

        // Jumlah transaksi berdasarkan jenis.
        if (preg_match('/\b(jenis transaksi|tipe transaksi|macam transaksi|berdasarkan jenis)\b/u', $q) === 1) {
            $counts = self::countsForPeriod($period);
            return 'Jumlah transaksi berdasarkan jenis ' . $periodLabel . ': '
                . self::n((int) $counts['receipts']) . ' dokumen barang masuk, '
                . self::n((int) $counts['issues']) . ' dokumen barang keluar, '
                . self::n((int) $counts['opnames']) . ' dokumen stock opname, dan '
                . self::n((int) $counts['movements']) . ' baris pergerakan stok (ledger). '
                . 'Jumlah dokumen berbeda dari jumlah item dan total kuantitas.';
        }

        if (preg_match('/\b(terbaru|terakhir)\b/u', $q) === 1) {
            if ($wantsIn && !$wantsOut) {
                $rows = self::fetchAll(
                    "SELECT 'Penerimaan' AS kind, gr.receipt_number AS number, gr.receipt_date AS doc_date,
                            s.name AS party,
                            (SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items WHERE receipt_id = gr.id) AS qty
                       FROM goods_receipts gr
                       JOIN suppliers s ON s.id = gr.supplier_id
                   ORDER BY gr.receipt_date DESC, gr.receipt_number DESC
                      LIMIT 5"
                );
            } elseif ($wantsOut && !$wantsIn) {
                $rows = self::fetchAll(
                    "SELECT 'Pengeluaran' AS kind, gi.issue_number AS number, gi.issue_date AS doc_date,
                            COALESCE(NULLIF(gi.plate_number, ''), NULLIF(gi.spk_number, ''), gi.usage_type) AS party,
                            (SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items WHERE issue_id = gi.id) AS qty
                       FROM goods_issues gi
                   ORDER BY gi.issue_date DESC, gi.issue_number DESC
                      LIMIT 5"
                );
            } else {
                $rows = Analytics::latestTransactions(5);
            }

            $topic = $wantsIn && !$wantsOut
                ? 'barang masuk'
                : ($wantsOut && !$wantsIn ? 'barang keluar' : 'penerimaan atau pengeluaran');
            if ($rows === []) {
                return 'Belum ada transaksi ' . $topic . ' yang tercatat.';
            }
            $lines = ['Transaksi ' . $topic . ' terbaru yang tercatat:'];
            foreach ($rows as $row) {
                $lines[] = '- ' . ucfirst((string) $row['kind']) . ' ' . $row['number']
                    . ' tanggal ' . self::formatDateId((string) $row['doc_date'])
                    . ', qty ' . self::qty((float) $row['qty'])
                    . ', pihak ' . $row['party'];
            }
            return implode("\n", $lines);
        }

        if (preg_match('/\b(riwayat|periode)\b/u', $q) === 1) {
            [$from, $to] = self::range($period);
            $rows = self::fetchAll(
                "SELECT 'Penerimaan' AS kind, gr.receipt_number AS number, gr.receipt_date AS doc_date,
                        s.name AS party,
                        (SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items WHERE receipt_id = gr.id) AS qty
                   FROM goods_receipts gr
                   JOIN suppliers s ON s.id = gr.supplier_id
                  WHERE gr.receipt_date BETWEEN ? AND ?
                  UNION ALL
                 SELECT 'Pengeluaran' AS kind, gi.issue_number AS number, gi.issue_date AS doc_date,
                        COALESCE(NULLIF(gi.plate_number, ''), NULLIF(gi.spk_number, ''), gi.usage_type) AS party,
                        (SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items WHERE issue_id = gi.id) AS qty
                   FROM goods_issues gi
                  WHERE gi.issue_date BETWEEN ? AND ?
               ORDER BY doc_date DESC
                  LIMIT 15",
                [$from, $to, $from, $to]
            );
            if ($rows === []) {
                return 'Belum ada riwayat transaksi pada ' . $periodLabel . '.';
            }
            $lines = ['Riwayat transaksi ' . $periodLabel . ' (' . self::n(count($rows)) . ' terbaru):'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['doc_date'] . ' · ' . $row['kind'] . ' ' . $row['number']
                    . ' · ' . $row['party'] . ' · qty ' . self::qty((float) $row['qty']);
            }
            return implode("\n", $lines);
        }

        // Catatan: pertanyaan Penyesuaian Stok, Stock Opname, dan istilah
        // "koreksi" sudah ditangani lebih awal oleh detectStockFeatureIntent()
        // dengan sumber data masing-masing. Jangan menyatukannya di sini.

        if ($period === 'bulan') {
            $month = $dash['transaksi']['bulan_ini'];
            $lines = ['Ringkasan transaksi bulan ini (' . $month['periode']['dari'] . ' s.d. ' . $month['periode']['sampai'] . '):'];
            if ($wantsIn || (!$wantsIn && !$wantsOut)) {
                $lines[] = '- Barang masuk (pembelian): ' . self::n((int) $month['pembelian_dokumen'])
                    . ' dokumen, qty ' . self::qty((float) $month['pembelian_qty'])
                    . ', nilai ' . self::money((float) $month['pembelian_nilai']);
            }
            if ($wantsOut || (!$wantsIn && !$wantsOut)) {
                $lines[] = '- Barang keluar: ' . self::n((int) $month['pengeluaran_dokumen'])
                    . ' dokumen, qty ' . self::qty((float) $month['pengeluaran_qty']);
            }
            if (!$wantsIn && !$wantsOut) {
                $lines[] = '- Stock opname: ' . self::n((int) $month['opname_total']) . ' dokumen ('
                    . self::n((int) $month['opname_terkonfirmasi']) . ' terkonfirmasi).';
            }
            return implode("\n", $lines);
        }

        if ($period === 'hari') {
            $today = $dash['transaksi']['hari_ini'];
            if ($wantsIn && !$wantsOut) {
                return 'Barang masuk hari ini (' . $today['tanggal'] . '): ' . self::n((int) $today['dokumen_penerimaan'])
                    . ' dokumen, qty ' . self::qty((float) $today['qty_masuk']) . '.';
            }
            if ($wantsOut && !$wantsIn) {
                return 'Barang keluar hari ini (' . $today['tanggal'] . '): ' . self::n((int) $today['dokumen_pengeluaran'])
                    . ' dokumen, qty ' . self::qty((float) $today['qty_keluar']) . '.';
            }
            if (preg_match('/\b(total transaksi|jumlah transaksi|berapa transaksi)\b/u', $q) === 1 || ($wantsIn && $wantsOut)) {
                return 'Total transaksi hari ini (' . $today['tanggal'] . '): ' . self::n((int) $today['total_transaksi'])
                    . ' transaksi, terdiri dari ' . self::n((int) $today['dokumen_penerimaan'])
                    . ' penerimaan dan ' . self::n((int) $today['dokumen_pengeluaran']) . ' pengeluaran.';
            }
        }

        $counts = self::countsForPeriod($period);
        if ($wantsIn && !$wantsOut) {
            return 'Jumlah transaksi barang masuk ' . $periodLabel . ': ' . self::n((int) $counts['receipts'])
                . ' dokumen penerimaan, ' . self::n((int) $counts['receipt_items']) . ' baris item, total qty '
                . self::qty((float) $counts['receipt_qty']) . ' unit.';
        }
        if ($wantsOut && !$wantsIn) {
            return 'Jumlah transaksi barang keluar ' . $periodLabel . ': ' . self::n((int) $counts['issues'])
                . ' dokumen pengeluaran, ' . self::n((int) $counts['issue_items']) . ' baris item, total qty '
                . self::qty((float) $counts['issue_qty']) . ' unit.';
        }

        if (preg_match('/\b(total transaksi|jumlah transaksi|berapa transaksi)\b/u', $q) === 1 || ($wantsIn && $wantsOut)) {
            return 'Total transaksi ' . $periodLabel . ': '
                . self::n((int) $counts['receipts'] + (int) $counts['issues'])
                . ' transaksi, terdiri dari ' . self::n((int) $counts['receipts'])
                . ' dokumen barang masuk dan ' . self::n((int) $counts['issues']) . ' dokumen barang keluar. '
                . 'Jumlah dokumen berbeda dari jumlah item dan total kuantitas.';
        }

        return null;
    }

    /**
     * Bedakan modul Penyesuaian Stok dan Stock Opname.
     *
     * Sumber data keduanya berbeda:
     * - Penyesuaian Stok : stock_movements.movement_type = 'PENYESUAIAN'
     *                      (dokumen ADJ, reference_type STOCK_ADJUSTMENT)
     * - Stock Opname     : stock_opnames + stock_opname_items (dokumen SO)
     *
     * @return string adjustment|opname|compare|ambiguous|''
     */
    private static function detectStockFeatureIntent(string $q): string
    {
        $hasAdjustment = preg_match(
            '/\b(penyesuaian(\s+stok)?|stock\s*adjustment|adjustment|'
            . 'tambah\s+stok\s+manual|tambah\s+manual|kurangi\s+stok\s+manual|kurang\s+stok\s+manual|'
            . 'riwayat\s+penyesuaian|penambahan\s+stok|pengurangan\s+stok|menyesuaikan\s+stok|'
            . 'adj-\d+)\b/u',
            $q
        ) === 1;

        $hasOpname = preg_match(
            '/\b(stock\s*opname|stok\s*opname|opname|pemeriksaan\s+stok\s+fisik|'
            . 'selisih\s+stok\s+fisik|pencocokan\s+stok|perhitungan\s+fisik|hitung\s+fisik|'
            . 'so-\d+)\b/u',
            $q
        ) === 1;

        $asksComparison = preg_match(
            '/\b(perbedaan|perbandingan|bedanya|apa\s+beda|apakah\s+sama|sama\s+dengan|dibedakan)\b/u',
            $q
        ) === 1;
        $bothFeatures = ($hasAdjustment && $hasOpname)
            || preg_match('/\b(kedua\s+(fitur|modul|hal)|antara\s+kedua)\b/u', $q) === 1;

        if ($asksComparison && $bothFeatures) {
            return 'compare';
        }
        if ($hasAdjustment && $hasOpname) {
            // "penyesuaian opname" atau menyebut dua modul sekaligus tanpa
            // meminta perbandingan: jangan menebak modul.
            return 'ambiguous';
        }
        if ($hasAdjustment) {
            return 'adjustment';
        }
        if ($hasOpname) {
            return 'opname';
        }
        if (preg_match('/\b(koreksi)\b/u', $q) === 1) {
            // "koreksi" bisa berarti koreksi transaksi, penyesuaian manual,
            // atau hasil stock opname. Minta klarifikasi singkat.
            return 'ambiguous';
        }
        return '';
    }

    private static function answerStockFeatureClarification(): string
    {
        return 'Saya perlu memastikan modul yang Anda maksud karena sumber datanya berbeda. '
            . 'Penyesuaian Stok (menu /transaksi/penyesuaian) mencatat penambahan/pengurangan stok '
            . 'manual dengan dokumen ADJ. Stock Opname (menu /transaksi/opname) mencatat pemeriksaan '
            . 'stok fisik dan selisihnya dengan dokumen SO. Apakah yang Anda maksud Penyesuaian Stok '
            . 'atau Stock Opname?';
    }

    private static function answerStockFeatureComparison(): string
    {
        $adjustmentRows = (int) self::scalar(
            "SELECT COUNT(DISTINCT COALESCE(reference_number, CONCAT('M', id)))
               FROM stock_movements
              WHERE movement_type = ?",
            [StockMovement::TYPE_ADJUSTMENT]
        );
        $opnameRows = (int) self::scalar('SELECT COUNT(*) FROM stock_opnames');

        return 'Penyesuaian Stok dan Stock Opname adalah dua fitur yang berbeda (bukan fitur yang sama). '
            . "\n\nPenyesuaian Stok (menu /transaksi/penyesuaian):"
            . "\n- Tujuan: menambah atau mengurangi stok secara manual oleh Partman."
            . "\n- Dokumen: ADJ-… (satu dokumen berisi satu item)."
            . "\n- Sumber data: tabel stock_movements dengan jenis pergerakan PENYESUAIAN."
            . "\n- Stok sistem langsung berubah saat dokumen disimpan dan tercatat di ledger."
            . "\n- Saat ini tercatat " . self::n($adjustmentRows) . ' dokumen penyesuaian stok.'
            . "\n\nStock Opname (menu /transaksi/opname):"
            . "\n- Tujuan: memeriksa stok fisik lalu mencocokkannya dengan stok sistem."
            . "\n- Dokumen: SO-… (satu sesi berisi banyak item yang diperiksa)."
            . "\n- Sumber data: tabel stock_opnames (header/sesi) dan stock_opname_items (detail)."
            . "\n- Stok tidak berubah saat sesi OPEN; perubahan hanya terjadi setelah sesi dikonfirmasi "
            . 'lewat pergerakan PENYESUAIAN_OPNAME.'
            . "\n- Saat ini tercatat " . self::n($opnameRows) . ' sesi stock opname.'
            . "\n\nJadi, Penyesuaian Stok adalah koreksi stok manual, sedangkan Stock Opname adalah "
            . 'proses pemeriksaan fisik dan penyesuaian berdasarkan hasil hitung.';
    }

    /**
     * @param array{type:string,from:string,to:string,label:string} $dateFilter
     * @return array{from:string|null,to:string|null,label:string}
     */
    private static function featureDateRange(array $dateFilter, string $period, string $periodLabel): array
    {
        if (($dateFilter['type'] ?? 'none') !== 'none') {
            return ['from' => $dateFilter['from'], 'to' => $dateFilter['to'], 'label' => $dateFilter['label']];
        }
        if ($period !== 'semua') {
            [$from, $to] = self::range($period);
            return ['from' => $from, 'to' => $to, 'label' => $periodLabel];
        }
        return ['from' => null, 'to' => null, 'label' => 'sejak data tersedia (seluruh periode)'];
    }

    /**
     * Penyesuaian Stok: dokumen dihitung dari stock_movements tipe PENYESUAIAN,
     * bukan dari stock_opnames. Jumlah dokumen, item, penambahan, dan
     * pengurangan dihitung terpisah.
     *
     * @param array{type:string,from:string,to:string,label:string} $dateFilter
     */
    private static function answerStockAdjustments(
        string $q,
        string $role,
        string $period,
        string $periodLabel,
        array $dateFilter,
        ?string $docNumber
    ): string {
        $range = self::featureDateRange($dateFilter, $period, $periodLabel);
        $where = ' WHERE m.movement_type = ?';
        $params = [StockMovement::TYPE_ADJUSTMENT];
        if ($range['from'] !== null) {
            $where .= ' AND m.movement_date BETWEEN ? AND ?';
            $params[] = $range['from'];
            $params[] = $range['to'];
        }
        if ($docNumber !== null) {
            $where .= ' AND m.reference_number = ?';
            $params[] = $docNumber;
        }

        $rows = self::fetchAll(
            'SELECT m.id, m.reference_number, m.movement_date, m.created_at, m.quantity,
                    m.stock_before, m.stock_after, m.note,
                    s.code AS sparepart_code, s.name AS sparepart_name, s.unit,
                    u.full_name AS user_name, u.username
               FROM stock_movements m
               JOIN spareparts s ON s.id = m.sparepart_id
          LEFT JOIN users u ON u.id = m.user_id' . $where . '
           ORDER BY m.movement_date DESC, m.id DESC',
            $params
        );

        $docNumbers = [];
        $addition = 0.0;
        $reduction = 0.0;
        foreach ($rows as $row) {
            $number = trim((string) ($row['reference_number'] ?? ''));
            if ($number !== '' && !in_array($number, $docNumbers, true)) {
                $docNumbers[] = $number;
            }
            $qty = (float) ($row['quantity'] ?? 0);
            if ($qty >= 0) {
                $addition += $qty;
            } else {
                $reduction += abs($qty);
            }
        }
        $docCount = $docNumbers !== [] ? count($docNumbers) : count($rows);
        $lastRow = $rows[0] ?? null;

        self::$lastContext = [
            'topic'       => 'transaction',
            'module'      => 'adjustment',
            'date_type'   => (string) ($dateFilter['type'] ?? 'none'),
            'date_from'   => $range['from'] ?? '',
            'date_to'     => $range['to'] ?? '',
            'date_label'  => $range['label'],
            'doc_numbers' => $docNumbers,
        ];

        if ($rows === []) {
            return 'Belum ada dokumen penyesuaian stok ' . $range['label'] . ' pada database SmartPartMan. '
                . 'Penyesuaian stok dihitung dari tabel stock_movements jenis PENYESUAIAN, '
                . 'bukan dari sesi stock opname.';
        }

        $asksActor = preg_match('/\b(siapa|pelaku|petugas|dicatat oleh|dilakukan oleh)\b/u', $q) === 1;
        $asksLast = preg_match('/\b(terakhir|terbaru)\b/u', $q) === 1
            && preg_match('/\b(riwayat|semua|daftar)\b/u', $q) !== 1;
        $asksAddition = preg_match('/\b(penambahan|menambah|tambah)\b/u', $q) === 1
            && preg_match('/\b(pengurangan|mengurangi|kurangi|kurang)\b/u', $q) !== 1;
        $asksReduction = preg_match('/\b(pengurangan|mengurangi|kurangi|kurang)\b/u', $q) === 1
            && preg_match('/\b(penambahan|menambah|tambah)\b/u', $q) !== 1;
        $asksDetail = preg_match('/\b(rincian|detail|tampilkan|daftar|sebutkan|semua|riwayat|apa saja|dokumen)\b/u', $q) === 1;

        if ($asksActor) {
            $byUser = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['user_name'] ?? ''));
                $key = $name !== '' ? $name . '|' . (string) ($row['username'] ?? '') : 'Tidak tercatat';
                if (!isset($byUser[$key])) {
                    $byUser[$key] = ['name' => $name !== '' ? $name : 'Tidak tercatat',
                        'username' => (string) ($row['username'] ?? ''), 'docs' => 0,
                        'add' => 0.0, 'reduce' => 0.0, 'last' => (string) ($row['movement_date'] ?? '')];
                }
                $byUser[$key]['docs']++;
                $qty = (float) ($row['quantity'] ?? 0);
                if ($qty >= 0) {
                    $byUser[$key]['add'] += $qty;
                } else {
                    $byUser[$key]['reduce'] += abs($qty);
                }
            }
            uasort($byUser, static fn (array $a, array $b): int => $b['docs'] <=> $a['docs']);
            $lines = ['Pengguna yang melakukan penyesuaian stok ' . $range['label'] . ':'];
            foreach (array_slice($byUser, 0, 10, true) as $actor) {
                $lines[] = '- ' . $actor['name']
                    . ($actor['username'] !== '' ? ' (' . $actor['username'] . ')' : '')
                    . ': ' . self::n((int) $actor['docs']) . ' dokumen, penambahan '
                    . self::qty($actor['add']) . ' unit, pengurangan ' . self::qty($actor['reduce'])
                    . ' unit, terakhir ' . self::formatDateId($actor['last']) . '.';
            }
            return implode("\n", $lines);
        }

        if ($asksLast && !$asksDetail) {
            return "Penyesuaian stok terakhir " . $range['label'] . ":\n"
                . self::formatAdjustmentRow($lastRow, 1);
        }

        if (($asksAddition || $asksReduction) && !$asksDetail) {
            $focus = $asksAddition ? 'penambahan' : 'pengurangan';
            $total = $asksAddition ? $addition : $reduction;
            return 'Total ' . $focus . ' stok dari ' . self::n($docCount) . ' dokumen penyesuaian stok '
                . $range['label'] . ': ' . self::qty($total) . ' unit. '
                . 'Penambahan dan pengurangan dihitung terpisah dari baris dokumen, bukan dari '
                . 'jumlah baris ledger.';
        }

        $lines = ['Berdasarkan data SmartPartMan, terdapat ' . self::n($docCount)
            . ' dokumen penyesuaian stok ' . $range['label'] . '.', '', 'Rincian:'];
        foreach (array_slice($rows, 0, 25) as $index => $row) {
            $lines[] = self::formatAdjustmentRow($row, $index + 1);
        }
        if (count($rows) > 25) {
            $lines[] = '… dan ' . self::n(count($rows) - 25) . ' baris item lainnya.';
        }
        $lines[] = '';
        $lines[] = 'Total penambahan: ' . self::qty($addition) . ' unit.';
        $lines[] = 'Total pengurangan: ' . self::qty($reduction) . ' unit.';
        $lines[] = 'Jumlah item detail: ' . self::n(count($rows))
            . ' item (satu dokumen penyesuaian berisi satu item pada ledger).';

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $row */
    private static function formatAdjustmentRow(array $row, int $number): string
    {
        $qty = (float) ($row['quantity'] ?? 0);
        $unit = (string) ($row['unit'] ?? '');
        $actor = trim((string) ($row['user_name'] ?? ''));
        return $number . ". Nomor dokumen: " . (string) ($row['reference_number'] ?? '-')
            . "\n   Tanggal: " . self::formatDateId((string) ($row['movement_date'] ?? $row['created_at'] ?? ''))
            . "\n   Sparepart: " . (string) ($row['sparepart_name'] ?? '-')
            . ' (' . (string) ($row['sparepart_code'] ?? '-') . ')'
            . "\n   Jenis: " . ($qty >= 0 ? 'Penambahan' : 'Pengurangan')
            . "\n   Jumlah: " . self::qty(abs($qty)) . ($unit !== '' ? ' ' . $unit : '')
            . "\n   Dicatat oleh: " . ($actor !== '' ? $actor : 'tidak tercatat')
            . "\n   Stok: " . self::qty((float) ($row['stock_before'] ?? 0)) . ' → '
            . self::qty((float) ($row['stock_after'] ?? 0));
    }

    /**
     * Stock Opname: sesi dari stock_opnames, item diperiksa dan item
     * berselisih dari stock_opname_items. Tidak memakai tabel penyesuaian.
     *
     * @param array{type:string,from:string,to:string,label:string} $dateFilter
     */
    private static function answerStockOpnames(
        string $q,
        string $role,
        string $period,
        string $periodLabel,
        array $dateFilter,
        ?string $docNumber
    ): string {
        $range = self::featureDateRange($dateFilter, $period, $periodLabel);
        $where = ' WHERE 1 = 1';
        $params = [];
        if ($range['from'] !== null) {
            $where .= ' AND COALESCE(o.opname_date, DATE(o.started_at)) BETWEEN ? AND ?';
            $params[] = $range['from'];
            $params[] = $range['to'];
        }
        if ($docNumber !== null) {
            $where .= ' AND o.opname_number = ?';
            $params[] = $docNumber;
        }

        $sessions = self::fetchAll(
            'SELECT o.*, su.full_name AS started_by_name, su.username AS started_by_username,
                    cu.full_name AS confirmed_by_name,
                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id) AS item_count,
                    (SELECT COUNT(*) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL) AS counted_count,
                    (SELECT COUNT(*) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL
                        AND i.difference <> 0) AS diff_count,
                    (SELECT COALESCE(SUM(i.difference), 0) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL) AS net_difference,
                    (SELECT COALESCE(SUM(ABS(i.difference)), 0) FROM stock_opname_items i
                      WHERE i.opname_id = o.id AND i.physical_stock IS NOT NULL) AS abs_difference
               FROM stock_opnames o
               JOIN users su ON su.id = o.started_by
          LEFT JOIN users cu ON cu.id = o.confirmed_by' . $where . '
           ORDER BY COALESCE(o.opname_date, DATE(o.started_at)) DESC, o.id DESC',
            $params
        );

        self::$lastContext = [
            'topic'       => 'transaction',
            'module'      => 'opname',
            'date_type'   => (string) ($dateFilter['type'] ?? 'none'),
            'date_from'   => $range['from'] ?? '',
            'date_to'     => $range['to'] ?? '',
            'date_label'  => $range['label'],
            'doc_numbers' => array_values(array_map(
                static fn (array $row): string => (string) ($row['opname_number'] ?? ''),
                $sessions
            )),
        ];

        if ($sessions === []) {
            return 'Belum ada sesi stock opname ' . $range['label'] . ' pada database SmartPartMan. '
                . 'Stock opname dihitung dari tabel stock_opnames dan stock_opname_items, '
                . 'bukan dari dokumen penyesuaian stok.';
        }

        $itemStats = self::fetchOne(
            'SELECT COUNT(*) AS total_items,
                    SUM(CASE WHEN i.physical_stock IS NOT NULL THEN 1 ELSE 0 END) AS checked_items,
                    SUM(CASE WHEN i.physical_stock IS NOT NULL AND i.difference <> 0 THEN 1 ELSE 0 END) AS diff_items,
                    COALESCE(SUM(CASE WHEN i.physical_stock IS NOT NULL THEN i.difference ELSE 0 END), 0) AS net_difference,
                    COALESCE(SUM(CASE WHEN i.physical_stock IS NOT NULL THEN ABS(i.difference) ELSE 0 END), 0) AS abs_difference,
                    COUNT(DISTINCT CASE WHEN i.physical_stock IS NOT NULL THEN i.sparepart_id END) AS checked_parts
               FROM stock_opname_items i
               JOIN stock_opnames o ON o.id = i.opname_id' . $where,
            $params
        ) ?? [];

        $sessionCount = count($sessions);
        $checked = (int) ($itemStats['checked_items'] ?? 0);
        $diffItems = (int) ($itemStats['diff_items'] ?? 0);
        $netDiff = (float) ($itemStats['net_difference'] ?? 0);
        $absDiff = (float) ($itemStats['abs_difference'] ?? 0);
        $checkedParts = (int) ($itemStats['checked_parts'] ?? 0);

        $asksActor = preg_match('/\b(siapa|pelaku|petugas|dilakukan oleh)\b/u', $q) === 1;
        $asksLast = preg_match('/\b(terakhir|terbaru)\b/u', $q) === 1
            && preg_match('/\b(riwayat|semua|daftar)\b/u', $q) !== 1;
        $asksParts = preg_match('/\b(sparepart|barang|item)\b/u', $q) === 1
            && preg_match('/\b(apa saja|diperiksa|mana saja|sebutkan|daftar)\b/u', $q) === 1;
        $asksDifference = preg_match('/\b(selisih|koreksi|perlu dikoreksi|fisik)\b/u', $q) === 1;

        if ($asksActor) {
            $lines = ['Pengguna yang melakukan stock opname ' . $range['label'] . ':'];
            foreach (array_slice($sessions, 0, 10) as $row) {
                $lines[] = '- ' . (string) ($row['started_by_name'] ?? '-')
                    . ' (' . (string) ($row['started_by_username'] ?? '-') . '): '
                    . (string) ($row['opname_number'] ?? '-') . ' tanggal '
                    . self::formatDateId((string) ($row['opname_date'] ?? $row['started_at'] ?? ''))
                    . ', ' . self::n((int) ($row['counted_count'] ?? 0)) . ' item diperiksa, '
                    . self::n((int) ($row['diff_count'] ?? 0)) . ' item berselisih.';
            }
            return implode("\n", $lines);
        }

        if ($asksParts) {
            $items = self::fetchAll(
                'SELECT s.code, s.name, s.unit, i.system_stock, i.physical_stock, i.difference,
                        o.opname_number, o.opname_date
                   FROM stock_opname_items i
                   JOIN stock_opnames o ON o.id = i.opname_id
                   JOIN spareparts s ON s.id = i.sparepart_id' . $where
                . ' AND i.physical_stock IS NOT NULL
                ORDER BY COALESCE(o.opname_date, DATE(o.started_at)) DESC, s.name ASC
                   LIMIT 25',
                $params
            );
            if ($items === []) {
                return 'Belum ada sparepart yang diperiksa pada sesi stock opname ' . $range['label'] . '.';
            }
            $lines = ['Sparepart yang diperiksa pada stock opname ' . $range['label'] . ':'];
            foreach ($items as $item) {
                $lines[] = '- ' . (string) $item['name'] . ' (' . (string) $item['code'] . '): '
                    . 'sistem ' . self::qty((float) $item['system_stock']) . ', fisik '
                    . self::qty((float) $item['physical_stock']) . ', selisih '
                    . self::qty((float) $item['difference']) . ' ' . (string) $item['unit']
                    . ' — ' . (string) $item['opname_number'] . '.';
            }
            return implode("\n", $lines);
        }

        if ($asksLast && !$asksDifference) {
            $last = $sessions[0];
            return 'Stock opname terakhir ' . $range['label'] . ' adalah ' . (string) ($last['opname_number'] ?? '-')
                . ' pada ' . self::formatDateId((string) ($last['opname_date'] ?? $last['started_at'] ?? ''))
                . ' (' . self::opnameStatusLabel((string) ($last['status'] ?? '')) . '), '
                . 'dilakukan oleh ' . (string) ($last['started_by_name'] ?? '-') . '. '
                . 'Diperiksa ' . self::n((int) ($last['counted_count'] ?? 0)) . ' item dari '
                . self::n((int) ($last['item_count'] ?? 0)) . ' item sesi; '
                . self::n((int) ($last['diff_count'] ?? 0)) . ' item berselisih '
                . '(selisih bersih ' . self::qty((float) ($last['net_difference'] ?? 0))
                . ', selisih absolut ' . self::qty((float) ($last['abs_difference'] ?? 0)) . ' unit).';
        }

        $lines = ['Berdasarkan data SmartPartMan, terdapat ' . self::n($sessionCount)
            . ' sesi stock opname ' . $range['label'] . '.', '', 'Rincian:'];
        foreach (array_slice($sessions, 0, 25) as $index => $row) {
            $lines[] = ($index + 1) . '. Nomor dokumen: ' . (string) ($row['opname_number'] ?? '-')
                . "\n   Tanggal: " . self::formatDateId((string) ($row['opname_date'] ?? $row['started_at'] ?? ''))
                . "\n   Status: " . self::opnameStatusLabel((string) ($row['status'] ?? ''))
                . "\n   Dimulai oleh: " . (string) ($row['started_by_name'] ?? '-')
                . "\n   Item diperiksa: " . self::n((int) ($row['counted_count'] ?? 0))
                . ' dari ' . self::n((int) ($row['item_count'] ?? 0)) . ' item sesi'
                . "\n   Item berselisih: " . self::n((int) ($row['diff_count'] ?? 0))
                . "\n   Selisih bersih: " . self::qty((float) ($row['net_difference'] ?? 0))
                . ' unit; selisih absolut: ' . self::qty((float) ($row['abs_difference'] ?? 0)) . ' unit';
        }
        if (count($sessions) > 25) {
            $lines[] = '… dan ' . self::n(count($sessions) - 25) . ' sesi lainnya.';
        }
        $lines[] = '';
        $lines[] = 'Total item diperiksa: ' . self::n($checked) . ' item dari '
            . self::n($checkedParts) . ' sparepart berbeda.';
        $lines[] = 'Total item berselisih: ' . self::n($diffItems) . ' item.';
        $lines[] = 'Selisih bersih (fisik - sistem): ' . self::qty($netDiff) . ' unit.';
        $lines[] = 'Total selisih absolut yang perlu dikoreksi: ' . self::qty($absDiff) . ' unit.';

        return implode("\n", $lines);
    }

    private static function opnameStatusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'OPEN' => 'belum dikonfirmasi (OPEN)',
            'CONFIRMED' => 'terkonfirmasi (CONFIRMED)',
            default => $status !== '' ? strtolower($status) : 'tidak diketahui',
        };
    }

    /**
     * Simpan topik, modul, dan periode transaksi untuk pertanyaan lanjutan.
     *
     * @param array{type:string,from:string,to:string,label:string} $dateFilter
     */
    private static function rememberTransactionContext(
        string $q,
        array $dateFilter,
        string $period,
        string $periodLabel,
        bool $wantsIn,
        bool $wantsOut
    ): void {
        if ($dateFilter['type'] !== 'none') {
            $type = $dateFilter['type'];
            $from = $dateFilter['from'];
            $to = $dateFilter['to'];
            $label = $dateFilter['label'];
        } elseif ($period === 'semua') {
            $type = 'none';
            $from = '';
            $to = '';
            $label = $periodLabel;
        } else {
            [$from, $to] = self::range($period);
            $type = match ($period) {
                'hari'   => 'day',
                'minggu' => 'range',
                'bulan'  => 'month',
                'tahun'  => 'year',
                default  => 'none',
            };
            $label = $periodLabel;
        }

        $module = match (true) {
            preg_match('/\b(stock opname|stok opname|opname)\b/u', $q) === 1 => 'opname',
            preg_match('/\b(penyesuaian|stock adjustment|menyesuaikan stok)\b/u', $q) === 1 => 'adjustment',
            $wantsIn && !$wantsOut => 'receipt',
            $wantsOut && !$wantsIn => 'issue',
            default => 'both',
        };

        self::$lastContext = [
            'topic'      => 'transaction',
            'module'     => $module,
            'date_type'  => $type,
            'date_from'  => $from,
            'date_to'    => $to,
            'date_label' => $label,
            'doc_numbers'=> [],
        ];
    }

    /**
     * Pertanyaan tentang pengguna, role, login, dan aktivitas pengguna.
     *
     * Semua jawaban disusun sebagai kalimat bahasa Indonesia yang lengkap.
     * ID internal tidak pernah ditampilkan; nama/username/role dipakai sebagai
     * identitas yang dapat dibaca manusia.
     */
    private static function answerUsers(string $q, string $role, ?array $rows = null): string
    {
        $rows = $rows ?? self::userRows();
        $matches = self::findUsersInQuestion($q, $rows);
        $user = count($matches) === 1 ? $matches[0] : null;
        $intent = self::detectUserIntent($q, $user !== null);

        return self::composeUserAnswer($q, $intent, $user, $matches, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private static function userRows(): array
    {
        return self::fetchAll(
            'SELECT u.id, u.username, u.full_name, u.email, u.is_active, u.failed_attempts,
                    u.locked_until, u.last_login_at, u.created_at,
                    r.code AS role_code, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
           ORDER BY u.id ASC'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function findUsersInQuestion(string $q, array $rows): array
    {
        $q = mb_strtolower($q);
        $exact = [];
        $partial = [];
        $stop = ['admin', 'administrator', 'user', 'pengguna', 'akun', 'role', 'peran',
            'kepala', 'bengkel', 'partman', 'staff', 'staf', 'sistem', 'super', 'data',
            'aktif', 'nonaktif', 'login', 'terakhir', 'semua', 'seluruh',
            'uji', 'test', 'probe', 'http',
            'apa', 'siapa', 'kapan', 'berapa', 'bagaimana', 'tampilkan', 'sebutkan',
            'detail', 'profil', 'info', 'informasi', 'nama', 'lengkap', 'username',
            'masing', 'tanggal', 'dibuat', 'dibuatkan', 'daftar', 'riwayat',
            'stok', 'sparepart', 'barang', 'masuk', 'keluar', 'transaksi',
            'opname', 'penyesuaian', 'kategori', 'supplier', 'rak', 'lokasi',
            'laporan', 'aktivitas', 'waktu', 'jam', 'gagal', 'belum', 'pernah'];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $username = mb_strtolower(trim((string) ($row['username'] ?? '')));
            $name = mb_strtolower(trim((string) ($row['full_name'] ?? '')));

            if ($username !== '' && mb_strlen($username) >= 3
                && preg_match('/(?<![a-z0-9_])' . preg_quote($username, '/') . '(?![a-z0-9_])/u', $q) === 1
            ) {
                $exact[$id] = $row;
                continue;
            }
            if ($name !== '' && mb_strlen($name) >= 3 && str_contains($q, $name)) {
                $exact[$id] = $row;
                continue;
            }

            $tokens = preg_split('/[^a-z0-9]+/u', $name) ?: [];
            foreach ($tokens as $token) {
                if (mb_strlen($token) < 4 || in_array($token, $stop, true)) {
                    continue;
                }
                if (preg_match('/(?<![a-z0-9])' . preg_quote($token, '/') . '(?![a-z0-9])/u', $q) === 1) {
                    $partial[$id] = $row;
                    break;
                }
            }
        }

        if ($exact !== []) {
            return array_values($exact);
        }

        return array_slice(array_values($partial), 0, 10);
    }

    private static function detectUserIntent(string $q, bool $hasUser): string
    {
        $hasLogin = preg_match('/\b(login|log masuk|masuk log)\b/u', $q) === 1;

        if (preg_match('/\b(belum pernah login|belum login|tidak pernah login|belum pernah masuk|belum ada login)\b/u', $q) === 1) {
            return 'never_login';
        }
        if ($hasLogin && preg_match('/\b(gagal|gagal login|failed)\b/u', $q) === 1) {
            return preg_match('/\b(paling sering|paling banyak|terbanyak)\b/u', $q) === 1
                ? 'top_failed'
                : 'failed_login';
        }
        if (preg_match('/\b(riwayat login|history login|riwayat masuk|riwayat percobaan login)\b/u', $q) === 1) {
            return 'login_history';
        }
        if ($hasLogin && !$hasUser
            && preg_match('/\b(siapa|pengguna|user)\b/u', $q) === 1
            && preg_match('/\b(terakhir|paling akhir|terbaru)\b/u', $q) === 1
        ) {
            return 'latest_login';
        }
        if ($hasLogin && preg_match('/\b(kapan|terakhir|waktu|jam)\b/u', $q) === 1) {
            return 'last_login';
        }
        if (preg_match('/\b(terakhir aktif|aktivitas terakhir|terakhir beraktivitas|kapan aktif)\b/u', $q) === 1) {
            return 'last_active';
        }
        if (preg_match('/\b(transaksi terakhir|terakhir transaksi|terakhir melakukan transaksi|terakhir bertransaksi)\b/u', $q) === 1) {
            return 'last_transaction';
        }

        $activity = self::detectActivityModule($q);
        if ($activity !== '' && preg_match('/\b(siapa|pelaku|petugas|pengguna)\b/u', $q) === 1) {
            return 'activity';
        }

        if (preg_match('/\b(role|peran)\b/u', $q) === 1) {
            return 'role';
        }
        if (preg_match('/\b(kapan akun dibuat|akun dibuat|tanggal dibuat|terdaftar sejak|sejak kapan|dibuat pada)\b/u', $q) === 1) {
            return 'created';
        }
        if (preg_match('/\b(username|nama pengguna)\b/u', $q) === 1) {
            return 'username';
        }
        if (preg_match('/\b(nama lengkap|nama masing-masing|nama setiap|nama semua)\b/u', $q) === 1) {
            return 'name';
        }
        if (preg_match('/\b(detail|profil|informasi akun|info akun|tentang pengguna)\b/u', $q) === 1) {
            return 'detail';
        }
        if (preg_match('/\b(nonaktif|tidak aktif)\b/u', $q) === 1) {
            return 'inactive';
        }
        if (preg_match('/\b(pengguna aktif|user aktif|akun aktif)\b/u', $q) === 1) {
            return 'active';
        }
        if (preg_match('/\b(berapa|jumlah|total|banyak)\b/u', $q) === 1) {
            return 'count';
        }

        return 'list';
    }

    private static function detectActivityModule(string $q): string
    {
        if (preg_match('/\b(stock opname|stok opname|opname|pencocokan stok|selisih stok fisik)\b/u', $q) === 1) {
            return 'opname';
        }
        if (preg_match('/\b(penyesuaian stok|penyesuaian|stock adjustment|menyesuaikan stok|tambah stok manual|kurangi stok manual)\b/u', $q) === 1) {
            return 'adjustment';
        }
        if (preg_match('/\b(purchase order|po|pembelian|menyetujui|persetujuan|approval)\b/u', $q) === 1) {
            return 'po';
        }
        if (preg_match('/\b(barang masuk|penerimaan barang|menerima barang|receipt)\b/u', $q) === 1) {
            return 'receipt';
        }
        if (preg_match('/\b(barang keluar|pengeluaran barang|issue)\b/u', $q) === 1) {
            return 'issue';
        }
        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $matches
     * @param array<int,array<string,mixed>> $rows
     */
    private static function composeUserAnswer(string $q, string $intent, ?array $user, array $matches, array $rows): string
    {
        $total = count($rows);
        $active = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
        $inactive = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) !== 1));

        $activity = self::detectActivityModule($q);
        self::$lastContext = [
            'topic'      => 'user',
            'module'     => 'user',
            'intent'     => $intent,
            'scope'      => $intent === 'active' ? 'active' : ($intent === 'inactive' ? 'inactive' : 'all'),
            'user_id'    => $user !== null ? (int) ($user['id'] ?? 0) : null,
            'user_name'  => $user !== null ? (string) ($user['full_name'] ?? '') : null,
            'username'   => $user !== null ? (string) ($user['username'] ?? '') : null,
            'activity'   => $activity,
        ];

        $needsUser = in_array($intent, [
            'last_login', 'last_active', 'last_transaction', 'failed_login',
            'login_history', 'detail', 'role', 'created',
        ], true);
        if ($needsUser && $user === null && count($matches) > 1) {
            return self::userClarification($matches);
        }
        if ($needsUser && $user === null && $intent !== 'role' && $intent !== 'created') {
            return 'Sebutkan nama lengkap atau username pengguna yang ingin ditanyakan. '
                . 'Contoh: "Kapan Hasan Khairi terakhir login?"';
        }

        return match ($intent) {
            'last_login'       => self::answerUserLastLogin($user),
            'latest_login'     => self::answerLatestLogin($rows),
            'last_active'      => self::answerUserLastActive($user),
            'last_transaction' => self::answerUserLastTransaction($user),
            'failed_login'     => self::answerUserFailedLogin($user, $q),
            'top_failed'       => self::answerTopFailedLogin(),
            'never_login'      => self::answerNeverLoggedIn($rows),
            'login_history'    => self::answerLoginHistory($user),
            'activity'         => self::answerUserActivity($q, $user),
            'role'             => self::answerUserRoles($user, $rows),
            'created'          => self::answerUserCreated($user, $rows),
            'username'         => self::answerUserUsername($user, $rows),
            'name'             => self::answerUserNames($rows),
            'detail'           => self::answerUserDetail($user),
            'inactive'         => self::answerUserStatusList($inactive, $total, false),
            'active'           => self::answerUserStatusList($active, $total, true),
            'count'            => self::answerUserCount($q, $rows, $active, $inactive),
            default            => self::answerUserList($rows),
        };
    }

    /** @param array<int,array<string,mixed>> $matches */
    private static function userClarification(array $matches): string
    {
        $lines = ['Ada lebih dari satu pengguna yang cocok dengan nama tersebut. '
            . 'Sebutkan username atau nama lengkapnya agar saya tidak salah orang:'];
        foreach (array_slice($matches, 0, 8) as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . ') — '
                . self::userRoleName($row);
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $row */
    private static function userRoleName(array $row): string
    {
        return (string) (($row['role_name'] ?? '') !== '' ? $row['role_name'] : ($row['role_code'] ?? ''));
    }

    /** @param array<string,mixed> $row */
    private static function userStatusLabel(array $row): string
    {
        return (int) ($row['is_active'] ?? 0) === 1 ? 'aktif' : 'nonaktif';
    }

    /** @param array<string,mixed>|null $user */
    private static function answerUserLastLogin(?array $user): string
    {
        if ($user === null) {
            return self::ANSWER_CLARIFY;
        }
        $name = (string) $user['full_name'];
        $last = self::formatDateTimeId((string) ($user['last_login_at'] ?? ''));
        if ($last === null) {
            return 'Belum terdapat catatan login untuk ' . $name . ' pada database SmartPartMan. '
                . 'Akun tersebut tercatat dengan username ' . $user['username'] . ' dan role '
                . self::userRoleName($user) . '.';
        }

        return $name . ' terakhir kali login pada ' . $last . '.';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function answerLatestLogin(array $rows): string
    {
        $logged = array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['last_login_at'] ?? '')) !== ''
        ));
        if ($logged === []) {
            return 'Belum terdapat catatan login untuk pengguna mana pun pada database SmartPartMan.';
        }
        usort($logged, static fn (array $a, array $b): int => strcmp(
            (string) $b['last_login_at'],
            (string) $a['last_login_at']
        ));
        $latest = $logged[0];

        return 'Pengguna yang terakhir login adalah ' . $latest['full_name']
            . ' (' . $latest['username'] . ', ' . self::userRoleName($latest) . ') pada '
            . self::formatDateTimeId((string) $latest['last_login_at']) . '.';
    }

    /** @param array<string,mixed>|null $user */
    private static function answerUserLastActive(?array $user): string
    {
        if ($user === null) {
            return self::ANSWER_CLARIFY;
        }
        $row = self::fetchOne(
            'SELECT MAX(created_at) AS last_active FROM audit_logs WHERE username = ?',
            [(string) $user['username']]
        );
        $last = self::formatDateTimeId((string) ($row['last_active'] ?? ''));
        if ($last === null) {
            return 'Belum terdapat catatan aktivitas untuk ' . $user['full_name']
                . ' pada Audit Log. Waktu login terakhir dan aktivitas terakhir adalah informasi yang berbeda.';
        }

        return 'Aktivitas terakhir ' . $user['full_name'] . ' tercatat pada ' . $last
            . '. Informasi ini berasal dari Audit Log dan berbeda dari waktu login terakhir.';
    }

    /** @param array<string,mixed>|null $user */
    private static function answerUserLastTransaction(?array $user): string
    {
        if ($user === null) {
            return self::ANSWER_CLARIFY;
        }
        $row = self::fetchOne(
            'SELECT MAX(last_date) AS last_date FROM (
                SELECT MAX(gr.receipt_date) AS last_date FROM goods_receipts gr WHERE gr.recorded_by = ?
                UNION ALL
                SELECT MAX(gi.issue_date) AS last_date FROM goods_issues gi WHERE gi.recorded_by = ?
                UNION ALL
                SELECT MAX(COALESCE(so.opname_date, DATE(so.started_at))) AS last_date
                  FROM stock_opnames so WHERE so.started_by = ?
             ) t',
            [(int) $user['id'], (int) $user['id'], (int) $user['id']]
        );
        $last = self::formatDateId((string) ($row['last_date'] ?? ''));
        if ($last === '') {
            return 'Belum terdapat catatan transaksi barang masuk, barang keluar, atau stock opname '
                . 'yang dilakukan oleh ' . $user['full_name'] . '.';
        }

        return 'Transaksi terakhir yang dicatat oleh ' . $user['full_name'] . ' adalah pada ' . $last . '.';
    }

    /** @param array<string,mixed>|null $user */
    private static function answerUserFailedLogin(?array $user, string $q): string
    {
        if ($user === null) {
            $total = (int) (self::fetchOne(
                'SELECT COUNT(*) AS total FROM login_attempts WHERE success = 0'
            )['total'] ?? 0);
            $users = (int) (self::fetchOne(
                'SELECT COUNT(DISTINCT username) AS total FROM login_attempts WHERE success = 0'
            )['total'] ?? 0);
            if ($total === 0) {
                return 'Belum ada percobaan login gagal yang tercatat pada database SmartPartMan.';
            }
            return 'Tercatat ' . self::n($total) . ' percobaan login gagal dari ' . self::n($users)
                . ' username berbeda. Tanyakan nama pengguna tertentu untuk melihat rinciannya, '
                . 'atau minta daftar pengguna yang paling sering gagal login.';
        }

        $history = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM login_attempts WHERE username = ? AND success = 0',
            [(string) $user['username']]
        )['total'] ?? 0);
        $current = (int) ($user['failed_attempts'] ?? 0);
        $locked = self::formatDateTimeId((string) ($user['locked_until'] ?? ''));

        $answer = $user['full_name'] . ' tercatat gagal login sebanyak ' . self::n($history) . ' kali. '
            . 'Jumlah percobaan gagal berturut-turut saat ini adalah ' . self::n($current) . '.';
        if ($locked !== null) {
            $answer .= ' Akun tersebut sedang terkunci sampai ' . $locked . '.';
        } elseif ($current === 0) {
            $answer .= ' Tidak ada penguncian akun yang aktif.';
        }
        return $answer;
    }

    private static function answerTopFailedLogin(): string
    {
        $rows = self::fetchAll(
            'SELECT la.username, COUNT(*) AS total, MAX(la.attempted_at) AS last_at, u.full_name
               FROM login_attempts la
          LEFT JOIN users u ON u.username = la.username
              WHERE la.success = 0
           GROUP BY la.username, u.full_name
           ORDER BY total DESC, la.username ASC
              LIMIT 5'
        );
        if ($rows === []) {
            return 'Belum ada percobaan login gagal yang tercatat pada database SmartPartMan.';
        }

        $lines = ['Pengguna yang paling sering gagal login:'];
        foreach ($rows as $row) {
            $name = trim((string) ($row['full_name'] ?? ''));
            $lines[] = '- ' . ($name !== '' ? $name : $row['username'])
                . ' (' . $row['username'] . '): ' . self::n((int) $row['total'])
                . ' kali gagal, terakhir ' . self::formatDateTimeId((string) $row['last_at']) . '.';
        }
        return implode("\n", $lines);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function answerNeverLoggedIn(array $rows): string
    {
        $never = array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['last_login_at'] ?? '')) === ''
        ));
        if ($never === []) {
            return 'Seluruh ' . self::n(count($rows)) . ' pengguna yang terdaftar sudah pernah login. '
                . 'Tidak ada pengguna yang belum pernah login.';
        }

        $lines = ['Pengguna yang belum pernah login: ' . self::n(count($never)) . ' orang dari total '
            . self::n(count($rows)) . ' pengguna terdaftar.'];
        foreach ($never as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . ') — '
                . self::userRoleName($row) . ', status ' . self::userStatusLabel($row) . '.';
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed>|null $user */
    private static function answerLoginHistory(?array $user): string
    {
        if ($user === null) {
            return 'Sebutkan nama lengkap atau username pengguna yang riwayat login-nya ingin ditampilkan.';
        }
        $rows = self::fetchAll(
            'SELECT success, ip_address, attempted_at
               FROM login_attempts
              WHERE username = ?
           ORDER BY attempted_at DESC
              LIMIT 10',
            [(string) $user['username']]
        );
        if ($rows === []) {
            return 'Belum terdapat catatan login untuk ' . $user['full_name'] . ' pada database SmartPartMan.';
        }

        $lines = ['Riwayat login ' . $user['full_name'] . ' (' . $user['username'] . '), '
            . self::n(count($rows)) . ' percobaan terakhir:'];
        foreach ($rows as $row) {
            $lines[] = '- ' . self::formatDateTimeId((string) $row['attempted_at'])
                . ' · ' . ((int) $row['success'] === 1 ? 'berhasil' : 'gagal')
                . ' · IP ' . ($row['ip_address'] ?: 'tidak tercatat') . '.';
        }
        return implode("\n", $lines);
    }

    /**
     * Aktivitas pengguna berdasarkan modul yang disebut (barang masuk, barang
     * keluar, opname, atau persetujuan PO), lengkap dengan waktu terakhir.
     *
     * @param array<string,mixed>|null $user
     */
    private static function answerUserActivity(string $q, ?array $user): string
    {
        $module = self::detectActivityModule($q);
        if ($module === '') {
            return 'Sebutkan aktivitas yang ingin diketahui, misalnya barang masuk, barang keluar, '
                . 'stock opname, atau persetujuan PO.';
        }

        $filter = $user !== null ? ' AND u.id = ?' : '';
        $params = $user !== null ? [(int) $user['id']] : [];

        [$title, $sql] = match ($module) {
            'receipt' => [
                'barang masuk (pencatatan penerimaan)',
                'SELECT u.full_name, u.username, COUNT(*) AS total, MAX(gr.receipt_date) AS last_date
                   FROM goods_receipts gr
                   JOIN users u ON u.id = gr.recorded_by
                  WHERE 1 = 1' . $filter . '
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC, u.full_name ASC',
            ],
            'issue' => [
                'barang keluar (pengeluaran)',
                'SELECT u.full_name, u.username, COUNT(*) AS total, MAX(gi.issue_date) AS last_date
                   FROM goods_issues gi
                   JOIN users u ON u.id = gi.recorded_by
                  WHERE 1 = 1' . $filter . '
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC, u.full_name ASC',
            ],
            'opname' => [
                'stock opname',
                'SELECT u.full_name, u.username, COUNT(*) AS total,
                        MAX(COALESCE(so.opname_date, DATE(so.started_at))) AS last_date
                   FROM stock_opnames so
                   JOIN users u ON u.id = so.started_by
                  WHERE 1 = 1' . $filter . '
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC, u.full_name ASC',
            ],
            'adjustment' => [
                'penyesuaian stok',
                'SELECT u.full_name, u.username,
                        COUNT(DISTINCT COALESCE(m.reference_number, CONCAT(\'M\', m.id))) AS total,
                        MAX(m.movement_date) AS last_date
                   FROM stock_movements m
                   JOIN users u ON u.id = m.user_id
                  WHERE m.movement_type = \'PENYESUAIAN\'' . $filter . '
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC, u.full_name ASC',
            ],
            default => [
                'persetujuan purchase order (PO)',
                'SELECT u.full_name, u.username, COUNT(*) AS total, MAX(po.approved_at) AS last_date
                   FROM purchase_orders po
                   JOIN users u ON u.id = po.approved_by
                  WHERE po.approved_by IS NOT NULL' . $filter . '
               GROUP BY u.id, u.full_name, u.username
               ORDER BY total DESC, u.full_name ASC',
            ],
        };

        $rows = self::fetchAll($sql, $params);
        if ($rows === []) {
            return 'Belum ada catatan aktivitas ' . $title
                . ($user !== null ? ' oleh ' . $user['full_name'] : '') . '.';
        }

        $lines = ['Pengguna yang melakukan aktivitas ' . $title
            . ($user !== null ? ' untuk ' . $user['full_name'] : '') . ':'];
        foreach (array_slice($rows, 0, 10) as $row) {
            $when = (string) ($row['last_date'] ?? '');
            $whenLabel = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $when) === 1
                ? self::formatDateTimeId($when)
                : self::formatDateId($when);
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . '): '
                . self::n((int) $row['total']) . ' catatan, terakhir ' . ($whenLabel !== '' ? $whenLabel : 'tidak tercatat') . '.';
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function answerUserRoles(?array $user, array $rows): string
    {
        if ($user !== null) {
            return $user['full_name'] . ' memiliki role ' . self::userRoleName($user)
                . ' dengan status akun ' . self::userStatusLabel($user) . '.';
        }

        $lines = ['Role masing-masing pengguna (seluruh data):'];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . '): '
                . self::userRoleName($row) . ' — ' . self::userStatusLabel($row);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed>|null $user
     * @param array<int,array<string,mixed>> $rows
     */
    private static function answerUserCreated(?array $user, array $rows): string
    {
        if ($user !== null) {
            return 'Akun ' . $user['full_name'] . ' dibuat pada '
                . self::formatDateTimeId((string) ($user['created_at'] ?? '')) . '.';
        }

        $lines = ['Tanggal pembuatan akun pengguna:'];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . '): '
                . self::formatDateTimeId((string) ($row['created_at'] ?? '')) . '.';
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed>|null $user
     * @param array<int,array<string,mixed>> $rows
     */
    private static function answerUserUsername(?array $user, array $rows): string
    {
        if ($user !== null) {
            return 'Username ' . $user['full_name'] . ' adalah ' . $user['username'] . '.';
        }

        $lines = ['Username masing-masing pengguna:'];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['full_name'] . ': ' . $row['username'] . ' (' . self::userRoleName($row) . ').';
        }
        return implode("\n", $lines);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function answerUserNames(array $rows): string
    {
        $lines = ['Nama lengkap pengguna terdaftar (' . self::n(count($rows)) . ' pengguna):'];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . ') — ' . self::userRoleName($row) . '.';
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed>|null $user */
    private static function answerUserDetail(?array $user): string
    {
        if ($user === null) {
            return 'Sebutkan nama lengkap atau username pengguna yang ingin ditampilkan detailnya.';
        }

        $last = self::formatDateTimeId((string) ($user['last_login_at'] ?? ''));
        $created = self::formatDateTimeId((string) ($user['created_at'] ?? ''));
        $locked = self::formatDateTimeId((string) ($user['locked_until'] ?? ''));

        $lines = ['Berikut informasi akun ' . $user['full_name'] . ':'];
        $lines[] = '- Nama: ' . $user['full_name'];
        $lines[] = '- Username: ' . $user['username'];
        $lines[] = '- Role: ' . self::userRoleName($user);
        $lines[] = '- Status akun: ' . ucfirst(self::userStatusLabel($user));
        if (trim((string) ($user['email'] ?? '')) !== '') {
            $lines[] = '- Email: ' . $user['email'];
        }
        $lines[] = '- Akun dibuat: ' . ($created ?? 'tidak tercatat');
        $lines[] = '- Terakhir login: ' . ($last ?? 'belum pernah login');
        $lines[] = '- Percobaan gagal saat ini: ' . self::n((int) ($user['failed_attempts'] ?? 0)) . ' kali';
        if ($locked !== null) {
            $lines[] = '- Terkunci sampai: ' . $locked;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $list
     */
    private static function answerUserStatusList(array $list, int $total, bool $active): string
    {
        if ($list === []) {
            return $active
                ? 'Tidak ada pengguna aktif saat ini dari total ' . self::n($total) . ' pengguna terdaftar.'
                : 'Tidak ada pengguna nonaktif saat ini. Seluruh ' . self::n($total) . ' pengguna berstatus aktif.';
        }

        $lines = [($active ? 'Pengguna aktif' : 'Pengguna nonaktif') . ' saat ini: '
            . self::n(count($list)) . ' orang dari total ' . self::n($total) . ' pengguna.'];
        foreach ($list as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . ') — ' . self::userRoleName($row);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $active
     * @param array<int,array<string,mixed>> $inactive
     */
    private static function answerUserCount(string $q, array $rows, array $active, array $inactive): string
    {
        $asksInactive = preg_match('/\b(nonaktif|tidak aktif)\b/u', $q) === 1;
        $asksActive = !$asksInactive && preg_match('/\b(aktif|active)\b/u', $q) === 1;

        if ($asksActive) {
            return 'Saat ini terdapat ' . self::n(count($active)) . ' pengguna aktif dari total '
                . self::n(count($rows)) . ' pengguna terdaftar.';
        }
        if ($asksInactive) {
            return 'Saat ini terdapat ' . self::n(count($inactive)) . ' pengguna nonaktif dari total '
                . self::n(count($rows)) . ' pengguna terdaftar.';
        }

        return 'Jumlah pengguna SmartPartMan saat ini adalah ' . self::n(count($rows))
            . ' orang, terdiri dari ' . self::n(count($active)) . ' pengguna aktif dan '
            . self::n(count($inactive)) . ' pengguna nonaktif.';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function answerUserList(array $rows): string
    {
        if ($rows === []) {
            return 'Belum ada pengguna yang terdaftar pada database SmartPartMan.';
        }
        $active = count(array_filter($rows, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1));

        $lines = ['Daftar pengguna SmartPartMan (' . self::n(count($rows)) . ' pengguna, '
            . self::n($active) . ' aktif):'];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['full_name'] . ' (' . $row['username'] . ') — '
                . self::userRoleName($row) . ' · ' . self::userStatusLabel($row) . '.';
        }
        return implode("\n", $lines);
    }

    /** Tanggal dan waktu Indonesia, misalnya "29 Oktober 2026 pukul 14.32 WIB". */
    private static function formatDateTimeId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }

        return $date->format('j') . ' ' . self::monthName((int) $date->format('n')) . ' ' . $date->format('Y')
            . ' pukul ' . $date->format('H.i') . ' ' . self::timezoneLabel();
    }

    /**
     * Label zona waktu Indonesia sesuai APP_TIMEZONE. Data waktu pada database
     * disimpan sebagai waktu lokal aplikasi, jadi tidak dikonversi ulang.
     */
    private static function timezoneLabel(): string
    {
        $timezone = (string) \App\Core\Env::get('APP_TIMEZONE', 'Asia/Jakarta');
        return match ($timezone) {
            'Asia/Jakarta', 'Asia/Pontianak' => 'WIB',
            'Asia/Makassar', 'Asia/Ujung_Pandang' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => date_default_timezone_get(),
        };
    }

    private static function answerAuditLog(string $q): string
    {
        $total = (int) (self::fetchOne('SELECT COUNT(*) AS total FROM audit_logs')['total'] ?? 0);
        if ($total === 0) {
            return 'Belum ada aktivitas yang tercatat pada Audit Log.';
        }
        $today = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM audit_logs WHERE DATE(created_at) = CURDATE()'
        )['total'] ?? 0);

        if (preg_match('/\b(apa saja|daftar|tampilkan|terbaru|terakhir|sebutkan)\b/u', $q) === 1) {
            $rows = self::fetchAll(
                'SELECT username, role, activity, entity_type, description, created_at
                   FROM audit_logs
               ORDER BY id DESC
                  LIMIT 10'
            );
            $lines = ['Aktivitas Audit Log terbaru (total ' . self::n($total) . ' entri, hari ini ' . self::n($today) . '):'];
            foreach ($rows as $row) {
                $lines[] = '- ' . $row['created_at'] . ' · ' . ($row['username'] ?: 'sistem')
                    . ' · ' . $row['activity']
                    . (trim((string) ($row['entity_type'] ?? '')) !== '' ? ' · ' . $row['entity_type'] : '')
                    . ' · ' . mb_substr((string) $row['description'], 0, 120);
            }
            return implode("\n", $lines);
        }

        return 'Audit Log mencatat ' . self::n($total) . ' aktivitas, dengan ' . self::n($today)
            . ' aktivitas hari ini. Log bersifat tambah-saja dan hanya dapat dibaca Admin.';
    }

    private static function answerBackups(string $q): string
    {
        $total = (int) (self::fetchOne('SELECT COUNT(*) AS total FROM backups')['total'] ?? 0);
        if ($total === 0) {
            return 'Belum ada berkas backup yang tercatat pada database SmartPartMan.';
        }

        $last = self::fetchOne(
            'SELECT filename, status, filesize, created_at, restored_at
               FROM backups
           ORDER BY id DESC
              LIMIT 1'
        );

        $lines = ['Ringkasan backup SmartPartMan: total ' . self::n($total) . ' berkas backup tercatat.'];
        if (is_array($last)) {
            $lines[] = '- Backup terakhir: ' . $last['filename'] . ' · status ' . $last['status']
                . ' · ' . $last['created_at']
                . ($last['filesize'] !== null ? ' · ' . number_format((int) $last['filesize'] / 1024, 0, ',', '.') . ' KB' : '');
            if (!empty($last['restored_at'])) {
                $lines[] = '- Restore terakhir: ' . $last['restored_at'] . '.';
            }
        }
        $restored = (int) (self::fetchOne(
            "SELECT COUNT(*) AS total FROM backups WHERE restored_at IS NOT NULL"
        )['total'] ?? 0);
        $lines[] = 'Jumlah backup yang pernah direstore: ' . self::n($restored) . '.';
        $lines[] = 'Backup dan restore hanya dapat dilakukan Admin melalui menu Pengaturan Backup.';
        return implode("\n", $lines);
    }

    private static function answerWhatsapp(string $q): string
    {
        $data = self::whatsappSummary();
        if ((int) $data['total'] === 0) {
            return 'Belum ada riwayat notifikasi WhatsApp yang tercatat.';
        }
        $lines = ['Ringkasan notifikasi WhatsApp: total ' . self::n((int) $data['total']) . ' notifikasi.'];
        foreach ((array) $data['per_status'] as $status => $count) {
            $lines[] = '- Status ' . $status . ': ' . self::n((int) $count);
        }
        foreach ((array) $data['per_jenis'] as $type => $count) {
            $lines[] = '- Jenis ' . $type . ': ' . self::n((int) $count);
        }
        $lines[] = 'Pengaturan gateway dan penerima hanya dapat diubah oleh Admin.';
        return implode("\n", $lines);
    }

    // =====================================================================
    // Utilitas
    // =====================================================================

    /** @return array{receipts:int,issues:int,opnames:int,movements:int,po:int,opname_movements:int,receipt_items:int,issue_items:int,receipt_qty:float,issue_qty:float} */
    private static function documentCounts(): array
    {
        $row = self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM goods_receipts) AS receipts,
                    (SELECT COUNT(*) FROM goods_issues) AS issues,
                    (SELECT COUNT(*) FROM stock_opnames) AS opnames,
                    (SELECT COUNT(*) FROM stock_movements) AS movements,
                    (SELECT COUNT(*) FROM purchase_orders) AS po,
                    (SELECT COUNT(*) FROM stock_movements
                      WHERE movement_type IN (\'PENYESUAIAN_OPNAME\', \'KOREKSI\')) AS opname_movements,
                    (SELECT COUNT(*) FROM goods_receipt_items) AS receipt_items,
                    (SELECT COUNT(*) FROM goods_issue_items) AS issue_items,
                    (SELECT COALESCE(SUM(quantity), 0) FROM goods_receipt_items) AS receipt_qty,
                    (SELECT COALESCE(SUM(quantity), 0) FROM goods_issue_items) AS issue_qty'
        );

        return [
            'receipts' => (int) ($row['receipts'] ?? 0),
            'issues' => (int) ($row['issues'] ?? 0),
            'opnames' => (int) ($row['opnames'] ?? 0),
            'movements' => (int) ($row['movements'] ?? 0),
            'po' => (int) ($row['po'] ?? 0),
            'opname_movements' => (int) ($row['opname_movements'] ?? 0),
            'receipt_items' => (int) ($row['receipt_items'] ?? 0),
            'issue_items' => (int) ($row['issue_items'] ?? 0),
            'receipt_qty' => (float) ($row['receipt_qty'] ?? 0),
            'issue_qty' => (float) ($row['issue_qty'] ?? 0),
        ];
    }

    /** @return array{receipts:int,issues:int,opnames:int,movements:int,po:int,opname_movements:int,receipt_items:int,issue_items:int,receipt_qty:float,issue_qty:float} */
    private static function countsForPeriod(string $period): array
    {
        if ($period === 'semua') {
            return self::documentCounts();
        }

        [$from, $to] = self::range($period);
        $row = self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM goods_receipts WHERE receipt_date BETWEEN ? AND ?) AS receipts,
                    (SELECT COUNT(*) FROM goods_issues WHERE issue_date BETWEEN ? AND ?) AS issues,
                    (SELECT COUNT(*) FROM stock_opnames WHERE opname_date BETWEEN ? AND ?) AS opnames,
                    (SELECT COUNT(*) FROM stock_movements WHERE movement_date BETWEEN ? AND ?) AS movements,
                    (SELECT COUNT(*) FROM purchase_orders WHERE DATE(created_at) BETWEEN ? AND ?) AS po,
                    (SELECT COUNT(*) FROM stock_movements
                      WHERE movement_date BETWEEN ? AND ?
                        AND movement_type IN (\'PENYESUAIAN_OPNAME\', \'KOREKSI\')) AS opname_movements,
                    (SELECT COUNT(*) FROM goods_receipt_items gri
                       JOIN goods_receipts gr ON gr.id = gri.receipt_id
                      WHERE gr.receipt_date BETWEEN ? AND ?) AS receipt_items,
                    (SELECT COUNT(*) FROM goods_issue_items gii
                       JOIN goods_issues gi ON gi.id = gii.issue_id
                      WHERE gi.issue_date BETWEEN ? AND ?) AS issue_items,
                    (SELECT COALESCE(SUM(gri.quantity), 0) FROM goods_receipt_items gri
                       JOIN goods_receipts gr ON gr.id = gri.receipt_id
                      WHERE gr.receipt_date BETWEEN ? AND ?) AS receipt_qty,
                    (SELECT COALESCE(SUM(gii.quantity), 0) FROM goods_issue_items gii
                       JOIN goods_issues gi ON gi.id = gii.issue_id
                      WHERE gi.issue_date BETWEEN ? AND ?) AS issue_qty',
            [
                $from, $to, $from, $to, $from, $to, $from, $to, $from, $to,
                $from, $to, $from, $to, $from, $to, $from, $to, $from, $to,
            ]
        );

        return [
            'receipts' => (int) ($row['receipts'] ?? 0),
            'issues' => (int) ($row['issues'] ?? 0),
            'opnames' => (int) ($row['opnames'] ?? 0),
            'movements' => (int) ($row['movements'] ?? 0),
            'po' => (int) ($row['po'] ?? 0),
            'opname_movements' => (int) ($row['opname_movements'] ?? 0),
            'receipt_items' => (int) ($row['receipt_items'] ?? 0),
            'issue_items' => (int) ($row['issue_items'] ?? 0),
            'receipt_qty' => (float) ($row['receipt_qty'] ?? 0),
            'issue_qty' => (float) ($row['issue_qty'] ?? 0),
        ];
    }

    /** @return array{0:string,1:string} */
    private static function range(string $period): array
    {
        $today = date('Y-m-d');
        return match ($period) {
            'hari' => [$today, $today],
            'minggu' => [date('Y-m-d', strtotime('monday this week')), $today],
            'bulan' => [date('Y-m-01'), date('Y-m-t')],
            'tahun' => [date('Y-01-01'), date('Y-12-31')],
            default => ['1970-01-01', $today],
        };
    }

    private static function detectPeriod(string $q): string
    {
        if (preg_match('/\b(bulan ini|bulan sekarang|this month)\b/u', $q) === 1) {
            return 'bulan';
        }
        if (preg_match('/\b(minggu ini|pekan ini)\b/u', $q) === 1) {
            return 'minggu';
        }
        if (preg_match('/\b(tahun ini)\b/u', $q) === 1) {
            return 'tahun';
        }
        if (preg_match('/\b(hari ini|today)\b/u', $q) === 1) {
            return 'hari';
        }
        return 'semua';
    }

    private static function periodLabel(string $period): string
    {
        if ($period === 'semua') {
            return 'sejak data tersedia (seluruh periode)';
        }
        [$from, $to] = self::range($period);
        return self::PERIOD_LABELS[$period] . ' (' . $from . ' s.d. ' . $to . ')';
    }

    /**
     * Cari nama/kode sparepart yang disebut dalam pertanyaan.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function findSparepartsInQuestion(string $q): array
    {
        if (preg_match('/\b(stok|stock|minimum|minimal|batas|harga|sisa)\b/u', $q) !== 1) {
            return [];
        }

        $rows = self::fetchAll(
            'SELECT id, code, name, unit, stock, min_stock, is_active
               FROM spareparts
           ORDER BY is_active DESC, LENGTH(name) DESC'
        );

        $matches = [];
        foreach ($rows as $row) {
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            $code = mb_strtolower((string) ($row['code'] ?? ''));
            $hit = ($name !== '' && mb_strlen($name) >= 3 && str_contains($q, $name))
                || ($code !== '' && mb_strlen($code) >= 3 && str_contains($q, $code));
            if ($hit) {
                $matches[] = $row;
            }
        }

        return array_slice($matches, 0, 8);
    }

    /**
     * Cari sparepart yang disebut tanpa mensyaratkan kata "stok".
     * Dipakai untuk pertanyaan lokasi penyimpanan sparepart tertentu.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function findMentionedParts(string $q): array
    {
        $rows = self::fetchAll(
            'SELECT sp.id, sp.code, sp.name, sp.unit, sp.stock, sp.min_stock, sp.is_active,
                    sl.code AS loc_code, sl.name AS loc_name
               FROM spareparts sp
          LEFT JOIN storage_locations sl ON sl.id = sp.location_id
           ORDER BY sp.is_active DESC, LENGTH(sp.name) DESC'
        );

        $matches = [];
        foreach ($rows as $row) {
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            $code = mb_strtolower((string) ($row['code'] ?? ''));
            $hit = ($name !== '' && mb_strlen($name) >= 4 && str_contains($q, $name))
                || ($code !== '' && mb_strlen($code) >= 3 && str_contains($q, $code));
            if ($hit) {
                $matches[] = $row;
            }
        }

        return array_slice($matches, 0, 8);
    }

    private static function riskLabel(string $risk): string
    {
        return AiPrediction::RISK_LABELS[mb_strtolower($risk)] ?? ($risk !== '' ? $risk : 'tidak diketahui');
    }

    private static function sufficiencyLabel(string $value): string
    {
        return AiPrediction::SUFFICIENCY_LABELS[mb_strtolower($value)] ?? ($value !== '' ? $value : 'tidak diketahui');
    }

    private static function movementLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'STOK_AWAL' => 'stok awal',
            'MASUK' => 'barang masuk',
            'KELUAR' => 'barang keluar',
            'PENYESUAIAN_OPNAME' => 'penyesuaian opname',
            'PENYESUAIAN' => 'penyesuaian stok',
            'KOREKSI' => 'koreksi',
            default => strtolower($type),
        };
    }

    private static function poStatusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'DRAFT' => 'draft',
            'MENUNGGU_PERSETUJUAN' => 'menunggu persetujuan',
            'DISETUJUI' => 'disetujui',
            'DITOLAK' => 'ditolak',
            'DIPROSES_ADMIN' => 'diproses admin',
            'SEBAGIAN_DITERIMA' => 'diterima sebagian',
            'SELESAI_DITERIMA' => 'selesai diterima',
            'DIBATALKAN' => 'dibatalkan',
            default => strtolower($status),
        };
    }

    private static function n(int|float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private static function qty(float $value): string
    {
        $formatted = number_format($value, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');
        return $formatted === '' ? '0' : $formatted;
    }

    private static function money(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Sparepart::STATUS_HABIS => 'habis',
            Sparepart::STATUS_KRITIS => 'kritis',
            Sparepart::STATUS_MENIPIS => 'menipis',
            Sparepart::STATUS_TANPA_MINIMUM => 'tanpa minimum',
            default => 'aman',
        };
    }

    private static function roleLabel(string $role): string
    {
        return ChatbotService::ROLE_LABELS[$role] ?? $role;
    }
}
