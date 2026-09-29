#!/bin/sh
# stop-stack.sh — jalan DI DALAM proot.
pkill -f "php-fpm8.3.*debz-phpfpm" 2>/dev/null || true
pkill -f "nginx.*debz-nginx" 2>/dev/null || true
pkill -f "opencode serve" 2>/dev/null || true
echo "stack stopped"
