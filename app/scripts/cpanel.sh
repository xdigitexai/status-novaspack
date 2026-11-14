# Nova Ops probe — cPanel target (cpanel-a / cpanel-b)
#
# Shared hosting: no root, so this is reachability + account health + the site
# inventory cPanel itself keeps in /var/cpanel/userdata/<user>/ (readable by the
# account owner) + the tail of each docroot's Apache error_log.
#
# Read-only. Takes no arguments.

export LC_ALL=C
H="$HOME"
U="$(id -un)"
D="/var/cpanel/userdata/$U"

echo "@@K hostname=$(hostname 2>/dev/null | tr -d '\n')"
echo "@@K whoami=$U"
echo "@@K home=$H"
echo "@@K kernel=$(uname -r 2>/dev/null)"
if [ -r /etc/os-release ]; then
  . /etc/os-release 2>/dev/null
  echo "@@K os=${PRETTY_NAME:-unknown}"
fi
if command -v uptime >/dev/null 2>&1; then
  echo "@@K uptime=$(uptime -p 2>/dev/null | tr -d '\n')"
  echo "@@K uptime_raw=$(uptime 2>/dev/null | sed 's/^ *//' | tr -d '\n')"
fi
echo "@@K load=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
echo "@@K cpus=$(nproc 2>/dev/null)"
echo "@@K memtotal_kb=$(awk '/^MemTotal:/{print $2}' /proc/meminfo 2>/dev/null)"
echo "@@K memavail_kb=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo 2>/dev/null)"
echo "@@K memfree_kb=$(awk '/^MemFree:/{print $2}' /proc/meminfo 2>/dev/null)"

df -P / 2>/dev/null | awk 'NR==2{
  print "@@K disk_fs="$1;
  print "@@K disk_size_kb="$2;
  print "@@K disk_used_kb="$3;
  print "@@K disk_avail_kb="$4;
  print "@@K disk_pct="$5
}'

# The account's own quota filesystem (CloudLinux/CageFS hosts give a separate mount).
df -P "$H" 2>/dev/null | awk 'NR==2{
  print "@@K home_fs="$1;
  print "@@K home_size_kb="$2;
  print "@@K home_used_kb="$3;
  print "@@K home_avail_kb="$4;
  print "@@K home_pct="$5
}'

echo "@@K php_cli=$(php -v 2>/dev/null | head -1)"
echo "@@K php_cli_ver=$(php -r 'echo PHP_VERSION;' 2>/dev/null)"
echo "@@K inodes_used=$(df -iP "$H" 2>/dev/null | awk 'NR==2{print $3}')"
echo "@@K inodes_total=$(df -iP "$H" 2>/dev/null | awk 'NR==2{print $2}')"

echo "@@P begin"
ps -eo pcpu=,pmem=,comm= --sort=-pcpu 2>/dev/null | head -6
echo "@@P end"

# ---- site inventory from cPanel's own userdata ---------------------------
LIST=""
echo "@@S begin"
if [ -d "$D" ]; then
  for f in "$D"/*; do
    [ -f "$f" ] || continue
    b=$(basename "$f")
    case "$b" in
      *cache*|main|scope|*_SSL) continue ;;
    esac
    sn=$(sed -n 's/^servername:[[:space:]]*//p' "$f" 2>/dev/null | head -1 | tr -d '\r')
    al=$(sed -n 's/^serveralias:[[:space:]]*//p' "$f" 2>/dev/null | head -1 | tr -d '\r')
    dr=$(sed -n 's/^documentroot:[[:space:]]*//p' "$f" 2>/dev/null | head -1 | tr -d '\r' | sed -E 's/[[:space:]]+$//')
    [ -n "$sn" ] || continue
    [ -n "$dr" ] || continue
    [ -d "$dr" ] || continue
    case "$dr" in *"|"*) continue ;; esac
    # same field order as the VPS script: file, names, root, proxy, cert
    printf '@@S1\t%s\t%s\t%s\t\t\n' "$b" "$sn $al" "$dr"
    LIST="$LIST$sn|$dr|$b
"
  done
fi
echo "@@S end"

# ---- error log tails, same SSH session, never over HTTP ------------------
echo "@@L begin"
printf '%s' "$LIST" | while IFS='|' read -r sn dr b; do
  [ -n "$sn" ] || continue
  el=""
  for cand in "$dr/error_log" "$H/logs/$sn.error.log" "$H/logs/error_log"; do
    if [ -f "$cand" ]; then el="$cand"; break; fi
  done
  entries=$(ls -1 "$dr" 2>/dev/null | wc -l | tr -d ' ')
  elsize=0
  [ -n "$el" ] && elsize=$(stat -c %s "$el" 2>/dev/null || echo 0)
  printf '@@L1\t%s\t%s\t%s\t%s\t%s\n' "$dr" "${el:-none}" "$entries" "$elsize" "$sn"
  if [ -n "$el" ]; then
    echo "@@LT $dr begin"
    tail -n 15 "$el" 2>/dev/null | cut -c1-400
    echo "@@LT $dr end"
  fi
done
echo "@@L end"

# ---- account cron jobs (informational: shows what automation exists) -----
if command -v crontab >/dev/null 2>&1; then
  echo "@@K cron_entries=$(crontab -l 2>/dev/null | grep -cE '^[^#]' )"
fi

echo "@@K probe_done=1"
