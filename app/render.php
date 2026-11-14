<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/collect.php';
require_once __DIR__ . '/actions.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pill(string $text, string $cls = ''): string
{
    return '<span class="pill ' . h($cls) . '">' . h($text) . '</span>';
}

function unit_state_class(string $st): string
{
    if ($st === 'not-found') {
        return 'grey';
    }
    if (str_starts_with($st, 'active')) {
        return 'ok';
    }
    if (str_starts_with($st, 'failed')) {
        return 'bad';
    }
    return 'warn';
}

function unit_state_label(string $st): string
{
    if ($st === 'not-found') {
        return 'unit not found';
    }
    $parts = explode('/', $st);
    return $parts[0] ?? $st;
}

function bar(?float $pct, string $label): string
{
    if ($pct === null || !is_finite($pct)) {
        return '<div class="tile"><span class="t-label">' . h($label) . '</span><span class="t-value">n/a</span></div>';
    }
    $cls = $pct >= 90 ? 'bad' : ($pct >= 75 ? 'warn' : 'ok');
    $w = max(0, min(100, $pct));
    return '<div class="tile"><span class="t-label">' . h($label) . '</span><span class="t-value">'
        . h(round($pct, 1) . '%') . '</span><span class="bar ' . $cls . '"><i style="width:' . h((string)$w) . '%"></i></span></div>';
}

function tile(string $label, string $value, string $sub = '', string $cls = ''): string
{
    return '<div class="tile' . ($cls ? ' ' . h($cls) : '') . '"><span class="t-label">' . h($label) . '</span>'
        . '<span class="t-value">' . h($value) . '</span>'
        . ($sub !== '' ? '<span class="t-sub">' . h($sub) . '</span>' : '') . '</div>';
}

function event_label(string $type): array
{
    return match ($type) {
        'target_down'       => ['Target unreachable', 'bad'],
        'target_up'         => ['Target recovered', 'ok'],
        'service_down'      => ['Service went down', 'bad'],
        'service_recovered' => ['Service recovered', 'ok'],
        'site_down'         => ['Site went non-200', 'bad'],
        'site_recovered'    => ['Site recovered', 'ok'],
        'action'            => ['Recovery action', 'ok'],
        'action_failed'     => ['Recovery action failed', 'bad'],
        'rejected'          => ['Request rejected', 'warn'],
        'flapping'          => ['Flapping detected', 'warn'],
        default             => [$type, 'grey'],
    };
}

function render_actions(string $id, array $state): string
{
    $t = target_conf($id);
    $out = '<div class="actions"><h4>Bring back online</h4><div class="btnrow">';

    foreach (action_map()[$id] ?? [] as $aid => $spec) {
        $ok = action_available($id, $aid, $state);
        $note = '';
        if (!$ok) {
            if (!empty($spec['pm2'])) {
                $note = 'pm2 not present';
            } elseif (!empty($spec['service'])) {
                $note = $spec['service'] . ' not present';
            } else {
                $note = 'unavailable';
            }
        }
        $out .= '<form method="post" action="/action.php" class="inline">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="op" value="action">'
            . '<input type="hidden" name="target" value="' . h($id) . '">'
            . '<input type="hidden" name="action" value="' . h($aid) . '">'
            . '<button class="btn ' . ($ok ? 'btn-primary' : 'btn-disabled') . '"'
            . ($ok ? '' : ' disabled title="' . h($note) . '"')
            . '>' . h($spec['label']) . ($ok ? '' : ' — ' . h($note)) . '</button></form>';
    }

    $out .= '</div>';
    if ($t['type'] === 'cpanel') {
        $out .= '<p class="hint">Shared host: there is no root, so the only recovery available is a re-check. '
            . 'This is logged as <code>re-check only (no root on shared host)</code>.</p>';
    } else {
        $out .= '<p class="hint">Each button runs exactly one fixed, allowlisted command on '
            . h($id) . '. Buttons for units this host does not have are disabled.</p>';
    }
    $out .= '</div>';
    return $out;
}

function render_sites(array $sites, string $type): string
{
    if (!$sites) {
        return '<p class="empty">No sites discovered on this target.</p>';
    }
    $out = '<div class="table-wrap"><table class="sites"><thead><tr>'
        . '<th>Site</th><th>HTTP</th><th>ms</th><th>TLS</th><th>Source / docroot</th><th>Error log</th>'
        . '</tr></thead><tbody>';
    foreach ($sites as $s) {
        $status = (int)($s['status'] ?? 0);
        $up = !empty($s['up']);
        $cls = $status === 0 ? 'bad' : ($up ? 'ok' : 'warn');
        $statusTxt = $status > 0 ? (string)$status : 'no response';
        if ($status === 0 && !empty($s['http_error'])) {
            $statusTxt = 'no response';
        }
        $tls = $s['cert_days'] ?? null;
        $tlsTxt = $tls === null ? '—' : ($tls . 'd');
        $tlsCls = ($tls !== null && $tls < 14) ? 'pill bad' : (($tls !== null && $tls < 30) ? 'pill warn' : 'pill grey');

        $tail = (array)($s['tail'] ?? []);
        $logCell = '<span class="muted">' . h((string)($s['error_log'] ?? 'none')) . '</span>';
        if ($tail) {
            $logCell = '<details><summary>' . count($tail) . ' line(s)</summary><pre class="log">'
                . h(implode("\n", array_map(static fn($l) => (string)$l, $tail))) . '</pre></details>';
        } elseif (($s['error_log'] ?? 'none') === 'none') {
            $logCell = '<span class="muted">no error log</span>';
        } else {
            $logCell = '<span class="muted">empty</span>';
        }

        $src = [];
        if (!empty($s['root'])) {
            $src[] = 'root ' . $s['root'];
        }
        if (!empty($s['proxy'])) {
            $src[] = 'proxy ' . $s['proxy'];
        }
        if (!empty($s['owner'])) {
            $src[] = $s['owner'];
        }
        if (($s['entries'] ?? null) !== null) {
            $src[] = (int)$s['entries'] . ' entries';
        }

        $out .= '<tr>'
            . '<td class="site"><strong>' . h((string)$s['id']) . '</strong>'
            . (count((array)($s['http'] ?? [])) > 1 ? '<br><span class="muted small">' . h(implode(', ', array_keys((array)$s['http']))) . '</span>' : '')
            . '</td>'
            . '<td><span class="pill ' . $cls . '">' . h($statusTxt) . '</span></td>'
            . '<td>' . ($up ? h((string)($s['ms'] ?? 0)) : '—') . '</td>'
            . '<td><span class="' . $tlsCls . '">' . h($tlsTxt) . '</span></td>'
            . '<td class="small muted">' . h(implode(' · ', $src)) . '</td>'
            . '<td class="logcell">' . $logCell . '</td>'
            . '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

function render_target(string $id, array $state): string
{
    $t = target_conf($id);
    $rec = $state['targets'][$id] ?? null;
    $now = time();

    if (!is_array($rec)) {
        return '<section class="target pending"><header><h2>' . h($t['label']) . '</h2>'
            . '<div class="pills">' . pill('not collected yet', 'grey') . '</div>'
            . '<form method="post" action="/action.php" class="inline"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="op" value="recheck"><input type="hidden" name="target" value="' . h($id) . '">'
            . '<button class="btn">Re-check</button></form></header>'
            . '<p class="empty">No reading yet for this target.</p></section>';
    }

    $age = max(0, $now - (int)$rec['checked_at']);
    $stale = $age > (int)$t['fresh'];
    $reachable = !empty($rec['reachable']);
    $m = (array)($rec['metrics'] ?? []);

    $stateCls = !$reachable ? 'down' : ($stale ? 'stale' : 'up');
    $stateTxt = !$reachable ? 'DOWN' : ($stale ? 'STALE' : 'UP');

    $out = '<section class="target ' . h($stateCls) . '" id="t-' . h($id) . '">';
    $out .= '<header><h2>' . h($t['label']) . ' <span class="muted small">' . h($t['note']) . '</span></h2>';
    $out .= '<div class="pills">' . pill($stateTxt, $stateCls) . pill('checked ' . human_age((int)$rec['checked_at']), 'grey');
    if (!$reachable && (int)($rec['last_good_at'] ?? 0) > 0) {
        $out .= pill('readings ' . human_age((int)$rec['last_good_at']), 'warn');
    }
    if (($rec['duration_ms'] ?? 0) > 0) {
        $out .= pill('probe ' . (int)$rec['duration_ms'] . 'ms', 'grey');
    }
    $out .= '</div>';
    $out .= '<form method="post" action="/action.php" class="inline"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="op" value="recheck"><input type="hidden" name="target" value="' . h($id) . '">'
        . '<button class="btn">Re-check</button></form>';
    $out .= '</header>';

    if (!$reachable) {
        $lastGood = (int)($rec['last_good_at'] ?? 0);
        $out .= '<p class="alert bad">Unreachable: ' . h((string)($rec['error'] ?? 'unknown')) . '</p>';
        $out .= '<p class="hint">Showing the last known reading'
            . ($lastGood > 0 ? ' from ' . h(human_age($lastGood)) : ' (none collected yet)')
            . '. Everything below is cached, not live.</p>';
    }

    $out .= '<div class="grid">';
    $out .= tile('hostname', (string)($m['hostname'] ?? '—'), (string)($m['os'] ?? ''));
    $out .= tile('uptime', (string)($m['uptime'] ?? '—'), (string)($m['uptime_raw'] ?? ''));
    $out .= tile('load 1/5/15', trim(($m['load1'] ?? '') . ' ' . ($m['load5'] ?? '') . ' ' . ($m['load15'] ?? '')), ($m['cpus'] ?? '?') . ' cores');
    $out .= bar($m['load_pct'] ?? null, 'load vs cores');
    $out .= bar($m['mem_pct'] ?? null, 'memory used');
    $out .= tile('memory', fmt_kb($m['mem_used_kb'] ?? 0) . ' / ' . fmt_kb($m['mem_total_kb'] ?? 0), 'free ' . fmt_kb($m['mem_free_kb'] ?? 0));
    $out .= bar($m['disk_pct'] ?? null, 'disk / used');
    $out .= tile('disk /', fmt_kb($m['disk_used_kb'] ?? 0) . ' / ' . fmt_kb($m['disk_size_kb'] ?? 0),
        (string)($m['disk_fs'] ?? '') . ' · ' . fmt_kb($m['disk_avail_kb'] ?? 0) . ' free');
    if (isset($m['home_pct'])) {
        $out .= bar((float)$m['home_pct'], 'account disk (home)');
        $out .= tile('home usage', fmt_kb($m['home_used_kb'] ?? 0) . ' / ' . fmt_kb($m['home_size_kb'] ?? 0), (string)($m['home_fs'] ?? ''));
    }
    if (isset($m['inodes_total']) && (int)$m['inodes_total'] > 0) {
        $out .= tile('inodes', number_format((int)$m['inodes_used']) . ' / ' . number_format((int)$m['inodes_total']), '');
    }
    if (!empty($m['php_cli_ver']) || !empty($m['php_cli'])) {
        $out .= tile('PHP (cli)', (string)($m['php_cli_ver'] ?: '—'), '');
    }
    if (!empty($m['whoami'])) {
        $out .= tile('login', (string)$m['whoami'], 'as ' . (string)($m['home'] ?? ''));
    }
    if ($t['type'] === 'cpanel' && isset($m['cron_entries'])) {
        $out .= tile('account cron lines', (string)$m['cron_entries'], 'informational only');
    }
    $out .= '</div>';

    // ---- services -------------------------------------------------------
    if ($t['type'] === 'vps') {
        $out .= '<h4>Services</h4>';
        $units = (array)($rec['units'] ?? []);
        if (!$units) {
            $out .= '<p class="empty">No unit data.</p>';
        } else {
            $out .= '<div class="table-wrap"><table><thead><tr><th>Unit</th><th>State</th><th>Enabled</th></tr></thead><tbody>';
            foreach ($units as $u => $st) {
                $parts = explode('/', (string)$st);
                $out .= '<tr><td>' . h($u) . '</td>'
                    . '<td><span class="pill ' . unit_state_class((string)$st) . '">' . h(unit_state_label((string)$st)) . '</span></td>'
                    . '<td class="muted small">' . h($parts[2] ?? '—') . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }
        $out .= '<h4>Containers (bots / servers)</h4>';
        if (!empty($m['docker_present'])) {
            $out .= '<div class="grid narrow">'
                . tile('running', (string)($m['docker_running'] ?? 0))
                . tile('not running', (string)($m['docker_stopped'] ?? 0))
                . tile('total', (string)($m['docker_total'] ?? 0))
                . tile('unhealthy', (string)($m['docker_unhealthy'] ?? 0), '', ((int)($m['docker_unhealthy'] ?? 0) > 0) ? 'bad' : '')
                . '</div>';
            $bad = array_values(array_filter((array)($rec['containers'] ?? []), static function ($c) {
                return !in_array($c['state'], ['running'], true) || str_contains((string)$c['status'], 'unhealthy');
            }));
            if ($bad) {
                $out .= '<div class="table-wrap"><table><thead><tr><th>Container</th><th>State</th><th>Status</th></tr></thead><tbody>';
                foreach ($bad as $c) {
                    $out .= '<tr><td>' . h($c['name']) . '</td><td><span class="pill warn">' . h($c['state']) . '</span></td><td class="small">' . h($c['status']) . '</td></tr>';
                }
                $out .= '</tbody></table></div>';
            } else {
                $out .= '<p class="empty">No exited / unhealthy containers.</p>';
            }
        } else {
            $out .= '<p class="empty">docker not installed on this host.</p>';
        }

        $out .= '<h4>Queue &amp; automation</h4><div class="grid narrow">';
        $qp = $m['queue_pending'] ?? 'n/a';
        if (is_numeric($qp)) {
            $out .= tile('panel jobs pending', (string)$qp, 'failed ' . (string)($m['queue_failed'] ?? 'n/a'));
            $out .= tile('jobs in flight', (string)($m['queue_inflight'] ?? 'n/a'), '');
        } else {
            $out .= tile('panel jobs pending', 'n/a', (string)($m['queue_note'] ?? 'pterodactyl not detected'));
        }
        if (!empty($m['pm2_present'])) {
            $pm2 = (array)($rec['pm2'] ?? []);
            $out .= tile('pm2 processes', (string)($pm2['online'] ?? 0) . ' online', (string)($pm2['total'] ?? 0) . ' total');
        } else {
            $out .= tile('pm2 processes', 'not installed', '');
        }
        $out .= '</div>';
        if (!empty($rec['pm2']['procs'])) {
            $out .= '<details class="more"><summary>pm2 process list</summary><div class="table-wrap"><table><tbody>';
            foreach ((array)$rec['pm2']['procs'] as $p) {
                $out .= '<tr><td>' . h($p['name']) . '</td><td><span class="pill ' . ($p['status'] === 'online' ? 'ok' : 'warn') . '">' . h($p['status']) . '</span></td></tr>';
            }
            $out .= '</tbody></table></div></details>';
        }

        $out .= '<h4>Top processes</h4>';
        $procs = (array)($rec['procs'] ?? []);
        if ($procs) {
            $out .= '<div class="table-wrap"><table><thead><tr><th>Process</th><th>CPU%</th><th>MEM%</th></tr></thead><tbody>';
            foreach ($procs as $p) {
                $out .= '<tr><td>' . h($p['cmd']) . '</td><td>' . h($p['cpu']) . '</td><td>' . h($p['mem']) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        } else {
            $out .= '<p class="empty">No process data.</p>';
        }
    }

    // ---- last action / last successful action ---------------------------
    $acts = action_summary_for($id);
    $out .= '<h4>Recovery</h4><div class="grid narrow">'
        . tile('last action', $acts['last'] !== '' ? $acts['last'] : 'never', 'from this app\'s action log')
        . tile('last successful action', $acts['last_ok'] !== '' ? $acts['last_ok'] : 'never', 'from this app\'s action log')
        . '</div>';
    $out .= render_actions($id, $state);

    // ---- sites ----------------------------------------------------------
    $sites = (array)($rec['sites'] ?? []);
    $out .= '<h4>Sites discovered <span class="muted small">(' . count($sites) . ')</span></h4>';
    if (!$reachable && $sites) {
        $lastGood = (int)($rec['last_good_at'] ?? 0);
        $out .= '<p class="hint">Last known site inventory'
            . ($lastGood > 0 ? ' from ' . h(human_age($lastGood)) : '')
            . ' — the host is not answering right now.</p>';
    }
    $out .= render_sites($sites, $t['type']);

    $out .= '</section>';
    return $out;
}

function action_summary_for(string $id): array
{
    $lines = tail_lines(data_dir() . '/actions.log', 400);
    $last = '';
    $lastOk = '';
    foreach ($lines as $l) {
        if (!str_contains($l, 'target=' . $id . ' ')) {
            continue;
        }
        $stamp = trim(explode(']', ltrim($l, '['))[0] ?? '');
        if ($last === '') {
            $last = $stamp;
        }
        if (str_contains($l, '| UP |') && $lastOk === '') {
            $lastOk = $stamp;
        }
    }
    return ['last' => $last, 'last_ok' => $lastOk];
}

function render_page(array $state, string $user, array $flash = []): string
{
    $c = cfg();
    $now = time();
    $total = count($c['targets']);
    $up = 0;
    $staleCount = 0;
    foreach ($c['targets'] as $id => $t) {
        $rec = $state['targets'][$id] ?? null;
        if (!is_array($rec)) {
            continue;
        }
        if (!empty($rec['reachable'])) {
            $up++;
        }
        if (($now - (int)$rec['checked_at']) > (int)$t['fresh']) {
            $staleCount++;
        }
    }
    $sitesTotal = 0;
    foreach ((array)($state['targets'] ?? []) as $rec) {
        $sitesTotal += count((array)($rec['sites'] ?? []));
    }

    $newest = 0;
    foreach ((array)($state['targets'] ?? []) as $rec) {
        $newest = max($newest, (int)($rec['checked_at'] ?? 0));
    }

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($c['app_name']) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=3">
</head>
<body>
<header class="topbar">
  <div class="topbar-in">
    <div class="brand">
      <span class="dot <?= $up === $total ? 'ok' : ($up > 0 ? 'warn' : 'bad') ?>"></span>
      <h1><?= h($c['app_name']) ?></h1>
      <span class="muted small">status.novaspack.com</span>
    </div>
    <div class="topbar-actions">
      <span class="pill grey"><?= h($up . '/' . $total) ?> targets reachable</span>
      <span class="pill grey"><?= h((string)$sitesTotal) ?> sites</span>
      <span class="pill grey">readings as of <?= h(human_age($newest)) ?></span>
      <form method="post" action="/action.php" class="inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="op" value="recheck_all">
        <button class="btn btn-primary">Re-check all</button>
      </form>
      <span class="muted small"><?= h($user) ?></span>
    </div>
  </div>
</header>

<?php foreach ($flash as $f): ?>
  <div class="flash <?= h($f['type'] ?? 'ok') ?>"><?= h($f['text'] ?? '') ?></div>
<?php endforeach; ?>

<main>
<?php if ($staleCount > 0): ?>
  <p class="hint"><?= h((string)$staleCount) ?> target(s) showing cached data older than their refresh interval — those cards say STALE.</p>
<?php endif; ?>

<?php foreach (array_keys($c['targets']) as $id): ?>
  <?= render_target($id, $state) ?>
<?php endforeach; ?>

<section class="panel">
  <h2>Recent incidents <span class="muted small">(state transitions and recovery actions)</span></h2>
  <?php $events = load_incidents((int)($c['history']['show_events'] ?? 50)); ?>
  <?php if (!$events): ?>
    <p class="empty">No transitions recorded yet. Events appear here when a service, a site or a whole target changes state.</p>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Target</th><th>Subject</th><th>Detail</th></tr></thead><tbody>
    <?php foreach ($events as $e): ?>
      <?php [$lbl, $cls] = event_label((string)($e['type'] ?? '')); ?>
      <tr>
        <td class="small muted nowrap"><?= h(date('Y-m-d H:i:s', (int)$e['at'])) ?></td>
        <td><span class="pill <?= h($cls) ?>"><?= h($lbl) ?></span><?= !empty($e['flap']) ? ' <span class="pill warn">flap</span>' : '' ?></td>
        <td><?= h((string)($e['target'] ?? '')) ?></td>
        <td class="small"><?= h((string)($e['subject'] ?? '')) ?></td>
        <td class="small muted"><?= h((string)($e['detail'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Action log <span class="muted small">(tail)</span></h2>
  <?php $log = tail_lines(data_dir() . '/actions.log', 30); ?>
  <?php if (!$log): ?>
    <p class="empty">No actions logged yet.</p>
  <?php else: ?>
    <pre class="log logbig"><?= h(implode("\n", array_reverse($log))) ?></pre>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>How this page works</h2>
  <ul class="notes">
    <li>Everything above is collected live from the four hosts — nothing is hardcoded. Site lists are re-discovered on every check.</li>
    <li>Readings are cached briefly (vps-a 25s, the remotes 60–90s) so refreshing does not hammer the hosts. Each card shows the age of its reading.</li>
    <li><strong>Re-check</strong> bypasses the cache for that target; <strong>Re-check all</strong> does all four.</li>
    <li>Recovery buttons POST a <code>target</code> + <code>action</code> id and a CSRF token. The server maps that pair to one fixed command — the request can never influence the command text. Anything else is rejected and logged.</li>
    <li>Site probing is HEAD-only against <code>/</code>. Scheduled / destructive-looking paths are on a hard blocklist and a GET is never issued, so fetching a page cannot trigger a cron, a billing run or a deploy.</li>
    <li>Error-log tails are read over SSH with <code>tail</code>, never fetched over HTTP.</li>
  </ul>
</section>
</main>

<footer class="foot">
  <span>php <?= h(PHP_VERSION) ?></span>
  <span>rendered <?= h(date('Y-m-d H:i:s')) ?> UTC</span>
  <span>collector: <?= h(json_encode($state['collector'] ?? ['ran' => false])) ?></span>
</footer>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
