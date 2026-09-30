#!/bin/sh
# stop-stack.sh — jalan DI DALAM proot. Wajib exit cepat: dipanggil juga dari
# watchdog.sh yang lagi nungguin app process, dan dari Java (yang drain
# output-nya non-blocking — script ini tak boleh nahan pipe).
#
# 0.2.0 bug: nginx diwarisi write-end pipe stdout proot, jadi pipe ga pernah
# EOF -> drain() di StackSupervisor.stop() blocking selamanya. Semua sudah
# di-redirect ke file di start-stack.sh, plus output script ini kecil.
R=/opt/debz
pkill -f "watchdog\.sh" 2>/dev/null || true
pkill -f "php-fpm8\.3.*debz-phpfpm" 2>/dev/null || true
pkill -f "nginx.*debz-nginx" 2>/dev/null || true
pkill -f "backend\.py" 2>/dev/null || true
pkill -f "opencode serve" 2>/dev/null || true
rm -f /tmp/debz-nginx.pid "$R/logs/watchdog.pid" >/dev/null 2>&1 || true
echo "stack stopped"
