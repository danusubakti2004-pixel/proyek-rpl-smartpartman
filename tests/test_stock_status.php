<?php
declare(strict_types=1);

use App\Models\Sparepart;
use App\Services\SparepartService;

/**
 * Pengujian Fase 4 — status stok (FR-08, PRD §13.3).
 *
 * Aturan:
 *   aman          : stok > 150% stok minimum
 *   menipis       : 100% – 150% stok minimum
 *   kritis        : 0 < stok < stok minimum
 *   habis         : stok = 0
 *   tanpa_minimum : stok minimum tidak valid (<= 0) tapi stok masih ada
 *                   → pengecualian eksplisit, TIDAK pernah membagi nol.
 */

/** @return array<string,mixed> */
function t_status_row(float $stock, float $min): array
{
    return ['stock' => $stock, 'min_stock' => $min];
}

function test_stock_status_lima_kategori(): void
{
    t_eq(Sparepart::STATUS_HABIS, Sparepart::stockStatusOf(t_status_row(0, 10)), 'Stok 0 → Habis');
    t_eq(Sparepart::STATUS_KRITIS, Sparepart::stockStatusOf(t_status_row(5, 10)), 'Stok 5 < min 10 → Kritis');
    t_eq(Sparepart::STATUS_MENIPIS, Sparepart::stockStatusOf(t_status_row(10, 10)), 'Stok 10 = min 10 (100%) → Menipis');
    t_eq(Sparepart::STATUS_MENIPIS, Sparepart::stockStatusOf(t_status_row(15, 10)), 'Stok 15 = 150% min → Menipis');
    t_eq(Sparepart::STATUS_AMAN, Sparepart::stockStatusOf(t_status_row(16, 10)), 'Stok 16 > 150% min → Aman');
    t_eq(Sparepart::STATUS_TANPA_MINIMUM, Sparepart::stockStatusOf(t_status_row(5, 0)), 'Min 0 & stok ada → pengecualian eksplisit');
}

function test_stock_status_pengecualian_stok_minimum_tidak_dibagi_nol(): void
{
    // Tidak ada pembagian dengan nol dan tidak ada warning PHP.
    $status = Sparepart::stockStatusOf(t_status_row(7, 0));
    t_eq(Sparepart::STATUS_TANPA_MINIMUM, $status, 'Stok minimum 0 ditangani tanpa pembagian nol');

    t_eq(null, Sparepart::stockPercentOf(t_status_row(7, 0)), 'Persentase null bila stok minimum tidak valid');
    t_eq(150.0, Sparepart::stockPercentOf(t_status_row(15, 10)), 'Persentase = 150% saat stok 1,5× minimum');
    t_eq(40.0, Sparepart::stockPercentOf(t_status_row(2, 5)), 'Persentase = 40% saat stok di bawah minimum');

    // Stok 0 dengan min 0 tetap "habis" (bukan tanpa_minimum).
    t_eq(Sparepart::STATUS_HABIS, Sparepart::stockStatusOf(t_status_row(0, 0)), 'Stok 0 → Habis walau minimum 0');
}

function test_stock_status_agregat_dan_label(): void
{
    $stats = Sparepart::countByStatus();
    foreach (['total', 'aktif', 'nonaktif', 'habis', 'kritis', 'menipis', 'aman', 'tanpa_minimum'] as $key) {
        t_true(array_key_exists($key, $stats), "Statistik status memuat kunci '{$key}'");
    }
    t_eq(
        $stats['total'],
        $stats['aktif'] + $stats['nonaktif'],
        'Total sparepart = aktif + nonaktif'
    );

    t_eq(['Habis', 'danger'], SparepartService::statusLabel(Sparepart::STATUS_HABIS), 'Label Habis berwarna danger');
    t_eq(['Kritis', 'danger'], SparepartService::statusLabel(Sparepart::STATUS_KRITIS), 'Label Kritis berwarna danger');
    t_eq(['Menipis', 'warning'], SparepartService::statusLabel(Sparepart::STATUS_MENIPIS), 'Label Menipis berwarna warning');
    t_eq(['Aman', 'success'], SparepartService::statusLabel(Sparepart::STATUS_AMAN), 'Label Aman berwarna success');
    t_eq(
        ['Stok Minimum Belum Diatur', 'secondary'],
        SparepartService::statusLabel(Sparepart::STATUS_TANPA_MINIMUM),
        'Label pengecualian minimum dijelaskan, bukan disamarkan sebagai "aman"'
    );
}

function test_stock_status_perhatian_dashboard(): void
{
    $categoryId = t_make_category('Kategori Status Stok');

    $habisId = t_make_sparepart($categoryId, ['stock' => 0, 'min_stock' => 5]);
    $kritisId = t_make_sparepart($categoryId, ['stock' => 2, 'min_stock' => 10]);
    $menipisId = t_make_sparepart($categoryId, ['stock' => 12, 'min_stock' => 10]);
    $amanId = t_make_sparepart($categoryId, ['stock' => 100, 'min_stock' => 10]);
    $tanpaMinId = t_make_sparepart($categoryId, ['stock' => 4, 'min_stock' => 0]);
    t_make_sparepart($categoryId, ['stock' => 0, 'min_stock' => 10, 'is_active' => 0]);

    // Daftar "perlu perhatian" (maks 100 baris) untuk notifikasi dashboard.
    // Keanggotaan habis/kritis diverifikasi lewat filter status yang TIDAK
    // dibatasi jumlah, agar pengujian tetap sahih walau basis data uji terus
    // berkembang antar-jalankan.
    $rows = Sparepart::needingAttention(100);
    t_true(count($rows) <= 100, 'Daftar "perlu perhatian" dibatasi maksimum 100 baris');

    $habisIds = array_map(static fn (array $r): int => (int) $r['id'], SparepartService::all(['stock_status' => 'habis']));
    $kritisIds = array_map(static fn (array $r): int => (int) $r['id'], SparepartService::all(['stock_status' => 'kritis']));

    t_true(in_array($habisId, $habisIds, true), 'Sparepart habis terdeteksi berstatus habis');
    t_true(in_array($kritisId, $kritisIds, true), 'Sparepart kritis terdeteksi berstatus kritis');

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    t_true(!in_array($amanId, $ids, true), 'Sparepart aman TIDAK muncul di daftar perlu perhatian');

    $semuaSesuai = true;
    foreach ($rows as $row) {
        $sesuai = (int) $row['is_active'] === 1
            && ((float) $row['stock'] <= 0 || (float) $row['min_stock'] <= 0 || (float) $row['stock'] <= (float) $row['min_stock'] * 1.5);
        if (!$sesuai) {
            $semuaSesuai = false;
            break;
        }
    }
    t_true($semuaSesuai, 'Seluruh baris "perlu perhatian" aktif dan berstatus habis/kritis/menipis/tanpa minimum');

    // Filter status stok (dipakai halaman master) mengembalikan status yang tepat
    // tanpa batas jumlah, sehingga menipis & tanpa minimum dapat diverifikasi.
    $menipisIds = array_map(static fn (array $r): int => (int) $r['id'], SparepartService::all(['stock_status' => 'menipis']));
    $tanpaMinIds = array_map(static fn (array $r): int => (int) $r['id'], SparepartService::all(['stock_status' => 'tanpa_minimum']));
    $amanIds = array_map(static fn (array $r): int => (int) $r['id'], SparepartService::all(['stock_status' => 'aman']));

    t_true(in_array($menipisId, $menipisIds, true), 'Filter status "menipis" menemukan sparepart 100%–150% minimum');
    t_true(in_array($tanpaMinId, $tanpaMinIds, true), 'Filter status "tanpa_minimum" menemukan data yang perlu diperbaiki');
    t_true(!in_array($amanId, $menipisIds, true), 'Sparepart aman tidak masuk filter "menipis"');
    t_true(in_array($amanId, $amanIds, true), 'Sparepart aman masuk filter "aman"');
    t_true(!in_array($amanId, $tanpaMinIds, true), 'Sparepart aman tidak masuk filter "tanpa_minimum"');
}
