<?php
declare(strict_types=1);

/*
 * Refresh the shared state cache.
 *   php bin/collect.php                 # everything that is past its interval
 *   php bin/collect.php --force         # everything, right now
 *   php bin/collect.php vps-a cpanel-b  # only these, if due
 *   php bin/collect.php --force vps-a   # only this one, right now
 */

require_once __DIR__ . '/../app/lib.php';
require_once __DIR__ . '/../app/collect.php';

$only = [];
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force' || $arg === '-f') {
        $force = true;
        continue;
    }
    if (!in_array($arg, target_ids(), true)) {
        fwrite(STDERR, 'unknown target: ' . $arg . "\n");
        exit(2);
    }
    $only[] = $arg;
}

$state = collect_state($only, $force, (int)cfg('budget')['cli']);

$c = (array)($state['collector'] ?? []);
if (php_sapi_name() === 'cli') {
    $line = date('Y-m-d H:i:s') . ' collector=' . json_encode($c, JSON_UNESCAPED_SLASHES);
    foreach (target_ids() as $id) {
        $rec = $state['targets'][$id] ?? null;
        $line .= ' ' . $id . '=' . (is_array($rec) ? (!empty($rec['reachable']) ? 'up' : 'down') : 'none');
    }
    fwrite(STDOUT, $line . "\n");
}
exit(0);
