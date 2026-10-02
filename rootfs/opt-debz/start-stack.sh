#!/bin/sh
# start-stack.sh — LAUNCHER. Wajib exit < 30s, ideal < 3s.
#
# Dua aturan yang bikin 0.2.0 mentok "starting-stack" selamanya:
#   1. satu pun daemon TIDAK boleh foreground di script ini — kalau ada yang
#      nyangkut, `sh` ga pernah exit, Java waitFor() mentok 180 detik.
#   2. semua daemon WAJIB fd-isolasi (`>>log 2>&1 </dev/null &`). Kalau mereka
#      mewarisi write-end pipe stdout dari proot, pipe ga pernah EOF ->
#      drain() di Java blocking selamanya (jalur stop jadi deadlock).
set -eu
PORT_WEB="${PORT_WEB:-8091}"
PORT_API="${PORT_API:-8092}"
PORT_FPM="${PORT_FPM:-9000}"
TOOLS_PORT="${TOOLS_PORT:-9191}"
R=/opt/debz
APP=$R/app
LOG=$R/logs
mkdir -p /tmp/debz "$LOG" "$APP"

alive_watchdog() {
  [ -s "$LOG/watchdog.pid" ] || return 1
  _p=$(cat "$LOG/watchdog.pid" 2>/dev/null) || return 1
  [ -n "$_p" ] || return 1
  kill -0 "$_p" 2>/dev/null || return 1
  # guard pid-reuse: pastikan pid itu BENERAN watchdog kita, bukan proses
  # lain yang kebetulan numpunya sama -> jangan salah skip start.
  tr '\0' ' ' < "/proc/$_p/cmdline" 2>/dev/null | grep -q 'watchdog\.sh' || return 1
  return 0
}

port_open() {
  python3 -c "import socket,sys; s=socket.socket(); s.settimeout(1); s.connect(('127.0.0.1',int(sys.argv[1])))" "$1" 2>/dev/null
}

# --- idempoten: stack hidup? jangan nyalain dua kali -----------------------
# Watchdog = sentinel: hidup selama app hidup, dan dia yang bunuh stack pas
# app mati. Hidup + semua port jawab = stack sehat -> skip. Hidup tapi ada
# port mati (opencode OOM dsb) = revive via restart bersih di bawah.
if alive_watchdog; then
  if port_open "$PORT_WEB" && port_open "$PORT_FPM" && port_open "$PORT_API"; then
    echo "stack already live (watchdog pid $(cat "$LOG/watchdog.pid")) web=$PORT_WEB api=$PORT_API"
    exit 0
  fi
  echo "watchdog hidup tapi ada daemon mati -> restart bersih web=$PORT_WEB api=$PORT_API fpm=$PORT_FPM"
fi

# --- bersihkan sisa stack mati, cuma yang conf miliknya kita -------------
# Pola php-fpm WAJIB "php-fpm.*debz-phpfpm", bukan "php-fpm8.3.*...": cmdline
# master aslinya "php-fpm: master process (/tmp/debz-phpfpm.conf)" — tidak ada
# string "php-fpm8.3" di sana, jadi pola lama tidak pernah cocok dan tiap
# restart bocorin satu master baru (pernah 5 master hidup bareng).
pkill -f "php-fpm.*debz-phpfpm" 2>/dev/null || true
pkill -f "nginx.*debz-nginx" 2>/dev/null || true
pkill -f "backend\.py" 2>/dev/null || true
pkill -f "opencode serve" 2>/dev/null || true
rm -f /tmp/debz-nginx.pid "$LOG/watchdog.pid"

sed -e "s/@WEB@/$PORT_WEB/" -e "s/@FPM@/$PORT_FPM/" \
  "$R/nginx-debz.conf.template" > /tmp/debz-nginx.conf
sed -e "s/@FPM@/$PORT_FPM/" \
  "$R/php-fpm-debz.conf" > /tmp/debz-phpfpm.conf

if [ ! -f "$APP/index.php" ]; then
  echo "<h3>debz-ai stack OK</h3><p>web=$PORT_WEB api=$PORT_API</p>" > "$APP/index.html"
fi

# media/ butuh write dari user php-fpm (www-data), sementara $APP milik uid
# app Android. Tanpa 0777 di sini, debz_chat_images_save() gagal mkdir/move
# -> "gak ada image valid" padahal file-nya valid.
mkdir -p "$APP/media"
chmod 0777 "$APP/media" 2>/dev/null || true

# daemon tanpa nyentuh stdin/stdout/stderr parent
spawn() { _dlog=$1; shift; "$@" >>"$_dlog" 2>&1 </dev/null & }

# app Android mati (uninstall/force-stop/crash) -> bunuh stack, free port,
# rootfs ga menggantung di inode deleted.
spawn "$LOG/watchdog.log" "$R/watchdog.sh"

spawn "$LOG/php-fpm-boot.log" /usr/sbin/php-fpm8.3 -D -y /tmp/debz-phpfpm.conf
# 0.2.0 line 28: `nginx -c ...` foreground tanpa `&` -> `sh` ga pernah exit
# -> waitFor(180) mentok. Sekarang: `daemon off` + background ourselves.
spawn "$LOG/nginx-boot.log" /usr/sbin/nginx -c /tmp/debz-nginx.conf -g "daemon off;"

# tool server backend.py (function_call/tools untuk agent + debz-term)
if [ -f "$APP/backend.py" ]; then
  # Gate pydeps. DEX punya firstBootLog + first-boot-pip.sh sendiri, tapi itu
  # hanya jalan kalau gate di sisi Java benar-benar terpicu. Kalau tidak (mis.
  # app di-upgrade, atau marker hilang karena rootfs di-refresh), backend.py
  # langsung crash "No module named requests" dan 22 tool mati sampai app
  # ditutup & dibuka ulang. Cek di sini supaya selesai sekali di sini: cepat
  # kalau marker sudah ada, dan memperbaiki kalau belum.
  if [ ! -f "$R/.pydeps-done" ]; then
    echo "pydeps marker hilang, jalankan first-boot-pip.sh"
    sh "$R/first-boot-pip.sh" >> "$LOG/firstboot.log" 2>&1 </dev/null || true
  fi
  if [ -f "$R/.pydeps-done" ]; then
    spawn "$LOG/tools.log" python3 "$APP/backend.py"
    echo "tools launched port $TOOLS_PORT"
  else
    echo "tools SKIP: first-boot-pip.sh gagal, cek $LOG/firstboot.log" >&2
  fi
fi

if [ "${START_OPENCODE:-1}" = "1" ]; then
  mkdir -p "$APP/opencode-bin/.cfg_home" "$APP/opencode-bin/.data_home"
  if [ ! -f "$APP/opencode-bin/.serve_password" ]; then
    (cat /proc/sys/kernel/random/uuid 2>/dev/null || echo "$RANDOM$RANDOM-$$") \
      > "$APP/opencode-bin/.serve_password"
    chmod 600 "$APP/opencode-bin/.serve_password"
  fi
  PASS=$(cat "$APP/opencode-bin/.serve_password")
  # password ikut ditulis: agent.php baca .serve.json buat auth `run --attach`.
  # 0.2.0 nulis marker tanpa password -> PHP fallback ke file, tapi begitu
  # port geser marker lama jadi nyesat.
  printf '{"port":%s,"url":"http://127.0.0.1:%s","password":"%s"}' \
    "$PORT_API" "$PORT_API" "$PASS" > "$APP/opencode-bin/.serve.json"
  chmod 600 "$APP/opencode-bin/.serve.json"
  # export eksplisit: prefix assignment di depan shell function TIDAK
  # diekspor ke child (jebakan POSIX). 0.2.0 aman karena pakai `nohup`
  # (external command = diekspor); versi spawn() ini wajib export manual,
  # kalau tidak XDG_HOME kosong -> bun crash uv_os_homedir.
  export XDG_CONFIG_HOME="$APP/opencode-bin/.cfg_home"
  export XDG_DATA_HOME="$APP/opencode-bin/.data_home"
  export OPENCODE_SERVER_PASSWORD="$PASS"
  spawn "$LOG/opencode.log" /opt/opencode/opencode serve \
    --port "$PORT_API" --hostname 127.0.0.1
  echo "opencode launched api=$PORT_API"
fi

# --- readiness bounded: report dulu, tetap exit 0 ------------------------
# Script GAIN dialem di sini; Java pegang timeout + health-check status UI.
# Cukup laporkan apa yang sudah listen supaya stack-last.log diagnosable.
sleep 1
LISTENING=$(python3 - "$PORT_WEB" "$PORT_FPM" "$PORT_API" <<'PY' 2>/dev/null || echo "probe-gagal"
import socket, sys
up = []
for name, port in (("web", sys.argv[1]), ("fpm", sys.argv[2]), ("api", sys.argv[3])):
    s = socket.socket()
    s.settimeout(1.5)
    try:
        s.connect(("127.0.0.1", int(port)))
        up.append(name)
    except (OSError, ValueError):
        pass
    finally:
        s.close()
print(",".join(up) or "belum")
PY
)
echo "stack up: web=$PORT_WEB api=$PORT_API fpm=$PORT_FPM listening=$LISTENING"
