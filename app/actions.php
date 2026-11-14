<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/*
 * ============================================================================
 *  RECOVERY ACTIONS
 * ============================================================================
 *  The client sends (target, action) IDs only. Both are looked up in the map
 *  below, which is the ONLY source of command text in this application.
 *  Nothing the client sends is ever concatenated into a command, a path or a
 *  unit name. There is deliberately no generic "run a command" endpoint.
 * ============================================================================
 */

function action_map(): array
{
    return [
        'vps-a' => [
            'start_docker'     => ['label' => 'Start docker',     'service' => 'docker',       'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'docker']],
            'start_containerd' => ['label' => 'Start containerd', 'service' => 'containerd',   'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'containerd']],
            'start_wings'      => ['label' => 'Start wings',      'service' => 'wings',        'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'wings']],
            'start_pteroq'     => ['label' => 'Start pteroq',     'service' => 'pteroq',       'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'pteroq']],
            'reload_nginx'     => ['label' => 'Reload nginx',     'service' => 'nginx',        'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'reload', 'nginx']],
            'start_mariadb'    => ['label' => 'Start mariadb',    'service' => 'mariadb',      'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'mariadb']],
            'start_redis'      => ['label' => 'Start redis-server', 'service' => 'redis-server', 'mode' => 'local_sudo', 'argv' => ['/usr/bin/systemctl', 'start', 'redis-server']],
            'pm2_resurrect'    => ['label' => 'pm2 resurrect',    'service' => null,           'mode' => 'local_sudo', 'argv' => ['/usr/local/bin/pm2', 'resurrect'], 'pm2' => true],
        ],
        'vps-b' => [
            'start_docker'     => ['label' => 'Start docker',     'service' => 'docker',       'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start docker'],
            'start_containerd' => ['label' => 'Start containerd', 'service' => 'containerd',   'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start containerd'],
            'start_wings'      => ['label' => 'Start wings',      'service' => 'wings',        'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start wings'],
            'start_pteroq'     => ['label' => 'Start pteroq',     'service' => 'pteroq',       'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start pteroq'],
            'reload_nginx'     => ['label' => 'Reload nginx',     'service' => 'nginx',        'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl reload nginx'],
            'start_mariadb'    => ['label' => 'Start mariadb',    'service' => 'mariadb',      'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start mariadb'],
            'start_redis'      => ['label' => 'Start redis-server', 'service' => 'redis-server', 'mode' => 'ssh_root', 'cmd' => '/usr/bin/systemctl start redis-server'],
            'pm2_resurrect'    => ['label' => 'pm2 resurrect',    'service' => null,           'mode' => 'ssh_root', 'cmd' => '/usr/bin/pm2 resurrect', 'pm2' => true],
        ],
        'cpanel-a' => [
            'recheck' => ['label' => 'Bring back online (re-check only)', 'service' => null, 'mode' => 'recheck'],
        ],
        'cpanel-b' => [
            'recheck' => ['label' => 'Bring back online (re-check only)', 'service' => null, 'mode' => 'recheck'],
        ],
    ];
}

/** Defensive assertion: no command text in the map may carry shell metacharacters. */
function action_map_is_safe(): bool
{
    foreach (action_map() as $actions) {
        foreach ($actions as $a) {
            if (isset($a['argv'])) {
                foreach ($a['argv'] as $part) {
                    if (preg_match('~^[A-Za-z0-9/._\-]+$~', (string)$part) !== 1) {
                        return false;
                    }
                }
                // only ever systemctl / pm2, only ever an allowlisted verb
                $bin = $a['argv'][0] ?? '';
                if (!in_array($bin, ['/usr/bin/systemctl', '/usr/local/bin/pm2'], true)) {
                    return false;
                }
                if (basename($bin) === 'systemctl') {
                    if (!in_array($a['argv'][1] ?? '', ['start', 'reload'], true)) {
                        return false;
                    }
                    if (!in_array($a['argv'][2] ?? '', ['docker', 'containerd', 'wings', 'pteroq', 'nginx', 'mariadb', 'redis-server'], true)) {
                        return false;
                    }
                }
                if (basename($bin) === 'pm2' && ($a['argv'][1] ?? '') !== 'resurrect') {
                    return false;
                }
            }
            if (isset($a['cmd'])) {
                if (preg_match('~^[A-Za-z0-9/._\- ]+$~', (string)$a['cmd']) !== 1) {
                    return false;
                }
            }
            if (!in_array($a['mode'] ?? '', ['local_sudo', 'ssh_root', 'recheck'], true)) {
                return false;
            }
        }
    }
    return true;
}

function action_lookup(string $target, string $action): ?array
{
    $m = action_map();
    return $m[$target][$action] ?? null;
}

/**
 * Is this action usable right now, given what discovery actually found?
 * Used both to decide which buttons to render and to reject a stale POST.
 */
function action_available(string $target, string $action, array $state): bool
{
    $a = action_lookup($target, $action);
    if ($a === null) {
        return false;
    }
    if (($a['mode'] ?? '') === 'recheck') {
        return true;
    }
    $rec = $state['targets'][$target] ?? null;
    if (!is_array($rec)) {
        return false;
    }
    if (!empty($a['pm2'])) {
        return !empty($rec['metrics']['pm2_present']);
    }
    $svc = $a['service'] ?? '';
    if ($svc === '' || !is_array($rec['units'] ?? null)) {
        return false;
    }
    $st = $rec['units'][$svc] ?? 'not-found';
    return $st !== 'not-found';
}

function action_rate_limited(string $target): bool
{
    $win = (int)(cfg('actions')['rate_limit_seconds'] ?? 5);
    $f = data_dir() . '/ratelimit/' . preg_replace('/[^a-z0-9\-]/i', '_', $target) . '.ts';
    $last = is_file($f) ? (int)@file_get_contents($f) : 0;
    if ($last > 0 && (time() - $last) < $win) {
        return true;
    }
    @file_put_contents($f, (string)time(), LOCK_EX);
    return false;
}

/**
 * Execute one allowlisted action. Returns [ok(bool), detail(string), ms(int)].
 * The command text is a literal from action_map() — nothing else.
 */
function run_action(string $target, string $action): array
{
    $t = target_conf($target);
    $a = action_lookup($target, $action);
    if ($t === null || $a === null) {
        return [false, 'unknown action', 0];
    }

    $mode = (string)$a['mode'];
    if ($mode === 'recheck') {
        return [true, 're-check only (no root on shared host)', 0];
    }

    $started = microtime(true);
    if ($mode === 'local_sudo') {
        $argv = array_merge(['/usr/bin/sudo', '-n'], (array)$a['argv']);
        [$code, $out, $err] = proc_run($argv, '', 30);
    } else {
        [$code, $out, $err] = proc_run(ssh_argv($t), (string)$a['cmd'] . "\n", 30);
    }
    $ms = (int)round((microtime(true) - $started) * 1000);

    $detail = trim((string)$out) !== '' ? trim((string)$out) : trim((string)$err);
    $detail = sanitize_field(preg_replace('/\s+/', ' ', $detail) ?? '', 200);
    if ($code !== 0) {
        return [false, 'exit ' . $code . ': ' . $detail, $ms];
    }
    return [true, 'exit 0', $ms];
}
