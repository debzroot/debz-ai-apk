#!/usr/bin/env python3
"""Smoke test critical path: health, auth, danger filter, WAL, sessions index."""
import json
import os
import sqlite3
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = int(os.environ.get("TOOLS_PORT", "9191"))
BASE = f"http://127.0.0.1:{PORT}"
FAILS = []

def check(name, cond, detail=""):
    print(f"{'PASS' if cond else 'FAIL'} {name}" + (f" — {detail}" if detail and not cond else ""))
    if not cond:
        FAILS.append(name)

def load_token():
    for src in (os.environ.get("TOOLS_TOKEN", ""),):
        if src.strip():
            return src.split(",")[0].strip()
    try:
        for line in open(os.path.join(ROOT, ".ai-config.ini"), encoding="utf-8", errors="replace"):
            line = line.strip()
            if line.startswith("AI_TOOLS_TOKEN") and "=" in line:
                return line.split("=", 1)[1].strip().split(",")[0].strip()
    except Exception:
        pass
    return ""

def get(path):
    with urllib.request.urlopen(BASE + path, timeout=8) as r:
        return r.status, json.loads(r.read().decode())

def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(), headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode())
    except Exception as e:
        msg = str(e)
        code = 0
        if "HTTP Error" in msg:
            try:
                code = int(msg.split("HTTP Error")[1].split(":")[0].strip())
            except Exception:
                pass
        return code, {"_error": msg[:200]}

TOKEN = load_token()
try:
    code, h = get("/api/health")
    check("health 200", code == 200, f"code={code}")
    check("health body", h.get("status") == "healthy", str(h)[:120])
except Exception as e:
    check("health reachable", False, str(e)[:160])

if TOKEN:
    code, d = post("/api/exec", {"token": TOKEN, "command": "echo hi", "cwd": ROOT, "approved": True})
    check("exec echo", code == 200, f"code={code} {d}")
    code, d = post("/api/exec", {"token": TOKEN, "command": "rm -rf / --no-preserve-root", "cwd": ROOT})
    blocked = code in (400, 403) or "danger" in json.dumps(d).lower() or d.get("need_approval") is True
    check("danger blocked", blocked, f"code={code} {d}")
    code, d = get("/api/sessions_index?token=" + TOKEN) if False else (0, {})
    code, d = post("/api/sessions_index", {"token": TOKEN})
    check("sessions rebuild", code == 200 and d.get("ok"), f"code={code} {d}")
else:
    check("token present", False, "AI_TOOLS_TOKEN kosong")
    code, d = post("/api/exec", {"token": "salah", "command": "echo hi"})
    check("auth reject", code == 403, f"code={code}")

try:
    con = sqlite3.connect(os.path.join(ROOT, "notes.db"), timeout=10)
    mode = con.execute("PRAGMA journal_mode;").fetchone()[0]
    check("sqlite WAL", mode.lower() == "wal", f"mode={mode}")
    cols = [r[1] for r in con.execute("PRAGMA table_info(sessions_idx);").fetchall()]
    check("sessions_idx table", "sid" in cols, str(cols))
    con.close()
except Exception as e:
    check("sqlite open", False, str(e)[:160])

try:
    hb = json.load(open(os.path.join(ROOT, "logs", "provider-heartbeat.json"), encoding="utf-8"))
    check("heartbeat fresh", abs(__import__("time").time() - hb.get("ts", 0)) < 900, f"ts={hb.get('ts')}")
    check("heartbeat ok", hb.get("ok") is True, str(hb)[:160])
except Exception as e:
    check("heartbeat file", False, str(e)[:160])

print(f"\n{len(FAILS)} gagal" if FAILS else "\nSEMUA LOLOS")
raise SystemExit(1 if FAILS else 0)
