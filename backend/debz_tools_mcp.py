#!/usr/bin/env python3
"""
debz_tools_mcp.py — MCP server (stdio) tool umum Debz AI untuk opencode-cli.
Tools: shell_exec, file_read, file_write, file_edit, browser.
Browser didelegasikan ke cdp_chrome.py (CDP Chrome/WebView lokal, stdlib).

Transport: stdio JSON-RPC 2.0 ala MCP (tiruan cua_mcp.py). Tanpa dependency
eksternal (murni stdlib) biar jalan di rootfs HP maupun desktop.

Register di opencode.json:
    "mcp": { "debz": { "type": "local",
                       "command": ["python3", "/opt/debz/app/debz_tools_mcp.py"],
                       "enabled": true } }
"""
import json
import os
import subprocess
import sys
import time
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
PROTO_VERSION = "2025-03-26"
CDP_CLI = str(BASE_DIR / "cdp_chrome.py")

TOOL_DEFS = [
    {
        "name": "shell_exec",
        "description": ("Jalankan perintah shell (/bin/sh -c) di dalam rootfs. "
                        "Untuk ls/cat/grep/python3/cek file, debug cepat, install. "
                        "Timeout default 60 detik, maks 300."),
        "inputSchema": {
            "type": "object",
            "properties": {
                "command": {"type": "string"},
                "timeout": {"type": "integer"},
            },
            "required": ["command"],
        },
    },
    {
        "name": "file_read",
        "description": "Baca file teks (offset baris mulai 1, limit jumlah baris).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "path": {"type": "string"},
                "offset": {"type": "integer"},
                "limit": {"type": "integer"},
            },
            "required": ["path"],
        },
    },
    {
        "name": "file_write",
        "description": "Tulis file (overwrite, bikin parent dir otomatis).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "path": {"type": "string"},
                "content": {"type": "string"},
            },
            "required": ["path", "content"],
        },
    },
    {
        "name": "file_edit",
        "description": "Ganti exact-string oldString -> newString di file (harus unik, kecuali replaceAll).",
        "inputSchema": {
            "type": "object",
            "properties": {
                "path": {"type": "string"},
                "oldString": {"type": "string"},
                "newString": {"type": "string"},
                "replaceAll": {"type": "boolean"},
            },
            "required": ["path", "oldString", "newString"],
        },
    },
    {
        "name": "browser",
        "description": ("Otomasi browser Chrome/WebView lokal via CDP. Commands: goto <url>, "
                        "content, text, title, screenshot, click <sel> [idx], type <sel> <text>, "
                        "press <key>, wait <ms>, eval <js>. Untuk debug frontend: goto URL -> "
                        "screenshot -> lihat gambar -> klik/isi."),
        "inputSchema": {
            "type": "object",
            "properties": {
                "command": {"type": "string"},
                "url": {"type": "string"},
                "selector": {"type": "string"},
                "index": {"type": "integer"},
                "text": {"type": "string"},
                "key": {"type": "string"},
                "ms": {"type": "integer"},
                "js": {"type": "string"},
            },
            "required": ["command"],
        },
    },
]

_BANNED = (".bak", ".orig", "__pycache__")


def _ok(**kw):
    out = {"ok": True}
    out.update(kw)
    return out


def _fail(msg):
    return {"ok": False, "error": str(msg)}


_SYNTAX_PROBE = {
    ".sh": ["sh", "-n"],
    ".php": ["php", "-l"],
    ".py": ["python3", "-c",
            "import ast,sys;ast.parse(open(sys.argv[1],encoding='utf-8').read(),sys.argv[1])"],
}


def syntax_error(path):
    """Balik pesan error kalau file ini rusak, else None."""
    probe = _SYNTAX_PROBE.get(os.path.splitext(path)[1].lower())
    if not probe:
        return None
    argv = probe + [path]
    try:
        r = subprocess.run(argv, capture_output=True, text=True, timeout=30)
    except Exception as e:
        return f"gate tak bisa jalan ({' '.join(argv[:-1])}): {e}"
    if r.returncode == 0:
        return None
    return f"{' '.join(argv[:-1])}: {(r.stdout + r.stderr).strip()[:400]}"


def t_exec(a):
    cmd = str(a.get("command", ""))
    if not cmd.strip():
        return _fail("command kosong")
    try:
        timeout = max(5, min(300, int(a.get("timeout", 60) or 60)))
    except Exception:
        timeout = 60
    try:
        p = subprocess.run(["/bin/sh", "-c", cmd], capture_output=True,
                           text=True, timeout=timeout)
        out = (p.stdout or "") + (p.stderr or "")
        return _ok(exit=p.returncode, output=out[-8000:])
    except subprocess.TimeoutExpired:
        return _fail(f"timeout {timeout}s")
    except Exception as e:
        return _fail(f"{type(e).__name__}: {e}")


def t_read(a):
    p = str(a.get("path", ""))
    if not p:
        return _fail("path kosong")
    for b in _BANNED:
        if b in p:
            return _fail(f"path dilarang: {b}")
    try:
        off = max(1, int(a.get("offset", 1) or 1))
        lim = max(1, min(2000, int(a.get("limit", 200) or 200)))
    except Exception:
        off, lim = 1, 200
    try:
        with open(p, errors="replace") as f:
            lines = f.readlines()
        total = len(lines)
        sel = lines[off - 1:off - 1 + lim]
        return _ok(path=p, total_lines=total, offset=off,
                   content="".join(sel)[-12000:])
    except Exception as e:
        return _fail(f"{type(e).__name__}: {e}")


def t_write(a):
    p = str(a.get("path", ""))
    c = a.get("content", "")
    if not p:
        return _fail("path kosong")
    for b in _BANNED:
        if b in p:
            return _fail(f"path dilarang: {b}")
    prev = None
    if os.path.exists(p):
        try:
            with open(p, errors="replace") as f:
                prev = f.read()
        except Exception:
            prev = None
    try:
        parent = os.path.dirname(p)
        if parent:
            os.makedirs(parent, exist_ok=True)
        with open(p, "w") as f:
            f.write(c if isinstance(c, str) else str(c))
    except Exception as e:
        return _fail(f"{type(e).__name__}: {e}")
    bad = syntax_error(p)
    if bad:
        if prev is not None:
            with open(p, "w") as f:
                f.write(prev)
            return _fail(f"file dibalik (sintaks rusak): {bad}")
        return _fail(f"PERHATIAN file baru tersimpan tapi sintaks rusak: {bad}")
    return _ok(path=p, bytes=len(c))


def t_edit(a):
    p = str(a.get("path", ""))
    old = str(a.get("oldString", ""))
    new = str(a.get("newString", ""))
    if not p or not old:
        return _fail("path/oldString kosong")
    try:
        with open(p, errors="replace") as f:
            src = f.read()
        n = src.count(old)
        if n == 0:
            return _fail("oldString tidak ketemu")
        if n > 1 and not a.get("replaceAll"):
            return _fail(f"oldString muncul {n}x, tidak unik (pakai replaceAll)")
        out = src.replace(old, new)
        with open(p, "w") as f:
            f.write(out)
        bad = syntax_error(p)
        if bad:
            with open(p, "w") as f:
                f.write(src)
            return _fail(f"file dibalik (sintaks rusak): {bad}")
        return _ok(path=p, replaced=n if a.get("replaceAll") else 1)
    except Exception as e:
        return _fail(f"{type(e).__name__}: {e}")


def t_browser(a):
    cmd = str(a.get("command", "")).strip()
    if not cmd:
        return _fail("command kosong: goto|content|text|title|screenshot|click|type|press|wait|eval")
    argv = [cmd]
    if cmd == "goto":
        argv.append(str(a.get("url", "")))
    elif cmd in ("click", "type"):
        argv.append(str(a.get("selector", "")))
        argv.append(str(int(a.get("index", 0) or 0)) if cmd == "click"
                    else str(a.get("text", "")))
    elif cmd == "press":
        argv.append(str(a.get("key", "Enter")))
    elif cmd == "wait":
        argv.append(str(int(a.get("ms", 1000) or 1000)))
    elif cmd == "eval":
        argv.append(str(a.get("js", "")))
    elif cmd == "screenshot":
        argv.append(str(a.get("path", "/tmp/debz_shot.png")))
    try:
        p = subprocess.run([sys.executable, CDP_CLI, *argv], capture_output=True,
                           text=True, timeout=120)
        out = (p.stdout or "").strip()
        if not out:
            return _fail((p.stderr or "")[-1000:] or "cdp tanpa output")
        try:
            return json.loads(out)
        except json.JSONDecodeError:
            return {"ok": True, "raw": out[-4000:]}
    except subprocess.TimeoutExpired:
        return _fail("browser timeout 120s")
    except Exception as e:
        return _fail(f"{type(e).__name__}: {e}")


_HANDLERS = {"shell_exec": t_exec, "file_read": t_read, "file_write": t_write,
             "file_edit": t_edit, "browser": t_browser}


def _log(msg):
    try:
        with open(BASE_DIR / "logs/debz_tools_mcp.log", "a", encoding="utf-8") as f:
            f.write(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}\n")
    except Exception:
        pass


class MCPHandler:
    def handle(self, raw):
        try:
            msg = json.loads(raw)
        except json.JSONDecodeError:
            return None
        method = msg.get("method")
        mid = msg.get("id")
        if method == "initialize":
            return {"jsonrpc": "2.0", "id": mid, "result": {
                "protocolVersion": PROTO_VERSION,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": "debz-tools", "version": "1.0.0"}}}
        if method == "notifications/initialized":
            return None
        if method == "ping":
            return {"jsonrpc": "2.0", "id": mid, "result": {}}
        if method == "tools/list":
            return {"jsonrpc": "2.0", "id": mid, "result": {"tools": TOOL_DEFS}}
        if method == "tools/call":
            params = msg.get("params") or {}
            name = params.get("name")
            fn = _HANDLERS.get(name)
            if fn is None:
                return {"jsonrpc": "2.0", "id": mid, "error":
                        {"code": -32602, "message": f"Unknown tool: {name}"}}
            try:
                res = fn(params.get("arguments") or {})
            except Exception as e:
                res = _fail(f"{type(e).__name__}: {e}")
            shot = None
            if isinstance(res, dict) and res.get("ok") and isinstance(res.get("path"), str):
                try:
                    import base64 as _b64
                    if res["path"].endswith(".png") and os.path.isfile(res["path"]):
                        with open(res["path"], "rb") as f:
                            shot = _b64.b64encode(f.read()).decode()
                except Exception:
                    shot = None
            text = json.dumps(res, ensure_ascii=False)[:12000]
            content = []
            if shot:
                content.append({"type": "image", "data": shot, "mimeType": "image/png"})
            content.append({"type": "text", "text": text})
            return {"jsonrpc": "2.0", "id": mid, "result": {"content": content}}
        if mid is not None:
            return {"jsonrpc": "2.0", "id": mid, "error":
                    {"code": -32601, "message": f"Method not found: {method}"}}
        return None


def main():
    h = MCPHandler()
    _log("debz_tools_mcp started (stdio)")
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        resp = h.handle(line)
        if resp is not None:
            sys.stdout.write(json.dumps(resp, ensure_ascii=False) + "\n")
            sys.stdout.flush()


if __name__ == "__main__":
    main()
