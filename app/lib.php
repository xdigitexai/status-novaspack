<?php
declare(strict_types=1);

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
        date_default_timezone_set($c['timezone'] ?? 'UTC');
    }
    if ($key === null) {
        return $c;
    }
    return $c[$key] ?? null;
}

function target_ids(): array
{
    return array_keys(cfg('targets'));
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('novaops');
    session_set_cookie_params([
        'httponly' => true, 'samesite' => 'Lax', 'secure' => true, 'path' => '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function target_conf(string $id): ?array
{
    $t = cfg('targets');
    return $t[$id] ?? null;
}

function data_dir(): string
{
    static $done = false;
    $d = (string)cfg('data_dir');
    if (!$done) {
        foreach (['', '/targets', '/ratelimit'] as $sub) {
            if (!is_dir($d . $sub)) {
                @mkdir($d . $sub, 0775, true);
            }
        }
        $done = true;
    }
    return $d;
}

function read_json(string $path, $fallback = null)
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $fallback;
}

function atomic_write(string $path, string $contents): bool
{
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return atomic_write($path, (string)$json);
}

/**
 * Make a value safe to place on a single log line. Control characters and
 * newlines are stripped (so a hostile target string cannot forge a second log
 * entry) and '|' is neutralised (so it cannot forge a field).
 */
function sanitize_field($v, int $max = 80): string
{
    $v = (string)$v;
    $v = (string)preg_replace('/[^\x20-\x7E]/', '', $v);
    $v = str_replace('|', '/', $v);
    $v = trim($v);
    if (strlen($v) > $max) {
        $v = substr($v, 0, $max);
    }
    return $v === '' ? '-' : $v;
}

function log_action(string $target, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] target=' . sanitize_field($target) . ' | ' . $message . "\n";
    $f = data_dir() . '/actions.log';
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    if ((int)@filesize($f) > 512000) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        @file_put_contents($f, implode("\n", array_slice($lines, -1500)) . "\n", LOCK_EX);
    }
}

function tail_lines(string $path, int $n): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    return array_slice($lines, -$n);
}

function human_age(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = time() - $ts;
    if ($d < 0) {
        return 'now';
    }
    if ($d < 2) {
        return 'just now';
    }
    if ($d < 60) {
        return $d . 's ago';
    }
    if ($d < 3600) {
        return floor($d / 60) . 'm ' . ($d % 60) . 's ago';
    }
    if ($d < 86400) {
        return floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm ago';
    }
    return floor($d / 86400) . 'd ago';
}

function fmt_kb($kb): string
{
    $kb = (float)$kb;
    if ($kb <= 0) {
        return '0';
    }
    $units = ['K', 'M', 'G', 'T'];
    $i = 0;
    while ($kb >= 1024 && $i < count($units) - 1) {
        $kb /= 1024;
        $i++;
    }
    return round($kb, $kb < 10 ? 1 : 0) . $units[$i];
}

function fmt_pct($v): string
{
    return is_numeric($v) ? round((float)$v, 1) . '%' : 'n/a';
}

/** Run an argv array with no shell involved. Returns [exitcode, stdout, stderr]. */
function proc_run(array $argv, string $stdin = '', int $timeout = 15): array
{
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($argv, $desc, $pipes, null, null);
    if (!is_resource($p)) {
        return [-1, '', 'proc_open failed'];
    }
    if ($stdin !== '') {
        @fwrite($pipes[0], $stdin);
    }
    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $code = -1;
    $deadline = microtime(true) + max(1, $timeout);
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            $code = (int)$st['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($p, 9);
            usleep(150000);
            $code = -2;
            break;
        }
        usleep(40000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    proc_close($p);
    return [$code, $out, $err];
}

/**
 * argv for a remote probe. Fixed options only, key auth only, hard timeouts so
 * a dead host can never hang a page render.
 */
function ssh_argv(array $t, string $remoteCommand = 'bash -s'): array
{
    return [
        (string)cfg('ssh_bin'),
        '-i', (string)cfg('ssh_key'),
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=' . (string)cfg('known_hosts'),
        '-o', 'PreferredAuthentications=publickey',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'ConnectTimeout=5',
        '-o', 'ServerAliveInterval=3',
        '-o', 'ServerAliveCountMax=2',
        '-o', 'LogLevel=ERROR',
        '-T',
        '-p', (string)$t['port'],
        $t['user'] . '@' . $t['addr'],
        $remoteCommand,
    ];
}
