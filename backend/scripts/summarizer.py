#!/usr/bin/env python3
"""Background summarizer: compact notes.db + sesi > threshold (default 50KB)."""
import json
import os
import re
import sqlite3
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CFG = os.path.join(ROOT, ".ai-config.ini")
LOG = os.path.join(ROOT, "logs", "summarizer.log")


def cfg_int(key, default):
    try:
        with open(CFG) as f:
            for line in f:
                line = line.strip()
                if not line or line[0] in "#;":
                    continue
                if "=" not in line:
                    continue
                k, v = line.split("=", 1)
                if k.strip() == key and re.fullmatch(r"\d+", v.strip()):
                    return int(v.strip())
    except OSError:
        pass
    return default


def log(msg, ctx=None):
    try:
        os.makedirs(os.path.dirname(LOG), exist_ok=True)
        line = "[%s] %s %s\n" % (time.strftime("%H:%M:%S"), msg, json.dumps(ctx or {})[:400])
        with open(LOG, "a") as f:
            f.write(line)
    except OSError:
        pass


def fsize(p):
    try:
        return os.path.getsize(p)
    except OSError:
        return 0


def compact_notes(db_path, keep=80):
    if not os.path.isfile(db_path):
        return {"skipped": "no db"}
    try:
        db = sqlite3.connect(db_path)
    except Exception as e:
        return {"error": str(e)[:120]}
    try:
        tables = [r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")]
        if "notes" not in tables:
            return {"skipped": "no notes table"}
        n = db.execute("SELECT COUNT(*) FROM notes").fetchone()[0]
        if n <= keep:
            return {"ok": True, "rows": n, "pruned": 0}
        old = db.execute(
            "SELECT key FROM notes ORDER BY updated_at ASC LIMIT ?", (n - keep,)
        ).fetchall()
        db.executemany("DELETE FROM notes WHERE key=?", old)
        db.commit()
        try:
            db.execute("VACUUM")
        except Exception:
            pass
        return {"ok": True, "rows": keep, "pruned": len(old)}
    except Exception as e:
        return {"error": str(e)[:200]}
    finally:
        try:
            db.close()
        except Exception:
            pass


def summarize_session(path, max_chars=1500):
    try:
        with open(path) as f:
            data = json.load(f)
    except Exception as e:
        return {"error": str(e)[:120]}
    msgs = data.get("messages") if isinstance(data, dict) else data
    if not isinstance(msgs, list):
        return {"skipped": "no messages"}
    users, tools = [], []
    for m in msgs[-20:]:
        if not isinstance(m, dict):
            continue
        c = str(m.get("content") or "").strip()
        if not c:
            continue
        if m.get("role") == "user":
            users.append(c[:200])
        elif m.get("role") == "tool":
            tools.append("[tool] " + c[:120])
    summary = ""
    if users:
        summary += "User: " + " | ".join(users[-3:])
    if tools:
        summary += "\nTool: " + " | ".join(tools[-4:])
    summary = summary[:max_chars]
    out = path + ".summary.json"
    try:
        with open(out, "w") as f:
            json.dump({"ts": int(time.time()), "summary": summary}, f)
    except OSError as e:
        return {"error": str(e)[:120]}
    return {"ok": True, "summary_len": len(summary), "out": os.path.basename(out)}


def check(threshold_kb=50):
    thr = threshold_kb * 1024
    targets = {
        "notes.db": os.path.join(ROOT, "notes.db"),
        "autosave": os.path.join(ROOT, "sessions", "autosave.json"),
        "app_today": os.path.join(ROOT, "logs", "app-%s.log" % time.strftime("%Y%m%d")),
    }
    for fn in sorted(os.listdir(os.path.join(ROOT, "sessions"))):
        if fn.endswith(".json") and not fn.endswith(".summary.json"):
            targets["sess/" + fn] = os.path.join(ROOT, "sessions", fn)
    over = {k: fsize(p) for k, p in targets.items() if fsize(p) > thr}
    return {"threshold_kb": threshold_kb, "over": over, "need_run": bool(over)}


def main(argv):
    thr = cfg_int("AI_SUMMARIZE_THRESHOLD_KB", 50)
    force = "--force" in argv
    st = check(thr)
    if not st["need_run"] and not force:
        print(json.dumps({"ok": True, "action": "none", **st}))
        return 0
    res = {"notes": compact_notes(os.path.join(ROOT, "notes.db"))}
    for k in list(st["over"]):
        if k.startswith("sess/") or k == "autosave":
            p = os.path.join(ROOT, "sessions", os.path.basename(k))
            if os.path.isfile(p):
                res[k] = summarize_session(p)
    log("summarize run", {"over": st["over"], "res": res})
    print(json.dumps({"ok": True, "action": "summarized", "over": st["over"], "res": res})[:2000])
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
