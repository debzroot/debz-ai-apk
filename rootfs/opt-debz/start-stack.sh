#!/bin/sh
# start-stack.sh — jalan DI DALAM proot. Env: PORT_WEB PORT_API PORT_FPM.
set -eu
PORT_WEB="${PORT_WEB:-8091}"
PORT_API="${PORT_API:-8092}"
PORT_FPM="${PORT_FPM:-9000}"
R=/opt/debz
APP=$R/app
mkdir -p /tmp/debz "$R/logs" "$APP"

sed -e "s/@WEB@/$PORT_WEB/" -e "s/@FPM@/$PORT_FPM/" \
  "$R/nginx-debz.conf.template" > /tmp/debz-nginx.conf
sed -e "s/@FPM@/$PORT_FPM/" \
  "$R/php-fpm-debz.conf" > /tmp/debz-phpfpm.conf

if [ ! -f "$APP/index.php" ]; then
  echo "<h3>debz-ai stack OK</h3><p>web=$PORT_WEB api=$PORT_API</p>" > "$APP/index.html"
fi

# bunuh stack otomatis kalau app Android mati (uninstall/force-stop/crash).
# Output WAJIB redirect: kalau nongkrong di pipe stdout, Java drain()
# nunggu EOF selamanya -> status mentok "starting-stack" abadi.
"$R/watchdog.sh" >>"$R/logs/watchdog.log" 2>&1 &
echo "watchdog pid $!"

# -D = jangan daemonize, tapi tetap & biar script lanjut ke nginx/opencode
/usr/sbin/php-fpm8.3 -D -y /tmp/debz-phpfpm.conf >> "$R/logs/php-fpm-boot.log" 2>&1 &
/usr/sbin/nginx -c /tmp/debz-nginx.conf
# tool server backend.py (function_call/tools untuk agent + debz-term)
if [ -f "$APP/backend.py" ]; then
  TOOLS_PORT="${TOOLS_PORT:-9191}"
  nohup python3 "$APP/backend.py" >> "$R/logs/tools.log" 2>&1 &
  echo "tools pid $! port $TOOLS_PORT"
fi
if [ "${START_OPENCODE:-1}" = "1" ]; then
  mkdir -p "$APP/opencode-bin"
  if [ ! -f "$APP/opencode-bin/.serve_password" ]; then
    (cat /proc/sys/kernel/random/uuid 2>/dev/null || echo "$RANDOM$RANDOM-$$") > "$APP/opencode-bin/.serve_password"
    chmod 600 "$APP/opencode-bin/.serve_password"
  fi
  PASS=$(cat "$APP/opencode-bin/.serve_password")
  printf '{"port":%s,"url":"http://127.0.0.1:%s"}' "$PORT_API" "$PORT_API" > "$APP/opencode-bin/.serve.json"
  chmod 600 "$APP/opencode-bin/.serve.json"
  mkdir -p "$APP/opencode-bin/.cfg_home" "$APP/opencode-bin/.data_home"
  XDG_CONFIG_HOME="$APP/opencode-bin/.cfg_home" XDG_DATA_HOME="$APP/opencode-bin/.data_home" \
    OPENCODE_SERVER_PASSWORD="$PASS" nohup /opt/opencode/opencode serve \
    --port "$PORT_API" --hostname 127.0.0.1 > "$R/logs/opencode.log" 2>&1 &
fi
echo "stack up: web=$PORT_WEB api=$PORT_API fpm=$PORT_FPM"
