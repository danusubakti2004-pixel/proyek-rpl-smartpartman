<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Analytics;

/**
 * Notifikasi dashboard (Fase 5, tautan modul Fase 6).
 *
 * Tahap ini notifikasi HANYA tampil di dashboard — belum ada email,
 * WhatsApp, atau pusat notifikasi terpisah. Isi disesuaikan hak akses:
 * - Semua role : peringatan stok (mereka boleh melihat data stok).
 * - Partman    : status permintaan reorder yang ia ajukan.
 * - Kepala     : permintaan reorder / PO yang menunggu persetujuan.
 * - Admin      : PO yang menunggu administrasi dan status pembayaran.
 *
 * Notifikasi bersifat INFORMATIF dan menautkan ke halaman modulnya:
 * reorder → /reorder, pembelian → /pembelian (dibangun pada Fase 6).
 */
final class NotificationService
{
    /**
     * @return array<int,array{level:string,title:string,message:string,url:?string,count:int}>
     */
    public static function forRole(string $role): array
    {
        $out = self::stockNotifications();

        if ($role === 'partman') {
            $reorder = Analytics::reorderStatusCounts();
            $waiting = (int) ($reorder['MENUNGGU_PERSETUJUAN'] ?? 0);
            $approved = (int) ($reorder['DISETUJUI'] ?? 0);
            $rejected = (int) ($reorder['DITOLAK'] ?? 0);

            if ($waiting > 0) {
                $out[] = [
                    'level'   => 'info',
                    'title'   => 'Permintaan reorder menunggu persetujuan',
                    'message' => $waiting . ' permintaan reorder masih menunggu Kepala Bengkel.',
                    'url'     => base_url('reorder?status=MENUNGGU_PERSETUJUAN'),
                    'count'   => $waiting,
                ];
            }
            if ($approved > 0) {
                $out[] = [
                    'level'   => 'info',
                    'title'   => 'Reorder disetujui',
                    'message' => $approved . ' permintaan reorder disetujui — buat Purchase Order lalu catat penerimaan barang.',
                    'url'     => base_url('reorder?status=DISETUJUI'),
                    'count'   => $approved,
                ];
            }
            if ($rejected > 0) {
                $out[] = [
                    'level'   => 'warning',
                    'title'   => 'Reorder ditolak',
                    'message' => $rejected . ' permintaan reorder ditolak. Periksa alasan penolakannya.',
                    'url'     => base_url('reorder?status=DITOLAK'),
                    'count'   => $rejected,
                ];
            }
        }

        if ($role === 'kepala_bengkel') {
            $reorder = Analytics::reorderStatusCounts();
            $waitingReorder = (int) ($reorder['MENUNGGU_PERSETUJUAN'] ?? 0);
            if ($waitingReorder > 0) {
                $out[] = [
                    'level'   => 'warning',
                    'title'   => 'Persetujuan reorder',
                    'message' => $waitingReorder . ' permintaan reorder menunggu persetujuan Anda.',
                    'url'     => base_url('reorder?status=MENUNGGU_PERSETUJUAN'),
                    'count'   => $waitingReorder,
                ];
            }

            $po = Analytics::poStatusCounts();
            $waitingPo = (int) ($po['MENUNGGU_PERSETUJUAN']['count'] ?? 0);
            if ($waitingPo > 0) {
                $out[] = [
                    'level'   => 'warning',
                    'title'   => 'Persetujuan Purchase Order',
                    'message' => $waitingPo . ' PO menunggu persetujuan Anda.',
                    'url'     => base_url('pembelian?status=MENUNGGU_PERSETUJUAN'),
                    'count'   => $waitingPo,
                ];
            }
        }

        if ($role === 'admin') {
            $po = Analytics::poStatusCounts();
            $waitingAdmin = (int) ($po['DISETUJUI']['count'] ?? 0) + (int) ($po['DIPROSES_ADMIN']['count'] ?? 0);
            if ($waitingAdmin > 0) {
                $out[] = [
                    'level'   => 'info',
                    'title'   => 'PO menunggu administrasi',
                    'message' => $waitingAdmin . ' PO perlu diproses administrasinya.',
                    'url'     => base_url('pembelian?status=DISETUJUI'),
                    'count'   => $waitingAdmin,
                ];
            }

            $payment = Analytics::poPaymentCounts();
            $unpaid = (int) ($payment['BELUM_DIBAYAR']['count'] ?? 0);
            $partial = (int) ($payment['DIBAYAR_SEBAGIAN']['count'] ?? 0);
            if ($unpaid + $partial > 0) {
                $out[] = [
                    'level'   => 'warning',
                    'title'   => 'Pembayaran PO belum lunas',
                    'message' => $unpaid . ' PO belum dibayar dan ' . $partial . ' PO dibayar sebagian.',
                    'url'     => base_url('pembelian?bayar=BELUM_DIBAYAR'),
                    'count'   => $unpaid + $partial,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{level:string,title:string,message:string,url:?string,count:int}>
     */
    private static function stockNotifications(): array
    {
        $stats = Analytics::stockStatusCounts(true);
        $out = [];

        if ((int) $stats['habis'] > 0) {
            $out[] = [
                'level'   => 'danger',
                'title'   => 'Stok habis',
                'message' => $stats['habis'] . ' sparepart aktif memiliki stok 0.',
                'url'     => base_url('laporan/stok_minimum?status=habis'),
                'count'   => (int) $stats['habis'],
            ];
        }
        if ((int) $stats['kritis'] > 0) {
            $out[] = [
                'level'   => 'danger',
                'title'   => 'Stok kritis',
                'message' => $stats['kritis'] . ' sparepart aktif berada di bawah stok minimum.',
                'url'     => base_url('laporan/stok_minimum?status=kritis'),
                'count'   => (int) $stats['kritis'],
            ];
        }
        if ((int) $stats['menipis'] > 0) {
            $out[] = [
                'level'   => 'warning',
                'title'   => 'Stok menipis',
                'message' => $stats['menipis'] . ' sparepart aktif mendekati batas stok minimum.',
                'url'     => base_url('laporan/stok_minimum?status=menipis'),
                'count'   => (int) $stats['menipis'],
            ];
        }
        if ((int) $stats['tanpa_minimum'] > 0) {
            $out[] = [
                'level'   => 'info',
                'title'   => 'Stok minimum belum diatur',
                'message' => $stats['tanpa_minimum'] . ' sparepart aktif belum memiliki stok minimum yang valid.',
                'url'     => base_url('laporan/stok_minimum?status=tanpa_minimum'),
                'count'   => (int) $stats['tanpa_minimum'],
            ];
        }

        return $out;
    }
}
