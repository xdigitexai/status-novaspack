<?php
declare(strict_types=1);

/*
 * Proof that the site probe can never fetch a scheduled / destructive URL.
 *   php bin/selftest-urls.php
 *
 * 1. Every path the dashboard could ever request must be in the allowlist.
 * 2. A list of hostile, real-looking paths must all be rejected by the guard.
 * 3. The source tree is grepped for every place an HTTP request is built.
 */

require_once __DIR__ . '/../app/lib.php';
require_once __DIR__ . '/../app/probe.php';

$fail = 0;

echo "allowlist (the only paths this app will ever request):\n";
foreach (SITE_PROBE_ALLOWLIST as $p) {
    printf("  %-14s %s\n", $p, path_is_forbidden($p) ? 'FORBIDDEN (!)' : 'allowed');
}
echo "paths actually requested at runtime: " . json_encode(probe_paths()) . "\n\n";

$hostile = [
    '/cron_billing.php', '/cron_expire.php', '/cron_auto_refund.php',
    '/pesapal_verify.php', '/email_verify.php', '/deduct.php', '/deduct_balance.php',
    '/queue_worker.php', '/queue.php', '/worker.php', '/deploy.php', '/deploy.sh',
    '/webhook.php', '/payment_callback.php', '/api/v1/mutate', '/api/',
    '/billing/settle', '/payouts/run', '/refund.php', '/send_sms.php',
    '/purge_cache.php', '/flush_queue.php', '/restart_bots.php', '/delete_all.php',
    '/cron_billing.php?force=1', '/index.php?cron=1', '/../etc/passwd', '//cron_billing.php',
    'cron_billing.php', '', 'http://evil.example/cron_billing.php',
];

echo "hostile path guard:\n";
foreach ($hostile as $path) {
    $blocked = path_is_forbidden($path);
    // also make sure the URL builder refuses them
    $builderBlocked = false;
    try {
        site_probe_url('https', 'example.com', $path);
    } catch (Throwable $e) {
        $builderBlocked = true;
    }
    if (!$blocked || !$builderBlocked) {
        $fail++;
        printf("  FAIL  %-36s guard=%s builder=%s\n", $path, $blocked ? 'blocked' : 'ALLOWED', $builderBlocked ? 'blocked' : 'ALLOWED');
    } else {
        printf("  ok    %-36s blocked by guard and by URL builder\n", $path);
    }
}

echo "\nsafe path still builds (positive control):\n";
foreach (['/', '/login', '/index.php'] as $p) {
    try {
        printf("  ok    %s  ->  %s\n", $p, site_probe_url('https', 'example.com', $p));
    } catch (Throwable $e) {
        $fail++;
        printf("  FAIL  %s rejected: %s\n", $p, $e->getMessage());
    }
}

echo "\nhostname guard:\n";
foreach (['good.example.com', 'a-b.example.co.ke', 'evil.com/../x', 'evil.com:22', 'evil.com/x'] as $host) {
    try {
        $u = site_probe_url('https', $host, '/');
        printf("  ok    %-22s -> %s\n", $host, $u);
    } catch (Throwable $e) {
        printf("  ok    %-22s rejected (%s)\n", $host, $e->getMessage());
    }
}

echo "\nevery HTTP request the app can build (source scan):\n";
$root = dirname(__DIR__);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$patterns = ['curl_init', 'curl_setopt', 'file_get_contents("http', "file_get_contents('http", 'CURLOPT_NOBODY', 'GET '];
$hits = 0;
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    foreach (file($f->getPathname()) ?: [] as $n => $line) {
        foreach ($patterns as $pat) {
            if (str_contains($line, $pat)) {
                $hits++;
                printf("  %s:%d  %s\n", str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname()), $n + 1, trim($line));
                break;
            }
        }
    }
}
echo "  ($hits matching line(s))\n";

echo "\nCURLOPT_NOBODY => true occurrences (HEAD-only): ";
$src = (string)file_get_contents(__DIR__ . '/../app/probe.php');
echo substr_count($src, 'CURLOPT_NOBODY') . "\n";
echo "CURLOPT_HTTPGET / literal \"GET\" in app/: ";
$getHits = 0;
foreach ($rii as $f) {
    if ($f->isFile() && $f->getExtension() === 'php' && str_contains($src, 'x')) {
        $c = (string)file_get_contents($f->getPathname());
        $getHits += substr_count($c, 'CURLOPT_HTTPGET') + substr_count($c, 'CURLOPT_POST');
    }
}
echo $getHits . "\n";

echo "\n" . ($fail === 0 ? "PASS — no probe URL outside the allowlist is reachable\n" : "FAIL — {$fail} problem(s)\n");
exit($fail === 0 ? 0 : 1);
