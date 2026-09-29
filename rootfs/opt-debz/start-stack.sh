#!/bin/sh
# start-stack.sh — jalan DI DALAM proot. Env: PORT_WEB PORT_API PORT_FPM.
set -eu
PORT_WEB="${PORT_WEB:-8091}"
PORT_API="${PORT_API:-8092}"
PORT_FPM="${PORT_FPM:-9000}"
R=/opt/debz
mkdir -p /tmp/debz "$R/logs" "$R/www"

sed -e "s/@WEB@/$PORT_WEB/" -e "s/@FPM@/$PORT_FPM/" \
  "$R/nginx-debz.conf.template" > /tmp/debz-nginx.conf
sed -e "s/@FPM@/$PORT_FPM/" \
  "$R/php-fpm-debz.conf" > /tmp/debz-phpfpm.conf

if [ ! -f "$R/www/index.html" ]; then
  echo "<h3>debz-ai stack OK</h3><p>web=$PORT_WEB api=$PORT_API</p>" > "$R/www/index.html"
fi

/usr/sbin/php-fpm8.3 -D -y /tmp/debz-phpfpm.conf
/usr/sbin/nginx -c /tmp/debz-nginx.conf
if [ "${START_OPENCODE:-0}" = "1" ]; then
  OPENCODE_PORT="$PORT_API" nohup /opt/opencode/opencode serve \
    > "$R/logs/opencode.log" 2>&1 &
fi
echo "stack up: web=$PORT_WEB api=$PORT_API fpm=$PORT_FPM"
