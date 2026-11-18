<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\DocumentSequence;
use LogicException;

/**
 * Penomoran dokumen otomatis & unik (FR-06, FR-07, FR-10, Fase 6).
 *
 * Format: <PREFIX>-<YYYYMM>-<urut 4 digit>  →  GR-202610-0001, PO-202610-0001
 *
 * next() WAJIB dipanggil dari dalam transaksi database: counter disimpan
 * di tabel `document_sequences` dan dinaikkan dengan row lock, sehingga
 * dua pengguna yang menyimpan bersamaan tidak pernah mendapat nomor sama.
 */
final class DocumentNumberService
{
    public const PREFIX_RECEIPT = 'GR';
    public const PREFIX_ISSUE = 'GI';
    public const PREFIX_OPNAME = 'SO';
    public const PREFIX_ADJUSTMENT = 'ADJ';

    // Fase 6 — dokumen pembelian: permintaan reorder & Purchase Order.
    public const PREFIX_REORDER = 'RQ';
    public const PREFIX_PO = 'PO';

    /** @throws LogicException bila dipanggil di luar transaksi database */
    public static function next(string $prefix, ?string $businessDate = null): string
    {
        if (!Database::pdo()->inTransaction()) {
            throw new LogicException('Penomoran dokumen wajib dijalankan di dalam transaksi database.');
        }

        $time = $businessDate !== null && $businessDate !== ''
            ? (strtotime($businessDate) ?: time())
            : time();
        $period = date('Ym', $time);

        $number = DocumentSequence::nextNumber($prefix, $period);

        return sprintf('%s-%s-%04d', $prefix, $period, $number);
    }
}
