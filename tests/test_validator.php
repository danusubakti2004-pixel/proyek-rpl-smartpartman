<?php
declare(strict_types=1);

use App\Core\Validator;

/**
 * Uji validasi backend (PRD §10.1 — validasi backend wajib, frontend pelengkap).
 */
function test_validator_required(): void
{
    $v = Validator::make(['username' => ''], ['username' => 'required'], ['username' => 'Username']);
    t_true($v->fails(), 'Field kosong wajib diisi → gagal');
    t_contains('Username wajib diisi', (string) $v->firstError(), 'Pesan "wajib diisi" berbahasa Indonesia');

    $v2 = Validator::make(['username' => 'admin'], ['username' => 'required']);
    t_true($v2->passes(), 'Field terisi → lulus');
}

function test_validator_email_dan_panjang(): void
{
    $v = Validator::make(
        ['email' => 'bukan-email'],
        ['email' => 'email'],
        ['email' => 'Email']
    );
    t_true($v->fails(), 'Email tidak valid → gagal');
    t_contains('berupa alamat email', (string) $v->firstError(), 'Pesan email tidak valid jelas');

    $v2 = Validator::make(['password' => 'abc'], ['password' => 'required|min:8'], ['password' => 'Password']);
    t_true($v2->fails(), 'Password < 8 karakter → gagal');
    t_contains('minimal 8 karakter', (string) $v2->firstError(), 'Pesan min karakter benar');

    $v3 = Validator::make(['password' => 'rahasia123'], ['password' => 'required|min:8']);
    t_true($v3->passes(), 'Password ≥ 8 karakter → lulus');
}

function test_validator_numeric_min(): void
{
    // Konteks numerik (ada aturan numeric) → min = perbandingan angka.
    $v = Validator::make(['qty' => '5'], ['qty' => 'required|numeric|min:10'], ['qty' => 'Jumlah']);
    t_true($v->fails(), 'Qty 5 < min 10 (angka) → gagal');
    t_contains('minimal 10', (string) $v->firstError(), 'Pesan min numerik benar');

    $v2 = Validator::make(['qty' => '10'], ['qty' => 'required|numeric|min:10']);
    t_true($v2->passes(), 'Qty 10 = min 10 → lulus');
}

function test_validator_confirmed_dan_in(): void
{
    $v = Validator::make(
        ['password' => 'rahasia123', 'password_confirmation' => 'rahasia12X'],
        ['password' => 'required|confirmed'],
        ['password' => 'Password']
    );
    t_true($v->fails(), 'Konfirmasi password tidak cocok → gagal');
    t_contains('tidak cocok', (string) $v->firstError(), 'Pesan konfirmasi benar');

    $v2 = Validator::make(
        ['status' => 'ngawur'],
        ['status' => 'required|in:aktif,nonaktif'],
        ['status' => 'Status']
    );
    t_true($v2->fails(), 'Nilai di luar daftar in: → gagal');

    $v3 = Validator::make(['status' => 'aktif'], ['status' => 'required|in:aktif,nonaktif']);
    t_true($v3->passes(), 'Nilai dalam daftar in: → lulus');
}

function test_validator_hanya_mengembalikan_field_lulus(): void
{
    $v = Validator::make(
        ['nama' => '  Sparepart Filter  ', 'email' => 'salah'],
        ['nama' => 'required|min:3', 'email' => 'email'],
        ['nama' => 'Nama', 'email' => 'Email']
    );
    t_true($v->fails(), 'Ada field gagal → gagal total');
    $validated = $v->validated();
    t_true(isset($validated['nama']), 'Field yang lulus tetap ada di validated()');
    t_eq('Sparepart Filter', $validated['nama'], 'Nilai string di-trim');
    t_true(!isset($validated['email']), 'Field yang GAGAL tidak masuk validated()');
}
