---
name: debz-ai-cli-progress-ui
description: "Patch UI debz-term.py: pindahin animasi PROGRESS worktree ke atas memory bar + memory bar live-update real-time saat tool output masuk context."
version: 1.0.0
tags: [debz-ai, cli, debz-term, rich, worktree, memory-bar, live-render, ui-patch]
---

# Debz AI CLI — Progress UI di Atas Memory Bar

## Konteks
File: `~/debz-ai/debz-term.py` (CLI agent Debz AI, Python + Rich).
Request: animasi PROGRESS worktree dipindahin posisinya jadi di ATAS memory bar (sebelumnya terpisah / urutan beda).

## Layout Hasil
```
╭─ ◆ PROGRESS ◆ ──────────────╮
│ ◐ ⚙️ shell command ...      │
│ ✓ 🧠 Lagi mikir    12s      │
╰─────────────────────────────╯
  🧠  [━━━━━━━━───]  47% · 7.4k/16k   ← live update!
```

## Perubahan (6 patch)
1. `UI.memory_bar` → refactor jadi helper `mem_line()` (no duplikasi kode).
2. Helper `mem_line()` + `WorkTree(tty, agent=None)` — WorkTree bisa akses data agent.
3. `self.agent = agent` di `__init__` WorkTree — ref untuk snapshot memory.
4. `_renderable()` → `Group(box, mem_snapshot)` — panel PROGRESS di atas, memory bar di bawah.
5. `chat()` → `WorkTree(self.tty, agent=self)` — wiring agent ke tree.
6. `memory_usage(agent, msgs=None)` + `tree.msgs = msgs` — bar update live pas tool output masuk context.

## Poin Teknis Kunci
- **Live update memory bar**: `tree.msgs` di-update tiap tool call selesai, jadi persentase naik real-time (bukan statis). Berguna buat monitor kapan auto-trim memory bakal trigger.
- **Group renderable**: pakai `rich.console.Group` untuk gabungin panel + bar jadi satu unit render.
- **Refactor tanpa duplikasi**: `mem_line()` jadi single source of truth buat format bar memory.

## Prosedur Patch (sesuai rules AGENTS.md)
1. Backup original → `~/.ai_staging/BACKUP/debz-term.py.<timestamp>`
2. Patch script → `~/Workspaces/patches/patch_progress_above_membar.py`
3. Direct Patch → `~/debz-ai/debz-term.py`
4. Validasi: `py_compile`, `--help`, render test 2x (dengan/without in-flight msgs)
5. Zero `.bak` di `~/debz-ai/` — Direct Patch + backup di `~/.ai_staging/BACKUP/`

## Validasi
- ✅ `python3 -m py_compile ~/debz-ai/debz-term.py`
- ✅ `python3 ~/debz-ai/debz-term.py --help`
- ✅ Render test 2x (dengan/without in-flight msgs) — panel + bar muncul bener
- ✅ Path project tetap bersih (backup/fixed di Workspaces, bukan `~/debz-ai/`)

## Related
- Skill `webui-ops/debz-ai-webui-ops` — operasi WebUI Debz AI (nginx + php-fpm).