<?php
declare(strict_types=1);

/**
 * Runner pengujian SmartPartMan (CLI).
 *
 * Pemakaian:
 *   php tests\run.php                → jalankan semua pengujian
 *   php tests\run.php validator      → jalankan test yang namanya mengandung kata tsb
 *
 * Keluar dengan kode 0 = lulus, 1 = gagal.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';

// Kumpulkan daftar fungsi test SEBELUM file test di-require.
$before = get_defined_functions()['user'];

$files = glob(__DIR__ . '/test_*.php');
sort($files);
foreach ($files as $file) {
    require_once $file;
}

$after = get_defined_functions()['user'];
$testFunctions = array_values(array_filter(
    array_diff($after, $before),
    static fn (string $fn): bool => str_starts_with($fn, 'test_')
));

if ($filter !== '') {
    $testFunctions = array_values(array_filter(
        $testFunctions,
        static fn (string $fn): bool => str_contains($fn, $filter)
    ));
}

if ($testFunctions === []) {
    fwrite(STDERR, "Tidak ada pengujian yang cocok.\n");
    exit(1);
}

echo "SmartPartMan — Runner Pengujian\n";
echo 'Database uji: ' . \App\Core\Env::get('DB_TEST_NAME', 'smartpartman_test') . "\n";
echo str_repeat('-', 60) . "\n";

foreach ($testFunctions as $fn) {
    echo "\n> {$fn}\n";
    try {
        $fn();
    } catch (\Throwable $e) {
        $GLOBALS['t_fail']++;
        $GLOBALS['t_failures'][] = $fn . ' melempar ' . get_class($e) . ': ' . $e->getMessage();
        echo "    [GAGAL] exception tak tertangani: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        echo '    ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "HASIL: {$GLOBALS['t_pass']} lulus, {$GLOBALS['t_fail']} gagal\n";

if ($GLOBALS['t_fail'] > 0) {
    echo "\nRincian kegagalan:\n";
    foreach ($GLOBALS['t_failures'] as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "Semua pengujian LULUS.\n";
exit(0);
