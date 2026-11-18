# Modul Prediksi Stok (Python) — Fase 7

Folder ini berisi pipeline prediksi yang dipanggil backend PHP.

## Berkas

- `forecast.py` — entry point. Dipanggil PHP via `proc_open` (argumen array,
  **tanpa shell**); data lewat JSON pada stdin, hasil JSON pada stdout.
- `requirements.txt` (di folder induk `python/`) — panduan dependensi.

## Metode integrasi PHP ↔ Python (keputusan disetujui)

- Metode: **CLI via `proc_open`** — proses lokal, **bukan** REST service.
- Tidak ada socket/proses jaringan sama sekali, sehingga layanan Python
  tidak mungkin terbuka ke jaringan publik (lokal-only by design).
- Perintah dibentuk sebagai array (`py`, `-3.12`, `forecast.py`) sehingga
  tidak ada interpolasi shell — tidak ada celah injeksi perintah.
- PHP mengirim JSON: riwayat barang keluar harian, stok saat ini, stok
  minimum, riwayat penerimaan, lead time supplier (bila ada), periode target
  (7/30/90 hari), dan rentang riwayat pilihan pengguna.
- Python membalas JSON: estimasi permintaan, perkiraan hari ke stok
  minimum/habis, risiko stockout, saran jumlah reorder, parameter, metrik
  evaluasi (MAE/RMSE/MAPE), versi model & pustaka, serta peringatan.
- Output divalidasi ketat oleh PHP (`App\Services\PredictionService`) sebelum
  disimpan ke tabel `ai_predictions`.
- Kegagalan Python **tidak** mengganggu transaksi inventory: fitur prediksi
  menampilkan status "tidak tersedia" dan saran reorder jatuh ke aturan
  stok-minimum.

## Menjalankan manual (untuk pengujian)

```powershell
Get-Content payload.json -Raw | py -3.12 python\prediction\forecast.py
```

## Metode & aturan model

- **Moving Average** (jendela 7 hari) dan **Linear Regression** (scikit-learn),
  dibandingkan objektif memakai MAE pada data uji.
- **Safety Stock** = z · σ_harian · √lead_time (z = 1,65 ≈ 95% layanan).
- **Reorder Point (ROP)** = laju harian · lead_time + safety stock.
- Pemisahan latih/uji **kronologis** (bagian awal latih, bagian akhir uji) —
  tidak ada data masa depan yang dipakai.
- **Data tidak cukup → prediksi tidak dihitung** (field `null` + alasan).
- Lead time kosong → SS/ROP diberi label **asumsi** + peringatan.
