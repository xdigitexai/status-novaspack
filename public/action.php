<?php
declare(strict_types=1);

/*
 * The only write endpoint. Accepts POST + CSRF + a (target, action) pair.
 * Both IDs are looked up in action_map(); the command text always comes from
 * there, never from the request. No endpoint here accepts a command, a path or
 * a unit name.
 */

require_once __DIR__ . '/../app/lib.php';
require_once __DIR__ . '/../app/collect.php';
require_once __DIR__ . '/../app/actions.php';

session_boot();
$c = cfg();

$redirect = static function (array $flash): void {
    $_SESSION['flash'] = $flash;
    header('Location: /', true, 303);
    exit;
};

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$rawTarget = (string)($_POST['target'] ?? '');
$op = (string)($_POST['op'] ?? '');
$action = (string)($_POST['action'] ?? '');
// For the log we use the value exactly as sent (sanitised), which is what the
// old page did — so a hostile target string is visible in the log as rejected.
$logTarget = $rawTarget !== '' ? $rawTarget : '-';

if ($method !== 'POST') {
    log_action($logTarget, 'request rejected | POST required');
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo "POST required\n";
    exit;
}

$token = (string)($_POST['csrf'] ?? '');
if (!hash_equals(csrf_token(), $token)) {
    log_action($logTarget, 'request rejected | Invalid CSRF token');
    $redirect(['type' => 'err', 'text' => 'Rejected: invalid CSRF token. The attempt was logged.']);
}

function summarise_target(array $state, string $id): string
{
    $rec = $state['targets'][$id] ?? null;
    if (!is_array($rec)) {
        return 'no data';
    }
    if (empty($rec['reachable'])) {
        return 'unreachable: ' . (string)($rec['error'] ?? 'unknown');
    }
    $m = (array)($rec['metrics'] ?? []);
    $sites = count((array)($rec['sites'] ?? []));
    $down = 0;
    foreach ((array)($rec['sites'] ?? []) as $s) {
        if (empty($s['up'])) {
            $down++;
        }
    }
    return 'UP;LOAD=' . fmt_pct($m['load_pct'] ?? null)
        . ';MEM=' . fmt_pct($m['mem_pct'] ?? null)
        . ';DISK=' . fmt_pct($m['disk_pct'] ?? null)
        . ';SITES=' . $sites . ';SITES_DOWN=' . $down;
}

switch ($op) {
    case 'recheck_all':
        $state = collect_state([], true, (int)$c['budget']['all']);
        $bits = [];
        foreach (target_ids() as $id) {
            $bits[] = $id . '(' . summarise_target($state, $id) . ')';
        }
        log_action('all', 're-check all | ' . implode(' ', $bits) . ';END=1');
        $redirect(['type' => 'ok', 'text' => 'Re-checked all targets — fresh readings below.']);

        // no break (redirect exits)
    case 'recheck':
        if (!in_array($rawTarget, target_ids(), true)) {
            log_action($logTarget, 'request rejected | Unknown target');
            $redirect(['type' => 'err', 'text' => 'Rejected: unknown target. The attempt was logged.']);
        }
        $state = collect_state([$rawTarget], true, (int)$c['budget']['one']);
        $rec = $state['targets'][$rawTarget] ?? [];
        $m = (array)($rec['metrics'] ?? []);
        if (empty($rec['reachable'])) {
            log_action($rawTarget, 're-check | unreachable: ' . (string)($rec['error'] ?? 'unknown') . ';END=0');
            $redirect(['type' => 'err', 'text' => $rawTarget . ' did not answer — showing its last known state.']);
        }
        if (($rec['type'] ?? '') === 'cpanel') {
            // exactly the spirit of the old page
            log_action($rawTarget, 're-check only (no root on shared host) | ssh reachable; UPTIME='
                . (string)($m['uptime'] ?? 'n/a') . ';LOAD=' . (string)($m['load1'] ?? '?') . ' '
                . (string)($m['load5'] ?? '?') . ' ' . (string)($m['load15'] ?? '?')
                . ';DISK=' . fmt_pct($m['disk_pct'] ?? null) . ';SITES=' . count((array)($rec['sites'] ?? [])) . ';END=1');
        } else {
            log_action($rawTarget, 're-check | ssh/local reachable;LOAD=' . (string)($m['load1'] ?? '?')
                . ';LOADPCT=' . fmt_pct($m['load_pct'] ?? null)
                . ';MEM=' . fmt_pct($m['mem_pct'] ?? null)
                . ';DISK=' . fmt_pct($m['disk_pct'] ?? null) . ';END=1');
        }
        $redirect(['type' => 'ok', 'text' => 'Re-checked ' . $rawTarget . ' — timestamps updated.']);

    case 'action':
        if (!in_array($rawTarget, target_ids(), true)) {
            log_action($logTarget, 'request rejected | Unknown target');
            $redirect(['type' => 'err', 'text' => 'Rejected: unknown target. The attempt was logged.']);
        }
        $spec = action_lookup($rawTarget, $action);
        if ($spec === null) {
            log_action($rawTarget, 'request rejected | Unknown action');
            $redirect(['type' => 'err', 'text' => 'Rejected: unknown action. The attempt was logged.']);
        }

        $before = state_read();
        if (!action_available($rawTarget, $action, $before)) {
            log_action($rawTarget, 'request rejected | Action "' . sanitize_field($action, 40) . '" not available on target');
            $redirect(['type' => 'err', 'text' => 'Rejected: that service is not installed on ' . $rawTarget . ' (logged).']);
        }

        $win = (int)($c['actions']['rate_limit_seconds'] ?? 5);
        if (action_rate_limited($rawTarget)) {
            log_action($rawTarget, 'request rejected | Rate limited (one action per target per ' . $win . 's)');
            $redirect(['type' => 'err', 'text' => 'Rate limited: one action per target every ' . $win . 's.']);
        }

        [$ok, $detail, $ms] = run_action($rawTarget, $action);

        if (($spec['mode'] ?? '') === 'recheck') {
            $state = collect_state([$rawTarget], true, (int)$c['budget']['one']);
            $rec = $state['targets'][$rawTarget] ?? [];
            $m = (array)($rec['metrics'] ?? []);
            $okNow = !empty($rec['reachable']);
            log_action($rawTarget, 're-check only (no root on shared host) | ' . ($okNow ? 'UP' : 'FAIL')
                . ' | ssh ' . ($okNow ? 'reachable' : 'unreachable')
                . ';UPTIME=' . (string)($m['uptime'] ?? 'n/a')
                . ';LOAD=' . (string)($m['load1'] ?? '?') . ' ' . (string)($m['load5'] ?? '?') . ' ' . (string)($m['load15'] ?? '?')
                . ';DISK=' . fmt_pct($m['disk_pct'] ?? null)
                . ';SITES=' . count((array)($rec['sites'] ?? [])) . ';END=' . ($okNow ? '1' : '0'));
            push_incident([
                'type' => $okNow ? 'action' : 'action_failed', 'target' => $rawTarget, 'subject' => 're-check',
                'detail' => 're-check only (no root on shared host) -> ' . ($okNow ? 'UP' : 'FAIL'),
            ]);
            $redirect(['type' => $okNow ? 'ok' : 'err', 'text' => $rawTarget . ': re-check only (no root on shared host) — ' . ($okNow ? 'UP' : 'FAIL') . '.']);
        }

        // "wait briefly, then re-run this target's health check"
        usleep(1500000);
        $state = collect_state([$rawTarget], true, (int)$c['budget']['one']);
        $rec = $state['targets'][$rawTarget] ?? [];
        $m = (array)($rec['metrics'] ?? []);
        $unit = (string)($spec['service'] ?? '');
        $unitState = $unit !== '' ? (string)($rec['units'][$unit] ?? 'unknown') : 'n/a';

        log_action($rawTarget, (string)$spec['label'] . ' | ' . ($ok ? 'UP' : 'FAIL') . ' | ' . $detail
            . ' | unit=' . $unitState
            . ';LOAD=' . fmt_pct($m['load_pct'] ?? null)
            . ';MEM=' . fmt_pct($m['mem_pct'] ?? null)
            . ';DISK=' . fmt_pct($m['disk_pct'] ?? null) . ';END=' . ($ok ? '1' : '0'));

        push_incident([
            'type' => $ok ? 'action' : 'action_failed', 'target' => $rawTarget, 'subject' => (string)$spec['label'],
            'detail' => $detail . ' | now: ' . $unitState . ' (' . $ms . 'ms)',
        ]);

        $redirect([
            'type' => $ok ? 'ok' : 'err',
            'text' => $rawTarget . ': ' . $spec['label'] . ' -> ' . ($ok ? 'UP' : 'FAIL') . ' (' . $detail . '); fresh state now ' . $unitState . '.',
        ]);

    default:
        log_action($logTarget, 'request rejected | Unknown action');
        $redirect(['type' => 'err', 'text' => 'Rejected: unknown request. The attempt was logged.']);
}
