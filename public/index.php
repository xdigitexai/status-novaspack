<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/lib.php';
require_once __DIR__ . '/../app/collect.php';
require_once __DIR__ . '/../app/actions.php';
require_once __DIR__ . '/../app/render.php';

session_boot();

$c = cfg();
$user = (string)($_SERVER['PHP_AUTH_USER'] ?? 'operator');

$flash = [];
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    $flash[] = $_SESSION['flash'];
}
unset($_SESSION['flash']);

// Collect only what is past its own interval. The background timer normally
// keeps this warm, so the page renders from cache in milliseconds.
$state = state_read();
$now = time();
$due = false;
foreach ($c['targets'] as $id => $t) {
    $rec = $state['targets'][$id] ?? null;
    if (!is_array($rec) || ($now - (int)$rec['checked_at']) >= (int)$t['interval']) {
        $due = true;
        break;
    }
}
if ($due) {
    $state = collect_state([], false, (int)$c['budget']['web']);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, must-revalidate');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

echo render_page($state, $user, $flash);
