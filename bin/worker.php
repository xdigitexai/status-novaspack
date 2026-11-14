<?php
declare(strict_types=1);

/*
 * Collect one target and write data/targets/<id>.json atomically.
 * Usage: php worker.php <target-id>
 * The target id is validated against config; nothing else is accepted.
 */

require_once __DIR__ . '/../app/lib.php';
require_once __DIR__ . '/../app/probe.php';

$id = (string)($argv[1] ?? '');
if (!in_array($id, target_ids(), true)) {
    fwrite(STDERR, "unknown target\n");
    exit(2);
}

try {
    $rec = probe_target($id);
} catch (Throwable $e) {
    fwrite(STDERR, 'probe failed: ' . $e->getMessage() . "\n");
    exit(3);
}

$path = data_dir() . '/targets/' . $id . '.json';

/*
 * A host that just became unreachable still has a useful last-known state.
 * Carry the previous reading forward (and timestamp it) instead of blanking
 * the page — the UI shows it as stale rather than pretending there is no data.
 */
if (empty($rec['reachable'])) {
    $old = read_json($path, null);
    if (is_array($old) && !empty($old['reachable'])) {
        foreach (['metrics', 'units', 'containers', 'pm2', 'sites', 'procs', 'memprocs'] as $k) {
            if (isset($old[$k]) && $old[$k] !== [] && $old[$k] !== null) {
                $rec[$k] = $old[$k];
            }
        }
        $rec['last_good_at'] = (int)($old['checked_at'] ?? 0);
        $rec['sites_probed_at'] = (int)($old['sites_probed_at'] ?? 0);
        $rec['stale'] = true;
    }
} else {
    $rec['last_good_at'] = (int)$rec['checked_at'];
    $rec['stale'] = false;
}

if (!write_json($path, $rec)) {
    fwrite(STDERR, "could not write target state\n");
    exit(4);
}
exit(0);
