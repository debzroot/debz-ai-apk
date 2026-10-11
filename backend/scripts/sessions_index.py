#!/usr/bin/env python3
"""Rebuild sessions_idx di notes.db dari sessions/session_*.json (WAL-safe)."""
import json
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DB = ROOT / "notes.db"
SESS = ROOT / "sessions"

def main() -> int:
    con = sqlite3.connect(str(DB), timeout=10, isolation_level=None)
    try:
        con.execute("PRAGMA journal_mode=WAL;")
        con.execute("PRAGMA synchronous=NORMAL;")
        con.execute("PRAGMA busy_timeout=5000;")
        con.execute("CREATE TABLE IF NOT EXISTS sessions_idx (sid TEXT PRIMARY KEY, title TEXT, updated_at INTEGER, msgs INTEGER)")
    except Exception as e:
        print(f"db init gagal: {e}", file=sys.stderr)
        return 1
    n = 0
    if SESS.is_dir():
        for f in sorted(SESS.glob("session_*.json")):
            try:
                st = f.stat()
                raw = f.read_text(encoding="utf-8", errors="replace")
                title, msgs = f.stem[:80], 0
                try:
                    j = json.loads(raw)
                    if isinstance(j, dict):
                        t = str(j.get("title", "") or j.get("name", "")).strip()
                        if t:
                            title = t[:80]
                        m = j.get("messages")
                        if isinstance(m, list):
                            msgs = len(m)
                except Exception:
                    pass
                con.execute("INSERT INTO sessions_idx (sid, title, updated_at, msgs) VALUES (?, ?, ?, ?) ON CONFLICT(sid) DO UPDATE SET title=excluded.title, updated_at=excluded.updated_at, msgs=excluded.msgs", (f.stem, title, int(st.st_mtime), msgs))
                n += 1
            except Exception:
                continue
    try:
        con.commit()
    except Exception:
        pass
    con.close()
    print(f"indexed={n}")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
