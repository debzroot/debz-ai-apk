#!/bin/bash
# Heartbeat provider AI tiap 5 mnt: cek endpoint aktif, tulis logs/provider-heartbeat.json
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$SCRIPT_DIR/logs/provider-heartbeat.json"
TMP="$OUT.tmp"
PROV="$SCRIPT_DIR/.ai-providers.json"
python3 - "$PROV" "$TMP" <<'PYEOF'
import json, sys, time, urllib.request
prov_file, tmp = sys.argv[1], sys.argv[2]
try:
    d = json.load(open(prov_file, encoding="utf-8"))
except Exception as e:
    json.dump({"ok": False, "ts": int(time.time()), "error": f"read providers: {e}"}, open(tmp, "w"))
    raise SystemExit(0)
ids = []
act = d.get("active") or ""
if act:
    ids.append(act)
for k in ("active_cli", "active_webui"):
    v = d.get(k)
    if v and v not in ids:
        ids.append(v)
for pid, p in (d.get("providers") or {}).items():
    if p.get("enabled") and pid not in ids:
        ids.append(pid)
    if len(ids) >= 5:
        break
res = {"ok": True, "ts": int(time.time()), "checks": []}
for pid in ids[:5]:
    p = (d.get("providers") or {}).get(pid) or {}
    base = str(p.get("base_url", "")).rstrip("/")
    mode = str(p.get("mode", ""))
    key = str(p.get("api_key", ""))
    url = base + "/models" if base else ""
    t0 = time.time()
    entry = {"id": pid, "mode": mode, "url": url, "ok": False, "ms": 0, "code": 0}
    if not url.startswith("http"):
        entry["error"] = "base_url invalid"
        res["checks"].append(entry)
        continue
    try:
        req = urllib.request.Request(url, headers={"Authorization": f"Bearer {key}"} if key else {"User-Agent": "debz-heartbeat/1.0"})
        with urllib.request.urlopen(req, timeout=8) as r:
            entry["code"] = getattr(r, "status", 200)
            entry["ok"] = entry["code"] < 500
    except Exception as e:
        msg = str(e)
        entry["error"] = msg[:160]
        if "HTTP Error 401" in msg or "HTTP Error 403" in msg:
            entry["ok"] = True
            entry["code"] = 401 if "401" in msg else 403
            entry["note"] = "reach ok (auth required)"
    entry["ms"] = int((time.time() - t0) * 1000)
    res["checks"].append(entry)
res["ok"] = any(c.get("ok") for c in res["checks"])
json.dump(res, open(tmp, "w"))
PYEOF
mv -f "$TMP" "$OUT" 2>/dev/null
echo "[$(date '+%F %T')] heartbeat: $(cat "$OUT" 2>/dev/null | head -c 220)" >> "$SCRIPT_DIR/logs/provider-heartbeat.log"
