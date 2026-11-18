# TODO — Rencana Fase & Status SmartPartMan

Aturan main: **setiap fase harus selesai + diuji + disetujui pemilik produk
sebelum fase berikutnya dimulai** (aturan kerja #16).

Terakhir diperbarui: **Fase 9 selesai + lulus uji — menunggu persetujuan pemilik produk sebelum Fase 10/deployment.**

## Status Fase

| Fase | Isi | Status |
|---|---|---|
| 1 — Fondasi proyek | Struktur MVC, router, PDO, migrasi + skema penuh (24 tabel), `.env`, installer admin (sekali jalan), halaman login, CSRF, Validator, AuditService, health check `/health` (tanpa bocor rahasia), proteksi `.htaccess`, pengujian dasar, dokumentasi | ✅ **SELESAI & DISETUJUI** (113 asersi) |
| 2 — Autentikasi & RBAC | Lockout 5× gagal + unlock Admin, manajemen user (tambah/ubah/nonaktif/aktifkan/reset), Audit Login lengkap, `RoleMiddleware` aktif di semua route, ganti password (Admin) | ✅ **SELESAI, LULUS UJI & DISETUJUI** (345 asersi unit + 69 pemeriksaan HTTP/DB) |
| 3 — Master data | Kategori, supplier, lokasi rak, master sparepart, import XLSX/CSV (template, preview, validasi, laporan gagal), stok awal | ✅ **SELESAI, LULUS UJI & DISETUJUI** (860 asersi unit + 144 pemeriksaan HTTP) |
| 4 — Transaksi & inventory | Barang masuk/keluar, ledger stok, status stok otomatis, riwayat pergerakan, koreksi pembalik, stock opname, harga beli terakhir + rata-rata (C4), pembersihan data demo | ✅ **SELESAI, LULUS UJI & DISETUJUI** (1510 asersi unit + 108 pemeriksaan HTTP terisolasi + 33 verifikasi pembersihan) |
| 5 — Dashboard & laporan | Dashboard 3 role, notifikasi stok/pembelian, grafik SVG, 12 laporan + filter, cetak, ekspor PDF/Excel, dokumen cetak PO & bukti masuk/keluar, pengaturan identitas bengkel + parameter fast/slow | ✅ **SELESAI, LULUS UJI & DISETUJUI** (660 asersi unit + 180 pemeriksaan HTTP terisolasi) |
| 6 — Reorder & pembelian | Reorder Request, persetujuan KB, PO, proses Admin, penerimaan parsial + tanpa PO, pembayaran (C5–C8) | ✅ **SELESAI & LULUS UJI** (160 asersi unit + 265 pemeriksaan HTTP terisolasi) — menunggu persetujuan Fase 7 |
| 7 - AI Stock Prediction | Pipeline Python: MA, Linear Regression, Safety Stock, ROP; periode 7/30/90 hari; kecukupan data (C1-C3) | ✅ **SELESAI, LULUS UJI & DISETUJUI** (2665 asersi unit + 313 pemeriksaan HTTP terisolasi) |
| 8 - AI Chatbot | Proxy Gemini, pengaturan Admin, filter role, ringkasan agregat, usage log (C14) | **SELESAI, LULUS UJI & DISETUJUI** |
| 9 - Administrasi & finalisasi | Backup/restore (C11), Audit Log lengkap, identitas bengkel, uji keamanan, dokumentasi final | **SELESAI & LULUS UJI** — 3012 asersi unit + 495 pemeriksaan HTTP terisolasi |

## Backlog Fase 9 (rincian — SELESAI)

Cakupan: backup/restore aman, penyempurnaan Audit Log, logo identitas
bengkel, header keamanan, regresi Fase 1–8, dan dokumentasi final.

- [x] `BackupService` + `BackupController`: backup Admin-only ke
      `storage/backups/` (di luar web root), nama berkas dari server,
      `mysqldump`/`mysql` via `proc_open` array (tanpa shell), kredensial
      lewat file sementara 0600, batas ukuran `BACKUP_MAX_MB`.
- [x] Metadata backup migrasi `014`: checksum SHA-256, nama database,
      jumlah migrasi, jenis manual/pre-restore, waktu & pelaku restore.
- [x] Restore wajib password Admin + teks `PULIHKAN`; validasi checksum,
      nama DB, dan jumlah migrasi; backup keselamatan otomatis sebelum
      restore; rollback otomatis bila restore gagal; sesi diakhiri.
- [x] Throttle 5 percobaan restore gagal → jeda 15 menit; aksi ditolak
      dicatat sebagai `BACKUP_RESTORE_DENIED`.
- [x] Logo bengkel PNG/JPEG maks. 1 MB di `storage/documents/logos/`,
      disajikan lewat `/pengaturan/logo`, dipakai kop dokumen cetak.
- [x] Audit Log: filter pengguna/aktivitas/entitas/ID/kata kunci/tanggal,
      ekspor CSV aman (BOM UTF-8 + anti formula injection), audit ekspor.
- [x] Header keamanan global: nosniff, anti-clickjacking, CSP, referrer,
      permissions, no-store; `X-Powered-By` dihapus.
- [x] Redaksi audit diperluas (authorization, cookie, session, private key,
      signature) tanpa mengubah sifat append-only.
- [x] Regresi Fase 1–8 + pengujian Fase 9; `.env` HTTP dipulihkan otomatis.
- [x] Dokumentasi: README, INSTALL, USER_GUIDE, ASSUMPTIONS, TODO,
      `.env.example`, schema snapshot.

## Hasil pengujian Fase 9

**Unit/CLI** — `php tests\run.php` → **3012 lulus, 0 gagal**.
Berkas baru: `tests/test_phase9_security.php`,
`tests/test_zz_backup_service.php`; perluasan `test_audit_log.php` dan
`test_settings.php`. Test restore **benar-benar memulihkan DB uji** dari
snapshot, memverifikasi data kembali, lalu membersihkan berkas uji.

**HTTP/DB terisolasi** — `php tests\http_verify.php` → **495 lulus, 0 gagal**.
Mencakup RBAC 403, backup + unduh, penolakan restore password/konfirmasi
salah tanpa mengubah DB, ekspor CSV, unggah/saji/hapus logo, kop dokumen,
dan header keamanan. DB uji dan `.env` dipulihkan di blok `finally`.

**Lint PHP** — seluruh file PHP yang diubah/baru: **0 galat sintaks**.

**Catatan deployment yang belum dilakukan:** database produksi
`smartpartman_v2` belum menjalankan migrasi 012–014 dan aplikasi belum
dinyatakan siap produksi. Backup pengaman pra-Fase 9:
`storage/backups/smartpartman_v2_pre_fase9_20261019_203652.sql`
(175.078 byte).

## Backlog Fase 2 (rincian — SELESAI)

- [x] `login_attempts` terisi otomatis: catat tiap percobaan (sukses/gagal + IP)
      — `AuthService` + `LoginAttemptService`.
- [x] Kunci akun setelah **5 kali gagal** (`failed_attempts` + `locked_until` 15 menit).
- [x] Halaman buka kunci akun khusus Admin + audit `USER_UNLOCK`.
- [x] CRUD user oleh Admin: tambah, ubah, nonaktifkan, aktifkan, reset password
      (Partman/KB tidak bisa ganti password sendiri; Admin bisa ganti punya sendiri).
- [x] Validasi: username unik, role hanya `partman|kepala_bengkel|admin`,
      password reset wajib konfirmasi, **tidak ada password di audit log**.
- [x] Aktifkan `RoleMiddleware` pada seluruh route sesuai matriks PRD §6.4.
- [x] Uji: akses URL role lain ditolak 403 meski mengetik URL langsung.
- [x] Penjaga tambahan: Admin **tidak dapat menonaktifkan akunnya sendiri** dan
      sistem selalu menyisakan **minimal 1 Admin aktif**.
- [x] Audit Log **append-only di level database** (trigger menolak UPDATE/DELETE),
      bukan hanya disembunyikan di UI.
- [x] Buka kunci otomatis saat masa kunci kedaluwarsa (tercatat `ACCOUNT_UNLOCKED_EXPIRED`).

## Hasil pengujian Fase 2

**Unit/CLI** — `php tests\run.php` → **345 lulus, 0 gagal** (idempoten, dijalankan
2× berturut-turut). Berkas baru: `test_user_management.php`, `test_rbac.php`,
`test_auth_login.php`, `test_auth_lockout.php`, `test_audit_log.php`.

**HTTP/DB (Apache + MariaDB, DB `smartpartman_v2`)** → **69 pemeriksaan lulus**:

| Skenario | Hasil |
|---|---|
| Buka `/pengguna` & `/audit-log` tanpa login | diarahkan ke halaman login |
| Login Admin | berhasil; menu Pengguna & Audit Log tampil |
| Admin buat akun Partman & Kepala Bengkel lewat UI | berhasil; duplikat username ditolak |
| `POST` tanpa token CSRF | **403** |
| Partman & Kepala Bengkel buka `/pengguna`, `/audit-log`, `/profil/password` | **403** (meski URL diketik langsung) |
| Partman `POST /profil/password` (ganti password sendiri) | **403** |
| Logout lalu akses halaman terproteksi | diarahkan ke login |
| 5× gagal login | akun terkunci (`failed_attempts=5`); password benar tetap ditolak |
| Admin buka kunci lewat UI | berhasil; Partman dapat login kembali; riwayat percobaan tetap utuh |
| Admin reset password pengguna | password lama tidak berlaku, password baru bisa dipakai |
| Admin ganti password sendiri (password salah / konfirmasi beda) | ditolak dengan pesan jelas; password tidak berubah |
| Audit Log ditolak database | UPDATE & DELETE ditolak; catatan tetap utuh |
| Tidak ada password di Audit Log | terverifikasi (halaman & isi tabel) |
| Session ID di-regenerate saat login | terverifikasi (cegah session fixation) |
| Cookie sesi | `HttpOnly` + `SameSite=Lax`; `Secure` hanya saat HTTPS |

## Backlog Fase 3 (rincian — SELESAI)

Cakupan: Kategori, Supplier, Lokasi penyimpanan (satu gudang, banyak rak/tingkat),
Master sparepart, Impor `.xlsx`/`.csv` + template, dan Stok awal (manual + impor).

- [x] Lapisan **Model** nyata (`app/Models/`) sebagai pembungkus tipis PDO —
      Controller → Service (aturan bisnis, transaksi, audit) → Model (SQL).
- [x] **Kategori (FR-12)**: CRUD, kode & nama unik, aktif/nonaktif, kategori
      nonaktif **tidak dapat dipakai** untuk sparepart baru, kategori tidak
      pernah dihapus permanen (tidak ada method delete).
- [x] **Supplier (FR-11)**: kode, nama perusahaan, alamat, telepon, email,
      contact person, status; validasi email; supplier nonaktif ditolak untuk
      transaksi baru lewat `SupplierService::ensureUsableForTransaction()`
      (penegakan penuh di Fase 4, sudah diuji sekarang); tidak dihapus permanen.
- [x] **Lokasi penyimpanan (FR-09)**: CRUD rak/tingkat (`RAK-A1-T2`), aktif/nonaktif.
- [x] **Master sparepart (FR-03)**: kode unik, nama, kategori (wajib), brand,
      model kendaraan, satuan, harga beli, harga jual, stok tersedia, stok
      minimum, lokasi rak/tingkat, supplier, tanggal pembelian terakhir;
      filter (cari/kategori/supplier/lokasi/status stok/status data) dan
      ringkasan status stok (habis/kritis/aman).
- [x] Kolom `stock` **tidak pernah** dapat diubah dari formulir master — hanya
      lewat pergerakan stok (ledger), sehingga tidak ada perubahan stok diam-diam.
- [x] **Impor (FR-04)**: template `.csv` & `.xlsx` (header saja, tanpa data
      contoh), pratinjau per baris (nomor baris + alasan), validasi header/tipe
      data/kode duplikat/kategori/supplier/lokasi/harga/minimum stok,
      **atomik** (satu baris salah = tidak ada data tersimpan), kode duplikat
      **ditolak** (tidak menimpa), batas 500 baris & 2 MB, tipe pembatalan
      ekstensi/isi biner/berkas rusak.
- [x] **Stok awal (FR-05)**: manual + impor, dicatat sebagai pergerakan
      `STOK_AWAL` (pengguna, tanggal bisnis, item, jumlah, catatan), bukan
      pembelian; **hanya sekali per sparepart** — dijaga service **dan** trigger
      database (`trg_stock_movements_single_initial`); koreksi lewat
      `PENYESUAIAN_OPNAME` (FR-10).
- [x] Migrasi baru: `008_stock_initial_guard.sql` (trigger stok awal sekali),
      `009_stock_movements_date.sql` (`movement_date` = tanggal bisnis,
      dipisah dari `created_at` = waktu sistem).
- [x] RBAC: melihat data master = semua peran; **menulis/mengimpor/stok awal =
      HANYA Partman** (Admin & Kepala Bengkel 403) — sesuai PRD §6.3/§6.4.
- [x] Semua aksi tercatat di Audit Log (`CATEGORY_*`, `SUPPLIER_*`, `LOCATION_*`,
      `SPAREPART_*`, `STOCK_INITIAL_CREATE`, `IMPORT_MASTER`,
      `IMPORT_STOCK_INITIAL`), tanpa pernah menulis rahasia.

## Hasil pengujian Fase 3

**Unit/CLI** — `php tests\run.php` → **860 lulus, 0 gagal** (idempoten, dijalankan
2× berturut-turut). Berkas baru: `test_master_category.php`,
`test_master_supplier.php`, `test_master_location.php`,
`test_master_sparepart.php`, `test_master_import.php`, `test_stock_initial.php`,
`test_master_view.php`; `test_rbac.php` diperluas. Lint PHP: **0 galat**.

**HTTP (Apache + MariaDB, DB `smartpartman_v2`)** → **144 pemeriksaan lulus**:

| Skenario | Hasil |
|---|---|
| 6 rute master tanpa login | diarahkan ke `/login` |
| Admin, Partman, Kepala Bengkel buka `/sparepart`, `/kategori`, `/supplier`, `/lokasi` | **200** (semua peran boleh melihat) |
| Admin & Kepala Bengkel `POST`/form tulis master (9 rute) | **403** (termasuk `/import` & stok awal) |
| Tombol tambah/impor pada halaman role lain | tidak dirender (UI), server tetap 403 |
| Partman CRUD kategori, supplier, lokasi, sparepart | berhasil; duplikat kode ditolak dengan pesan jelas |
| Ubah master sparepart | stok **tidak berubah** (hanya lewat ledger) |
| Kategori dinonaktifkan lalu dipakai sparepart baru | ditolak ("sedang nonaktif"), isian form dipertahankan |
| Stok awal manual | membentuk pergerakan `STOK_AWAL` + catatan; percobaan kedua ditolak |
| Template `.csv` & `.xlsx` diunduh lewat HTTP | **200**; XLSX valid (ZIP) — `ext-zip` aktif juga di Apache |
| Impor CSV valid (pratinjau → komit) | tersimpan; stok > 0 membentuk `STOK_AWAL`; harga `15.000,50` terbaca |
| Impor kode duplikat | ditolak, data lama **tidak tertimpa**, tombol simpan disembunyikan |
| Impor kategori tak dikenal / berkas `.xlsx` rusak / berkas > 2 MB | ditolak dengan alasan jelas, tidak ada data tersimpan |
| Impor stok awal (kode+jumlah+tanggal) | membentuk ledger `STOK_AWAL` sumber `IMPORT`; impor kedua ditolak |
| Audit Log (Admin) | memuat seluruh aktivitas Fase 3; **tidak** memuat password apa pun |
| Partman buka `/audit-log` | **403** |
| Logout & sesi | sesi dihapus, halaman master kembali dialihkan ke `/login` |
| Galat/peringatan PHP di error log Apache selama pengujian | **0** |

## Backlog Fase 4 (rincian — SELESAI)

Cakupan: barang masuk, barang keluar, stock opname, koreksi pembalik, riwayat
pergerakan, status stok otomatis, dan pengamanan konkurensi.

- [x] **Ledger sebagai satu-satunya sumber riwayat** — `stock_movements`
      append-only; `StockLedger` adalah **satu-satunya** kelas yang boleh
      mengubah `spareparts.stock`.
- [x] **Satu transaksi database** untuk perubahan stok + penulisan ledger;
      `StockLedger` menolak berjalan di luar transaksi (`LogicException`).
- [x] **Konkurensi**: baris sparepart dikunci `SELECT ... FOR UPDATE`; stok
      tidak pernah negatif (ditolak di dalam transaksi); penomoran dokumen
      memakai row lock counter. Diuji dengan **dua koneksi PDO nyata**.
- [x] **Barang masuk (FR-06)**: nomor otomatis `GR-YYYYMM-####`, tanggal,
      sparepart, qty, harga/unit, supplier, invoice, Partman pencatat, catatan,
      bukti foto/PDF opsional. Stok bertambah **hanya setelah** penyimpanan
      berhasil. Hanya tipe `pembelian` yang memperbarui tanggal pembelian
      terakhir & harga rata-rata. Penerimaan **tanpa PO** wajib alasan.
- [x] **Barang keluar (FR-07)**: nomor otomatis `GI-YYYYMM-####`, jenis
      penggunaan `service|penjualan|lainnya`, SPK & nomor polisi opsional,
      Partman pencatat, catatan. Qty melebihi stok → **rollback penuh**.
- [x] **Stock opname (FR-10)**: sesi bernomor `SO-YYYYMM-####`, snapshot stok
      sistem, Partman mengisi fisik, selisih dihitung, alasan **wajib** bila
      selisih, stok **tidak berubah** sampai konfirmasi; konfirmasi membuat
      `PENYESUAIAN_OPNAME`. Kepala Bengkel dapat melihat, **tidak** mengubah.
- [x] **Koreksi (PRD §7.4 / Aturan 8)**: transaksi tidak pernah dihapus;
      koreksi = pergerakan `KOREKSI` baru (`reversal_of_id`), wajib alasan +
      pengguna + waktu + referensi asal, **boleh sebagian**, Audit Log mencatat
      nilai sebelum/sesudah.
- [x] **Status stok** (`Sparepart::stockStatusOf`): `aman` >150% minimum,
      `menipis` 100–150%, `kritis` >0 & <minimum, `habis` = 0, serta
      `tanpa_minimum` sebagai pengecualian eksplisit saat `min_stock ≤ 0`
      (tanpa pembagian nol). Filter status di master sparepart & dashboard
      memakai model yang sama.
- [x] **Riwayat pergerakan (FR-08)**: filter pencarian, sparepart, jenis
      pergerakan, jenis referensi, rentang tanggal + ringkasan masuk/keluar.
- [x] **Migrasi `010_transaction_support.sql`**: `document_sequences`,
      `stock_movements.reversal_of_id` (+FK/indeks), `goods_receipts.receipt_type`
      (+CHECK) & `attachment_name`, `stock_opnames.opname_date`,
      `stock_opname_items.counted_by/counted_at` (+FK), jenis pergerakan
      `KOREKSI`. Diterapkan ke DB uji **dan** `smartpartman_v2`.
- [x] **RBAC**: menulis transaksi (masuk/keluar/opname/koreksi) = **hanya
      Partman**; membaca (daftar, detail, bukti, riwayat) = semua peran.
      Tombol pengubah tidak dirender untuk peran lain; server tetap 403.
- [x] **Bukti transaksi aman**: JPG/JPEG/PNG/PDF maks 5 MB, diperiksa dari isi
      berkas, disimpan di luar web root dengan nama acak, diunduh lewat route
      ber-otorisasi; berkas yatim dihapus bila transaksi gagal.
- [x] Semua aksi tercatat di Audit Log: `GOODS_RECEIPT_CREATE`,
      `GOODS_ISSUE_CREATE`, `STOCK_OPNAME_START/INPUT/CONFIRM`,
      `TRANSACTION_CORRECTION` — tanpa rahasia.

## Hasil pengujian Fase 4

**Unit/CLI** — `php tests\run.php` → **1510 lulus, 0 gagal** (idempoten,
dijalankan 2× berturut-turut). Berkas baru: `test_transaction_schema.php`,
`test_document_number.php`, `test_stock_ledger.php`, `test_stock_status.php`,
`test_transaction_attachment.php`, `test_transaction_receipt.php`,
`test_transaction_issue.php`, `test_transaction_opname.php`,
`test_transaction_correction.php`, `test_transaction_concurrency.php`,
`test_transaction_view.php`; `test_rbac.php`, `test_master_view.php`, dan
`test_install.php` diperluas. Lint PHP: **0 galat** (146 berkas).

Cakupan uji yang diminta secara eksplisit:

| Skenario wajib | Bukti uji |
|---|---|
| Barang masuk menambah stok | `test_transaction_receipt_menambah_stok_dan_mencatat_ledger` (10 → 15 + ledger `MASUK`) |
| Barang keluar mengurangi stok | `test_transaction_issue_mengurangi_stok_dan_mencatat_ledger` (10 → 6 + ledger `KELUAR`) |
| Stok negatif ditolak | `test_transaction_issue_menolak_melebihi_stok_tanpa_mengubah_apa_pun`, `..._stok_tepat_habis_dan_berikutnya_ditolak` |
| Penyimpanan gagal tidak mengubah stok | `test_transaction_receipt_gagal_tidak_mengubah_stok_dan_tidak_menyampah` (kegagalan di tengah transaksi) |
| Dua transaksi bersamaan | `test_transaction_concurrency_*` (dua koneksi PDO, `innodb_lock_wait_timeout=1`, cek lost update & nomor dokumen) |
| Opname tidak mengubah stok sebelum konfirmasi | `test_transaction_opname_isi_fisik_tidak_mengubah_stok`, `..._mulai_membuat_snapshot_tanpa_mengubah_stok` |
| Koreksi tidak menghapus transaksi asal | `test_transaction_correction_membalik_masuk_tanpa_menghapus_asal` (baris asal utuh + baris `KOREKSI` baru) |
| Bukti unggahan | `test_transaction_attachment_*` (PNG/PDF sah, tolak MIME palsu/terlalu besar/path traversal) |
| Hak akses semua role | `test_rbac_transaksi_*` + `test_transaction_view_*` (tombol vs server) |

**HTTP terisolasi (Apache + MariaDB, DB uji `smartpartman_test`)** →
**108 pemeriksaan lulus, 0 gagal** lewat `php tests\http_verify.php`
(lihat §Pemeliharaan data produksi di bawah). Verifikasi HTTP sebelumnya pada
DB `smartpartman_v2` (144 pemeriksaan) tetap tercatat sebagai bukti Fase 4;
data yang dibuatnya sudah ditutup sesuai keputusan pemilik produk.

| Skenario | Hasil |
|---|---|
| 5 rute transaksi tanpa login | diarahkan ke `/login` |
| Partman buka daftar/form/detail/riwayat/koreksi | **200** |
| Partman buat kategori/supplier/sparepart + stok awal lewat UI | berhasil; stok awal 10 tercatat sebagai `STOK_AWAL` |
| Partman catat barang masuk + unggah bukti PNG | berhasil; stok 10 → 15; nomor `GR-202610-0002`; bukti tersimpan |
| Unduh bukti lewat route ber-otorisasi | **200**, isi identik 70 byte, `image/png` |
| Akses langsung `storage/private_uploads/...` | **403** |
| Partman catat barang keluar | berhasil; stok 15 → 11; nomor `GI-…`; plat `b 4321 qa` → `B 4321 QA` |
| Pengeluaran melebihi stok | ditolak, pesan "Stok tidak mencukupi", stok tetap 11, tidak ada dokumen tersimpan |
| `POST` tanpa token CSRF | **403**, stok tidak berubah |
| Riwayat + filter pencarian | **200**; nomor dokumen ditemukan |
| Opname: mulai → isi fisik 9 → konfirmasi | sesi `SO-…`; stok **tetap 11** sampai konfirmasi, lalu **9**; ledger `PENYESUAIAN_OPNAME` −2 |
| Koreksi penerimaan 5 unit | stok 9 → 4; baris asal utuh; baris `KOREKSI` bertaut; koreksi ulang ditolak |
| Audit Log Fase 4 | 6 aktivitas tercatat; tidak memuat password |
| Kepala Bengkel: 6 halaman baca | **200**; tombol pengubah tidak dirender |
| Kepala Bengkel: 4 form + 6 POST tulis | **403** (semua) |
| Admin: 4 halaman baca | **200**; semua form/POST tulis **403** |

## Backlog Fase 5 (rincian — SELESAI)

- [x] **Notifikasi hanya di dashboard** (`NotificationService::forRole`):
      stok habis/kritis/menipis, minimum belum diatur, permintaan reorder
      menunggu persetujuan, reorder disetujui/ditolak, PO menunggu
      administrasi, pembayaran PO belum lunas. Tanpa email/WhatsApp/pusat
      notifikasi terpisah; isi disesuaikan hak akses role.
- [x] **Dashboard Partman**: total jenis sparepart & total stok, barang
      masuk/keluar hari ini, jumlah stok habis/kritis/menipis/tanpa minimum,
      bagian rekomendasi reorder (kosong sampai AI Fase 7), grafik
      pergerakan stok, transaksi terbaru, ringkasan aktivitas hari ini.
- [x] **Dashboard Kepala Bengkel**: nilai persediaan, pemakaian tertinggi &
      terendah, daftar stok kritis, grafik barang masuk/keluar, ringkasan
      pembelian (PO per status pembayaran), placeholder AI berlabel.
- [x] **Dashboard Admin**: jumlah pengguna & sparepart, statistik transaksi,
      aktivitas pengguna terbaru, status sistem, ringkasan laporan,
      pintasan pengaturan & chatbot (chatbot dinonaktifkan sampai Fase 8).
- [x] **Grafik server-side SVG responsif** (`ChartService`) — tanpa CDN,
      tanpa library eksternal; tetap terbaca di layar kecil.
- [x] **12 laporan** (`ReportService::registry`): `stok`, `masuk`, `keluar`,
      `stok_minimum`, `opname`, `riwayat`, `fast_slow`, `supplier`,
      `pembelian`, `penerimaan_po`, `pembayaran`, `ai`. Setiap laporan punya
      filter (tanggal, kategori, kode/nama, supplier, status) sesuai
      kebutuhan, ringkasan angka, grafik, dan batas maksimum baris 5000.
- [x] **Cetak, PDF, Excel**: `/laporan/{jenis}/cetak|pdf|excel`. PDF memakai
      `PdfService` (A4, potret/lanskap, pemenggalan kata, paginasi, kop halaman
      berulang, nomor halaman). Excel memakai `XlsxWriter` (nama sheet
      opsional). Semua memakai metadata laporan yang sama agar angka konsisten.
- [x] **Dokumen cetak**: Purchase Order (`/laporan/po/{id}/cetak|pdf`),
      bukti barang masuk (`/transaksi/masuk/{id}/cetak`) dan bukti barang
      keluar (`/transaksi/keluar/{id}/cetak`), lengkap dengan blok tanda
      tangan dan identitas bengkel dari pengaturan.
- [x] **Identitas bengkel dari `app_settings`** (tanpa migrasi baru):
      nama, alamat, telepon, email, catatan kaki — dipakai kop semua dokumen.
- [x] **Halaman Pengaturan (Admin saja)**: identitas bengkel + parameter
      fast/slow moving (periode analisis, ambang cepat, ambang lambat) dengan
      validasi (nama wajib, ambang slow < fast, periode 7–365 hari) dan
      pencatat Audit Log `SETTINGS_UPDATE` (nilai lama/baru, tanpa rahasia).
- [x] **Metode nilai persediaan dinyatakan eksplisit**: utama = harga beli
      rata-rata tertimbang (`COALESCE(avg_purchase_price, purchase_price)`),
      harga terakhir ditampilkan sebagai pembanding dengan catatan kaki;
      halaman tidak mengklaim angka pasti sebelum metode disepakati.
- [x] **Laporan fast/slow moving**: periode & ambang dapat dikonfigurasi
      (default 30 hari, cepat 10, lambat 2), label Cepat/Sedang/Lambat, dan
      **aturan dicetak** di laporan agar pembaca tidak menebak definisinya.
- [x] **Bagian AI berstatus placeholder/empty state yang eksplisit**
      sampai Fase 7 (reorder, prediksi, chatbot).
- [x] **Laporan bersifat baca-saja untuk semua role**; pengaturan hanya
      Admin; dashboard berbeda per role.

## Hasil pengujian Fase 5

**Unit/CLI** — `php tests\run.php` → **2183 lulus, 0 gagal** (seluruh suite,
idempoten). Berkas Fase 5 yang baru: `test_report.php` (311),
`test_report_dashboard.php` (247), `test_report_documents.php` (55),
`test_settings.php` (47) → **660 asersi Fase 5**. Lint PHP: **0 galat**
(171 berkas, tanpa `vendor`/`storage`).

Cakupan uji yang diminta secara eksplisit:

| Skenario wajib | Bukti uji |
|---|---|
| Angka dashboard = query database | `test_dashboard_*_angka_dan_tampilan` (ringkasan, status stok, masuk/keluar hari ini, nilai persediaan, jumlah pengguna) |
| Filter tanggal & pencarian bekerja | `test_report_*` (filter tanggal masuk/keluar, kategori, supplier, kode/nama, status; tanggal di luar data → 0 baris) |
| Hak akses laporan per role | `test_rbac_*` + HTTP: Partman/KB/Admin **200** pada `/laporan/*`; tulis `/pengaturan` **403** untuk non-Admin |
| PDF terbuka | `/laporan/stok/pdf` & `/laporan/po/{id}/pdf` → 200, `application/pdf`, header `%PDF-1.4`, penutup `%%EOF` |
| Excel terbuka | `/laporan/stok/excel` → 200, `PK\x03\x04` (ZIP/XLSX) |
| Layout cetak tidak memotong tabel | `test_report_*` (tabel memakai `doc-table` + pembungkus responsif; `PdfService` memecah baris & mengulang header) |
| Responsif mobile & desktop | view memakai `table-responsive` + grid kolom Bootstrap pada dashboard/laporan/pengaturan |

**HTTP terisolasi (Apache + MariaDB, DB uji `smartpartman_test`)** →
**180 pemeriksaan lulus, 0 gagal** lewat `php tests\http_verify.php`
(.env disimpan & selalu dipulihkan; DB produksi tidak disentuh).

| Skenario | Hasil |
|---|---|
| 7 rute Fase 5 tanpa login (`/dashboard`, `/laporan`, `/laporan/stok`, cetak/PDF/Excel, `/pengaturan`) | diarahkan ke `/login` |
| Partman: dashboard, `/laporan`, laporan stok terfilter | **200**; kartu stok & bagian reorder tampil |
| Partman: cetak laporan stok | **200**; judul laporan + data uji tampil |
| Partman: PDF laporan | **200**; `application/pdf`, `%PDF-1.4`, `%%EOF` |
| Partman: Excel laporan | **200**; berkas ZIP/XLSX |
| Partman: cetak + PDF Purchase Order | **200**; judul "PURCHASE ORDER" + nomor PO |
| Partman: cetak bukti barang masuk/keluar | **200**; judul + nomor dokumen |
| Partman: GET/POST `/pengaturan` | **403** |
| Kepala Bengkel: dashboard + laporan + PDF | **200**; nilai persediaan & placeholder AI tampil |
| Kepala Bengkel: `/pengaturan` | **403** (GET & POST) |
| Admin: dashboard + laporan + `/pengaturan` | **200**; total pengguna, status sistem, akses chatbot tampil |
| Admin: simpan pengaturan valid | tersimpan & tercatat `SETTINGS_UPDATE` |
| Admin: pengaturan tidak valid (ambang slow ≥ fast) | ditolak; nilai lama tidak berubah |
| Pemulihan pengaturan setelah uji | nilai awal dipulihkan; `.env` dipulihkan; backup `.env` bersih |

**Verifikasi produksi**: migrasi **10/10 terpasang, 0 tertunda** (hanya dibaca,
tidak dimigrasi ulang); `GET /health` → **200** `{status: ok, php: ok,
database: ok, migrations: ok}`.

## Backlog Fase 6 (rincian — SELESAI)

Cakupan: Reorder Request (FR-15), Purchase Order (FR-16), penerimaan
parsial/tanpa PO, pembayaran — Aturan Bisnis 13–17, keputusan C5–C8.

- [x] Migrasi `011_purchase_status_history.sql`: tabel **append-only**
      `purchase_status_history` (dokumen `REORDER`|`PO`: aktor, waktu, status
      lama → baru, alasan) + trigger database menolak UPDATE/DELETE.
- [x] **Reorder (FR-15)**: Partman melihat daftar kebutuhan (stok di bawah
      minimum) dan membuat permintaan — nomor `RQ-YYYYMM-####` otomatis,
      langsung berstatus `MENUNGGU_PERSETUJUAN` (status DRAFT di skema tidak
      dipakai). **Saran jumlah** dari aturan `ceil(minimum − stok)` (prediksi
      AI menyusul Fase 7); Partman boleh mengubah jumlah saran **dengan alasan
      wajib**. Kepala Bengkel menyetujui/menolak (penolakan wajib beralasan).
- [x] **Persetujuan reorder TIDAK menambah stok** (Aturan 14) — hanya
      penerimaan barang yang menambah stok; diuji eksplisit.
- [x] **PO (FR-16)**: Partman membuat draft (nomor `PO-YYYYMM-####`, total
      dihitung sistem), supplier dipilih sendiri dengan **rekomendasi sistem**
      (riwayat pembelian: kali beli, tanggal & harga terakhir + supplier
      default sparepart). Ajukan → Kepala Bengkel setujui/tolak (alasan
      wajib) → Admin memproses (`DIPROSES_ADMIN`) & mencatat pembayaran.
- [x] **Tiga status dipisah**: persetujuan, penerimaan, pembayaran —
      masing-masing berriwayat sendiri (`purchase_status_history` +
      `purchase_payment_history`); persetujuan/tolak/cancel/penerimaan
      tercatat aktor, waktu, transisi status, alasan.
- [x] **Penerimaan parsial** (`GoodsReceiptService`): 100 → 60 → stok +60,
      sisa 40 → `SEBAGIAN_DITERIMA`; seluruh sisa terpenuhi →
      `SELESAI_DITERIMA`. **Kelebihan penerimaan di atas sisa SELALU
      ditolak** (tanpa pengecualian). Penerimaan terkait PO memperbarui
      status PO **dan mencatat riwayat statusnya** (rujukan nomor dokumen
      masuk).
- [x] **Penerimaan tanpa PO wajib beralasan**; hanya tipe "pembelian" yang
      memperbarui harga beli terakhir & rata-rata (aturan Fase 4 tetap).
- [x] **Pembayaran** (C5): `BELUM_DIBAYAR` | `DIBAYAR_SEBAGIAN` | `LUNAS` +
      catatan; Admin saja; riwayat perubahan; **bukan modul akuntansi**.
- [x] **PO tidak pernah di-hard-delete**: penutupan lewat `DIBATALKAN`
      dengan alasan wajib (Partman pembuat untuk DRAFT/MENUNGGU_PERSETUJUAN,
      Admin juga untuk DISETUJUI/DIPROSES_ADMIN); PO dengan riwayat
      penerimaan tidak dapat dibatalkan. Baris PO, item, dan riwayat selalu
      tersimpan.
- [x] **Satu permintaan reorder melahirkan maksimal satu PO**; PO yang
      dibuat dari reorder wajib memuat sparepart yang sama.
- [x] RBAC: lihat (index/detail/cetak) semua peran; **buat/ubah/ajukan =
      Partman**; **setujui/tolak = Kepala Bengkel**; **proses/pembayaran =
      Admin**; batalkan = Partman (miliknya) + Admin.
- [x] Form barang masuk kini memakai **pemilih PO terbuka** (filter per
      supplier, item & sisa otomatis terisi) mengganti input nomor PO manual.

## Hasil pengujian Fase 6

**Unit/CLI** — `php tests\run.php` → **2395 lulus, 0 gagal** (seluruh suite,
idempoten — dijalankan lebih dari sekali dengan hasil sama). Berkas Fase 6
yang baru: `test_purchase_reorder.php` (51 asersi), `test_purchase_order.php`
(109 asersi) → **160 asersi Fase 6**. Lint PHP: **0 galat** (184 berkas).

Cakupan uji yang diminta pemilik produk:

| Skenario wajib | Bukti uji |
|---|---|
| Partman membuat reorder | `test_reorder_partman_membuat_permintaan` (nomor RQ, status Menunggu, riwayat, audit, **stok tidak berubah**) |
| Kepala Bengkel menyetujui/menolak | `test_reorder_kb_menyetujui_tanpa_ubah_stok`, `test_reorder_kb_menolak_wajib_alasan` (stok tetap, transisi status tercatat) |
| Admin memproses PO yang sudah disetujui | `test_po_alur_persetujuan_dan_proses` (`DIPROSES_ADMIN`, pelaku & waktu tercatat, stok tidak berubah) |
| Menolak tanpa alasan ditolak sistem | `test_reorder_kb_menolak_wajib_alasan`, `test_po_tolak_wajib_alasan` (status tidak berubah) |
| Penerimaan parsial menghitung sisa | `test_po_alur_lengkap_reorder_ke_penerimaan` (100→60→… sisa benar, kelebihan ditolak) |
| Penerimaan penuh menutup PO | idem (`SELESAI_DITERIMA`, sisa 0, penerimaan tambahan ditolak) |
| Penerimaan tanpa PO wajib alasan | `test_penerimaan_po_belum_disetujui_ditolak` |
| Stok tidak berubah saat persetujuan, hanya saat penerimaan | seluruh alur: 4 → (approve, proses) tetap 4 → penerimaan naik → 10 |
| Admin tidak dapat mengubah transaksi gudang | HTTP: Admin `POST /transaksi/masuk` = **403** (Fase 4 tetap berlaku di harness Fase 6) |
| Saran jumlah & aturan minimum | `test_reorder_saran_aturan_minimum` (`ceil(min−stok)`, stok ≥ minimum → tanpa saran) |
| Ubah jumlah saran wajib alasan | `test_reorder_ubah_jumlah_wajib_alasan` |
| Draft PO, validasi item/supplier | `test_po_partman_membuat_draft`, `test_po_validasi_item_dan_supplier` |
| Pembayaran terpisah dari persetujuan | `test_po_pembayaran_terpisah_dan_beriwayat` (status persetujuan tidak ikut berubah; riwayat tercatat) |
| Pembatalan menggantikan penghapusan | `test_po_pembatalan_bukan_hapus` (baris PO + item tetap ada; PO selesai tidak dapat dibatalkan) |
| Rekomendasi supplier | `test_po_rekomendasi_supplier_dari_riwayat` (riwayat beli + supplier default) |
| PO dari reorder (sekali jadi) | `test_po_dari_permintaan_reorder` (reorder belum disetujui / tanpa sparepart sama / dobel → ditolak) |

**HTTP terisolasi (Apache + MariaDB, DB uji `smartpartman_test`)** →
**265 pemeriksaan lulus, 0 gagal** lewat `php tests\http_verify.php`
(.env disimpan & selalu dipulihkan; DB produksi tidak disentuh). Seksi 5
harness memuat alur UI penuh: Partman membuat reorder (saran JSON + alasan
perubahan), KB menolak tanpa alasan (status tetap) lalu menolak beralasan &
menyetujui (stok tetap), Partman membuat PO dari reorder, mengajukan,
KB menyetujui, Admin memproses & membayar (riwayat), Partman menerima
parsial → kelebihan ditolak → sisa menutup PO, riwayat status PO 6 transisi,
cetak + PDF PO, serta RBAC 403 untuk seluruh rute tulis lintas peran
(termasuk `/reorder/saran` 200 dan pemilih PO pada form barang masuk).

**Verifikasi produksi**: migrasi **11/11 terpasang, 0 tertunda**
(`011_purchase_status_history.sql` dijalankan di `smartpartman_v2`).

## Backlog Fase 7 (rincian — SELESAI)

Cakupan: AI Stock Prediction (FR-13) — estimasi permintaan & risiko stockout,
periode 7/30/90 hari, kelanjutan C1–C3. **Prediksi hanya rekomendasi**; tidak
ada pesanan otomatis dan tidak ada transaksi inventory yang berubah.

- [x] **Pipeline Python** `python/prediction/forecast.py` (model
      `sfm-forecast/1.0.0`, skema payload `SCHEMA = 1`): Moving Average,
      Linear Regression (OLS), Safety Stock `Z × σ × √lead time`, Reorder Point.
- [x] **Integrasi lokal** PHP→Python via CLI **`proc_open` argumen-array**
      (tanpa shell, `bypass_shell`), JSON stdin/stdout — **tanpa endpoint
      jaringan**. Batas waktu 20 s **benar-benar menghentikan proses**
      (keluaran via berkas sementara + polling tenggat, `taskkill /F /T` lalu
      `proc_terminate`). Kegagalan tidak memblokir transaksi inventory.
- [x] **Kecukupan data (C3)**: ≥ 30 hari berdata & ≥ 5 transaksi keluar;
      `terbatas`/`cukup` pada 60 hari & 10 transaksi. Di bawah ambang →
      `tidak_cukup`, baris disimpan `method = belum_dihitung`, **tanpa angka
      karangan**.
- [x] **Lead time (C1)** diprioritaskan dari **riwayat penerimaan PO** (median
      selisih tanggal PO→penerimaan) → master supplier → asumsi 7 hari
      (ditandai `lead_time_assumed`, ROP belum andal).
- [x] **Override lead time manual (C1.4/C1.5)**: Partman dapat mengisi jumlah
      hari + **alasan wajib** saat menjalankan analisis; nilai otomatis tetap
      ditampilkan, dan setiap override dicatat pada audit `LEAD_TIME_OVERRIDE`
      (nilai lama → baru + alasan). Backend menolak override 0/>365 hari atau
      tanpa alasan yang memadai.
- [x] **Evaluasi objektif MA vs LR**: pemisahan **kronologis** latih/uji
      (`test_ratio = 0.3`, tanpa data masa depan), metrik MAE/RMSE/MAPE,
      metode terpilih = MAE terkecil; parameter & versi dicatat per baris.
- [x] **Keluaran**: estimasi permintaan, hari ke minimum/nol, risiko stockout,
      saran qty reorder, fast/slow (dihitung PHP dari pengaturan F6), indikator
      kecukupan data, waktu & rentang analisis.
- [x] **Halaman**: `/prediksi` (indeks, filter risiko), `/prediksi/{id}`
      (detail + riwayat analisis + formulir jalankan), tombol jalankan
      **hanya Partman** (`POST /prediksi/{id}/jalankan`, CSRF).
- [x] **Tampil di dashboard Partman & Kepala Bengkel** serta **laporan `ai`**
      (cetak/PDF/Excel), badge status modul di daftar laporan.
- [x] **Integrasi reorder (G7 lanjutan)**: saran dari prediksi dipakai bila
      **segar (≤ 7 hari)** & kecukupan `cukup`/`terbatas`; selain itu kembali
      ke aturan minimum. Sumber & `prediction_id` dicatat; ubah qty wajib alasan.
- [x] **Audit**: `PREDIKSI_DIBUAT` (entitas `ai_predictions`) &
      `PREDIKSI_GAGAL` (entitas `spareparts`); fallback saran aturan minimum.
- [x] **RBAC**: lihat hasil = semua peran login; jalankan = hanya Partman.

## Hasil pengujian Fase 7

**Unit/CLI** - `php tests\run.php` → **2665 lulus, 0 gagal** (seluruh suite,
idempoten; dijalankan pada DB uji yang **baru direset**). Berkas Fase 7:
`test_prediction_pipeline.php` + `test_prediction_service.php` (176 asersi),
`test_prediction_view.php` (88 asersi: render indeks/detail + RBAC) → **264
asersi Fase 7**. Lint PHP: **0 galat**; `forecast.py` lolos `py_compile`.

Cakupan uji yang diminta pemilik produk:

| Skenario wajib | Bukti uji |
|---|---|
| Dataset kosong | `test_prediction_kosong` (status ok, `tidak_cukup`, ramalan NULL, alasan tertulis) |
| Data sangat sedikit | `test_prediction_data_sangat_sedikit` (3 transaksi → `tidak_cukup`, tanpa saran) |
| Data normal | `test_prediction_normal_dan_pembanding_manual` (60 transaksi → `cukup`, metode terpilih) |
| Permintaan musiman/tidak stabil | seri bervariasi diuji pada evaluasi kronologis (MA vs LR, metrik kedua metode) |
| Lead time kosong | `test_prediction_view_lead_time_asumsi` + detail menandai "ROP belum dapat dihitung secara andal" |
| Lead time dari riwayat | `test_prediction_lead_time_riwayat_penerimaan` (median 10 hari > master 20 hari tidak dipakai) |
| **Override lead time manual** | `test_prediction_lead_time_override` (validasi alasan wajib + audit), `test_prediction_lead_time_override_pipeline` (pipeline Python nyata memakai 15 hari, mengalahkan master 5), `test_prediction_view_lead_time_override` (badge, alasan, nilai otomatis) |
| Stok nol | `test_prediction_*` fixture stok 0 (hari ke nol = 0, tanpa pembagian-nol) |
| Stok minimum nol/invalid | guard payload + status `tanpa_minimum` (reuse Fase 4) |
| Prediksi 7/30/90 hari | `test_prediction_periode_7_30_90` (rentang & target benar) |
| Bandingkan dengan hitungan manual | `test_prediction_normal_dan_pembanding_manual` (laju harian, permintaan, hari ke minimum/nol manual) |
| Proses berhasil terbaca PHP & tersimpan | `test_prediction_proses_berhasil` (stub → baris + audit `PREDIKSI_DIBUAT`) |
| Proses gagal terkendali | `test_prediction_proses_gagal` (skrip hilang, JSON rusak, periode salah, stderr → status error, stok utuh) |
| **Timeout nyata** (kirim dimatikan) | `test_prediction_proses_timeout` (stub tidur 3 s; elapsed < 2 s, penanda pasca-tidur **tidak pernah** ditulis, audit `PREDIKSI_GAGAL`) |
| Integrasi saran reorder | `test_prediction_integrasi_saran_reorder` (segar & memadai → pakai prediksi; selain itu fallback) |
| Render + RBAC halaman | `test_prediction_view_*`, `test_prediction_rbac_route` (semua route wajib login; jalankan 403 untuk Admin/KB) |

**HTTP terisolasi (Apache + MariaDB, DB uji `smartpartman_test`)** →
**313 pemeriksaan lulus, 0 gagal** lewat `php tests\http_verify.php`
(`.env` dicadangkan & **selalu dipulihkan** — diverifikasi pada akhir suite).
Seksi 6 harness menjalankan **integrasi Python-PHP nyata lewat HTTP**: Partman
membuka `/prediksi` & detail, menolak POST tanpa CSRF (403), menjalankan
analisis (`POST /prediksi/{id}/jalankan` → 302; baris `ai_predictions`
tersimpan; `details` memuat `cli_proc_open` & `lokal-only`; audit
`PREDIKSI_DIBUAT`), **stok tidak berubah** & transaksi inventory tetap dapat
dibuka; Kepala Bengkel & Admin hanya melihat (formulir tersembunyi, jalankan
= **403**); laporan `ai` tanpa rekomendasi karangan saat data belum cukup.

## Backlog Fase 8 (rincian — SELESAI)

Cakupan: **AI Chatbot Gemini** untuk Partman, Kepala Bengkel, dan Admin;
pengaturan Admin; pembatasan role; keamanan & ketahanan API.

- [x] **Migrasi `012_chatbot_gemini_defaults.sql`** — default model/prompt/topik
      dan kolom penggunaan; **`013_chatbot_default_prompt_upgrade.sql`** —
      mengganti prompt/topik default lama tanpa menimpa kustomisasi.
- [x] **API key hanya di server**: enkripsi **AES-256-GCM** dari `APP_KEY`,
      format `v1:base64(nonce+tag+ciphertext)`; UI hanya menampilkan `****abcd`;
      fallback `GEMINI_API_KEY` dari `.env`; tidak pernah masuk log/Audit Log.
- [x] **Pengaturan Admin** (`/pengaturan/chatbot`): aktif/nonaktif, API key,
      pilihan model (`gemini-3.5-flash-lite` default, `gemini-3.8-flash`),
      instruksi sistem, batas topik, gaya jawaban (ringkas/normal/detail),
      batas pemakaian harian/bulanan, uji koneksi, ringkasan log penggunaan/error.
- [x] **Uji koneksi** memakai `GET /models/{model}` (tidak memakai kuota
      generasi); API key yang diuji tidak disimpan.
- [x] **Chatbot stateless per pertanyaan** (riwayat hanya di sesi UI) untuk
      mengurangi prompt injection & menjaga privasi.
- [x] **Konteks ber-RBAC** dari service/agregat backend — bukan SQL bebas:
      Partman (persediaan, stok kritis, pergerakan, prediksi), Kepala Bengkel
      (nilai persediaan, pemakaian, laporan, PO/pembayaran, prediksi), Admin
      (statistik pengguna/persediaan/transaksi + aktivitas aplikasi).
- [x] **Guard deterministik sebelum API**: tolak SQL, permintaan API
      key/password/token/system prompt, prompt injection/role escalation, topik
      Admin untuk non-Admin, dan topik jelas di luar SmartPartMan. Penolakan
      tidak memakai kuota.
- [x] **Batas pemakaian** dari `chatbot_usage_logs` (harian/bulanan; `0`/kosong =
      tanpa batas); hanya status `success`/`failed` yang dihitung.
- [x] **Ketahanan**: API key kosong/tidak valid, kuota habis, API tidak
      tersedia/timeout, error internal → pesan aman; modul inventory tetap
      berjalan. Timeout default **25 detik** (`GEMINI_TIMEOUT`).
- [x] **Anti-XSS**: pertanyaan, jawaban AI, nama pengguna, dan pesan error di
      log di-escape pada halaman chat/pengaturan.
- [x] **RBAC**: `/chatbot` untuk semua role login; seluruh route pengaturan
      chatbot **hanya Admin** (GET/POST/uji), non-Admin **403**.

## Hasil pengujian Fase 8

**Unit/CLI** — `php tests\run.php` → **2946 lulus, 0 gagal** (seluruh suite,
idempoten). Berkas Fase 8 baru: `test_chatbot_crypto.php`,
`test_chatbot_settings.php`, `test_chatbot_gemini.php`,
`test_chatbot_service.php`, `test_chatbot_view.php`; stub
`tests/gemini_stub.php` + `tests/gemini_stub_router.php`. Lint PHP:
**210 file, 0 gagal**; `forecast.py` lolos `py_compile`.
Total asersi baru Fase 8: **281** (selisih 2946 − 2665 dari suite Fase 7).

**HTTP terisolasi (Apache + MariaDB, DB uji `smartpartman_test`)** →
**421 pemeriksaan lulus, 0 gagal** lewat `php tests\http_verify.php`.
Seksi 8 mengarahkan `GEMINI_API_URL` ke **stub lokal** (bukan Google), lalu:

| Skenario wajib | Bukti uji |
|---|---|
| Uji koneksi Gemini | Admin menyimpan API key → **Uji Koneksi** sukses; stub menerima `GET /models/{model}`; key tidak valid → `API_KEY_INVALID`; key tersimpan tidak berubah |
| Chatbot Partman / Kepala Bengkel / Admin | masing-masing menerima jawaban stub; prompt memuat konteks role yang benar; statistik pengguna/audit **tidak** masuk konteks non-Admin |
| Pembatasan akses | tanpa login → 302; Partman/KB `/pengaturan/chatbot` (GET/POST/uji) → **403**; halaman tidak membocorkan key/mask |
| Pertanyaan luar topik | ditolak **sebelum** API (jumlah panggilan stub tidak bertambah) |
| API key kosong / tidak valid | pesan konfigurasi aman / `API key Gemini tidak valid`; log `failed`; key tidak bocor |
| API tidak tersedia / timeout | pesan "sedang tidak dapat dihubungi"; aplikasi tetap berjalan |
| Batas penggunaan | batas harian 1 → pemakaian pertama sukses, kedua ditolak **tanpa** panggilan API tambahan |
| Keamanan | SQL ditolak, permintaan data Admin oleh non-Admin ditolak, XSS jawaban di-escape, CSRF salah 403, tidak ada API key di DB plaintext/log/audit |
| Regresi | seluruh suite Fase 1–7 tetap lulus; `.env` & pengaturan chatbot dipulihkan (nonaktif, tanpa key) |

**Batasan yang dinyatakan terbuka:** **tidak ada API key Gemini nyata** pada
pengujian ini, sehingga koneksi ke endpoint Google sebenarnya **belum diuji**;
semua alur di atas diverifikasi memakai stub lokal. Kode memakai Interactions
API (`POST /interactions`) dan model default `gemini-3.5-flash-lite`.

## Revisi UI — Floating AI Assistant (setelah Fase 9)

Chatbot yang sebelumnya hanya halaman `/chatbot` kini juga tampil sebagai widget
mengambang di **seluruh halaman setelah login**. Halaman `/chatbot`, menu
sidebar, dan Pengaturan Chatbot **tetap dipertahankan**.

- Berkas baru: `app/Views/chatbot/widget.php`, `assets/js/chatbot-widget.js`.
- Berkas diubah: `app/Controllers/ChatbotController.php` (`apiSend`, `apiClear`),
  `routes/web.php` (`POST /chatbot/api`, `POST /chatbot/api/clear`),
  `app/Views/layouts/app.php`, `assets/css/app.css`,
  `app/Services/ChatbotService.php` (`suggestionsForRole`, `WELCOME_MESSAGE`),
  `app/Views/chatbot/index.php` (memakai helper saran yang sama).
- Konfigurasi & integrasi tidak berubah: membaca `ChatbotSettingsService`/DB
  yang sama, memakai `GeminiService`, system prompt, batas topik, batas
  penggunaan, log pemakaian, dan penyaringan konteks per role.
- Riwayat memakai sesi `chatbot_history` yang sama; tutup/buka jendela atau
  pindah halaman tidak menghapus percakapan. Tombol percakapan baru
  membersihkan riwayat lewat `POST /chatbot/api/clear`.
- Widget tidak tampil di halaman login/installer, hanya dirender sekali per
  halaman, dan disembunyikan saat cetak.

**Hasil uji revisi:** unit/CLI **3050 lulus, 0 gagal**; HTTP terisolasi
**535 lulus, 0 gagal**; lint **218 file, 0 galat**. Uji meliputi tampilan widget
di dashboard/sparepart/transaksi/laporan, ketiadaan widget di login, CSRF 403,
RBAC role (Partman ditolak untuk data pengguna), kirim JSON + riwayat sesi,
percakapan baru, escaping XSS, dan tidak bocornya API key.
**Belum diuji:** koneksi Gemini nyata (tanpa API key) dan interaksi visual
langsung di browser/HP (sintaks JS divalidasi, bukan diuji di peramban).

## Bug diperbaiki selama Fase 7 (regression test tersedia)

- [x] **Timeout `proc_open` di Windows tidak benar-benar berlaku**:
      `stream_set_blocking()`/`stream_select()` pada pipa `proc_open` tidak
      andal (pembacaan menunggu EOF dan mengabaikan tenggat). Diperbaiki dengan
      mengalihkan stdout/stderr ke **berkas sementara** ber-polling tenggat
      (`microtime`) + `taskkill /F /T`. Dibuktikan dengan probe stub yang
      **tidak pernah** menulis penanda pasca-tidur — diuji
      `test_prediction_proses_timeout`.
- [x] **`safety_stock`/`reorder_point` dibaca sebagai kolom**, padahal hanya
      tersimpan di JSON `details` → kartu SS/ROP tidak pernah dirender +
      peringatan "Undefined array key". View kini membaca dari `details`;
      ditangkap `test_prediction_view_show`.
- [x] **Pengujian lama mengasumsikan AI masih placeholder** (Fase 5):
      `test_report_dashboard` menuntut `ai.ready === false`. Status kini
      diturunkan dari `AiPrediction::summary()['total']` agar tidak
      bergantung urutan/isi DB uji.
- [x] **`test_stock_status` bergantung ukuran DB uji** (daftar "perlu
      perhatian" dibatasi 100 baris; sisa fixture antar-run menenggelamkan
      fixture uji). Keanggotaan habis/kritis kini diverifikasi lewat filter
      status tanpa batas.

## Bug diperbaiki selama Fase 6 (regression test tersedia)

- [x] Penerimaan barang mengubah status PO (`SEBAGIAN_DITERIMA` /
      `SELESAI_DITERIMA`) **tanpa mencatat riwayat status** — padahal setiap
      perubahan status wajib tercatat (aktor, waktu, transisi, rujukan).
      `GoodsReceiptService::applyPoReceipt` kini mengunci header PO, membaca
      status lama, dan menulis `purchase_status_history` dalam transaksi yang
      sama — diuji `test_po_alur_lengkap_reorder_ke_penerimaan` (6 transisi)
      + HTTP (riwayat 6 baris).
- [x] Fixture pengujian Fase 6 memakai nama pengguna tetap; `t_make_user`
      menghapus baris lama lebih dulu tetapi baris PO (FK `created_by`
      RESTRICT) masih menunjuknya → PDOException pada run kedua. Username
      fixture kini unik per pemanggilan — suite idempoten.
- [x] Form `reorder/form.php` sempat menyisipkan `<?php` nyasar di baris 45
      (galat parse) — tertangkap lint sebelum pengujian berjalan.
- [x] Harness HTTP memakai `?sparepart=` pada `/reorder/saran` padahal
      endpoint memakai `sparepart_id` (view sudah benar) — diperbaiki pada
      harness; kini 200 + JSON `suggested_qty`.

## Bug diperbaiki selama Fase 5 (regression test tersedia)

- [x] `PdfService` sempat melempar fatal pada closure (`$continued` ikut
      `use`) sehingga semua PDF gagal dibuat. Dihapus dari daftar `use`;
      kini PDF laporan & PO diuji lewat HTTP (header + `%%EOF`).
- [x] Sel kode laporan pada tabel laporan ditautkan ke URL seolah-olah
      menjadi tautan, padahal hanya kode teks. Tautan palsu dihapus agar
      pembaca tidak diklik ke tempat yang salah.
- [x] Laporan fast/slow menampilkan "semua tanggal" meski memakai periode
      default 30 hari. Teks filter kini melaporkan periode yang benar-benar
      dipakai.
- [x] Uji laporan fast/slow mengasumsikan fixture selalu masuk peringkat
      top-5 dashboard; database uji menumpuk sisa fixture dari run
      sebelumnya sehingga peringkat terisi 5 fixture lain. Uji kini memakai
      periode yang sama dengan batas lebih lebar (50) dan memeriksa ledger
      langsung, sehingga tetap benar walau database uji tumbuh.
- [x] Ekspektasi desimal pada pemeriksaan Excel salah (1 digit) padahal
      formatter memakai 2 digit (`1,50`). Ekspektasi diperbaiki.



- [x] Halaman detail opname tetap mengirim **form POST + token CSRF** kepada
      peran baca-saja (Kepala Bengkel). Form kini hanya dirender bila
      `$editable` (Partman + sesi `OPEN`)
      — `test_transaction_view_stock_opname`.
- [x] Uji installer gagal pada run berikutnya karena transaksi Fase 4 memakai
      `ON DELETE RESTRICT` ke `users` → ditambahkan `t_purge_transaction_data()`
      yang menghapus tabel transaksi berurutan FK sebelum `DELETE FROM users`
      — `test_install_keadaan_awal_bersih`.

## Bug diperbaiki selama Fase 3 (regression test tersedia)

- [x] `MasterImportService::preview()` membaca berkas dua kali (sekali di
      controller, sekali di service) → dipisah menjadi `preview()` (isi berkas
      yang sudah dibaca) + `previewFile()` (untuk CLI/pengujian).
- [x] Trigger `trg_stock_movements_single_initial` sempat memblokir koreksi
      `PENYESUAIAN_OPNAME` → trigger hanya menolak `STOK_AWAL`; koreksi FR-10
      tetap boleh (diuji langsung lewat INSERT).

## Bug diperbaiki selama Fase 1 (regression test tersedia)

- [x] `dispatch()` crash (TypeError `array_slice`) untuk middleware terdaftar
      sebagai string → didukung bentuk string & array — `test_router_middleware_bentuk_string_dan_array`.
- [x] Test CHECK constraint tadinya bisa "lulus" karena FK (kategori tak ada) →
      sekarang memastikan pesan error berasal dari `chk_spareparts_stock`.
- [x] `Validator` aturan `min/max` (numerik vs panjang karakter) & `confirmed` direfactor bersih.

## Bug diperbaiki selama Fase 2 (regression test tersedia)

- [x] `AuditLogService::paginate()` mencampur placeholder bernama & posisional
      (`SQLSTATE[HY093]`) saat `ATTR_EMULATE_PREPARES=false` → SQL memakai
      `LIMIT :limit OFFSET :offset` — `test_audit_log_filter_dan_paginasi`.
- [x] Uji audit yang bergantung urutan berkas (aktivitas belum ada saat berkas
      pertama berjalan) → diganti uji redaksi rahasia yang mandiri.
- [x] Uji filter audit menumpuk data antar-run → penanda unik per run
      (`UJI_AUDIT_FILTER_<acak>`), suite kini idempoten.

## Keputusan tunggu produk (lihat docs/ASSUMPTIONS.md bagian C)

Butir **C9–C11, C13** belum boleh dianggap final — dibahas saat fase
terkait dimulai. **C12 (session timeout)** sudah final: tidak ada timeout
(keputusan pengguna). **C1–C3 (lead time, parameter AI, kecukupan data)**
sudah **disetujui & diterapkan** pada Fase 7 — lihat
`docs/ASSUMPTIONS.md` bagian H/I. **C14 (data ke Gemini)** sudah diterapkan
pada Fase 8 — lihat bagian di bawah dan `docs/ASSUMPTIONS.md` bagian J.

Menunggu konfirmasi setelah Fase 4 — **SUDAH DIJAWAB & DITERAPKAN (18 Okt 2026)**:

1. **C4 — harga beli** ✅ final: `purchase_price` mengikuti harga pembelian
   terakhir; *weighted average* tetap dihitung terpisah sebagai dasar nilai
   persediaan; riwayat harga tertelusur di detail sparepart (B37, E1).
2. **Akun verifikasi** `partman1` / `kepala1` ✅ dipertahankan, role
   diverifikasi (`partman`, `kepala_bengkel`), password diganti dengan password
   acak aman. Berkas plaintext `storage/private/akun_verifikasi.txt` (ditolak
   web = 403, ACL dibatasi) **sudah dihapus 19 Okt 2026**; reset password
   tersedia lewat Admin (E2).
3. **Data master demo** `HTTP-` / `HTTP4-` ✅ dibersihkan: seluruhnya
   **dinonaktifkan** karena memiliki relasi; stok dinolkan lewat opname
   `SO-202610-0002`; data non-demo tidak disentuh (E3).
4. **Data transaksi verifikasi** ✅ **tidak dihapus**; ledger utuh, dokumen
   tetap ada, penyesuaian hanya lewat opname beralasan. Pengujian berikutnya
   memakai DB uji lewat harness baru (E4).

Menunggu konfirmasi setelah Fase 6 — **SUDAH DIIMPLEMENTASIKAN SEMENTARA,
MENUNGGU PERSETUJUAN (19 Okt 2026)** — detail di `docs/ASSUMPTIONS.md` (C5–C8
+ aturan saran/pembatalan):

1. **C5 — pembayaran**: status saja (`BELUM_DIBAYAR`/`DIBAYAR_SEBAGIAN`/
   `LUNAS`) + catatan teks bebas, tanpa tanggal/jumlah nominal terstruktur;
   diketik Admin, bukan sistem hitung. Bukan modul akuntansi.
2. **C6 — penerimaan melebihi sisa**: **selalu ditolak**, tanpa prosedur
   pengecualian/otorisasi khusus — perbaiki lewat penerimaan baru atau opname
   bila terjadi kesalahan fisik.
3. **C7 — penghapusan PO**: diganti **pembatalan beralasan** (`DIBATALKAN`);
   PO dengan riwayat penerimaan tidak dapat dibatalkan; tidak ada
   hard-delete. Baris PO + riwayat tetap tersimpan untuk audit.
4. **C8 — saran jumlah reorder**: saat ini aturan sederhana
   `ceil(minimum − stok)` (tanpa AI). Prediksi AI (Fase 7) menggantikan
   aturan ini; Partman tetap boleh mengubah angka dengan alasan wajib.

Menunggu konfirmasi setelah Fase 7 — **DISETUJUI & DITERAPKAN (19 Okt 2026)** —
detail di `docs/ASSUMPTIONS.md` (C1–C3 + bagian H/I):

1. **C1 — lead time**: diprioritaskan dari **riwayat penerimaan PO** (median
   jarak PO→penerimaan); bila kosong, pakai `suppliers.lead_time_days`; bila
   keduanya kosong → asumsi **7 hari**, ditandai `lead_time_assumed` dan
   **ROP dinyatakan belum andal**. **Partman boleh override manual dengan
   alasan wajib**; perubahan dicatat pada audit `LEAD_TIME_OVERRIDE`.
2. **C2 — parameter AI**: MA (jendela 7), Linear Regression (OLS),
   Safety Stock `Z=1,65 × σ × √lead time`, ROP = permintaan lead time + SS;
   `test_ratio = 0,30` dengan pemisahan **kronologis**; versi model
   `sfm-forecast/1.0.0`; seluruh parameter/versi dicatat per baris. Model
   **tidak dinyatakan akurat tanpa dukungan evaluasi**.
3. **C3 — kecukupan data**: minimal **30 hari data historis & 5 transaksi
   barang keluar**; bila belum terpenuhi → status **Data terbatas** tanpa
   angka karangan; `cukup` pada ≥ 60 hari & ≥ 10 transaksi.

Menunggu konfirmasi setelah Fase 8 — **DITERAPKAN, MENUNGGU TINJAUAN PRIVASI
(19 Okt 2026)** — detail di `docs/ASSUMPTIONS.md` (C14 + bagian J):

1. **C14 — data yang boleh dikirim ke Gemini**: hanya **pertanyaan pengguna**
   + **konteks agregat/daftar terbatas yang dibangun server sesuai role**.
   Tidak ada dump database, password, API key, token, audit mentah, atau data
   pengguna massal. Admin mendapat statistik agregat & aktivitas aplikasi
   (bukan data mentah massal); non-Admin tidak mendapat topik Admin.
2. **Tanpa akses SQL/tool**: Gemini tidak menerima koneksi/query database dan
   tidak dapat mengubah transaksi. Guard deterministik menolak permintaan SQL,
   rahasia, prompt injection, dan topik di luar SmartPartMan **sebelum** API.
3. **Privasi & retensi**: chatbot **stateless** (riwayat hanya di sesi browser);
   `store=false` pada permintaan Gemini; log aplikasi hanya menyimpan potongan
   pertanyaan (maks 200 karakter) + status/error, **bukan jawaban lengkap**.
4. **Keterbatasan**: koneksi ke Google **belum diuji dengan API key nyata**;
   pengujian memakai stub lokal. Kebijakan privasi final tetap perlu ditinjau
   pemilik produk sebelum pemakaian produksi.

## Pemeliharaan data produksi (18 Okt 2026)

- **Pembersihan data demo** (`HTTP-`/`HTTP4-`) di `smartpartman_v2`:
  10 sparepart, 4 kategori, 4 supplier, 2 lokasi rak dinonaktifkan; 8 item
  yang masih bersaldo dinolkan lewat satu sesi opname
  `SO-202610-0002` (alasan wajib terisi). **Ledger tetap 22 baris** dan
  transaksi asal (`MASUK #8`, `KELUAR #9`, `KOREKSI #14`) tidak berubah.
  Audit Log mencatat `DEMO_DATA_CLEANUP` + `STOCK_OPNAME_START/INPUT/CONFIRM`.
- **Konsekuensi**: DB produksi kini **tidak memiliki master aktif** (memang
  seluruh master yang ada adalah data demo). Data asli harus diisi/diimpor
  Partman sebelum pemakaian operasional.
- **Pengujian HTTP terpisah dari produksi**: `php tests\http_verify.php`
  1. mencadangkan `.env` → `.env.httpbak`,
  2. mengganti `DB_NAME` ke `DB_TEST_NAME` (wajib berakhiran `_test`),
  3. menjalankan migrasi + membuat akun uji khusus di DB uji,
  4. menguji 421 pemeriksaan (rute, RBAC 3 role, masuk/keluar/opname/koreksi,
     unggah & unduh bukti, CSRF, audit, prediksi AI Fase 7 + integrasi
     Python-PHP, serta chatbot Gemini Fase 8 memakai **stub lokal**),
  5. **selalu memulihkan `.env`** lewat `finally` + shutdown handler
     (termasuk bila gagal/crash; sisa backup dipulihkan saat run berikutnya).
  Jalankan saat tidak ada pengguna lain (jeda beberapa detik). **Jangan**
  mengarahkan harness ini ke DB produksi.
- Verifikasi integritas setelah harness: DB aktif kembali
  `smartpartman_v2`, tidak ada data `HTTPV-` di produksi, tidak ada
  `.env.httpbak` tersisa.
