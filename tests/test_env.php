<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Uji loader .env (parse, default, tipe boolean).
 * File sementara ditulis ke direktori sementara; kunci memakai awalan TEST_
 * agar tidak menimpa konfigurasi asli.
 */
function test_env_loader_memuat_file(): void
{
    $tmp = sys_get_temp_dir() . '/spm_env_test_' . uniqid() . '.env';
    file_put_contents($tmp, implode("\n", [
        '# baris komentar harus diabaikan',
        'TEST_SPM_NAME="SmartPartMan"',
        "TEST_SPM_QUOTED='nilai kutip'",
        'TEST_SPM_PLAIN=123',
        'TEST_SPM_EMPTY=',
        'BARIS_TANPA_EQUALS',
        '',
    ]));

    Env::load($tmp);
    unlink($tmp);

    t_eq('SmartPartMan', Env::get('TEST_SPM_NAME'), 'Kutip ganda dibuka dan nilai termuat');
    t_eq('nilai kutip', Env::get('TEST_SPM_QUOTED'), 'Kutip tunggal dibuka');
    t_eq('123', Env::get('TEST_SPM_PLAIN'), 'Nilai polos termuat');
    t_eq('', Env::get('TEST_SPM_EMPTY'), 'Nilai kosong tetap ada (bukan default)');
    t_eq('bawaan', Env::get('TEST_SPM_TIDAK_ADA', 'bawaan'), 'Default dikembalikan bila kunci tidak ada');
}

function test_env_boolean_sejati(): void
{
    $tmp = sys_get_temp_dir() . '/spm_env_bool_' . uniqid() . '.env';
    file_put_contents($tmp, "TEST_SPM_ON=true\nTEST_SPM_OFF=false\nTEST_SPM_ZERO=0\n");
    Env::load($tmp);
    unlink($tmp);

    t_eq(true, Env::bool('TEST_SPM_ON'), 'true → bool true');
    t_eq(false, Env::bool('TEST_SPM_OFF', true), 'false → bool false');
    t_eq(false, Env::bool('TEST_SPM_ZERO', true), '0 → bool false');
    t_eq(true, Env::bool('TEST_SPM_TIDAK_ADA', true), 'Tanpa kunci → default');
}
