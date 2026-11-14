<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/probe.php';

/* ---------------------------------------------------------------------------
 *  Incident history
 * ------------------------------------------------------------------------- */

function incidents_path(): string
{
    return data_dir() . '/incidents.json';
}

function push_incident(array $event): void
{
    $max = (int)(cfg('history')['max_events'] ?? 60);
    $store = read_json(incidents_path(), ['events' => []]);
    $events = (array)($store['events'] ?? []);
    $event['at'] = (int)($event['at'] ?? time());
    $events[] = $event;
    if (count($events) > $max) {
        $events = array_slice($events, -$max);
    }
    write_json(incidents_path(), ['events' => $events]);
}

function load_incidents(int $limit = 50): array
{
    $store = read_json(incidents_path(), ['events' => []]);
    $events = (array)($store['events'] ?? []);
    return array_reverse(array_slice($events, -$limit));
}

function snapshot_target(array $rec): array
{
    $units = [];
    foreach ((array)($rec['units'] ?? []) as $u => $st) {
        $units[$u] = (string)$st;
    }
    $sites = [];
    foreach ((array)($rec['sites'] ?? []) as $s) {
        $sites[(string)$s['id']] = (int)($s['status'] ?? 0);
    }
    return [
        'reachable' => (bool)($rec['reachable'] ?? false),
        'units'     => $units,
        'sites'     => $sites,
    ];
}

function mark_flapping(array &$events, array $ev): bool
{
    $win = (int)(cfg('history')['flap_window'] ?? 900);
    $need = (int)(cfg('history')['flap_count'] ?? 3);
    $kinds = ['target_down', 'target_up', 'service_down', 'service_recovered', 'site_down', 'site_recovered'];
    $n = 0;
    foreach ($events as $e) {
        if (!in_array($e['type'] ?? '', $kinds, true)) {
            continue;
        }
        if (($e['target'] ?? '') === $ev['target'] && ($e['subject'] ?? '') === $ev['subject']
            && ($ev['at'] - (int)$e['at']) <= $win) {
            $n++;
        }
    }
    return $n >= $need;
}

/** Diff the freshly collected state against the previous snapshot. */
function update_incidents(array $state): void
{
    $prevFile = data_dir() . '/prev.json';
    $prev = read_json($prevFile, []);
    $store = read_json(incidents_path(), ['events' => []]);
    $events = (array)($store['events'] ?? []);
    $new = [];
    $now = time();

    $add = static function (string $type, string $target, string $subject, string $detail) use (&$new, $now) {
        $ev = ['at' => $now, 'type' => $type, 'target' => $target, 'subject' => $subject, 'detail' => $detail];
        $ev['flap'] = mark_flapping($new, $ev);
        $new[] = $ev;
    };

    foreach ((array)($state['targets'] ?? []) as $id => $rec) {
        $cur = snapshot_target((array)$rec);
        $old = $prev[$id] ?? null;

        if (is_array($old)) {
            if ($old['reachable'] && !$cur['reachable']) {
                $add('target_down', (string)$id, 'reachability', 'no response: ' . (string)($rec['error'] ?? 'unknown'));
            } elseif (!$old['reachable'] && $cur['reachable']) {
                $add('target_up', (string)$id, 'reachability', 'probe succeeded again');
            }

            // service / site transitions are only meaningful when we can see both sides
            if ($old['reachable'] && $cur['reachable']) {
                foreach ($cur['units'] as $u => $st) {
                    $was = $old['units'][$u] ?? null;
                    if ($was === null || $was === $st) {
                        continue;
                    }
                    $isOk = str_starts_with($st, 'active');
                    $wasOk = str_starts_with((string)$was, 'active');
                    if ($wasOk && !$isOk) {
                        $add('service_down', (string)$id, $u, $was . ' -> ' . $st);
                    } elseif (!$wasOk && $isOk) {
                        $add('service_recovered', (string)$id, $u, $was . ' -> ' . $st);
                    }
                }
                foreach ($cur['sites'] as $host => $code) {
                    $was = $old['sites'][$host] ?? null;
                    if ($was === null || $was === $code) {
                        continue;
                    }
                    $isOk = ($code >= 200 && $code < 400);
                    $wasOk = ((int)$was >= 200 && (int)$was < 400);
                    if ($wasOk && !$isOk) {
                        $add('site_down', (string)$id, $host, 'HTTP ' . ($was > 0 ? $was : 'no response') . ' -> ' . ($code > 0 ? $code : 'no response'));
                    } elseif (!$wasOk && $isOk) {
                        $add('site_recovered', (string)$id, $host, 'HTTP ' . ($was > 0 ? $was : 'no response') . ' -> ' . $code);
                    }
                }
            }
        }
        $prev[$id] = $cur;
    }

    write_json($prevFile, $prev);

    if (!$new) {
        return;
    }
    foreach ($new as $ev) {
        if ($ev['flap']) {
            $events[] = [
                'at' => $ev['at'], 'type' => 'flapping', 'target' => $ev['target'],
                'subject' => $ev['subject'], 'detail' => 'flapping: ' . $ev['detail'], 'flap' => true,
            ];
        }
    }
    $events = array_merge($events, $new);
    $max = (int)(cfg('history')['max_events'] ?? 60);
    if (count($events) > $max) {
        $events = array_slice($events, -$max);
    }
    write_json(incidents_path(), ['events' => $events]);
}

/* ---------------------------------------------------------------------------
 *  Collection
 * ------------------------------------------------------------------------- */

function php_cli(): string
{
    if (PHP_SAPI === 'cli') {
        return PHP_BINARY;
    }
    foreach (['/usr/bin/php8.3', '/usr/bin/php'] as $c) {
        if (is_executable($c)) {
            return $c;
        }
    }
    return 'php';
}

function state_read(): array
{
    $s = read_json(data_dir() . '/state.json', ['targets' => [], 'updated_at' => 0]);
    if (!isset($s['targets']) || !is_array($s['targets'])) {
        $s['targets'] = [];
    }
    return $s;
}

/**
 * Refresh every target that is past its own minimum interval, in parallel,
 * within a wall-clock budget. Targets that do not answer keep their last known
 * state (rendered as "stale") instead of blocking the page.
 */
function collect_state(array $only = [], bool $force = false, ?int $budget = null): array
{
    $c = cfg();
    $budget = $budget ?? (int)$c['budget']['cli'];
    $state = state_read();

    $due = [];
    foreach ($c['targets'] as $id => $t) {
        if ($only && !in_array($id, $only, true)) {
            continue;
        }
        $age = time() - (int)($state['targets'][$id]['checked_at'] ?? 0);
        if ($force || !isset($state['targets'][$id]) || $age >= (int)$t['interval']) {
            $due[] = $id;
        }
    }
    if (!$due) {
        $state['collector'] = ['ran' => false, 'reason' => 'all targets fresh'];
        return $state;
    }

    $lockFile = data_dir() . '/collect.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock === false) {
        $state['collector'] = ['ran' => false, 'reason' => 'lock file not writable'];
        return $state;
    }
    @chmod($lockFile, 0664);
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $state['collector'] = ['ran' => false, 'reason' => 'another collector is running'];
        return $state;
    }

    $cli = php_cli();
    $workers = [];
    foreach ($due as $id) {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open([$cli, $c['base_dir'] . '/bin/worker.php', $id], $desc, $pipes, null, null);
        if (!is_resource($p)) {
            continue;
        }
        @fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $workers[$id] = ['p' => $p, 'out' => $pipes[1], 'err' => $pipes[2], 'stderr' => ''];
    }

    $killed = [];
    if ($workers) {
        $deadline = microtime(true) + max(1, $budget);
        while ($workers) {
            foreach ($workers as $id => $w) {
                @stream_get_contents($w['out']);
                $e = @stream_get_contents($w['err']);
                if ($e !== false && $e !== '') {
                    $workers[$id]['stderr'] .= $e;
                }
                $st = proc_get_status($w['p']);
                if ($st['running']) {
                    continue;
                }
                @fclose($w['out']);
                @fclose($w['err']);
                proc_close($w['p']);
                if ((int)$st['exitcode'] !== 0) {
                    $killed[] = $id;
                }
                unset($workers[$id]);
            }
            if (!$workers) {
                break;
            }
            if (microtime(true) >= $deadline) {
                foreach ($workers as $id => $w) {
                    proc_terminate($w['p'], 9);
                    $killed[] = $id;
                }
                usleep(150000);
                foreach ($workers as $w) {
                    @fclose($w['out']);
                    @fclose($w['err']);
                    proc_close($w['p']);
                }
                $workers = [];
                break;
            }
            usleep(70000);
        }
    }

    $merged = [];
    foreach ($due as $id) {
        $rec = read_json(data_dir() . '/targets/' . $id . '.json');
        if (is_array($rec) && isset($rec['checked_at'])) {
            $state['targets'][$id] = $rec;
            $merged[] = $id;
        }
    }
    $state['updated_at'] = time();
    $state['collector'] = ['ran' => true, 'refreshed' => $merged, 'timed_out' => array_values(array_unique($killed))];
    write_json(data_dir() . '/state.json', $state);
    update_incidents($state);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $state;
}
