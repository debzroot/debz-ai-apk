#!/usr/bin/env python3
"""
cdp_chrome.py — browser automation via Chrome DevTools Protocol, murni stdlib.
Pengganti ringan pw_browser.mjs (node+playwright+chromium ~200MB) untuk HP:
memakai Chrome/WebView yang SUDAH ada di device, TANPA browser bundelan.

Sumber CDP (dicoba berurutan):
  1. TCP 127.0.0.1:9222 (Chrome dengan --remote-debugging-port, best-effort)
  2. Abstract unix socket webview_devtools_remote_<pid> (WebView debug milik
     app sendiri; pid dari env DEBZ_APP_PID atau flag --pid)

CLI kompatibel browser.py (JSON ke stdout):
  goto <url> | content | text | title | screenshot <path> | click <sel> [idx] |
  type <sel> <text> | press <key> | wait <ms> | eval <js> | close
"""
import base64
import hashlib
import json
import os
import socket
import struct
import sys
import time

TCP_HOST = "127.0.0.1"
TCP_PORT = 9222
SOCK_TMPL = "webview_devtools_remote_{pid}"
TARGET_FILE = "/tmp/debz-cdp-target"
CMD_TIMEOUT = 25


def _tcp_port():
    try:
        return int(os.getenv("CDP_TCP_PORT", "9222"))
    except Exception:
        return 9222


def _log(msg):
    if os.getenv("CDP_DEBUG"):
        print(f"[cdp] {msg}", file=sys.stderr)


class CDPError(Exception):
    pass


def _recv_exact(sock, n, timeout=10):
    sock.settimeout(timeout)
    buf = b""
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise CDPError("koneksi CDP putus")
        buf += chunk
    return buf


def _http_request(host_hdr, path, sock_factory):
    """GET path, return body bytes. Bisa via TCP atau abstract unix socket."""
    s = sock_factory()
    try:
        req = f"GET {path} HTTP/1.0\r\nHost: {host_hdr}\r\nConnection: close\r\n\r\n"
        s.sendall(req.encode())
        data = b""
        while True:
            try:
                chunk = s.recv(65536)
            except socket.timeout:
                break
            if not chunk:
                break
            data += chunk
    finally:
        s.close()
    head, _, body = data.partition(b"\r\n\r\n")
    if b"200" not in head.split(b"\r\n")[0]:
        raise CDPError(f"CDP http {path}: {head[:80]!r}")
    return body


def _tcp_sock():
    s = socket.create_connection((TCP_HOST, _tcp_port()), timeout=2)
    s.settimeout(10)
    return s


def _pid():
    if "--pid" in sys.argv:
        i = sys.argv.index("--pid")
        if i + 1 < len(sys.argv):
            return sys.argv[i + 1]
    return os.getenv("DEBZ_APP_PID", "")


def _unix_sock():
    pid = _pid()
    if not pid:
        raise CDPError("butuh DEBZ_APP_PID/--pid untuk socket WebView")
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(5)
    s.connect("\0" + SOCK_TMPL.format(pid=pid))
    return s


def _targets():
    """Return (list_target, transport) dengan transport 'tcp'/'unix'."""
    try:
        body = _http_request("127.0.0.1", "/json/list", _tcp_sock)
        return json.loads(body.decode()), "tcp"
    except Exception as e:
        _log(f"tcp 9222 gagal: {e}")
    body = _http_request("x", "/json/list", _unix_sock)
    return json.loads(body.decode()), "unix"


def _ws_key():
    return base64.b64encode(os.urandom(16)).decode()


def _ws_connect(ws_url, transport):
    """Handshake websocket, return socket siap frame. Path diambil dari URL."""
    path = ws_url.split("/", 3)[-1]
    if not path.startswith("/"):
        path = "/" + path
    if transport == "tcp":
        s = _tcp_sock()
    else:
        s = _unix_sock()
    key = _ws_key()
    req = (f"GET /{path.lstrip('/')} HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\n"
           f"Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\n"
           f"Sec-WebSocket-Version: 13\r\n\r\n")
    s.sendall(req.encode())
    resp = b""
    while b"\r\n\r\n" not in resp:
        chunk = s.recv(4096)
        if not chunk:
            break
        resp += chunk
    if b"101" not in resp.split(b"\r\n")[0]:
        s.close()
        raise CDPError(f"ws handshake gagal: {resp[:80]!r}")
    return s


def _ws_send(sock, payload):
    raw = payload.encode()
    hdr = bytes([0x81])
    n = len(raw)
    mask = os.urandom(4)
    if n < 126:
        hdr += bytes([0x80 | n])
    elif n < 65536:
        hdr += bytes([0x80 | 126]) + struct.pack(">H", n)
    else:
        hdr += bytes([0x80 | 127]) + struct.pack(">Q", n)
    sock.sendall(hdr + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(raw)))


def _ws_recv(sock, timeout=20):
    """Baca frame sampai dapat message text (event CDP dibuang)."""
    deadline = time.time() + timeout
    buf = b""
    while True:
        left = deadline - time.time()
        if left <= 0:
            raise CDPError("timeout tunggu respon CDP")
        sock.settimeout(left)
        while len(buf) < 2:
            chunk = sock.recv(4096)
            if not chunk:
                raise CDPError("ws CDP putus")
            buf += chunk
        b1, b2 = buf[0], buf[1]
        fin = b1 & 0x80
        op = b1 & 0x0F
        ln = b2 & 0x7F
        idx = 2
        if ln == 126:
            while len(buf) < idx + 2:
                buf += sock.recv(4096)
            ln = struct.unpack(">H", buf[idx:idx + 2])[0]
            idx += 2
        elif ln == 127:
            while len(buf) < idx + 8:
                buf += sock.recv(4096)
            ln = struct.unpack(">Q", buf[idx:idx + 8])[0]
            idx += 8
        if b2 & 0x80:
            while len(buf) < idx + 4:
                buf += sock.recv(4096)
            idx += 4
        while len(buf) < idx + ln:
            chunk = sock.recv(max(4096, ln))
            if not chunk:
                raise CDPError("ws CDP putus")
            buf += chunk
        payload = buf[idx:idx + ln]
        buf = buf[idx + ln:]
        if op == 0x8:
            raise CDPError("ws CDP ditutup server")
        if op in (0x9, 0xA):
            continue
        if op in (0x1, 0x0):
            try:
                msg = json.loads(payload.decode())
            except Exception:
                continue
            if "id" in msg:
                if not fin:
                    raise CDPError("fragmentasi tak didukung")
                return msg
            continue


class CDP:
    def __init__(self):
        targets, self.transport = _targets()
        tid = ""
        try:
            if os.path.isfile(TARGET_FILE):
                with open(TARGET_FILE) as f:
                    tid = f.read().strip()
        except Exception:
            tid = ""
        self.target = None
        if tid:
            for t in targets:
                if t.get("id") == tid:
                    self.target = t
                    break
        if self.target is None:
            for t in targets:
                if t.get("type") == "page" and t.get("webSocketDebuggerUrl"):
                    self.target = t
                    break
        if self.target is None:
            raise CDPError("tidak ada page target (buka Chrome/WebView dulu?). "
                           "Cek: Chrome dibuka sekali, atau WebView debug aktif.")
        try:
            with open(TARGET_FILE, "w") as f:
                f.write(self.target["id"])
        except Exception:
            pass
        self.sock = _ws_connect(self.target["webSocketDebuggerUrl"], self.transport)
        self._seq = 0

    def close(self):
        try:
            self.sock.close()
        except Exception:
            pass

    def call(self, method, params=None, timeout=CMD_TIMEOUT):
        self._seq += 1
        _ws_send(self.sock, json.dumps(
            {"id": self._seq, "method": method, "params": params or {}}))
        msg = _ws_recv(self.sock, timeout=timeout)
        if "error" in msg:
            raise CDPError(f"{method}: {msg['error'].get('message', msg['error'])}")
        return msg.get("result", {})

    def evaluate(self, js, await_promise=False):
        r = self.call("Runtime.evaluate", {
            "expression": js, "returnByValue": True,
            "awaitPromise": await_promise})
        res = r.get("result", {})
        if res.get("subtype") == "error" or res.get("type") == "undefined":
            return None
        if "value" in res:
            return res["value"]
        return res.get("description")

    def wait_ready(self, timeout=25):
        deadline = time.time() + timeout
        while time.time() < deadline:
            try:
                if self.evaluate("document.readyState") == "complete":
                    return True
            except CDPError:
                pass
            time.sleep(0.5)
        return False


JS_CLICK = """((sel, idx) => {
  const els = [...document.querySelectorAll(sel)];
  if (!els.length || idx >= els.length) return {count: els.length, clicked: false};
  const el = els[idx];
  el.scrollIntoView({block: 'center'});
  el.click();
  return {count: els.length, clicked: true};
})(%s, %s)"""

JS_TYPE = """((sel, text) => {
  const el = document.querySelector(sel);
  if (!el) return {ok: false};
  el.focus();
  if ('value' in el) el.value = text;
  else el.textContent = text;
  el.dispatchEvent(new Event('input', {bubbles: true}));
  el.dispatchEvent(new Event('change', {bubbles: true}));
  return {ok: true};
})(%s, %s)"""

JS_PRESS = """((key) => {
  const t = document.activeElement || document.body;
  const init = {key: key, code: key, bubbles: true, cancelable: true};
  t.dispatchEvent(new KeyboardEvent('keydown', init));
  t.dispatchEvent(new KeyboardEvent('keypress', init));
  if (key.length === 1) {
    if ('value' in t) { t.value += key; t.dispatchEvent(new Event('input', {bubbles: true})); }
    else t.dispatchEvent(new TextEvent('textInput', {data: key, bubbles: true}));
  }
  t.dispatchEvent(new KeyboardEvent('keyup', init));
  if (key === 'Enter' && t.form) t.form.submit();
  return {ok: true, key: key};
})(%s)"""


def _strip_pid(argv):
    """Buang '--pid X' dari argv (pid dibaca via _pid())."""
    out, skip = [], False
    for a in argv:
        if skip:
            skip = False
            continue
        if a == "--pid":
            skip = True
            continue
        out.append(a)
    return out


def main(argv):
    args = _strip_pid(argv)
    if not args:
        print(json.dumps({"ok": False, "error": "butuh command",
                          "usage": "goto|content|text|title|screenshot|click|type|press|wait|eval|close"}))
        return 1
    cmd, rest = args[0], args[1:]
    cdp = None
    try:
        if cmd == "wait":
            time.sleep(int(rest[0]) / 1000 if rest else 1)
            print(json.dumps({"ok": True, "waited_ms": int(rest[0]) if rest else 1000}))
            return 0
        cdp = CDP()
        if cmd == "goto":
            if not rest or not rest[0].startswith(("http://", "https://", "file://")):
                raise CDPError("url harus http(s):// atau file://")
            cdp.call("Page.enable")
            cdp.call("Page.navigate", {"url": rest[0]})
            ready = cdp.wait_ready()
            print(json.dumps({"ok": True, "url": rest[0], "ready": ready}))
        elif cmd == "content":
            print(json.dumps({"ok": True, "html": cdp.evaluate("document.documentElement.outerHTML") or ""}))
        elif cmd == "text":
            print(json.dumps({"ok": True, "text": cdp.evaluate("document.body ? document.body.innerText : ''") or ""}))
        elif cmd == "title":
            print(json.dumps({"ok": True, "title": cdp.evaluate("document.title") or ""}))
        elif cmd == "screenshot":
            path = rest[0] if rest else "/tmp/cdp_shot.png"
            parent = os.path.dirname(path)
            if parent:
                os.makedirs(parent, exist_ok=True)
            r = cdp.call("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True})
            with open(path, "wb") as f:
                f.write(base64.b64decode(r["data"]))
            print(json.dumps({"ok": True, "path": path}))
        elif cmd == "click":
            if not rest:
                raise CDPError("selector wajib untuk click")
            idx = int(rest[1]) if len(rest) > 1 else 0
            r = cdp.evaluate(JS_CLICK % (json.dumps(rest[0]), idx))
            print(json.dumps({"ok": True, "selector": rest[0], "result": r}))
        elif cmd == "type":
            if len(rest) < 2:
                raise CDPError("type butuh selector + text")
            r = cdp.evaluate(JS_TYPE % (json.dumps(rest[0]), json.dumps(rest[1])))
            print(json.dumps({"ok": True, "selector": rest[0], "result": r}))
        elif cmd == "press":
            r = cdp.evaluate(JS_PRESS % json.dumps(rest[0] if rest else "Enter"))
            print(json.dumps({"ok": True, "result": r}))
        elif cmd == "eval":
            if not rest:
                raise CDPError("js wajib untuk eval")
            print(json.dumps({"ok": True, "result": cdp.evaluate(" ".join(rest), await_promise=True)}))
        elif cmd == "close":
            try:
                _http_request("x", "/json/close/" + cdp.target["id"],
                              _unix_sock if cdp.transport == "unix" else _tcp_sock)
            except Exception:
                pass
            try:
                os.remove(TARGET_FILE)
            except Exception:
                pass
            print(json.dumps({"ok": True, "closed": True}))
        else:
            print(json.dumps({"ok": False, "error": f"command tidak dikenal: {cmd}"}))
            return 1
        return 0
    except CDPError as e:
        print(json.dumps({"ok": False, "command": cmd, "error": str(e)}))
        return 1
    except Exception as e:
        print(json.dumps({"ok": False, "command": cmd, "error": f"{type(e).__name__}: {e}"}))
        return 1
    finally:
        if cdp is not None:
            cdp.close()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
