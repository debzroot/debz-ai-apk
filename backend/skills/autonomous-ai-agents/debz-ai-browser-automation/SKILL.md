---
name: debz-ai-browser-automation
description: "Browser automation (CDP-native, tanpa Playwright) and virtual desktop control on Debz AI."
version: 1.1.0
author: Debz AI
license: MIT
platforms: [android, linux]
metadata:
  debz_ai:
    tags: [debz-ai, browser, computer-use, cdp, chromium, automation, frontend-testing]
    related_skills: [computer-use]
---

# Debz AI — Browser & Computer-Use Automation

Skill ini mendeskripsikan seluruh stack browser automation + computer-use di
lingkungan Debz AI (Ubuntu/Linux container).

## Environment Context

- OS: Ubuntu (Linux container/chroot)
- Runtime: Python 3.x + Node.js
- **Playwright TIDAK dipakai lagi** — daemon & client sudah rewrite jadi
  **CDP-native** (raw Chrome DevTools Protocol via WebSocket Node 22+),
  karena playwright-core tidak support platform Android dan dependency-nya berat.

## Stack Components (verified 2026-09-11)

| Komponen | Lokasi | Port |
|---|---|---|
| Backend AI (Python) | `~/debz-ai/backend.py` | **9191** (127.0.0.1) |
| Browser daemon CDP (pw_daemon.mjs) | `~/debz-ai/pw_daemon.sh` | **9333** (127.0.0.1) |
| CUA driver (Xvfb :99) | `~/debz-ai/cua_driver.py` | :99 (virtual) |
| pw_browser.mjs (CDP client Node) | `~/debz-ai/pw_browser.mjs` | via daemon |
| WebUI nginx (debz) | `~/debz-ai/config/webui-nginx.conf` | **8080** |
| WebUI php-fpm | `~/debz-ai/config/webui-fpm.conf` | unix socket |
| Proxy manager | `proxy_manager.py --port 8766` | 8766 |

## Browser Automation (CDP Daemon)

### Start & Status
```bash
sh ~/debz-ai/pw_daemon.sh start    # mulai daemon (idempotent, cek port dulu)
sh ~/debz-ai/pw_daemon.sh status   # cek status
sh ~/debz-ai/pw_daemon.sh stop     # stop
curl -s http://127.0.0.1:9333/json/version  # verifikasi langsung
```

Daemon spawn `chromium-browser --headless=new --remote-debugging-port=9333`
dengan profile `~/.cdp_profile` (persistent). Log: `~/debz-ai/logs/pw_daemon.log`.

### Perintah via Node wrapper (pw_browser.mjs)
```bash
node ~/debz-ai/pw_browser.mjs goto https://example.com
node ~/debz-ai/pw_browser.mjs title
node ~/debz-ai/pw_browser.mjs text
node ~/debz-ai/pw_browser.mjs content
node ~/debz-ai/pw_browser.mjs click 'button.submit' [index]
node ~/debz-ai/pw_browser.mjs type 'input#search' 'keyword'
node ~/debz-ai/pw_browser.mjs press Enter
node ~/debz-ai/pw_browser.mjs wait 2000
node ~/debz-ai/pw_browser.mjs eval 'document.title'
node ~/debz-ai/pw_browser.mjs screenshot /path/shot.png
node ~/debz-ai/pw_browser.mjs close
```

State **persistent antar step** — daemon pegang tab sendiri. Kalau daemon
mati, wrapper fallback launch chromium lokal (port acak 9300+) lalu cleanup.

## CUA Driver (Layar Virtual)

Layar Xvfb 1280x800 di display :99, input via xdotool. Dipakai tool
`computer_use` (screenshot → koordinat → click/type/key/scroll/drag).

## PITFALL KRITIS: ESM vs CommonJS (fixed 2026-09-11)

**Gejala:** daemon "up" di log tapi port 9333 tidak pernah listen;
`ERR_AMBIGUOUS_MODULE_SYNTAX` di `logs/pw_daemon.log`.

**Root cause:** rewrite CDP-native masih pakai `require('path')`,
`require('os')`, `__dirname` (gaya CJS) di file `.mjs` (ESM) yang punya
top-level await. Node 24 menolak load.

**Fix (sudah diterapkan):**
```js
// GANTI ini (CJS, illegal di .mjs):
const PROFILE = require('path').join(require('os').homedir(), '.cdp_profile');
const LOG = require('path').join(__dirname, 'logs', 'pw_daemon.log');

// JADI (ESM):
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROFILE = path.join(os.homedir(), '.cdp_profile');
const LOG = path.join(__dirname, 'logs', 'pw_daemon.log');
```
Di `pw_browser.mjs`: 3x `require('os').homedir()` → `import os` + `os.homedir()`.

**Pelajaran:** setelah rewrite/patch file `.mjs`, SELALU jalankan
`node --check <file>.mjs` + grep `require(` sebelum deploy. Bug ini silent —
launcher tetap bilang "daemon di-start" padahal node langsung crash.

## Recovery (Setelah Reboot Manual)

```bash
cd ~/debz-ai
nohup python3 backend.py > logs/backend.log 2>&1 &
sh pw_daemon.sh start
# verifikasi:
curl -s http://127.0.0.1:9333/json/version
```

## Common Pitfall: TMPDIR kosong (exit 21)

Kalau autostart boot, TMPDIR bisa kosong → chromium fallback ke /tmp yang
tidak writable → "Failed to create socket directory" exit 21. pw_daemon.sh
sudah handle: set TMPDIR ke `/tmp`.

## Referensi

File terkait: `cua_driver.py`, `pw_daemon.sh`, `pw_daemon.mjs`,
`pw_browser.mjs`, `browser.py`, `backend.py`, `agent.php`.
Backup fix ESM: `~/.ai_staging/BACKUP/pw_{daemon,browser}.mjs.20260911_*`.
