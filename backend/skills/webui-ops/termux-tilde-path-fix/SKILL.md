---
name: termux-tilde-path-fix
description: "Memastikan tool path backend (`fs_read`/`fs_write`/`list_dir`/`search`) selalu expand `~`/`~/` ke Debz Home, bukan literal folder `~`. Pakai kalau muncul folder anomali bernama `~` atau path `~/...` tidak resolve."
version: 1.1.0
author: Debz AI
license: MIT
---

# Fix Tilde Path di Tool Server (Debz Home)

## Masalah
AI sering gagal `read_file`/`write_file`/`list_dir`/`search` dengan path `~/...` karena endpoint tidak expand `~`. Akibatnya muncul folder anomali literal bernama `~` (misal `~/debz-ai/~/`).

## Root Cause
- Endpoint `/api/fs_read`, `/api/fs_write`, `/api/fs_list`, `/api/fs_search` di `backend.py` memakai `str(body.get("path"))` mentah tanpa resolusi `~`.
- Backend dijamin expand `~` ke Debz Home (`/home/debz`) via `_expand_home()`.

## Mekanisme (sudah aktif)
```python
# backend.py
_DEBZ_AI_ROOT = str(Path(__file__).resolve().parent)   # ~/debz-ai
DEBZ_HOME    = str(Path(_DEBZ_AI_ROOT).parent)          # /home/debz

def _expand_home(path: str) -> str:
    if not path: return path
    if path == "~": return DEBZ_HOME
    if path.startswith("~/"): return os.path.join(DEBZ_HOME, path[2:])
    return path
```
- `~` / `~/<sub>` SELALU resolve ke Debz Home — konsisten walau backend jalan sebagai root (HOME proses bisa beda).
- Kalau masih ada endpoint yang melempar path mentah, bungkus dengan `_expand_home(str(body.get("path")))`.

## Test
```bash
curl -s -X POST http://127.0.0.1:9191/api/fs_read \
  -H "Content-Type: application/json" -H "x-tools-token: $TOKEN" \
  -d '{"path": "~/debz-ai/AGENTS.md"}'
```
Auth pakai header `x-tools-token` (bukan Bearer). Token dari `.ai-config.ini` key `AI_TOOLS_TOKEN`.

## Catatan
- Backup original → `~/.ai_staging/BACKUP/`, Direct Patch ke file aktif (rules AGENTS.md).
- Jangan pernah tulis path literal `~/...` sebagai nama folder.
- Restart backend: `pkill -f backend.py` lalu start ulang via `debz.sh` (watchdog restart otomatis).