# Nova Ops probe — VPS target (vps-a / vps-b)
#
# Read-only. Takes no arguments. Everything it prints is a record of the host's
# own state. It never writes, starts, stops or reloads anything.

export LC_ALL=C

echo "@@K hostname=$(hostname 2>/dev/null | tr -d '\n')"
echo "@@K whoami=$(id -un 2>/dev/null)"
echo "@@K kernel=$(uname -r 2>/dev/null)"
if [ -r /etc/os-release ]; then
  . /etc/os-release 2>/dev/null
  echo "@@K os=${PRETTY_NAME:-unknown}"
fi

if command -v uptime >/dev/null 2>&1; then
  echo "@@K uptime=$(uptime -p 2>/dev/null | tr -d '\n')"
  echo "@@K uptime_raw=$(uptime 2>/dev/null | sed 's/^ *//' | tr -d '\n')"
fi
echo "@@K boot_epoch=$(awk '{printf "%d", $1}' /proc/uptime 2>/dev/null)"

echo "@@K load=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
echo "@@K procs=$(cut -d'/' -f2 /proc/loadavg 2>/dev/null)"
echo "@@K cpus=$(nproc 2>/dev/null)"
echo "@@K memtotal_kb=$(awk '/^MemTotal:/{print $2}' /proc/meminfo 2>/dev/null)"
echo "@@K memavail_kb=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo 2>/dev/null)"
echo "@@K memfree_kb=$(awk '/^MemFree:/{print $2}' /proc/meminfo 2>/dev/null)"
echo "@@K swaptotal_kb=$(awk '/^SwapTotal:/{print $2}' /proc/meminfo 2>/dev/null)"

df -P / 2>/dev/null | awk 'NR==2{
  print "@@K disk_fs="$1;
  print "@@K disk_size_kb="$2;
  print "@@K disk_used_kb="$3;
  print "@@K disk_avail_kb="$4;
  print "@@K disk_pct="$5
}'

echo "@@P begin"
ps -eo pcpu=,pmem=,comm= --sort=-pcpu 2>/dev/null | head -6
echo "@@P end"

echo "@@M begin"
ps -eo rss=,comm= --sort=-rss 2>/dev/null | head -6
echo "@@M end"

# ---- systemd unit states -------------------------------------------------
for u in docker containerd nginx mariadb redis-server wings pteroq; do
  if systemctl cat "${u}.service" >/dev/null 2>&1; then
    st=$(systemctl show -p ActiveState -p SubState -p UnitFileState --value "${u}.service" 2>/dev/null | paste -sd'/' -)
    echo "@@U ${u} ${st}"
  else
    echo "@@U ${u} not-found"
  fi
done

# ---- pm2 -----------------------------------------------------------------
if command -v pm2 >/dev/null 2>&1; then
  echo "@@K pm2_present=1"
  echo "@@J pm2"
  timeout 8 pm2 jlist 2>/dev/null | head -c 262144
  echo
  echo "@@E pm2"
else
  echo "@@K pm2_present=0"
fi

# ---- docker --------------------------------------------------------------
if command -v docker >/dev/null 2>&1; then
  echo "@@K docker_present=1"
  echo "@@K docker_running=$(timeout 8 docker ps -q 2>/dev/null | wc -l | tr -d ' ')"
  echo "@@K docker_total=$(timeout 8 docker ps -aq 2>/dev/null | wc -l | tr -d ' ')"
  echo "@@K docker_unhealthy=$(timeout 8 docker ps --filter health=unhealthy -q 2>/dev/null | wc -l | tr -d ' ')"
  echo "@@C begin"
  timeout 8 docker ps -a --format '{{.Names}}\t{{.State}}\t{{.Status}}\t{{.Image}}' 2>/dev/null | head -60
  echo "@@C end"
else
  echo "@@K docker_present=0"
fi

# ---- pterodactyl queue health (read only; never runs artisan) -------------
if [ -f /var/www/pterodactyl/artisan ] && command -v php >/dev/null 2>&1; then
  echo "@@K ptero_present=1"
  timeout 12 php 2>/dev/null <<'PHPEOF'
<?php
$env = @file_get_contents('/var/www/pterodactyl/.env');
if ($env === false) { echo "@@K queue_pending=n/a\n@@K queue_note=env-unreadable\n"; exit; }
$c = [];
foreach (explode("\n", $env) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $p = explode('=', $l, 2);
    if (count($p) === 2) { $c[trim($p[0])] = trim(trim($p[1]), "\"'"); }
}
$u = $c['DB_USERNAME'] ?? ''; $pw = $c['DB_PASSWORD'] ?? ''; $db = $c['DB_DATABASE'] ?? '';
$h = $c['DB_HOST'] ?? '127.0.0.1'; $pt = $c['DB_PORT'] ?? '3306';
if ($u === '' || $db === '') { echo "@@K queue_pending=n/a\n@@K queue_note=no-db-config\n"; exit; }
try {
    $pdo = new PDO("mysql:host=$h;port=$pt;dbname=$db;charset=utf8mb4", $u, $pw,
        [PDO::ATTR_TIMEOUT => 4, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $j = (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    $f = (int)$pdo->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn();
    $w = 0;
    try { $w = (int)$pdo->query('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL')->fetchColumn(); } catch (Throwable $e) {}
    echo "@@K queue_pending=$j\n@@K queue_failed=$f\n@@K queue_inflight=$w\n";
} catch (Throwable $e) {
    echo "@@K queue_pending=n/a\n@@K queue_note=db-unreachable\n";
}
PHPEOF
else
  echo "@@K ptero_present=0"
  echo "@@K queue_pending=n/a"
  echo "@@K queue_note=no-pterodactyl"
fi

# ---- sites from nginx ----------------------------------------------------
echo "@@S begin"
for f in /etc/nginx/sites-enabled/*; do
  [ -f "$f" ] || continue
  name=$(basename "$f")
  sn=$(grep -hoE '^[[:space:]]*server_name[[:space:]]+[^;]+;' "$f" 2>/dev/null \
        | sed -E 's/^[[:space:]]*server_name[[:space:]]+//; s/;[[:space:]]*$//' | tr '\n' ' ')
  rt=$(grep -hoE '^[[:space:]]*root[[:space:]]+[^;]+;' "$f" 2>/dev/null \
        | sed -E 's/^[[:space:]]*root[[:space:]]+//; s/;[[:space:]]*$//' | grep -v '^/var/www/html$' | head -1)
  pp=$(grep -hoE '^[[:space:]]*proxy_pass[[:space:]]+[^;]+;' "$f" 2>/dev/null \
        | sed -E 's/^[[:space:]]*proxy_pass[[:space:]]+//; s/;[[:space:]]*$//' | head -1)
  sc=$(grep -hoE '^[[:space:]]*ssl_certificate[[:space:]]+[^;]+;' "$f" 2>/dev/null \
        | sed -E 's/^[[:space:]]*ssl_certificate[[:space:]]+//; s/;[[:space:]]*$//' | head -1)
  ce=""
  if [ -n "$sc" ] && [ -r "$sc" ]; then
    ce=$(openssl x509 -in "$sc" -noout -enddate 2>/dev/null | cut -d= -f2)
  fi
  printf '@@S1\t%s\t%s\t%s\t%s\t%s\n' "$name" "$sn" "$rt" "$pp" "$ce"
done
echo "@@S end"

echo "@@K probe_done=1"
