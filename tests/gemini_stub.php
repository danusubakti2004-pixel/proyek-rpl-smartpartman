<?php
declare(strict_types=1);

/**
 * Helper menjalankan stub Gemini (php -S) untuk pengujian.
 *
 * Bukan file test (tidak diawali test_) sehingga tidak dikumpulkan runner.
 * Stub hanya mendengarkan 127.0.0.1 dan berhenti setelah test selesai.
 *
 * @return array{proc:resource,port:int,base:string,log:string,server_log:string}
 */
function t_gemini_stub_start(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('Tidak dapat mencari port kosong: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    if ($port <= 0) {
        throw new RuntimeException('Port stub tidak valid.');
    }

    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spm_gemini_stub_' . $port . '.log';
    $serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spm_gemini_stub_server_' . $port . '.log';
    @unlink($log);
    @unlink($serverLog);

    $router = __DIR__ . DIRECTORY_SEPARATOR . 'gemini_stub_router.php';
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $pipes = [];
    $proc = proc_open($cmd, $descriptors, $pipes, __DIR__);
    if (!is_resource($proc)) {
        throw new RuntimeException('Gagal menjalankan stub Gemini.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $ready = false;
    for ($i = 0; $i < 60; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        t_gemini_stub_stop(['proc' => $proc, 'port' => $port, 'log' => $log, 'server_log' => $serverLog]);
        throw new RuntimeException('Stub Gemini tidak siap pada port ' . $port . '.');
    }

    return [
        'proc'       => $proc,
        'port'       => $port,
        'base'       => 'http://127.0.0.1:' . $port . '/v1beta',
        'log'        => $log,
        'server_log' => $serverLog,
    ];
}

/** @param array<string,mixed> $stub */
function t_gemini_stub_stop(array $stub): void
{
    $proc = $stub['proc'] ?? null;
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
}

/** @param array<string,mixed> $stub @return array<int,array<string,mixed>> */
function t_gemini_stub_requests(array $stub): array
{
    $log = (string) ($stub['log'] ?? '');
    if (!is_file($log)) {
        return [];
    }
    $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }
    return $out;
}

/** @param array<string,mixed> $stub */
function t_gemini_stub_clear(array $stub): void
{
    @unlink((string) ($stub['log'] ?? ''));
}

/** @param array<string,mixed> $stub */
function t_gemini_stub_cleanup(array $stub): void
{
    t_gemini_stub_stop($stub);
    @unlink((string) ($stub['log'] ?? ''));
    @unlink((string) ($stub['server_log'] ?? ''));
}
