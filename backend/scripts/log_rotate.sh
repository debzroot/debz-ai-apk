#!/bin/bash
# Rotasi log sistematis (dipanggil watchdog tiap 5 mnt + manual). Cap 2MB, retensi 7 hari.
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
php -r 'require "'"$SCRIPT_DIR"'/logger.php"; applog_rotate(true);' 2>/dev/null
find "$LOG_DIR" -maxdepth 1 -type f \( -name 'app-*.log' -o -name 'agent-*.log' -o -name 'perf-*.log' -o -name 'json-*.log' -o -name 'stall-*.log' -o -name 'debug-*.log' \) -mtime +7 -delete 2>/dev/null
find "$LOG_DIR" -maxdepth 1 -type f -name 'sess-*.log' -mtime +3 -delete 2>/dev/null
for n in nginx-access.log nginx-error.log oc-serve.log webui-fpm.log pw_daemon.log dns_proxy.log backend.log watchdog.log xvfb.log provider-heartbeat.log webui-fpm-slow.log summarizer.log; do
  p="$LOG_DIR/$n"
  if [ -f "$p" ] && [ "$(stat -c%s "$p" 2>/dev/null || echo 0)" -gt 2097152 ]; then
    mv -f "$p" "$p.1" 2>/dev/null
    : > "$p" 2>/dev/null
    echo "[$(date '+%F %T')] rotate: $n -> $n.1" >> "$LOG_DIR/watchdog.log"
  fi
done
if [ -f "$SCRIPT_DIR/debug.log" ] && [ "$(stat -c%s "$SCRIPT_DIR/debug.log" 2>/dev/null || echo 0)" -gt 524288 ]; then
  cat "$SCRIPT_DIR/debug.log" >> "$LOG_DIR/debug-$(date +%Y%m%d).log" 2>/dev/null
  : > "$SCRIPT_DIR/debug.log" 2>/dev/null
fi
