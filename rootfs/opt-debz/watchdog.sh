#!/bin/sh
# watchdog.sh — bunuh stack kalau process app Android sudah tidak ada.
#
# Uninstall cuma kirim SIGKILL ke app process. Child proot (php-fpm, nginx,
# opencode) jadi orphan: port 8091/8092 ke-occupi, rootfs 130MB nge-gantung
# di deleted inode. Jadi app harus bunuh-stack-nya sendiri begitu parent mati.
#
# Env: DEBZ_APP_PID = android.os.Process.myPid() dari sisi Java.
set -u
R=/opt/debz
LOG="$R/logs/watchdog.log"
PID="${DEBZ_APP_PID:-}"
[ -n "$PID" ] || exit 0
mkdir -p "$R/logs"

# field 22 /proc/PID/stat (starttime) sebagai token: kalau PID dipakai ulang
# proses lain, watchdog lama harus exit biar gak matiin stack yang baru start.
stat_field() { cut -d')' -f2 2>/dev/null < "/proc/$1/stat" 2>/dev/null | awk '{print $20}'; }
TOKEN=$(stat_field "$PID")
[ -n "$TOKEN" ] || exit 0
echo "watchdog aktif untuk pid $PID (start $TOKEN)" >> "$LOG"

while [ -d "/proc/$PID" ]; do
  NOW=$(stat_field "$PID")
  [ -z "$NOW" ] && break
  [ "$NOW" != "$TOKEN" ] && exit 0
  sleep 3
done

echo "$(date -u +%FT%TZ) app process $PID hilang, stop stack" >> "$LOG"
"$R/stop-stack.sh" >> "$LOG" 2>&1
pkill -f 'php-fpm8.3' 2>/dev/null
pkill -f 'debz-nginx' 2>/dev/null
pkill -f 'opencode serve' 2>/dev/null
exit 0
