#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SmartPartMan — Pipeline prediksi stok (Fase 7: AI Stock Prediction).

Dipanggil backend PHP melalui proses CLI LOKAL (proc_open, tanpa shell):

    py -3.12 forecast.py

    - Input : objek JSON pada stdin (dibangun PHP dari data terotorisasi;
              TIDAK PERNAH menerima SQL mentah dari browser/LLM).
    - Output: satu objek JSON pada stdin→stdout (UTF-8).
    - Gagal : {"status": "error", "error": "..."} dengan kode keluar != 0.

Metode: Moving Average, Linear Regression (scikit-learn), Safety Stock,
        Reorder Point (ROP).

Aturan model:
  1. Tidak mengarang prediksi bila data tidak cukup — field keluaran yang
     tidak dapat dihitung diisi null dan `data_sufficiency` menjelaskan alasannya.
  2. Lead time diprioritaskan dari RIWAYAT PENERIMAAN pembelian (median),
     lalu master supplier; bila keduanya kosong → Safety Stock/ROP memakai
     asumsi berlabel + peringatan eksplisit (ROP BELUM andal).
  3. Latih/uji HANYA memakai data lampau — pemisahan kronologis
     (bagian awal = latih, bagian akhir = uji). Tidak ada data masa depan.
  4. Metrik evaluasi (MAE/RMSE/MAPE) dihitung bila ukuran data memadai.
  5. Moving Average vs Linear Regression dibandingkan objektif: MAE uji
     terendah menang; seri → Moving Average (lebih sederhana).
  6. Parameter, versi model, dan versi pustaka ikut dicatat pada keluaran.
"""

import json
import math
import platform
import statistics
import sys
from datetime import date, datetime, timedelta

import numpy as np
import pandas as pd
import sklearn
from sklearn.linear_model import LinearRegression

SCHEMA = 1
MODEL_VERSION = "sfm-forecast/1.0.0"
PERIODS = (7, 30, 90)

# Parameter bawaan (dapat ditimpa PHP lewat kunci "params").
DEFAULT_PARAMS = {
    "ma_window": 7,                  # jendela Moving Average (hari)
    "safety_z": 1.65,                # z Safety Stock (~95% layanan)
    "test_ratio": 0.30,              # porsi uji kronologis
    "min_train_days": 14,            # minimal hari latih untuk evaluasi
    "min_test_days": 7,              # minimal hari uji untuk evaluasi
    "min_days_with_data": 30,        # ambang awal "data cukup": hari berdata
    "min_transactions": 5,           # ambang awal "data cukup": jumlah transaksi
    "sufficient_days": 60,           # ambang "cukup" penuh: hari berdata
    "sufficient_transactions": 10,   # ambang "cukup" penuh: transaksi
    "lead_time_fallback_days": 7,    # asumsi lead time bila data lead time kosong
}

MAX_DAYS = 36500  # pembatas kegilaan output (±100 tahun)


def emit(obj, code=0):
    """Tulis JSON ke stdout (UTF-8 murni) lalu keluar."""
    text = json.dumps(obj, ensure_ascii=False, allow_nan=False, separators=(",", ":"))
    sys.stdout.buffer.write(text.encode("utf-8"))
    sys.stdout.buffer.flush()
    sys.exit(code)


def fail(message):
    emit({"schema": SCHEMA, "status": "error", "error": str(message)[:500]}, 1)


def as_float(value, default=None):
    try:
        number = float(value)
    except (TypeError, ValueError):
        return default
    if not math.isfinite(number):
        return default
    return number


def rnd(value, digits=2):
    """Bulatkan; None/NaN/inf → None (aman untuk allow_nan=False)."""
    number = as_float(value)
    if number is None:
        return None
    return round(number, digits)


def clamp_days(value):
    if value is None:
        return None
    number = as_float(value)
    if number is None or number < 0:
        return None
    return int(min(number, MAX_DAYS))


def metrics(y_true, y_pred):
    """MAE, RMSE, MAPE (% pada hari beraktual > 0)."""
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    mae = float(np.mean(np.abs(y_true - y_pred)))
    rmse = float(np.sqrt(np.mean((y_true - y_pred) ** 2)))
    mask = y_true > 0
    if not bool(np.any(mask)):
        mape = None
    else:
        mape = float(np.mean(np.abs((y_true[mask] - y_pred[mask]) / y_true[mask])) * 100)
    return mae, rmse, mape


def main():
    raw = sys.stdin.buffer.read()
    try:
        payload = json.loads(raw.decode("utf-8"))
    except Exception as exc:  # noqa: BLE001 — semua kesalahan input dilaporkan rapi
        fail("stdin bukan JSON valid: " + str(exc))
    if not isinstance(payload, dict):
        fail("stdin harus berupa objek JSON.")

    warnings = []

    # --- 1. Validasi parameter utama -------------------------------------
    period = int(as_float(payload.get("period_days"), 0) or 0)
    if period not in PERIODS:
        fail("period_days harus salah satu dari 7, 30, 90.")

    history_days = int(as_float(payload.get("history_days"), 0) or 0)
    if history_days < 1 or history_days > 1095:
        fail("history_days tidak valid.")

    params = dict(DEFAULT_PARAMS)
    user_params = payload.get("params")
    if isinstance(user_params, dict):
        for key in DEFAULT_PARAMS:
            if key in user_params:
                value = as_float(user_params[key])
                if value is not None and value > 0:
                    params[key] = value

    as_of_raw = str(payload.get("as_of") or "")[:10]
    try:
        as_of = datetime.strptime(as_of_raw, "%Y-%m-%d").date()
    except ValueError:
        as_of = date.today()
        warnings.append("Tanggal analisis tidak valid — memakai tanggal hari ini.")

    stock = as_float(payload.get("stock"), 0.0)
    if stock is None or stock < 0:
        warnings.append("Stok saat ini tidak valid (negatif/kosong) — diperlakukan 0.")
        stock = 0.0

    min_stock = as_float(payload.get("min_stock"), 0.0)
    if min_stock is None or min_stock < 0:
        warnings.append("Stok minimum tidak valid (negatif) — diperlakukan 0.")
        min_stock = 0.0

    # --- 2. Lead time (PRIORITAS: override Partman → riwayat → master → asumsi)
    #   a. override manual Partman (keputusan C1.4) — selalu menang, beralasan,
    #   b. lead time nyata dari riwayat penerimaan pembelian (median),
    #   c. jika kosong → lead time master supplier,
    #   d. jika semuanya kosong → asumsi berlabel; ROP dinyatakan BELUM andal.
    master_lt = as_float(payload.get("lead_time_days"))
    override_lt = as_float(payload.get("lead_time_override_days"))
    history_lt = []
    for item in payload.get("lead_time_receipt_days") or []:
        sample = as_float(item)
        if sample is not None and sample > 0:
            history_lt.append(sample)
    lead_time_samples = len(history_lt)

    if override_lt is not None and override_lt > 0:
        lead_time_eff = float(override_lt)
        lead_time_source = "manual_override"
        lead_time_assumed = False
    elif lead_time_samples > 0:
        lead_time_eff = float(statistics.median(history_lt))
        lead_time_source = "receipt_history"
        lead_time_assumed = False
    elif master_lt is not None and master_lt > 0:
        lead_time_eff = float(master_lt)
        lead_time_source = "supplier_master"
        lead_time_assumed = False
    else:
        lead_time_assumed = True
        lead_time_source = "assumption"
        lead_time_eff = float(params["lead_time_fallback_days"])
        warnings.append(
            "Lead time supplier tidak tersedia (tidak ada riwayat penerimaan pembelian "
            "maupun lead time pada master supplier) — Safety Stock & ROP memakai asumsi "
            + str(int(lead_time_eff))
            + " hari sehingga ROP BELUM dapat dihitung secara andal. Isi lead time pada "
            "master supplier atau selesaikan penerimaan PO agar lead time terukur."
        )

    # --- 3. Deret harian barang keluar (rentang yang dipilih pengguna) ----
    start = as_of - timedelta(days=history_days - 1)
    index = pd.date_range(start=start, end=as_of, freq="D")
    qty_by_day = {}
    for item in payload.get("daily_out") or []:
        if not isinstance(item, dict):
            continue
        try:
            d = datetime.strptime(str(item.get("date") or "")[:10], "%Y-%m-%d").date()
        except ValueError:
            continue
        qty = as_float(item.get("qty"), 0.0) or 0.0
        if qty < 0:
            warnings.append("Terdapat qty keluar negatif pada riwayat — diperlakukan 0.")
            qty = 0.0
        if start <= d <= as_of:
            qty_by_day[d] = qty_by_day.get(d, 0.0) + qty

    values = [round(qty_by_day.get(ts.date(), 0.0), 4) for ts in index]
    series = pd.Series(values, index=index, dtype="float64")

    tx_count = int(as_float(payload.get("tx_count"), 0) or 0)
    if tx_count < 0:
        tx_count = 0
    days_with_data = int(sum(1 for v in values if v > 0))
    total_qty = float(np.sum(values)) if values else 0.0

    # --- 4. Ringkasan penerimaan (informatif) ----------------------------
    receipt_summary = None
    receipts = [r for r in (payload.get("receipts") or []) if isinstance(r, dict)]
    if receipts:
        receipt_total = 0.0
        last_date = None
        for item in receipts:
            qty = as_float(item.get("qty"), 0.0) or 0.0
            receipt_total += qty if qty > 0 else 0.0
            try:
                d = datetime.strptime(str(item.get("date") or "")[:10], "%Y-%m-%d").date()
                last_date = d if last_date is None or d > last_date else last_date
            except ValueError:
                continue
        receipt_summary = {
            "count": len(receipts),
            "total_qty": rnd(receipt_total, 2),
            "last_date": last_date.isoformat() if last_date else None,
        }

    # --- 5. Indikator kecukupan data -------------------------------------
    if tx_count < params["min_transactions"] or days_with_data < params["min_days_with_data"]:
        sufficiency = "tidak_cukup"
        reason = (
            "Transaksi barang keluar {tx} (min {mtx}) dan/atau hari berdata {dd} "
            "(min {mdd}) pada rentang {rng} hari — prediksi TIDAK dihitung agar "
            "tidak mengarang angka."
        ).format(
            tx=tx_count,
            mtx=int(params["min_transactions"]),
            dd=days_with_data,
            mdd=int(params["min_days_with_data"]),
            rng=history_days,
        )
    elif tx_count < params["sufficient_transactions"] or days_with_data < params["sufficient_days"]:
        sufficiency = "terbatas"
        reason = (
            "Transaksi {tx} dan hari berdata {dd} pada rentang {rng} hari belum "
            "mencapai ambang penuh ({mtx} transaksi / {mdd} hari) — hasil prediksi "
            "bersifat indikatif."
        ).format(
            tx=tx_count,
            dd=days_with_data,
            rng=history_days,
            mtx=int(params["sufficient_transactions"]),
            mdd=int(params["sufficient_days"]),
        )
    else:
        sufficiency = "cukup"
        reason = (
            "Transaksi {tx} pada {dd} hari berdata (rentang {rng} hari) memenuhi "
            "ambang prediksi."
        ).format(tx=tx_count, dd=days_with_data, rng=history_days)

    if sufficiency == "terbatas":
        warnings.append("Data terbatas — " + reason)

    history_range = {
        "start": start.isoformat(),
        "end": as_of.isoformat(),
        "days": len(values),
        "days_with_data": days_with_data,
        "tx_count": tx_count,
        "total_qty": rnd(total_qty, 2),
    }

    common = {
        "schema": SCHEMA,
        "status": "ok",
        "model_version": MODEL_VERSION,
        "generated_at": str(payload.get("generated_at") or datetime.now().isoformat()),
        "python_version": platform.python_version(),
        "library_versions": {
            "pandas": pd.__version__,
            "scikit_learn": sklearn.__version__,
            "numpy": np.__version__,
        },
        "period_days": period,
        "history_range": history_range,
        "receipt_summary": receipt_summary,
        "parameters": params,
        "data_sufficiency": sufficiency,
        "data_sufficiency_reason": reason,
        "lead_time_days": int(lead_time_eff),
        "lead_time_assumed": lead_time_assumed,
        "lead_time_source": lead_time_source,
        "lead_time_samples": lead_time_samples,
        "lead_time_reliable": not lead_time_assumed,
        "warnings": warnings[:20],
    }

    # --- 6. Data tidak cukup: JANGAN mengarang prediksi -------------------
    if sufficiency == "tidak_cukup":
        common.update(
            {
                "methods": None,
                "evaluation": {
                    "performed": False,
                    "note": "Evaluasi tidak dilakukan — data tidak cukup untuk "
                            "pemisahan kronologis latih/uji.",
                    "test_days": None,
                    "split": None,
                    "moving_average": None,
                    "linear_regression": None,
                },
                "comparison": None,
                "predicted_demand": None,
                "daily_rate": None,
                "days_to_min": None,
                "days_to_zero": None,
                "stockout_risk": None,
                "safety_stock": None,
                "reorder_point": None,
                "lead_time_days": int(lead_time_eff),
                "lead_time_assumed": lead_time_assumed,
                "recommended_qty": None,
            }
        )
        emit(common)

    # --- 7. Evaluasi kronologis (tanpa bocoran data masa depan) ----------
    n = len(values)
    window = max(1, min(int(params["ma_window"]), n))

    ma_mae = lr_mae = None
    ma_rmse = lr_rmse = None
    ma_mape = lr_mape = None
    eval_performed = False

    test_n = max(int(params["min_test_days"]), int(round(n * params["test_ratio"])))
    train_n = n - test_n
    if train_n >= int(params["min_train_days"]) and test_n >= int(params["min_test_days"]):
        # Latih: `train_n` hari PERTAMA. Uji: `test_n` hari TERAKHIR.
        y_test = series.iloc[train_n:].to_numpy(dtype=float)

        # Moving Average satu-langkah: prediksi hari i memakai hari < i saja.
        ma_pred = np.array(
            [float(series.iloc[max(0, i - window):i].mean()) for i in range(train_n, n)],
            dtype=float,
        )

        # Linear Regression: dilatih pada indeks latih, diuji pada indeks uji
        # (ekstrapolasi ke depan — hanya fit pada bagian latih).
        X_train = np.arange(train_n, dtype=float).reshape(-1, 1)
        y_train = series.iloc[:train_n].to_numpy(dtype=float)
        eval_model = LinearRegression().fit(X_train, y_train)
        X_test = np.arange(train_n, n, dtype=float).reshape(-1, 1)
        lr_pred = np.maximum(eval_model.predict(X_test), 0.0)

        ma_mae, ma_rmse, ma_mape = metrics(y_test, ma_pred)
        lr_mae, lr_rmse, lr_mape = metrics(y_test, lr_pred)
        eval_performed = True
        split_note = "Pemisahan kronologis: latih {t} hari pertama, uji {u} hari terakhir.".format(
            t=train_n, u=test_n
        )
    else:
        test_n = 0
        split_note = (
            "Evaluasi tidak dilakukan: riwayat {n} hari belum mencukupi latih "
            "≥ {mt} + uji ≥ {me} hari.".format(
                n=n,
                mt=int(params["min_train_days"]),
                me=int(params["min_test_days"]),
            )
        )

    evaluation = {
        "performed": eval_performed,
        "split": split_note if eval_performed else None,
        "test_days": test_n if eval_performed else None,
        "note": split_note,
        "moving_average": (
            {"mae": rnd(ma_mae, 4), "rmse": rnd(ma_rmse, 4), "mape": rnd(ma_mape, 4)}
            if eval_performed
            else None
        ),
        "linear_regression": (
            {"mae": rnd(lr_mae, 4), "rmse": rnd(lr_rmse, 4), "mape": rnd(lr_mape, 4)}
            if eval_performed
            else None
        ),
    }

    # --- 8. Pemilihan metode (objektif: MAE uji terendah) ----------------
    if eval_performed and ma_mae is not None and lr_mae is not None:
        if lr_mae < ma_mae - 1e-9:
            selected = "linear_regression"
        else:
            selected = "moving_average"  # seri → MA (lebih sederhana & stabil)
        selection_rule = (
            "Metode dengan MAE uji terendah yang dipakai; bila seri memakai "
            "Moving Average (model lebih sederhana)."
        )
    else:
        selected = "moving_average"
        selection_rule = (
            "Evaluasi tidak memadai — Moving Average dipakai sebagai metode "
            "utama yang lebih sederhana."
        )

    # --- 9. Prakiraan final memakai seluruh riwayat lampau ---------------
    ma_daily = float(series.iloc[-window:].mean())
    ma_forecast = ma_daily * period

    X_all = np.arange(n, dtype=float).reshape(-1, 1)
    y_all = series.to_numpy(dtype=float)
    final_model = LinearRegression().fit(X_all, y_all)
    future_index = np.arange(n + 1, n + 1 + period, dtype=float).reshape(-1, 1)
    lr_future_daily = np.maximum(final_model.predict(future_index), 0.0)
    lr_forecast = float(np.sum(lr_future_daily))
    lr_slope = float(np.asarray(final_model.coef_).reshape(-1)[0])

    methods = {
        "moving_average": {
            "window_days": window,
            "daily_avg": rnd(ma_daily, 4),
            "forecast": rnd(ma_forecast, 2),
        },
        "linear_regression": {
            "slope_per_day": rnd(lr_slope, 6),
            "daily_first": rnd(lr_future_daily[0], 4),
            "daily_last": rnd(lr_future_daily[-1], 4),
            "forecast": rnd(lr_forecast, 2),
        },
    }

    predicted_demand = lr_forecast if selected == "linear_regression" else ma_forecast
    predicted_demand = rnd(predicted_demand, 2)
    daily_rate = rnd(predicted_demand / period, 4) if predicted_demand is not None else None

    comparison = {
        "selected_method": selected,
        "rule": selection_rule,
        "mae_moving_average": rnd(ma_mae, 4),
        "mae_linear_regression": rnd(lr_mae, 4),
    }

    # --- 10. Perkiraan hari ke stok minimum / habis ----------------------
    if daily_rate is None:
        days_to_min = None
        days_to_zero = None
    else:
        if stock <= min_stock:
            days_to_min = 0
        elif daily_rate <= 0:
            days_to_min = None
            warnings.append(
                "Laju keluar harian 0 — perkiraan hari mencapai stok minimum "
                "tidak dapat dihitung."
            )
        else:
            days_to_min = clamp_days(math.floor((stock - min_stock) / daily_rate))

        if stock <= 0:
            days_to_zero = 0
        elif daily_rate <= 0:
            days_to_zero = None
        else:
            days_to_zero = clamp_days(math.floor(stock / daily_rate))

    # --- 11. Risiko stockout --------------------------------------------
    if daily_rate is None:
        stockout_risk = None
    elif stock <= 0 or (min_stock > 0 and stock <= min_stock):
        stockout_risk = "tinggi"
    elif daily_rate <= 0:
        stockout_risk = "rendah"  # stok di atas minimum, tanpa riwayat keluar
    else:
        coverage = stock / daily_rate
        if coverage <= lead_time_eff:
            stockout_risk = "tinggi"
        elif coverage <= 2 * lead_time_eff or (stock - (predicted_demand or 0.0)) < min_stock:
            stockout_risk = "sedang"
        else:
            stockout_risk = "rendah"

    # --- 12. Safety Stock & Reorder Point --------------------------------
    if n >= 2:
        std = as_float(series.std(ddof=1))
        if std is None or std < 0:
            std = 0.0
    else:
        std = 0.0
    safety_stock = max(0.0, float(params["safety_z"]) * std * math.sqrt(lead_time_eff))
    reorder_point = (daily_rate or 0.0) * lead_time_eff + safety_stock

    # --- 13. Rekomendasi jumlah reorder ----------------------------------
    if predicted_demand is None:
        recommended_qty = None
    else:
        target = predicted_demand + safety_stock + min_stock
        recommended_qty = float(math.ceil(max(0.0, target - stock)))

    emit(
        dict(
            common,
            warnings=warnings[:20],
            methods=methods,
            evaluation=evaluation,
            comparison=comparison,
            predicted_demand=predicted_demand,
            daily_rate=daily_rate,
            days_to_min=days_to_min,
            days_to_zero=days_to_zero,
            stockout_risk=stockout_risk,
            safety_stock=rnd(safety_stock, 2),
            reorder_point=rnd(reorder_point, 2),
            lead_time_days=int(lead_time_eff),
            lead_time_assumed=lead_time_assumed,
            recommended_qty=rnd(recommended_qty, 2),
        )
    )


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:  # noqa: BLE001 — jatuh ke JSON error, bukan traceback ke PHP
        fail("galat tak terduga: " + type(exc).__name__ + ": " + str(exc))
