#!/bin/sh
# watchdog.sh — bunuh stack kalau process app Android sudah tidak ada.
#
# Uninstall cuma kirim SIGKILL ke app process. Child proot (php-fpm, nginx,
# opencode) jadi orphan: port ke-occupi, rootfs nge-gantung di deleted inode.
# Jadi app harus bunuh-stack-nya sendiri begitu parent mati.
#
# Env: DEBZ_APP_PID = android.os.Process.myPid() dari sisi Java.
#
# Peran kedua: jadi SENTINEL. start-stack.sh nge-cek file pid di bawah buat
# tahu stack masih hidup atau belum — tanpa ini, tiap app dibuka nyalain
# stack baru di port acak dan ninggalin yang lama jadi yatim.
set -u
R=/opt/debz
LOG="$R/logs/watchdog.log"
PID="${DEBZ_APP_PID:-}"
mkdir -p "$R/logs"
PIDFILE="$R/logs/watchdog.pid"

# single-instance: watchdog lama yang masih napet pidfile = jangan dobel,
# nanti dua-duanya nunggu app pid yang sama dan bunuh stack bareng.
if [ -s "$PIDFILE" ]; then
  OLD=$(cat "$PIDFILE" 2>/dev/null || true)
  if [ -n "$OLD" ] && [ "$OLD" != "$$" ] && kill -0 "$OLD" 2>/dev/null; then
    if tr '\0' ' ' < "/proc/$OLD/cmdline" 2>/dev/null | grep -q 'watchdog\.sh'; then
      echo "$(date -u +%FT%TZ) watchdog lama masih hidup (pid $OLD), exit" >> "$LOG"
      exit 0
    fi
  fi
fi
echo "$$" > "$PIDFILE"
cleanup() { rm -f "$PIDFILE" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

[ -n "$PID" ] || { echo "$(date -u +%FT%TZ) tanpa DEBZ_APP_PID, watchdog exit" >> "$LOG"; exit 0; }

# field 22 /proc/PID/stat (starttime) sebagai token: kalau PID dipakai ulang
# proses lain, watchdog lama harus exit biar gak matiin stack yang baru start.
stat_field() { cut -d')' -f2 2>/dev/null < "/proc/$1/stat" 2>/dev/null | awk '{print $20}'; }
TOKEN=$(stat_field "$PID")
[ -n "$TOKEN" ] || exit 0
echo "$(date -u +%FT%TZ) watchdog aktif untuk pid $PID (start $TOKEN)" >> "$LOG"

while [ -d "/proc/$PID" ]; do
  NOW=$(stat_field "$PID")
  [ -z "$NOW" ] && break
  [ "$NOW" != "$TOKEN" ] && exit 0
  sleep 3
done

echo "$(date -u +%FT%TZ) app process $PID hilang, stop stack" >> "$LOG"
# fd-isolated: kalau stop-stack pegang pipe stdout proot, pemanggilnya
# (Java drain) nunggu EOF selamanya.
"$R/stop-stack.sh" >> "$LOG" 2>&1 </dev/null
exit 0
