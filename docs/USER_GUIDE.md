# Panduan Pengguna SmartPartMan

Dokumen ini berkembang mengikuti fase. Bagian yang modulnya belum dibangun
ditandai **(Fase X — belum tersedia)**.

## 1. Masuk & Keluar

1. Buka `http://localhost/smartpartman` (atau `http://<IP-server>/smartpartman` dari HP/client).
2. Masukkan **username** dan **password** → **Login**.
3. Anda masuk ke **Dashboard** sesuai peran.
4. Untuk keluar: tekan tombol **Logout** (kanan atas).
   > Karena aplikasi **tidak memakai logout otomatis**, wajib menekan Logout
   > sebelum meninggalkan perangkat, terutama pada komputer bersama.

### Kesalahan umum login
- "Username atau password salah, atau akun Anda nonaktif" → periksa ejaan.
  Untuk username yang tidak dikenal, pesan dibuat serupa agar data pengguna
  tidak mudah ditebak.
- "Akun Anda dinonaktifkan" → hubungi **Admin** untuk mengaktifkan kembali.
- "Akun terkunci sementara" → Anda salah password **5 kali**; akun terkunci
  **15 menit**. Tunggu, atau minta **Admin** membuka kunci (tombol *Buka Kunci*
  di menu Pengguna). Reset password oleh Admin juga otomatis membuka kunci.
- Lupa password → hubungi **Admin**. Partman & Kepala Bengkel **tidak dapat
  mengganti password sendiri** (kebijakan produk).

## 2. Peran & Hak Akses

| Peran | Ringkas (PRD §6) |
|---|---|
| Partman | Kelola master & transaksi gudang (barang masuk/keluar, opname, koreksi), reorder/draft PO, catat penerimaan |
| Kepala Bengkel | Baca-saja data operasional & transaksi + setujui/tolak reorder & PO (tolak = wajib alasan) |
| Admin | Kelola user, pengaturan, backup, audit log, proses administrasi PO, status pembayaran |

Hak akses ditegakkan di server: mengetik URL halaman Admin **tetap ditolak**
(403) bila Anda bukan Admin, dan URL form transaksi **ditolak 403** bila Anda
bukan Partman.

## 3. Menu Admin: Pengguna, Audit Log, Backup & Restore, Ganti Password

Menu ini **hanya tampil dan dapat diakses oleh Admin**.

- **Pengguna** — daftar akun Partman/Kepala Bengkel/Admin. Admin dapat:
  - **Tambah** pengguna baru (username, nama, email opsional, peran, password
    min 8 karakter);
  - **Ubah** data pengguna (username, nama, email, peran);
  - **Nonaktifkan / Aktifkan** akun. Akun nonaktif **tidak dapat login**, tetapi
    seluruh riwayat (percobaan login, audit) **tetap tersimpan** — data tidak
    dihapus;
  - **Reset Password** pengguna (juga membuka kunci akun);
  - **Buka Kunci** akun yang terkunci karena 5× gagal login.
  - Batasan: Admin **tidak dapat menonaktifkan akunnya sendiri**, dan sistem
    selalu menyisakan **minimal satu Admin aktif**.
- **Ganti Password** — Admin dapat mengganti **password-nya sendiri**
  (wajib mengisi password saat ini).
- **Audit Log** — catatan aktivitas penting (login/logout, gagal login, kunci
  akun, tambah/ubah/nonaktifkan pengguna, reset password, buka kunci, transaksi,
  backup/restore, ekspor). Audit Log bersifat **baca-saja**: tidak ada tombol
  ubah/hapus, dan database menolak perubahan/penghapusan. Tersedia filter
  pengguna/aktivitas/entitas/ID/kata kunci/tanggal serta **Ekspor CSV** (aman
  dibuka di Excel). Password/API key **tidak pernah** tercatat di sini.
- **Backup & Restore** — khusus Admin; lihat bagian 10.

## 4. Data Master (Kategori, Supplier, Lokasi, Sparepart, Impor, Stok Awal)

> **Siapa yang boleh mengisi?** Hanya **Partman**. Kepala Bengkel & Admin dapat
> **melihat** semua daftar (untuk pengawasan/laporan), tetapi tombol tambah/ubah
> tidak muncul dan URL-nya ditolak **403** bila dipaksa.

### 4.1 Kategori sparepart
Menu **Data Master → Kategori**.
- **Tambah/Ubah**: kode (mis. `OLI`, otomatis jadi huruf besar), nama, keterangan.
- **Nonaktifkan**: kategori lama tetap tersimpan (tidak pernah dihapus
  permanen), tetapi **tidak dapat dipakai untuk sparepart baru**.
  Mengaktifkan kembali: tombol *Aktifkan*.

### 4.2 Supplier
Menu **Data Master → Supplier**.
- Isi kode, **nama perusahaan**, alamat, telepon, email, contact person,
  lead time (hari).
- **Nonaktif** berarti supplier tidak boleh dipakai untuk transaksi baru;
  data & riwayatnya tetap tersimpan.

### 4.3 Lokasi penyimpanan (rak/tingkat)
Menu **Data Master → Lokasi**. Satu gudang dengan banyak rak/tingkat.
- Contoh kode: `RAK-A1` (Rak A, tingkat 1), `RAK-A1-T2` (Rak A1, tingkat 2).
- Nama sebaiknya deskriptif: "Rak A1 Tingkat 2 — Oli & Filter".

### 4.4 Master sparepart
Menu **Data Master → Sparepart**. Daftar dapat dicari dan disaring
(kategori, supplier, lokasi, status stok: **Aman / Menipis / Kritis / Habis /
Tanpa Minimum**).
- Arti status stok:
  | Status | Arti |
  |---|---|
  | **Aman** | stok > 150% dari stok minimum |
  | **Menipis** | stok 100%–150% dari stok minimum — segera rencanakan pembelian |
  | **Kritis** | stok masih ada tetapi **di bawah** stok minimum |
  | **Habis** | stok = 0 |
  | **Tanpa Minimum** | stok minimum belum diisi (0), jadi perbandingan tidak dapat dihitung — isi stok minimum agar status akurat |
- **Tambah/ubah**: kode (unik), nama, kategori (**wajib**), brand, model
  kendaraan, satuan (mis. `pcs`, `liter`), harga beli terakhir, harga jual,
  stok minimum, lokasi, supplier.
- **Harga beli**:
  - **Harga Beli Terakhir** diperbarui **otomatis** setiap penerimaan bertipe
    *Pembelian* (harga per unit terbaru).
  - **Harga Beli Rata-rata** (weighted average) dihitung terpisah dan dipakai
    sebagai **dasar nilai persediaan** pada halaman detail.
  - **Riwayat Harga Beli** (10 pembelian terakhir) tampil di detail sparepart —
    tanggal, nomor dokumen, supplier, jumlah, dan harga per unit.
  - Penerimaan bertipe *Lainnya* hanya menambah stok; harga tidak berubah.
- **Kode duplikat ditolak** dengan pesan jelas.
- **Stok tersedia tidak dapat diubah dari form ini.** Jumlah stok hanya berubah
  lewat **pergerakan stok** (ledger) agar selalu ada jejak siapa/kapan/mengapa.
- Saat **menambah** sparepart, tersedia blok **Stok Awal** (opsional): lihat §4.6.

### 4.5 Impor data massal (.csv / .xlsx)
Menu **Impor Data** (khusus Partman).
1. **Unduh template** sesuai jenis data (`.csv` untuk Excel versi apa pun,
   `.xlsx` bila ekstensi `zip` aktif). Template berisi **baris header saja**;
   contoh nilai ada di halaman.
2. **Unggah** berkas → muncul **Pratinjau**: setiap baris diberi nomor dan
   alasan bila bermasalah (kode duplikat, kategori/supplier/lokasi tidak
   dikenal, harga/minimum stok tidak valid, tipe data salah).
3. **Simpan Semua** hanya muncul bila **seluruh baris valid** — penyimpanan
   bersifat **atomik** (satu baris salah = tidak ada yang tersimpan).
4. Batas: maksimal **500 baris** dan **2 MB** per berkas.
5. **Kode yang sudah ada di database DITOLAK** (impor tidak menimpa data lama).
   Perbaiki kode di berkas, atau ubah datanya lewat menu Sparepart.
6. Bila baris impor memuat **stok > 0**, stok tersebut dicatat sebagai
   pergerakan **Stok Awal** (bukan pembelian) — lihat §4.6.

### 4.6 Stok awal (manual & impor)
- Stok awal **bukan** pembelian; ia dicatat sebagai pergerakan **`STOK_AWAL`**
  berisi petugas, tanggal bisnis, jumlah, dan catatan.
- Dapat diisi manual (tombol **Input Stok Awal** pada detail sparepart) atau
  lewat impor berkas (kolom Kode, Jumlah, Tanggal, Catatan).
- **Hanya sekali per sparepart.** Setelah tercatat, form input tidak disediakan
  lagi dan percobaan kedua ditolak dengan pesan
  *"Stok awal … sudah pernah dicatat"*. **Koreksi** dilakukan lewat
  **Stock Opname** (§5.3) agar tetap berjejak.
- **Kartu Stok** pada detail sparepart menampilkan seluruh pergerakan
  (tanggal bisnis, jenis, jumlah masuk/keluar, sisa, petugas, catatan).

### 4.7 Bukti jejak (Audit Log)
Setiap perubahan master dicatat otomatis: `CATEGORY_*`, `SUPPLIER_*`,
`LOCATION_*`, `SPAREPART_*`, `STOCK_INITIAL_CREATE`, `IMPORT_MASTER`,
`IMPORT_STOCK_INITIAL`. Hanya **Admin** yang dapat membuka **Audit Log**, dan
**password tidak pernah** tercatat di dalamnya.

## 5. Transaksi & Pengelolaan Stok (Fase 4)

> **Siapa yang boleh mencatat?** Hanya **Partman**. Kepala Bengkel & Admin dapat
> **melihat** semua daftar, detail, bukti, dan riwayat, tetapi tombol
> *Catat/Mulai/Koreksi* tidak muncul dan URL form ditolak **403** bila dipaksa.

Prinsip penting: **stok hanya berubah lewat pergerakan (ledger)**. Setiap
perubahan mencatat siapa, kapan, mengapa, dan sisa stok sebelum/sesudah.

### 5.1 Barang masuk (penerimaan)

Menu **Transaksi → Barang Masuk → Catat Penerimaan**.

1. Pilih **tanggal penerimaan**, **supplier**, dan **sparepart**.
2. Isi **jumlah**, **harga beli per unit**, **nomor invoice** (opsional),
   **catatan** (opsional).
3. **Bukti** (opsional): foto nota atau PDF invoice — maksimal **5 MB**,
   format JPG/JPEG/PNG/PDF. Bukti disimpan aman dan hanya dapat dibuka lewat
   detail penerimaan.
4. **PO**: bila barang datang berdasarkan PO, pilih nomornya. Bila **tanpa PO**,
   kolom **alasan** wajib diisi.
5. Simpan. **Nomor dokumen dibuat otomatis** (`GR-YYYYMM-####`).
6. **Stok bertambah hanya setelah penyimpanan berhasil.** Bila penyimpanan
   gagal, stok tidak berubah sama sekali.

Catatan:
- Penerimaan bertipe **Pembelian** memperbarui **tanggal pembelian terakhir**,
  **harga beli terakhir**, dan **harga beli rata-rata** (weighted average)
  sparepart. Tipe **Lainnya** (mis. retur) hanya menambah stok.
  Riwayat harga lengkap dapat dilihat di **detail sparepart**.
- Penerimaan terkait PO tidak boleh melebihi **sisa** pesanan; status PO menjadi
  *Sebagian Diterima* / *Selesai Diterima*.

### 5.2 Barang keluar (pengeluaran)

Menu **Transaksi → Barang Keluar → Catat Pengeluaran**.

1. Pilih **tanggal**, **sparepart**, dan **jenis penggunaan**:
   **Service** (perbaikan), **Penjualan**, atau **Lainnya**.
2. Isi **jumlah**, **nomor SPK** (opsional), **nomor polisi** (opsional, otomatis
   huruf besar), dan **catatan**.
3. Simpan. Nomor dokumen otomatis (`GI-YYYYMM-####`).
4. Bila jumlah **melebihi stok tersedia**, penyimpanan **ditolak** dengan pesan
   *"Stok tidak mencukupi…"* dan stok **tidak berubah**. Stok tidak pernah
   menjadi negatif.

### 5.3 Stock opname (perhitungan fisik)

Menu **Transaksi → Stock Opname → Mulai Stock Opname**.

1. Isi **tanggal** dan **catatan sesi** (opsional) → sistem membuat nomor
   `SO-YYYYMM-####` dan **snapshot** stok sistem seluruh sparepart.
2. Isi **jumlah fisik** untuk item yang dihitung. Selisih dihitung otomatis.
   **Alasan wajib** untuk setiap item yang selisihnya ≠ 0.
3. **Simpan Hasil Hitung** — **stok belum berubah** pada tahap ini.
4. Setelah yakin, tekan **Konfirmasi Hasil Opname**. Baru pada saat ini stok
   disesuaikan lewat pergerakan `PENYESUAIAN_OPNAME`, dan sesi menjadi
   *terkonfirmasi* (tidak dapat diubah lagi).

Catatan:
- Hanya **satu sesi terbuka** pada satu waktu. Selesaikan sesi yang ada sebelum
  memulai yang baru.
- Hasil akhir opname **selalu sama dengan jumlah fisik** yang Anda hitung, walau
  ada transaksi lain setelah snapshot. Halaman menandai item yang
  *"berubah sejak snapshot"*.
- Salah input stok awal? Gunakan Stock Opname untuk menyesuaikan — jangan
  menghapus riwayat.

### 5.4 Koreksi transaksi (tanpa menghapus riwayat)

Menu **Transaksi → Riwayat Stok**, lalu tombol **Koreksi** pada baris
`MASUK`/`KELUAR` — atau menu **Transaksi → Koreksi Transaksi**.

1. Pilih transaksi asal (hanya `MASUK` dan `KELUAR` yang dapat dikoreksi).
2. Isi **jumlah yang dibalik** (boleh sebagian) dan **alasan** (wajib).
3. Simpan. Sistem membuat pergerakan **`KOREKSI`** baru yang menunjuk transaksi
   asal; **transaksi asal tidak dihapus atau diubah**.

Batasan:
- Total pembalikan **tidak boleh melebihi** jumlah transaksi asal.
- Koreksi tidak boleh membuat stok negatif.
- `STOK_AWAL` dan `PENYESUAIAN_OPNAME` dikoreksi lewat **Stock Opname** (§5.3),
  bukan lewat menu Koreksi.
- Setiap koreksi tercatat di **Audit Log** beserta nilai sebelum/sesudah.

### 5.5 Riwayat pergerakan stok

Menu **Transaksi → Riwayat Stok** menampilkan seluruh pergerakan:
`STOK_AWAL`, `MASUK`, `KELUAR`, `PENYESUAIAN_OPNAME`, `KOREKSI`.
Dapat disaring berdasarkan pencarian (kode/nama/nomor dokumen/catatan),
sparepart, jenis pergerakan, jenis referensi, dan rentang tanggal, serta
menampilkan ringkasan total masuk/keluar.

## 6. Dashboard, Laporan & Dokumen Cetak (Fase 5)

### 6.1 Dashboard (berbeda tiap peran)

Setiap peran melihat dashboard yang berbeda setelah login.

| Peran | Isi dashboard |
|---|---|
| Partman | Total jenis sparepart & total stok, barang masuk/keluar hari ini, jumlah stok habis/kritis/menipis, bagian rekomendasi reorder, grafik pergerakan stok, transaksi terbaru, ringkasan aktivitas hari ini |
| Kepala Bengkel | Nilai persediaan, pemakaian tertinggi & terendah, daftar stok kritis, grafik barang masuk/keluar, ringkasan pembelian (status PO & pembayaran) |
| Admin | Jumlah pengguna & sparepart, statistik transaksi, aktivitas pengguna terbaru, status sistem, ringkasan laporan, pintasan ke Pengaturan & Chatbot |

**Notifikasi** (stok habis/kritis/menipis, stok minimum belum diatur, status
reorder & PO, pembayaran belum lunas) tampil **hanya di dashboard**, tidak
melalui email/WhatsApp, dan isinya mengikuti hak akses Anda.

### 6.2 Melihat & mencetak laporan

1. Buka menu **Laporan**. Semua peran dapat melihat laporan.
2. Pilih jenis laporan (12 pilihan: stok, barang masuk, barang keluar, stok
   minimum, opname, riwayat, fast/slow moving, supplier, pembelian,
   penerimaan PO, pembayaran, AI).
3. Atur filter bila perlu: **tanggal**, **kategori**, **kode/nama sparepart**,
   **supplier**, dan **status** (setiap laporan hanya menampilkan filter yang
   relevan).
4. Untuk mengubah parameter fast/slow moving (periode analisis, ambang cepat,
   ambang lambat), minta Admin mengaturnya di menu **Pengaturan**.
5. Tombol di kanan atas:
   - **Cetak** → buka tampilan cetak lalu gunakan dialog cetak browser
     (Ctrl+P). Layout sudah dioptimalkan (kop identitas bengkel, tabel tidak
     terpotong, mencakup tanda tangan bila diperlukan).
   - **PDF** → unduh berkas `.pdf` A4.
   - **Excel** → unduh berkas `.xlsx` untuk dianalisis lebih lanjut.

Catatan penting:

- Setiap laporan dibatasi maksimum **5000 baris**; persempit filter bila
  hasil terpotong.
- **Angka nilai persediaan** memakai metode **harga beli rata-rata
  tertimbang**, dengan **harga beli terakhir** sebagai pembanding. Metode
  ini dinyatakan di laporan agar tidak menimbulkan salah tafsir.
- Laporan **AI**, modul **Prediksi**, dan **Chatbot** sudah aktif (Fase 7 & 8).
  **Rekomendasi reorder** memakai prediksi bila hasilnya masih segar &
  memadai; bila tidak, kembali ke aturan stok-minimum — lihat §7.1 dan §8.

### 6.3 Cetak dokumen transaksi & PO

- **Bukti barang masuk**: buka detail penerimaan → klik **Cetak Bukti**.
- **Bukti barang keluar**: buka detail pengeluaran → klik **Cetak Bukti**.
- **Purchase Order**: buka laporan pembelian → buka detail PO → **Cetak**
  (atau **PDF**).

Kop dokumen memakai identitas bengkel yang diatur Admin di **Pengaturan**.

### 6.4 Pengaturan aplikasi (khusus Admin)

Menu **Pengaturan** berisi:

- **Identitas bengkel**: nama (wajib), alamat, telepon, email, catatan kaki,
  dan **logo PNG/JPEG maks. 1 MB** — dipakai sebagai kop semua dokumen cetak.
  Logo dapat dihapus kapan saja.
- **Parameter laporan**: periode analisis fast/slow moving (7–365 hari),
  ambang cepat, dan ambang lambat. Ambang lambat **harus lebih kecil** daripada
  ambang cepat.

Setiap perubahan tersimpan beserta catatan jejak (nilai lama → nilai baru) di
Audit Log.

## 7. Reorder & Purchase Order (Fase 6)

Alurnya bertahap dan **setiap langkah dicatat** (siapa, kapan, status lama →
baru, alasan). **Persetujuan tidak menambah stok** — stok hanya bertambah
saat barang benar-benar diterima (§5.1).

### 7.1 Permintaan reorder (Partman → Kepala Bengkel)

1. Buka menu **Reorder** — daftar sparepart yang stoknya di bawah minimum
   tampil sebagai kebutuhan.
2. Klik **Buat Permintaan** — sistem memberi **saran jumlah**
   (`ceil(stok minimum − stok)`). Anda boleh mengubah angkanya, tetapi
   **wajib mengisi alasan**. Supplier dapat dipilih opsional.
3. Simpan — permintaan langsung berstatus **Menunggu Persetujuan** (nomor
   `RQ-…`). Stok **tidak berubah**.
4. **Kepala Bengkel** membuka menu **Reorder** → detail → **Setujui** atau
   **Tolak**. Menolak **wajib mengisi alasan**; permintaan yang sudah
   diputuskan tidak dapat ditinjau ulang atau diubah jumlahnya.

### 7.2 Membuat Purchase Order (Partman)

1. Buka menu **Pembelian** → **Buat PO**. Bisa dimulai dari detail reorder
   yang disetujui (isian awal terisi otomatis) — satu reorder hanya boleh
   menjadi satu PO.
2. Pilih **supplier** — sistem menampilkan **rekomendasi** berdasarkan
   riwayat pembelian (sering beli, terbaru, harga terakhir) + supplier
   default sparepart. **Keputusan akhir tetap di tangan Anda.**
3. Tambahkan item (sparepart, jumlah, harga satuan) — total dihitung sistem.
   Simpan sebagai **Draf** (nomor `PO-…`), lalu **Ajukan** bila sudah yakin.
4. Setelah diajukan, **isi PO tidak dapat diubah** — kesalahan diperbaiki
   dengan membatalkan (§7.6) lalu membuat PO baru yang beralasan.

### 7.3 Persetujuan PO (Kepala Bengkel)

Buka menu **Pembelian** → detail PO → **Setujui** atau **Tolak**
(tolak = **wajib alasan**). Persetujuan **tidak menambah stok**.

### 7.4 Proses & pembayaran (Admin)

- Setelah disetujui, Admin membuka detail PO → **Proses** (status
  `DIPROSES_ADMIN`) — misalnya untuk diteruskan ke pemasok.
- **Pembayaran** diubah Admin pada PO yang sama: `Belum Dibayar` /
  `Dibayar Sebagian` / `Lunas` + catatan (mis. "DP 50% transfer").
  Status pembayaran **terpisah** dari status penerimaan; setiap perubahan
  berriwayat. Sistem ini **bukan modul akuntansi**.

### 7.5 Mencatat penerimaan (Partman — menu Barang Masuk)

1. Buka **Transaksi → Barang Masuk → Catat Barang Masuk**.
2. Pada kolom **Purchase Order**, pilih PO yang sudah disetujui — item PO,
   jumlah, dan **sisa** terisi otomatis mengikuti PO.
3. Isi jumlah diterima. **Penerimaan parsial diizinkan**: menerima sebagian
   membuat status PO menjadi **Sebagian Diterima** dengan sisa tercatat;
   setelah sisa terpenuhi seluruhnya, PO menjadi **Selesai Diterima**.
4. **Jumlah diterima tidak boleh melebihi sisa PO** — permintaan berlebih
   selalu ditolak. Penerimaan tanpa PO tetap bisa, tetapi **wajib
   mengisi alasan**.
5. **Stok baru bertambah di langkah ini** (sesuai jumlah yang diterima), dan
   harga beli terakhir/rata-rata ikut diperbarui untuk tipe pembelian.

### 7.6 Pembatalan, cetak & riwayat

- **Batal** (dengan alasan) menggantikan penghapusan PO: Partman membatalkan
  draft/menunggu miliknya; Admin juga membatalkan yang sudah disetujui/diproses
  namun belum diterima. PO yang sudah pernah menerima penerimaan **tidak dapat
  dibatalkan** — tutup lewat penerimaan penuh atau opname bila fisik berbeda.
  **Tidak ada tombol hapus** — PO, item, dan riwayatnya selalu tersimpan.
- **Cetak PO** dari detail PO (Cetak/PDF) atau laporan pembelian.
- **Riwayat status** (aktor, waktu, transisi, alasan) tampil di detail
  reorder & PO; **riwayat pembayaran** tampil di detail PO.

## 8. Prediksi Stok AI (Fase 7)

Prediksi memberi **usulan** permintaan, risiko kehabisan stok, dan **saran
jumlah reorder**. Sistem **tidak pernah memesan otomatis** dan **tidak mengubah
stok** apa pun dari halaman ini.

**Siapa yang melakukan apa:**

- **Partman** — menjalankan analisis (`Jalankan Prediksi`) dan memilih periode
  target (7/30/90 hari) serta rentang riwayat. Saran prediksi menjadi dasar
  reorder; mengubah jumlahnya tetap **wajib beralasan** (§7.1).
- **Kepala Bengkel** — melihat hasil prediksi (mis. pada dashboard & menu
  **Prediksi**) untuk pengawasan; **tidak dapat menjalankan** analisis.
- **Admin** — melihat hasil; **tidak dapat menjalankan** analisis.

**Cara menjalankan (Partman):**

1. Buka menu **Prediksi** → pilih sparepart (atau dari daftar) → halaman
   detail.
2. Pilih **periode target** (7/30/90 hari) dan **rentang riwayat** (30–365 hari).
3. Klik **Jalankan Prediksi**. Analisis berjalan lokal di server (beberapa
   detik). Setelah selesai, halaman menampilkan hasil terbaru & **riwayat
   analisis**.
4. **(Opsional) Override lead time** — bila lead time otomatis tidak sesuai
   kondisi nyata (mis. supplier sedang lama), isi **jumlah hari** dan **alasan**
   (wajib, minimal 10 karakter). Nilai otomatis tetap ditampilkan sebagai
   pembanding dan setiap override dicatat di **Audit Log**. Kosongkan bila tidak
   perlu.

**Arti hasil:**

| Bagian | Arti |
|---|---|
| Estimasi permintaan | perkiraan total permintaan pada periode target |
| Hari ke minimum / nol | perkiraan berapa hari stok mencapai stok minimum / habis |
| Risiko stockout | **Rendah / Sedang / Tinggi** |
| Saran reorder | jumlah yang disarankan dipesan (usulan, bukan perintah) |
| Kategori fast/slow | cepat/sedang/lambat sesuai parameter laporan (Pengaturan) |
| Indikator kecukupan data | **Cukup / Terbatas / Data terbatas** |

**Yang penting diketahui:**

- Jika riwayat **terlalu sedikit** (kurang dari **30 hari berdata** atau
  **< 5 transaksi keluar**), status **"Data terbatas"** ditampilkan dan sistem
  **tidak menampilkan angka apa pun** — aplikasi tidak mengarang prediksi.
- Status **"Terbatas"** berarti data sudah memenuhi ambang minimum tetapi belum
  mencapai ambang penuh (60 hari & 10 transaksi); angka ditampilkan dengan
  hati-hati.
- **Lead time** (jarak waktu pengadaan) diambil dari **riwayat penerimaan PO**;
  bila tidak ada, dari **data supplier**; bila keduanya kosong sistem memakai
  asumsi **7 hari** dan menandai bahwa **Reorder Point belum dapat dihitung
  secara andal**. Partman dapat melakukan **override manual** dengan **alasan
  wajib**; nilai otomatis tetap ditampilkan dan perubahan dicatat di Audit Log
  (`LEAD_TIME_OVERRIDE`).
- Prediksi = **usulan**. Akurasi bergantung pada mutu riwayat barang keluar;
  pola musiman/perubahan mendadak bisa membuat hasil melenceng.
- Bila proses prediksi **gagal** (mis. gangguan pada Python), saran otomatis
  kembali ke **aturan stok minimum** dan transaksi inventory tetap berjalan
  normal — kegagalan prediksi **tidak pernah** menghambat pekerjaan gudang.

## 9. Chatbot Gemini AI (Fase 8)

Chatbot membantu menjawab pertanyaan seputar **SmartPartMan** dan pengelolaan
persediaan. Ia adalah **asisten baca-saja**: **tidak dapat mengubah transaksi**
dan **tidak memiliki akses SQL** ke database.

### 9.1 Siapa dapat menggunakan

| Peran | Yang dapat ditanyakan |
|---|---|
| Partman | stok, sparepart, barang masuk/keluar, opname, koreksi, reorder, PO, supplier, prediksi |
| Kepala Bengkel | ringkasan laporan, nilai persediaan, pemakaian, kondisi stok, rekomendasi pemesanan, PO/pembayaran, prediksi |
| Admin | semua topik operasional + statistik pengguna/persediaan/transaksi dan aktivitas aplikasi (agregat, bukan data mentah massal) |

Data pengguna, audit, atau topik khusus Admin **ditolak** untuk Partman/Kepala
Bengkel. Pertanyaan di luar SmartPartMan juga ditolak.

### 9.2 Cara memakai (semua peran)

Chatbot tersedia sebagai **Floating AI Assistant**: tombol bulat **SmartPartMan
AI** di pojok kanan bawah pada **setiap halaman** setelah login (tidak tampil di
halaman login/installer). Klik tombol untuk membuka jendela percakapan tanpa
meninggalkan halaman. Halaman **Chatbot** di menu sidebar tetap tersedia
sebagai tampilan penuh.

1. Klik tombol **SmartPartMan AI** di kanan bawah (atau buka menu **Chatbot**).
2. Ketik pertanyaan (3–1000 karakter), misalnya:
   - “Sparepart apa yang stoknya kritis?”
   - “Ringkas barang masuk bulan ini.”
   - “Berapa saran reorder untuk oli?”
3. Klik **Kirim** atau tekan **Enter** (Shift+Enter untuk baris baru).
   Indikator “AI sedang memproses” tampil sampai jawaban diterima.
4. Tutup jendela kapan saja — percakapan **tidak hilang** selama masih sesi
   browser yang sama. Ikon **percakapan baru** menghapus riwayat dan
   menampilkan sambutan kembali.
5. Riwayat hanya tersimpan di **sesi browser**; setiap pertanyaan diproses
   tanpa mengirim riwayat ke Gemini.

Bila chatbot **nonaktif** atau **API key belum diatur**, tombol tetap tampil
dan jendela menampilkan penjelasan; kolom input dinonaktifkan.

**Yang tidak dapat dilakukan chatbot:** menulis/menjalankan SQL, menampilkan
API key/password/token, mengubah stok/transaksi, atau mengarang angka. Bila data
tidak tersedia, ia diminta mengatakan dengan jujur dan mengarahkan ke menu
aplikasi. Selalu verifikasi angka penting di menu/laporan.

### 9.3 Pengaturan Chatbot (khusus Admin)

Buka **Pengaturan → Chatbot**. Tersedia:

- **Aktif/nonaktif** chatbot.
- **API key Gemini** — disimpan **terenkripsi**; hanya ditampilkan
  tersamarkan (`****abcd`). Ada opsi menghapus key.
- **Model** — `Gemini 3.5 Flash-Lite` (default, cepat & hemat) atau
  `Gemini 3.8 Flash` (lebih cerdas/lambat).
- **Instruksi sistem** dan **batas topik**.
- **Gaya jawaban** — ringkas, normal, atau detail.
- **Batas pemakaian** harian/bulanan (panggilan API); kosong/0 = tanpa batas.
- **Uji Koneksi** — memeriksa key/model tanpa memakai kuota generasi.
- **Ringkasan pemakaian & error terbaru** — tanpa API key/pertanyaan lengkap.

### 9.4 Bila chatbot tidak tersedia

- **“Chatbot dinonaktifkan”** — minta Admin mengaktifkannya.
- **“API key belum diatur”** — Admin perlu mengisi API key.
- **“API key Gemini tidak valid”** — key salah/kedaluwarsa; buat key baru.
- **“Sedang tidak dapat dihubungi”/timeout** — periksa internet/kuota; coba
  lagi nanti.
- **“Batas penggunaan tercapai”** — tunggu atau minta Admin menaikkan batas.
- Gangguan chatbot **tidak menghambat** barang masuk/keluar, opname, atau
  laporan. Modul inventory tetap berjalan normal.

> **Catatan verifikasi:** pengembangan ini belum memiliki API key Gemini nyata,
> sehingga koneksi ke Google **belum diuji langsung**. Setelah key diisi,
> gunakan **Uji Koneksi** untuk memastikan akun/model Anda berfungsi.

## 10. Backup, restore & ekspor Audit Log (Fase 9)

Menu **Backup & Restore** hanya tersedia untuk **Admin**.

### 10.1 Membuat & mengunduh backup

1. Buka **Backup & Restore**.
2. Periksa nama database, jumlah migrasi, dan batas ukuran.
3. Klik **Buat Backup Sekarang**. Berkas `.sql` disimpan di server
   (`storage/backups/`, di luar folder web) dan muncul di daftar.
4. Klik **Unduh** untuk menyimpan salinan ke komputer. Simpan salinan itu di
   lokasi aman **terpisah dari server**.
5. Aplikasi **tidak menghapus backup otomatis**; lakukan retensi manual. Jangan
   menghapus backup terbaru sebelum ada salinan lain yang terverifikasi.

### 10.2 Restore database

> **Restore mengganti seluruh isi database.** Lakukan hanya bila benar-benar
> diperlukan dan pastikan backup terbaru sudah diunduh.

1. Pilih backup, lalu isi **password Admin** Anda dan ketik **`PULIHKAN`**
   (huruf besar, tanpa spasi).
2. Aplikasi memeriksa checksum, nama database, dan jumlah migrasi. Bila tidak
   cocok, restore ditolak.
3. Sebelum memulihkan, aplikasi otomatis membuat **backup keselamatan**
   (`pre_restore`). Bila proses gagal, snapshot ini dipulihkan kembali.
4. Setelah berhasil, semua sesi diakhiri. Login kembali dan periksa data.
5. Bila aplikasi menolak karena batas percobaan, tunggu 15 menit atau minta
   Admin lain memverifikasi. Jangan mencoba berulang dengan password salah.

### 10.3 Audit Log: filter & ekspor CSV

- Filter berdasarkan **pengguna, aktivitas, jenis entitas, ID entitas, kata
  kunci, dan rentang tanggal**.
- **Ekspor CSV** menghasilkan berkas maksimal 10.000 baris dengan BOM UTF-8
  (rapi di Excel) dan perlindungan formula injection.
- Aksi ekspor ikut dicatat sebagai `AUDIT_LOG_EXPORT`.
- Audit Log tetap **append-only**: tidak ada fitur ubah/hapus.

### 10.4 Logo bengkel

Unggah logo di **Pengaturan → Identitas bengkel** (PNG/JPEG maks. 1 MB). Logo
disajikan lewat route ber-otorisasi dan tampil di kop dokumen cetak. SVG dan
berkas selain gambar ditolak.

## 11. Modul (per fase)

- Master data — ✅ **tersedia (Fase 3)**: kategori, supplier, lokasi, sparepart,
  impor `.csv`/`.xlsx`, stok awal
- Transaksi — ✅ **tersedia (Fase 4)**: barang masuk, barang keluar, stock opname,
  koreksi pembalik, riwayat pergerakan, status stok otomatis
- Laporan & ekspor — ✅ **tersedia (Fase 5)**: dashboard per role, notifikasi,
  grafik, 12 laporan + filter, cetak, PDF, Excel, dokumen PO & bukti
  masuk/keluar, pengaturan identitas bengkel
- Reorder & Purchase Order — ✅ **tersedia (Fase 6)**: permintaan reorder +
  persetujuan Kepala Bengkel, draft PO + rekomendasi supplier, proses Admin,
  penerimaan parsial (sisa PO), pembayaran, pembatalan beralasan, riwayat
  status — lihat §7
- AI Stock Prediction — ✅ **tersedia (Fase 7)**: estimasi permintaan, risiko
  stockout, Safety Stock/ROP, periode 7/30/90 hari, indikator kecukupan data,
  saran reorder dari prediksi (bila segar & memadai, jika tidak kembali ke
  aturan minimum), tampil di dashboard & laporan `ai` — lihat §8
- AI Chatbot — ✅ **tersedia (Fase 8)**: chat untuk semua role dengan konteks
  sesuai hak akses, pengaturan Admin (API key terenkripsi, model, prompt,
  batas topik/gaya jawaban, batas pemakaian, uji koneksi), guard SQL/rahasia/
  luar topik, dan log pemakaian — lihat §9
- Administrasi & finalisasi — ✅ **tersedia (Fase 9)**: backup/restore database
  khusus Admin (checksum, password ulang, konfirmasi `PULIHKAN`, backup
  keselamatan otomatis, rollback), logo bengkel di kop dokumen, filter + ekspor
  CSV Audit Log, dan header keamanan — lihat bagian 10
