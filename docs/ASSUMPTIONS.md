# Register Asumsi & Keputusan SmartPartMan

Dokumen ini mencatat hal yang **belum diputuskan di PRD** atau keputusan
teknis yang diambil saat implementasi. Setiap butir **wajib dikonfirmasi ke
pemilik produk** sebelum dianggap final (aturan kerja #9).

Terakhir diperbarui: **Fase 9** (19 Okt 2026).

## A. Keputusan teknis yang sudah disetujui pemilik produk

| # | Keputusan | Status | Catatan |
|---|---|---|---|
| D1 | Nama folder/URL: rename ke `smartpartman` → `http://localhost/smartpartman` | ✅ disetujui | Folder lama `smartpartman2` dikosongkan/diganti |
| D2 | Integrasi PHP↔Python: **CLI via `proc_open`** (argumen tetap, JSON stdin/stdout) | ✅ disetujui | Bukan REST service; alasan: paling sederhana di XAMPP, `proc_open` terverifikasi aktif |
| D3 | Import XLSX/CSV + ekspor PDF/Excel: **native tanpa Composer** | ✅ disetujui | XLSX via `ZipArchive` (perlu `extension=zip`), CSV native, PDF via halaman cetak browser |
| D4 | Database proyek: **`smartpartman_v2`**; database lama `smartpartman` dibiarkan utuh | ✅ disetujui | DB lama korup ("doesn't exist in engine") milik proyek berbeda di `htdocs\SmartPartMan` — tidak dihapus agar peluang pemulihan tetap ada |
| D5 | Tidak ada session timeout otomatis (PRD FR-01) | ✅ keputusan PRD | Risiko didokumentasikan di INSTALL.md §6 |

## B. Keputusan desain implementasi (dicatat, boleh dibahas ulang)

| # | Keputusan | Alasan | Risiko bila berubah |
|---|---|---|---|
| B1 | Front controller di **root** (`index.php` + `.htaccess`), bukan `public/` | Bisa jalan di XAMPP tanpa edit vhost/alias; aman karena folder sensitif di-deny | Bila kelak pakai vhost, pindah ke `public/` mudah |
| B2 | **`goods_receipts(+items)` menampung penerimaan DENGAN & TANPA PO**; tabel `purchase_receipts*` PRD §12 TIDAK dibuat terpisah | Satu jalur penerimaan = ledger stok tunggal → tidak ada risiko stok ganda/belang | Nama tabel beda dari PRD; terdokumentasi di sini |
| B3 | Tabel riwayat pembayaran dinamai **`purchase_payment_history`** (PRD: `purchase_payment_status`) | Nama mencerminkan isi (riwayat perubahan); status kini tersimpan juga kolom `purchase_orders.payment_status` | Penyesuaian dokumentasi |
| B4 | Kuantitas memakai **DECIMAL(12,2)** (bukan INTEGER) | Satuan bisa liter/ml (oli, ATF) | ✅ Fase 4: `StockLedger` membulatkan kuantitas & stok ke **2 desimal** (`round(..., 2)`) sebelum disimpan |
| B5 | Uang: **DECIMAL(15,2)** | Rupiah tanpa desimal, aman hingga 999 triliun | — |
| B6 | Skema memakai **CHECK constraint** (`stock >= 0`, `quantity > 0`) sebagai pertahanan lapis-2 | MariaDB 10.4 menegakkan CHECK; menangkap bug di luar aplikasi | Pastikan DB selalu MariaDB ≥10.4 / MySQL ≥8 |
| B7 | Login dasar (tanpa lockout) sudah masuk Fase 1 agar loop instalasi→login teruji; **lockout 5× & manajemen user = Fase 2** | Fase dipecah kecil, tiap fase diuji & disetujui dulu | Tidak ada — termasuk rencana |
| B8 | `min_stock = 0` diizinkan skema; status "stok minimum tidak valid" ditangani **di Service** sebagai status pengecualian (tanpa pembagian-nol) | PRD §13.3 mewajibkan status pengecualian eksplisit | ✅ Fase 4: status eksplisit `tanpa_minimum` (lihat B36), diuji di `test_stock_status.php` |
| B9 | Seeder hanya roles + pengaturan awal + 1 baris chatbot (nonaktif) | Admin dibuat lewat installer, bukan seeder | — |
| B10 | `GET /health`: publik hanya status boolean/enum; diagnostik teknis (versi PHP/DB, ekstensi hilang, migrasi tertunda, pesan error DB) hanya untuk Admin login | Memenuhi butir "health check tanpa membocorkan rahasia" & tetap berguna untuk diagnosis | Detail sulit dipantau robot monitoring tanpa sesi Admin (bisa ditinjau Fase 9) |
| B11 | **Lockout: 5 kali gagal → kunci 15 menit** (`LoginAttemptService::MAX_ATTEMPTS/LOCK_MINUTES`). Setelah masa kunci lewat, hitungan direset otomatis pada percobaan berikutnya (tercatat `ACCOUNT_UNLOCKED_EXPIRED`). Admin juga dapat membuka kunci manual (`USER_UNLOCK`) | Angka 5/15 menit adalah nilai aman-umum; dapat diubah satu tempat di Service | Ubah konstanta di `LoginAttemptService` + sesuaikan dokumentasi |
| B12 | Pesan kegagalan login **membedakan** tiga kondisi: kredensial salah, akun **nonaktif**, akun **terkunci** | Keterbacaan untuk pengguna bengkel; tanpa ini pengguna tidak tahu harus menghubungi Admin atau salah ketik | **Sedikit membocorkan status akun** (bukan password). Alternatif paling aman: satu pesan seragam — ditinjau ulang bila diperlukan |
| B13 | Unknown username tetap menjalankan `password_verify()` terhadap **hash dummy** (`AuthService::DUMMY_HASH`) | Menyetarakan waktu respons → mempersulit enumerasi username (mengimbangi B12) | Beban CPU sedikit lebih besar; aman |
| B14 | **Reset password oleh Admin sekaligus membuka kunci** (nol-kan `failed_attempts`, `locked_until`) | Satu tindakan pemulihan untuk kasus "lupa password / terkunci" | Bila kelak perlu dipisah, cukup pisahkan pemanggilan di `UserService::resetPassword()` |
| B15 | **Hanya Admin** memiliki rute ganti password sendiri (`GET/POST /profil/password`). Partman & Kepala Bengkel menerima **403** | Menegakkan FR "Partman/KB tidak bisa ganti password sendiri" langsung di middleware, bukan menyembunyikan tombol | Bila produk ingin mereka bisa ganti sendiri, cukup tambah role pada route |
| B16 | Audit Log **append-only ditegakkan di level database** (migrasi 007: trigger menolak `UPDATE` isi & `DELETE`). Satu-satunya pengecualian yang diizinkan: `user_id` menjadi `NULL` | Menjaga integritas audit walau ada bug/aplikasi lain; pengecualian diperlukan karena FK `audit_logs.user_id` ber-`ON DELETE SET NULL` | Menghapus user akan mengosongkan kolom `user_id` di audit (tidak menghapus catatan). Bila audit harus mengabadikan nama pelaku, `username`/`role` tetap tersimpan sebagai teks |
| B17 | **Guard keamanan akun Admin:** Admin tidak dapat menonaktifkan akunnya sendiri, dan sistem menolak menonaktifkan/menurunkan role **Admin aktif terakhir** | Mencegah aplikasi terkunci tanpa administrator | Bila Admin harus bisa menurunkan diri sendiri, ubah `UserService` (tidak disarankan) |
| B18 | Audit mencatat aktivitas Fase 2: `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGIN_BLOCKED`, `LOGOUT`, `ACCOUNT_LOCKED`, `ACCOUNT_UNLOCKED_EXPIRED`, `USER_CREATE`, `USER_UPDATE`, `USER_ACTIVATE`, `USER_DEACTIVATE`, `USER_PASSWORD_RESET`, `USER_UNLOCK`, `PASSWORD_CHANGE_SELF`, `PASSWORD_CHANGE_FAILED` | PRD FR-20 (audit aktivitas penting) | — |
| B19 | Fase 3 memperkenalkan lapisan **Model** (`app/Models/`) — pembungkus tipis PDO. Alur tetap **Controller → Service (aturan bisnis + transaksi + audit) → Model (SQL)** | Service sudah memuat aturan bisnis Fase 1–2; memisahkan SQL membuat service lebih mudah diuji dan konsisten dengan PRD (MVC) | Model yang "terlalu pintar" harus dihindari; aturan tetap di Service |
| B20 | **Kode master (kategori/supplier/lokasi/sparepart) dinormalkan UPPERCASE** (`MasterRules::code()`); keunikan bergantung pada collation `utf8mb4_unicode_ci` (case-insensitive) | Menghindari "FLT-1" vs "flt-1" dianggap berbeda | Kode non-alfanumerik tetap apa adanya (hanya huruf yang di-uppercase) |
| B21 | **Panjang kolom dijaga eksplisit di PHP** (`MasterRules`/Service) | MariaDB di lingkungan ini berjalan **tanpa `STRICT_TRANS_TABLES`** → kelebihan panjang dipotong **diam-diam** bila tidak dijaga | Bila `sql_mode` kelak diperketat, penjagaan PHP tetap benar (lapis ganda) |
| B22 | Kolom `spareparts.stock` **tidak pernah** dapat diisi/diubah dari formulir master. Saat **tambah** sparepart, opsional `initial_stock` dialirkan ke `StockInitialService` sehingga tetap tercatat sebagai pergerakan `STOK_AWAL` | Menjaga kejujuran ledger (tidak ada perubahan stok tanpa jejak) | Bila produk ingin stok awal dipisah dari pembuatan master, cukup hapus blok opsional itu dari form |
| B23 | **Impor bersifat atomik & tidak menimpa**: pratinjau dulu (nomor baris + alasan), komit hanya bila **nol baris bermasalah**, satu transaksi, kode duplikat **ditolak** (tidak ada opsi overwrite), batas **500 baris** dan **2 MB** per berkas; pratinjau disimpan di sesi dengan **token sekali pakai** | Sesuai permintaan Fase 3 ("default tolak kode duplikat", ringkasan berhasil/gagal + nomor baris + alasan, atomik) | Overwrite massal tidak tersedia; bila dibutuhkan kelak, harus jadi fitur eksplisit dengan audit terpisah |
| B24 | **Stok awal hanya sekali per sparepart** — dijaga service (`StockInitialService`) **dan** trigger DB `trg_stock_movements_single_initial` (migrasi 008). Koreksi berikutnya wajib lewat `PENYESUAIAN_OPNAME` (FR-10) | Permintaan Fase 3: "stok awal tidak bisa diterapkan dua kali untuk item yang sama tanpa prosedur koreksi eksplisit" | ✅ Prosedur koreksi tersedia sejak Fase 4 lewat **Stock Opname** (lihat B33) |
| B25 | `stock_movements.movement_date` (tanggal **bisnis**, diisi pengguna) dipisah dari `created_at` (waktu **sistem**); migrasi 009 mengisi baris lama dari `DATE(created_at)` | Stok awal bisa dihitung kemarin, penerimaan barang tanggal invoice → laporan periode Fase 5 akurat | Semua laporan Fase 5 harus memakai `movement_date` |
| B26 | **Kategori nonaktif** ditolak untuk sparepart **baru** (`CategoryService::resolveUsable`), tetapi sparepart lama tetap boleh diubah selama kategorinya tidak diganti. **Supplier nonaktif** tetap boleh tersimpan sebagai asal barang pada master sparepart, tetapi **tidak boleh dipakai untuk transaksi baru** — diblokir lewat `SupplierService::ensureUsableForTransaction()` (penegakan penuh di Fase 4, sudah diuji sekarang) | Sesuai FR-11/FR-12 dan permintaan Fase 3 | ✅ Fase 4: `GoodsReceiptService` memanggil `ensureUsableForTransaction()` pada setiap penerimaan baru |
| B27 | Template impor berisi **baris header saja** (tanpa data contoh); contoh nilai ditampilkan di tabel halaman impor | Mencegah data contoh ikut terimpor tanpa sengaja | — |
| B28 | RBAC Fase 3: **membaca** data master = semua peran login; **menulis** (tambah/ubah/aktif-nonaktif/impor/stok awal) = **hanya Partman**. Admin & Kepala Bengkel menerima **403** di server; tombol hanya disembunyikan sebagai pelengkap UI | PRD §6.3 "Admin tidak mengubah data master operasional"; menyembunyikan tombol bukan pengamanan | Bila produk ingin Admin boleh mengelola master, cukup ubah daftar role pada route |
| B29 | Audit mencatat aktivitas Fase 3: `CATEGORY_CREATE/UPDATE/ACTIVATE/DEACTIVATE`, `SUPPLIER_*`, `LOCATION_*`, `SPAREPART_CREATE/UPDATE/ACTIVATE/DEACTIVATE`, `STOCK_INITIAL_CREATE`, `IMPORT_MASTER`, `IMPORT_STOCK_INITIAL` | PRD FR-20 | — |
| B30 | **Satu sparepart per dokumen** pada formulir barang masuk & barang keluar Fase 4. Skema `goods_receipts/goods_issues` + `*_items` tetap dipakai apa adanya agar Fase 6 dapat menambah multi-item tanpa migrasi ulang | Form bengkel sehari-hari umumnya satu item per nota; menyederhanakan validasi PO & penguncian stok | Bila produk ingin multi-item sekarang, form + service perlu diubah (skema sudah siap) |
| B31 | **Penerimaan terkait PO sudah ditegakkan** walau UI PO baru dibuat Fase 6: PO harus ada, status bukan `DIBATALKAN/DITOLAK`, supplier sama, sparepart adalah item PO, dan qty diterima ≤ **sisa** PO. Penerimaan memperbarui `purchase_order_items.received_qty` dan status PO menjadi `SEBAGIAN_DITERIMA` / `SELESAI_DITERIMA` | Mencegah stok bertambah melebihi pesanan sejak sekarang; data PO dari Fase 6 langsung konsisten | Bila aturan penerimaan PO berubah (mis. boleh lebih), ubah `GoodsReceiptService::resolvePo()` |
| B32 | **Penomoran dokumen** `GR-YYYYMM-####`, `GI-YYYYMM-####`, `SO-YYYYMM-####` disimpan di tabel `document_sequences` (satu baris per prefix+periode) dan dinaikkan dengan **row lock di dalam transaksi**. Periode mengikuti **tanggal bisnis** dokumen | Dua pengguna yang menyimpan bersamaan tidak pernah memperoleh nomor sama; nomor tidak "bolong" karena rollback | Nomor kembali ke `0001` setiap bulan; bila produk ingin nomor global, ubah `DocumentNumberService` |
| B33 | **Opname menyesuaikan terhadap stok TERBARU saat konfirmasi**, bukan terhadap snapshot. Selisih = `fisik − stok_saat_konfirmasi` (baris sparepart dikunci), sehingga hasil akhir **selalu sama dengan jumlah fisik** walau ada transaksi di tengah sesi. Halaman menandai item "berubah sejak snapshot". **Hanya satu sesi OPEN** pada satu waktu. Alasan per item wajib bila selisih; **catatan sesi** dipakai sebagai alasan cadangan bila selisih baru muncul setelah snapshot | Menghindari stok "melompat" ke nilai snapshot yang sudah usang; satu sesi mencegah dua penghitungan bersamaan | Bila produk ingin selisih dihitung terhadap snapshot, ubah `StockOpnameService::confirm()` + dokumentasikan konsekuensinya |
| B34 | **Bukti transaksi** (opsional): JPG/JPEG/PNG/PDF, maks **5 MB**, diperiksa dari **isi berkas** (`finfo`) + kecocokan ekstensi + `getimagesize()` untuk gambar. Disimpan **di luar web root** (`storage/private_uploads/<kategori>/YYYY/MM/`) dengan nama acak 32 hex; nama asli hanya jadi keterangan. Unduhan lewat route ber-otorisasi `GET /transaksi/masuk/{id}/bukti`. Berkas yatim dihapus bila penyimpanan transaksi gagal | PRD §10.1 (validasi unggahan, jangan simpan di web root) | `storage/private_uploads` wajib dapat ditulis proses Apache; lihat INSTALL.md |
| B35 | **Koreksi transaksi = pergerakan pembalik baru** `KOREKSI` dengan `reversal_of_id` + `reference_type = CORRECTION`; transaksi asal **tidak pernah** diubah/dihapus. Yang dapat dikoreksi: `MASUK` & `KELUAR` (`StockMovement::CORRECTABLE_TYPES`). Koreksi **boleh sebagian**; total pembalikan ≤ kuantitas asal (diperiksa di dalam transaksi pada baris sparepart yang dikunci). `STOK_AWAL` & `PENYESUAIAN_OPNAME` tidak dikoreksi dengan cara ini — gunakan stock opname. Alasan wajib 3–500 karakter | PRD §7.4 & §9 aturan 8; audit sebelum/sesudah | Bila koreksi harus boleh membalik baris opname juga, perlu aturan bisnis baru |
| B36 | **Status stok 4 tingkat**: `aman` (> 150% minimum), `menipis` (100%–150%), `kritis` (> 0 dan < minimum), `habis` (= 0). Bila `min_stock ≤ 0` dan stok > 0 → status pengecualian eksplisit **`tanpa_minimum`** (tanpa pembagian nol) | Permintaan Fase 4 + PRD §13.3 | Bila produk ingin `menipis` mulai tepat 100%, sudah sesuai; ubah `Sparepart::DILUT_RATIO` bila ambang berubah |
| B37 | Penerimaan bertipe **`pembelian`** memperbarui `last_purchase_date`, **`purchase_price` = harga pembelian terakhir**, dan `avg_purchase_price` = weighted average `(stok_lama × rata_lama + qty × harga_baru) / (stok_lama + qty)`. Tipe **`lainnya`** hanya menambah stok. **Nilai persediaan memakai `avg_purchase_price`**; `purchase_price` adalah harga terakhir. Riwayat harga tetap lengkap di `goods_receipt_items.unit_price` dan ditampilkan pada detail sparepart (10 pembelian terakhir) | Keputusan pemilik produk 18 Okt 2026: harga master mengikuti pembelian terakhir, weighted average dipakai untuk nilai persediaan, riwayat tetap tertelusur | Bila kebijakan berubah, ubah `Sparepart::applyPurchase()` + `purchase_price` pada tampilan |
| B38 | Audit mencatat aktivitas Fase 4: `GOODS_RECEIPT_CREATE`, `GOODS_ISSUE_CREATE`, `STOCK_OPNAME_START`, `STOCK_OPNAME_INPUT`, `STOCK_OPNAME_CONFIRM`, `TRANSACTION_CORRECTION`. Koreksi menyimpan nilai **sebelum/sesudah** di `old_values`/`new_values` (termasuk id transaksi asal) | PRD §7.4, FR-20 | — |
| B39 | **Backup database Fase 9**: berkas `.sql` hanya di `storage/backups/` (di luar web root), nama dibuat server, batas default **100 MB** (`BACKUP_MAX_MB`), **tanpa penghapusan otomatis** (retensi manual). `mysqldump`/`mysql` dijalankan via `proc_open` array tanpa shell; kredensial lewat file sementara 0600. Metadata mencatat checksum SHA-256, nama DB, jumlah migrasi, pelaku, dan waktu | Keamanan + dapat diaudit; mencegah path/command injection dan kebocoran kredensial | Perlu kebijakan retensi/off-site dari pemilik produk |
| B40 | **Restore hanya menerima backup buatan aplikasi** dengan checksum, nama DB, dan jumlah migrasi yang sama; wajib password Admin + teks `PULIHKAN`; membuat backup keselamatan otomatis terlebih dahulu; rollback otomatis bila import gagal; sesi login diakhiri setelah restore berhasil | Mencegah restore lintas versi/salah DB dan memberi jalur pemulihan bila proses terputus | Restore ke versi skema lain harus lewat prosedur manual di luar aplikasi |
| B41 | **Logo bengkel**: PNG/JPEG maks. 1 MB, divalidasi dari isi berkas (`finfo` + `getimagesize`), disimpan di `storage/documents/logos/YYYY/MM/` dengan nama acak, disajikan lewat `/pengaturan/logo` (semua role login), dipakai kop dokumen cetak. **SVG ditolak** | SVG dapat memuat JavaScript (XSS); route ber-otorisasi mencegah akses langsung ke storage | Bila produk ingin format lain, tambahkan validasi khusus dan sanitasi |
| B42 | **Penyempurnaan Audit Log**: filter pengguna/aktivitas/entitas/ID/kata kunci/tanggal + ekspor CSV maks. 10.000 baris; CSV memakai BOM UTF-8 dan awalan apostrof untuk sel yang dimulai `= + - @` (anti formula injection); ekspor dicatat `AUDIT_LOG_EXPORT`. Header keamanan global ditambahkan (`nosniff`, `SAMEORIGIN`, CSP, referrer, permissions, no-store) | Memenuhi pemeriksaan keamanan Fase 9 tanpa mengubah sifat append-only audit | Ekspor besar dibatasi; bila perlu ekspor penuh, gunakan backup/CLI dengan kontrol akses terpisah |

## C. Register PRD §18 — MENUNGGU keputusan (belum boleh dianggap final)

| # | Butir PRD §18 | Fase terkait | Status |
|---|---|---|---|
| C1 | Lead time supplier: nilai & cara pengumpulan datanya | 7 (Prediksi) | ✅ **DISETUJUI & DITERAPKAN (19 Okt 2026, lihat H2)**: diprioritaskan dari **riwayat penerimaan PO** (median selisih tanggal PO→penerimaan); fallback master `suppliers.lead_time_days`; bila keduanya kosong → **asumsi 7 hari** + label eksplisit "ROP belum andal". **Partman boleh override manual dengan alasan wajib**, dicatat pada audit `LEAD_TIME_OVERRIDE` |
| C2 | Parameter AI: jendela Moving Average, evaluasi Linear Regression, rumus Safety Stock/ROP, ambang fast/slow moving | 7 | ✅ **DISETUJUI & DITERAPKAN (19 Okt 2026, lihat H3–H4)**: MA **jendela 7 hari**, `Z = 1,65`, `test_ratio = 0,30`, pemisahan **kronologis** (bukan random), MA & LR dievaluasi dengan hasil aktual (MAE/RMSE/MAPE). Model **tidak dinyatakan akurat** tanpa dukungan evaluasi; parameter & versi dicatat per baris. Ambang fast/slow tetap dikonfigurasi Admin (F6) |
| C3 | Kecukupan data minimum sebelum prediksi ditampilkan | 7 | ✅ **DISETUJUI & DITERAPKAN (19 Okt 2026, lihat H1)**: minimal **30 hari data historis** & **5 transaksi barang keluar**. Bila belum terpenuhi → status **Data terbatas**, prediksi tidak dihitung, **tanpa angka karangan** |
| C4 | Metode harga rata-rata (rekomendasi: weighted average) | 4 | ✅ **FINAL (keputusan pemilik produk, 18 Okt 2026)**: `purchase_price` **diperbarui otomatis mengikuti harga pembelian terakhir**; **weighted average** tetap dihitung & disimpan terpisah di `avg_purchase_price` dan **dipakai sebagai dasar nilai persediaan**; riwayat harga dapat ditelusuri lewat `goods_receipt_items.unit_price` + halaman detail sparepart (lihat B37) |
| C5 | Detail pembayaran: nominal/tanggal/metode (baru status disetujui) | 6 | 🔶 **DITERAPKAN SEMENTARA (menunggu konfirmasi, lihat G1)**: hanya **status + catatan teks**; tanpa tanggal/nominal/metode terstruktur |
| C6 | Prosedur perubahan kuantitas/harga PO setelah disetujui | 6 | 🔶 **DITERAPKAN SEMENTARA (menunggu konfirmasi, lihat G2)**: kuantitas **tidak dapat diubah** setelah disetujui; perbaiki lewat penerimaan batal/pembatalan + PO baru beralasan |
| C7 | Pembatalan PO: pihak berwenang & alasan | 6 | 🔶 **DITERAPKAN SEMENTARA (menunggu konfirmasi, lihat G3)**: **pembatalan beralasan** menggantikan penghapusan; otorisasi = pembuat (Partman) + Admin; PO dengan penerimaan tidak dapat dibatalkan |
| C8 | Penerimaan melebihi sisa PO: otorisasi | 6 | 🔶 **DITERAPKAN SEMENTARA (menunggu konfirmasi, lihat G4)**: **SELALU DITOLAK** — tanpa prosedur pengecualian; perbaiki lewat penerimaan baru beralasan atau opname |
| C9 | Versi teknis final (PHP/MySQL/Python/Gemini) | semua | ✅ sebagian: PHP 8.2.12, MariaDB 10.4.32, Python 3.12 — **model Gemini dipilih pada Fase 8, tetapi koneksi nyata ke Google belum diuji (tidak ada API key)** |
| C10 | Integrasi PHP↔Python | 7 | ✅ D2 (CLI proc_open) |
| C11 | Backup: lokasi, retensi, batas ukuran, prosedur pemulihan | 9 | **DITERAPKAN (19 Okt 2026, B39–B40)**: `storage/backups/` di luar web root; retensi **manual**; batas default **100 MB**; restore wajib checksum + password + `PULIHKAN` + backup keselamatan otomatis. Kebijakan retensi/off-site tetap menunggu keputusan pemilik produk |
| C12 | Session timeout | 2 | ✅ **FINAL**: tidak ada timeout (D5); risiko perangkat bersama didokumentasikan di INSTALL.md §6; session ID di-regenerate saat login; cookie `HttpOnly` + `SameSite=Lax` |
| C13 | Akses LAN: firewall, IP server, prosedur client | 9 (instalasi) | ⏳ Panduan ditulis di INSTALL.md §5; **IP statis = tanggung jawab opsional** |
| C14 | Kebijakan data apa yang boleh dikirim ke Gemini | 8 | 🔶 **DITERAPKAN, MENUNGGU TINJAUAN PRIVASI (19 Okt 2026, lihat J1–J8)**: hanya pertanyaan + konteks agregat/daftar terbatas per role; tanpa dump DB, rahasia, audit mentah, atau data pengguna massal; stateless + `store=false`; guard deterministik sebelum API. Koneksi Google **belum diuji dengan key nyata** |

## D0. Insiden pemindahan folder (18 Okt 2026) — catatan transparan

Saat rename `smartpartman2` → `smartpartman`, folder tujuan ternyata **sudah
berisi proyek lama** `htdocs\SmartPartMan` (Windows case-insensitive: sama dengan
`smartpartman`). Proses harness memegang folder sumber sehingga rename langsung
gagal, dan salinan pertama jadi **tergabung** dengan proyek lama.

Konsekuensi jujur:
1. **File proyek lama yang ber-Path SAMA dengan proyek baru tertimpa**
   (`index.php`, `.htaccess`, `README.md`, `.env`, `config/config.php`,
   `app/autoload.php`, `app/Views/layouts/`, `app/Helpers/`, `storage/*`).
   Versi lama file-file itu **tidak bisa dipulihkan** (tidak ada backup sebelum
   salin).
2. **File khas proyek lama yang path-nya berbeda UTUH** — semua sudah diarsipkan
   ke: `C:\xampp\htdocs\_arsip_SmartPartMan_lama_20261018\`
   (controllers/models/views lama, `public/`, 15 file migrasi September, dll).
3. **Database lama `smartpartman` TIDAK tersentuh** (masih rusak seperti sebelumnya,
   file `.frm/.ibd` utuh di datadir — tetap bisa dipulihkan pihak ahli bila perlu).
4. Folder `smartpartman2` sudah **dikosongkan penuh** (sisa direktori kosong bisa
   dihapus manual setelah aplikasi/opencode ditutup).
5. Folder final `smartpartman` kini **bersih**: 80 file, identik dengan sumber,
   seluruh pengujian lulus (91 asersi) dan alur HTTP terverifikasi ulang.

Saran: bila pemilik produk masih membutuhkan proyek v1, gunakan arsip di atas +
database `smartpartman` (perlu pemulihan IBD). Jika tidak, arsip boleh dihapus
setelah dikonfirmasi.

## D. Temuan lingkungan (penting untuk fase berikut)

1. **Database lama `smartpartman` rusak** — semua tabel error `doesn't exist in
   engine` meski file `.frm/.ibd` ada (kemungkinan file disalin/dari instance
   lain). Tabel lama: `parts`, `stock_transactions`, `otp_verifications`,
   `notifications`, `purchase_recommendations`, dst. **Tidak disentuh.**
   Proyek lain terlihat di `C:\xampp\htdocs\SmartPartMan\` (jejak di Apache log).
2. **`extension=zip` sudah DIAKTIFKAN** (18 Okt 2026) — `C:\xampp\php\php.ini`
   baris 962 (`extension=zip`), cadangan asli: `php.ini.bak-smartpartman-20261018`.
   Diperlukan untuk impor & template XLSX (Fase 3). CLI langsung terpakai;
   Apache di-restart agar jalur web ikut aktif (diverifikasi lewat unduhan
   template `.xlsx` = HTTP 200 + tanda tangan ZIP `PK`).
3. **`extension=gd` tidak aktif** — logo cukup disimpan apa adanya bila tidak diaktifkan.
4. Python 3.14 & 3.12 keduanya terpasang → target **3.12** (kompatibilitas wheel pandas/sklearn).
5. `php`/`mysql` tidak di PATH → semua konfigurasi memakai path absolut XAMPP.
6. **MariaDB di sini berjalan tanpa `STRICT_TRANS_TABLES`**
   (`sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`) — penjagaan
   panjang/tipe nilai wajib eksplisit di PHP (lihat B21).
7. **Apache tidak terpasang sebagai Windows service** — httpd berjalan sebagai
   proses mandiri. Setelah mengubah `php.ini`, hentikan proses `httpd.exe` lama
   lalu jalankan ulang `C:\xampp\apache\bin\httpd.exe -d C:/xampp/apache`
   (tanpa langkah ini `extension=zip` tidak terbaca oleh jalur web).
8. **Database pengembangan `smartpartman_v2` sudah dimigrasi penuh** (10 migrasi,
   termasuk 008 trigger stok awal, 009 `movement_date`, 010 dukungan transaksi
   Fase 4). **Data demo `HTTP-`/`HTTP4-` sudah dibersihkan (18 Okt 2026)** sesuai
   keputusan pemilik produk: seluruh entitas demo **dinonaktifkan** (semuanya
   memiliki relasi/riwayat), stok dinolkan lewat **Stock Opname**
   `SO-202610-0002` (alasan tercatat), dan **ledger/dokumen transaksi tidak
   dihapus**. DB produksi kini **tidak memiliki data master aktif** — data asli
   harus diisi/diimpor Partman sebelum pemakaian.
9. **Akun verifikasi `partman1` (role `partman`) & `kepala1` (role
   `kepala_bengkel`) dipertahankan**, aktif, dengan **password acak baru** yang
   semula ditulis ke `storage/private/akun_verifikasi.txt` (di luar web root,
   ditolak `.htaccess` = 403; ACL dibatasi ke pengguna Windows saat ini). Password
   **tidak pernah** dicetak ke log/aplikasi. **Berkas plaintext sudah dihapus
   pada 19 Okt 2026** setelah kredensial disimpan; bila password hilang, Admin
   dapat mereset lewat menu *Pengguna*.
10. **Pengujian HTTP kini terpisah dari produksi**: `php tests\http_verify.php`
    menukar `.env` ke `DB_TEST_NAME` selama pengujian (dengan backup +
    pemulihan otomatis via `finally`/shutdown), membuat akun uji khusus, lalu
    memulihkan `.env`. Jalankan saat tidak ada pengguna lain (jeda beberapa detik).
11. **`storage/private_uploads/`** dipakai untuk bukti transaksi (di luar web
   root, ditolak `.htaccess` bila diakses langsung). Folder dibuat otomatis
   oleh `AttachmentService`; proses Apache harus punya izin tulis.

## E. Keputusan pemilik produk setelah Fase 4 (18 Okt 2026)

| # | Keputusan | Penerapan |
|---|---|---|
| E1 | **Harga beli**: `purchase_price` diperbarui otomatis mengikuti harga pembelian terakhir; **weighted average** disimpan & dihitung terpisah sebagai dasar nilai persediaan; riwayat harga tetap tertelusur | `Sparepart::applyPurchase()` (harga terakhir + rata-rata), `Sparepart::purchasePriceHistory()`, kartu **Riwayat Harga Beli** di detail sparepart; diuji di `test_transaction_receipt_*` (harga terakhir menang, rata-rata tertimbang terpisah, riwayat 2 pembelian) |
| E2 | **Akun verifikasi** `partman1` & `kepala1` dipertahankan, role dipastikan benar, password bawaan diganti dengan password aman | Rotasi password + verifikasi role; kredensial plaintext semula di `storage/private/akun_verifikasi.txt` (403 dari web) dan **sudah dihapus (19 Okt 2026)** setelah disimpan; audit `USER_PASSWORD_RESET`; password **tidak** dicetak ke log/laporan |
| E3 | **Data master demo** `HTTP-`/`HTTP4-` dibersihkan; hapus hanya bila tanpa relasi, selain itu nonaktifkan; data asli tidak disentuh | 10 sparepart + 4 kategori + 4 supplier + 2 lokasi demo **dinonaktifkan** (semuanya berelasi); stok dinolkan lewat opname `SO-202610-0002` dengan alasan; data non-demo (tidak ada) tidak disentuh — lihat TODO.md §Pemeliharaan |
| E4 | **Transaksi verifikasi tidak dihapus**; gunakan koreksi/pembatalan beralasan; jaga konsistensi stok & audit log; pengujian berikutnya dipisahkan dari produksi | Ledger utuh (22 baris; transaksi asal tidak berubah), dokumen penerimaan/pengeluaran tetap ada, penyesuaian lewat opname; harness baru `tests/http_verify.php` memakai DB uji dan memulihkan `.env` otomatis |

## F. Keputusan & catatan Fase 5 (19 Okt 2026)

| # | Butir | Status / penerapan |
|---|---|---|
| F1 | **Notifikasi hanya di dashboard** — tanpa email, WhatsApp, atau pusat notifikasi terpisah | `NotificationService::forRole()`: stok habis/kritis/menipis, stok minimum belum diatur, status reorder & PO, pembayaran belum lunas. Isi mengikuti hak akses role. **Menunggu konfirmasi** bila nanti perlu kanal lain |
| F2 | **Laporan/ekspor/cetak terbuka untuk semua role; pengaturan hanya Admin; dashboard berbeda per role** | Route `/laporan*` memakai `$anyRole`, `/pengaturan` memakai `$adminOnly`, `/dashboard` memilih isi berdasarkan `Auth::user()['role']` |
| F3 | **Grafik dirender server sebagai SVG responsif** (`ChartService`) — tanpa CDN/library eksternal | Konsisten dengan kebijakan tanpa pustaka luar; grafik tetap terbaca di layar kecil |
| F4 | **PDF & Excel dihasilkan murni PHP**: `PdfService` (A4, potret/lanskap, pemenggalan kata, paginasi, kop halaman berulang) dan `XlsxWriter` (nama sheet opsional) | Tidak ada pustaka Python PDF/Excel. Ekstensi yang dipakai: `zip` + `fileinfo`; `gd` tidak diperlukan |
| F5 | **Metode nilai persediaan dinyatakan eksplisit** — utama: **harga beli rata-rata tertimbang** (`COALESCE(avg_purchase_price, purchase_price)` × stok); **harga beli terakhir** ditampilkan sebagai pembanding dengan catatan kaki | Sesuai keputusan C4/E1. Halaman **tidak mengklaim angka pasti** sebelum metode disepakati & diuji bersama pemilik produk — **menunggu konfirmasi** |
| F6 | **Parameter fast/slow moving dapat dikonfigurasi Admin** — default: periode **30 hari**, ambang cepat **10**, ambang lambat **2**; label Cepat/Sedang/Lambat; **aturan dicetak pada laporan** | Disimpan di `app_settings` (`moving_period_days`, `fast_moving_threshold`, `slow_moving_threshold`) + validasi (slow < fast; periode 7–365). Nilai **default menunggu konfirmasi** (terkait C2) |
| F7 | **Purchase Order bersifat baca-saja di Fase 5** — laporan, cetak, dan PDF PO tersedia; **pembuatan/persetujuan PO tetap Fase 6** | Rute `/laporan/po/{id}/cetak|pdf` memakai `PurchaseOrder::find/items` (read-only). Fixture PO pada uji HTTP disisipkan langsung ke DB uji, bukan lewat UI |
| F8 | **Bagian AI berstatus placeholder/empty state yang eksplisit** sampai modul AI aktif | Rekomendasi reorder (dashboard Partman), prediksi (dashboard KB, laporan `ai`), dan chatbot (pintasan Admin) semuanya menyatakan jelas "belum aktif" + fase yang akan mengaktifkannya |
| F9 | **Pengaturan memakai tabel `app_settings` yang sudah ada — tanpa migrasi baru** | `AppSetting::putMany()` menulis hanya nilai yang **berubah**; setiap perubahan tercatat di Audit Log `SETTINGS_UPDATE` (nilai lama → baru, tanpa rahasia) |
| F10 | **Batas maksimum 5000 baris per laporan** (`ReportService::MAX_ROWS`) | Mencegah PDF/HTML tampilan membengkak; filter disarankan bila hasil terpotong |
| F11 | **Uji dashboard fast/slow tidak lagi mengasumsikan fixture masuk peringkat top-5** | Database uji menumpuk sisa fixture antar-run sehingga peringkat terisi fixture lain. Uji kini memakai **periode yang sama dengan batas lebih lebar (50)** dan memeriksa ledger langsung — perilaku aplikasi tidak diubah |

## G. Keputusan & aturan Fase 6 (19 Okt 2026) — DITERAPKAN, MENUNGGU KONFIRMASI

Butir-butir di bawah sudah **diimplementasikan & diuji** pada Fase 6 sebagai
jawaban sementara atas C5–C8 dan kebutuhan desain baru. Bila pemilik produk
bermusyawarah lain, ubahnya lewat kode + regresi uji — bukan lewat database.

| # | Butir | Penerapan |
|---|---|---|
| G1 | **Pembayaran = status + catatan (C5)** — `BELUM_DIBAYAR`/`DIBAYAR_SEBAGIAN`/`LUNAS` + catatan teks Admin; tanpa tanggal, nominal terstruktur, atau metode pembayaran; **bukan modul akuntansi** | `PurchaseOrderService::updatePayment()` (Admin saja, status harus berubah); setiap perubahan tercatat di `purchase_payment_history` (status lama → baru, catatan, pelaku, waktu) + Audit Log `PO_PAYMENT_UPDATE`; status pembayaran **terpisah** dari status penerimaan & persetujuan — diuji `test_po_pembayaran_terpisah_dan_beriwayat` |
| G2 | **Kuantitas/isi PO tidak diubah setelah diajukan (C6)** — draft dapat diedit Partman (item dihapus & disusun ulang via `PurchaseOrder::deleteItems`); setelah `ajukan`, hanya persetujuan/penolakan/pembatalan yang mungkin; perbaikan kesalahan = batalkan (G3) + buat PO baru beralasan, sehingga riwayat angka tetap jujur | `PurchaseOrderService` menolak edit pada status non-DRAFT; `reject`/`cancel` mewajibkan alasan; alur penuh diuji `test_po_alur_persetujuan_dan_proses` + `test_po_pembatalan_bukan_hapus` |
| G3 | **Pembatalan beralasan menggantikan penghapusan PO (C7)** — **tidak ada hard-delete** PO/item/riwayat; otorisasi: **pembuat PO (Partman)** membatalkan DRAFT/MENUNGGU_PERSETUJUAN miliknya; **Admin** juga membatalkan DISETUJUI/DIPROSES_ADMIN yang belum/tidak sempat diterima; **Kepala Bengkel tidak membatalkan** — penolakannya lewat `tolak` (riwayat tersendiri); **PO dengan riwayat penerimaan tidak dapat dibatalkan** (tutup lewat penerimaan penuh atau opname bila fisik berbeda) | Kolom `cancelled_by/at/reason` terisi wajib (`reason`); status `DIBATALKAN` + riwayat status; Audit Log `PO_CANCEL`; baris PO + item + riwayat selalu tersimpan — diuji `test_po_pembatalan_bukan_hapus` |
| G4 | **Kelebihan penerimaan di atas sisa PO SELALU ditolak (C8)** — tanpa prosedur pengecualian/otorisasi khusus, siapa pun perekamnya; penerimaan hanya mengurangi `remaining_qty` dan berhenti di nol | `GoodsReceiptService::resolvePo()` mengunci baris item (`FOR UPDATE`) dan menolak bila `quantity > remaining`; status otomatis `SEBAGIAN_DITERIMA` → `SELESAI_DITERIMA`; penerimaan ganda pada PO selesai ikut ditolak — diuji `test_po_alur_lengkap_reorder_ke_penerimaan` + `test_penerimaan_po_belum_disetujui_ditolak` + HTTP |
| G5 | **Setiap perubahan status PO akibat penerimaan ikut tercatat di riwayat status** (aktor = pencatat penerimaan, waktu, transisi lama → baru, rujukan nomor dokumen masuk) — bukan hanya persetujuan/penolakan | `GoodsReceiptService::applyPoReceipt()` mengunci header PO & menulis `purchase_status_history` dalam transaksi yang sama; alur lengkap menghasilkan **6 transisi** (buat → menunggu → disetujui → diproses → sebagian → selesai) |
| G6 | **Status persetujuan tidak memakai DRAFT untuk reorder** — permintaan langsung `MENUNGGU_PERSETUJUAN` saat dibuat (skema menyediakan `DRAFT` demi kelengkapan, tidak dipakai); PO tetap memakai alur DRAFT → diajukan | `ReorderRequestService::create()`; alur PO: draft → `ajukan` → `setujui`/`tolak` — diuji `test_reorder_partman_membuat_permintaan`, `test_po_alur_persetujuan_dan_proses` |
| G7 | **Saran jumlah reorder (C8 sementara)** = `ceil(minimum − stok)`; bila stok ≥ minimum → tanpa saran (`suggested_qty` null, sumber `tidak_ada`); sumber `aturan_minimum` dicatat di baris permintaan (`prediction_id` null). Prediksi AI (Fase 7) mengganti aturan ini tanpa mengubah alur persetujuan | `ReorderRequestService::suggestion()`; **mengubah angka dari saran wajib `change_reason`** (dibanding angka saran, bukan angka sebelumnya); kembali ke angka saran tidak perlu alasan — diuji `test_reorder_saran_aturan_minimum`, `test_reorder_ubah_jumlah_wajib_alasan` |
| G8 | **Satu permintaan reorder melahirkan maksimal satu PO**; PO yang tertaut wajib memuat sparepart yang sama dengan permintaannya; hanya reorder `DISETUJUI` yang boleh dijadikan PO | Guard di `PurchaseOrderService::create()` (`reorder_request_id`) — diuji `test_po_dari_permintaan_reorder` |
| G9 | **Rekomendasi supplier bersifat informatif, keputusan akhir di tangan Partman** — peringkat dari riwayat pembelian (kali beli ↓, tanggal terakhir ↓, harga terakhir ↓) + supplier default sparepart sebagai fallback; tanpa riwayat & tanpa default → tanpa rekomendasi | `PurchaseOrderService::supplierRecommendations()` (AJAX `/pembelian/rekomendasi-supplier`); formulir tetap memilih supplier sendiri — diuji `test_po_rekomendasi_supplier_dari_riwayat` |
| G10 | **Nomor dokumen** reorder = `RQ-YYYYMM-####`, PO = `PO-YYYYMM-####` (berurutan per bulan, pembuat `DocumentNumberService`, dihitung ulang aman lewat lock) | Konsisten dengan pola `GR-`/`GI-`/`SO-` Fase 4 |
| G11 | **Riwayat status = tabel `purchase_status_history` append-only** (dokumen `REORDER`/`PO`) dengan trigger database menolak UPDATE/DELETE — setara sifat ledger stok & Audit Log | Migrasi `011_purchase_status_history.sql`; aktor, waktu, status lama → baru, alasan (wajib terisi saat tolak/cancel) — diuji seluruh uji reorder/PO |
| G12 | **Form barang masuk memakai pemilih PO terbuka** (bukan input nomor bebas): hanya PO `DISETUJUI`/`DIPROSES_ADMIN`/`SEBAGIAN_DITERIMA`, difilter per supplier, item & sisa otomatis terisi, harga ikut PO | `PurchaseOrder::openForReceipt()`; JS form masuk menyaring item per PO — diuji render view + alur parsial HTTP. Penerimaan **tanpa** PO tetap boleh dengan **alasan wajib** (aturan Fase 4) |

## H. Keputusan & aturan Fase 7 (19 Okt 2026) — DISETUJUI & DITERAPKAN

Modul **Prediksi Stok (AI)** menjawab C1–C3 secara sementara. Prediksi adalah
**rekomendasi**: tidak ada pesanan otomatis dan tidak ada transaksi inventory
yang berubah dari modul ini. Bila pemilik produk memutuskan lain, ubah lewat
kode + regresi uji — bukan lewat database.

| # | Butir | Penerapan |
|---|---|---|
| H1 | **Ambang kecukupan data (C3)** — dihitung atas riwayat **barang keluar** dalam rentang yang dipilih pengguna (7/30/90 hari target, riwayat 30–365 hari): `min_days_with_data = 30` hari berdata **dan** `min_transactions = 5` transaksi; **`sufficient_days = 60`** & **`sufficient_transactions = 10`** menandai `cukup`. Di bawah minimum → `tidak_cukup` (label tampilan **"Data terbatas"**); di antara minimum & batas penuh → `terbatas`; di atas keduanya → `cukup`. **Tanpa data cukup tidak ada angka dikarang**: baris tetap disimpan (`method = belum_dihitung`, kolom ramalan `NULL`) + alasan tertulis | `PredictionService::PARAMS`; `forecast.py` `DEFAULT_PARAMS`; diuji `test_prediction_kosong`, `test_prediction_data_sangat_sedikit`, `test_prediction_normal_dan_pembanding_manual`, `test_prediction_view_data_tidak_cukup` |
| H2 | **Prioritas lead time (C1)** — urutan sumber: (1) **override manual Partman** `manual_override` (opsional saat menjalankan analisis; **alasan wajib** minimal 10 karakter, 1–365 hari) — **selalu menang** atas nilai otomatis; (2) **riwayat penerimaan PO** `receipt_history` (median selisih tanggal efektif PO `COALESCE(approved_at, processed_at, created_at)` → `goods_receipts.receipt_date`, PO `DIBATALKAN`/`DITOLAK` dikecualikan, maks 20 sampel) → **andal**; (3) **master supplier** `supplier_master` (`suppliers.lead_time_days`) → **andal**; (4) **asumsi** `assumption` = `lead_time_fallback_days = 7` dengan `lead_time_assumed = true`. Bila tidak ada lead time terukur, Safety Stock/ROP **ditandai belum andal** dan halaman menjelaskannya. **Setiap override dicatat pada audit `LEAD_TIME_OVERRIDE`** (nilai otomatis → override + alasan) | `PredictionService::buildPayload()/run()/autoLeadTime()`; `forecast.py`; `details.lead_time = {days, assumed, source, samples, reliable, override?, reason?, auto_days?, auto_source?}`; diuji `test_prediction_lead_time_riwayat_penerimaan`, `test_prediction_lead_time_override`, `test_prediction_lead_time_override_pipeline`, `test_prediction_view_lead_time_asumsi`, `test_prediction_view_lead_time_override` |
| H3 | **Metode & parameter (C2)** — Moving Average (jendela `ma_window = 7`), Linear Regression (OLS), Safety Stock `Z × σ_harian × √lead_time` (`safety_z = 1.65` ≈ 95%), ROP = permintaan selama lead time + Safety Stock. Model: **`sfm-forecast/1.0.0`**, skema payload `SCHEMA = 1`. Seluruh parameter + versi pustaka dipersistenkan di `details.parameters` / `details.library_versions` | `python/prediction/forecast.py`; `PredictionService::PARAMS`; ditampilkan "Parameter & versi model" di detail |
| H4 | **Evaluasi & perbandingan metode objektif** — pemisahan **kronologis** latih/uji (`test_ratio = 0.3`; **tidak pernah** memakai data masa depan), metrik **MAE/RMSE/MAPE** untuk MA & LR, metode terpilih = **MAE terkecil** pada data uji (aturan dicatat). Bila data tak cukup untuk menguji, evaluasi ditandai tidak dilakukan | `forecast.py` (`evaluate`), `details.evaluation` / `details.comparison`; ditampilkan pada halaman detail |
| H5 | **Keluaran & tampilan** — estimasi permintaan, perkiraan hari ke stok minimum & ke nol, risiko stockout (rendah/sedang/tinggi), saran qty reorder, kategori **fast/slow**, indikator kecukupan data, waktu & rentang analisis, serta perbandingan MA vs LR. Muncul di **detail `/prediksi/{id}`**, **indeks `/prediksi`**, **dashboard Partman & Kepala Bengkel**, dan **laporan `ai`** (12 laporan: cetak/PDF/Excel) | `app/Views/prediksi/{index,show}.php`; `DashboardService`; `ReportService::aiReport()`; `app/Views/reports/*` |
| H6 | **Kategori fast/slow dihitung PHP, bukan Python** — memakai `SettingService::reportConfig()` (F6: periode & ambang fast/slow Admin) sehingga konsisten dengan laporan; Python **tidak** mengembalikan klasifikasi | `PredictionService::classification()`; diuji `test_prediction_klasifikasi_mengikuti_pengaturan` |
| H7 | **Integrasi lokal (C10/D2)** — PHP memanggil `python/prediction/forecast.py` via CLI **`proc_open` argumen-array tanpa shell** (`bypass_shell`), JSON lewat **stdin/stdout**. **Tidak ada endpoint jaringan**; skrip tidak diekspos publik. Batas waktu (`PredictionService::TIMEOUT_SECONDS`, default **20 detik**) **ditegakkan & proses benar-benar dihentikan** (`taskkill /F /T` lalu `proc_terminate` sebagai cadangan). Karena pipa `proc_open` tidak andal untuk timeout di Windows, keluaran dialihkan ke **berkas sementara** yang di-poll dengan tenggat `microtime`. Kegagalan Python **tidak pernah** memblokir transaksi inventory | `PredictionService::invokePython()` / `terminateProcess()`; `config/config.php` (`cli.python`, `cli.python_args`, `cli.predict_script`); `python/prediction/README.md`; diuji `test_prediction_proses_berhasil/gagal/timeout`, dan integrasi HTTP nyata di `tests/http_verify.php` |
| H8 | **Fallback & kejujuran kegagalan** — bila skrip hilang, JSON rusak, atau timeout: `PredictionService::run()` **tidak melempar**, mengembalikan `status = error` + saran fallback aturan stok-minimum, mencatat Audit **`PREDIKSI_GAGAL`**, dan transaksi inventory tetap berjalan. Keberhasilan dicatat **`PREDIKSI_DIBUAT`** (entitas `ai_predictions`) | `PredictionService::run()`; diuji `test_prediction_proses_*` |
| H9 | **RBAC** — melihat hasil (indeks/detail) = **semua role login** (`$anyRole`); **menjalankan** analisis = **hanya Partman** (`$partmanOnly` → `POST /prediksi/{id}/jalankan`). Admin/Kepala Bengkel menerima **403**; formulir di halaman detail hanya tampil untuk Partman. Tidak ada route prediksi tanpa `AuthMiddleware` | `routes/web.php`; `PredictionController`; diuji `test_prediction_rbac_route` + sesi HTTP per role |
| H10 | **Integrasi saran reorder (G7 dilanjutkan)** — saran dari prediksi hanya dipakai bila **segar** (`processed_at` ≤ **7 hari**, `PREDICTION_MAX_AGE_DAYS`) **dan** kecukupan `cukup`/`terbatas`; selain itu kembali ke **aturan minimum** `ceil(minimum − stok)`. Sumber & `prediction_id` dicatat di baris reorder; mengubah qty tetap **wajib alasan** | `ReorderRequestService::suggestion()`; diuji `test_prediction_integrasi_saran_reorder` |
| H11 | **Keterbatasan yang dinyatakan terbuka** — prediksi mengandalkan riwayat barang keluar; pola musiman/perubahan mendadak tidak dijamin akurat; tanpa lead time terukur ROP belum andal; hasil adalah **usulan**. Aplikasi **tidak menjanjikan akurasi yang belum diuji**. Sistem lama/tanpa riwayat → indikator data terbatas, tanpa angka | `details.warnings`; teks pada halaman detail & laporan `ai` |

## I. Operasional & keamanan (disetujui 19 Okt 2026)

| # | Butir | Penerapan |
|---|---|---|
| I1 | **Reset DB uji berkala** (keputusan pemilik produk) | `php tests\reset_test_db.php --yes` — **hanya** database berakhiran `_test`; **menolak** bila nama sama dengan DB produksi; menghapus & membangun ulang skema dari migrasi. DB produksi `smartpartman_v2` **tidak pernah disentuh**. Tanpa `--yes` hanya menampilkan target. |
| I2 | **Berkas sensitif tidak dapat diakses browser** | `.htaccess` root menolak `app|config|database|storage|tests|docs|python|routes` serta seluruh dotfile (`.env`); `storage/`, `python/`, `database/` masing-masing menolak semua akses. Diverifikasi lewat HTTP (403). |
| I3 | **Kredensial akun verifikasi** | `storage/private/akun_verifikasi.txt` (password acak) **dihapus** setelah pemilik menyimpan kredensial; folder `storage/` ditolak web. Bila password hilang, Admin dapat mereset lewat menu Pengguna. Password **tidak pernah** dicatat ke log/aplikasi (AuditService meredaksi otomatis). |
| I4 | **Parameter final C1–C3** | Disetujui pemilik produk 19 Okt 2026: MA jendela **7**, `Z = 1,65`, `test_ratio = 0,30`, split **kronologis**, ambang **30 hari / 5 transaksi**, status **"Data terbatas"** tanpa angka karangan, dan override lead time manual beralasan dengan audit. |

## J. Keputusan & aturan Fase 8 (19 Okt 2026) — DITERAPKAN, MENUNGGU TINJAUAN PRIVASI

Modul **Chatbot Gemini** adalah asisten **baca-saja**. Ia tidak memiliki akses
SQL/tool, tidak dapat mengubah transaksi, dan tidak dianggap sumber kebenaran
data — semua angka berasal dari konteks backend ber-RBAC. Keputusan C14
diterapkan sebagai berikut; tinjauan privasi pemilik produk masih diperlukan
sebelum pemakaian produksi.

| # | Butir | Penerapan |
|---|---|---|
| J1 | **API key hanya di server (C14)** — sumber prioritas: database terenkripsi lewat Pengaturan Chatbot; fallback `GEMINI_API_KEY` di `.env`. Tidak pernah dikirim ke frontend/log/Audit Log; UI hanya `****abcd` | `App\Core\Crypto` (AES-256-GCM dari `APP_KEY`, format `v1:base64(nonce+tag+ciphertext)`), `ChatbotSetting::maskKey()`; plaintext hanya di memori saat memanggil API; diuji `test_chatbot_crypto`, `test_chatbot_settings`, HTTP (DB plaintext/log/audit = 0) |
| J2 | **Konteks minimal per role** — Partman: persediaan, stok kritis, pergerakan terbaru, prediksi; Kepala Bengkel: nilai persediaan, pemakaian, laporan, PO/pembayaran, prediksi; Admin: statistik pengguna/persediaan/transaksi + aktivitas aplikasi. **Tanpa** dump tabel, password, API key, token, audit mentah, atau data pengguna massal | `ChatbotService::contextForRole()` memakai model/agregat backend ber-RBAC; `CONTEXT_MAX_CHARS = 12000`; prompt memuat aturan "jangan mengarang angka"; diuji `test_chatbot_service` + HTTP per role |
| J3 | **Guard deterministik sebelum API** — tolak permintaan SQL/operasi DB, API key/password/token/system prompt, prompt injection/role escalation, topik Admin untuk non-Admin, dan topik jelas di luar SmartPartMan | `ChatbotService::guard()`; penolakan dicatat `blocked`/`off_topic` dan **tidak memakai kuota Gemini**; diuji service + HTTP (jumlah panggilan stub tidak bertambah) |
| J4 | **Stateless & privasi** — riwayat percakapan hanya di sesi browser; setiap pertanyaan dikirim tanpa riwayat ke Gemini; permintaan memakai `store=false`. Log aplikasi menyimpan potongan pertanyaan maks **200 karakter** + status/error, **bukan jawaban lengkap** | `ChatbotService::respond()`, `ChatbotUsageLog`; diuji log tidak memuat jawaban/API key |
| J5 | **Pengaturan Admin** — aktif/nonaktif, API key, model (`gemini-3.5-flash-lite` default; `gemini-3.8-flash`), instruksi sistem, batas topik, gaya jawaban (ringkas/normal/detail), batas harian/bulanan, uji koneksi, ringkasan pemakaian/error. Batas `0`/kosong = tanpa batas | `ChatbotSettingsService`; migrasi `012`/`013`; perubahan dicatat Audit Log `CHATBOT_SETTINGS_UPDATE` tanpa nilai rahasia; route hanya Admin |
| J6 | **Uji koneksi hemat kuota** — `GET {GEMINI_API_URL}/models/{model}` dengan `x-goog-api-key`; API key yang diuji **tidak disimpan**; hasil sukses/gagal tidak membocorkan key | `GeminiService::testConnection()`; diuji `test_chatbot_settings` + HTTP stub (GET, model benar, key tersimpan tidak berubah) |
| J7 | **Ketahanan API** — API key kosong/tidak valid, kuota, API tidak tersedia, timeout (`GEMINI_TIMEOUT`, default **25 detik**), dan error internal dipetakan ke pesan aman; chatbot **tidak pernah** memblokir modul inventory | `GeminiService::generate()` + `ChatbotService::friendlyError()`; status `failed` di log; diuji `test_chatbot_gemini`, `test_chatbot_service`, HTTP (invalid/unavailable/limit) |
| J8 | **Batas pemakaian** — dihitung dari `chatbot_usage_logs` harian/bulanan; hanya status `success`/`failed` yang dihitung; penolakan guard/limit/disabled tidak menambah kuota | `ChatbotService::rateLimitState()`; diuji service + HTTP (batas 1 → panggilan kedua tidak memanggil API) |

### Keterbatasan Fase 8 yang dinyatakan terbuka

- **Belum ada koneksi nyata ke Google Gemini**: tidak tersedia API key pada
  pengujian. Seluruh alur (chat, konteks role, guard, error, rate limit, XSS,
  CSRF, RBAC, enkripsi) diuji memakai **stub lokal**; model & endpoint Google
  harus diverifikasi Admin lewat **Uji Koneksi** setelah key diisi.
- **Kualitas jawaban model belum dievaluasi** dengan data nyata; prompt sudah
  memerintahkan agar tidak mengarang angka, tetapi hasil bahasa tetap perlu
  ditinjau manusia.
- **Kebijakan privasi final (C14)** masih memerlukan persetujuan pemilik produk,
  terutama mengenai data agregat yang dikirim ke penyedia AI pihak ketiga.
- **Model Gemini dapat berubah/dihentikan** oleh penyedia; pilihan model
  tersimpan di pengaturan dan dapat diganti tanpa mengubah kode.
