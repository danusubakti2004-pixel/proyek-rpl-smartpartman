# SmartPartMan

**Sistem Informasi Manajemen Persediaan Sparepart Bengkel Suzuki Berbasis Web dengan AI**

SmartPartMan adalah aplikasi sistem informasi manajemen persediaan (inventory
management) sparepart untuk gudang bengkel/dealer Suzuki. Aplikasi berjalan di
XAMPP, menggunakan antarmuka Bahasa Indonesia dan mata uang Rupiah, serta
dibangun dengan PHP native tanpa framework eksternal.

SmartPartMan membantu **Partman**, **Kepala Bengkel**, dan **Admin** mengelola
stok sparepart, transaksi barang masuk/keluar, purchase order, laporan,
prediksi kebutuhan stok, chatbot berbasis data, serta notifikasi WhatsApp.

---

## A. Identitas Mahasiswa

| Keterangan | Isi |
|---|---|
| Nama | Danu Subakti |
| NIM | 41257183 |
| Program Studi | Teknik Informatika |
| Kelas | 1F |

---

## B. Deskripsi Aplikasi

### Gambaran Umum

SmartPartMan merupakan aplikasi berbasis web yang dirancang untuk membantu
operasional persediaan sparepart pada bengkel/dealer Suzuki. Aplikasi mencatat
seluruh pergerakan stok secara terpusat sehingga data persediaan tidak lagi
tersebar pada buku catatan atau spreadsheet manual.

### Tujuan Aplikasi

- Mencatat persediaan sparepart secara akurat dan append-only (ledger stok).
- Menyediakan alur transaksi dan persetujuan yang sesuai hak akses.
- Menyajikan laporan, dokumen cetak, dan analitik operasional bengkel.
- Membantu keputusan pengadaan melalui prediksi stok lokal (Python).
- Menyediakan asisten AI yang **selalu mengambil angka dari database**
  (bukan estimasi AI) melalui `ChatbotDataService`.

### Permasalahan yang Diselesaikan

- Pencatatan stok manual yang rawan selisih dan sulit ditelusuri.
- Kesulitan mengetahui sparepart yang menipis/habis secara cepat.
- Proses pengajuan dan persetujuan pembelian yang belum terdokumentasi.
- Laporan persediaan yang harus disusun ulang secara manual.
- Kebutuhan informasi cepat bagi petugas melalui chatbot dan notifikasi.

### Pengguna Aplikasi

| Role | Fokus utama |
|---|---|
| **Partman** | Input master, transaksi stok, penyesuaian, pengajuan reorder/PO |
| **Kepala Bengkel** | Melihat data, menyetujui PO, memantau laporan dan prediksi |
| **Admin** | Semua modul + pengguna, audit log, backup/restore, pengaturan AI & WhatsApp |

### Manfaat bagi Operasional Bengkel

- Data stok tunggal dan konsisten untuk seluruh petugas.
- Pengurangan risiko kehabisan stok melalui peringatan stok menipis.
- Proses pengadaan lebih terkontrol melalui Purchase Order dan persetujuan.
- Pengambilan keputusan lebih cepat berkat dashboard, laporan, dan prediksi.
- Komunikasi operasional lebih efisien melalui notifikasi WhatsApp.

---

## C. Fitur Utama Aplikasi

| Modul | Fitur |
|---|---|
| Dashboard | Ringkasan stok, transaksi, PO, grafik, dan peringatan stok menipis |
| Master data | Sparepart, kategori, supplier, lokasi/rak, impor CSV/XLSX |
| Stok | Stok awal, riwayat pergerakan, penyesuaian, stock opname |
| Transaksi | Barang masuk (penerimaan), barang keluar, koreksi, lampiran bukti |
| Pengadaan | Reorder request, Purchase Order, persetujuan, penerimaan |
| Laporan | Dashboard, 12 laporan, rekap, cetak, PDF, ekspor Excel/CSV |
| AI Prediksi | Forecast kebutuhan stok via Python lokal (Pandas, Scikit-learn) |
| Chatbot AI | Gemini, DeepSeek, OpenAI (dipilih Admin), data-first, usage log |
| WhatsApp | Notifikasi transaksi, stok menipis, laporan harian/bulanan, OTP |
| Administrasi | Pengguna, audit log, backup/restore, pengaturan aplikasi |
| Keamanan | RBAC 3 role, CSRF, prepared statements, enkripsi API key |

Rincian fitur yang tersedia:

- **Dashboard** – ringkasan kondisi persediaan, transaksi terbaru, dan grafik.
- **Manajemen data sparepart** – tambah/ubah/status sparepart, kategori,
  supplier, serta lokasi penyimpanan; impor massal CSV/XLSX dengan pratinjau.
- **Barang masuk dan barang keluar** – pencatatan penerimaan dan pengeluaran
  sparepart beserta lampiran bukti transaksi.
- **Stock opname** – penghitungan fisik, input hasil, dan konfirmasi selisih.
- **Penyesuaian stok** – koreksi stok dengan alasan dan jejak audit.
- **Purchase Order dan persetujuan** – pengajuan PO oleh Partman, persetujuan
  atau penolakan oleh Kepala Bengkel, pemrosesan oleh Admin.
- **Penerimaan barang** – penerimaan berdasarkan PO, termasuk penerimaan
  otomatis oleh Admin, dengan pembaruan stok melalui ledger.
- **Laporan persediaan** – 12 jenis laporan, rekap, cetak, PDF, dan ekspor.
- **Notifikasi WhatsApp** – notifikasi transaksi, stok menipis, laporan harian
  dan bulanan, pengaturan penerima, serta verifikasi nomor melalui OTP.
- **Manajemen pengguna dan hak akses** – pengelolaan akun tiga role, reset
  password, pembukaan akun terkunci, dan audit log.
- **Backup dan restore** – pencadangan database oleh Admin dengan validasi
  checksum dan backup keselamatan sebelum restore.
- **Fitur lain** – pengaturan identitas/logo bengkel, riwayat stok, reorder
  request, rekomendasi supplier, serta monitoring penggunaan API AI.

---

## D. Fitur Artificial Intelligence (AI)

### 1. AI Chatbot

Chatbot membantu pengguna memperoleh informasi dan menjawab pertanyaan
berdasarkan data serta konteks aplikasi. Chatbot memperhatikan hak akses
pengguna, sehingga informasi yang ditampilkan tetap sesuai role yang dimiliki.

- Angka jawaban chatbot diambil dari database melalui `ChatbotDataService`,
  bukan hasil estimasi model bahasa.
- Provider yang didukung: **Gemini**, **DeepSeek**, dan **OpenAI**, dipilih
  oleh Admin melalui halaman pengaturan.
- API key disimpan terenkripsi (AES-256-GCM) dan hanya ditampilkan tersamarkan.
- Penggunaan chatbot dicatat pada log penggunaan untuk pemantauan.

### 2. Prediksi Stok

Prediksi stok dijalankan secara lokal melalui skrip Python
`python/prediction/forecast.py` dan memberikan rekomendasi pengadaan sparepart.
Metode yang digunakan:

- **Moving Average** – rata-rata pergerakan permintaan pada jendela tertentu.
- **Linear Regression** – regresi linear berbasis Scikit-learn.
- **Safety Stock** – cadangan pengaman berdasarkan variabilitas permintaan dan
  lead time.
- **Reorder Point (ROP)** – titik pemesanan ulang berdasarkan laju permintaan
  dan lead time.

Moving Average dan Linear Regression dibandingkan secara objektif menggunakan
MAE pada data uji; metode dengan galat terendah dipilih. Jika data tidak cukup,
prediksi tidak dihitung dan alasan ditampilkan. Jika lead time supplier belum
diisi, Safety Stock/ROP memakai asumsi yang diberi label dan peringatan.

> **Catatan penting:** prediksi AI hanya memberikan **rekomendasi**. Aplikasi
> **tidak membuat Purchase Order secara otomatis**. Keputusan dan proses
> pengadaan tetap dilakukan oleh pengguna sesuai hak akses.

---

## E. Tautan Video

- **Video Presentasi : https://youtu.be/OhCozLbVUvc?si=WmeooXxKZS-NgxcP**

---

## F. Akun Login Aplikasi

Akun demo dibuat **melalui mekanisme aplikasi** (halaman instalasi dan menu
Pengguna), bukan melalui `INSERT` SQL manual. Gunakan database demo/uji yang
baru, bukan database produksi.

| Role | Username | Password |
|---|---|---|
| Admin | `demo_admin` | `[PASSWORD DEMO DIISI SETELAH INSTALASI]` |
| Partman | `demo_partman` | `[PASSWORD DEMO DIISI SETELAH INSTALASI]` |
| Kepala Bengkel | `demo_kepala` | `[PASSWORD DEMO DIISI SETELAH INSTALASI]` |

> Password sengaja tidak dicantumkan di repository publik. Gunakan password
> demo yang **khusus**, minimal 8 karakter, dan **tidak sama** dengan password
> produksi, email, atau akun pribadi lainnya. Simpan password demo secara
> terpisah dan bagikan hanya kepada dosen/penguji.

### Prosedur Membuat Akun Demo

Lakukan setelah database selesai dimigrasikan dan aplikasi dapat dibuka.

1. **Siapkan database demo.** Gunakan database baru khusus demonstrasi,
   misalnya `smartpartman_demo`, melalui `DB_NAME` di `.env`. Jangan memakai
   database produksi.
2. **Jalankan migrasi** hingga selesai:

   ```powershell
   C:\xampp\php\php.exe database\migrate.php
   ```

3. **Buat akun Admin pertama** melalui halaman instalasi
   `http://localhost/smartpartman/install`:
   - Username: `demo_admin` (4–50 karakter, hanya huruf, angka, titik,
     garis bawah, atau tanda hubung).
   - Nama lengkap: minimal 3 karakter.
   - Password: minimal 8 karakter dan dikonfirmasi ulang.
   - Halaman instalasi hanya dapat dipakai sekali; setelah Admin pertama
     dibuat, halaman tersebut terkunci.
4. **Login sebagai `demo_admin`**, lalu buka menu **Pengguna → Tambah
   Pengguna**.
5. **Buat akun Partman:**
   - Username: `demo_partman`
   - Role: **Partman**
   - Password: password demo khusus (minimal 8 karakter).
6. **Buat akun Kepala Bengkel:**
   - Username: `demo_kepala`
   - Role: **Kepala Bengkel**
   - Password: password demo khusus (minimal 8 karakter, berbeda dari akun
     lain).
7. Pastikan status kedua akun **Aktif** pada daftar Pengguna.
8. Catat username dan password demo pada catatan terpisah, lalu isi kolom
   password pada tabel di atas **setelah instalasi** bila diperlukan untuk
   penilaian. Jangan menyimpan password demo di repository publik.

Pembuatan akun melalui menu Pengguna otomatis:
- menyimpan password sebagai hash (`password_hash`), bukan teks biasa;
- mencatat aktivitas pada Audit Log;
- membatasi role pada whitelist `partman`, `kepala_bengkel`, dan `admin`;
- menolak username duplikat dan password kurang dari 8 karakter.

Jangan membuat akun demo dengan menulis langsung ke tabel `users` atau
membagikan hash password. Bila password demo perlu diganti, gunakan menu
**Pengguna → Reset Password** sebagai Admin.

### Pengujian Login Setiap Role

| Role | Langkah uji | Menu yang harus dapat diakses |
|---|---|---|
| Admin | Buka `/login`, masuk dengan `demo_admin` | Dashboard, Pengguna, Audit Log, Pengaturan, Backup/Restore, seluruh modul transaksi dan laporan |
| Partman | Logout, lalu login dengan `demo_partman` | Dashboard, Sparepart, Barang Masuk/Keluar, Stock Opname, Penyesuaian Stok, Reorder, Purchase Order (pengajuan), Prediksi (menjalankan), Chatbot |
| Kepala Bengkel | Logout, lalu login dengan `demo_kepala` | Dashboard, laporan, melihat master data, menyetujui/menolak Reorder dan Purchase Order, melihat prediksi, Chatbot |

Pengujian akses juga dapat dilakukan dengan membuka URL modul yang bukan
haknya. Aplikasi harus menolak dengan **HTTP 403**, bukan hanya
menyembunyikan tombol.

> Jika akun terkunci karena percobaan login gagal, Admin dapat membukanya
> melalui menu **Pengguna → Buka Kunci** atau **Reset Password**.

---

## Teknologi

- **PHP 8.2** native MVC (tanpa framework), PDO prepared statements.
- **MySQL/MariaDB** (XAMPP), InnoDB, utf8mb4.
- **Bootstrap 5** + CSS/JS lokal (tanpa CDN wajib).
- **Python 3.12** untuk prediksi stok (Pandas, NumPy, Scikit-learn).
- **GitHub Actions** untuk CI (lint + unit test).
- Integrasi HTTP: Google Gemini, DeepSeek, OpenAI (OpenAI-compatible), Fonnte.

## Struktur Proyek

```text
smartpartman/
├── app/
│   ├── Core/          # App, Router, Database, View, Session, Csrf, Auth, Env, ...
│   ├── Controllers/   # Handler HTTP per modul
│   ├── Models/        # Akses data (prepared statements)
│   ├── Services/      # Logika bisnis (stok, laporan, AI, WhatsApp, backup)
│   ├── Middleware/    # Auth, Guest, Role (RBAC)
│   ├── Helpers/       # e(), rupiah(), base_url(), tanggal_id()
│   └── Views/         # Layout + halaman per modul
├── config/            # config.php (tanpa rahasia)
├── database/
│   ├── migrations/    # 19 migrasi SQL berurutan
│   ├── schema_snapshot.sql
│   ├── keygen.php
│   ├── migrate.php
│   ├── whatsapp_scheduler.php
│   └── prediction_scheduler.php
├── routes/web.php     # Definisi route + middleware
├── storage/           # log, backup, unggahan (TIDAK masuk Git)
├── python/
│   ├── requirements.txt
│   └── prediction/
│       ├── forecast.py
│       └── README.md
├── tests/             # Unit/integration test (DB uji)
└── docs/              # INSTALL, USER_GUIDE, ASSUMPTIONS, TODO
```

## Hak Akses dan Keamanan Role

Hak akses ditegakkan di server melalui middleware route (`AuthMiddleware`,
`GuestMiddleware`, `RoleMiddleware`). Menyembunyikan tombol di UI bukan
pengamanan; akses URL langsung tetap ditolak **403**.

---

## G. Petunjuk Instalasi (Setup Instruction)

### 1. Persyaratan Perangkat Lunak

| Komponen | Keterangan |
|---|---|
| XAMPP | Apache + MySQL/MariaDB (versi teruji: Apache 2.4, PHP 8.2, MariaDB 10.4) |
| PHP | 8.2 atau lebih baru |
| MySQL/MariaDB | Disertakan dalam XAMPP |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`, `zip`, `curl`, `dom`, `gd` |
| Python | 3.12 (opsional, hanya untuk prediksi stok) |
| Browser | Chrome/Edge/Firefox terbaru |
| Composer | Tidak diperlukan |

Python dan koneksi internet/API key bersifat opsional. Tanpa keduanya, modul
inventory utama tetap berjalan normal.

### 2. Menempatkan Folder Aplikasi ke `htdocs`

1. Salin folder proyek `smartpartman` ke dalam direktori web XAMPP.
2. Pastikan struktur akhirnya menjadi:

   ```text
   C:\xampp\htdocs\smartpartman\
   ├── index.php
   ├── app\
   ├── config\
   ├── database\
   └── ...
   ```

3. Jangan menempatkan seluruh isi proyek langsung di `htdocs` tanpa folder
   pembungkus, agar URL aplikasi tetap konsisten.

### 3. Membuat Database

Database dapat dibuat otomatis oleh proses migrasi. Jika ingin membuat manual:

1. Buka `http://localhost/phpmyadmin`.
2. Pilih menu **New**.
3. Buat database dengan nama, misalnya, `smartpartman_v2`.
4. Pilih collation `utf8mb4_unicode_ci`.

Atau melalui SQL:

```sql
CREATE DATABASE IF NOT EXISTS `smartpartman_v2`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 4. Mengimpor SQL atau Menjalankan Migrasi

Cara yang **disarankan** adalah menjalankan migrasi resmi proyek:

```powershell
C:\xampp\php\php.exe database\migrate.php
```

Perintah tersebut otomatis membuat database (jika belum ada) dan menjalankan
seluruh file pada `database/migrations/` secara berurutan. Untuk melihat status:

```powershell
C:\xampp\php\php.exe database\migrate.php --status
```

Alternatif impor manual: setelah database dibuat, impor berkas
`database/schema_snapshot.sql` melalui phpMyAdmin. Berkas ini merupakan
snapshot struktur tabel; migrasi tetap menjadi sumber utama pembaruan skema.

### 5. Menyesuaikan Konfigurasi Koneksi Database

1. Salin template konfigurasi:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Buka `.env`, lalu sesuaikan bagian database:

   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=smartpartman_v2
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   DB_TEST_NAME=smartpartman_test
   ```

3. `DB_NAME` adalah database aplikasi. `DB_TEST_NAME` wajib berbeda dan hanya
   dipakai oleh pengujian otomatis.

### 6. Mengatur Konfigurasi Aplikasi

1. Buat `APP_KEY` dengan perintah:

   ```powershell
   C:\xampp\php\php.exe database\keygen.php
   ```

2. Tempelkan hasilnya pada baris `APP_KEY=` di `.env`.
3. Sesuaikan nilai lain bila diperlukan:

   ```env
   APP_NAME="SmartPartMan"
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost/smartpartman
   APP_TIMEZONE=Asia/Jakarta
   ```

4. Jangan mengganti `APP_KEY` setelah menyimpan API key/token melalui aplikasi,
   karena data terenkripsi lama tidak dapat dibuka kembali.

### 7. Menjalankan Apache dan MySQL melalui XAMPP

1. Buka **XAMPP Control Panel**.
2. Klik **Start** pada modul **Apache**.
3. Klik **Start** pada modul **MySQL**.
4. Pastikan kedua modul berstatus *running* tanpa konflik port.

### 8. Membuka Aplikasi melalui Browser

1. Buka `http://localhost/smartpartman`.
2. Saat pertama kali dijalankan, halaman **Instalasi** muncul otomatis.
3. Buat akun **Admin** pertama (password minimal 8 karakter).
4. Setelah berhasil, halaman instalasi terkunci permanen.
5. Login sebagai Admin, ganti password awal, lalu buat akun **Partman** dan
   **Kepala Bengkel** melalui menu **Pengguna**.
6. Health check tersedia di `http://localhost/smartpartman/health`.

### 9. Menjalankan Fitur Python (Prediksi Stok)

Langkah ini hanya diperlukan jika fitur prediksi stok akan digunakan.

1. Pasang dependensi:

   ```powershell
   py -3.12 -m pip install -r python\requirements.txt
   ```

2. Pastikan `.env` berisi:

   ```env
   PYTHON_PATH=py
   PYTHON_ARGS=-3.12
   ```

3. Uji instalasi Python:

   ```powershell
   py -3.12 -c "import pandas, sklearn; print(pandas.__version__, sklearn.__version__)"
   ```

4. Uji manual skrip prediksi (opsional):

   ```powershell
   Get-Content payload.json -Raw | py -3.12 python\prediction\forecast.py
   ```

Bila Python belum siap atau gagal dijalankan, fitur prediksi menampilkan status
tidak tersedia dan transaksi inventory tetap aman.

### 10. Mengatur Integrasi Gemini dan Fonnte

**A. Chatbot Gemini (opsional)**

1. Dapatkan API key dari Google AI Studio/Google Cloud.
2. Login sebagai Admin.
3. Buka **Pengaturan → Chatbot**.
4. Pilih provider (Gemini/DeepSeek/OpenAI), isi API key, pilih model, lalu
   simpan.
5. Klik **Uji Koneksi** untuk memverifikasi konfigurasi.
6. API key disimpan terenkripsi dan hanya ditampilkan tersamarkan.

Alternatif melalui `.env` (fallback, jangan di-commit):

```env
GEMINI_API_KEY=
# GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta
# GEMINI_TIMEOUT=25
```

**B. Notifikasi WhatsApp Fonnte (opsional)**

1. Dapatkan token dari layanan Fonnte.
2. Login sebagai Admin.
3. Buka **Pengaturan → WhatsApp Gateway**.
4. Isi token Fonnte, aktifkan integrasi, lalu simpan.
5. Atur timezone, jam laporan harian, dan jenis notifikasi.
6. Daftarkan nomor penerima dan lakukan verifikasi melalui OTP.
7. Gunakan tombol uji koneksi sebelum mengaktifkan notifikasi produksi.

Token Fonnte tidak disimpan di `.env`; token disimpan terenkripsi di database.
Jangan menuliskan API key, token, atau password apa pun ke dalam README.

---

## Konfigurasi Database

Contoh `.env` (JANGAN commit file `.env`):

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=smartpartman_v2
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
DB_TEST_NAME=smartpartman_test
```

- `DB_NAME` dipakai aplikasi produksi/lokal.
- `DB_TEST_NAME` **wajib berbeda** dan dipakai seluruh pengujian.
- Database uji dapat di-reset aman:

  ```powershell
  C:\xampp\php\php.exe tests\reset_test_db.php --yes
  ```

  Perintah ini menolak database yang tidak berakhiran `_test`.

## Menjalankan Aplikasi

- Web: `http://localhost/smartpartman`
- Health check: `http://localhost/smartpartman/health`
- CLI scheduler WhatsApp:

  ```powershell
  C:\xampp\php\php.exe database\whatsapp_scheduler.php daily
  ```

## Menjalankan Unit Test

```powershell
# Seluruh test CLI (memakai DB uji, bukan produksi)
C:\xampp\php\php.exe tests\run.php

# Filter nama test
C:\xampp\php\php.exe tests\run.php whatsapp

# Verifikasi HTTP end-to-end (menukar .env ke DB uji lalu memulihkannya)
C:\xampp\php\php.exe tests\http_verify.php
```

Test memakai database `DB_TEST_NAME`, menjalankan migrasi otomatis, dan tidak
mengirim pesan WhatsApp sungguhan (transport di-stub pada test terkait).
Laporkan kegagalan apa adanya; jangan mengubah test agar terlihat PASS.

## Konfigurasi AI

### Chatbot (Gemini / DeepSeek / OpenAI)

API key **tidak disimpan di `.env` atau source code**. Admin mengisinya melalui:

**Pengaturan → Pengaturan AI Chatbot**

- Pilih provider aktif (Gemini, DeepSeek, OpenAI).
- Isi API key (disimpan terenkripsi AES-256-GCM, ditampilkan tersamarkan).
- Atur model dan Base URL bila diperlukan.
- Gunakan tombol **Uji Koneksi** sebelum menyimpan.
- Key tidak dikirim ke browser setelah tersimpan dan tidak ditulis ke log.
- Angka jawaban chatbot berasal dari database melalui `ChatbotDataService`.

Opsional untuk pengembangan: `GEMINI_API_KEY` di `.env` (jangan commit nilainya).
Mengganti `APP_KEY` membuat key terenkripsi lama tidak dapat dibuka.

### Prediksi Stok (Python)

```powershell
py -3.12 -m pip install -r python\requirements.txt
```

Atur `PYTHON_PATH` dan `PYTHON_ARGS` di `.env`. Prediksi bersifat lokal; tidak
mengirim data ke layanan eksternal. Skrip utama berada di
`python/prediction/forecast.py`.

## Konfigurasi WhatsApp (Fonnte)

Token Fonnte **tidak disimpan di `.env`**. Admin mengisi melalui:

**Pengaturan → WhatsApp Gateway**

- Token disimpan terenkripsi di database.
- Uji koneksi tersedia dari halaman pengaturan.
- Atur timezone, jam laporan harian, dan jenis notifikasi.
- Nomor penerima diverifikasi melalui OTP.
- Laporan harian dikirim ke semua pengguna aktif yang nomornya terverifikasi
  dan mengaktifkan preferensi `DAILY_REPORT`.

Jangan pernah menaruh token sungguhan di README, workflow, atau file contoh.

## CI/CD (GitHub Actions)

Workflow: [`.github/workflows/ci.yml`](.github/workflows/ci.yml)

- Berjalan pada `push` dan `pull_request`.
- PHP 8.2 + ekstensi yang dibutuhkan.
- MariaDB 10.6 sebagai service database khusus testing.
- Python 3.12 + dependensi prediksi.
- `php -l` untuk seluruh file PHP.
- `php tests/run.php` untuk unit/integration test.
- Memakai `.env` dummy yang dihasilkan saat runtime; **tidak ada secret produksi**.

## Keamanan

- `.env`, backup database, log, unggahan pengguna, dan session dikecualikan
  melalui `.gitignore`.
- API key/token disimpan terenkripsi; hanya versi tersamarkan yang ditampilkan.
- Semua query memakai prepared statements.
- CSRF token pada form POST; session `HttpOnly` + `SameSite=Lax`.
- Audit log append-only untuk aktivitas penting.
- Backup/restore hanya untuk Admin dan memakai path tervalidasi.

## Dokumen Lain

- [`docs/INSTALL.md`](docs/INSTALL.md) — instalasi & troubleshooting
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — panduan pengguna
- [`docs/ASSUMPTIONS.md`](docs/ASSUMPTIONS.md) — asumsi & keputusan desain
- [`docs/TODO.md`](docs/TODO.md) — status pengembangan
- [`python/prediction/README.md`](python/prediction/README.md) — detail modul prediksi

## Lisensi

Proyek akademik SmartPartMan (Rekayasa Perangkat Lunak). Sesuaikan lisensi
sebelum publikasi bila diperlukan.
