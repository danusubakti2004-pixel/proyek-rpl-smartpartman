<?php
declare(strict_types=1);

/**
 * Daftar route SmartPartMan + middleware (RBAC di level server).
 *
 * ATURAN (PRD §10.1, aturan kerja #6): SETIAP route WAJIB menyebut middleware.
 * Menyembunyikan tombol BUKAN pengamanan — otorisasi diperiksa di server,
 * sehingga mengetik URL langsung tetap ditolak (403).
 *
 * Bentuk middleware:
 *   [AuthMiddleware::class]                                   → wajib login
 *   [AuthMiddleware::class, [RoleMiddleware::class, 'admin']]  → wajib login + Admin
 *   [AuthMiddleware::class, [RoleMiddleware::class, 'a', 'b']] → wajib login + salah satu role
 *
 * Matriks hak akses PRD §6.4 yang sudah relevan:
 *   - Dashboard       : semua role (isi berbeda per role)
 *   - Pengguna        : hanya Admin
 *   - Audit Log       : hanya Admin
 *   - Password sendiri: hanya Admin (Partman & Kepala Bengkel tidak boleh)
 *   - Master sparepart/Supplier/Kategori/Lokasi (Fase 3):
 *       melihat  : semua role
 *       mengelola (tambah/ubah/aktif-nonaktif/impor/stok awal): HANYA Partman
 *     PRD §6.3: "Admin tidak mengubah ... data master operasional."
 */

use App\Controllers\ApiMonitoringController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\CategoryController;
use App\Controllers\ChatbotController;
use App\Controllers\CorrectionController;
use App\Controllers\GoodsIssueController;
use App\Controllers\GoodsReceiptController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\ImportController;
use App\Controllers\InstallController;
use App\Controllers\LocationController;
use App\Controllers\ProfileController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\PredictionController;
use App\Controllers\ReorderController;
use App\Controllers\ReportController;
use App\Controllers\SettingController;
use App\Controllers\SparepartController;
use App\Controllers\StockHistoryController;
use App\Controllers\StockAdjustmentController;
use App\Controllers\StockInitialController;
use App\Controllers\StockOpnameController;
use App\Controllers\SupplierController;
use App\Controllers\UserController;
use App\Controllers\WhatsAppController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {

    /** Middleware khusus Admin (wajib login + role admin). */
    $adminOnly = [AuthMiddleware::class, [RoleMiddleware::class, 'admin']];

    /** Fase 3 — data master: menulis HANYA Partman, membaca semua role. */
    $partmanOnly = [AuthMiddleware::class, [RoleMiddleware::class, 'partman']];
    $anyRole = [AuthMiddleware::class];

    /** Fase 6 — keputusan reorder & PO: hanya Kepala Bengkel. */
    $kepalaOnly = [AuthMiddleware::class, [RoleMiddleware::class, 'kepala_bengkel']];

    /** Fase 6 — pembatalan PO: Partman (draft miliknya) atau Admin. */
    $partmanOrAdmin = [AuthMiddleware::class, [RoleMiddleware::class, 'partman', 'admin']];

    // --- Health check: publik (status saja); detail teknis hanya untuk Admin login ---
    $router->get('/health', [HealthController::class, 'index']);

    // --- Instalasi (hanya sebelum admin pertama dibuat; terkunci sesudahnya) ---
    $router->get('/install', [InstallController::class, 'index']);
    $router->post('/install', [InstallController::class, 'store']);

    // --- Autentikasi (GuestMiddleware: yang sudah login diarahkan ke dashboard) ---
    $router->get('/login', [AuthController::class, 'showLogin'], [GuestMiddleware::class]);
    $router->post('/login', [AuthController::class, 'login'], [GuestMiddleware::class]);
    $router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);

    // --- Dashboard: semua role (isi berbeda per role — Fase 5) ---
    $router->get('/', [HomeController::class, 'root']);
    $router->get('/dashboard', [HomeController::class, 'index'], [AuthMiddleware::class]);

    // ==================================================================
    // FASE 5 — DASHBOARD, LAPORAN, CETAK, EKSPOR, PENGATURAN
    // Laporan & cetak: semua role (baca-saja). Pengaturan: HANYA Admin.
    // ==================================================================
    $router->get('/laporan', [ReportController::class, 'index'], $anyRole);
    // Rute dokumen PO didaftarkan sebelum pola /laporan/{jenis}.
    $router->get('/laporan/po/{id}/cetak', [ReportController::class, 'printPo'], $anyRole);
    $router->get('/laporan/po/{id}/pdf', [ReportController::class, 'pdfPo'], $anyRole);
    $router->get('/laporan/{jenis}/cetak', [ReportController::class, 'print'], $anyRole);
    $router->get('/laporan/{jenis}/pdf', [ReportController::class, 'pdf'], $anyRole);
    $router->get('/laporan/{jenis}/excel', [ReportController::class, 'excel'], $anyRole);
    $router->get('/laporan/{jenis}', [ReportController::class, 'show'], $anyRole);

    // --- Pengaturan aplikasi (identitas bengkel & parameter laporan) — Admin ---
    $router->get('/pengaturan', [SettingController::class, 'index'], $adminOnly);
    $router->post('/pengaturan', [SettingController::class, 'update'], $adminOnly);
    // Logo identitas: semua role yang login boleh melihat (dipakai kop dokumen).
    $router->get('/pengaturan/logo', [SettingController::class, 'logo'], $anyRole);

    // ==================================================================
    // FASE 9 — BACKUP & RESTORE DATABASE, KEAMANAN, AUDIT LOG
    // Backup/restore/unduh: HANYA Admin. Berkas SQL tidak dapat diakses
    // langsung dari web (storage/backups di luar web root).
    // ==================================================================
    $router->get('/pengaturan/backup', [BackupController::class, 'index'], $adminOnly);
    $router->post('/pengaturan/backup', [BackupController::class, 'create'], $adminOnly);
    $router->get('/pengaturan/backup/{id}/unduh', [BackupController::class, 'download'], $adminOnly);
    $router->post('/pengaturan/backup/{id}/restore', [BackupController::class, 'restore'], $adminOnly);

    // ==================================================================
    // FASE 8 — CHATBOT GEMINI
    // Chat: semua role (konteks disaring server sesuai role).
    // Pengaturan & uji koneksi: HANYA Admin.
    // ==================================================================
    $router->get('/chatbot', [ChatbotController::class, 'index'], $anyRole);
    $router->post('/chatbot/kirim', [ChatbotController::class, 'send'], $anyRole);
    $router->post('/chatbot/bersihkan', [ChatbotController::class, 'clear'], $anyRole);
    // Endpoint JSON untuk Floating AI Assistant (tanpa redirect; CSRF tetap wajib).
    $router->post('/chatbot/api', [ChatbotController::class, 'apiSend'], $anyRole);
    $router->post('/chatbot/api/clear', [ChatbotController::class, 'apiClear'], $anyRole);

    $router->get('/pengaturan/chatbot', [ChatbotController::class, 'settings'], $adminOnly);
    $router->post('/pengaturan/chatbot', [ChatbotController::class, 'saveSettings'], $adminOnly);
    $router->post('/pengaturan/chatbot/uji', [ChatbotController::class, 'testConnection'], $adminOnly);

    // --- Monitoring Penggunaan API (Admin) ---
    $router->get('/monitoring/api', [ApiMonitoringController::class, 'index'], $adminOnly);

    // --- Pengaturan Notifikasi WhatsApp (Admin) ---
    $router->get('/pengaturan/whatsapp', [WhatsAppController::class, 'settings'], $adminOnly);
    $router->post('/pengaturan/whatsapp', [WhatsAppController::class, 'save'], $adminOnly);
    $router->post('/pengaturan/whatsapp/uji', [WhatsAppController::class, 'test'], $adminOnly);
    $router->get('/pengaturan/whatsapp/riwayat', [WhatsAppController::class, 'history'], $adminOnly);
    $router->get('/pengaturan/whatsapp/notifikasi', [WhatsAppController::class, 'recipients'], $adminOnly);
    $router->post('/pengaturan/whatsapp/scheduler', [WhatsAppController::class, 'runScheduler'], $adminOnly);
    $router->get('/pengaturan/whatsapp/penerima/{id}', [WhatsAppController::class, 'recipientEdit'], $adminOnly);
    $router->post('/pengaturan/whatsapp/penerima/{id}', [WhatsAppController::class, 'recipientSave'], $adminOnly);
    $router->post('/pengaturan/whatsapp/penerima/{id}/kirim-otp', [WhatsAppController::class, 'sendOtp'], $adminOnly);
    $router->post('/pengaturan/whatsapp/penerima/{id}/verifikasi-otp', [WhatsAppController::class, 'verifyOtp'], $adminOnly);
    $router->get('/pengaturan/whatsapp/otp', [WhatsAppController::class, 'selfOtp'], $adminOnly);

    // --- Profil: ubah password SENDIRI — Admin saja (PRD §6.4) ---
    $router->get('/profil/password', [ProfileController::class, 'showPasswordForm'], $adminOnly);
    $router->post('/profil/password', [ProfileController::class, 'updatePassword'], $adminOnly);

    // --- Manajemen pengguna — Admin saja (PRD §6.3) ---
    $router->get('/pengguna', [UserController::class, 'index'], $adminOnly);
    $router->get('/pengguna/tambah', [UserController::class, 'createForm'], $adminOnly);
    $router->post('/pengguna', [UserController::class, 'store'], $adminOnly);
    $router->get('/pengguna/{id}/ubah', [UserController::class, 'editForm'], $adminOnly);
    $router->post('/pengguna/{id}', [UserController::class, 'update'], $adminOnly);
    $router->post('/pengguna/{id}/status', [UserController::class, 'toggleStatus'], $adminOnly);
    $router->get('/pengguna/{id}/reset-password', [UserController::class, 'resetPasswordForm'], $adminOnly);
    $router->post('/pengguna/{id}/reset-password', [UserController::class, 'resetPassword'], $adminOnly);
    $router->post('/pengguna/{id}/unlock', [UserController::class, 'unlock'], $adminOnly);

    // --- Audit Log — Admin saja, hanya baca (FR-20) ---
    // Rute ekspor didaftarkan SEBELUM /audit-log/{id} agar tidak terbaca sebagai ID.
    $router->get('/audit-log', [AuditLogController::class, 'index'], $adminOnly);
    $router->get('/audit-log/export', [AuditLogController::class, 'export'], $adminOnly);
    $router->get('/audit-log/{id}', [AuditLogController::class, 'show'], $adminOnly);

    // ==================================================================
    // FASE 3 — MASTER DATA INVENTORY (PRD §6.4, FR-03/04/05/09/11/12)
    // Membaca : semua role yang sudah login.
    // Menulis : HANYA Partman (Admin & Kepala Bengkel tidak mengubah data
    //           master operasional — PRD §6.3).
    // ==================================================================

    // --- Kategori sparepart (FR-12) ---
    $router->get('/kategori', [CategoryController::class, 'index'], $anyRole);
    $router->get('/kategori/tambah', [CategoryController::class, 'createForm'], $partmanOnly);
    $router->post('/kategori', [CategoryController::class, 'store'], $partmanOnly);
    $router->get('/kategori/{id}/ubah', [CategoryController::class, 'editForm'], $partmanOnly);
    $router->post('/kategori/{id}', [CategoryController::class, 'update'], $partmanOnly);
    $router->post('/kategori/{id}/status', [CategoryController::class, 'toggleStatus'], $partmanOnly);

    // --- Supplier (FR-11) ---
    $router->get('/supplier', [SupplierController::class, 'index'], $anyRole);
    $router->get('/supplier/tambah', [SupplierController::class, 'createForm'], $partmanOnly);
    $router->post('/supplier', [SupplierController::class, 'store'], $partmanOnly);
    $router->get('/supplier/{id}/ubah', [SupplierController::class, 'editForm'], $partmanOnly);
    $router->post('/supplier/{id}', [SupplierController::class, 'update'], $partmanOnly);
    $router->post('/supplier/{id}/status', [SupplierController::class, 'toggleStatus'], $partmanOnly);

    // --- Lokasi penyimpanan (FR-09) ---
    $router->get('/lokasi', [LocationController::class, 'index'], $anyRole);
    $router->get('/lokasi/tambah', [LocationController::class, 'createForm'], $partmanOnly);
    $router->post('/lokasi', [LocationController::class, 'store'], $partmanOnly);
    $router->get('/lokasi/{id}/ubah', [LocationController::class, 'editForm'], $partmanOnly);
    $router->post('/lokasi/{id}', [LocationController::class, 'update'], $partmanOnly);
    $router->post('/lokasi/{id}/status', [LocationController::class, 'toggleStatus'], $partmanOnly);

    // --- Master sparepart (FR-03) ---
    // CATATAN URUTAN: '/sparepart/tambah' didaftarkan SEBELUM '/sparepart/{id}'
    // agar tidak terbaca sebagai id.
    $router->get('/sparepart', [SparepartController::class, 'index'], $anyRole);
    $router->get('/sparepart/tambah', [SparepartController::class, 'createForm'], $partmanOnly);
    $router->post('/sparepart', [SparepartController::class, 'store'], $partmanOnly);
    $router->get('/sparepart/{id}/ubah', [SparepartController::class, 'editForm'], $partmanOnly);
    $router->post('/sparepart/{id}', [SparepartController::class, 'update'], $partmanOnly);
    $router->post('/sparepart/{id}/status', [SparepartController::class, 'toggleStatus'], $partmanOnly);
    $router->get('/sparepart/{id}', [SparepartController::class, 'show'], $anyRole);

    // --- Stok awal manual (FR-05) — hanya Partman, tercatat sebagai ledger ---
    $router->get('/sparepart/{id}/stok-awal', [StockInitialController::class, 'form'], $partmanOnly);
    $router->post('/sparepart/{id}/stok-awal', [StockInitialController::class, 'store'], $partmanOnly);

    // --- Impor .csv/.xlsx: pratinjau lalu komit (FR-04, FR-05) ---
    $router->get('/import', [ImportController::class, 'index'], $partmanOnly);
    $router->get('/import/template', [ImportController::class, 'template'], $partmanOnly);
    $router->post('/import/pratinjau', [ImportController::class, 'preview'], $partmanOnly);
    $router->post('/import/komit', [ImportController::class, 'commit'], $partmanOnly);

    // ==================================================================
    // FASE 4 — TRANSAKSI & PENGELOLAAN STOK
    //        (PRD §6.4, FR-06/07/08/10 + Aturan Bisnis 1-8, 10-12)
    // Membaca : semua role (Kepala Bengkel memantau, Admin melihat).
    // Menulis : HANYA Partman.
    // CATATAN URUTAN: route statis (…/tambah, …/mulai) didaftarkan SEBELUM
    // route berparameter (…/{id}) agar tidak terbaca sebagai id.
    // ==================================================================

    // --- Barang masuk / penerimaan (FR-06) ---
    $router->get('/transaksi/masuk', [GoodsReceiptController::class, 'index'], $anyRole);
    $router->get('/transaksi/masuk/tambah', [GoodsReceiptController::class, 'createForm'], $partmanOnly);
    $router->post('/transaksi/masuk', [GoodsReceiptController::class, 'store'], $partmanOnly);
    $router->get('/transaksi/masuk/{id}/bukti', [GoodsReceiptController::class, 'attachment'], $anyRole);
    $router->get('/transaksi/masuk/{id}/cetak', [GoodsReceiptController::class, 'print'], $anyRole);
    $router->get('/transaksi/masuk/{id}', [GoodsReceiptController::class, 'show'], $anyRole);

    // --- Penerimaan berdasarkan PO (Admin) �?" alias rute ---
    $router->get('/transaksi/masuk/po/{id}', [GoodsReceiptController::class, 'createFormPo'], $adminOnly);
    $router->post('/transaksi/masuk/po/{id}', [GoodsReceiptController::class, 'storePo'], $adminOnly);
    $router->get('/transaksi/masuk/po/{id}/terima', [GoodsReceiptController::class, 'createFormPo'], $adminOnly);
    $router->post('/transaksi/masuk/po/{id}/terima', [GoodsReceiptController::class, 'storePo'], $adminOnly);

    // --- Barang keluar / pengeluaran (FR-07) ---
    $router->get('/transaksi/keluar', [GoodsIssueController::class, 'index'], $anyRole);
    $router->get('/transaksi/keluar/tambah', [GoodsIssueController::class, 'createForm'], $partmanOnly);
    $router->post('/transaksi/keluar', [GoodsIssueController::class, 'store'], $partmanOnly);
    $router->get('/transaksi/keluar/{id}/cetak', [GoodsIssueController::class, 'print'], $anyRole);
    $router->get('/transaksi/keluar/{id}', [GoodsIssueController::class, 'show'], $anyRole);

    // --- Stock opname (FR-10): stok berubah HANYA setelah konfirmasi Partman ---
    $router->get('/transaksi/opname', [StockOpnameController::class, 'index'], $anyRole);
    $router->get('/transaksi/opname/mulai', [StockOpnameController::class, 'createForm'], $partmanOnly);
    $router->post('/transaksi/opname', [StockOpnameController::class, 'store'], $partmanOnly);
    $router->get('/transaksi/opname/{id}', [StockOpnameController::class, 'show'], $anyRole);
    $router->post('/transaksi/opname/{id}/simpan', [StockOpnameController::class, 'saveInputs'], $partmanOnly);
    $router->post('/transaksi/opname/{id}/konfirmasi', [StockOpnameController::class, 'confirm'], $partmanOnly);

    // --- Riwayat pergerakan stok (FR-08) — hanya baca ---
    $router->get('/transaksi/riwayat', [StockHistoryController::class, 'index'], $anyRole);

    // --- Koreksi transaksi (PRD §7.4, Aturan 8) — Partman saja ---
    $router->get('/transaksi/koreksi', [CorrectionController::class, 'index'], $partmanOnly);
    $router->get('/transaksi/koreksi/{id}', [CorrectionController::class, 'form'], $partmanOnly);
    $router->post('/transaksi/koreksi/{id}', [CorrectionController::class, 'store'], $partmanOnly);

    // --- Penyesuaian stok (Partman) ---
    $router->get('/transaksi/penyesuaian', [StockAdjustmentController::class, 'index'], $anyRole);
    $router->get('/transaksi/penyesuaian/tambah', [StockAdjustmentController::class, 'createForm'], $partmanOnly);
    $router->post('/transaksi/penyesuaian', [StockAdjustmentController::class, 'store'], $partmanOnly);
    $router->get('/transaksi/penyesuaian/{id}', [StockAdjustmentController::class, 'show'], $anyRole);

    // alias rute
    $router->get('/stok/penyesuaian', [StockAdjustmentController::class, 'index'], $anyRole);
    $router->get('/stok/penyesuaian/tambah', [StockAdjustmentController::class, 'createForm'], $partmanOnly);
    $router->post('/stok/penyesuaian', [StockAdjustmentController::class, 'store'], $partmanOnly);
    $router->get('/stok/penyesuaian/{id}', [StockAdjustmentController::class, 'show'], $anyRole);
    $router->get('/penyesuaian-stok', [StockAdjustmentController::class, 'index'], $anyRole);
    $router->get('/penyesuaian-stok/tambah', [StockAdjustmentController::class, 'createForm'], $partmanOnly);
    $router->post('/penyesuaian-stok', [StockAdjustmentController::class, 'store'], $partmanOnly);
    $router->get('/penyesuaian-stok/{id}', [StockAdjustmentController::class, 'show'], $anyRole);

    // ==================================================================
    // FASE 6 — REORDER & PEMBELIAN (FR-15, FR-16, Aturan Bisnis 13-17)
    //
    // Reorder : semua role membaca; Partman mengajukan/mengubah jumlah
    //           (alasan wajib bila berbeda dari saran); Kepala Bengkel
    //           menyetujui/menolak (alasan wajib saat menolak).
    // PO      : semua role membaca & mencetak; Partman membuat draft,
    //           mengubah, mengajukan, membatalkan draft miliknya;
    //           Kepala Bengkel menyetujui/menolak; Admin memproses
    //           administrasi, status pembayaran, dan membatalkan PO
    //           yang sudah disetujui (belum ada penerimaan).
    //
    // Persetujuan reorder/PO TIDAK menambah stok — stok hanya bertambah
    // lewat penerimaan barang (menu Barang Masuk), bisa bertahap.
    // CATATAN URUTAN: route statis didaftarkan sebelum route {id}.
    // ==================================================================

    // --- Permintaan reorder ---
    $router->get('/reorder', [ReorderController::class, 'index'], $anyRole);
    $router->get('/reorder/saran', [ReorderController::class, 'suggestion'], $partmanOnly);
    $router->get('/reorder/tambah', [ReorderController::class, 'createForm'], $partmanOnly);
    $router->post('/reorder', [ReorderController::class, 'store'], $partmanOnly);
    $router->get('/reorder/{id}', [ReorderController::class, 'show'], $anyRole);
    $router->get('/reorder/{id}/ubah', [ReorderController::class, 'editForm'], $partmanOnly);
    $router->post('/reorder/{id}', [ReorderController::class, 'update'], $partmanOnly);
    $router->post('/reorder/{id}/setujui', [ReorderController::class, 'approve'], $kepalaOnly);
    $router->post('/reorder/{id}/tolak', [ReorderController::class, 'reject'], $kepalaOnly);

    // --- Purchase Order (cetak/PDF memakai rute /laporan/po/{id}/… yang sudah ada) ---
    $router->get('/pembelian', [PurchaseOrderController::class, 'index'], $anyRole);
    $router->get('/pembelian/rekomendasi-supplier', [PurchaseOrderController::class, 'recommendations'], $partmanOnly);
    $router->get('/pembelian/tambah', [PurchaseOrderController::class, 'createForm'], $partmanOnly);
    $router->post('/pembelian', [PurchaseOrderController::class, 'store'], $partmanOnly);
    $router->get('/pembelian/{id}', [PurchaseOrderController::class, 'show'], $anyRole);
    $router->get('/pembelian/{id}/ubah', [PurchaseOrderController::class, 'editForm'], $partmanOnly);
    $router->post('/pembelian/{id}', [PurchaseOrderController::class, 'update'], $partmanOnly);
    $router->post('/pembelian/{id}/ajukan', [PurchaseOrderController::class, 'submit'], $partmanOnly);
    $router->post('/pembelian/{id}/setujui', [PurchaseOrderController::class, 'approve'], $kepalaOnly);
    $router->post('/pembelian/{id}/tolak', [PurchaseOrderController::class, 'reject'], $kepalaOnly);
    $router->post('/pembelian/{id}/proses', [PurchaseOrderController::class, 'process'], $adminOnly);
    $router->post('/pembelian/{id}/pembayaran', [PurchaseOrderController::class, 'payment'], $adminOnly);
    $router->post('/pembelian/{id}/batal', [PurchaseOrderController::class, 'cancel'], $partmanOrAdmin);

    // --- Penerimaan barang berdasarkan PO (Admin) �?" Fase revisi ---
    $router->get('/pembelian/{id}/terima', [GoodsReceiptController::class, 'createFormPo'], $adminOnly);
    $router->post('/pembelian/{id}/terima', [GoodsReceiptController::class, 'storePo'], $adminOnly);
    $router->post('/pembelian/{id}/terima-otomatis', [PurchaseOrderController::class, 'autoReceive'], $adminOnly);
    $router->get('/pembelian/{id}/penerimaan', [GoodsReceiptController::class, 'createFormPo'], $adminOnly);
    $router->post('/pembelian/{id}/penerimaan', [GoodsReceiptController::class, 'storePo'], $adminOnly);
    $router->get('/pembelian/{id}/terima-barang', [GoodsReceiptController::class, 'createFormPo'], $adminOnly);
    $router->post('/pembelian/{id}/terima-barang', [GoodsReceiptController::class, 'storePo'], $adminOnly);

    // ==================================================================
    // FASE 7 — AI STOCK PREDICTION
    // Hasil = rekomendasi (bukan pesanan otomatis): semua role membaca;
    // menjalankan analisis hanya Partman (peninjau rekomendasi reorder).
    // ==================================================================
    $router->get('/prediksi', [PredictionController::class, 'index'], $anyRole);
    $router->get('/prediksi/{id}', [PredictionController::class, 'show'], $anyRole);
    $router->post('/prediksi/{id}/jalankan', [PredictionController::class, 'run'], $partmanOnly);
};
