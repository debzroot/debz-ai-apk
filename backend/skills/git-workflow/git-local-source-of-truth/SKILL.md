---
name: git-local-source-of-truth
description: SOP sync repo lokal (Termux) ke GitHub. Local SELALU jadi sumber kebenaran; remote hanya tempat backup/update. Dilarang pull/reset dari remote.
tags: [git, github, backup, termux, sync]
---

# Git Sync: Local = Source of Truth

## Prinsip
- Local (`~/debz-ai` atau project di `~/Workspaces/`) adalah sumber kebenaran yang selalu update.
- GitHub/remote HANYA tempat backup — di-update tiap kali local ada pembaruan fitur.
- **DILARANG KERAS:** `git pull`, `git fetch` + `git reset --hard`, atau merge dari remote ke local. Itu bisa menimpa kerjaan local.

## Pre-Flight (wajib sebelum push)
1. Cek status & branch:
   ```bash
   cd ~/debz-ai && git status && git branch --show-current
   ```
2. Verifikasi `.gitignore` menutup file sensitif sebelum commit pertama kali:
   - `.ai-config.ini`, `.ai-providers.json`, `.ai-rr-state.json`, `notes.db`, `.env`, `session_*.json`, `*.bak`, `tmp_*`
   - Cek tracked: `git ls-files | grep -Ei 'config.ini|providers|notes.db|\.env'` → harus kosong.
3. Scan secret di diff baru (jika ada perubahan besar):
   ```bash
   git diff --cached | grep -Ei '(sk-[a-z0-9]{20}|api[_-]?key|token|secret|password)\s*[:=]\s*["'"'"'][A-Za-z0-9_\-]{16,}' | head
   ```
   Hanya referensi kode (variabel) yang boleh muncul, bukan nilai token.

## Sync (push local → remote)
```bash
cd ~/debz-ai
git add -A
git commit -m "chore: sync local $(date +%Y-%m-%d)"
git push origin main
```

## Post-Flight
- `git status` → harus bersih (0 perubahan).
- `git log --oneline -1` → commit terbaru ada di local & remote (`git log origin/main -1` sama).

## Rollback (jika push bikin error)
- Jangan pull dari remote. Perbaiki di local, commit fix, push lagi.
- Kalau perlu restore file: ambil dari `~/.ai_staging/BACKUP/` (bukan dari git remote).

## Catatan
- Branch default: `main`.
- Remote: `origin` (cek dengan `git remote -v`).
- Jangan pernah commit file `.bak`/`.orig`/`tmp_*` — backend memblokirnya di Workspaces, ikuti aturan yang sama di repo.