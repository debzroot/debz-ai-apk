#!/usr/bin/env python3
"""
cua_mcp.py — MCP (Model Context Protocol) server for Computer Use (CUA).
=======================================================================
Bridging cua_driver.py sehingga opencode binary (tanpa port 9191) punya
tool `computer_use` untuk mengendalikan layar virtual Xvfb:
screenshot, klik, ketik, scroll, drag, buka URL, dsb.

Transport: stdio (newline-delimited JSON-RPC 2.0) — protocol MCP standar.
Tanpa dependency eksternal (murni stdlib).

Register di opencode config:
    "mcp": { "cua": { "type": "local",
                      "command": ["python3", "/home/debz/debz-ai/cua_mcp.py"],
                      "enabled": true } }
"""

import json
import os
import re
import sys
import time
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE_DIR))

import cua_driver as cua  # noqa: E402

PROTO_VERSION = "2025-03-26"
TOOL_NAME = "computer_use"

TOOL_DEF = {
    "name": TOOL_NAME,
    "description": (
        "Kendali komputer via layar virtual (CUA/Xvfb). Actions: "
        "status = info layar + window; screenshot = ambil screenshot layar; "
        "open = buka URL di browser virtual; launch = jalankan program; "
        "click/dblclick/rightclick = klik mouse at (x,y); "
        "move = gerakkan kursor; drag = drag dari (x1,y1) ke (x2,y2); "
        "type = ketik teks; key = tekan tombol (ctrl+c, Return, alt+Tab); "
        "scroll = scroll wheel (dy>0 bawah); meta = status + screenshot. "
        "Gunakan untuk men-debug frontend/website: open URL → screenshot → lihat "
        "gambar → klik/type sesuai koordinat."
    ),
    "inputSchema": {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "enum": [
                    "status", "screenshot", "open", "launch", "click",
                    "dblclick", "rightclick", "move", "drag", "type",
                    "key", "scroll", "meta",
                ],
            },
            "url": {"type": "string"},
            "cmd": {"type": "string"},
            "x": {"type": "integer"},
            "y": {"type": "integer"},
            "x1": {"type": "integer"},
            "y1": {"type": "integer"},
            "x2": {"type": "integer"},
            "y2": {"type": "integer"},
            "button": {"type": "integer"},
            "text": {"type": "string"},
            "key": {"type": "string"},
            "dx": {"type": "integer"},
            "dy": {"type": "integer"},
            "times": {"type": "integer"},
            "return_screenshot": {"type": "boolean"},
        },
        "required": ["action"],
    },
}


def _log(msg: str):
    try:
        with open(BASE_DIR / "logs/cua_mcp.log", "a", encoding="utf-8") as f:
            f.write(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}\n")
    except Exception:
        pass


class MCPHandler:
    def __init__(self):
        self.buffer = ""

    def handle(self, raw: str):
        try:
            msg = json.loads(raw)
        except json.JSONDecodeError:
            return None
        _log(f"<< {raw}")
        method = msg.get("method")
        mid = msg.get("id")
        if method == "initialize":
            return {
                "jsonrpc": "2.0",
                "id": mid,
                "result": {
                    "protocolVersion": PROTO_VERSION,
                    "capabilities": {"tools": {"listChanged": False}},
                    "serverInfo": {"name": "cua-mcp", "version": "1.0.0"},
                },
            }
        if method == "notifications/initialized":
            return None
        if method == "ping":
            return {"jsonrpc": "2.0", "id": mid, "result": {}}
        if method == "tools/list":
            return {
                "jsonrpc": "2.0",
                "id": mid,
                "result": {"tools": [TOOL_DEF]},
            }
        if method == "tools/call":
            return self._call(mid, msg.get("params") or {})
        # method ga dikenal -> notifikasi balik kosong
        if mid is not None:
            return {"jsonrpc": "2.0", "id": mid, "error": {"code": -32601, "message": f"Method not found: {method}"}}
        return None

    def _call(self, mid, params):
        name = params.get("name")
        if name != TOOL_NAME:
            return self._tool_error(mid, -32602, f"Unknown tool: {name}")
        args = params.get("arguments") or {}
        action = str(args.get("action", "")).strip().lower() or "status"
        try:
            res = cua.run(action, args)
        except Exception as e:  # noqa: BLE001
            _log(f"tool call crashed: {e}")
            return self._tool_error(mid, -32603, f"cua.run error: {e}")

        # Cek screenshot: kalau hasil punya base64 gambar, embed via image content
        shot = None
        if isinstance(res, dict) and res.get("ok"):
            res_r = res.get("result")
            if isinstance(res_r, dict) and res_r.get("base64"):
                shot = res_r.get("base64")
            if isinstance(res_r, dict) and isinstance(res_r.get("shot"), dict) and res_r["shot"].get("base64"):
                shot = res_r["shot"].get("base64")

        text = json.dumps(res, ensure_ascii=False)[:12000]
        content = []
        if shot and args.get("return_screenshot"):
            content.append({"type": "image", "data": shot, "mimeType": "image/png"})
            text += f"\nScreenshot tersedia (base64, {len(shot)} chars) — tampilkan sebagai gambar."
        content.append({"type": "text", "text": text})
        return {"jsonrpc": "2.0", "id": mid, "result": {"content": content}}

    def _tool_error(self, mid, code, message):
        return {"jsonrpc": "2.0", "id": mid, "error": {"code": code, "message": str(message)}}


def main():
    h = MCPHandler()
    _log("cua_mcp started (stdio)")
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        resp = h.handle(line)
        if resp is not None:
            out = json.dumps(resp, ensure_ascii=False)
            _log(f">> {out}")
            sys.stdout.write(out + "\n")
            sys.stdout.flush()


if __name__ == "__main__":
    main()