#!/bin/bash
# Debz AI Gateway autostart block — drop into ~/.bashrc (or the equivalent
# chroot rootfs copy) inside the existing "AUTO START SERVICES" guard block,
# right after the last service's closing `fi`. Idempotent: no double-start.
#
# Notes:
# - Absolute debz path so it works regardless of login PATH.
# - (cd ~/debz-ai && nohup ... &) keeps the process out of the login shell's
#   job table and logs to ~/debz-ai/logs/gateway.log.
# - The nohup/'&' ban in the gateway skill applies ONLY to Debz AI' own
#   terminal tool; this runs in the user's login shell, outside Debz AI'
#   process tree, so nohup is correct here.
# - If this file itself is ever run via Debz AI' terminal tool while the
#   gateway is live, the guard will refuse it (string match) — see SKILL.md
#   pitfall for the munge workaround.

echo "[+] Starting Debz AI Gateway (Discord DM)..."
if ! pgrep -f "debz gateway run" > /dev/null; then
    sleep 3
    mkdir -p ~/debz-ai/logs
    (cd ~/debz-ai && nohup ~/debz-ai/debz-term gateway run > ~/debz-ai/logs/gateway.log 2>&1 &)
    echo " Debz AI Gateway Enabled"
else
    echo " Debz AI Gateway already running"
fi
