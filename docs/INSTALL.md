# Panduan Instalasi SmartPartMan

Target: **XAMPP di Windows**, satu komputer server + client LAN/Wi-Fi yang sama.

## 1. Prasyarat

| Komponen | Versi teruji | Keterangan |
|---|---|---|
| XAMPP | Apache 2.4.58 + PHP 8.2.12 + MariaDB 10.4.32 | Modul: Apache & MySQL aktif |
| ext-zip | **harus diaktifkan** | Untuk import XLSX (Fase 3) **dan ekspor Excel laporan** (Fase 5) — lihat §4 |
| ext-fileinfo | **harus diaktifkan** (aktif bawaan XAMPP) | Memeriksa **isi** berkas bukti transaksi (Fase 4) |
| Izin folder | `storage/private_uploads/` dapat ditulis | Menyimpan bukti transaksi **di luar web root** (Fase 4) |
| Python | 3.12 (terpasang berdampingan 3.14) + paket `pandas` & `scikit-learn` | **Hanya untuk Fase 7 (prediksi stok)** — lihat **§2a**. Modul lain tetap berjalan walau Python belum siap |
| Internet + API key Gemini | opsional | **Hanya untuk Fase 8 (chatbot)** — lihat **§2b**. Tanpa internet/key, chatbot nonaktif/terbatas tetapi seluruh modul inventory tetap berjalan |
| Browser | Chrome/Edge/Firefox terbaru | — |

> Versi di atas terverifikasi sampai **Fase 9** (Administrasi & finalisasi, 19 Okt 2026).
> Python teruji: **3.12.10** + `pandas` 3.0.6 + `scikit-learn` 1.9.1 (lewat
> `py -3.12`).
>
> **PDF laporan/dokumen TIDAK memerlukan ekstensi tambahan** — dihasilkan oleh
> `PdfService` murni PHP (tanpa pustaka Python maupun library PDF eksternal).
>
> **Koneksi nyata ke Google Gemini belum diuji** pada pengembangan ini karena
> tidak tersedia API key; seluruh alur diuji dengan stub lokal. Verifikasi
> akun/model Anda lewat tombol **Uji Koneksi** setelah key diisi.

## 2. Langkah Instalasi

1. **Buka XAMPP Control Panel** → Start **Apache** dan **MySQL**.
2. **Salin konfigurasi:**
   ```
   copy .env.example .env
   ```
3. **Edit `.env`:**
   - `DB_USER` / `DB_PASS` — default XAMPP: `root` / kosong.
   - `DB_NAME` — bawaan `smartpartman_v2`
     (⚠️ database lama bernama `smartpartman` berisi proyek lain yang kondisinya
     rusak — **jangan dipakai, jangan dihapus** agar masih bisa dipulihkan).
   - `APP_KEY` — jalankan `php database\keygen.php` lalu tempel hasilnya.
4. **Jalankan migrasi** (CMD dari folder proyek):
   ```
   php database\migrate.php
   ```
   *(Bila `php` tidak dikenal, gunakan path penuh: `C:\xampp\php\php.exe database\migrate.php`)*

   Migrasi otomatis membuat database `smartpartman_v2`. Bila ingin membuat
   manual lebih dulu, buka **phpMyAdmin** (`http://localhost/phpmyadmin`) →
   *New* → nama `smartpartman_v2`, collation `utf8mb4_unicode_ci` — atau:
   ```sql
   CREATE DATABASE IF NOT EXISTS `smartpartman_v2`
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
5. **Buka** `http://localhost/smartpartman` → halaman **Instalasi** muncul
   otomatis karena belum ada admin.
6. **Buat Admin pertama** (username, nama, password min 8 karakter).
   Setelah berhasil, halaman instalasi **terkunci permanen**.
7. **Login sebagai Admin**, lalu:
   - buka menu **Ganti Password** dan ganti password awal instalasi;
   - buat akun **Partman** dan **Kepala Bengkel** di menu **Pengguna**.
8. Bagikan username/password ke masing-masing pengguna.

### 2a. Menyiapkan modul prediksi (Fase 7) — Python 3.12

Hanya diperlukan bila fitur **Prediksi Stok** ingin dipakai. Modul lain tetap
berjalan normal tanpa langkah ini.

1. **Pasang paket Python** (CMD dari folder proyek):
   ```
   py -3.12 -m pip install -r python\requirements.txt
   ```
   *(Bila `py` tidak dikenal, gunakan path penuh ke `python.exe` versi 3.12.)*
2. **Pastikan `.env`** berisi (sudah menjadi bawaan):
   ```
   PYTHON_PATH=py
   PYTHON_ARGS=-3.12
   ```
   Bila Python 3.12 Anda bukan lewat launcher `py`, isi `PYTHON_PATH` dengan
   **path penuh** `python.exe` versi 3.12 dan `PYTHON_ARGS` dengan kosong.
3. **Uji cepat** (opsional):
   ```
   py -3.12 -c "import pandas, sklearn; print(pandas.__version__, sklearn.__version__)"
   ```
4. Bila prediksi dijalankan dari halaman web tetapi gagal, prediksi otomatis
   **kembali ke aturan stok minimum** dan transaksi inventory tetap aman —
   lihat Troubleshooting §8.

### 2b. Menyiapkan chatbot Gemini (Fase 8)

Chatbot memerlukan **API key Google Gemini** dan koneksi internet. Modul lain
tetap berjalan tanpa langkah ini.

1. **Dapatkan API key** dari Google AI Studio / Google Cloud untuk Gemini API.
   Perhatikan kuota **Free Tier** akun Anda.
2. **Cara yang disarankan:** login sebagai **Admin** → **Pengaturan →
   Chatbot** → isi **API key** → pilih model → **Simpan** → klik **Uji Koneksi**.
   API key disimpan **terenkripsi AES-256-GCM** (memakai `APP_KEY`) dan hanya
   ditampilkan tersamarkan (`****abcd`).
3. **Alternatif `.env`** (fallback bila database kosong):
   ```
   GEMINI_API_KEY=isi-api-key-anda
   # GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta
   # GEMINI_TIMEOUT=25
   ```
   Jangan menaruh API key di source code atau membagikan `.env`.
4. **Aktifkan chatbot** di halaman pengaturan yang sama, lalu buka menu
   **Chatbot**. Bila API key kosong/tidak valid atau internet terputus,
   halaman menampilkan pesan aman dan modul inventory tetap dapat dipakai.
5. **Jangan mengganti `APP_KEY`** setelah menyimpan API key lewat halaman
   Admin — key lama tidak dapat didekripsi. Isi ulang API key bila `APP_KEY`
   berubah.
6. Pengujian otomatis memakai **stub lokal** (`GEMINI_API_URL` diarahkan ke
   stub); tidak memerlukan API key nyata dan tidak memakai kuota Google.

## 3. Verifikasi Instalasi

```
php tests\run.php
```
Semua pengujian harus `LULUS` (ujian memakai database terpisah
`smartpartman_test`, tidak menyentuh data produksi). Sampai Fase 9: **3012 asersi**
(0 gagal; aman dijalankan berulang). Lint PHP: **216 file, 0 galat**;
`forecast.py` lolos `py_compile`.

**Reset database uji (opsional/berkala):**
```
php tests\reset_test_db.php --yes
```
Skrip **hanya** menyentuh database berakhiran `_test`; ia **menolak** bila nama
sama dengan database produksi dan tanpa `--yes` hanya menampilkan target. DB
produksi `smartpartman_v2` tidak pernah dihapus.

Cek lint cepat seluruh berkas PHP (tidak boleh ada *parse error*):
```
Get-ChildItem -Recurse -Filter *.php -Path app,config,database,routes,tests | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

**Health check** — buka `http://localhost/smartpartman/health`. Respons sehat:

```json
{ "status": "ok", "php": "ok", "database": "ok", "migrations": "ok", "installed": true }
```

- Tanpa login: hanya status (tanpa versi/kredensial/path).
- Setelah login sebagai Admin: ditambah versi PHP/DB, ekstensi hilang, dan
  jumlah migrasi tertunda (tetap tanpa password/APP_KEY).
- `status: "degraded"` + HTTP 503 berarti ada masalah PHP/ekstensi/database/migrasi.

Cek cepat keamanan:
- `http://localhost/smartpartman/.env` → **403**
- `http://localhost/smartpartman/app/Core/App.php` → **403**
- `http://localhost/smartpartman/storage/logs/` → **403**
- Buka `/pengguna` atau `/audit-log` **tanpa login** → diarahkan ke halaman login.
- Login sebagai Partman lalu buka `/pengguna`, `/audit-log`, `/profil/password`
  → **403** (RBAC bekerja walau URL diketik langsung).
- Login salah **5×** → pesan akun terkunci; Admin membuka kunci di menu
  **Pengguna** (tombol *Buka Kunci*).
- Login sebagai **Admin** lalu buka `/sparepart`, `/kategori`, `/supplier`,
  `/lokasi` → **200** (boleh melihat), tetapi `/sparepart/tambah`, `/import`
  → **403** (data master hanya dikelola Partman; lihat §3b).
- Buka `/transaksi/masuk`, `/transaksi/keluar`, `/transaksi/opname`,
  `/transaksi/riwayat`, `/transaksi/koreksi` **tanpa login** → diarahkan ke
  halaman login.
- Login sebagai **Kepala Bengkel** → halaman transaksi **200**, tetapi
  `/transaksi/masuk/tambah`, `/transaksi/keluar/tambah`, `/transaksi/opname/mulai`,
  `/transaksi/koreksi` → **403** (lihat §3c).
- `http://localhost/smartpartman/storage/private_uploads/` → **403**
  (bukti transaksi tidak dapat diakses langsung).

## 3b. Verifikasi modul master data (Fase 3)

1. Login sebagai **Partman** → menu **Data Master** tampil:
   - **Kategori** → Tambah; isi kode (mis. `OLI`) & nama.
   - **Supplier** → Tambah; isi kode, nama perusahaan, telepon.
   - **Lokasi** → Tambah; mis. `RAK-A1-T2` (Gudang Utama, Rak A1, Tingkat 2).
   - **Sparepart** → Tambah; pilih kategori/supplier/lokasi, isi harga, dan
     **Stok Awal** (opsional, akan tercatat sebagai pergerakan `STOK_AWAL`).
2. Ulangi dengan **kode yang sama** → muncul pesan tolak "sudah dipakai".
3. **Impor**: menu *Impor Data* → unduh template `.csv`/`.xlsx` → unggah →
   **Pratinjau** (tiap baris diberi nomor + alasan bila salah) → **Simpan Semua**
   hanya aktif bila **seluruh** baris valid.
4. **Stok awal** hanya dapat diisi **sekali** per sparepart; form input hilang
   setelah tercatat (koreksi lewat **Stock Opname** — §3c).
5. Login sebagai **Admin** → buka **Audit Log**: pastikan aktivitas Fase 3
   (`CATEGORY_CREATE`, `SPAREPART_CREATE`, `STOCK_INITIAL_CREATE`, `IMPORT_MASTER`,
   dst.) tercatat, dan **tidak ada password** di dalamnya.

## 3c. Verifikasi modul transaksi (Fase 4)

1. Login sebagai **Partman** → menu **Transaksi**:
   - **Barang Masuk** → *Catat Penerimaan*: pilih supplier & sparepart, isi
     jumlah, harga/unit, invoice, dan (opsional) **Bukti** foto/PDF.
     Tanpa PO → **alasan wajib** diisi. Setelah tersimpan, buka detail
     sparepart: **Harga Beli Terakhir** dan **Harga Beli Rata-rata** diperbarui;
     **Riwayat Harga Beli** menampilkan pembelian tersebut.
   - **Barang Keluar** → *Catat Pengeluaran*: pilih jenis penggunaan
     (service/penjualan/lainnya), isi jumlah. Coba isi jumlah **melebihi stok**
     → harus ditolak dengan pesan "Stok tidak mencukupi" dan stok **tidak**
     berubah.
   - **Stock Opname** → *Mulai Stock Opname* → isi jumlah fisik per item →
     *Simpan Hasil Hitung* (stok **belum** berubah) → *Konfirmasi Hasil Opname*
     (baru stok berubah). Alasan **wajib** untuk item yang berselisih.
   - **Riwayat Stok** → periksa pergerakan `STOK_AWAL`, `MASUK`, `KELUAR`,
     `PENYESUAIAN_OPNAME`, `KOREKSI`.
   - **Koreksi Transaksi** → pilih transaksi `MASUK`/`KELUAR`, isi jumlah yang
     dibalik + alasan. Transaksi asal **tetap ada**; muncul baris `KOREKSI` baru.
2. Login sebagai **Kepala Bengkel** → semua halaman transaksi dapat **dilihat**,
   tetapi tombol *Catat/Mulai/Koreksi* tidak muncul dan URL form → **403**.
3. Login sebagai **Admin** → dapat melihat daftar/detail/riwayat; form tulis
   → **403**. Buka **Audit Log**: aktivitas `GOODS_RECEIPT_CREATE`,
   `GOODS_ISSUE_CREATE`, `STOCK_OPNAME_*`, `TRANSACTION_CORRECTION` tercatat
   tanpa rahasia.
4. Cek status stok di **Sparepart** / dashboard: `Aman`, `Menipis`, `Kritis`,
   `Habis` — dan `Tanpa Minimum` bila stok minimum belum diisi (0).

## 3d. Verifikasi HTTP otomatis — TERPISAH dari data produksi

```
php tests\http_verify.php
```

- Skrip **mencadangkan `.env`**, mengganti `DB_NAME` ke `DB_TEST_NAME`
  (wajib berakhiran `_test`), menjalankan migrasi + membuat akun uji khusus di
  DB uji, lalu menguji **495 pemeriksaan** (rute, RBAC 3 role, barang
  masuk/keluar, opname, koreksi, unggah & unduh bukti, CSRF, Audit Log,
  dashboard per role, laporan + cetak/PDF/Excel, dokumen PO & bukti
  masuk/keluar, pengaturan aplikasi, alur Fase 6: reorder → persetujuan →
  draft PO → proses/pembayaran Admin → penerimaan parsial/penuh, Fase 7:
  halaman prediksi + menjalankan analisis **nyata** Python via HTTP + RBAC,
  serta Fase 8: chatbot/uji koneksi/guard/rate limit memakai **stub Gemini
  lokal** — bukan API Google, dan Fase 9: RBAC backup/ekspor Audit Log,
  pembuatan + unduhan backup nyata, penolakan restore password/konfirmasi
  salah tanpa mengubah DB, ekspor CSV, unggah/saji/hapus logo, kop dokumen,
  serta header keamanan).
- **Selalu memulihkan `.env`** lewat `finally` + shutdown handler, termasuk bila
  gagal/crash; sisa `.env.httpbak` dipulihkan otomatis pada run berikutnya.
  Pengaturan chatbot DB uji juga dipulihkan ke **nonaktif tanpa API key**.
- **Jalankan saat tidak ada pengguna lain** (aplikasi dialihkan ke DB uji hanya
  beberapa detik). Data uji **tidak pernah** masuk ke DB produksi.
- Hasil akhir harus `HASIL HTTP (DB UJI): 495 lulus, 0 gagal` dan baris
  `.env dipulihkan ke DB produksi (smartpartman_v2)`.
- **Catatan jujur:** tidak ada API key Gemini nyata pada pengujian ini; koneksi
  ke Google harus diverifikasi manual lewat **Uji Koneksi**.

## 3e. Verifikasi dashboard, laporan & pengaturan (Fase 5)

1. **Login sebagai Partman** → dashboard menampilkan ringkasan stok, barang
   masuk/keluar hari ini, kartu *Rekomendasi reorder* (prediksi AI menyusul
   Fase 7 — kini memuat jumlah permintaan menunggu/disetujui + pintasan ke
   modul Reorder), grafik, transaksi terbaru, dan notifikasi stok kritis.
2. **Menu Laporan** → pilih *Stok* → atur filter pencarian → halaman menampilkan
   ringkasan + grafik + tabel.
3. Klik **Cetak** → tampilan cetak memuat kop identitas bengkel (nama default
   `SmartPartMan` bila belum diisi di Pengaturan) dan tabel utuh.
4. Klik **PDF** → unduhan `.pdf` terbuka di penampil PDF (header `%PDF-1.4`).
5. Klik **Excel** → unduhan `.xlsx` terbuka di Excel/LibreOffice.
6. Buka detail penerimaan/pengeluaran → klik **Cetak Bukti** → dokumen bertanda
   tangan tampil.
7. **Login sebagai Admin** → menu **Pengaturan** → ubah *Nama bengkel* →
   simpan → buka kembali cetak laporan, kop kini memakai nama baru.
8. **Login sebagai Kepala Bengkel** → dashboard menampilkan nilai persediaan &
   pemakaian; menu **Pengaturan** tidak ada / menolak akses (**403**).

> Jangan mengarahkan skrip ini ke DB produksi. Bila `.env.httpbak` masih ada
> setelah proses terhenti paksa, jalankan skrip sekali lagi (akan memulihkan
> lebih dulu) atau salin kembali isinya ke `.env` secara manual.

## 3f. Verifikasi reorder & pembelian (Fase 6)

1. **Login sebagai Partman** → menu **Reorder** → daftar kebutuhan (stok di
   bawah minimum) tampil; buat permintaan (ubah angka saran = wajib alasan) →
   status **Menunggu Persetujuan**, **stok tidak berubah**.
2. Menu **Pembelian** → **Buat PO** dari reorder yang disetujui (atau mandiri),
   lihat **rekomendasi supplier**, simpan draf → **Ajukan**.
3. **Login sebagai Kepala Bengkel** → **Reorder/Pembelian**: setujui satu,
   tolak lainnya tanpa alasan (ditolak sistem) lalu dengan alasan (diterima).
   Persetujuan **tidak menambah stok**.
4. **Login sebagai Admin** → **Pembelian** → **Proses** PO disetujui, ubah
   **pembayaran** (mis. Dibayar Sebagian + catatan) → riwayat tercatat. Menu
   Barang Masuk/Reorder tulis → **403**.
5. **Login sebagai Partman** → **Barang Masuk** → pilih PO (item & sisa
   terisi otomatis) → terima **sebagian** → PO `SEBAGIAN_DITERIMA`, sisa
   benar; coba melebihi sisa → **ditolak**; terima sisa → `SELESAI_DITERIMA`,
   **stok naik sesuai penerimaan** (bukan saat persetujuan).
6. Buka detail PO → **Riwayat status** lengkap (buat → menunggu → disetujui →
   diproses → sebagian → selesai) → **Cetak** PO.

## 3g. Verifikasi prediksi stok AI (Fase 7)

1. **Login sebagai Partman** → menu **Prediksi** → pilih sparepart → pada detail
   pilih periode (7/30/90 hari) & rentang riwayat → **Jalankan Prediksi**.
2. Hasil muncul: **estimasi permintaan**, **risiko stockout**, **hari ke
   minimum/nol**, **saran reorder**, **kategori fast/slow**, serta **indikator
   kecukupan data**. Riwayat analisis tercatat.
3. Halaman **"Data terbatas"** adalah perilaku benar bila riwayat barang keluar
   masih sedikit (ambang awal ≥ 30 hari & ≥ 5 transaksi) — aplikasi **tidak
   mengarang** angka.
4. Buka **dashboard Partman & Kepala Bengkel** dan **Laporan → Rekomendasi AI**:
   hasil prediksi tampil (laporan bisa dicetak/PDF/Excel).
5. **Login sebagai Kepala Bengkel / Admin** → halaman Prediksi hanya **dilihat**;
   formulir/tombol menjalankan analisis **tidak tampil** dan
   `POST /prediksi/{id}/jalankan` ditolak **403**.
6. Pastikan menjalankan prediksi **tidak mengubah stok** dan transaksi barang
   masuk/keluar tetap dapat dibuka.

## 3h. Verifikasi chatbot Gemini (Fase 8)

1. **Login sebagai Admin** → **Pengaturan → Chatbot**.
2. Isi **API key** dari Google AI Studio → pilih model → **Simpan** →
   **Uji Koneksi**. Pesan sukses memastikan key/model dapat dihubungi; pesan
   gagal menampilkan kode aman (mis. `API_KEY_INVALID`, `QUOTA`, `UNAVAILABLE`)
   tanpa membocorkan key.
3. Aktifkan chatbot. Halaman pengaturan menampilkan key **tersamarkan**
   (`****abcd`) dan log pemakaian/error tanpa rahasia.
4. **Login sebagai Partman/Kepala Bengkel** → menu **Chatbot** → tanyakan stok,
   barang masuk/keluar, laporan, atau prediksi. Riwayat hanya tersimpan di sesi
   browser; percakapan tidak dikirim sebagai riwayat ke Gemini.
5. Coba pertanyaan **di luar topik** (mis. resep masakan), permintaan **SQL**,
   atau data pengguna sebagai non-Admin → ditolak dengan sopan dan **tidak**
   memanggil API.
6. Uji ketahanan: kosongkan API key → chatbot menampilkan pesan konfigurasi;
   gunakan key salah → pesan aman; cabut internet → pesan "tidak dapat
   dihubungi". Modul inventory tetap normal.
7. **Jangan memakai API key produksi untuk pengujian otomatis**; harness
   memakai stub lokal. Untuk uji manual, gunakan key uji dengan kuota terbatas.

## 3i. Verifikasi backup/restore, logo & Audit Log (Fase 9)

> **PENTING:** lakukan uji restore hanya pada **database uji**
> (`smartpartman_test`) atau setelah backup produksi terbaru diverifikasi.
> Restore dari aplikasi mengganti **seluruh isi database**.

1. **Login sebagai Admin** → menu **Backup & Restore**.
2. Periksa nama database, jumlah migrasi, dan batas ukuran. Klik
   **Buat Backup Sekarang** → berkas baru muncul di daftar.
3. Klik **Unduh** → berkas `.sql` terunduh; buka sebagian dan pastikan berisi
   dump database, bukan halaman HTML.
4. Coba restore dengan **password salah** atau konfirmasi selain `PULIHKAN` →
   harus ditolak dan **database tidak berubah**.
5. Untuk uji restore yang sebenarnya, gunakan **DB uji**: catat satu nilai
   (mis. catatan bengkel), ubah nilai tersebut, lalu restore backup yang dibuat
   sebelum perubahan. Setelah berhasil, aplikasi mengakhiri sesi; login kembali
   dan pastikan nilai lama kembali. Backup **pre-restore** otomatis tersedia.
6. Masuk **Pengaturan → Identitas bengkel**, unggah logo **PNG/JPEG maks. 1 MB**,
   simpan, lalu buka **cetak dokumen barang masuk/keluar**. Logo harus tampil di
   kop. Hapus logo → dokumen kembali tanpa logo.
7. Buka **Audit Log** sebagai Admin: coba filter pengguna/aktivitas/entitas/ID/
   kata kunci/tanggal, lalu **Ekspor CSV**. Berkas harus terbuka rapi di Excel
   (BOM UTF-8) dan aksi ekspor tercatat di Audit Log.
8. Buka Developer Tools → Network, pastikan respons PHP memuat header
   `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, dan
   `Content-Security-Policy`. Tidak ada API key/password pada halaman/log.

**Artefak uji:** `storage/backups/` dan `storage/documents/logos/` berada di
luar web root. Jangan menghapus backup produksi
(`smartpartman_v2_pre_fase9_*.sql`) sebelum deployment disetujui.

## 4. Mengaktifkan ekstensi `zip` (wajib untuk import XLSX — Fase 3 — dan ekspor Excel laporan — Fase 5)

1. Buka `C:\xampp\php\php.ini`.
2. Cari baris `;extension=zip` → hapus `;` menjadi `extension=zip`.
3. **Restart Apache** — di XAMPP Control Panel: Stop → Start. Bila Apache
   dijalankan mandiri (tanpa service Windows), hentikan proses `httpd.exe` lama
   lalu jalankan `C:\xampp\apache\bin\httpd.exe -d C:/xampp/apache`.
   *Perubahan `php.ini` TIDAK berlaku sampai Apache di-restart.*
4. Verifikasi CLI: `C:\xampp\php\php.exe -r "echo extension_loaded('zip') ? 'OK' : 'MISSING';"`
5. Verifikasi jalur **web**: login Partman → *Impor Data* → unduh template
   **XLSX** harus berhasil (berkas diawali tanda tangan ZIP `PK`). Bila gagal,
   Apache belum ter-restart.
6. Verifikasi **ekspor laporan**: login → *Laporan* → *Stok* → **Excel** →
   unduhan `.xlsx` harus terbuka (juga diawali `PK`).

> Status 18 Okt 2026: `extension=zip` **sudah aktif** (baris 962 `C:\xampp\php\php.ini`;
> cadangan asli `php.ini.bak-smartpartman-20261018`) dan sudah diverifikasi lewat
> jalur web. Ext `gd` (opsional, untuk resize logo) masih nonaktif — lihat §7.
> Impor `.csv` tetap berfungsi tanpa `zip`. **Ekspor PDF laporan/dokumen tetap
> berfungsi walau `zip` nonaktif** (PDF dihasilkan murni PHP).

## 5. Akses dari perangkat LAN/Wi-Fi

Server: `http://localhost/smartpartman` (di komputer server).
Client: `http://<IP-SERVER>/smartpartman` — mis. `http://192.168.1.10/smartpartman`.

Langkah bila client belum bisa membuka:

1. **Cari IP server:** `ipconfig` → IPv4 Address (mis. `192.168.1.10`).
2. **Buka firewall Windows** untuk Apache (jalankan di PowerShell **Admin**):
   ```powershell
   New-NetFirewallRule -DisplayName "Apache XAMPP (SmartPartMan)" `
       -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Private
   ```
3. **Sarankan IP statis/DHCP reservasi** agar alamat server tidak berubah
   (client akan menyimpan bookmark).
4. Uji dari client: buka `http://<IP-SERVER>/smartpartman`.

> Browser client menurunkan URL basis otomatis dari path aplikasi —
> tidak perlu konfigurasi tambahan.

## 6. Keamanan sesi & perangkat bersama

Keputusan pengguna (PRD FR-01): **TIDAK ada session timeout otomatis.**

Risiko: komputer yang ditinggal dalam keadaan login dapat dipakai pihak lain.

Mitigasi operasional:
- Selalu tekan tombol **Logout** (merah, kanan atas) sebelum meninggalkan perangkat.
- Jangan gunakan "Remember me" (fitur ini tidak disediakan).
- Akun yang dicurigai: Admin dapat **menonaktifkan** akun + **reset password** lewat
  menu *Pengguna* (tersedia sejak Fase 2). Reset password sekaligus membuka kunci.
- Bila perangkat dipakai bergantian, biasakan menutup browser setelah logout.

Catatan teknis (Fase 2): ID sesi **di-regenerate** setiap kali login, dan cookie
sesi memakai `HttpOnly` + `SameSite=Lax` (serta `Secure` otomatis bila diakses
via HTTPS). Yang **tidak** disediakan sengaja: batas waktu sesi otomatis.

## 7. Catatan lingkungan

- `php` / `mysql` TIDAK berada di PATH XAMPP — gunakan path penuh atau
  tambahkan `C:\xampp\php` ke PATH.
- Chatbot (Fase 8) membutuhkan **internet + API key Gemini**; modul lain tetap
  jalan tanpa internet. API key disimpan terenkripsi; key kosong/tidak valid
  tidak menghentikan aplikasi. Kegagalan/lambatnya Gemini hanya memengaruhi
  jawaban chatbot.
- Prediksi stok (Fase 7) berjalan **lokal** via CLI `proc_open` ke
  `python/prediction/forecast.py` (`py -3.12`, tanpa shell, **tanpa endpoint
  jaringan**). Batas waktu **20 detik** dengan penghentian proses nyata; bila
  gagal/timeout, saran otomatis kembali ke aturan stok minimum dan transaksi
  inventory tetap berjalan. Folder `python/` diblokir `.htaccess` (403).
- Ekstensi: `zip` **aktif** (wajib Fase 3 — impor XLSX, **dan** Fase 5 — ekspor
  Excel laporan), `fileinfo` **aktif** (wajib Fase 4 — validasi isi bukti),
  `gd` nonaktif (opsional — logo cukup disimpan apa adanya; **laporan PDF tidak
  memerlukannya**, PDF dihasilkan `PdfService` murni PHP).
- **`storage/private_uploads/`** menyimpan bukti transaksi **di luar web root**.
  Folder kategori/tahun/bulan dibuat otomatis; jangan memindahkannya ke dalam
  folder yang dapat diakses langsung lewat URL. Unduhan hanya lewat route
  ber-otorisasi (`/transaksi/masuk/{id}/bukti`).
- **`storage/private/akun_verifikasi.txt`** (kredensial akun verifikasi
  `partman1` / `kepala1`) **sudah dihapus pada 19 Okt 2026** setelah password
  disimpan di pengelola kata sandi. Folder `storage/` tetap ditolak web (403);
  password tidak pernah dicetak ke log/aplikasi. Bila password hilang, Admin
  dapat mereset lewat menu *Pengguna*.
- MariaDB di sini berjalan **tanpa `STRICT_TRANS_TABLES`** sehingga nilai yang
  melebihi panjang kolom dapat terpotong diam-diam; aplikasi menjaga panjang
  lewat validasi PHP (bukan mengandalkan database).

## 8. Troubleshooting

| Gejala | Penyebab / Solusi |
|---|---|
| Halaman kosong / 500 | Lihat `storage/logs/app-YYYY-MM-DD.log` dan set `APP_DEBUG=true` |
| "Tabel tidak ditemukan" | Jalankan `php database\migrate.php` |
| Installer muncul lagi padahal sudah pernah jalan | Cek `DB_NAME` di `.env` — kemungkinan menunjuk DB salah |
| 403 pada halaman aplikasi | Pastikan tidak mengakses file PHP langsung; gunakan URL route |
| Client LAN gagal | Firewall (§5) atau IP server berubah |
| Import XLSX gagal | `extension=zip` belum aktif **atau** Apache belum di-restart (§4) |
| Template XLSX gagal diunduh | Sama seperti di atas; sementara pakai template `.csv` |
| Impor ditolak "kode sudah ada di database" | Sesuai kebijakan: impor **tidak** menimpa data. Ubah kode di berkas, atau ubah data lewat menu Sparepart |
| Impor ditolak "kategori … nonaktif" | Aktifkan kembali kategori di menu Kategori, atau pakai kategori lain |
| "Stok awal … sudah pernah dicatat" | Stok awal hanya sekali per item; koreksi lewat **Stock Opname** (§3c) |
| "Stok tidak mencukupi" saat barang keluar | Jumlah keluar melebihi stok tersedia. Kurangi jumlah, atau catat barang masuk dulu. Stok **tidak** berubah saat penolakan |
| Bukti transaksi gagal diunggah | Pastikan berkas **JPG/JPEG/PNG/PDF** dan ≤ **5 MB**; isi berkas harus benar-benar sesuai ekstensi |
| Unduhan bukti 404 / halaman kosong | Bukti hanya dapat diunduh lewat detail penerimaan (route ber-otorisasi); cek `storage/private_uploads/` ada dan dapat ditulis Apache |
| "Penerimaan tanpa PO wajib menyertakan alasan" | Isi kolom alasan, atau pilih PO yang sesuai |
| "Sudah ada sesi stock opname yang belum dikonfirmasi" | Selesaikan/konfirmasi sesi yang terbuka lebih dulu — hanya satu sesi OPEN pada satu waktu |
| Tombol PDF/Excel laporan tidak ada atau gagal | Halaman laporan tetap terbaca; unduh lewat tombol **Cetak** → cetak ke PDF dari browser. Bila Excel gagal, `extension=zip` belum aktif (§4) |
| Kop dokumen kosong / nama aplikasi muncul di kop | Isi **Nama bengkel** di menu *Pengaturan* (khusus Admin) — kop memakai `SmartPartMan` bila nama belum diisi |
| Menu *Pengaturan* tidak terlihat / 403 | *Pengaturan* hanya untuk **Admin**; Partman & Kepala Bengkel ditolak (403) di server |
| Prediksi menampilkan "Data terbatas" | Riwayat **barang keluar** belum memenuhi ambang (≥ 30 hari berdata & ≥ 5 transaksi) - belum cukup untuk prediksi yang jujur. Catat transaksi dulu; sistem tidak mengarang angka |
| Prediksi gagal / kembali ke aturan minimum | Python 3.12 atau paket `pandas`/`scikit-learn` belum terpasang, atau `PYTHON_PATH`/`PYTHON_ARGS` salah — lihat **§2a**. Transaksi inventory **tetap aman**; cek `storage/logs/` bila perlu |
| Lead time & ROP ditandai "belum andal" | Belum ada `lead_time_days` pada supplier **dan** belum ada riwayat penerimaan PO. Isi lead time supplier atau selesaikan penerimaan PO, lalu jalankan ulang prediksi |
| Chatbot menampilkan "dinonaktifkan" | Admin belum mengaktifkan chatbot — buka **Pengaturan → Chatbot** dan centang **Aktifkan** |
| Chatbot "API key Gemini belum diatur" | Isi API key di **Pengaturan → Chatbot** atau `GEMINI_API_KEY` di `.env`; key lewat halaman Admin disimpan terenkripsi |
| Uji koneksi gagal `API_KEY_INVALID` | API key salah/kedaluwarsa/tidak memiliki akses model. Buat key baru di Google AI Studio, pastikan model dipilih benar |
| Chatbot gagal setelah `APP_KEY` diganti | API key terenkripsi lama tidak dapat didekripsi — isi ulang API key di Pengaturan Chatbot |
| Chatbot "sedang tidak dapat dihubungi"/timeout | Periksa internet server, kuota Gemini, atau `GEMINI_API_URL`/`GEMINI_TIMEOUT`. Modul inventory tetap aman; coba lagi nanti |
| Chatbot "Batas penggunaan tercapai" | Batas harian/bulanan di Pengaturan Chatbot tercapai — tunggu atau minta Admin menaikkan/mengosongkan batas |
| Chatbot menolak pertanyaan yang wajar | Guard hanya mengizinkan topik SmartPartMan; tanyakan stok, transaksi, laporan, reorder, prediksi, atau pengaturan. Jangan minta SQL/data pengguna/rahasia |
| Jawaban chatbot tidak akurat | AI hanya memakai konteks agregat server dan **tidak menjamin** akurasi; verifikasi angka di menu/laporan aplikasi. Laporkan bila terjadi penyimpangan |
| Cetak laporan terasa lambat / hasil banyak | Laporan dibatasi **5000 baris**; persempit filter tanggal/kategori/supplier agar ringkas |
| `/health` 503 `migrations: pending` | Jalankan `php database\migrate.php` (§2 langkah 4) |
| Password salah terus | Akun terkunci setelah 5× gagal (15 menit). Tunggu, atau minta **Admin** membuka kunci / reset password di menu *Pengguna*. Partman & Kepala Bengkel tidak dapat mengganti password sendiri — harus lewat Admin |
| "Akun Anda nonaktif" | Akun dinonaktifkan Admin — minta Admin mengaktifkan kembali (riwayat login tetap tersimpan) |
| Verifikasi HTTP macet / `.env` menunjuk DB uji | Jalankan `php tests\http_verify.php` sekali lagi (memulihkan `.env.httpbak` lebih dulu), atau salin isi `.env.httpbak` ke `.env` lalu hapus backup |
| Akun verifikasi `partman1`/`kepala1` tidak bisa login | Berkas kredensial plaintext sudah **dihapus**; minta **Admin** mereset password lewat menu *Pengguna* |
| Perlu override lead time | Isi **jumlah hari** + **alasan** (wajib, minimal 10 karakter) pada formulir prediksi; nilai otomatis tetap ditampilkan dan override tercatat di Audit Log (`LEAD_TIME_OVERRIDE`) |

## 9. Backup & restore (Fase 9)

Backup dari aplikasi tersedia untuk **Admin** di menu **Backup & Restore**:

- berkas disimpan di `storage/backups/` (di luar web root), bukan di folder
  yang dapat diakses langsung lewat URL;
- batas ukuran default **100 MB** (`BACKUP_MAX_MB`; `0` = tanpa batas);
- retensi **manual** — aplikasi tidak menghapus backup otomatis;
- setiap backup mencatat ukuran, **checksum SHA-256**, nama database, jumlah
  migrasi, pelaku, dan waktu;
- `mysqldump`/`mysql` dipanggil tanpa shell; kredensial database lewat file
  sementara 0600 dan tidak ditampilkan di log/UI.

Restore hanya menerima backup buatan aplikasi dengan **checksum, nama database,
dan jumlah migrasi yang sama**. Admin wajib memasukkan **password sendiri** dan
mengetik **`PULIHKAN`**. Aplikasi membuat backup keselamatan otomatis
(`pre_restore`) sebelum import dan mengakhiri sesi login setelah restore
berhasil. Bila import gagal, snapshot keselamatan dipulihkan kembali.

Migrasi Fase 9: `014_phase9_backup_metadata.sql`. Jalankan
`php database\migrate.php` sebelum memakai menu Backup pada database yang
dituju. Database produksi **belum dimigrasikan** pada penyelesaian Fase 9;
keputusan deployment menunggu persetujuan pemilik produk.

Backup manual darurat (Admin/IT), di luar aplikasi:
```
C:\xampp\mysql\bin\mysqldump.exe -uroot smartpartman_v2 > D:\backup\smartpartman_v2_%date%.sql
```
Hentikan aplikasi sebelum restore manual. Simpan salinan **di luar folder web**
dan di lokasi terpisah dari server.
