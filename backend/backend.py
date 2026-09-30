#!/usr/bin/env python3

import os
import json
import re
import secrets
import sys
import subprocess
import requests


import socket as _socket_doh
import struct as _struct_doh
import random as _random_doh

_doh_orig_getaddrinfo = _socket_doh.getaddrinfo

def _doh_query_a(name):
    """Query DNS A record via UDP ke 8.8.8.8:53, return list IP (string)."""
    tid = _random_doh.randint(0, 0xFFFF)
    hdr = _struct_doh.pack('>HHHHHH', tid, 0x0100, 1, 0, 0, 0)
    q = b''.join(bytes([len(p)]) + p.encode() for p in name.split('.')) + b'\x00'
    qtype = _struct_doh.pack('>HH', 1, 1)
    pkt = hdr + q + qtype
    try:
        s = _socket_doh.socket(_socket_doh.AF_INET, _socket_doh.SOCK_DGRAM)
        s.settimeout(3)
        s.sendto(pkt, ('8.8.8.8', 53))
        data, _ = s.recvfrom(4096)
        s.close()
        if len(data) < 12:
            return []
        ancount = _struct_doh.unpack('>H', data[6:8])[0]
        off = 12

        while off < len(data):
            l = data[off]
            if l == 0:
                off += 1
                break
            off += l + 1
        off += 4
        ips = []
        for _ in range(ancount):
            if off + 2 > len(data):
                break

            off += 2
            if off + 10 > len(data):
                break
            rtype, rclass, ttl, rdlen = _struct_doh.unpack('>HHIH', data[off:off+10])
            off += 10
            if rtype == 1 and rdlen == 4 and off + 4 <= len(data):
                ips.append(_socket_doh.inet_ntoa(data[off:off+4]))
            off += rdlen
        return ips
    except Exception:
        return []

def _doh_getaddrinfo(host, port=None, family=0, type=0, proto=0, flags=0):
    try:
        ips = _doh_query_a(host)
        if ips:
            results = []
            for ip in ips:
                try:
                    results.append((_socket_doh.AF_INET, _socket_doh.SOCK_STREAM, 6, '', (ip, port or 0)))
                except Exception:
                    pass
            if results:
                return results
    except Exception:
        pass
    return _doh_orig_getaddrinfo(host, port, family, type, proto, flags)

_socket_doh.getaddrinfo = _doh_getaddrinfo

import uuid
import shutil as _shutil
import sqlite3 as _sqlite3
import time as _time
import threading as _threading
import html as _html
import urllib.parse as _urlparse
from pathlib import Path
from typing import Optional

from flask_cors import CORS
from flask import Flask, request, jsonify
from flask_sock import Sock

app = Flask("c0n73xt-tool-server")
CORS(app)
sock = Sock(app)

CONFIG_FILE = Path(__file__).resolve().parent / ".ai-config.ini"

def load_token() -> str:
    try:
        for line in CONFIG_FILE.read_text().splitlines():
            line = line.strip()
            if line.startswith("AI_TOOLS_TOKEN") and "=" in line:
                return line.split("=", 1)[1].strip()
    except Exception:
        pass
    return ""


TOKENS = [
    t.strip() for t in (os.environ.get("TOOLS_TOKEN", "") or load_token()).split(",")
    if t.strip()
]

if not TOKENS:
    print("[tool-server] FATAL: AI_TOOLS_TOKEN kosong di .ai-config.ini", file=sys.stderr)
    sys.exit(1)

def check_token(supplied: str) -> bool:
    if not supplied:
        return False
    return any(secrets.compare_digest(supplied, t) for t in TOKENS)

DANGER_PATTERNS = [
    r"\brm\s+(-[a-z]*r[a-z]*f?|--recursive)\b",
    r"\brm\s+[^|;&]*\s/\S*",
    r"\b(dd|mkfs(\.\w+)?|fdisk|parted|wipefs)\b",
    r"\b(shutdown|reboot|halt|poweroff|init\s+[06])\b",
    r"\bchmod\s+-R\s+777\s+/\b",
    r"\bchown\s+-R\b.*\s/\b",
    r"\bmv\s+[^|;&]*\s/\s*$",
    r">\s*/dev/sd[a-z]",
    r"\biptables\b.*-F", r"\bnft\b.*flush",
    r"curl[^|]*\|\s*(ba)?sh",
    r"wget[^|]*\|\s*(ba)?sh",
]
DANGER_RE = [re.compile(p, re.IGNORECASE) for p in DANGER_PATTERNS]

def is_dangerous(command: str) -> Optional[str]:
    for i, rx in enumerate(DANGER_RE):
        m = rx.search(command)
        if m:
            return f"match rule #{i + 1}: {m.group(0)!r}"
    return None


@app.get("/api/health")
def health():
    return jsonify({"status": "healthy", "service": "c0n73xt-tool-server", "version": "2.0-flask"})

def _auth() -> Optional[tuple]:
    body = {}
    try:
        body = request.get_json(silent=True) or {}
    except Exception:
        pass
    supplied = (
        (body.get("token") if isinstance(body, dict) else None)
        or request.headers.get("x-tools-token")
        or request.args.get("token")
        or ""
    )
    if not check_token(str(supplied)):
        return jsonify({"error": "invalid token"}), 403
    return None


@app.post("/api/exec")
def api_exec():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    command = str(body.get("command", "")).strip()
    cwd = str(body.get("cwd", "")).strip() or os.getcwd()
    timeout = min(int(body.get("timeout", 60) or 60), 600)
    approved = bool(body.get("approved", False))

    if not command:
        return jsonify({"error": "command kosong"}), 400
    if not os.path.isdir(cwd):
        return jsonify({"error": f"cwd gak ada: {cwd}"}), 400

    why = is_dangerous(command)
    if why and not approved:
        return jsonify(
            {"need_approval": True, "command": command, "reason": why}
        ), 202

    _cmd_lower = command.lower()
    _debz_ai = _DEBZ_AI_ROOT.lower()
    if _debz_ai in _cmd_lower or _cmd_lower.startswith(("cp ", "mv ", "tee ", "cat ", "cp	", "mv	")):
        _anomaly_hints = [".bak", ".bak-", "tmp_", "session_", ".orig", "tmp_tooltest", "tmp_extract"]
        if any(h in _cmd_lower for h in _anomaly_hints):
            if not approved:
                return jsonify({
                    "need_approval": True, "command": command,
                    "reason": f"[CLEAN PATH] Command kemungkinan membuat file anomali di ~/debz-ai/. Rules AGENTS.md: file backup/tmp harus ke ~/Workspaces/. Set approved=true kalau memang mau lanjut."
                }), 202

    try:
        res = subprocess.run(
            command,
            shell=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            cwd=cwd,
            timeout=timeout,

            env={**os.environ, "TERM": "xterm-256color", "HOME": DEBZ_HOME}
        )

        return jsonify({
            "stdout": res.stdout.decode("utf-8", errors="replace")[:65536],
            "stderr": res.stderr.decode("utf-8", errors="replace")[:16384],
            "exit_code": res.returncode,
            "command": command,
        })
    except subprocess.TimeoutExpired as e:
        return jsonify({
            "stdout": (e.stdout.decode("utf-8", errors="replace")[:65536] if e.stdout else ""),
            "stderr": f"timeout {timeout}s — proses dibunuh",
            "exit_code": -1
        }), 200
    except Exception as e:
        return jsonify({"stdout": "", "stderr": str(e), "exit_code": -1}), 200


@app.post("/api/fs_read")
def api_fs_read():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    path = _expand_home(str(body.get("path", "")).strip())
    if not path or not os.path.isfile(path):
        return jsonify({"error": f"file gak ada: {path}"}), 404
    if os.path.getsize(path) > 2 * 1024 * 1024:
        return jsonify({"error": "file gede banget (>2MB), pake exec + head/tail"}), 413
    try:
        text = Path(path).read_text(errors="replace")
        return jsonify({"path": path, "content": text, "bytes": len(text)})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


_DEBZ_AI_ROOT = str(Path(__file__).resolve().parent)


DEBZ_HOME = str(Path(_DEBZ_AI_ROOT).parent)

def _expand_home(path: str) -> str:
    """Paksa expand '~' / '~/' selalu ke Debz Home, abaikan user root."""
    if not path:
        return path
    if path == "~":
        return DEBZ_HOME
    if path.startswith("~/"):
        return os.path.join(DEBZ_HOME, path[2:])
    return path

_CLEAN_PATH_BANNED_PATTERNS = (
    ".bak", ".bak-", ".bak2", ".orig", ".tmp",
    "tmp_tooltest", "tmp_extract", "tmp_",
)
_CLEAN_PATH_BANNED_GLOBS = (
    "session_*.json",
    "dns_local.*",
    "screenshots/shot_*.png",
)

def _is_debz_ai_path(path: str) -> bool:
    """Cek apakah path resolve ke dalam ~/debz-ai/"""
    try:
        resolved = os.path.realpath(_expand_home(path))
        return resolved.startswith(_DEBZ_AI_ROOT)
    except Exception:
        return False

def _is_banned_filename(path: str) -> str | None:
    """Cek apakah filename termasuk yang dilarang di ~/debz-ai/. Return alasan jika melanggar."""
    import fnmatch
    basename = os.path.basename(path).lower()

    for pat in _CLEAN_PATH_BANNED_PATTERNS:
        if pat in basename:
            return f"File '{basename}' termasuk anomali (pattern: '{pat}') — tidak boleh di ~/debz-ai/. Simpan ke ~/Workspaces/ sesuai rules AGENTS.md."

    for g in _CLEAN_PATH_BANNED_GLOBS:
        if fnmatch.fnmatch(basename, g.lower()):
            return f"File '{basename}' termasuk anomali (glob: '{g}') — tidak boleh di ~/debz-ai/. Simpan ke ~/Workspaces/ sesuai rules AGENTS.md."
    return None

@app.post("/api/fs_write")
def api_fs_write():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    path = _expand_home(str(body.get("path", "")).strip())
    content = str(body.get("content", ""))
    append = bool(body.get("append", False))
    if not path:
        return jsonify({"error": "path kosong"}), 400

    if _is_debz_ai_path(path):
        ban = _is_banned_filename(path)
        if ban:
            return jsonify({"error": ban, "hint": "Pindahkan output ke ~/Workspaces/<nama_project>/ sesuai rules AGENTS.md."}), 403
    try:
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        if append:
            with open(p, "a", encoding="utf-8") as f:
                f.write(content)
        else:
            p.write_text(content, encoding="utf-8")
        return jsonify({"path": path, "bytes_written": len(content), "append": append})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.post("/api/fs_list")
def api_fs_list():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    path = _expand_home(str(body.get("path", "/").strip() or "/"))
    if not os.path.isdir(path):
        return jsonify({"error": f"folder gak ada: {path}"}), 404
    try:
        items = []
        with os.scandir(path) as it:
            for entry in it:
                try:
                    st = entry.stat()
                    items.append({
                        "name": entry.name,
                        "dir": entry.is_dir(),
                        "size": st.st_size if not entry.is_dir() else None,
                    })
                except Exception:
                    continue
        items.sort(key=lambda x: (not x["dir"], x["name"].lower()))
        return jsonify({"path": path, "total_count": len(items), "entries": items[:2000]})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.post("/api/fs_search")
def api_fs_search():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    path = _expand_home(str(body.get("path", _DEBZ_AI_ROOT).strip() or _DEBZ_AI_ROOT))
    pattern = str(body.get("pattern", "")).strip()
    in_content = bool(body.get("content", False))
    if not pattern:
        return jsonify({"error": "pattern kosong"}), 400
    if not os.path.isdir(path):
        return jsonify({"error": f"folder gak ada: {path}"}), 404

    try:
        rx_name = re.compile(pattern, re.IGNORECASE)
    except re.error:
        rx_name = re.compile(re.escape(pattern), re.IGNORECASE)

    SKIP_DIRS = {".git", "node_modules", "__pycache__", "ble.sh", ".cache", "proc", "sys"}
    results = []
    LIMIT = 300
    try:
        for root, dirs, files in os.walk(path):
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
            for name in files:
                if len(results) >= LIMIT:
                    return jsonify({"path": path, "pattern": pattern, "truncated": True, "results": results})
                fpath = os.path.join(root, name)
                if rx_name.search(name):
                    results.append({"path": fpath, "match": "name"})
                    continue
                if in_content:
                    try:
                        if os.path.getsize(fpath) > 512 * 1024:
                            continue
                        with open(fpath, "r", errors="ignore") as f:
                            text = f.read()
                        m = rx_name.search(text)
                        if m:
                            results.append({"path": fpath, "match": "content"})
                    except Exception:
                        continue
        return jsonify({"path": path, "pattern": pattern, "truncated": False, "results": results})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.post("/api/http")
def api_http():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    url = str(body.get("url", "")).strip()
    method = str(body.get("method", "GET")).upper()[:8]
    headers = body.get("headers") or {}
    data = body.get("body")
    timeout = min(max(int(body.get("timeout", 20) or 20), 1), 60)
    follow = bool(body.get("follow", True))
    if not url:
        return jsonify({"error": "url kosong"}), 400
    if not url.lower().startswith(("http://", "https://")):
        return jsonify({"error": "url harus http(s)://"}), 400
    cmd = ["curl", "-s", "-S", "--doh-url", "https://dns.google/dns-query", "--resolve", "dns.google:443:8.8.8.8", "-m", str(timeout), "-X", method, "-o", "-", "-w", "\n%{http_code}"]
    if follow:
        cmd.insert(1, "-L")
    for k, v in (headers or {}).items():
        cmd += ["-H", "{k}: {v}".format(k=k, v=v)]
    if data is not None:
        cmd += ["--data", str(data)]
        if method == "GET":
            cmd[cmd.index("-X") + 1] = "POST"
    cmd.append(url)
    try:
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout + 10)
    except subprocess.TimeoutExpired:
        return jsonify({"error": "http timeout", "http_code": "408", "body": "", "bytes": 0}), 200
    except Exception as e:
        return jsonify({"error": str(e), "http_code": "000", "body": "", "bytes": 0}), 200
    raw = res.stdout.decode("utf-8", errors="replace")
    parts = raw.rsplit("\n", 1)
    code = parts[1].strip() if len(parts) > 1 else "000"
    txt = parts[0] if len(parts) > 1 else raw
    truncated = False
    if len(txt) > 262144:
        txt = txt[:262144] + "\n...[truncated]"
        truncated = True
    err = res.stderr.decode("utf-8", errors="replace").strip()
    eff = method if (data is None or method != "GET") else "POST"
    return jsonify({
        "url": url, "method": eff, "http_code": code,
        "body": txt, "bytes": len(txt), "truncated": truncated,
        "curl_error": (err[:2000] or None),
    }), 200


_ACTIVE_DOWNLOADS = {}
_DOWNLOADS_LOCK = _threading.Lock()


@app.post("/api/download")
def api_download():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    url = str(body.get("url", "")).strip()
    path = str(body.get("path", "")).strip()
    timeout = min(max(int(body.get("timeout", 120) or 120), 5), 600)
    max_mb = int(body.get("max_mb", 200) or 200)
    dl_id = str(body.get("download_id", "")).strip() or uuid.uuid4().hex
    if not url or not path:
        return jsonify({"error": "url & path wajib"}), 400
    if not url.lower().startswith(("http://", "https://")):
        return jsonify({"error": "url harus http(s)://"}), 400
    try:
        p = Path(_expand_home(path))
        p.parent.mkdir(parents=True, exist_ok=True)
        if p.is_dir():
            return jsonify({"error": "path adalah folder, kasih nama file"}), 400
        cmd = ["curl", "-s", "-S", "-L", "-f", "--doh-url", "https://dns.google/dns-query", "--resolve", "dns.google:443:8.8.8.8", "--max-filesize", str(max_mb * 1024 * 1024),
               "-m", str(timeout), "-o", str(p), "-w", "%{http_code}", url]
        proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        cancelled = _threading.Event()
        with _DOWNLOADS_LOCK:
            _ACTIVE_DOWNLOADS[dl_id] = {"proc": proc, "path": str(p), "cancelled": cancelled}
        try:
            deadline = _time.time() + timeout + 15
            while True:
                if cancelled.is_set():
                    try: proc.kill()
                    except Exception: pass
                    try: proc.wait(timeout=5)
                    except Exception: pass
                    try:
                        if p.exists(): p.unlink()
                    except Exception: pass
                    return jsonify({"error": "download dibatalkan (Ctrl+C)", "cancelled": True, "download_id": dl_id}), 200
                try:
                    proc.wait(timeout=0.5)
                    break
                except subprocess.TimeoutExpired:
                    if _time.time() > deadline:
                        try: proc.kill()
                        except Exception: pass
                        try: proc.wait(timeout=5)
                        except Exception: pass
                        try:
                            if p.exists(): p.unlink()
                        except Exception: pass
                        return jsonify({"error": "download timeout"}), 504
        finally:
            with _DOWNLOADS_LOCK:
                _ACTIVE_DOWNLOADS.pop(dl_id, None)
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    stdout, stderr = proc.communicate()
    code = stdout.decode("utf-8", errors="replace").strip() or "000"
    err = stderr.decode("utf-8", errors="replace").strip()
    bytes_saved = p.stat().st_size if p.exists() else 0
    out = {"path": str(p), "http_code": code, "bytes": bytes_saved, "download_id": dl_id}
    if err:
        out["error"] = err[:2000]
    return jsonify(out), 200


@app.post("/api/download_cancel")
def api_download_cancel():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    dl_id = str(body.get("download_id", "")).strip()
    if not dl_id:
        return jsonify({"error": "download_id kosong"}), 400
    with _DOWNLOADS_LOCK:
        entry = _ACTIVE_DOWNLOADS.get(dl_id)
        if not entry:
            return jsonify({"error": "download tidak ditemukan / sudah selesai", "cancelled": False}), 200
        entry["cancelled"].set()

    return jsonify({"cancelled": True, "download_id": dl_id}), 200


@app.post("/api/db")
def api_db():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    db_path = _expand_home(str(body.get("db_path", "")).strip())
    sql = str(body.get("sql", "")).strip()
    params = body.get("params") or []
    row_limit = min(max(int(body.get("limit", 500) or 500), 1), 5000)
    if not db_path:
        return jsonify({"error": "db_path kosong"}), 400
    if not sql:
        return jsonify({"error": "sql kosong"}), 400
    try:
        conn = _sqlite3.connect(db_path, timeout=5)
        conn.row_factory = _sqlite3.Row
        cur = conn.cursor()
        if isinstance(params, dict):
            cur.execute(sql, params)
        elif isinstance(params, list):
            cur.execute(sql, params)
        else:
            cur.execute(sql)
        low = sql.strip().lower()
        if low.startswith(("select", "pragma", "explain", "with", "show")):
            rows = cur.fetchmany(row_limit)
            cols = [d[0] for d in (cur.description or [])]
            data = [dict(zip(cols, r)) if cols else None for r in rows]
            conn.close()
            return jsonify({"columns": cols, "rows": data, "count": len(data), "truncated": len(data) >= row_limit}), 200
        conn.commit()
        affected = cur.rowcount
        lastrow = cur.lastrowid
        conn.close()
        return jsonify({"affected": affected, "lastrowid": lastrow, "ok": True}), 200
    except Exception as e:
        try:
            conn.close()
        except Exception:
            pass
        return jsonify({"error": str(e)}), 500


@app.post("/api/archive")
def api_archive():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "")).lower()
    archive_path = str(body.get("archive_path", "")).strip()
    target_dir = str(body.get("target_dir", "")).strip()
    if action not in ("create", "extract"):
        return jsonify({"error": "action harus create/extract"}), 400
    if not archive_path:
        return jsonify({"error": "archive_path kosong"}), 400
    try:
        if action == "extract":
            if not os.path.isfile(archive_path):
                return jsonify({"error": "file arsip gak ada: " + archive_path}), 404
            dest = target_dir or os.path.dirname(archive_path) or "."
            os.makedirs(dest, exist_ok=True)
            if archive_path.endswith((".zip", ".apk")):
                import zipfile
                with zipfile.ZipFile(archive_path) as z:
                    infos = z.infolist()
                    z.extractall(dest)
                return jsonify({"action": "extract", "format": "zip", "entries": len(infos), "dest": dest}), 200
            import tarfile
            with tarfile.open(archive_path) as t:
                members = t.getmembers()
                t.extractall(dest)
            return jsonify({"action": "extract", "format": "tar", "entries": len(members), "dest": dest}), 200
        files = body.get("files") or []
        if not isinstance(files, list) or not files:
            return jsonify({"error": "files (list path) wajib buat create"}), 400
        base_dir = str(body.get("base_dir", "")).strip() or None
        os.makedirs(os.path.dirname(archive_path) or ".", exist_ok=True)
        if archive_path.endswith(".zip"):
            import zipfile
            with zipfile.ZipFile(archive_path, "w", zipfile.ZIP_DEFLATED) as z:
                for f in files:
                    f = str(f)
                    if not os.path.exists(f):
                        continue
                    arc = os.path.relpath(f, base_dir) if base_dir else os.path.basename(f)
                    z.write(f, arc)
            return jsonify({"action": "create", "format": "zip", "archive": archive_path}), 200
        import tarfile
        mode = "w:gz" if archive_path.endswith((".tar.gz", ".tgz")) else ("w:bz2" if archive_path.endswith(".tar.bz2") else "w")
        with tarfile.open(archive_path, mode) as t:
            for f in files:
                f = str(f)
                if not os.path.exists(f):
                    continue
                arc = os.path.relpath(f, base_dir) if base_dir else os.path.basename(f)
                t.add(f, arcname=arc)
        fmt = "tar" + ("-gz" if archive_path.endswith((".tar.gz", ".tgz")) else ("-bz2" if archive_path.endswith(".tar.bz2") else ""))
        return jsonify({"action": "create", "format": fmt, "archive": archive_path}), 200
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.post("/api/ps")
def api_ps():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    pattern = str(body.get("pattern", "")).strip()
    try:
        res = subprocess.run(["ps", "-e", "-o", "pid,user,args"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
        if res.returncode != 0:
            res = subprocess.run(["ps", "aux"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
        text = res.stdout.decode("utf-8", errors="replace")
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    lines = text.splitlines()
    if not lines:
        return jsonify({"processes": [], "total": 0}), 200
    header = lines[0]
    procs = []
    for line in lines[1:]:
        cols = line.split(None, 2)
        if len(cols) < 2:
            continue
        pid, user = cols[0], cols[1]
        cmd = cols[2] if len(cols) > 2 else ""
        if pattern and pattern.lower() not in (cmd + " " + pid).lower():
            continue
        procs.append({"pid": pid, "user": user, "cmd": cmd[:300]})
    return jsonify({"processes": procs, "total": len(procs), "header": header}), 200


@app.post("/api/kill")
def api_kill():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    pid = body.get("pid")
    pattern = str(body.get("pattern", "")).strip()
    sig = int(body.get("signal", 15) or 15)
    if sig not in (1, 2, 9, 15):
        return jsonify({"error": "signal harus 1/2/9/15"}), 400
    self_pid = os.getpid()
    targets = []
    if pid is not None:
        pid = int(pid)
        if pid <= 1 or pid == self_pid:
            return jsonify({"error": "gak boleh kill proses penting itu"}), 400
        targets = [str(pid)]
    elif pattern:
        try:
            res = subprocess.run(["ps", "-e", "-o", "pid,args"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
            text = res.stdout.decode("utf-8", errors="replace") if res.returncode == 0 else ""
        except Exception:
            text = ""
        for line in text.splitlines()[1:]:
            cols = line.split(None, 1)
            if len(cols) < 2:
                continue
            p, cmd = cols[0], cols[1]
            if pattern.lower() in cmd.lower() and p not in ("1", str(self_pid)):
                targets.append(p)
    else:
        return jsonify({"error": "kasih pid atau pattern"}), 400
    results = []
    for p in targets:
        try:
            os.kill(int(p), sig)
            results.append({"pid": p, "ok": True, "signal": sig})
        except Exception as e:
            results.append({"pid": p, "ok": False, "error": str(e)})
    return jsonify({"killed": results, "signal": sig}), 200


_NOTES_DB = str(Path(__file__).resolve().parent / "notes.db")

def _notes_conn():
    conn = _sqlite3.connect(_NOTES_DB, timeout=5)
    conn.execute("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT UNIQUE, content TEXT, updated_at TEXT DEFAULT (datetime('now')))")
    conn.commit()
    return conn


@app.post("/api/note")
def api_note():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "list")).lower()
    key = str(body.get("key", "")).strip()
    content = str(body.get("content", ""))
    pattern = str(body.get("pattern", "")).strip()
    try:
        conn = _notes_conn()
        if action == "add":
            if not key:
                conn.close()
                return jsonify({"error": "key wajib"}), 400
            conn.execute("INSERT INTO notes (key, content) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET content=excluded.content, updated_at=datetime('now')", (key, content))
            conn.commit()
            conn.close()
            return jsonify({"ok": True, "key": key, "bytes": len(content)}), 200
        if action == "get":
            cur = conn.execute("SELECT key, content, updated_at FROM notes WHERE key=?", (key,))
            row = cur.fetchone()
            conn.close()
            if not row:
                return jsonify({"error": "note tidak ada: " + key}), 404
            return jsonify({"key": row[0], "content": row[1], "updated_at": row[2]}), 200
        if action == "delete":
            cur = conn.execute("DELETE FROM notes WHERE key=?", (key,))
            conn.commit()
            conn.close()
            return jsonify({"ok": True, "deleted": cur.rowcount}), 200
        if action == "search":
            cur = conn.execute("SELECT key, content, updated_at FROM notes WHERE key LIKE ? OR content LIKE ? ORDER BY updated_at DESC LIMIT 20", (f"%{pattern}%", f"%{pattern}%"))
            rows = cur.fetchall()
            conn.close()
            return jsonify({"results": [{"key": r[0], "content": r[1][:500], "updated_at": r[2]} for r in rows], "count": len(rows)}), 200
        cur = conn.execute("SELECT key, length(content) AS len, updated_at FROM notes ORDER BY updated_at DESC LIMIT 100")
        rows = cur.fetchall()
        conn.close()
        return jsonify({"notes": [{"key": r[0], "bytes": r[1], "updated_at": r[2]} for r in rows], "count": len(rows)}), 200
    except Exception as e:
        try:
            conn.close()
        except Exception:
            pass
        return jsonify({"error": str(e)}), 500


_SKILLS_DIR = Path(__file__).resolve().parent / "skills"

def _skill_all_files():
    """Scan semua SKILL.md di skills/ (rekursif)."""
    if not _SKILLS_DIR.is_dir():
        return []
    return sorted(_SKILLS_DIR.rglob("SKILL.md"))

def _skill_fm(path: Path) -> dict:
    """Parse YAML frontmatter sederhana (blok --- ---)."""
    meta = {}
    try:
        raw = path.read_text(encoding="utf-8", errors="replace")
    except Exception:
        raw = ""
    if raw.startswith("---"):
        end = raw.find("\n---", 3)
        if end != -1:
            for line in raw[3:end].splitlines():
                line = line.strip()
                if not line or line.startswith("#") or ":" not in line:
                    continue
                k, v = line.split(":", 1)
                meta[k.strip()] = v.strip().strip('"').strip("'")
    return meta

def _skill_entry(path: Path) -> dict:
    rel = path.relative_to(_SKILLS_DIR)
    parts = list(rel.parts[:-1])
    name = path.parent.name
    category = parts[0] if parts else "uncategorized"
    fm = _skill_fm(path)
    return {
        "name": fm.get("name", name),
        "category": category,
        "path": str(rel.parent),
        "description": fm.get("description", ""),
        "tags": fm.get("tags", ""),
        "version": fm.get("version", ""),
        "bytes": path.stat().st_size if path.exists() else 0,
    }

def _skill_search(q: str, limit: int = 8):
    """Cari skill by keyword di name/desc/tags/path. Ranking token sederhana."""
    q = (q or "").lower()[:300]
    if not q:
        return []
    scored = []
    for p in _skill_all_files():
        e = _skill_entry(p)
        hay = " ".join([e["name"], e["category"], e["description"], e["tags"], e["path"]]).lower()
        score = 0
        for tok in q.split():
            if len(tok) >= 3 and tok in hay:
                score += 1
        if q in e["name"].lower():
            score += 4
        if q in e["category"].lower():
            score += 2
        if score > 0:
            scored.append((score, e))
    scored.sort(key=lambda x: (-x[0], x[1]["name"]))
    return [e for _, e in scored[:limit]]

@app.post("/api/skill")
def api_skill():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "list")).lower()
    name = str(body.get("name", "")).strip()
    category = str(body.get("category", "")).strip() or "custom"
    content = str(body.get("content", ""))
    pattern = str(body.get("pattern", "")).strip()
    try:
        if action == "search":
            if not pattern:
                return jsonify({"error": "pattern wajib buat search"}), 400
            res = _skill_search(pattern)
            return jsonify({"results": res, "count": len(res)}), 200
        if action == "get":
            if not name:
                return jsonify({"error": "name wajib"}), 400
            for p in _skill_all_files():
                e = _skill_entry(p)
                if e["name"] == name or str(p.parent.name) == name:
                    return jsonify({"name": e["name"], "category": e["category"],
                                    "content": p.read_text(encoding="utf-8", errors="replace"),
                                    "bytes": e["bytes"]}), 200
            return jsonify({"error": "skill tidak ada: " + name}), 404
        if action == "create":
            if not name:
                return jsonify({"error": "name wajib"}), 400
            if not content:
                return jsonify({"error": "content wajib (isi SKILL.md)"}), 400
            safe_name = re.sub(r"[^a-z0-9-]+", "-", name.lower()).strip("-")
            if not safe_name:
                return jsonify({"error": "name gak valid"}), 400
            cat = re.sub(r"[^a-z0-9-]+", "-", category.lower()).strip("-") or "custom"
            target = _SKILLS_DIR / cat / safe_name / "SKILL.md"
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")
            return jsonify({"ok": True, "path": str(target.relative_to(_SKILLS_DIR)),
                            "bytes": len(content)}), 200
        if action == "delete":
            if not name:
                return jsonify({"error": "name wajib"}), 400
            for p in _skill_all_files():
                e = _skill_entry(p)
                if e["name"] == name or str(p.parent.name) == name:
                    _shutil.rmtree(p.parent, ignore_errors=True)
                    return jsonify({"ok": True, "deleted": e["name"]}), 200
            return jsonify({"error": "skill tidak ada: " + name}), 404
        if action == "stats":
            allf = _skill_all_files()
            cats = {}
            for p in allf:
                e = _skill_entry(p)
                cats[e["category"]] = cats.get(e["category"], 0) + 1
            return jsonify({"total": len(allf), "categories": len(cats),
                            "by_category": cats}), 200

        entries = [_skill_entry(p) for p in _skill_all_files()]
        cats = {}
        for e in entries:
            cats.setdefault(e["category"], []).append(e)
        return jsonify({"skills": entries, "count": len(entries),
                        "by_category": cats}), 200
    except Exception as e:
        return jsonify({"error": str(e)}), 500

def _pkg_mgr():
    for m in ("apk", "pkg", "apt-get", "apt"):
        p = _shutil.which(m)
        if p:
            return m, p
    return None, None


@app.post("/api/pkg")
def api_pkg():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "search")).lower()
    package = str(body.get("package", "")).strip()
    timeout = 120 if action in ("install", "remove", "update") else 30
    mgr, mgr_path = _pkg_mgr()
    if not mgr:
        return jsonify({"error": "gak ada package manager (apk/pkg) di sistem ini"}), 501
    if mgr == "apk":
        cmds = {
            "search": ["apk", "search", package],
            "install": ["apk", "add", package],
            "remove": ["apk", "del", package],
            "update": ["apk", "update"],
            "installed": ["apk", "info"],
        }.get(action, ["apk", "info"])
    elif mgr == "pkg":
        cmds = {
            "search": [mgr, "search", package],
            "install": [mgr, "install", "-y", package],
            "remove": [mgr, "uninstall", "-y", package],
            "update": [mgr, "update"],
            "installed": [mgr, "list-installed"],
        }.get(action, [mgr, "list-installed"])
    else:
        cmds = {
            "search": [mgr, "search", package],
            "install": [mgr, "install", "-y", package],
            "remove": [mgr, "remove", "-y", package],
            "update": [mgr, "update"],
            "installed": [mgr, "list", "--installed"],
        }.get(action, [mgr, "list", "--installed"])
    if action in ("search", "install", "remove") and not package:
        return jsonify({"error": "package wajib"}), 400
    try:
        res = subprocess.run(cmds, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, env={**os.environ, "TERM": "dumb"})
    except subprocess.TimeoutExpired:
        return jsonify({"error": action + " timeout " + str(timeout) + "s"}), 504
    out = res.stdout.decode("utf-8", errors="replace")
    err = res.stderr.decode("utf-8", errors="replace")
    return jsonify({"mgr": mgr, "action": action, "package": package, "exit_code": res.returncode,
                    "stdout": out[:8192], "stderr": err[:4096]}), 200


_CUA_AVAILABLE = False
_cua = None
_cua_error_msg = ""

try:
    import cua_driver as _cua
    _cua.start_xvfb()
    _CUA_AVAILABLE = True
except Exception as e:
    _cua_error_msg = str(e)
    print(f"[tool-server] CUA disabled: {e}", file=sys.stderr)


@app.post("/api/cua")
def api_cua():
    deny = _auth()
    if deny:
        return deny
    if not _CUA_AVAILABLE:
        return jsonify({
            "ok": False,
            "error": f"CUA belum aktif — {_cua_error_msg}. Pastikan Xvfb, openbox, xdotool terinstall."
        }), 200
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "")).strip()
    try:
        res = _cua.run(action, body)
        return jsonify({"ok": res.get("ok", False), "action": action, "result": res.get("result") or res.get("error")}), 200
    except Exception as e:
        return jsonify({"ok": False, "action": action, "error": str(e)}), 200


@app.route("/api/screenshot", methods=["GET", "POST"])
def api_screenshot():
    deny = _auth()
    if deny:
        return deny
    if not _CUA_AVAILABLE:
        return jsonify({"ok": False, "error": "CUA belum aktif — install deps dulu"}), 500
    try:
        shot = _cua.screenshot()
        if not shot.get("ok"):
            return jsonify({"ok": False, "error": shot.get("error")}), 500
        return jsonify({"ok": True, "path": shot["path"], "width": shot["width"],
                        "height": shot["height"], "base64": shot["base64"]}), 200
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


_BROWSER_SHOT_DIR = os.path.join(DEBZ_HOME, "Workspaces/debz_ai_screenshots")

@app.post("/api/browser")
def api_browser():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    command = str(body.get("command", "")).strip()
    VALID = {"goto", "content", "text", "title", "screenshot", "click", "type", "press", "wait", "eval", "close"}
    if not command:
        return jsonify({"error": "command kosong: " + "|".join(sorted(VALID))}), 400
    if command not in VALID:
        return jsonify({"error": f"command tidak dikenal: {command}"}), 400

    args = [command]
    if command == "goto":
        url = str(body.get("url", "")).strip()
        if not url.startswith(("http://", "https://", "file://")):
            return jsonify({"error": "url harus http(s):// atau file://"}), 400
        args.append(url)
    elif command == "screenshot":
        p = str(body.get("path", "")).strip() or (_BROWSER_SHOT_DIR + "/browser_shot.png")
        args.append(p)
    elif command in ("click", "type"):
        sel = str(body.get("selector", "")).strip()
        if not sel:
            return jsonify({"error": "selector wajib untuk click/type"}), 400
        args.append(sel)
        if command == "click":
            args.append(str(int(body.get("index", 0) or 0)))
        else:
            args.append(str(body.get("text", "")))
    elif command == "press":
        args.append(str(body.get("key", "Enter")))
    elif command == "wait":
        args.append(str(int(body.get("ms", 1000) or 1000)))
    elif command == "eval":
        js = str(body.get("js", "")).strip()
        if not js:
            return jsonify({"error": "js wajib untuk eval"}), 400
        args.append(js)

    try:
        res = subprocess.run(
            ["python3", str(Path(__file__).resolve().parent / "browser.py"), *args],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=130,
        )
        stdout = res.stdout.decode("utf-8", errors="replace")
        stderr = res.stderr.decode("utf-8", errors="replace")
    except subprocess.TimeoutExpired:
        return jsonify({"ok": False, "command": command, "error": "browser timeout 130s"}), 200
    except Exception as e:
        return jsonify({"ok": False, "command": command, "error": str(e)}), 200

    data = {}
    try:
        data = json.loads(stdout) if stdout.strip() else {}
    except Exception:
        data = {"ok": False, "error": (stdout or stderr)[:2000]}

    if command == "screenshot" and data.get("ok"):
        path = data.get("path") or (args[1] if len(args) > 1 else "")
        if path and os.path.isfile(path):
            try:
                from PIL import Image
                import io as _io
                import base64 as _b64
                img = Image.open(path)
                img.thumbnail((1024, 1024))
                buf = _io.BytesIO()
                img.save(buf, "JPEG", quality=80)
                data["base64"] = "data:image/jpeg;base64," + _b64.b64encode(buf.getvalue()).decode()
                data["width"], data["height"] = img.size
            except Exception as e:
                data.setdefault("warn", f"base64 gagal: {e}")

    if not data.get("ok") and stderr:
        data.setdefault("error", stderr[:2000])
    data.setdefault("command", command)
    return jsonify(data), 200


import time as _time
import threading as _threading
import html as _html
import urllib.parse as _urlparse

BASE_DIR = Path(__file__).resolve().parent
LOG_DIR = BASE_DIR / "logs"
BACKUP_DIR = BASE_DIR / "backups"
SCHEDULER_FILE = BASE_DIR / ".scheduler.json"
DEBUG_LOG_FILE = BASE_DIR / "debug.log"

LOG_MAX_MB = int(os.environ.get("DEBZ_LOG_MAX_MB", "5"))
LOG_KEEP = int(os.environ.get("DEBZ_LOG_KEEP", "3"))
BACKUP_MAX_KEEP = int(os.environ.get("DEBZ_BACKUP_KEEP", "8"))


def _parse_ddg(html_text, max_results):
    out = []
    blocks = re.split(r'<div class="result\b', html_text)
    for blk in blocks[1:]:
        if len(out) >= max_results:
            break
        m = re.search(
            r'<a[^>]*class="result__a"[^>]*href="([^"]*)"[^>]*>(.*?)</a>',
            blk, re.DOTALL | re.IGNORECASE)
        if not m:
            continue
        href = m.group(1)
        title = re.sub(r"<[^>]+>", "", m.group(2))
        title = _html.unescape(title).strip()
        if "uddg=" in href:
            qs = _urlparse.parse_qs(_urlparse.urlparse(href).query)
            real = qs.get("uddg", [""])[0]
        else:
            real = href
        if not real.startswith(("http://", "https://")):
            real = "https://" + real.lstrip("/")
        sm = re.search(
            r'<a[^>]*class="result__snippet"[^>]*>(.*?)</a>',
            blk, re.DOTALL | re.IGNORECASE)
        snippet = re.sub(r"<[^>]+>", "", sm.group(1)) if sm else ""
        snippet = _html.unescape(snippet).strip()
        out.append({
            "title": title[:300],
            "url": real[:500],
            "snippet": snippet[:600],
        })
    return out


@app.post("/api/web_search")
def api_web_search():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    query = str(body.get("query", "")).strip()
    max_results = min(max(int(body.get("max_results", 6) or 6), 1), 15)
    timeout = min(max(int(body.get("timeout", 15) or 15), 5), 40)
    if not query:
        return jsonify({"error": "query kosong"}), 400
    url = "https://html.duckduckgo.com/html/?q=" + _urlparse.quote(query)
    headers = {
        "User-Agent": "Mozilla/5.0 (Linux; Android 13; Pixel 7) "
                      "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36"
    }
    try:
        r = requests.get(url, headers=headers, timeout=timeout)
    except Exception as e:
        return jsonify({"error": f"web search gagal: {e}"}), 502
    if r.status_code != 200:
        return jsonify({"error": f"web search HTTP {r.status_code}"}), 502
    results = _parse_ddg(r.text, max_results)
    if not results:

        try:
            r2 = requests.get(
                "https://lite.duckduckgo.com/lite/?q=" + _urlparse.quote(query),
                headers=headers, timeout=timeout)
            if r2.ok:
                results = _parse_ddg_lite(r2.text, max_results)
        except Exception:
            pass
    return jsonify({
        "query": query,
        "count": len(results),
        "results": results,
        "engine": "duckduckgo",
    }), 200


def _parse_ddg_lite(html_text, max_results):
    out = []

    lines = [l for l in html_text.splitlines() if l.strip()]
    for line in lines:
        if len(out) >= max_results:
            break
        m = re.search(r'<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>', line, re.DOTALL | re.IGNORECASE)
        if not m:
            continue
        href, title = m.group(1), re.sub(r"<[^>]+>", "", m.group(2)).strip()
        if not title or "duckduckgo" in href:
            continue
        if not href.startswith("http"):
            href = "https://" + href.lstrip("/")
        out.append({"title": title[:300], "url": href[:500], "snippet": ""})
    return out


BACKUP_ITEMS = [
    "notes.db", ".ai-providers.json", ".ai-config.ini", ".approval_always",
    ".ai-rr-state.json", "skills", "config", "screenshots",
]


def _prune_backups():
    try:
        files = sorted(BACKUP_DIR.glob("*.tar.gz"), key=lambda p: p.stat().st_mtime, reverse=True)
        for p in files[BACKUP_MAX_KEEP:]:
            p.unlink()
    except Exception:
        pass


@app.post("/api/backup")
def api_backup():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "list")).lower()
    try:
        BACKUP_DIR.mkdir(parents=True, exist_ok=True)
        if action == "create":
            import tarfile
            ts = _time.strftime("%Y%m%d_%H%M%S")
            name = re.sub(r"[^A-Za-z0-9._-]", "_", str(body.get("name", "")).strip()) or f"debz_{ts}"
            arc = BACKUP_DIR / f"{name}.tar.gz"
            added = []
            with tarfile.open(arc, "w:gz") as t:
                for item in BACKUP_ITEMS:
                    p = BASE_DIR / item
                    if not p.exists():
                        continue
                    t.add(p, arcname=item)
                    added.append(item)
            _prune_backups()
            return jsonify({
                "action": "create", "backup": str(arc),
                "bytes": arc.stat().st_size, "items": added,
            }), 200
        if action == "list":
            files = sorted(BACKUP_DIR.glob("*.tar.gz"), key=lambda p: p.stat().st_mtime, reverse=True)
            return jsonify({
                "action": "list", "dir": str(BACKUP_DIR),
                "backups": [{
                    "name": p.name,
                    "bytes": p.stat().st_size,
                    "created": _time.strftime("%Y-%m-%d %H:%M:%S", _time.localtime(p.stat().st_mtime)),
                } for p in files],
            }), 200
        if action == "restore":
            import tarfile
            name = str(body.get("name", "")).strip()
            arc = BACKUP_DIR / name
            if not arc.is_file() and not name.endswith(".tar.gz"):
                arc = BACKUP_DIR / f"{name}.tar.gz"
            if not arc.is_file():
                return jsonify({"error": f"backup gak ada: {name}"}), 404

            pre = BACKUP_DIR / f"pre_restore_{_time.strftime('%Y%m%d_%H%M%S')}.tar.gz"
            with tarfile.open(pre, "w:gz") as t:
                for item in BACKUP_ITEMS:
                    p = BASE_DIR / item
                    if p.exists():
                        t.add(p, arcname=item)
            with tarfile.open(arc, "r:gz") as t:
                members = t.getmembers()
                for m in members:
                    nm = m.name.replace("\\", "/")
                    if nm.startswith("/") or ".." in nm.split("/"):
                        return jsonify({"error": "backup berisi path tidak aman"}), 400
                t.extractall(BASE_DIR, members=members)
            return jsonify({
                "action": "restore", "backup": str(arc),
                "entries": len(members), "pre_restore_backup": str(pre),
            }), 200
        if action == "delete":
            name = str(body.get("name", "")).strip()
            arc = BACKUP_DIR / name if name.endswith(".tar.gz") else BACKUP_DIR / f"{name}.tar.gz"
            if not arc.is_file():
                return jsonify({"error": f"backup gak ada: {name}"}), 404
            arc.unlink()
            return jsonify({"action": "delete", "deleted": str(arc)}), 200
        return jsonify({"error": "action harus create/list/restore/delete"}), 400
    except Exception as e:
        return jsonify({"error": str(e)}), 500


SCHED_LOCK = _threading.RLock()
_sched_stop = _threading.Event()
_sched_thread = None
_logrot_thread = None


def _sched_load():
    try:
        with open(SCHEDULER_FILE, encoding="utf-8") as f:
            d = json.load(f)
        if isinstance(d, list):
            return d
    except Exception:
        pass
    return []


def _sched_save(jobs):
    try:
        with SCHED_LOCK:
            tmp = str(SCHEDULER_FILE) + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(jobs, f, indent=2, ensure_ascii=False)
            os.replace(tmp, str(SCHEDULER_FILE))
    except Exception:
        pass


def _cron_match(expr, now):
    parts = expr.split()
    if len(parts) != 5:
        return False
    fields = [now.minute, now.hour, now.day, now.month, now.weekday()]
    for i, part in enumerate(parts):
        if part == "*":
            continue
        ok = False
        for seg in part.split(","):
            seg = seg.strip()
            if not seg:
                continue
            if "/" in seg:
                base, step = seg.split("/", 1)
                base = base or "*"
                try:
                    step_i = int(step)
                except Exception:
                    continue
                if base == "*":
                    if fields[i] % step_i == 0:
                        ok = True
                        break
                else:
                    try:
                        lo, hi = (int(x) for x in base.split("-"))
                    except Exception:
                        continue
                    if lo <= fields[i] <= hi and (fields[i] - lo) % step_i == 0:
                        ok = True
                        break
            elif "-" in seg:
                try:
                    lo, hi = (int(x) for x in seg.split("-"))
                except Exception:
                    continue
                if lo <= fields[i] <= hi:
                    ok = True
                    break
            else:
                try:
                    if int(seg) == fields[i]:
                        ok = True
                        break
                except Exception:
                    continue
        if not ok:
            return False
    return True


def _sched_due(job, now):
    sched = str(job.get("schedule", "")).strip()
    last = float(job.get("last_run", 0) or 0)
    if sched.startswith("interval:"):
        try:
            secs = int(sched.split(":", 1)[1])
        except Exception:
            return False
        return secs > 0 and (now - last) >= secs
    if " " in sched and len(sched.split()) == 5:
        return _cron_match(sched, now) and (now - last) >= 50
    return False


def _sched_run_job(job):
    try:
        cmd = str(job.get("command", "")).strip()
        if not cmd:
            job["last_error"] = "command kosong"
            job["last_status"] = -1
            return
        timeout = min(max(int(job.get("timeout", 120) or 120), 5), 600)
        res = subprocess.run(
            cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=timeout, env={**os.environ, "TERM": "xterm-256color"})
        out = res.stdout.decode("utf-8", errors="replace")[:500]
        err = res.stderr.decode("utf-8", errors="replace")[:500]
        job["last_status"] = res.returncode
        job["last_output"] = (out + (("\n[stderr] " + err) if err else "")).strip()
        job["last_error"] = None
    except subprocess.TimeoutExpired:
        job["last_status"] = -1
        job["last_error"] = f"timeout {job.get('timeout', 120)}s"
    except Exception as e:
        job["last_status"] = -1
        job["last_error"] = str(e)
    finally:
        job["running"] = False
        job["last_run"] = _time.time()
        jobs = _sched_load()
        for j in jobs:
            if j.get("id") == job.get("id"):
                j.update(job)
                break
        _sched_save(jobs)


def _sched_tick():
    jobs = _sched_load()
    now = _time.time()
    changed = False
    for job in jobs:
        if job.get("enabled", True) is False or job.get("running"):
            continue
        if _sched_due(job, now):
            job["running"] = True
            changed = True
            t = _threading.Thread(target=_sched_run_job, args=(job,), daemon=True)
            t.start()
    if changed:
        _sched_save(jobs)


def _sched_loop():
    while not _sched_stop.is_set():
        try:
            _sched_tick()
        except Exception:
            pass
        _sched_stop.wait(10)


def _sched_start():
    global _sched_thread
    if _sched_thread and _sched_thread.is_alive():
        return
    _sched_thread = _threading.Thread(target=_sched_loop, daemon=True)
    _sched_thread.start()


@app.post("/api/scheduler")
def api_scheduler():
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "list")).lower()
    jobs = _sched_load()
    if action == "list":
        return jsonify({
            "action": "list", "jobs": jobs,
            "scheduler_running": bool(_sched_thread and _sched_thread.is_alive()),
        }), 200
    if action == "add":
        command = str(body.get("command", "")).strip()
        schedule = str(body.get("schedule", "")).strip()
        name = str(body.get("name", "")).strip() or f"job_{len(jobs) + 1}"
        if not command:
            return jsonify({"error": "command wajib"}), 400
        ok_sched = schedule.startswith("interval:") or (len(schedule.split()) == 5)
        if not schedule or not ok_sched:
            return jsonify({"error": 'schedule harus "interval:DETIK" atau cron 5-field (min hour dom month dow)'}), 400
        job = {
            "id": str(uuid.uuid4())[:8],
            "name": name,
            "command": command,
            "schedule": schedule,
            "enabled": True,
            "running": False,
            "last_run": 0,
            "last_status": None,
            "last_output": "",
            "last_error": None,
            "timeout": min(max(int(body.get("timeout", 120) or 120), 5), 600),
        }
        jobs.append(job)
        _sched_save(jobs)
        _sched_start()
        return jsonify({"action": "add", "job": job}), 200
    if action == "remove":
        jid = str(body.get("id", "")).strip()
        new = [j for j in jobs if j.get("id") != jid]
        _sched_save(new)
        return jsonify({"action": "remove", "id": jid}), 200
    if action == "toggle":
        jid = str(body.get("id", "")).strip()
        for j in jobs:
            if j.get("id") == jid:
                j["enabled"] = not j.get("enabled", True)
                _sched_save(jobs)
                return jsonify({"action": "toggle", "id": jid, "enabled": j["enabled"]}), 200
        return jsonify({"error": "job gak ada"}), 404
    if action == "run":
        jid = str(body.get("id", "")).strip()
        for j in jobs:
            if j.get("id") == jid and not j.get("running"):
                j["running"] = True
                _sched_save(jobs)
                t = _threading.Thread(target=_sched_run_job, args=(j,), daemon=True)
                t.start()
                return jsonify({"action": "run", "id": jid, "started": True}), 200
        return jsonify({"error": "job gak ada atau lagi jalan"}), 404
    return jsonify({"error": "action harus list/add/remove/toggle/run"}), 400


def _rotate_logs():
    try:
        LOG_DIR.mkdir(parents=True, exist_ok=True)
        limit = LOG_MAX_MB * 1024 * 1024
        targets = list(LOG_DIR.glob("*.log")) + ([DEBUG_LOG_FILE] if DEBUG_LOG_FILE.is_file() else [])
        for f in targets:
            try:
                if f.stat().st_size <= limit:
                    continue
                for i in range(LOG_KEEP - 1, 0, -1):
                    src = LOG_DIR / f"{f.name}.{i}"
                    dst = LOG_DIR / f"{f.name}.{i + 1}"
                    if src.exists():
                        _shutil.move(str(src), str(dst))
                if (LOG_DIR / f"{f.name}.1").exists():
                    (LOG_DIR / f"{f.name}.1").unlink()
                _shutil.move(str(f), str(LOG_DIR / f"{f.name}.1"))
                with open(f, "w") as fh:
                    fh.write("")
            except Exception:
                continue
    except Exception:
        pass


def _log_rot_loop():
    while not _sched_stop.is_set():
        try:
            _rotate_logs()
        except Exception:
            pass
        _sched_stop.wait(300)


def _logrot_start():
    global _logrot_thread
    if _logrot_thread and _logrot_thread.is_alive():
        return
    _logrot_thread = _threading.Thread(target=_log_rot_loop, daemon=True)
    _logrot_thread.start()


_RAG_DOCS_DIR = '/data/delinux/opt/rag/docs'
_RAG_SERVICE_PY = '/data/delinux/opt/rag/service.py'
_RAG_ENV = {
    "HOME": "/data/delinux/opt/rag",
    "PATH": "/usr/local/bin:/usr/bin:/bin",
    "LD_LIBRARY_PATH": "/data/delinux/lib:/data/delinux/usr/lib",
    "PYTHONHOME": "/data/delinux/usr",
    "PYTHONPATH": "/data/delinux/usr/lib/python3.14/site-packages",
    "PYTHONDONTWRITEBYTECODE": "1",
}


_RAG_INGEST_LOCK = _threading.Lock()
_RAG_INGEST_BUSY = False

def _rag_ingest_dir(docs_dir):
    """Ingest satu direktori penuh (satu child process, bukan per-file).

    Root cause CPU loop sebelumnya: watcher spawn 1 proses `service.py ingest`
    per file (121 file) berurutan, masing2 load model embedding ~100MB. Tiap 5
    menit rescan -> proses menumpuk. Sekarang: 1 proses utk seluruh direktori +
    flock global di service.py (hanya satu ingest berjalan pada satu waktu).
    """
    import subprocess as _sp
    global _RAG_INGEST_BUSY
    if _RAG_INGEST_BUSY:
        print("[RAG] ingest skip: proses lain masih jalan", file=sys.stderr)
        return
    with _RAG_INGEST_LOCK:
        if _RAG_INGEST_BUSY:
            return
        _RAG_INGEST_BUSY = True
    try:
        cmd = [
            "/data/delinux/lib/ld-musl-aarch64.so.1",
            "/data/delinux/usr/bin/python3.14",
            _RAG_SERVICE_PY, "ingest", "--path", docs_dir,
        ]
        result = _sp.run(cmd, capture_output=True, text=True, timeout=600, env=_RAG_ENV)
        if result.returncode == 0:
            print(f"[RAG] auto_ingest ok (dir): {docs_dir}", file=sys.stderr)
        else:
            print(f"[RAG] auto_ingest fail (dir): {docs_dir} — {result.stderr[:300]}", file=sys.stderr)
    except Exception as e:
        print(f"[RAG] auto_ingest error (dir): {docs_dir} — {e}", file=sys.stderr)
    finally:
        _RAG_INGEST_BUSY = False

def _rag_watcher_loop(interval=300):
    """Idle loop: tidak spawn ingest lagi (hanya sekali per boot via start)."""
    import time as _time
    import glob as _glob
    while True:
        _time.sleep(interval)
        if not os.path.isdir(_RAG_DOCS_DIR):
            continue
        n_md = len(_glob.glob(os.path.join(_RAG_DOCS_DIR, "**", "*.md"), recursive=True))
        n_txt = len(_glob.glob(os.path.join(_RAG_DOCS_DIR, "**", "*.txt"), recursive=True))
        print(f"[RAG] watcher idle — {n_md + n_txt} docs, ingest sekali per boot (manual: /api/rag)", file=sys.stderr)

def _rag_watcher_start():
    """Start watcher: ingest sekali saat startup (bukan tiap 5 menit)."""
    import glob as _glob
    if not os.path.isdir(_RAG_DOCS_DIR):
        print(f"[RAG] docs dir gak ada: {_RAG_DOCS_DIR} — watcher di-skip", file=sys.stderr)
        return
    count = 0
    for ext in ('*.md', '*.txt'):
        count += len(_glob.glob(os.path.join(_RAG_DOCS_DIR, '**', ext), recursive=True))
    print(f"[RAG] watcher start — ingest {count} docs sekali di background", file=sys.stderr)
    _threading.Thread(target=_rag_ingest_dir, args=(_RAG_DOCS_DIR,), daemon=True).start()
    _threading.Thread(target=_rag_watcher_loop, daemon=True).start()

def _startup_helpers():
    import sys as _sys
    _sys.dont_write_bytecode = True
    _sched_start()
    _logrot_start()
    _rotate_logs()
    _rag_watcher_start()


@sock.route("/ws/terminal/<terminal_id>")
def terminal_websocket(ws, terminal_id):
    token = request.args.get("token", "")
    if not check_token(token):
        ws.close()
        return

    ws.send("\033[32m✓ c0n73xt tool-server terminal connected\033[0m\n")
    ws.send("\033[90mKetik command shell. /help buat bantuan.\033[0m\n\n")

    history = []
    try:
        while True:
            data = ws.receive(timeout=None)
            if data is None:
                break
            if data == "PING":
                ws.send("PONG")
                continue
            if data.strip() == "/help":
                ws.send(
                    "\033[36m═══ c0n73xt terminal ═══\033[0m\n"
                    "  /clear /pwd /cd DIR /exit — sisanya shell langsung\n"
                )
                continue
            if data.strip() == "/clear":
                ws.send("\033c")
                continue
            if data.strip() == "/pwd":
                ws.send(f"{os.getcwd()}\n")
                continue
            if data.strip().startswith("/cd "):
                target = data.strip()[4:].strip()
                try:
                    os.chdir(target)
                    ws.send(f"\033[32mcd: {os.getcwd()}\033[0m\n")
                except Exception as e:
                    ws.send(f"\033[31mcd: {e}\033[0m\n")
                continue
            if data.strip() == "/exit":
                ws.close()
                break

            command = data.strip()
            if not command:
                continue
            history.append(command)
            cwd = os.getcwd()
            ws.send(f"\033[90m$ {command}\033[0m\n")
            try:
                p = subprocess.Popen(
                    command,
                    shell=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    cwd=cwd,
                    env={**os.environ, "TERM": "xterm-256color"}
                )
                for line in iter(p.stdout.readline, b''):
                    ws.send(line.decode("utf-8", errors="replace"))
                for line in iter(p.stderr.readline, b''):
                    ws.send(f"\033[31m{line.decode('utf-8', errors='replace')}\033[0m")
                p.wait()
                if p.returncode != 0:
                    ws.send(f"\033[90m[exit: {p.returncode}]\033[0m\n")
            except Exception as e:
                ws.send(f"\033[31m[error: {e}]\033[0m\n")
    except Exception:
        pass


@app.post("/api/rag")
def api_rag():
    """RAG: embedding + vector search via chroot Alpine (/data/delinux).
    Actions: status | embed | ingest | query. Output JSON.
    """
    deny = _auth()
    if deny:
        return deny
    body = request.get_json(silent=True) or {}
    action = str(body.get("action", "")).strip()
    text = str(body.get("text", "")).strip()
    path = str(body.get("path", "")).strip()
    top = min(max(int(body.get("top", 5) or 5), 1), 20)

    if action not in ("status", "embed", "ingest", "query"):
        return jsonify({"error": "action harus status|embed|ingest|query"}), 400
    if action in ("embed", "query") and text == "":
        return jsonify({"error": "text kosong"}), 400
    if action == "ingest" and path == "":
        return jsonify({"error": "path kosong"}), 400

    RAG_DOCS_ROOT = "/home/debz/Workspaces/rag_docs"
    if action == "ingest":
        real = os.path.realpath(path)
        if real != RAG_DOCS_ROOT and not real.startswith(os.path.realpath(RAG_DOCS_ROOT) + os.sep):
            return jsonify({"error": "path ingest harus di bawah " + RAG_DOCS_ROOT}), 400

    _pp = os.path.dirname(os.path.abspath(__file__))
    RAG_PY = os.path.join(_pp, "venv", "bin", "python")
    RAG_SVC = os.path.join(_pp, "rag", "service.py")
    cmd = [RAG_PY, RAG_SVC, action]
    if text:
        cmd += ["--text", text]
    if path:
        cmd += ["--path", path]
    if action == "query":
        cmd += ["--top", str(top)]

    try:
        proc = subprocess.run(
            cmd, capture_output=True, text=True, timeout=120
        )
    except subprocess.TimeoutExpired:
        return jsonify({"error": "RAG service timeout (120s)"}), 504
    except Exception as e:
        return jsonify({"error": f"RAG service gagal: {e}"}), 500

    if proc.returncode != 0:
        return jsonify({"error": "RAG service error", "stderr": proc.stderr[-500:]}), 500

    try:
        data = json.loads(proc.stdout)
    except Exception:
        return jsonify({"error": "RAG output invalid", "raw": proc.stdout[-500:]}), 500
    return jsonify(data), 200


@app.route("/", methods=["GET", "POST", "OPTIONS"])
def root_handler():
    if request.method == "OPTIONS":
        return jsonify({"ok": True}), 200

    action = request.args.get("action")
    if action == "providers":
        body = request.get_json(silent=True) or {}

        if body.get("op") == "gensession":
            base_url = body.get("base_url")
            api_key = body.get("api_key")
            ua = body.get("ua")

            if not base_url:
                return jsonify({"success": False, "error": "Base URL kosong"}), 400

            try:
                headers = {"User-Agent": ua} if ua else {}
                if api_key:
                    headers["Authorization"] = f"Bearer {api_key}"

                resp = requests.get(base_url, headers=headers, timeout=10)

                session_id = resp.headers.get("X-Session-ID") or resp.headers.get("Set-Cookie")

                if session_id:
                    return jsonify({"success": True, "session_id": session_id, "source": "response"})
                else:
                    return jsonify({"success": True, "session_id": str(uuid.uuid4()), "source": "generated"})

            except Exception as e:
                return jsonify({"success": False, "error": f"Gagal fetch target: {str(e)}"}), 500

    return jsonify({"error": "Not Found"}), 404


if __name__ == "__main__":
    _startup_helpers()
    _port = int(os.getenv("TOOLS_PORT", os.getenv("BACKEND_PORT", 9191)))
    if _port < 1024:
        print(f"[tool-server] Port {_port} < 1024, naikkan ke 9191 (butuh root)", file=sys.stderr)
        _port = 9191
    _host = os.environ.get("TOOLS_HOST", "127.0.0.1")
    print(f"[tool-server] Starting on {_host}:{_port}...", file=sys.stderr)
    app.run(host=_host, port=_port)
