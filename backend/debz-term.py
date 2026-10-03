import re
import sys
import os
import time
import json
import random
import argparse
import textwrap
import threading
import subprocess
import socket
import difflib
import select
import signal
import atexit
import shutil
import tempfile
import glob

try:
    import fcntl
except ImportError:
    fcntl = None

PROJECT_ROOT = os.path.dirname(os.path.abspath(__file__))
DEBUG_LOG_PATH = os.path.join(PROJECT_ROOT, "debug.log")
sys.path = [p for p in sys.path if p]

# Single source of truth long-job.
_LONG_JOB_RE = re.compile(r"build|gradle|compile|kompil|download|upload|install|\bapt(?:-get)?\b|\bnpm\b|\bpip\b|docker|ffmpeg|youtube|yt-?dlp|tunggu|lama|lanjut|\btest\b|render|convert|backup|\bci[\s\-]", re.I)

def _is_long_job(t):
    try:
        return bool(_LONG_JOB_RE.search(t or ""))
    except Exception:
        return False

_REQ_LIBS = ("requests", "rich", "prompt_toolkit")

try:
    import requests
    from rich.console import Console, Group
    from rich.panel import Panel
    from rich.text import Text
    from rich import box
    from rich.markdown import Markdown
    from rich.markup import escape
    from rich.live import Live
    from rich.theme import Theme
    from rich.box import ROUNDED as Box
    from rich.box import Box as RichBox
    from prompt_toolkit import PromptSession
    from prompt_toolkit.completion import Completer, Completion
    from prompt_toolkit.styles import Style
    from prompt_toolkit.formatted_text import HTML
    from prompt_toolkit.shortcuts import CompleteStyle
    from prompt_toolkit.formatted_text import FormattedText
except ImportError:
    subprocess.run([sys.executable, "-m", "pip", "install", "-q", *_REQ_LIBS], check=False)
    import requests
    from rich.console import Console, Group
    from rich.panel import Panel
    from rich.text import Text
    from rich import box
    from rich.markdown import Markdown
    from rich.markup import escape
    from rich.live import Live
    from rich.theme import Theme
    from rich.box import ROUNDED as Box
    from rich.box import Box as RichBox
    from prompt_toolkit import PromptSession
    from prompt_toolkit.completion import Completer, Completion
    from prompt_toolkit.styles import Style
    from prompt_toolkit.formatted_text import HTML
    from prompt_toolkit.shortcuts import CompleteStyle
    from prompt_toolkit.formatted_text import FormattedText

THEME = {
    "bg": "#262626",
    "surface": "#2a2a2a",
    "surface_alt": "#333333",
    "text": "#eeeeee",
    "muted": "#b8b8b8",
    "green": "#7fd88f",
    "green_soft": "#7fd88f",
    "cyan": "#56b6c2",
    "yellow": "#e5c07b",
    "red": "#e06c75",
    "magenta": "#b599e8",
    "blue": "#61afef",
    "border": "#6e6e6e",
    "border_focus": "#8a8a8a",
    "root_bg": "#262626",
    "ai_bg": "#262626",
    "retro_orange": "#fab283",
    "retro_orange_bg": "#262626",
    "retro_orange_bright": "#ffc09f",
    "chat_bg": "#262626",
    "box_bg": "#262626",
    "banner_bg": "#262626",
    "user_bg": "#2e2e2e",
    "stream_bg": "#262626",
    "mem_bg": "#262626",
    "worktree_bg": "#262626",
    "diff_bg": "#262626",
    "chat_border": "#7a7a7a",
    "user_border": "#9a9a9a",
    "ai_border": "#a8a8a8",
    "stream_border": "#8a8a8a",
    "mem_border": "#7a7a7a",
    "worktree_border": "#3fa765",
    "diff_border": "#a5743c",
}

_BOXLESS = RichBox("\n".join(["    "] * 8))
_BOX_BOTTOM = RichBox("\n".join(["    "] * 7 + ["────"]))
_BOX_BOTTOM2 = RichBox("\n".join(["    "] * 7 + ["════"]))
_BOX_TOPBOT = RichBox("\n".join(["────"] + ["    "] * 6 + ["────"]))

_LIGHT_MODE_CACHE = None
_TRUE_RE = re.compile(r"^(1|true|on|yes|y)$")
_FALSE_RE = re.compile(r"^(0|false|off|no|n)$")

def _luminance_from_hex(hex_str):
    s = (hex_str or "").strip().lstrip("#")
    if len(s) == 3:
        s = "".join(c * 2 for c in s)
    if len(s) != 6 or not all(c in "0123456789abcdefABCDEF" for c in s):
        return None
    try:
        r, g, b = int(s[0:2], 16), int(s[2:4], 16), int(s[4:6], 16)
    except ValueError:
        return None
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255.0

_DA1_REPLY_RE = re.compile(rb"\x1b\[\?[0-9;]*c")

def _query_osc11_background():
    try:
        if not (sys.stdin.isatty() and sys.stdout.isatty()):
            return None
        if any(os.environ.get(v) for v in ("SSH_CONNECTION", "SSH_CLIENT", "SSH_TTY")):
            return None
        import termios
        import tty
        fd = sys.stdin.fileno()
        old = termios.tcgetattr(fd)
    except Exception:
        return None
    try:
        try:
            tty.setcbreak(fd)
        except Exception:
            return None
        try:
            sys.stdout.write("\x1b]11;?\x1b\\\x1b[c")
            sys.stdout.flush()
        except Exception:
            return None
        deadline = time.monotonic() + 1.0
        buf = b""
        while time.monotonic() < deadline:
            r, _, _ = select.select([fd], [], [], deadline - time.monotonic())
            if not r:
                continue
            try:
                chunk = os.read(fd, 64)
            except OSError:
                break
            if not chunk:
                break
            buf += chunk
            if _DA1_REPLY_RE.search(buf):
                break
        m = re.search(rb"rgb:([0-9a-fA-F]+)/([0-9a-fA-F]+)/([0-9a-fA-F]+)", buf)
        if not m:
            return None

        def norm(h):
            v = int(h, 16)
            bits = len(h) * 4
            return (v * 255) // ((1 << bits) - 1) if bits else 0
        r, g, b = norm(m.group(1)), norm(m.group(2)), norm(m.group(3))
        return f"#{r:02X}{g:02X}{b:02X}"
    finally:
        try:
            import termios
            termios.tcsetattr(fd, termios.TCSAFLUSH, old)
        except Exception:
            pass
        try:
            _d = time.monotonic() + 0.05
            while time.monotonic() < _d:
                r, _, _ = select.select([fd], [], [], _d - time.monotonic())
                if not r or not os.read(fd, 64):
                    break
        except Exception:
            pass

def _set_term_background(color=None):
    try:
        if not (sys.stdin.isatty() and sys.stdout.isatty()):
            return
        if any(os.environ.get(v) for v in ("SSH_CONNECTION", "SSH_CLIENT", "SSH_TTY")):
            return
        color = color or THEME.get("bg", "#262626")
        sys.stdout.write(f"\x1b]11;{color}\x1b\\")
        sys.stdout.flush()
    except Exception:
        pass

def _detect_light_mode_uncached():
    for var in ("DEBZ_LIGHT", "HERMES_LIGHT"):
        v = (os.environ.get(var) or "").strip().lower()
        if _TRUE_RE.match(v):
            return True
        if _FALSE_RE.match(v):
            return False
    bg_lum = _luminance_from_hex(os.environ.get("DEBZ_BACKGROUND") or "")
    if bg_lum is not None:
        return bg_lum >= 0.5
    last = (os.environ.get("COLORFGBG") or "").strip().split(";")[-1]
    if last.isdigit() and 0 <= int(last) < 16:
        return int(last) in {7, 15}
    bg_color = _query_osc11_background()
    if bg_color:
        lum = _luminance_from_hex(bg_color)
        if lum is not None:
            return lum >= 0.5
    return False

def _detect_light_mode():
    global _LIGHT_MODE_CACHE
    if _LIGHT_MODE_CACHE is not None:
        return _LIGHT_MODE_CACHE
    try:
        result = _detect_light_mode_uncached()
    except Exception:
        result = False
    _LIGHT_MODE_CACHE = result
    return result

_LIGHT_MODE_REMAP = {
    "#0a0a0a": "#ffffff",
    "#141414": "#fafafa",
    "#1e1e1e": "#f5f5f5",
    "#eeeeee": "#1a1a1a",
    "#808080": "#8a8a8a",
    "#b8b8b8": "#555555",
    "#7fd88f": "#3d9a57",
    "#56b6c2": "#318795",
    "#e5c07b": "#b0851f",
    "#e06c75": "#d1383d",
    "#9d7cd8": "#7b5bb6",
    "#b599e8": "#6d4bb8",
    "#61afef": "#2563eb",
    "#3c3c3c": "#d4d4d4",
    "#484848": "#b8b8b8",
    "#6e6e6e": "#c9c9c9",
    "#7a7a7a": "#b8b8b8",
    "#8a8a8a": "#a5a5a5",
    "#9a9a9a": "#999999",
    "#a8a8a8": "#8f8f8f",
    "#fab283": "#3b7dd8",
    "#ffc09f": "#2968c3",
    "#151515": "#e6e6e6",
    "#1a1a1a": "#ececec",
    "#282828": "#f2f2f2",
    "#262626": "#f0f0f0",
    "#0d2318": "#e2efe6",
    "#261a10": "#f0e7dc",
    "#3a3a3a": "#d0d0d0",
    "#414141": "#c8c8c8",
    "#505050": "#b0b0b0",
    "#3fa765": "#1f8a52",
    "#a5743c": "#8a6420",
    "#262626": "#f0f0f0",
    "#2a2a2a": "#e8e8e8",
    "#2e2e2e": "#e0e0e0",
    "#333333": "#d8d8d8",
}
_LIGHT_MODE_REMAP_UPPER = {k.upper(): v for k, v in _LIGHT_MODE_REMAP.items()}

def _maybe_remap_for_light_mode(hex_color):
    if not _detect_light_mode():
        return hex_color
    if not hex_color or not hex_color.startswith("#"):
        return hex_color
    return _LIGHT_MODE_REMAP_UPPER.get(hex_color.upper(), hex_color)

def _apply_light_theme():
    if not _detect_light_mode():
        return
    for k, v in list(THEME.items()):
        THEME[k] = _maybe_remap_for_light_mode(v)

_apply_light_theme()

retro_theme = Theme({
    "green": THEME["green"],
    "red": THEME["red"],
    "cyan": THEME["cyan"],
    "yellow": THEME["yellow"],
    "magenta": THEME["magenta"],
    "blue": THEME["blue"],
    "white": THEME["text"],
    "black": THEME["bg"],
})

console = Console(color_system="truecolor", highlight=False, soft_wrap=False, theme=retro_theme)

def _two_tone_title(prefix, detail="", pcolor=None, dcolor=None):
    t = Text(str(prefix), style="bold " + pcolor if pcolor else None)
    if str(detail).strip():
        t.append(" • ", style=THEME["muted"])
        t.append(str(detail), style=dcolor or "bold " + pcolor)
    return t

_ACTIVE_TREE = None

BRAND_NAME = "Debz Term"
VERSION    = "1.3.3.7"
COOLDOWN   = int(os.environ.get("DEBZ_COOLDOWN", "300"))
MAX_RETRY  = int(os.environ.get("DEBZ_MAX_RETRY", "3"))
MAX_REPEAT = int(os.environ.get("DEBZ_MAX_REPEAT", "4"))
STREAM_IDLE_S = int(os.environ.get("DEBZ_STREAM_IDLE_S", "90"))
STREAM_ABS_S = int(os.environ.get("DEBZ_STREAM_ABS_S", "600"))

ASCII_BANNER = f"""
      .:: ████▄ █████ ████▄ █████ ::.
          ██  █ ██▄▄  ██▄▄█  ▄▄██
          ██  █ ██▀▀  ██▀▀█ ██▀▀
          ████▀ █████ ████▀ █████
                Lets.Coding
"""
TOOL_ICONS_MAP = {
    "shell": "🛠️", "read_file": "📖", "write_file": "✍️", "list_dir": "🔎", "search": "🔎",
    "http_request": "🌐", "download_file": "⬇️", "db_query": "🗄️", "archive": "🗜️",
    "process_list": "📊", "process_kill": "💀", "note": "🧠", "skill": "📚", "app_install": "📦",
    "web_search": "🔎", "backup": "💾", "scheduler": "⏰",
}

_OC_TOOL_ICONS = {
    "read": "📖", "write": "✍️", "edit": "✍️", "patch": "🩹", "apply_patch": "🩹",
    "multiedit": "✍️", "bash": "🧩", "shell": "🔥", "exec": "🚀",
    "grep": "🔎", "glob": "🔎", "list": "🔎", "ls": "🔎", "fs_list": "🔎", "search": "🔎",
    "todowrite": "📋", "todoread": "📋", "todo": "📋",
    "webfetch": "🌐", "fetch": "🌐", "websearch": "🔎",
    "task": "🧩", "agent": "🧩", "skill": "📚", "question": "❓", "plan": "🗺️",
}

def _fmt_range(off, lim):
    try:
        o, l = int(off), int(lim)
        return f" baris {o}-{o+l-1}"
    except (TypeError, ValueError):
        return ""

def _fmt_path(p):
    return os.path.basename((p or "").rstrip("/")) or (p or "")

def _fmt_cmd(cmd, limit=32):
    s = re.sub(r"\s+", " ", (cmd or "").strip())
    s = s.replace(os.path.expanduser("~"), "~")
    if len(s) <= limit:
        return s
    return s[: max(0, limit - 1)] + "…"

def _get_dynamic_label(name, args):
    if name == "read_file":
        return f"📖 baca: {_fmt_path(args.get('path', ''))}{_fmt_range(args.get('offset'), args.get('limit'))}"
    elif name == "write_file": return f"✍️ edit: {_fmt_path(args.get('path', ''))}"
    elif name == "shell": return f"🛠️ exec: {_fmt_cmd(args.get('command', ''))}"
    elif name == "search": return f"🔎 cari: \"{args.get('pattern', '')[:15]}\""
    elif name == "list_dir": return f"📁 lihat isi: {args.get('path', '') or '/'}"
    elif name == "http_request": return f"🌐 akses HTTP {args.get('url', '')[:36]}"
    elif name == "download_file": return f"⬇️ unduh: {_fmt_path(args.get('path', 'file'))}"
    elif name == "db_query": return f"🗄️ query DB: {args.get('sql', '')[:20]}"
    elif name == "archive": return f"📦 arsip: {args.get('action', '')}"
    elif name == "process_list": return f"🧭 lihat proses: {args.get('pattern', 'all')}"
    elif name == "process_kill": return f"⛔ hentikan: {args.get('pid', args.get('pattern', '?'))}"
    elif name == "skill": return f"📚 muat skill: {args.get('action', '')}"
    elif name == "note": return f"🧠 catat memory: {args.get('action', '')}"
    elif name == "app_install": return f"📦 Pasang: {args.get('package', args.get('action', ''))}"
    elif name == "web_search": return f"🔎 cari web: {args.get('query', '')[:36]}"
    elif name == "backup": return f"💾 backup: {args.get('action', '')}"
    elif name == "scheduler": return f"⏰ jadwal: {args.get('action', '')}"
    elif name == "computer_use": return f"🖥️ kendali komputer: {args.get('action', '')}"
    elif name == "browser": return f"🌐 browser: {args.get('command', '')}"
    elif name == "screenshot": return "📸 screenshot layar"
    elif name == "rag_query": return f"📚 cari dokumen: {args.get('action', '')}"
    return name

def _oc_indo_label(tool, d):
    _b = (d or "").strip()
    if tool == "read": return f"baca: {_b}".rstrip()
    if tool in ("write", "edit", "multiedit", "apply_patch", "patch"): return f"edit: {_b}".rstrip()
    if tool in ("bash", "shell", "exec"): return f"exec: {_fmt_cmd(_b)}".rstrip()
    if tool in ("grep", "glob", "list", "ls", "fs_list", "search", "websearch"): return f"cari: {_b}".rstrip()
    if tool in ("todowrite", "todoread", "todo"): return f"catat rencana: {_b}".rstrip()
    if tool in ("webfetch", "fetch"): return f"ambil web: {_b}".rstrip()
    if tool in ("task", "agent"): return f"subtugas: {_b}".rstrip()
    if tool == "skill": return f"muat skill: {_b}".rstrip()
    if tool == "question": return f"tanya: {_b}".rstrip()
    if tool == "plan": return f"rencana: {_b}".rstrip()
    if tool == "screenshot": return "screenshot layar"
    if _b:
        return f"terapkan {tool}: {_b}"
    return f"terapkan {tool}" if tool else "langkah"

def _providers_path():
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), ".ai-providers.json")

def _read_providers():
    try:
        with open(_providers_path(), "r", encoding="utf-8") as f: d = json.load(f)
        p = d.get("providers", {})
        a = d.get("active_cli") or d.get("active", "")
        if not a or a not in p: a = next(iter(p), "") if p else ""
        return a, p
    except Exception:
        return "", {}

def _provider_cfg(p, cfg):
    b = (p.get("base_url") or "").rstrip("/") or cfg.get("ENDPOINT", "")
    for s in ("/chat/completions", "/completions"):
        if b.endswith(s): b = b[:-len(s)]; break
    return {
        "endpoint": b.rstrip("/") + "/chat/completions",
        "key": p.get("api_key", ""),
        "model": p.get("model", ""),
        "extra": p.get("extra", {}),
        "mode": p.get("mode", ""),
        "name": p.get("name", p.get("id", "?")),
    }

def _set_active_provider(prov_id, model=None):
    path = _providers_path()
    try:
        with open(path, "r", encoding="utf-8") as f: d = json.load(f)
        if prov_id not in (d.get("providers") or {}): return False
        d["active_cli"] = prov_id
        if model: d["providers"][prov_id]["model"] = model
        backup_path = os.path.join(tempfile.gettempdir(), os.path.basename(path) + ".bak")
        try: shutil.copy2(path, backup_path)
        except Exception: pass
        with open(path + ".tmp", "w", encoding="utf-8") as f: json.dump(d, f, indent=2, ensure_ascii=False)
        os.replace(path + ".tmp", path)
        return True
    except Exception: return False

def _load_config():
    cfg = {"API_KEY": "", "ENDPOINT": "https://api.tokenrouter.com/v1/chat/completions", "MODEL": "z-ai/glm-5.3-free", "TOOLS_TOKEN": "", "TOOLS_PORT": 9191, "MAX_ITER": 128, "MAX_TOKEN": 8192, "_CLI_MODEL": ""}
    ini = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".ai-config.ini")
    if os.path.exists(ini):
        try:
            for line in open(ini, "r", encoding="utf-8", errors="replace"):
                line = line.strip()
                if not line or line.startswith(";") or line.startswith("#"): continue
                if "=" in line:
                    k, v = line.split("=", 1)
                    kk = k.strip()
                    kk = kk[3:] if kk.startswith("AI_") else kk
                    if kk in cfg and v.strip():
                        if kk == "TOOLS_TOKEN":
                            cfg[kk] = v.strip().split(",")[0].strip()
                        else:
                            cfg[kk] = int(v) if kk in ("MAX_ITER", "MAX_TOKEN", "MAX_OUTPUT_TOKENS", "TOOLS_PORT") else v.strip()
        except Exception: pass
    a_id, provs = _read_providers()
    if a_id and a_id in provs:
        pc = _provider_cfg(provs[a_id], cfg)
        cfg["ENDPOINT"] = pc["endpoint"]
        if pc["key"]: cfg["API_KEY"] = pc["key"]
        if pc["model"]: cfg["MODEL"] = pc["model"]
        if pc["extra"]: cfg["EXTRA"] = pc["extra"]
    cfg["_PROV_MODE"] = pc.get("mode", "") if a_id in provs else ""
    cfg["_PROV_ID"], cfg["_PROVIDERS"] = a_id, provs
    cfg["_ROUTING"] = _read_routing()
    return cfg

def refresh_cfg(cfg):
    a_id, provs = _read_providers()
    if not provs: return cfg
    if not a_id or a_id not in provs: a_id = cfg.get("_PROV_ID", "") or next(iter(provs))
    if a_id and a_id in provs:
        pc = _provider_cfg(provs[a_id], cfg)
        cfg["ENDPOINT"] = pc["endpoint"]
        if pc["key"]: cfg["API_KEY"] = pc["key"]
        cfg["EXTRA"] = pc["extra"] or {}
        if pc["model"] and not cfg.get("_CLI_MODEL"): cfg["MODEL"] = pc["model"]
        cfg["_PROV_MODE"] = pc.get("mode", "")
    cfg["_PROV_ID"], cfg["_PROVIDERS"] = a_id, provs
    cfg["_ROUTING"] = _read_routing()
    return cfg

UA_OK_HARNESS = "opencode/1.0 (linux; x64)"
def _sanitize_ua(ua):
    return UA_OK_HARNESS if not ua or "compat" in ua.lower() or not re.match(r"^[A-Za-z0-9._-]+/\d", ua) else ua

TRANSPORT_KEYS = {"user_agent", "referer", "x_title", "headers"}

def _rr_state_path():
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), ".ai-rr-state.json")

def _routing_of(data):
    r = str(data.get("routing", "fixed") or "fixed").lower()
    return r if r in ("fixed", "roundrobin", "failover") else "fixed"

def _read_routing():
    try:
        with open(_providers_path(), "r", encoding="utf-8") as f:
            return _routing_of(json.load(f))
    except Exception:
        return "fixed"

def _enabled_ids(provs):
    return [pid for pid, p in (provs or {}).items() if (p.get("enabled", True) is not False)]

def _apply_provider(cfg, pid, mark_active=True):
    provs = cfg.get("_PROVIDERS") or {}
    p = provs.get(pid)
    if not p: return False
    pc = _provider_cfg(p, cfg)
    cfg["ENDPOINT"] = pc["endpoint"]
    cfg["API_KEY"] = pc["key"]
    if pc["model"]: cfg["MODEL"] = pc["model"]
    cfg["EXTRA"] = pc["extra"] or {}
    cfg["_PROV_ID"] = pid
    if mark_active:
        _set_active_provider(pid)
    return True

def _rotate_for_rr(cfg, announce=True):
    if cfg.get("_ROUTING") != "roundrobin":
        return
    provs = cfg.get("_PROVIDERS") or {}
    pool = _enabled_ids(provs)
    if not pool:
        return
    st = {}
    try:
        with open(_rr_state_path(), "r", encoding="utf-8") as f:
            st = json.load(f) or {}
    except Exception:
        st = {}
    try:
        idx = int(st.get("idx", 0) or 0) % len(pool)
    except Exception:
        idx = 0
    st["idx"] = idx + 1
    try:
        with open(_rr_state_path(), "w", encoding="utf-8") as f:
            json.dump(st, f)
    except Exception:
        pass
    pid = pool[idx]
    if _apply_provider(cfg, pid, mark_active=False):
        if announce:
            console.print(f"  [dim cyan]🔀 round-robin → {pid}[/dim cyan]")

def _set_routing(mode):
    path = _providers_path()
    try:
        with open(path, "r", encoding="utf-8") as f:
            d = json.load(f)
        d["routing"] = mode
        with open(path + ".tmp", "w", encoding="utf-8") as f:
            json.dump(d, f, indent=2, ensure_ascii=False)
        os.replace(path + ".tmp", path)
        return True
    except Exception:
        return False

def _gen_session_id(base_url, api_key="", ua=""):
    import uuid as _uuid
    base = (base_url or "").strip().rstrip("/")
    if not base.startswith(("http://", "https://")):
        return None, "base_url invalid", ""
    headers = {"Authorization": "Bearer " + api_key, "Accept": "application/json"}
    if not ua and "openrouter.ai" in base:
        ua = UA_OK_HARNESS
    if ua:
        headers["User-Agent"] = ua
    try:
        r = requests.get(base + "/models", headers=headers, timeout=120)
        if r.ok:
            sid = r.headers.get("x-session-id") or r.headers.get("X-Session-Id")
            if sid: return sid.strip(), "-", "response"
    except Exception: pass
    try:
        body = {"model": "test", "messages": [{"role": "user", "content": "hi"}], "max_tokens": 1}
        r = requests.post(base + "/chat/completions", headers={**headers, "Content-Type": "application/json"}, json=body, timeout=120)
        if r.ok:
            sid = r.headers.get("x-session-id") or r.headers.get("X-Session-Id")
            if sid: return sid.strip(), "-", "response"
    except Exception: pass
    return str(_uuid.uuid4()), "-", "generated"

def _set_provider_sid(prov_id, sid):
    path = _providers_path()
    try:
        with open(path, "r", encoding="utf-8") as f:
            d = json.load(f)
        if prov_id not in (d.get("providers") or {}):
            return False
        extra = d["providers"][prov_id].setdefault("extra", {})
        if not isinstance(extra.get("headers"), dict):
            extra["headers"] = {}
        if sid is None:
            extra["headers"].pop("x-session-id", None)
            if not extra["headers"]:
                extra.pop("headers", None)
        else:
            extra["headers"]["x-session-id"] = sid
        backup_path = os.path.join(tempfile.gettempdir(), os.path.basename(path) + ".bak")
        try: shutil.copy2(path, backup_path)
        except Exception: pass
        with open(path + ".tmp", "w", encoding="utf-8") as f:
            json.dump(d, f, indent=2, ensure_ascii=False)
        os.replace(path + ".tmp", path)
        return True
    except Exception:
        return False

OPENCODE_UA = "opencode/1.18.31 ai-sdk/provider-utils/4.0.23 runtime/bun/1.3.14"

def _gen_opencode_id(prefix=""):
    import secrets as _secrets
    hex_part = _secrets.token_hex(6)
    chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
    b62_part = "".join(_secrets.choice(chars) for _ in range(14))
    return prefix + hex_part + b62_part

def _get_headers(cfg):
    h = {"Authorization": f"Bearer {cfg['API_KEY']}", "Content-Type": "application/json"}
    e = cfg.get("EXTRA", {})
    endpoint = cfg.get("ENDPOINT", "")
    is_opencode = "opencode.ai" in endpoint.lower()
    if is_opencode:
        h["User-Agent"] = OPENCODE_UA
        h["x-opencode-client"] = "cli"
        h["x-opencode-project"] = "global"
        h["x-opencode-session"] = _gen_opencode_id("ses_")
        h["x-opencode-request"] = _gen_opencode_id("msg_")
        h["Origin"] = "https://opencode.ai"
        h["Referer"] = "https://opencode.ai/"
    else:
        h["User-Agent"] = _sanitize_ua(e.get("user_agent") or UA_OK_HARNESS)
    if "referer" in e: h["HTTP-Referer"] = e["referer"]
    elif "openrouter.ai" in endpoint: h["HTTP-Referer"] = "https://c0n73xt.app"
    if "x_title" in e: h["X-Title"] = e["x_title"]
    elif "openrouter.ai" in endpoint: h["X-Title"] = "Debz AI"
    for hk, hv in (e.get("headers") or {}).items():
        if hv is None or str(hv) == "": continue
        h[str(hk)] = str(hv)
    return h

# PROXY-FREE BUILD: proxy pool/grabber dibuang total (UI tidak ada opsi proxy,
# semua request direct. Stub no-op dipertahankan agar call-site lama tetap jalan.
def _proxy_load_state():
    return {}

def _proxy_apply(force_new=False, use_proxy=False, force_proxy=False):
    return {}

def _proxy_cli_apply(force_new=False, use_proxy=False, force_proxy=False):
    return {}

def _proxy_pick(type_, force_new=False):
    return ""

def _proxy_failover(proxy, is_rate_limit=False, blacklist=True):
    return None

def _proxy_mark_used(proxy):
    return None

def _proxy_score_record(proxy, success):
    return None

def _proxy_note(text, icon="", color="cyan"):
    return None

_REASONING_TAGS = ("REASONING_SCRATCHPAD", "think", "thinking", "reasoning", "thought")
_TOOL_CALL_TAGS = ("tool_call", "tool_calls", "tool_result", "function_call", "function_calls")

def _strip_reasoning_tags(text):
    cleaned = text or ""

    cleaned = re.sub(r'<parameter\b[^>]*name=["\']?thinking["\']?[^>]*>.*?(?:</parameter>|$)', '', cleaned, flags=re.DOTALL | re.IGNORECASE)

    cleaned = re.sub(r'<parameter\b[^>]*thinking[^>]*思考.*?(?:>|\])', '', cleaned, flags=re.IGNORECASE)

    for tag in _REASONING_TAGS:

        cleaned = re.sub(rf"<{tag}\b[^>]*>.*?</{tag}>\s*", "", cleaned, flags=re.DOTALL | re.IGNORECASE)
        cleaned = re.sub(rf"<{tag}\b[^>]*>.*$", "", cleaned, flags=re.DOTALL | re.IGNORECASE)
        cleaned = re.sub(rf"</{tag}>\s*", "", cleaned, flags=re.IGNORECASE)

    for tc_tag in _TOOL_CALL_TAGS:
        cleaned = re.sub(rf"<{tc_tag}\b[^>]*>.*?</{tc_tag}>\s*", "", cleaned, flags=re.DOTALL | re.IGNORECASE)

    cleaned = re.sub(r'(?:(?<=^)|(?<=[\n\r.!?:]))[ \t]*<function\b[^>]*\bname\s*=[^>]*>(?:(?:(?!</function>).)*)</function>\s*', '', cleaned, flags=re.DOTALL | re.IGNORECASE)
    cleaned = re.sub(r'</(?:tool_call|tool_calls|tool_result|function_call|function_calls|function)>\s*', '', cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r'(?:^|\n)[ \t]*<(?:tool_call|tool_calls|tool_result|function_call|function_calls)\b[^>]*>.*$|(?:^|\n)[^\n<]*</?arg_(?:key|value)\b.*$', '', cleaned, flags=re.DOTALL | re.IGNORECASE)

    return cleaned.strip()

def _render_unified_diff(diff_text, max_show, width):
    lines = (diff_text or "").splitlines()

    hint = ""
    for _l in lines:
        if _l.startswith("+" * 3) or _l.startswith("---"):
            _p = _l[4:].strip().lstrip("/").lstrip("b/").lstrip("a/")
            _base = _p.rsplit("/", 1)[-1]
            if "." in _base:
                _ext_hint = _EXT_LANG_MAP.get(_base.rsplit(".", 1)[-1].lower(), "")
                if _ext_hint:
                    hint = "lang:" + _ext_hint
                    break
        elif _l.startswith("@@"):
            break

    added_block = "\n".join(l[1:] for l in lines if l.startswith("+") and not l.startswith("+++"))
    removed_block = "\n".join(l[1:] for l in lines if l.startswith("-") and not l.startswith("---"))
    context_block = "\n".join(l[1:] for l in lines if l.startswith(" ") and not l.startswith(("+", "-", "@")))
    hl_added = _colorize_lines(added_block, hint, None) if added_block else None
    hl_removed = _colorize_lines(removed_block, hint, None) if removed_block else None
    hl_context = _colorize_lines(context_block, hint, None) if context_block else None

    rendered = []
    new_line_no, old_line_no = 0, 0
    a_i, r_i, c_i = 0, 0, 0
    for line in lines:
        if line.startswith("+++") or line.startswith("---"):
            continue
        if line.startswith("@@"):
            m_new = re.search(r'\+(\d+)', line)
            m_old = re.search(r'-(\d+)', line)
            if m_new: new_line_no = int(m_new.group(1)) - 1
            if m_old: old_line_no = int(m_old.group(1)) - 1
            continue
        if line.startswith("+"):
            new_line_no += 1
            t = Text(f"{new_line_no:4d} │ ", style=THEME["muted"])
            t.append("+", style="bold " + THEME["green"])
            t.append(" ", style=THEME["muted"])
            if hl_added and a_i < len(hl_added):
                _tok = hl_added[a_i]
                if _tok.plain:
                    t.append_text(_tok)
                else:
                    t.append(" ", style=THEME["green"])
            else:
                t.append(line[1:], style=THEME["green"])
            a_i += 1
            rendered.append(t)
        elif line.startswith("-"):
            old_line_no += 1
            t = Text(f"{old_line_no:4d} │ ", style=THEME["muted"])
            t.append("-", style="bold " + THEME["red"])
            t.append(" ", style=THEME["muted"])
            if hl_removed and r_i < len(hl_removed):
                _tok = hl_removed[r_i]
                if _tok.plain:
                    t.append_text(_tok)
                else:
                    t.append(" ", style=THEME["red"])
            else:
                t.append(line[1:], style=THEME["red"])
            r_i += 1
            rendered.append(t)
        elif line.startswith(" "):
            new_line_no += 1
            old_line_no += 1
            t = Text(f"{new_line_no:4d} │ ", style=THEME["muted"])
            t.append(" ", style=THEME["muted"])
            t.append(" ", style=THEME["muted"])
            if hl_context and c_i < len(hl_context):
                _tok = hl_context[c_i]
                if _tok.plain:
                    t.append_text(_tok)
                else:
                    t.append(" ", style=THEME["muted"])
            else:
                t.append(line[1:], style=THEME["muted"])
            c_i += 1
            rendered.append(t)

    for _r in rendered:
        try:
            _r.no_wrap = True
            _r.overflow = "ellipsis"
        except Exception:
            pass
    out = rendered[:max_show]
    if len(rendered) > max_show:
        out.append(Text(f"  … {len(rendered) - max_show} baris disembunyikan", style=THEME["muted"], no_wrap=True, overflow="ellipsis"))
    return out

_PYG_STYLE = None
_PYG_LEXER_CACHE = {}

_EXT_LANG_MAP = {
    "py": "python", "pyw": "python", "pyi": "python", "rb": "ruby", "php": "php",
    "js": "javascript", "mjs": "javascript", "cjs": "javascript", "jsx": "jsx",
    "ts": "typescript", "tsx": "tsx", "vue": "vue",
    "json": "json", "jsonc": "json", "webmanifest": "json",
    "html": "html", "htm": "html", "phphtml": "html", "xml": "xml", "svg": "xml",
    "css": "css", "scss": "scss", "less": "less", "sass": "sass",
    "yaml": "yaml", "yml": "yaml", "toml": "toml", "ini": "ini", "cfg": "ini",
    "sh": "bash", "bash": "bash", "zsh": "bash", "fish": "fish", "env": "bash",
    "ps1": "powershell", "bat": "bat",
    "java": "java", "kt": "kotlin", "scala": "scala", "groovy": "groovy",
    "c": "c", "h": "c", "cpp": "cpp", "cc": "cpp", "cxx": "cpp", "hpp": "cpp",
    "cs": "csharp", "go": "go", "rs": "rust", "swift": "swift", "dart": "dart",
    "sql": "sql", "psql": "sql", "r": "r", "lua": "lua", "pl": "perl",
    "md": "markdown", "markdown": "markdown", "rst": "rst", "txt": "text",
    "log": "text", "diff": "diff", "patch": "diff",
    "makefile": "makefile", "mk": "makefile", "dockerfile": "dockerfile",
    "tf": "terraform", "nginx": "nginx", "conf": "nginx",
    "ex": "elixir", "exs": "elixir", "hs": "haskell", "clj": "clojure",
    "erl": "erlang", "elm": "elm", "sol": "solidity",
    "scss.lock": "text", "lock": "text", "css.lock": "text",
}

def _pyg_style():
    global _PYG_STYLE
    if _PYG_STYLE is None:
        try:
            from pygments.styles import get_style_by_name
            _PYG_STYLE = get_style_by_name(os.environ.get("DEBZ_SYNTAX_THEME", "monokai") or "monokai")
        except Exception:
            _PYG_STYLE = None
    return _PYG_STYLE

def _pyg_lexer(hint="", code=None):
    name = ""
    h = str(hint or "").strip()
    if h.startswith("lang:"):
        name = h[5:].strip().lower()
    else:
        base = h.rsplit("/", 1)[-1] if "/" in h else h
        if "." in base:
            name = _EXT_LANG_MAP.get(base.rsplit(".", 1)[-1].lower(), "")
        elif base:
            name = _EXT_LANG_MAP.get(base.lower(), base.lower())
    key = name or "?"
    if key in _PYG_LEXER_CACHE:
        return _PYG_LEXER_CACHE[key]
    lexer = None
    if name:
        try:
            from pygments.lexers import get_lexer_by_name
            lexer = get_lexer_by_name(name, stripall=False, ensurenl=False)
        except Exception:
            lexer = None
    if lexer is None and code and len(code) > 8:
        try:
            from pygments.lexers import guess_lexer
            lexer = guess_lexer(code)
        except Exception:
            lexer = None
    if lexer is None:
        try:
            from pygments.lexers import TextLexer
            lexer = TextLexer(stripall=False)
        except Exception:
            lexer = None
    if len(_PYG_LEXER_CACHE) > 200:
        _PYG_LEXER_CACHE.clear()
    if lexer is not None:
        _PYG_LEXER_CACHE[key] = lexer
    return lexer

def _pyg_token_style(ttype, bg=None):
    st = None
    try:
        style = _pyg_style()
        if style is not None:
            st = style.style_for_token(ttype)
    except Exception:
        st = None
    parts = []
    if st:
        if st.get("color"): parts.append("#" + st["color"])
        if st.get("bold"): parts.append("bold")
        if st.get("italic"): parts.append("italic")
        if st.get("underline"): parts.append("underline")
    if bg:
        parts.append("on " + bg)
    return " ".join(parts) if parts else ("on " + bg if bg else None)

def _pyg_lexer_is_php(lexer):
    try:
        return bool(lexer) and "php" in (getattr(lexer, "aliases", None) or [])
    except Exception:
        return False

def _highlight_code_text(code, hint="", bg=None):
    try:
        lexer = _pyg_lexer(hint, code)
        if lexer is None:
            return None
        from pygments import lex as _lex
        src = code or ""
        lim = 0
        if _pyg_lexer_is_php(lexer) and "<?php" not in src:

            src = "<?php\n" + src
            lim = len("<?php\n")
        out = Text()
        for ttype, value in _lex(src, lexer):
            if not value:
                continue
            if lim > 0:
                if len(value) <= lim:
                    lim -= len(value)
                    continue
                value = value[lim:]
                lim = 0
            _st = _pyg_token_style(ttype, bg)
            out.append(value, style=_st)
        return out if out.plain else None
    except Exception:
        return None

def _colorize_lines(code, hint="", bg=None):
    try:
        lexer = _pyg_lexer(hint, code)
        if lexer is None:
            return None
        from pygments import lex as _lex
        src = code or ""
        n = len(src.rstrip("\n").split("\n")) if src.strip("\n") else 0
        lim = 0
        if _pyg_lexer_is_php(lexer) and "<?php" not in src:
            src = "<?php\n" + src
            lim = len("<?php\n")
        out_lines = [Text() for _ in range(max(n, 1))]
        li = 0
        for ttype, value in _lex(src, lexer):
            if not value:
                continue
            if lim > 0:
                if len(value) <= lim:
                    lim -= len(value)
                    continue
                value = value[lim:]
                lim = 0
            parts = value.split("\n")
            for k, part in enumerate(parts):
                if part and li < n:
                    out_lines[li].append(part, style=_pyg_token_style(ttype, bg))
                if k < len(parts) - 1:
                    li += 1
                    if li >= n:
                        break
        return out_lines
    except Exception:
        return None

def _strip_oc_annotations(text):
    t = (text or "").replace("\r\n", "\n")
    t = re.sub(r'(?m)^[ \t]*<(?:path|type|content|file|summary|line)\b[^>]*>[^<\r\n]*</(?:path|type|content|file|summary|line)>[ \t]*$', '', t)
    t = re.sub(r'(?m)^[ \t]*</?(?:path|type|content|file|summary|line)\b[^>]*>[ \t]*$', '', t)
    lines = t.split("\n")
    non_empty = [l for l in lines if l.strip()]
    numbered = [l for l in non_empty if re.match(r'^\d+:[ \t]+', l)]
    if non_empty and len(numbered) >= len(non_empty) * 0.8:
        t = re.sub(r'^(\s*)\d+:[\t ]', r'\1', t, flags=re.M)
    return t.strip("\n")

def _normalize_tool_arguments(raw):
    raw = (raw or "").strip()
    if not raw:
        return "{}"
    try:
        d = json.loads(raw)
        if isinstance(d, dict):
            return json.dumps(d, ensure_ascii=False)
        return "{}"
    except (json.JSONDecodeError, ValueError):
        pass
    c = re.sub(r"^```(?:json)?\s*", "", raw, flags=re.IGNORECASE)
    c = re.sub(r"\s*```\s*$", "", c)
    c = c.strip()
    try:
        d = json.loads(c)
        if isinstance(d, dict):
            return json.dumps(d, ensure_ascii=False)
    except (json.JSONDecodeError, ValueError):
        pass
    c2 = re.sub(r",\s*([}\]])", r"\1", c)
    try:
        d = json.loads(c2)
        if isinstance(d, dict):
            return json.dumps(d, ensure_ascii=False)
    except (json.JSONDecodeError, ValueError):
        pass
    return ""

def _tool_call_sig(tool_calls):
    import hashlib
    if not tool_calls:
        return ""
    parts = []
    for tc in tool_calls:
        fn = tc.get("function", {}) if isinstance(tc, dict) else {}
        name = fn.get("name", "") if isinstance(fn, dict) else ""
        args_raw = fn.get("arguments", "") if isinstance(fn, dict) else ""
        norm = _normalize_tool_arguments(args_raw)
        parts.append(f"{name}:{hashlib.sha256(norm.encode()).hexdigest()}")
    return hashlib.sha256("|".join(parts).encode()).hexdigest()

def _stats_record(event, data=None):
    try:
        f = os.path.join(PROJECT_ROOT, ".stats.json")
        st = {}
        if os.path.isfile(f):
            with open(f, "r", encoding="utf-8") as fh:
                st = json.load(fh) or {}
        if "events" not in st:
            st["events"] = []
        entry = {"t": int(time.time()), "e": event}
        if data:
            entry.update(data)
        st["events"].append(entry)
        if len(st["events"]) > 2000:
            st["events"] = st["events"][-2000:]
        with open(f, "w", encoding="utf-8") as fh:
            json.dump(st, fh, ensure_ascii=False)
    except Exception:
        pass

def _inject_skills(user_text):
    if not user_text or len(user_text.strip()) < 5:
        return ""
    try:
        _cfg = _load_config()
        t = re.sub(r"\s+", " ", user_text.strip())[:200]
        r = requests.post(
            f"http://127.0.0.1:{_cfg['TOOLS_PORT']}/api/skill",
            headers={"Content-Type": "application/json"},
            json={"action": "search", "pattern": t, "token": ""},
            timeout=5,
        )
        if r.status_code != 200:
            return ""
        data = r.json()
        results = data.get("results") or []
        if not results:
            return ""
        block = "\n\n===== SKILL RELEVAN =====\n"
        count = 0
        for s in results[:5]:
            if not isinstance(s, dict):
                continue
            cat = (s.get("category") or "custom").strip()
            name = (s.get("name") or "").strip()
            desc = (s.get("description") or "").strip()
            if not name:
                continue
            block += f"* [{cat}/{name}] {desc}\n"
            count += 1
            if count >= 5:
                break
        if count == 0:
            return ""
        block += "Gunakan skill hanya jika relevan. Ambil detail dengan skill action=get jika diperlukan.\n"
        return block
    except Exception:
        return ""

def _flush_stdio():
    for _s in (sys.stdout, sys.stderr):
        try: _s.flush()
        except Exception: pass

def _arm_exit_watchdog(timeout_s=None):
    try: timeout_s = float(os.environ.get("DEBZ_EXIT_WATCHDOG_S", "10")) if timeout_s is None else float(timeout_s)
    except Exception: timeout_s = 10.0
    if timeout_s <= 0: return
    def _wd():
        time.sleep(timeout_s)
        _flush_stdio()
        os._exit(0)
    try: threading.Thread(target=_wd, daemon=True, name="exit-watchdog").start()
    except Exception: pass

def _exit_cleanup():
    _flush_stdio()
    _arm_exit_watchdog()

atexit.register(_exit_cleanup)

def _term_signal_handler(signum, frame):
    _arm_exit_watchdog()
    raise KeyboardInterrupt()

for _sig in (signal.SIGINT, signal.SIGTERM, getattr(signal, "SIGHUP", None)):
    if _sig is not None:
        try: signal.signal(_sig, _term_signal_handler)
        except Exception: pass

SESSIONS_DIR = os.path.join(PROJECT_ROOT, "sessions")

def _session_title(agent):
    hist = agent.history or []
    for m in hist:
        if isinstance(m, dict) and m.get("role") == "user" and (m.get("content") or "").strip():
            return (m.get("content") or "").strip()
    return time.strftime("%d-%m-%Y %H:%M")

def _session_slug(text, max_len=40):
    slug = re.sub(r"[^A-Za-z0-9]+", "-", (text or "").lower()).strip("-")
    return (slug[:max_len].strip("-") or "untitled")

def _session_filename(agent):
    base = _session_slug(_session_title(agent))
    candidate = os.path.join(SESSIONS_DIR, f"session_{base}.json")
    if not os.path.exists(candidate):
        return os.path.basename(candidate)
    for i in range(2, 1000):
        c2 = os.path.join(SESSIONS_DIR, f"session_{base}-{i}.json")
        if not os.path.exists(c2):
            return os.path.basename(c2)
    return f"session_{base}-{int(time.time())}.json"

def _session_meta(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return str(data.get("title", "") or ""), len(data.get("history", []) or []) // 2, str(data.get("oc_session", "") or "")
    except Exception:
        return "", 0, ""

def _session_autosave(agent):
    try:
        os.makedirs(SESSIONS_DIR, exist_ok=True)
        with open(os.path.join(SESSIONS_DIR, "autosave.json"), "w", encoding="utf-8") as f:
            json.dump({
                "system": agent.system,
                "history": agent.history,
                "pending": agent.pending,
                "oc_session": getattr(agent, "_oc_session", ""),
                "title": _session_title(agent),
            }, f, ensure_ascii=False)
    except Exception: pass

def _session_load(path, agent):
    with open(path, "r", encoding="utf-8") as f: data = json.load(f)
    agent.history = data.get("history", []) or []
    agent.system = data.get("system", agent.system)
    agent.pending = data.get("pending")
    agent._oc_session = str(data.get("oc_session", "") or "")

def _migrate_legacy_sessions():
    try:
        os.makedirs(SESSIONS_DIR, exist_ok=True)
        legacy = os.path.expanduser("~/.debz_sessions")
        if os.path.isdir(legacy):
            for f in os.listdir(legacy):
                if f.endswith(".json"):
                    src_f = os.path.join(legacy, f)
                    dst_f = os.path.join(SESSIONS_DIR, f)
                    if not os.path.exists(dst_f):
                        try: shutil.move(src_f, dst_f)
                        except Exception: pass
        for f in glob.glob(os.path.join(PROJECT_ROOT, "session_*.json")):
            try: shutil.move(f, os.path.join(SESSIONS_DIR, os.path.basename(f)))
            except Exception: pass
    except Exception: pass

class OverloadedError(Exception):
    def __init__(self, msg="", retry_after=None, status=None):
        super().__init__(msg)
        self.retry_after, self.status = retry_after, status

class PermanentError(Exception): pass
class _ToolsUnsupported(Exception): pass
class HoldSignal(Exception): pass
class CanceledByUser(Exception): pass

RATELIMIT_FILE = os.path.join(PROJECT_ROOT, ".ai-ratelimits.json")
_RL = {"limits": {}, "taps": {}}

def _rl_load():
    try:
        with open(RATELIMIT_FILE, "r", encoding="utf-8") as f: _RL["limits"] = json.load(f)
    except Exception: _RL["limits"] = {}

def _rl_save():
    try:
        with open(RATELIMIT_FILE, "w", encoding="utf-8") as f: json.dump(_RL["limits"], f, indent=2)
    except Exception: pass

def _rl_key(cfg):
    pid = cfg.get("_PROV_ID") or ""
    ep  = cfg.get("ENDPOINT") or ""
    host = ep.split("/")[2] if "://" in ep else ep
    return f"{pid or host}:{cfg.get('MODEL', '')}"

def _rl_learn(key, limit, window):
    cur = _RL["limits"].get(key)
    if not cur or cur.get("limit") != limit or cur.get("window") != window:
        _RL["limits"][key] = {"limit": int(limit), "window": int(window), "learned": time.strftime("%Y-%m-%dT%H:%M:%S")}
        _rl_save()

def _rl_learn_from_error(key, text):
    m = re.search(r"maximum\s+(\d+)\s+requests?\s+within\s+(\d+)\s*(seconds?|minutes?|hours?)", str(text), re.I)
    if not m: return None
    mult = {"second": 1, "seconds": 1, "minute": 60, "minutes": 60, "hour": 3600, "hours": 3600}
    window = int(m.group(2)) * mult.get(m.group(3).lower(), 60)
    _rl_learn(key, int(m.group(1)), window)
    return int(m.group(1)), window

def _rl_tap(key):
    lim = _RL["limits"].get(key)
    if not lim: return True, 0.0
    now, w = time.time(), _RL["taps"].setdefault(key, [])
    while w and w[0] < now - lim["window"]: w.pop(0)
    if len(w) >= lim["limit"]:
        return False, max(0.0, w[0] + lim["window"] - now)
    w.append(now)
    return True, 0.0

_rl_load()

def log_debug(msg):
    try:
        if os.path.isfile(DEBUG_LOG_PATH) and os.path.getsize(DEBUG_LOG_PATH) > 5 * 1024 * 1024:
            _rot = DEBUG_LOG_PATH + ".1"
            if os.path.isfile(_rot): os.remove(_rot)
            os.replace(DEBUG_LOG_PATH, _rot)
        with open(DEBUG_LOG_PATH, "a", encoding="utf-8") as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')} | {msg}\n")
    except Exception: pass

def fmt_k(n):
    return f"{n/1000.0:.1f}".rstrip("0").rstrip(".") + "k" if n >= 1000 else str(n)

def format_duration_compact(seconds):
    seconds = float(seconds or 0.0)
    if seconds < 60:
        return f"{seconds:.0f}s"
    minutes = seconds / 60
    if minutes < 60:
        return f"{minutes:.0f}m"
    hours = minutes / 60
    if hours < 24:
        remaining_min = int(minutes % 60)
        return f"{int(hours)}h {remaining_min}m" if remaining_min else f"{int(hours)}h"
    return f"{hours / 24:.1f}d"

def format_token_count_compact(value):
    value = int(value or 0)
    abs_value = abs(value)
    if abs_value < 1_000:
        return str(value)
    sign = "-" if value < 0 else ""
    units = ((1_000_000_000, "B"), (1_000_000, "M"), (1_000, "K"))
    for threshold, suffix in units:
        if abs_value >= threshold:
            scaled = abs_value / threshold
            text = f"{scaled:.{2 if scaled < 10 else 1 if scaled < 100 else 0}f}"
            if "." in text:
                text = text.rstrip("0").rstrip(".")
            return f"{sign}{text}{suffix}"
    return f"{value:,}"

def check_tools_server(cfg):
    try:
        r = requests.get(f"http://127.0.0.1:{cfg['TOOLS_PORT']}/api/health", timeout=5)
        return r.status_code == 200
    except Exception:
        try:
            r = requests.post(f"http://127.0.0.1:{cfg['TOOLS_PORT']}/", headers={"Content-Type":"application/json"}, json={}, timeout=5)
            return r.status_code in (200, 404)
        except Exception: return False

def _ask_permission(reason, command):
    options = [
        ("once",    "Izinkan SEKALI ini saja"),
        ("session", "Izinkan SESI ini (sampai /reset)"),
        ("always",  "Izinkan SELALU (auto-approve)"),
        ("deny",    "TOLAK perintah ini"),
    ]
    if sys.stdout.isatty():
        try:
            from prompt_toolkit.application import Application
            from prompt_toolkit.key_binding import KeyBindings
            from prompt_toolkit.layout import Layout, Window
            from prompt_toolkit.layout.controls import FormattedTextControl
            from prompt_toolkit.shortcuts.dialogs import Dialog, Box
            from prompt_toolkit.styles import Style as _Style
            from prompt_toolkit.application.current import create_app_session
            import asyncio

            try:
                asyncio.set_event_loop(asyncio.new_event_loop())
            except Exception:
                pass

            def _clean(s):
                return str(s or "").replace("\n", " ").replace("\r", " ").strip()

            reason_t = _clean(reason)
            cmd_t = "$ " + _clean(command)
            if len(reason_t) > 80: reason_t = reason_t[:79] + "…"
            if len(cmd_t) > 80: cmd_t = cmd_t[:79] + "…"

            idx = 0

            def _content():
                lines = []
                lines.append(("class:preason", reason_t + "\n"))
                lines.append(("class:pcmd", cmd_t + "\n"))
                lines.append(("", "\n"))
                for i, (val, label) in enumerate(options):
                    mark = "●" if i == idx else "○"
                    cls = "class:pselected" if i == idx else "class:plabel"
                    lines.append((cls, "  " + mark + " " + label + "\n"))
                lines.append(("", "\n"))
                lines.append(("class:phint", "  ↑↓ pilih · Enter = OK · Esc = TOLAK"))
                return lines

            kb = KeyBindings()

            @kb.add("down")
            def _down(event):
                nonlocal idx
                idx = (idx + 1) % len(options)
                event.app.invalidate()

            @kb.add("up")
            def _up(event):
                nonlocal idx
                idx = (idx - 1) % len(options)
                event.app.invalidate()

            @kb.add("enter")
            def _ok(event):
                event.app.exit(result=options[idx][0])

            @kb.add("escape")
            def _esc(event):
                event.app.exit(result=None)

            control = FormattedTextControl(_content, focusable=True)
            dialog = Dialog(
                title=HTML("<ansiyellow>⚠️  PERMISSION</ansiyellow>"),
                body=Box(Window(content=control, wrap_lines=True), padding=1),
                with_background=True,
            )
            st = _Style.from_dict({
                "dialog": "bg:#262626 #eeeeee",
                "dialog.title": "bold #e5c07b",
                "dialog.body": "bg:#262626 #eeeeee",
                "dialog shadow": "bg:#1a1a1a",
                "preason": "#eeeeee",
                "pcmd": "#b8b8b8",
                "plabel": "#b8b8b8",
                "pselected": "bold #0a0a0a bg:#fab283",
                "phint": "#b8b8b8",
            })

            with create_app_session():
                app = Application(layout=Layout(dialog), key_bindings=kb, style=st, full_screen=True)
                result = app.run()
            return result if result is not None else "deny"
        except Exception as e:
            log_debug(f"permission dialog error, fallback: {e}")

    answer = console.input(f"[{THEME['yellow']}]  Izinkan? [y]once · [s]ession · [a]allow · [N]tolak [/{THEME['yellow']}]").strip().lower()
    if answer in ("y", "yes"): return "once"
    if answer in ("s", "session"): return "session"
    if answer in ("a", "all", "always"): return "always"
    return "deny"

def _terminal_columns():
    try:
        return shutil.get_terminal_size((80, 24)).columns
    except Exception:
        return 80

def _terminal_width_for_streaming():
    return max(16, _terminal_columns() - 4)

def _strip_markdown_syntax(text):
    plain = Text.from_ansi(text or "").plain
    plain = re.sub(r"^\s{0,3}(?:[-_]\s*){3,}$", "", plain, flags=re.MULTILINE)
    plain = re.sub(r"^\s{0,3}(?:\*\s*){3}\s*$", "", plain, flags=re.MULTILINE)
    plain = re.sub(r"^\s{0,3}#{1,6}\s+", "", plain, flags=re.MULTILINE)
    plain = re.sub(r"(```+|~~~+)", "", plain)
    plain = re.sub(r"`([^`]*)`", r"\1", plain)
    plain = re.sub(r"!\[([^\]]*)\]\([^\)]*\)", r"\1", plain)
    plain = re.sub(r"\[([^\]]+)\]\([^\)]*\)", r"\1", plain)
    plain = re.sub(r"\*\*\*([^*]+)\*\*\*", r"\1", plain)
    plain = re.sub(r"(?<!\w)___([^_]+)___(?!\w)", r"\1", plain)
    plain = re.sub(r"\*\*([^*]+)\*\*", r"\1", plain)
    plain = re.sub(r"(?<!\w)__([^_]+)__(?!\w)", r"\1", plain)
    plain = re.sub(r"\*([^\s*][^*]*?[^\s*])\*", r"\1", plain)
    plain = re.sub(r"(?<!\w)_([^_]+)_(?!\w)", r"\1", plain)
    plain = re.sub(r"~~([^~]+)~~", r"\1", plain)
    plain = re.sub(r"\n{3,}", "\n\n", plain)
    return plain.strip("\n")

def _render_final_assistant_content(text, mode="render"):
    mode = str(mode or "render").strip().lower()
    if mode == "strip":
        return Text(_strip_markdown_syntax(text), style=THEME["text"])
    if mode == "raw":
        return Text.from_ansi(text or "")
    return Markdown(text.strip() or "(empty)", style=THEME["text"], code_theme="ansi_dark")

class UI:
    def banner(self, cfg, tools_on):
        _banner_text = Text(ASCII_BANNER.strip("\n"), style=THEME["retro_orange_bright"])
        console.print(Panel(
            _banner_text,
            box=box.DOUBLE_EDGE,
            border_style=THEME["border"],
            padding=(1, 4),
            title="[ SYSTEM INITIATED ]",
            title_align="center"
        ))
        console.print("")

    def boxed(self, title, renderable, bg=None, border=None, lines=False, tstyle=None, ebox=None):
        _border = (border if border in THEME else "border") if border else "border"
        _title = title if isinstance(title, Text) else Text(str(title), style=tstyle or THEME["text"])

        if lines:
            return Panel(
                renderable,
                title=_title,
                title_align="left",
                box=ebox or box.ROUNDED,
                border_style=THEME[_border],
                padding=(1, 3),
            )

        _bg = THEME.get(bg, THEME["box_bg"]) if bg else ""
        return Panel(
            renderable,
            title=_title,
            title_align="left",
            box=box.MINIMAL,
            style=("on " + _bg) if _bg else "none",
            padding=(1, 3),
        )

    def get_user_box(self, text):
        content = Text(text.strip(), style=THEME["text"])
        return Panel(
            content,
            title=Text(" 👻 R00T ", style="bold " + THEME["retro_orange_bright"]),
            title_align="right",
            border_style=THEME["retro_orange_bright"],
            box=box.ROUNDED,
            padding=(1, 3),
        )

    def user_box(self, text):
        console.print(self.get_user_box(text))
        console.print("")

    def ai_box(self, text):
        content = _render_final_assistant_content(text, mode=os.environ.get("DEBZ_FINAL_MD", "render"))
        panel = Panel(
            content,
            title=Text(" 🤖 DEBZ AI ", style="bold " + THEME["cyan"]),
            title_align="left",
            border_style=THEME["cyan"],
            box=box.ROUNDED,
            padding=(1, 3),
        )
        console.print(panel)
        console.print("")

    def ai_stream(self, text):
        try:
            console.print(text, end="", style=THEME["text"])
        except Exception:
            try: print(text, end="", flush=True)
            except Exception: pass

    def memory_bar(self, used, mx, pct):
        console.print(mem_line(used, mx, pct))
        console.print("")

def mem_line(used, mx, pct):
    width = max(18, min(50, _terminal_columns() - 38))
    filled = max(0, int(round(width * pct / 100)))

    if used > 0 and filled == 0:
        filled = 1

    color = THEME["red"] if pct >= 95 else THEME["yellow"] if pct >= 80 else THEME.get("cyan", THEME["green"])

    line = Text("  💾 MEMORY ", style="bold " + THEME["muted"])
    line.append("├", style=THEME["border"])
    line.append("█" * filled, style=color)
    line.append("░" * (width - filled), style=THEME["muted"])
    line.append("┤ ", style=THEME["border"])

    line.append(f"{int(pct + 0.5):3d}%", style="bold " + color)
    line.append(" │ ", style=THEME["border"])

    str_used = format_token_count_compact(used)
    str_mx = format_token_count_compact(mx)
    line.append(f"{str_used:>5} / {str_mx:<5}", style=THEME["text"])

    return line

class WorkTree:
    MAX_ROWS = 6
    SPINNER_FRAMES = ("◐", "◓", "◑", "◒")

    def __init__(self, tty, agent=None, user_msg=None, ui=None):
        self.tty = tty
        self.agent = agent
        self.user_msg = user_msg
        self.ui = ui
        self.cur = None
        self.rows = []
        self._total_steps = 0
        self.frozen = False
        self.live = None
        self.spinner_index = 0
        self.lock = threading.RLock()
        self.stream_text = None
        self._stream_t = 0.0
        self.thought_t0 = None
        self.thought_t_end = None
        self.thought_text = ""
        self.thought_active = False
        self.diff_lines = []
        self.diff_path = ""
        self.preview_lines = []
        self.preview_path = ""
        self.preview_kind = "read"
        self.closed = False
        self._mem_cache = None
        self._mem_cache_t = 0.0
        self._mem_last_len = -1
        self.notices = []
        self.proxy_rows = []
        self._turn_t0 = time.monotonic()
        self._last_active = time.monotonic()
        self._strip_src = None
        self._strip_out = ""
        self._prev_src = []
        self.prev_inner = []
        self._prev_hl_key = None
        self._stream_n, self._diff_n = self._plan_panels()
        self._start_live()
        self.ticker = threading.Thread(target=self._tick_loop, daemon=True)
        self.ticker.start()

    def _plan_panels(self):
        try:
            _h = console.size.height or 24
        except Exception:
            _h = 24
        budget = max(16, int(_h) - 1)
        base = 1 + self.MAX_ROWS
        _s = 6
        while base + _s > budget and _s > 4:
            _s -= 1
        return max(4, _s), 130

    def _fit_layout(self):
        try:
            _h = int(console.size.height or 24)
        except Exception:
            _h = 24
        h = max(8, _h - 1)
        self._drop_extra = False
        has_diff = bool(self.diff_lines)
        has_prev = bool(getattr(self, "_prev_src", None))
        has_user = getattr(self, "user_msg", None) is not None and getattr(self, "ui", None) is not None
        u_lines = 2 if has_user else 0
        self._fit_user_lines = 2
        if has_user:
            try:
                u_lines = len(self._user_msg_for_live().splitlines()) + 1
            except Exception:
                u_lines = 2
        n_parts = 2 + int(has_diff) + int(has_prev) + int(has_user)
        seps = max(0, n_parts - 1)
        todo = self.MAX_ROWS
        stream = getattr(self, "_stream_n", 6)
        diff = 0
        if has_diff or has_prev:
            diff = min(getattr(self, "_diff_n", 130), 24)
            if has_prev:
                diff = min(diff, 18)

        def measure(sep_lines):
            fixed = 2 * n_parts + sep_lines + u_lines

            def total():
                return todo + stream + diff * (int(has_diff) + int(has_prev)) + fixed

            return total

        total = measure(seps)
        while total() > h and diff > 2 and (has_diff or has_prev):
            diff -= 1
        while total() > h and stream > 2:
            stream -= 1
        while total() > h and todo > 1:
            todo -= 1
        no_sep = False
        if total() > h and seps > 0:
            no_sep = True
            total = measure(0)
            diff = min(diff, 12 if has_prev else 20)
            while total() > h and diff > 2 and (has_diff or has_prev):
                diff -= 1
            while total() > h and stream > 2:
                stream -= 1
            while total() > h and todo > 1:
                todo -= 1
        if total() > h:
            if u_lines > 1:
                self._fit_user_lines = 1
                try:
                    u_lines = len(self._user_msg_for_live().splitlines()) + 1
                except Exception:
                    u_lines = 1
                total = measure(seps if not no_sep else 0)
                while total() > h and diff > 1 and (has_diff or has_prev):
                    diff -= 1
                while total() > h and stream > 1:
                    stream -= 1
                while total() > h and todo > 1:
                    todo -= 1
            while total() > h and diff > 1 and (has_diff or has_prev):
                diff -= 1
            while total() > h and stream > 1:
                stream -= 1
            while total() > h and todo > 1:
                todo -= 1
            if total() > h and u_lines > 0:
                self._fit_user_lines = 0
                u_lines = 0
                total = measure(seps if not no_sep else 0)
                while total() > h and diff > 1 and (has_diff or has_prev):
                    diff -= 1
                while total() > h and stream > 1:
                    stream -= 1
                while total() > h and todo > 1:
                    todo -= 1
            if total() > h and (has_diff or has_prev):
                self._drop_extra = True
                has_diff = has_prev = False
                n_parts = 2 + int(has_user and self._fit_user_lines > 0)
                seps = max(0, n_parts - 1)
                diff = 0
                total = measure(seps if not no_sep else 0)
                while total() > h and stream > 1:
                    stream -= 1
                while total() > h and todo > 1:
                    todo -= 1
        self._no_sep = no_sep
        return todo, stream, diff

    def _user_msg_for_live(self):
        try:
            _w2 = max(10, _terminal_columns() - 10)
            _maxlines = max(1, int(getattr(self, "_fit_user_lines", 2)))
            lines = str(getattr(self, "user_msg", "") or "").strip().splitlines()
            keep = []
            for l in lines:
                while len(l) > _w2:
                    keep.append(l[:_w2])
                    l = l[_w2:]
                keep.append(l)
            while len(keep) > _maxlines:
                keep.pop()
            return "\n".join(keep or [""])
        except Exception:
            return str(getattr(self, "user_msg", "") or "")[:120]

    def _tick_loop(self):
        while True:
            time.sleep(0.09)
            try:
                with self.lock:
                    if self.closed:
                        break
                    if not self.frozen and (self.cur or self.thought_active or self.stream_text):
                        self.spinner_index += 1
                        self._refresh()
            except Exception:
                pass

    def _live_raw(self, codes):
        try:
            f = getattr(console, "file", None)
            if f is not None:
                f.write(codes)
                f.flush()
        except Exception:
            pass

    def _start_live(self):
        global _ACTIVE_TREE
        if getattr(self, "closed", False):
            return
        with self.lock:
            if not self.tty:
                return
            if self.live is not None:
                try:
                    self.live.stop()
                except Exception:
                    pass
                self.live = None
            try:
                self.live = Live(
                    self._renderable(),
                    console=console,
                    refresh_per_second=12,
                    transient=True,
                    auto_refresh=False,
                    vertical_overflow="crop",
                )
                self.live.start(refresh=True)
                _ACTIVE_TREE = self
            except Exception:
                self.live = None

    def _stop_live(self, erase=True, hard=False):
        global _ACTIVE_TREE
        with self.lock:
            if _ACTIVE_TREE is self:
                _ACTIVE_TREE = None
            if self.live is not None:

                try:
                    self.live.update(Text(""), refresh=True)
                except Exception:
                    pass
                try:
                    self.live.stop()
                except Exception:
                    pass
                self.live = None
            if erase and self.tty:
                self._wiped = False

                self._live_raw("\x1b[0m\x1b[?25h")
                if hard:

                    self._live_raw("\x1b[2J\x1b[3J\x1b[H")
                    self._wiped = True
                else:
                    self._live_raw("\r\x1b[J")

    def _row(self, icon, label, state="running", elapsed=None):
        text = Text(no_wrap=True, overflow="ellipsis")
        text.append("  ")

        if state == "running":
            marker = self.SPINNER_FRAMES[self.spinner_index % len(self.SPINNER_FRAMES)]
            text.append(marker + " ", style=THEME["retro_orange_bright"])
        elif state == "waiting":
            text.append("⏸ ", style=THEME["yellow"])
        elif state == "done":
            text.append("✓ ", style=THEME["green"])
        else:
            text.append("✗ ", style=THEME["red"])

        text.append(
            str(label),
            style=THEME["retro_orange_bright"] if state == "running" else THEME["yellow"] if state == "waiting" else THEME["text"]
        )

        if state == "running": text.append("  …", style=THEME["muted"])
        elif state == "waiting": text.append("  menunggu", style=THEME["yellow"])
        elif elapsed is not None: text.append(f"  {format_duration_compact(elapsed)}", style=THEME["muted"])
        return text

    def _mem_snapshot(self):
        try:
            now = time.monotonic()
            extra = str(getattr(self, "_strip_out", "") or "")
            if self._mem_cache is not None and (now - self._mem_cache_t) < 0.8 and len(extra) == getattr(self, "_mem_last_len", -1):
                return self._mem_cache
            used, mx, pct = memory_usage(self.agent, getattr(self, "msgs", None), extra_text=extra)
            self._mem_cache = mem_line(used, mx, pct)
            self._mem_cache_t = now
            self._mem_last_len = len(extra)
            return self._mem_cache
        except Exception:
            pass
        return Text("")

    def stream_update(self, text):
        with self.lock:
            if self.frozen or not text: return
            self.stream_text = text
            self._last_active = time.monotonic()
            now = time.monotonic()
            if now - self._stream_t >= 0.12:
                self._stream_t = now
                self._refresh()

    def thought_update(self, text, done=False):
        with self.lock:
            if self.frozen: return
            self._last_active = time.monotonic()
            if done:
                if self.thought_t0 is None:
                    self.thought_t0 = time.monotonic()
                self.thought_t_end = time.monotonic()
                self.thought_active = False
                self.thought_text = text or ""
            else:
                if self.thought_t0 is None or (self.thought_t_end is not None and not self.thought_active):
                    self.thought_t0 = time.monotonic()
                    self.thought_t_end = None
                self.thought_active = True
                self.thought_text = text or ""
            now = time.monotonic()
            if done or now - self._stream_t >= 0.12:
                self._stream_t = now
                self._refresh()

    def _todo_rows(self):
        rows = list(self.rows)
        if self.cur and rows and not self.frozen:
            try:
                rows[-1] = self._row(self.cur["icon"], self.cur["label"], "running")
            except Exception:
                pass
        fit = self._fit if getattr(self, "_fit", None) else self._fit_layout()
        _mr = max(1, fit[0])
        _proxy = getattr(self, "proxy_rows", None) or []
        _pn = min(2, len(_proxy))
        _slots = max(1, _mr - _pn)
        shown = rows[-_slots:]
        hidden = max(0, len(rows) - _slots)
        out = []
        if hidden > 0:
            out.append(Text(f"  🎯 {hidden} proses selesai · total {self._total_steps}", style=THEME["muted"], no_wrap=True, overflow="ellipsis"))
        out.extend(shown)
        out.extend(_proxy[-_pn:])
        out = out[:_mr]
        while len(out) < _mr:
            out.append(Text("", style=THEME["muted"], no_wrap=True))
        return out

    def _todo_panel(self):
        return Group(*self._todo_rows())

    def _stream_panel(self):
        _w = _terminal_width_for_streaming()

        _wrap = max(16, _terminal_columns() - 10)
        _n = self._fit[1] if getattr(self, "_fit", None) else self._stream_n
        if (self.diff_lines or getattr(self, "_prev_src", None)) and not getattr(self, "_fit", None):
            _n = max(4, min(_n, 6))
            if self.diff_lines and getattr(self, "_prev_src", None):
                _n = max(3, _n - 1)
        lines = []

        for nt in self.notices[-2:]:
            try:
                _nt = Text.from_markup("  " + str(nt), style=THEME["muted"])
            except Exception:
                _nt = Text("  " + str(nt), style=THEME["muted"])
            if len(_nt.plain) > _w:
                _nt = Text(_nt.plain[:_w], style=THEME["muted"])
            lines.append(_nt)

        if self.thought_t0 is not None:
            _end = self.thought_t_end if self.thought_t_end is not None else time.monotonic()
            _dur = max(0.0, _end - self.thought_t0)
            _mark = " ◐" if self.thought_active else ""
            lines.append(Text(
                f"  💭 Berpikir {_dur:.1f}s{_mark}",
                style=THEME["retro_orange_bright"] if self.thought_active else THEME["magenta"],
                no_wrap=True, overflow="ellipsis",
            ))
            if self.thought_active and self.thought_text and len(lines) < _n:
                _prev = self.thought_text.strip().splitlines()
                if _prev:
                    _pl = _prev[-1].strip()
                    if len(_pl) > _w - 6:
                        _pl = _pl[:max(0, _w - 7)] + "…"
                    lines.append(Text("    " + _pl, style=THEME["muted"], no_wrap=True, overflow="ellipsis"))

        _raw = self.stream_text or ""
        if getattr(self, "_strip_src", None) is not _raw:
            self._strip_src = _raw
            self._strip_out = _strip_reasoning_tags(_raw).strip()
        t = self._strip_out
        body_n = _n - len(lines) - 2
        if t:
            wrapped = []
            for raw in t.split("\n"):
                raw = raw.rstrip()
                if not raw:
                    wrapped.append("")
                    continue
                sub = textwrap.wrap(raw, width=_wrap, break_long_words=False, break_on_hyphens=False)
                wrapped.extend(sub or [""])
            if body_n <= 0:
                view = []
            else:
                cut = len(wrapped) > body_n
                view = wrapped[-body_n:]
                if cut and view:
                    view[0] = ("… " + view[0][:max(0, _w - 2)]) if view[0] else "…"
            lines.extend(Text(p or " ", style=THEME["text"], no_wrap=True, overflow="ellipsis") for p in view)
        else:
            if self.cur is not None or self.rows:
                lines.append(Text("tunggu lagi diproses…", style=THEME["muted"], no_wrap=True, overflow="ellipsis"))
            else:
                lines.append(Text(" ", style=THEME["muted"], no_wrap=True))

        lines = lines[-max(0, _n - 2):] if _n >= 2 else []
        while _n >= 2 and len(lines) < _n - 1:
            lines.append(Text("", style=THEME["muted"], no_wrap=True))
        lines.append(self._loading_bar(_w))

        return Group(*lines)

    def _loading_bar(self, w):
        idle = time.monotonic() - self._last_active
        if idle >= 45:
            color = THEME["red"]
        elif idle >= 15:
            color = THEME["yellow"]
        else:
            color = THEME["retro_orange_bright"]
        seg = 4
        track = max(seg + 2, w - 16)
        span = max(1, track - seg)
        idx = self.spinner_index % max(1, span * 2)
        pos = idx if idx < span else span * 2 - idx
        bar = Text(no_wrap=True, overflow="ellipsis")
        bar.append("  ", style=THEME["muted"])
        bar.append("│", style=THEME["border"])
        bar.append("─" * pos, style=THEME["muted"])
        bar.append("█" * seg, style="bold " + color)
        bar.append("─" * (span - pos), style=THEME["muted"])
        bar.append("│", style=THEME["border"])
        bar.append(f" ⏳ {int(idle)}s", style=color)
        return bar

    def _diff_panel(self):
        _n = self._fit[2] if getattr(self, "_fit", None) else self._diff_n
        src = list(self.diff_lines or [])
        lines = []
        if len(src) > _n:
            lines = src[:_n - 1]
            lines.append(Text(f"  … {len(src) - _n + 1} baris lagi · beda total {len(src)}", style=THEME["muted"], no_wrap=True, overflow="ellipsis"))
        else:
            lines = [ln for ln in src]
        if not lines:
            lines.append(Text("", style=THEME["muted"], no_wrap=True))
        return Group(*lines)

    def _preview_panel(self):
        _n = self._fit[2] if getattr(self, "_fit", None) else self._diff_n
        src = list(getattr(self, "_prev_src", None) or [])
        key = (len(src), src[:1], src[-1:] if src else "", self.preview_kind, self.preview_path)
        if getattr(self, "_prev_hl_key", None) != key:
            hint = self.preview_path
            _ln0 = 1
            if self.preview_kind == "exec":
                hint = "bash"
            elif self.preview_kind == "search":
                hint = ""
                _ext_alts = "|".join(sorted((k for k in _EXT_LANG_MAP if re.fullmatch(r"[a-z0-9]{1,10}", k)), key=len, reverse=True))
                _ext_re = re.compile(r"([A-Za-z0-9_.~\-/]+\.(?:%s))\b" % _ext_alts)
                for ln in src[:30]:
                    m = _ext_re.search(ln)
                    if m:
                        hint = m.group(1)
                        break
            else:

                hint = os.path.basename(re.sub(r"\s+baris\s+\d+(?:-\d+)?\s*$", "", str(self.preview_path or ""))) or ""

                _m = re.search(r"baris\s+(\d+)(?:-\d+)?\s*$", str(self.preview_path or ""))
                if _m:
                    _ln0 = int(_m.group(1))
            inner = []
            hl_all = None
            if src and self.preview_kind != "exec":
                hl_all = _colorize_lines("\n".join(src), hint, None)
            for i, ln in enumerate(src, 1):
                t = Text(f"{_ln0 + i - 1:>4d} │ ", style=THEME["muted"])
                hl = (hl_all[i - 1] if hl_all is not None and i - 1 < len(hl_all) else None)
                if hl is not None:
                    t.append_text(hl)
                elif self.preview_kind == "exec":
                    hl = _highlight_code_text(ln, "bash", None)
                    if hl is not None:
                        t.append_text(hl)
                    else:
                        t.append(ln, style=THEME["green"])
                else:
                    t.append(ln, style=THEME["text"])
                inner.append(t)
            self.prev_inner = inner
            self._prev_hl_key = key
        lines = list(self.prev_inner)
        if len(lines) > _n:
            lines = lines[: _n - 1]
            lines.append(Text(f"  … {len(src) - _n + 1} baris disembunyikan · total {len(src)} baris", style=THEME["muted"], no_wrap=True, overflow="ellipsis"))
        if not lines:
            lines.append(Text("(tidak ada output)", style=THEME["muted"], no_wrap=True))
        for _l in lines:
            try:
                _l.no_wrap = True
                _l.overflow = "ellipsis"
            except Exception:
                pass
        return Group(*lines)

    def set_diff(self, path, diff_lines):
        with self.lock:
            self.diff_path = os.path.basename(path) if path else "?"
            self.diff_lines = diff_lines or []
            self._refresh()

    def clear_diff(self):
        with self.lock:
            self.diff_lines = []
            self._refresh()

    def set_preview(self, path, text, kind="read"):
        with self.lock:
            self.preview_path = ""
            self.preview_kind = kind if kind in ("read", "exec", "search") else "read"
            if kind == "read":
                self.preview_path = os.path.basename(str(path or "").rstrip("/")) or str(path or "")
            else:
                self.preview_path = str(path or "")[:60]
            self._prev_src = str(text or "").splitlines()
            self._prev_hl_key = None
            self._refresh()

    def _stream_idle(self):
        with self.lock:
            if self.notices:
                return False
            if self.thought_t0 is not None:
                return False
            if (self.stream_text or "").strip():
                return False
            return True

    def _renderable(self):
        with self.lock:
            self._fit = self._fit_layout()
            parts = []
            parts.append(self.ui.boxed(_two_tone_title("🖥️ PROSES", "", THEME["green"]), self._todo_panel(), lines=True, ebox=_BOX_TOPBOT))
            if not self._stream_idle():
                parts.append(self.ui.boxed(_two_tone_title("💬 RESPON", "", THEME["cyan"]), self._stream_panel(), lines=True))
            if self.diff_lines and not getattr(self, "_drop_extra", False):
                diff_title = _two_tone_title("📝 EDIT", self.diff_path or "", THEME["yellow"], THEME["magenta"])
                parts.append(self.ui.boxed(diff_title, self._diff_panel(), lines=True))
            if getattr(self, "_prev_src", None) and not getattr(self, "_drop_extra", False):
                if self.preview_kind == "exec":
                    prev_title = _two_tone_title("🔳 SHELL", "", THEME["red"])
                elif self.preview_kind == "search":
                    prev_title = _two_tone_title("🔍 CARI", self.preview_path, THEME["magenta"], THEME["cyan"])
                else:
                    prev_title = _two_tone_title("📖 BACA", self.preview_path, THEME["blue"], THEME["green"])
                parts.append(self.ui.boxed(prev_title, self._preview_panel(), lines=True))
            if getattr(self, "user_msg", None) is not None and getattr(self, "ui", None) is not None and getattr(self, "_fit_user_lines", 2) > 0:
                parts.append(self.ui.get_user_box(self._user_msg_for_live()))
            elements = []
            _no_sep = bool(getattr(self, "_no_sep", False))
            for i, p in enumerate(parts):
                if i and not _no_sep:
                    elements.append(Text("", style=THEME["muted"], no_wrap=True))
                elements.append(p)
            return Group(*elements)

    def _refresh(self):
        with self.lock:
            if self.frozen or getattr(self, "closed", False): return
            if self.live is None: self._start_live()
            if self.live:
                if self.cur and self.rows:
                    self.rows[-1] = self._row(self.cur["icon"], self.cur["label"], "running")
                try:
                    self.live.update(self._renderable(), refresh=True)
                except Exception:
                    pass

    def _append(self, row):
        with self.lock:
            if self.frozen: return
            self._last_active = time.monotonic()
            self.rows.append(row)
            if len(self.rows) > 60:
                self.rows = self.rows[-60:]
            self._refresh()

    def step(self, icon, label):
        with self.lock:
            if self.frozen: return
            if self.cur: self.done()
            self.cur = {"icon": icon, "label": label, "t0": time.monotonic()}
            self._total_steps += 1
            self._append(self._row(icon, label, "running"))

    def done(self, note="✓", color="green"):
        with self.lock:
            if not self.cur:
                return

            elapsed = int(time.monotonic() - self.cur["t0"])

            if note in ("⏸", "⏸ Lagi nunggu"):
                state = "waiting"
            elif note == "✓":
                state = "done"
            else:
                state = "error"

            if self.rows and not self.frozen:
                self.rows[-1] = self._row(self.cur["icon"], self.cur["label"], state, elapsed)
                if self.live:
                    try:
                        self.live.update(self._renderable(), refresh=True)
                    except Exception:
                        pass
            self.cur = None

    def suspend(self):
        with self.lock:
            self.frozen = True
            self._stop_live()

    def resume(self):
        with self.lock:
            self.frozen = False
            self._start_live()
            self._refresh()

    def ask(self, reason, command):
        self.suspend()
        console.print()
        try:
            return _ask_permission(reason, command)
        finally:
            self.resume()

    def below(self, text):
        with self.lock:
            if str(text).strip():
                self.notices.append(str(text))
                if len(self.notices) > 3:
                    self.notices = self.notices[-3:]
            if not self.frozen:
                self._refresh()

    def proxy_status(self, text, icon="", color="cyan"):
        """Baris status proxy di PANEL PROGRESS (worktree) — BUKAN di bubble LIVE."""
        with self.lock:
            if self.frozen or not text:
                return

            t = str(text).strip()
            _w = max(16, _terminal_width_for_streaming() - 5)
            if len(t) > _w:
                t = t[: max(0, _w - 1)] + "…"

            color_style = THEME.get(color, color) or THEME["cyan"]
            line = Text("  ", style=THEME["muted"])

            if icon:
                line.append(f"{icon} ", style=THEME["cyan"])
            line.append(t, style=color_style)

            line.no_wrap = True
            line.overflow = "ellipsis"

            if not hasattr(self, "proxy_rows"):
                self.proxy_rows = []

            self.proxy_rows.append(line)
            self.proxy_rows = self.proxy_rows[-3:]
            self._refresh()

    def close(self):
        with self.lock:
            self.closed = True
            self.frozen = True
            self._stop_live(hard=True)
            self.cur = None


class Tools:
    def __init__(self, cfg):

        self.base = f"http://127.0.0.1:{cfg['TOOLS_PORT']}"
        self.token = cfg.get("TOOLS_TOKEN", "")
        self.cwd = PROJECT_ROOT

    def _post(self, path, body):
        url = f"{self.base}{path}"
        headers = {
            "Content-Type": "application/json",
            "x-tools-token": self.token
        }
        payload = {**body, "token": self.token}

        try:
            r = requests.post(url, headers=headers, json=payload, timeout=1800)

            if r.status_code == 403:
                cfg = _load_config()
                if cfg.get("TOOLS_TOKEN") and cfg["TOOLS_TOKEN"] != self.token:
                    self.token = cfg["TOOLS_TOKEN"]
                    headers["x-tools-token"] = self.token
                    payload["token"] = self.token

                    r = requests.post(url, headers=headers, json=payload, timeout=1800)

            return r.json()
        except Exception as e:
            return {"error": f"Tool Server Offline / Error: {str(e)}"}

    def exec(self, cmd, cwd=None, approved=False):
        return self._post("/api/exec", {"command": cmd, "cwd": cwd or self.cwd, "approved": approved})

    def read(self, path):
        return self._post("/api/fs_read", {"path": path})

    def write(self, path, content, append=False):
        return self._post("/api/fs_write", {"path": path, "content": content, "append": append})

    def list(self, path):
        return self._post("/api/fs_list", {"path": path})

    def search(self, pattern, path=PROJECT_ROOT, content=False):
        return self._post("/api/fs_search", {"pattern": pattern, "path": path, "content": content})

    def http_request(self, url, method="GET", headers=None, body=None, timeout=300):
        return self._post("/api/http", {"url": url, "method": method, "headers": headers or {}, "body": body, "timeout": timeout})

    def download(self, url, path, max_mb=200, timeout=300, download_id=None):
        return self._post("/api/download", {"url": url, "path": path, "max_mb": max_mb, "timeout": timeout, "download_id": download_id or ""})

    def download_cancel(self, download_id):
        return self._post("/api/download_cancel", {"download_id": download_id})

    def db(self, db_path, sql, params=None):
        return self._post("/api/db", {"db_path": db_path, "sql": sql, "params": params or []})

    def archive(self, action, archive_path, files=None, target_dir=None):
        return self._post("/api/archive", {"action": action, "archive_path": archive_path, "files": files or [], "target_dir": target_dir})

    def ps(self, pattern=None):
        return self._post("/api/ps", {"pattern": pattern or ""})

    def kill(self, pid=None, pattern=None, signal=15):
        return self._post("/api/kill", {"pid": pid, "pattern": pattern or "", "signal": signal})

    def skill(self, action, name=None, category=None, content=None, pattern=None):
        return self._post("/api/skill", {"action": action, "name": name, "category": category, "content": content, "pattern": pattern})

    def note(self, action, key=None, content=None, pattern=None):
        return self._post("/api/note", {"action": action, "key": key, "content": content, "pattern": pattern})

    def pkg(self, action, package=None):
        return self._post("/api/pkg", {"action": action, "package": package or ""})

    def web_search(self, query, max_results=6, timeout=120):
        return self._post("/api/web_search", {"query": query, "max_results": max_results, "timeout": timeout})

    def backup(self, action, name=None):
        return self._post("/api/backup", {"action": action, "name": name or ""})

    def scheduler(self, action, id=None, name=None, command=None, schedule=None, timeout=120):
        return self._post("/api/scheduler", {"action": action, "id": id or "", "name": name or "", "command": command or "", "schedule": schedule or "", "timeout": timeout})


ALLOW_ALL_FILE = os.path.join(PROJECT_ROOT, ".approval_always")

def _allow_all_get() -> bool:
    return os.path.isfile(ALLOW_ALL_FILE)

def _allow_all_set(on: bool) -> bool:
    try:
        if on:
            with open(ALLOW_ALL_FILE, "w") as f:
                f.write(time.strftime("%Y-%m-%dT%H:%M:%S"))
        elif os.path.isfile(ALLOW_ALL_FILE):
            os.remove(ALLOW_ALL_FILE)
    except Exception:
        pass
    return _allow_all_get()

def _load_agents_rules():
    _f = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'AGENTS.md')
    try:
        if os.path.isfile(_f):
            with open(_f, 'r', encoding='utf-8') as fh:
                txt = fh.read().strip()
            if txt:
                return '\n\n===== RULES WAJIB (baca & patuhi, dari AGENTS.md) =====\n' + txt
    except Exception:
        pass
    return ''


class Agent:
    def __init__(self, cfg, tty):
        self.cfg = cfg
        self.tty = tty
        self.history = []
        self.pending = None
        self.tools_on = True
        self.tools = Tools(cfg)

        self.allow_all = _allow_all_get()
        self.allow_session = False

        self._cur_proxy = None
        self._proxy_fail_next = False
        self._use_proxy = False
        self._force_proxy = False

        self.system = (
            f"Anda adalah {BRAND_NAME}, Polyglot Principal Software Engineer, Senior Enterprise Architect, "
            "dan Expert Code Reviewer yang menguasai seluruh ekosistem pemrograman (JavaScript/TypeScript, "
            "Python, Go, Rust, Java, C++, C#, PHP, HTML, CSS, Ruby, SQL, serta berbagai framework modern). "
            "Anda memberikan jawaban dengan ketepatan analisis tingkat tinggi sekelas Gemini Pro dan GPT-4o.\n"
            "Secara otomatis, Anda wajib menyesuaikan diri berdasarkan bahasa pemrograman yang saya berikan dan mematuhi aturan berikut:\n"
            "1. ZERO FLUFF (TANPA BASA-BASI)\n"
            "   - Jangan pernah menulis kalimat pembuka seperti 'Tentu, ini kodenya...' atau kalimat penutup 'Semoga membantu!'.\n"
            "   - LANGSUNG berikan analisis arsitektur, potongan kode, atau perbaikan error.\n"
            "2. ADAPTIF TERHADAP EKOSISTEM BAHASA (ECOSYSTEM-SPECIFIC BEST PRACTICES)\n"
            "   Jika kode menggunakan:\n"
            "   - TypeScript/JavaScript: Patuhi ESM, Strict Mode, Functional Programming, asinkronus yang bersih (async/await), dan minimalisasi dependensi npm.\n"
            "   - Python: Terapkan PEP 8, Type Hinting, struktur efisien (list comprehension/generator), dan penanganan memori yang tepat.\n"
            "   - Go: Terapkan idiomatic Go, penanganan error eksplisit (if err != nil), efisiensi goroutine/channel, dan zero-allocation jika memungkinkan.\n"
            "   - Rust: Patuhi aturan kepemilikan (ownership/borrowing), hindari 'unsafe' dan 'unwrap' tanpa penanganan, serta optimalkan manajemen memori.\n"
            "   - Java/C#: Patuhi SOLID principles, OOP yang bersih, penanganan eksepsi yang tepat, dan design patterns standar industri.\n"
            "   - C/C++: Prioritaskan manajemen memori yang aman (hindari memory leaks/buffer overflow), efisiensi pointer, dan optimasi kompiler.\n"
            "   - SQL: Terapkan optimasi indeks, hindari N+1 query, cegah SQL Injection dengan prepared statements, dan perhatikan efisiensi JOIN.\n"
            "3. STRUKTUR RESPONS (WAJIB)\n"
            "   - ANALISIS SINGKAT: Maksimal 2-3 kalimat di awal tentang pendekatan logika atau akar masalah (root cause) jika itu sebuah bug.\n"
            "   - BLOK KODE (PRODUCTION-READY): Tulis kode yang utuh, bersih, aman, memiliki error handling yang kuat, dan siap pakai di lingkungan produksi. Berikan komentar singkat pada baris yang kompleks.\n"
            "   - REKOMENDASI LANJUTAN: Gunakan poin-poin singkat hanya untuk menjelaskan kompleksitas algoritma (Big-O), celah keamanan yang dihindari, atau opsi optimasi skala besar.\n"
            "4. SIKAP REVIEWS & KOREKSI CRITICAL\n"
            "   Jika pendekatan atau arsitektur kode yang saya berikan suboptimal, rentan bug, atau tidak aman, koreksi saya secara langsung dan tunjukkan letak kesalahannya beserta solusi alternatif yang lebih efisien.\n"
            "5. ZERO PASSIVE (WAJIB) — DILARANG balas ack kosong/pasif kayak 'Siap, gue masih wait', 'belum ngedit apa-apa', 'kalau ada kode/path di-patch kirim', 'gue diem', dsb. Saat user nyebut path file + keluhan, itu perintah ACTION: baca file-nya, telusuri root cause, kasih diagnosa + patch langsung (backup dulu, show diff). Kalau user cek hidup ('MASIH LA GUA JALAN?'), jawab langsung statusnya."
        ) + _load_agents_rules()

    def _build_body(self, msgs):
        max_tokens = min(int(self.cfg.get("MAX_OUTPUT_TOKENS") or self.cfg.get("MAX_TOKENS") or 8192), 8192)
        body = {
            "model": self.cfg["MODEL"],
            "messages": msgs,
            "max_tokens": max_tokens
        }

        if self.tools_on:
            body["tools"] = self.TOOLS_SCHEMA

        is_openrouter = "openrouter.ai" in self.cfg.get("ENDPOINT", "").lower()

        for key, value in (self.cfg.get("EXTRA") or {}).items():
            if key in TRANSPORT_KEYS or value is None:
                continue
            if key == "reasoning" and isinstance(value, dict) and not is_openrouter:
                continue
            if isinstance(value, (dict, list, str, int, float, bool)):
                body[key] = value

        return body

    def _llm(self, msgs, on_delta=None, on_thought=None):
        body = self._build_body(msgs)
        try:
            _ok, _wait = _rl_tap(_rl_key(self.cfg))
            if not _ok and _wait > 0:
                _w = min(_wait + 1.5, 120)
                if _ACTIVE_TREE is not None:
                    _ACTIVE_TREE.below(f"[dim]⏳ rate-limit · tunggu {int(_w)}s[/dim]")
                else:
                    console.print(f"  [dim]⏳ Waduh: rate-limit · tunggu {int(_w)}s[/dim]")
                time.sleep(_w)
                _rl_tap(_rl_key(self.cfg))
        except Exception:
            pass

        for _ in range(2):
            try:
                return self._llm_request(body, on_delta=on_delta, on_thought=on_thought)
            except _ToolsUnsupported:
                self.tools_on = False
                body.pop("tools", None)

        raise PermanentError("Tools tidak didukung provider ini.")

    def _llm_request(self, body, on_delta=None, on_thought=None):
        self._cur_proxy = None
        _t0 = time.monotonic()
        _req_timeout = (15, max(30, STREAM_IDLE_S + 15))
        _stream = on_delta is not None

        if _stream and not body.get("stream"):
            body["stream"] = True

        try:
            # PROXY-FREE: request selalu direct.
            self._proxy_fail_next = False
            r = requests.post(
                self.cfg["ENDPOINT"],
                headers=_get_headers(self.cfg),
                json=body,
                timeout=_req_timeout,
                stream=_stream
            )

        except requests.exceptions.SSLError as e:
            if not getattr(self, "_force_proxy", False):
                self._force_proxy = True
                self._proxy_fail_next = True
                raise OverloadedError(f"direct SSL error ({e}) → coba lagi", retry_after=5, status=0)

            raise HoldSignal(f"SSL error: {str(e)}")

        except requests.exceptions.RequestException as e:
            raise HoldSignal(f"HTTP Request Timeout / Terputus: {str(e)}")

        if not r.ok:
            try:
                err_json = r.json()
                err_msg = str(err_json.get("error", {}).get("message", err_json.get("error", r.text)))
            except Exception:
                err_msg = r.text

            if _stream and r.status_code in (400, 422) and "stream" in err_msg.lower():
                body.pop("stream", None)
                return self._llm_request(body, on_delta=None, on_thought=None)

            if r.status_code == 400 and "token" in err_msg.lower():
                _mt = int(body.get("max_tokens") or 0)
                if _mt > 8192:
                    body["max_tokens"] = _mt // 2
                    log_debug(f"max_tokens turun: {_mt} -> {body['max_tokens']}")
                    return self._llm_request(body, on_delta=on_delta, on_thought=on_thought)

            if self.tools_on and r.status_code in (400, 422) and any(k in err_msg.lower() for k in ("tool", "function", "support", "invalid type")):
                raise _ToolsUnsupported(err_msg)

            if r.status_code in (429, 500, 502, 503, 504, 529):
                _ra = None
                try:
                    _h = r.headers.get("Retry-After") or r.headers.get("retry-after")
                    if _h:
                        if str(_h).strip().isdigit():
                            _ra = int(str(_h).strip())
                        else:
                            from email.utils import parsedate_to_datetime
                            import datetime as _dt
                            _ra = max(0, int((parsedate_to_datetime(str(_h)) - _dt.datetime.now(_dt.timezone.utc)).total_seconds()))
                except Exception:
                    _ra = None

                raise OverloadedError(f"HTTP {r.status_code}: {err_msg[:80]}", retry_after=_ra, status=r.status_code)

            raise PermanentError(f"HTTP {r.status_code}: {err_msg[:120]}")

        if _stream:
            return self._parse_sse(r, on_delta, on_thought)

        if not r.text.strip():
            raise PermanentError("API provider mengembalikan response kosong.")

        try:
            d = r.json()
        except json.JSONDecodeError:
            raise PermanentError("API provider merespon dengan data non-JSON.")

        if "error" in d and isinstance(d["error"], dict):
            raise PermanentError(str(d.get("error")))

        return d.get("choices", [{}])[0].get("message", {})

    def _parse_sse(self, r, on_delta, on_thought=None):
        content_parts, tool_acc = [], {}
        reason_parts = []
        _thought_done = False
        _reason_keys = ("reasoning_content", "reasoning", "thinking", "thought")

        _idle = STREAM_IDLE_S
        _t_start = time.monotonic()
        _deadline = _t_start + _idle
        _abs_deadline = _t_start + STREAM_ABS_S
        _got_data = False

        def _idle_err(msg):
            e = OverloadedError(msg, retry_after=5, status=0)
            e._soft = not _got_data
            return e

        try:
            r.encoding = "utf-8"
            for raw in r.iter_lines(decode_unicode=True):
                if not raw:
                    continue

                line = raw.strip()

                if line.startswith(":") or line.startswith("event:"):
                    if time.monotonic() >= _abs_deadline:
                        raise _idle_err("stream absolute timeout (cuma keep-alive, tanpa data) -> retry")
                    _deadline = time.monotonic() + _idle
                    continue

                if line.startswith("data:"):
                    payload = line[5:].strip()
                elif line.startswith("{"):
                    payload = line
                else:
                    continue

                if time.monotonic() >= _abs_deadline:
                    raise _idle_err("stream absolute timeout (tidak ada data nyata) -> retry")

                if time.monotonic() >= _deadline:
                    raise _idle_err("stream idle (tidak ada data nyata) -> retry")

                _deadline = time.monotonic() + _idle
                _got_data = True

                if payload == "[DONE]":
                    break

                try:
                    d = json.loads(payload)
                except Exception:
                    continue

                _err = d.get("error")
                if isinstance(_err, (dict, str)):
                    raise PermanentError(str(_err)[:120])

                if not (d.get("choices") or []):
                    # usage-only / status chunk (mis. {"usage":{...}} sesudah finish) bukan akhir stream — skip, jangan break biar konten tidak kepotong.
                    continue

                ch = (d.get("choices") or [{}])[0]
                delta = ch.get("delta") or {}

                rk = next((k for k in _reason_keys if isinstance(delta.get(k), str) and delta.get(k)), None)
                if rk:
                    reason_parts.append(delta[rk])
                    if on_thought is not None:
                        try:
                            on_thought("".join(reason_parts), False)
                        except Exception:
                            pass

                c = delta.get("content")
                if c:
                    content_parts.append(c)
                    if on_thought is not None and reason_parts and not _thought_done:
                        _thought_done = True
                        try:
                            on_thought("".join(reason_parts), True)
                        except Exception:
                            pass

                    if on_delta is not None:
                        try:
                            on_delta("".join(content_parts))
                        except Exception:
                            pass

                for tc in delta.get("tool_calls") or []:
                    idx = int(tc.get("index", 0) or 0)
                    acc = tool_acc.setdefault(idx, {"id": "", "name": "", "args": ""})

                    if tc.get("id"):
                        acc["id"] = tc["id"]

                    fn = tc.get("function") or {}

                    if fn.get("name"):
                        acc["name"] = fn["name"]
                    if fn.get("arguments"):
                        acc["args"] += fn["arguments"]

        except requests.exceptions.RequestException as e:
            raise OverloadedError(f"stream error ({e}) → retry", retry_after=5, status=0)

        finally:
            try:
                r.close()
            except Exception:
                pass

            if on_thought is not None and reason_parts and not _thought_done:
                _thought_done = True
                try:
                    on_thought("".join(reason_parts), True)
                except Exception:
                    pass

        if not content_parts and not tool_acc:
            _bh = OverloadedError("Blackhole: stream selesai tapi 0 tokens", retry_after=5, status=0)
            _bh._soft = not _got_data
            raise _bh

        msg = {"role": "assistant", "content": "".join(content_parts)}

        if tool_acc:
            msg["tool_calls"] = []
            for i, key in enumerate(sorted(tool_acc.keys())):
                a = tool_acc[key]
                msg["tool_calls"].append({
                    "id": a["id"] or f"call_{i}",
                    "type": "function",
                    "function": {
                        "name": a["name"],
                        "arguments": a["args"] or "{}"
                    }
                })

        return msg

    TOOLS_SCHEMA = [
        {"type": "function", "function": {"name": "shell", "description": "Jalankan command shell di server. Untuk cek sistem, install, git, network, dsb. Output dibatasi.", "parameters": {"type": "object", "properties": {"command": {"type": "string"}, "cwd": {"type": "string"}}, "required": ["command"]}}},
        {"type": "function", "function": {"name": "read_file", "description": "Baca isi file teks.", "parameters": {"type": "object", "properties": {"path": {"type": "string"}}, "required": ["path"]}}},
        {"type": "function", "function": {"name": "write_file", "description": "Tulis/replace isi file (buat file baru kalau belum ada).", "parameters": {"type": "object", "properties": {"path": {"type": "string"}, "content": {"type": "string"}}, "required": ["path", "content"]}}},
        {"type": "function", "function": {"name": "list_dir", "description": "List isi folder.", "parameters": {"type": "object", "properties": {"path": {"type": "string"}}, "required": ["path"]}}},
        {"type": "function", "function": {"name": "search", "description": "Cari file by nama (regex) atau isi file.", "parameters": {"type": "object", "properties": {"pattern": {"type": "string"}, "path": {"type": "string"}, "content": {"type": "boolean"}}, "required": ["pattern"]}}},
        {"type": "function", "function": {"name": "http_request", "description": "Fetch URL / panggil API (GET/POST/PUT/DELETE).", "parameters": {"type": "object", "properties": {"url": {"type": "string"}, "method": {"type": "string", "enum": ["GET", "POST", "PUT", "DELETE"]}, "headers": {"type": "object"}, "body": {"type": "string"}, "timeout": {"type": "integer"}}, "required": ["url"]}}},
        {"type": "function", "function": {"name": "download_file", "description": "Download file dari URL ke path lokal.", "parameters": {"type": "object", "properties": {"url": {"type": "string"}, "path": {"type": "string"}, "max_mb": {"type": "integer"}}, "required": ["url", "path"]}}},
        {"type": "function", "function": {"name": "db_query", "description": "Query SQLite (SELECT/INSERT/UPDATE/DELETE).", "parameters": {"type": "object", "properties": {"db_path": {"type": "string"}, "sql": {"type": "string"}, "params": {"type": "array"}}, "required": ["db_path", "sql"]}}},
        {"type": "function", "function": {"name": "archive", "description": "Buat atau ekstrak arsip zip/tar/tar.gz.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["create", "extract"]}, "archive_path": {"type": "string"}, "files": {"type": "array"}, "target_dir": {"type": "string"}}, "required": ["action", "archive_path"]}}},
        {"type": "function", "function": {"name": "process_list", "description": "List proses yang berjalan.", "parameters": {"type": "object", "properties": {"pattern": {"type": "string"}}}}},
        {"type": "function", "function": {"name": "process_kill", "description": "Kill proses by pid atau nama.", "parameters": {"type": "object", "properties": {"pid": {"type": "integer"}, "pattern": {"type": "string"}, "signal": {"type": "integer"}}}}},
        {"type": "function", "function": {"name": "skill", "description": "Kelola knowledge base skills.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["list", "search", "get", "create", "delete", "stats"]}, "name": {"type": "string"}, "category": {"type": "string"}, "content": {"type": "string"}, "pattern": {"type": "string"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "note", "description": "Memori persisten AI ke notes.db.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["list", "get", "add", "delete", "search"]}, "key": {"type": "string"}, "content": {"type": "string"}, "pattern": {"type": "string"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "app_install", "description": "Manajemen package via apk/pkg.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["search", "install", "remove", "update", "installed"]}, "package": {"type": "string"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "web_search", "description": "Cari informasi di web (DuckDuckGo).", "parameters": {"type": "object", "properties": {"query": {"type": "string"}, "max_results": {"type": "integer"}, "timeout": {"type": "integer"}}, "required": ["query"]}}},
        {"type": "function", "function": {"name": "backup", "description": "Backup & restore data Debz AI.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["create", "list", "restore", "delete"]}, "name": {"type": "string"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "scheduler", "description": "Kelola job terjadwal: list/add/remove/toggle/run.", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["list", "add", "remove", "toggle", "run"]}, "id": {"type": "string"}, "name": {"type": "string"}, "command": {"type": "string"}, "schedule": {"type": "string"}, "timeout": {"type": "integer"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "computer_use", "description": "Kendali komputer via layar virtual (CUA).", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["status", "screenshot", "open", "launch", "click", "dblclick", "rightclick", "move", "drag", "type", "key", "scroll", "meta"]}, "url": {"type": "string"}, "cmd": {"type": "string"}, "x": {"type": "integer"}, "y": {"type": "integer"}, "button": {"type": "integer"}, "x1": {"type": "integer"}, "y1": {"type": "integer"}, "x2": {"type": "integer"}, "y2": {"type": "integer"}, "duration": {"type": "number"}, "text": {"type": "string"}, "key": {"type": "string"}, "dx": {"type": "integer"}, "dy": {"type": "integer"}, "times": {"type": "integer"}}, "required": ["action"]}}},
        {"type": "function", "function": {"name": "browser", "description": "Browser automation Playwright + Chromium headless.", "parameters": {"type": "object", "properties": {"command": {"type": "string", "enum": ["goto", "content", "text", "title", "screenshot", "click", "type", "press", "wait", "eval", "close"]}, "url": {"type": "string"}, "selector": {"type": "string"}, "text": {"type": "string"}, "key": {"type": "string"}, "path": {"type": "string"}, "js": {"type": "string"}, "ms": {"type": "integer"}, "index": {"type": "integer"}}, "required": ["command"]}}},
        {"type": "function", "function": {"name": "screenshot", "description": "Ambil screenshot layar virtual (CUA/Xvfb).", "parameters": {"type": "object", "properties": {}}}},
        {"type": "function", "function": {"name": "rag_query", "description": "Cari di knowledge base via embedding semantic (RAG).", "parameters": {"type": "object", "properties": {"action": {"type": "string", "enum": ["status", "ingest", "query"]}, "text": {"type": "string"}, "path": {"type": "string"}, "top": {"type": "integer"}}, "required": ["action"]}}}
    ]

    def _cooldown_wait(self, tree=None, total=None):
        total = COOLDOWN if total is None else max(1, int(total))
        t0 = time.time()

        if tree:
            tree.suspend()

        try:
            while True:
                left = total - (time.time() - t0)
                if left <= 0:
                    return True

                console.print(f"  [yellow]⏳ Cooldown {int(left)//60:02d}:{int(left)%60:02d}[/yellow] [dim]· Enter=Ulang · Ctrl+C=Tahan[/dim]", end="\r")

                try:
                    rdy, _, _ = select.select([sys.stdin], [], [], 0.5)
                    if rdy:
                        sys.stdin.readline()
                        console.print()
                        return True
                except KeyboardInterrupt:
                    console.print()
                    return False
        finally:
            if tree:
                tree.resume()

    def _fo_worthy(self, e):
        t = str(e).lower()
        return any(k in t for k in ("timeout", "terputus", "curl error", "http 5", "http 404", "http 401", "http 403", "http 429", "http 422"))

    def _fo_try(self, tree=None):
        if self.cfg.get("_ROUTING", "fixed") == "fixed":
            return False

        if getattr(self, "_fo_pool", None) is None:
            self._fo_pool = [pid for pid in _enabled_ids(self.cfg.get("_PROVIDERS") or {}) if pid != self.cfg.get("_PROV_ID")]

        if not self._fo_pool:
            return False

        pid = self._fo_pool.pop(0)
        cur = self.cfg.get("_PROV_ID", "?")

        if tree:
            tree.below(f"[yellow]🔄 Failover:[/yellow] {cur} [dim]→[/dim] [cyan]{pid}[/cyan]")

        if not _apply_provider(self.cfg, pid, mark_active=False):
            return False

        return True

    def _smart_wait(self, e, attempt):
        ra = getattr(e, "retry_after", None)
        if ra is not None and ra > 0:
            return min(int(ra) + 2, 900)

        t = str(e).lower()
        if "429" in t or "rate limit" in t or "request limit" in t:
            lim = _RL["limits"].get(_rl_key(self.cfg))
            if lim:
                return min(int(lim.get("window", 60)) + 5, 300)
            return 65

        w = 15 * (2 ** min(attempt, 4)) + random.uniform(2, 8)
        return min(int(w), COOLDOWN)

    def _llm_retry(self, msgs, tree=None, on_delta=None, on_thought=None):
        attempt = 0

        while True:
            try:
                return self._llm(msgs, on_delta=on_delta, on_thought=on_thought)
            except OverloadedError as e:
                _rl_learn_from_error(_rl_key(self.cfg), str(e))

                if self._fo_try(tree):
                    continue

                attempt += 1
                if tree:
                    tree.done("⏸", "yellow")
                    match = re.search(r'\b(4\d\d|5\d\d)\b', str(e))
                    status = match.group(1) if match else "503"
                    tree.below(f"[yellow]🚧 Overload - {status} Tunggu...[/yellow]")

                if attempt >= MAX_RETRY:
                    raise HoldSignal(str(e))

                _wait = self._smart_wait(e, attempt)
                if not self._cooldown_wait(tree, total=_wait):
                    raise HoldSignal(str(e))

                if tree:
                    tree.step("", "🧠 Berpikir…")

            except PermanentError as e:
                if self._fo_worthy(e) and self._fo_try(tree):
                    continue
                raise
            except KeyboardInterrupt:
                if tree:
                    tree.done("✗", "red")
                raise CanceledByUser()

    def _shell_out(self, r):
        if "error" in r:
            return {"output": f"error: {r['error']}", "ok": False}

        stdout = r.get("stdout", "")
        stderr = r.get("stderr", "")
        output = stdout + (f"\n[stderr] {stderr}" if stderr else "")

        return {
            "output": output.strip(),
            "ok": r.get("exit_code") in (0, None)
        }

    def _show_diff(self, path, new_content, tree=None):
        try:
            with open(path, 'r', encoding='utf-8') as f:
                old_lines = f.readlines()
        except Exception:
            old_lines = []

        diff = list(difflib.unified_diff(
            old_lines,
            new_content.splitlines(keepends=True),
            fromfile=path, tofile=path,
            n=1
        ))

        if not diff:
            return

        _w = _terminal_width_for_streaming()
        max_show = tree._diff_n if tree is not None else 12
        rendered_lines = _render_unified_diff("".join(diff), max_show, _w)

        if tree is not None:
            tree.set_diff(path, rendered_lines)
        else:
            console.print()
            console.print(Group(*rendered_lines))

    def _oc_show_diff(self, tree, inp, meta):
        if tree is None or not isinstance(inp, dict):
            return

        fp = inp.get("filePath") or inp.get("file") or inp.get("path") or ""
        if not fp:
            return

        diff_text = ""
        if isinstance(meta, dict):
            for k in ("diff", "patch", "unified", "filediff"):
                v = meta.get(k)
                if isinstance(v, str) and v.strip():
                    diff_text = v
                    break

        if not diff_text:
            old = inp.get("oldString")
            new = inp.get("newString")
            if new is None:
                new = inp.get("content")

            if old is not None or new is not None:
                diff_text = "".join(difflib.unified_diff(
                    str(old or "").splitlines(keepends=True),
                    str(new or "").splitlines(keepends=True),
                    fromfile=fp, tofile=fp, n=1,
                ))

        if diff_text.strip():
            try:
                tree.set_diff(fp, _render_unified_diff(diff_text, tree._diff_n, _terminal_width_for_streaming()))
            except Exception:
                pass

    def _run_tool(self, name, args, tree):

        if name == "shell":
            r = self.tools.exec(args.get("command"), args.get("cwd"))
            if r.get("need_approval"):
                return r
            out = self._shell_out(r)
            if tree is not None:
                try:
                    tree.set_preview(args.get("command", "")[:20], out.get("output", ""), "exec")
                except Exception:
                    pass
            return out

        elif name == "read_file":
            r = self.tools.read(args.get("path", ""))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            _c = _strip_oc_annotations(r.get("content", "") or "")
            if tree is not None:
                try:
                    _p = str(args.get("path", "")) + _fmt_range(args.get("offset"), args.get("limit")).strip()
                    tree.set_preview(_p, _c, "read")
                except Exception:
                    pass
            return {"output": _c[:20000], "ok": True}

        elif name == "write_file":
            p, c_str = args.get("path", ""), args.get("content", "")
            self._show_diff(p, c_str, tree)
            r = self.tools.write(p, c_str)
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": f"wrote {r.get('bytes_written', '?')} bytes", "ok": True}

        elif name == "list_dir":
            r = self.tools.list(args.get("path", "/"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            entries = "\n".join(f"{'d' if e['dir'] else '-'}  {e['name']}  {e['size']}b" for e in r.get("entries", [])[:100])
            return {"output": entries or "(empty)", "ok": True}

        elif name == "search":
            r = self.tools.search(args.get("pattern", ""), path=args.get("path", PROJECT_ROOT), content=bool(args.get("content", False)))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            results = "\n".join(f"{m.get('path', '?')} [{m.get('match', '?')}]" if isinstance(m, dict) else str(m) for m in r.get("results", [])[:50])
            return {"output": results or "(no matches)", "ok": True}

        elif name == "http_request":
            r = self.tools.http_request(args.get("url", ""), method=args.get("method", "GET"), headers=args.get("headers"), body=args.get("body"), timeout=args.get("timeout", 300))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": f"HTTP {r.get('http_code')}\n{r.get('body', '')[:4000]}", "ok": True}

        elif name == "download_file":
            import uuid as _uuid
            dl_id = _uuid.uuid4().hex
            try:
                r = self.tools.download(args.get("url", ""), args.get("path", ""), max_mb=args.get("max_mb", 200), download_id=dl_id)
            except KeyboardInterrupt:
                try:
                    self.tools.download_cancel(dl_id)
                except Exception:
                    pass
                raise
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": f"downloaded {r.get('bytes', 0)} bytes", "ok": True}

        elif name == "db_query":
            db_path = args.get("db_path", "")
            sql = args.get("sql", "")
            if not db_path or not sql:
                return {"output": "error: db_path atau sql kosong", "ok": False}
            r = self.tools.db(db_path, sql, args.get("params"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            out_str = "\n".join(str(row) for row in r.get("rows", [])[:50]) if "rows" in r else f"{r.get('affected', 0)} rows changed"
            return {"output": out_str, "ok": True}

        elif name == "archive":
            action, archive_path = args.get("action", ""), args.get("archive_path", "")
            if action not in ("create", "extract"):
                return {"output": "error: action harus create atau extract", "ok": False}
            if not archive_path:
                return {"output": "error: archive_path kosong", "ok": False}
            r = self.tools.archive(action, archive_path, files=args.get("files"), target_dir=args.get("target_dir"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r)[:2000], "ok": True}

        elif name == "process_list":
            r = self.tools.ps(args.get("pattern"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            out_str = "\n".join(f"{p.get('pid')} {p.get('user', '?')} {p.get('cmd', '')[:80]}" for p in r.get("processes", [])[:50])
            return {"output": out_str, "ok": True}

        elif name == "process_kill":
            r = self.tools.kill(pid=args.get("pid"), pattern=args.get("pattern"), signal=args.get("signal", 15))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": f"signal {r.get('signal')}", "ok": True}

        elif name == "skill":
            r = self.tools.skill(args.get("action", ""), name=args.get("name"), category=args.get("category"), content=args.get("content"), pattern=args.get("pattern"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r)[:2000], "ok": True}

        elif name == "note":
            r = self.tools.note(args.get("action", ""), key=args.get("key"), content=args.get("content"), pattern=args.get("pattern"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r)[:2000], "ok": True}

        elif name == "app_install":
            r = self.tools.pkg(args.get("action", ""), args.get("package"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": (r.get("stdout") or "")[:3000], "ok": r.get("exit_code", 0) == 0}

        elif name == "web_search":
            r = self.tools.web_search(args.get("query", ""), max_results=args.get("max_results", 12), timeout=args.get("timeout", 120))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r, ensure_ascii=False)[:4000], "ok": True}

        elif name == "backup":
            r = self.tools.backup(args.get("action", "list"), name=args.get("name"))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r, ensure_ascii=False)[:2000], "ok": True}

        elif name == "scheduler":
            r = self.tools.scheduler(args.get("action", "list"), id=args.get("id"), name=args.get("name"), command=args.get("command"), schedule=args.get("schedule"), timeout=args.get("timeout", 120))
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            return {"output": json.dumps(r, ensure_ascii=False)[:3000], "ok": True}

        elif name in ("computer_use", "browser", "screenshot", "rag_query"):
            endpoint_map = {
                "computer_use": "/api/cua",
                "browser": "/api/browser",
                "screenshot": "/api/screenshot",
                "rag_query": "/api/rag"
            }
            r = self.tools._post(endpoint_map[name], args)
            if "error" in r:
                return {"output": f"error: {r['error']}", "ok": False}
            if name == "screenshot":
                return {"output": f'screenshot {r.get("width", "?")}x{r.get("height", "?")}', "ok": True}
            return {"output": json.dumps(r, ensure_ascii=False)[:3000], "ok": True}

        return {"output": f"unknown tool: {name}", "ok": False}

    def _final_no_tools(self, msgs, tree, _on_delta=None, _on_thought=None):
        _saved, self.tools_on = self.tools_on, False
        try:
            if tree:
                tree.step("", "✍️ Menyusun jawaban…")
            _tail = [{"role": "system", "content": "STOP pakai tools. Jawab langsung ke user sekarang dengan ringkas dan jelas."}]
            m = self._llm_retry(msgs + _tail, tree, on_delta=_on_delta, on_thought=_on_thought)
            txt = _strip_reasoning_tags((m.get("content") or "").strip())

            if tree:
                tree.done("✓")
                tree.clear_diff()
            return txt or "(model tetap tidak menjawab — coba ulang)"
        finally:
            self.tools_on = _saved

    def _chat_opencode_cli(self, user_msg, ui):
        extra = self.cfg.get("EXTRA") or {}
        bin_p = (extra.get("cli_bin") or "").strip()
        # APK rootfs: CLI di /opt/opencode, bukan opencode-bin/ (lihat agent.php).
        if not bin_p:
            for cand in (os.path.join(PROJECT_ROOT, "opencode-bin", "opencode"),
                         "/opt/opencode/opencode"):
                if os.path.isfile(cand) and os.access(cand, os.X_OK):
                    bin_p = cand
                    break
            else:
                bin_p = os.path.join(PROJECT_ROOT, "opencode-bin", "opencode")

        if not os.path.isfile(bin_p):
            return f"⚠️ opencode binary tidak ditemukan: {bin_p}"

        model = (self.cfg.get("MODEL") or "").strip()
        if "/" not in model:
            model = ("opencode/" + model) if model else "opencode/big-pickle"

        self._oc_pid = None
        tree = None

        if self.tty:
            try:
                tree = WorkTree(self.tty, agent=self, user_msg=None, ui=ui)
                tree.step("", "🧠 mikir dulu")
            except Exception:
                tree = None

        def _msg(text):
            if tree is not None:
                try: tree.below(text)
                except Exception: pass
            else:
                try: console.print(f"  {text}")
                except Exception: pass

        def _msg_proxy(text, icon="", color="cyan"):
            if tree is not None:
                try: tree.proxy_status(str(text), icon, color)
                except Exception: pass
            else:
                try: console.print("  " + (f"{icon} " if icon else "") + str(text))
                except Exception: pass

        def _oc_kill(p, wait_s=2):

            _pg = None
            try:
                _pg = os.getpgid(p.pid)
            except Exception:
                _pg = None
            try:
                if _pg is not None:
                    os.killpg(_pg, signal.SIGTERM)
                else:
                    p.terminate()
            except Exception:
                pass
            try:
                p.wait(timeout=wait_s)
            except Exception:
                try:
                    if _pg is not None:
                        os.killpg(_pg, signal.SIGKILL)
                    else:
                        p.kill()
                except Exception:
                    pass

        def _run(prompt, force_new=False, fast=False):
            import subprocess as _sp

            env = dict(os.environ)
            env["XDG_CONFIG_HOME"] = os.path.join(PROJECT_ROOT, "opencode-bin", ".cfg_home")
            env["XDG_DATA_HOME"] = os.path.join(PROJECT_ROOT, "opencode-bin", ".data_home")
            # LONG-JOB vs CHAT: build/CI/download sunyi 5-30 mnt BUKAN hang.
            _is_long = _is_long_job(prompt)
            stall_lim = 1800 if _is_long else 900

            import json as _json
            import socket as _sock
            serve_url = ""
            try:
                _sj = os.path.join(PROJECT_ROOT, "opencode-bin", ".serve.json")
                with open(_sj, "r", encoding="utf-8") as _f:
                    _sc = _json.loads(_f.read() or "{}")
                if _sc.get("port") and _sc.get("url"):
                    _so = _sock.create_connection(("127.0.0.1", int(_sc["port"])), timeout=1.0)
                    _so.close()
                    serve_url = _sc["url"]
                    env["OPENCODE_SERVER_PASSWORD"] = str(_sc.get("password") or "")
            except Exception:
                serve_url = ""

            cmd = [bin_p, "run", "--attach", serve_url, "--format", "json", "--no-replay", "-m", model] if serve_url else [bin_p, "run", "--format", "json", "--no-replay", "-m", model]

            oc_sid = getattr(self, "_oc_session", "")

            if serve_url and not oc_sid and self.tools_on:

                import urllib.request as _uq_ns
                import urllib.parse as _up_ns
                import base64 as _b64_ns
                try:
                    _scj = {}
                    with open(os.path.join(PROJECT_ROOT, "opencode-bin", ".serve.json"), "r", encoding="utf-8") as _f:
                        _scj = json.loads(_f.read() or "{}")
                    _auth_ns = {"Authorization": "Basic " + _b64_ns.b64encode(("opencode:%s" % (_scj.get("password") or "")).encode()).decode(),
                                "x-opencode-directory": _up_ns.quote(PROJECT_ROOT),
                                "Content-Type": "application/json"}
                    _req_ns = _uq_ns.Request(serve_url.rstrip("/") + "/session",
                                             data=json.dumps({"directory": PROJECT_ROOT}).encode(),
                                             headers=_auth_ns, method="POST")
                    with _uq_ns.urlopen(_req_ns, timeout=20) as _r:
                        _raw_ns = _r.read()
                    _sj_ns = json.loads(_raw_ns or b"{}")
                    _sid_ns = _sj_ns.get("id") or _sj_ns.get("sessionID") or ""
                    if _sid_ns:
                        oc_sid = _sid_ns
                        self._oc_session = _sid_ns
                        self._oc_session_id = _sid_ns
                        log_debug(f"OC_CLI: session baru dibuat utk attach: {_sid_ns[:12]}")
                except Exception as _se:
                    log_debug(f"OC_CLI_NEW_SESSION: {_se}")

            try:
                t_oc_deadline = int(os.environ.get("DEBZ_OC_IDLE_S", "900") or 900)
            except Exception:
                t_oc_deadline = 900
            t_oc_deadline = max(300, min(3600, t_oc_deadline))
            try:
                _oc_hard_cap = int(os.environ.get("DEBZ_OC_CAP_S", "1800") or 1800)
            except Exception:
                _oc_hard_cap = 1800
            if _is_long:
                t_oc_deadline = max(t_oc_deadline, 1800)
                _oc_hard_cap = max(_oc_hard_cap, 3600)
            _oc_hard_cap = max(t_oc_deadline + 60, _oc_hard_cap)

            if serve_url and not self.tools_on:

                import urllib.request as _uq
                import urllib.error as _ue
                import urllib.parse as _up
                import base64 as _b64

                _sc = {}
                try:
                    with open(os.path.join(PROJECT_ROOT, "opencode-bin", ".serve.json"), "r", encoding="utf-8") as _f:
                        _sc = _json.loads(_f.read() or "{}")
                except Exception:
                    _sc = {}

                _card_sid = oc_sid
                _auth_h = {"Authorization": "Basic " + _b64.b64encode(("opencode:%s" % (_sc.get("password") or "")).encode()).decode(),
                           "x-opencode-directory": _up.quote(PROJECT_ROOT),
                           "Content-Type": "application/json"}

                def _api(method, path, body=None, timeout=30):
                    data = json.dumps(body).encode() if body is not None else None
                    req = _uq.Request(serve_url.rstrip("/") + path, data=data, headers=_auth_h, method=method)
                    try:
                        with _uq.urlopen(req, timeout=timeout) as _r:
                            raw = _r.read()
                    except _ue.HTTPError as _e:
                        raw = _e.read()
                        log_debug(f"OC_API {method} {path} -> {_e.code}: {raw[:200]}")
                        return None
                    if not raw:
                        return None
                    try:
                        return json.loads(raw)
                    except Exception:
                        return None

                if not _card_sid:
                    _sn = _api("POST", "/session", {"directory": PROJECT_ROOT}, timeout=30)
                    _card_sid = (_sn or {}).get("id") or (_sn or {}).get("sessionID") or ""
                    if _card_sid:
                        self._oc_session = _card_sid
                        self._oc_session_id = _card_sid

                ft, et, done, reason = "", "", False, None
                t0 = time.time()
                _last_act = t0
                _result = {}
                _result_ev = threading.Event()

                _curl_cmd = ["curl", "-s", "-N", "-u", "opencode:%s" % (_sc.get("password") or ""),
                             "-H", "x-opencode-directory: %s" % _up.quote(PROJECT_ROOT),
                             "-H", "Accept: text/event-stream",
                             serve_url.rstrip("/") + "/event"]
                _evp = None
                try:
                    _evp = _sp.Popen(_curl_cmd, stdout=_sp.PIPE, stderr=_sp.DEVNULL,
                                     bufsize=0, cwd=PROJECT_ROOT, start_new_session=True)
                except Exception as _se:
                    _evp = None
                    log_debug(f"OC_CARD_SSE_SPAWN: {_se}")
                _ev_fd = _evp.stdout.fileno() if _evp is not None else None

                def _send_worker():
                    try:
                        _r = _api("POST", "/session/%s/message" % _card_sid,
                                  {"providerID": "opencode", "modelID": model,
                                   "parts": [{"type": "text", "text": prompt}]},
                                  timeout=max(900, _oc_hard_cap))
                        _result.update(_r or {})
                    except Exception as _e:
                        _result["_error"] = str(_e)
                    finally:
                        try:
                            _result_ev.set()
                        except Exception:
                            pass

                _th_send = threading.Thread(target=_send_worker, daemon=True)
                _th_send.start()

                def _card_deny_done(ev):
                    nonlocal reason
                    reason = "denied"
                    log_debug("OC_CARD: permission ditolak user")

                try:
                    _buf = b""
                    _card_live = True
                    _post_done_at = 0.0

                    _msg_roles = {}
                    import select as _sel
                    while _card_live:
                        if (time.time() - _last_act) > max(stall_lim, _oc_hard_cap):
                            reason = "card_stall"
                            log_debug("OC_CARD_STALL: tanpa event > limit")
                            _card_live = False
                            break
                        if _ev_fd is None:

                            if _result_ev.is_set() or time.time() - t0 > 30:
                                _card_live = False
                                break
                            time.sleep(1.0)
                            continue
                        if _result_ev.is_set():
                            if _buf.strip() == b"":
                                if _post_done_at == 0.0:
                                    _post_done_at = time.time()
                                elif (time.time() - _post_done_at) >= 2.0:
                                    _card_live = False
                                    break
                        else:
                            _post_done_at = 0.0
                        _blk = b""
                        try:
                            _r, _, _ = _sel.select([_ev_fd], [], [], 1.0)
                            if _r:
                                _blk = os.read(_ev_fd, 65536)
                        except Exception as _te:
                            _blk = b""
                        if not _blk:
                            if _result_ev.is_set() and _buf.strip() == b"":
                                _card_live = False
                                break
                            try:
                                if _evp is not None and _evp.poll() is not None and not (time.time() - _last_act < 30):
                                    _card_live = False
                                    break
                            except Exception:
                                pass
                            time.sleep(1.0)
                            continue
                        _buf += _blk
                        while b"\n\n" in _buf:
                            _chunk, _buf = _buf.split(b"\n\n", 1)
                            for _ln in _chunk.splitlines():
                                if not _ln.startswith(b"data: "):
                                    continue
                                try:
                                    _ev = json.loads(_ln[6:].decode("utf-8", "replace"))
                                except Exception:
                                    continue
                                _last_act = time.time()
                                _typ = _ev.get("type", "")
                                _p = _ev.get("properties", {}) or {}
                                if _typ == "message.updated":
                                    _info = _p.get("info") or {}
                                    _mid = _info.get("id") or ""
                                    _mrole = _info.get("role") or ""
                                    if _mid and _mrole:
                                        _msg_roles[_mid] = _mrole
                                    continue
                                if _typ == "permission.asked":
                                    _pid = _p.get("id")
                                    if _pid:
                                        _cmd = (_p.get("metadata") or {}).get("command") or ""
                                        if not _cmd:
                                            _pats = _p.get("patterns") or []
                                            _cmd = _pats[0] if _pats else ""
                                        _perm = _p.get("permission", "tool")
                                        # Live-check file agar toggle sidebar AllowAll langsung ngefek
                                        # walau agent process sudah jalan dari sebelum toggle ON.
                                        if _allow_all_get():
                                            self.allow_all = True

                                        if self.allow_all or self.allow_session:
                                            _auto = "always" if self.allow_all else "once"
                                            _api("POST", "/session/%s/permissions/%s" % (_card_sid, _pid),
                                                 {"response": _auto, "remember": self.allow_all}, timeout=30)
                                            log_debug(f"OC_CARD auto-approve ({self.allow_all and 'always' or 'session'}) {_perm}")
                                            continue
                                        if _cmd and tree is not None:
                                            try: tree.below(f"[bold yellow]⚠ izin {_perm} diminta[/bold yellow]")
                                            except Exception: pass
                                        _ans = _ask_permission(_perm, _cmd)
                                        if _ans == "session":
                                            self.allow_session = True
                                        elif _ans == "always":
                                            self.allow_all = True
                                            _allow_all_set(True)
                                        _resp = {"once": "once", "always": "always", "session": "once", "deny": "reject"}.get(_ans, "reject")
                                        _api("POST", "/session/%s/permissions/%s" % (_card_sid, _pid),
                                             {"response": _resp, "remember": _ans == "always"}, timeout=30)
                                        if _ans == "deny":
                                            _card_deny_done(_ev)
                                elif _typ in ("message.part.updated", "message.part.delta"):
                                    _part = _p.get("part", {}) or {}

                                    _pmsg_id = _part.get("messageID") or ""
                                    if _pmsg_id and _msg_roles.get(_pmsg_id) == "user":
                                        continue
                                    _ptype = _part.get("type") or ""
                                    _ln_out = ""
                                    if _ptype == "text" and _part.get("text"):
                                        _ln_out = json.dumps({"type": "text", "part": {"text": _part["text"]},
                                                              "sessionID": _p.get("sessionID", _card_sid)})
                                    elif _ptype == "reasoning" and _part.get("text"):
                                        _ln_out = json.dumps({"type": "reasoning", "part": {"text": _part["text"]},
                                                              "sessionID": _p.get("sessionID", _card_sid)})
                                    elif _ptype == "step-start":
                                        _ln_out = json.dumps({"type": "step_start", "part": {},
                                                              "sessionID": _p.get("sessionID", _card_sid)})
                                    elif _ptype == "step-finish":
                                        _ln_out = json.dumps({"type": "step_finish", "part": {},
                                                              "sessionID": _p.get("sessionID", _card_sid)})
                                    elif _ptype == "tool":
                                        _st = _part.get("state") or {}
                                        _st2 = dict(_st)
                                        _st2.pop("time", None)
                                        _ln_out = json.dumps({"type": "tool_use", "part": {"tool": _part.get("tool") or "",
                                                                                           "state": _st2, "callID": _part.get("callID") or ""},
                                                              "sessionID": _p.get("sessionID", _card_sid)})
                                    if _ln_out:
                                        _ft = _parse_oc_lines(_ln_out + "\n", self, ui, tree)
                                        if _ft:
                                            ft += _ft
                                elif _typ == "text":
                                    _txt = _p.get("text") or ""
                                    if _txt:
                                        _ft = _parse_oc_lines(json.dumps({"type": "text", "part": {"text": _txt},
                                                                          "sessionID": _card_sid}) + "\n", self, ui, tree)
                                        if _ft:
                                            ft += _ft
                                elif _typ in ("server.heartbeat", "busy", "idle"):
                                    pass
                except Exception as _e:
                    log_debug(f"OC_CARD_SSE: {_e}")
                finally:
                    if _evp is not None:
                        try:
                            os.killpg(os.getpgid(_evp.pid), signal.SIGTERM)
                        except Exception:
                            try:
                                _evp.terminate()
                            except Exception:
                                pass
                        try:
                            _evp.wait(timeout=2)
                        except Exception:
                            pass
                    try:
                        _th_send.join(timeout=2)
                    except Exception:
                        pass

                fail = reason in ("card_stall",)
                if _result.get("_error") and not ft:
                    et = str(_result["_error"])[:300]
                    fail = True
                elif _result.get("_error") and ft:
                    et = str(_result["_error"])[:300]
                if reason == "denied" and not ft:
                    et = "permission ditolak user"
                return ft, et, fail

            if oc_sid:
                cmd += ["-s", oc_sid]
            if self.tools_on:
                cmd.append("--auto")

            cmd += ["--", prompt]
            log_debug(f"OC_CLI: {' '.join(cmd)}")

            self._oc_stream_acc = ""
            self._oc_reason_acc = ""
            proc = _sp.Popen(cmd, stdout=_sp.PIPE, stderr=_sp.PIPE, bufsize=0, cwd=PROJECT_ROOT, env=env, start_new_session=True)
            self._oc_pid = proc.pid
            self._oc_proc = proc
            ft, et, done, reason = "", "", False, None

            t0 = time.time()

            _stderr_lines = []
            def _drain_stderr():
                try:
                    while True:
                        _blk = os.read(proc.stderr.fileno(), 65536)
                        if not _blk:
                            break
                        _stderr_lines.append(_blk.decode("utf-8", "replace"))
                        if len(_stderr_lines) > 100:
                            _stderr_lines.pop(0)
                except Exception:
                    pass

            ts_err = threading.Thread(target=_drain_stderr, daemon=True)
            ts_err.start()

            import select as _sel
            _oc_fd = proc.stdout.fileno()
            _oc_buf = b""
            _last_act = t0

            try:
                while True:
                    now = time.time()
                    stall = now - _last_act

                    if stall > stall_lim:
                        reason = "direct_stall"
                        log_debug(f"OC_CLI_STALL: {reason} stall={stall:.0f}s limit={stall_lim}s via=direct")
                        _oc_kill(proc)
                        break

                    if (now - t0) > _oc_hard_cap:
                        reason = "cli_timeout"
                        log_debug(f"OC_CLI_TIMEOUT: hard-cap {_oc_hard_cap}s exceeded total={now - t0:.0f}s")
                        _oc_kill(proc)
                        break

                    if proc.poll() is not None:

                        while True:
                            try:
                                _r, _, _ = _sel.select([_oc_fd], [], [], 1.0)
                            except Exception:
                                _r = []
                            if not _r:
                                break
                            try:
                                _blk = os.read(_oc_fd, 65536)
                            except Exception:
                                _blk = b""
                            if not _blk:
                                break
                            _oc_buf += _blk
                        done = True
                        break

                    try:
                        _r, _, _ = _sel.select([_oc_fd], [], [], 1.0)
                    except Exception:
                        _r = []
                    if not _r:
                        continue

                    try:
                        _blk = os.read(_oc_fd, 65536)
                    except Exception:
                        _blk = b""
                    if not _blk:
                        continue

                    _oc_buf += _blk
                    while b"\n" in _oc_buf:
                        _ln, _oc_buf = _oc_buf.split(b"\n", 1)
                        try:
                            _txt = _ln.decode("utf-8", "replace")
                        except Exception:
                            _txt = ""
                        ch = _parse_oc_lines(_txt + "\n", self, ui, tree)
                        if ch:
                            ft += ch
                        _last_act = time.time()

                if _oc_buf.strip():
                    try:
                        ft += _parse_oc_lines(_oc_buf.decode("utf-8", "replace"), self, ui, tree)
                    except Exception:
                        pass

            finally:
                if not done:
                    _oc_kill(proc)
                try:
                    ts_err.join(timeout=2)
                except Exception:
                    pass

                if not et:
                    _tail_err = "".join(_stderr_lines).strip()
                    et = _tail_err[-3000:] if _tail_err else ""

            fail = False

            if reason == "direct_stall":
                et = f"direct stall ({stall_lim} detik tanpa progres) → proses dihentikan"
                _msg("[red]⚠ direct stall → proses dihentikan[/red]")
                fail = True
            elif reason == "cli_timeout":
                et = f"cli timeout (deadline rolling {t_oc_deadline}s / cap {_oc_hard_cap}s) → proses dihentikan"
                _msg_proxy("cli timeout → proses dihentikan", icon="⚠️", color="red")
                fail = True

            return ft, et, fail

        try:
            final_txt, err_txt = "", ""
            stalls = 0

            prev_ctx = ""
            if not getattr(self, "_oc_session", "") and len(getattr(self, "history", []) or []) > 1:
                try:
                    prev_ctx = _oc_context_preamble(self.history[:-1])
                except Exception:
                    prev_ctx = ""

            for attempt in range(4):
                prompt = user_msg
                if attempt > 0:
                    prompt = (user_msg or "").strip() + "\n\n(analisis/tool sudah selesai. Berikan jawaban final langsung sekarang, lengkap dan ringkas.)"
                    _msg("[dim yellow]⚠ Balasan kosong/hang · coba lagi[/dim yellow]")
                elif prev_ctx:
                    prompt = prev_ctx + "\n\n" + user_msg

                ft, et, fail = _run(prompt, force_new=(attempt > 0), fast=(stalls > 0))
                final_txt, err_txt = ft, et

                if fail:
                    stalls += 1
                    _etl_f = (et or "").lower()
                    _keep_sess = ("direct stall" in _etl_f or "cli timeout" in _etl_f or _is_long_job(user_msg))
                    if not _keep_sess:
                        self._oc_session = ""
                    if attempt < 3:
                        _msg_proxy(f"coba lagi ({attempt + 1}/4)…", icon="🔄", color="yellow")
                        continue
                    return f"⚠️ opencode CLI: {et}"

                if final_txt.strip():
                    break

                if et.strip():
                    _etl = et.lower()
                    # WHY: bug JS di binary opencode (mis. G.includes) = state session korup, cuma sembuh via session baru
                    js_dead = any(k in _etl for k in ("includes is not a function", "is not a function", "is undefined", "cannot read propert", "undefined is not an object"))
                    if js_dead:
                        self._oc_session = ""
                        self._oc_session_id = ""
                        if attempt < 3:
                            _msg("[dim yellow]🔄 Session opencode korup · reset ke session baru…[/dim yellow]")
                            continue
                        break
                    bad = any(k in _etl for k in ("timeout", "terputus", "curl error", "proxy", "hang", "rate limit", "http 5", "429", "connection", "reset", "socket", "refused"))
                    if attempt < 3 and bad:
                        _msg("[dim yellow]🔄 Network error · coba run berikutnya…[/dim yellow]")
                        self._proxy_fail_next = True
                        continue
                    break

        finally:
            self._oc_pid = None
            if tree is not None:
                try: tree.done("✓")
                except Exception: pass

                try: tree.close()
                except Exception: pass

        if not final_txt and err_txt:
            e2 = (err_txt.strip().splitlines() or ["opencode error"])[-1]
            if any(k in e2.lower() for k in ("includes is not a function", "is not a function", "is undefined")):
                return f"⚠️ opencode error (session korup, reset fresh tetap gagal — restart debz-term lalu coba lagi): {e2[:200]}"
            return f"⚠️ opencode error: {e2[:300]}"

        return (final_txt or "(model tidak mengembalikan teks)").strip()

    def chat(self, user_msg, ui, resume_msgs=None):
        self._fo_pool = None
        used, mx, pct = memory_usage(self)

        if pct > 85 and len(self.history) > 4:
            self.history = self.history[len(self.history)//2:]
            if self.tty:
                console.print("  [dim yellow]⚠ Memory auto-trimmed[/dim yellow]")

        if self.tty and resume_msgs is None:
            try:
                ui.user_box(user_msg)
            except Exception:
                pass

        if resume_msgs is None:
            self.history.append({"role": "user", "content": user_msg})

        msgs = resume_msgs if resume_msgs is not None else ([{"role": "system", "content": self.system}] + self.history)

        if str(self.cfg.get("_PROV_MODE", "")).lower() == "opencode-cli":
            try:
                ft = self._chat_opencode_cli(user_msg, ui)
            except Exception as _e:
                ft = f"⚠️ opencode-cli error: {_e}"

            if resume_msgs is None:
                ft2 = ft or ""
                if self.history and self.history[-1].get("role") == "user":
                    self.history.pop()
                self.history.append({"role": "user", "content": user_msg})
                self.history.append({"role": "assistant", "content": ft2})

            if self.tty:

                try:
                    _wr = getattr(console, "file", None)
                    if _wr is not None:
                        _wr.write("\x1b[0m\x1b[?25h\x1b[2J\x1b[3J\x1b[H")
                        _wr.flush()
                except Exception:
                    pass
                ui.user_box(user_msg)
            ui.ai_box(ft or "(model tidak mengembalikan teks)")
            return ft or "(model tidak mengembalikan teks)"

        tree = WorkTree(self.tty, agent=self, user_msg=None, ui=ui)
        tree.msgs = msgs
        final_text, held = "", False

        def _on_delta(full_text):
            try: tree.stream_update(full_text)
            except Exception: pass

        def _on_thought(text, done):
            try: tree.thought_update(text, done)
            except Exception: pass

        _last_call_sig = ""
        _repeat_count = 0
        _empty_retries = 0
        _EMPTY_MAX = 2

        try:
            tree.step("", "🧠 Berpikir…")
            for _iter_idx in range(self.cfg["MAX_ITER"]):
                msg = self._llm_retry(msgs, tree, on_delta=_on_delta if self.tty else None, on_thought=_on_thought if self.tty else None)
                tc_list = msg.get("tool_calls") or []
                cur_sig = _tool_call_sig(tc_list) if tc_list else ""

                if cur_sig and cur_sig == _last_call_sig:
                    _repeat_count += 1
                    if _repeat_count >= MAX_REPEAT:
                        log_debug(f"LOOP_GUARD: repeated {_repeat_count}x sig={cur_sig[:16]}")
                        tree.below("[yellow]⚠️ Loop Guard: tool calls identik berulang[/yellow]")
                        _stats_record("loop_guard", {"repeat": _repeat_count})
                        break
                elif cur_sig:
                    _repeat_count = 0

                if cur_sig:
                    _last_call_sig = cur_sig

                if not tc_list and not (msg.get("content") or "").strip():
                    if _empty_retries < _EMPTY_MAX:
                        _empty_retries += 1
                        msgs.append({"role": "user", "content": "Output kosong. Berikan jawaban final sekarang dengan ringkas dan jelas."})
                        if tree:
                            tree.below(f"[dim]Output kosong · retry ({_empty_retries}/{_EMPTY_MAX})[/dim]")
                        continue

                if not tc_list:
                    tree.done("✓")
                    final_text = _strip_reasoning_tags((msg.get("content") or "").strip()) or ""
                    if not final_text:
                        final_text = self._final_no_tools(msgs, tree, _on_delta, _on_thought)

                    self.history.append({"role": "assistant", "content": final_text or "(empty)"})
                    _stats_record("run_end", {"iter": _iter_idx + 1, "chars": len(final_text)})
                    break

                tree.done("✓")
                if msg.get("content"):
                    msg["content"] = _strip_reasoning_tags(msg["content"])
                msgs.append(msg)

                for tc in tc_list:
                    fn = tc.get("function", {})
                    name = fn.get("name", "") if isinstance(fn, dict) else ""
                    args_str = fn.get("arguments", "") if isinstance(fn, dict) else ""
                    _norm = _normalize_tool_arguments(args_str)

                    try:
                        args = json.loads(_norm) if _norm else {}
                    except (json.JSONDecodeError, ValueError):
                        args = {}

                    icon, label = "", _get_dynamic_label(name, args)
                    tree.step(icon, label)

                    try:
                        result = self._run_tool(name, args, tree)
                    except KeyboardInterrupt:
                        raise CanceledByUser()

                    if isinstance(result, dict) and result.get("need_approval"):
                        if _allow_all_get():
                            self.allow_all = True
                        if self.allow_all or self.allow_session:
                            tree.below("[yellow]🔓 Disetujui otomatis[/yellow]")
                            result = self._shell_out(self.tools.exec(result["command"], approved=True))
                        else:
                            tree.done("⏸ Lagi nunggu", "yellow")
                            try:
                                choice = tree.ask(result.get("reason", ""), result.get("command", ""))
                            except KeyboardInterrupt:
                                raise CanceledByUser()

                            if choice == "deny":
                                tree.below("[red]✗ Ditolak user[/red]")
                                msgs.append({"role": "tool", "tool_call_id": tc.get("id"), "content": "user denied"})
                                continue

                            if choice == "session":
                                self.allow_session = True
                            elif choice == "always":
                                self.allow_all = True
                                _allow_all_set(True)

                            tree.step(icon, f"{label} (run)")
                            result = self._shell_out(self.tools.exec(result["command"], approved=True))

                    is_ok = result.get("ok", True)
                    tree.done("✓" if is_ok else "✗", "green" if is_ok else "red")
                    msgs.append({
                        "role": "tool",
                        "tool_call_id": tc.get("id"),
                        "content": (result.get("output", "")[:20000] or "(no output)")
                    })

                tree.step("", "🧠 Berpikir…")

        except CanceledByUser:
            if resume_msgs is None and self.history and self.history[-1].get("role") == "user":
                self.history.pop()
            tree.done("✗", "red")
            final_text = "😵 Dibatalin !!"
        except PermanentError as e:
            final_text = f"X model error - {e}"
        except HoldSignal:
            held = True
            final_text = ""
        finally:
            if not final_text and not held:
                try:
                    final_text = self._final_no_tools(msgs, tree, _on_delta, _on_thought)
                except Exception:
                    final_text = ""

                if final_text and resume_msgs is None and self.history and self.history[-1].get("role") == "user":
                    self.history.append({"role": "assistant", "content": final_text})
            try:
                tree.close()
            except Exception:
                pass

        if held:
            self.pending = (user_msg, msgs)
            return None

        _wiped = False
        try:
            _wiped = bool(getattr(tree, "_wiped", False))
        except Exception:
            _wiped = False
        if _wiped:
            try:
                ui.user_box(user_msg)
            except Exception:
                pass
        ui.ai_box(final_text)
        return final_text

    def reset(self):
        self.history = []


def _oc_context_preamble(hist):
    lines = []
    for m in hist:
        if not isinstance(m, dict):
            continue
        role = m.get("role")
        content = (m.get("content") or "").strip()
        if not content:
            continue
        label = "User" if role == "user" else "Assistant"
        lines.append(f"[{label}]: {content}")
    joined = "\n\n".join(lines)
    return f"Konteks sesi sebelumnya (hanya konteks referensi — JANGAN balas pasif/diem; yang dikerjakan adalah instruksi user paling TERBARU, eksekusi penuh + ACTION):\n{joined}"

def _parse_oc_lines(raw, agent, ui, tree=None):
    out = ""
    agent._oc_session_id = getattr(agent, "_oc_session_id", "")

    for line in raw.splitlines(True):
        ls = line.strip()
        if not ls:
            continue

        try:
            ev = json.loads(ls)
        except (ValueError, json.JSONDecodeError):
            continue

        t = ev.get("type")
        part = ev.get("part") or {}
        sid = part.get("sessionID") or ev.get("sessionID") or ""

        if sid and not agent._oc_session_id:
            agent._oc_session_id = sid

        if t == "session.updated":
            sid2 = part.get("id") or ""
            if sid2 and not agent._oc_session_id:
                agent._oc_session_id = sid2

        elif t == "text":
            txt = part.get("text") or ""
            if txt:
                out += txt
                if tree is not None:
                    agent._oc_stream_acc = getattr(agent, "_oc_stream_acc", "") + txt
                    if getattr(agent, "_oc_reason_acc", ""):
                        try: tree.thought_update(agent._oc_reason_acc, True)
                        except Exception: pass
                    try: tree.stream_update(agent._oc_stream_acc)
                    except Exception: pass
                elif agent.tty:
                    try: ui.ai_stream(txt)
                    except Exception: pass

        elif t == "reasoning":
            txt = part.get("text") or ""
            if txt and tree is not None:
                agent._oc_reason_acc = getattr(agent, "_oc_reason_acc", "") + txt
                if not getattr(agent, "_oc_stream_acc", ""):
                    try: tree.thought_update(agent._oc_reason_acc, False)
                    except Exception: pass

        elif t == "step_start":
            try:
                it = int(part.get("iterations") or 0)
            except (ValueError, TypeError):
                it = 0
            try:
                if tree is not None:
                    tree.step("", "🔍 cek: tugas")
                else:
                    console.print(f"  [dim magenta]◑ step {it + 1}[/dim magenta]")
            except Exception:
                pass

        elif t == "tool_use":
            tool = part.get("tool") or "tool"
            st = part.get("state") or {}
            detail, dkey = "", ""
            inp = st.get("input") or {}

            if isinstance(inp, dict):
                for k in ("command", "filePath", "file", "path", "query", "pattern", "url", "name", "skill"):
                    v = inp.get(k)
                    if isinstance(v, str) and v:
                        detail = v[:64]
                        dkey = k
                        break

            status = str(st.get("status") or "").lower()
            _d = os.path.basename(detail.rstrip("/")) if (detail and dkey in ("file", "path", "filePath")) else detail

            if tool == "read" and isinstance(inp, dict):
                _rng = _fmt_range(inp.get("offset"), inp.get("limit")).strip()
                if _rng:
                    _d = f"{_d} {_rng}".strip() if _d else _rng

            icon_str = _OC_TOOL_ICONS.get(tool, "")
            label = (f"{icon_str} " if icon_str else "") + _oc_indo_label(tool, _d)

            if tree is not None and tool in ("edit", "write", "patch", "multiedit", "apply_patch"):
                agent._oc_show_diff(tree, inp, st.get("metadata"))

            if tree is not None and status in ("completed", "success", "done"):
                _out = st.get("output") or st.get("outputText") or ""
                if isinstance(_out, str) and _out.strip():
                    try:
                        if tool == "read":
                            _preview_path = _d or str(inp.get("filePath") or inp.get("path") or detail or "?")
                            tree.set_preview(_preview_path, _strip_oc_annotations(_out), "read")
                        elif tool in ("bash", "shell", "exec"):
                            tree.set_preview(detail[:24], _out[:20000], "exec")
                        elif tool in ("grep", "glob", "search", "websearch", "list", "ls"):
                            tree.set_preview(detail[:24], _strip_oc_annotations(_out[:8000]), "search")
                    except Exception:
                        pass

            try:
                if tree is not None:
                    tree.step(icon_str, label)
                    if status in ("completed", "success", "done"):
                        tree.done("✓")
                    elif status in ("error", "failed"):
                        tree.done("✗", "red")
                else:
                    console.print(f"  [dim]{label}[/dim]")
            except Exception:
                pass

        elif t == "step_finish":
            if tree is not None:
                try:
                    if getattr(agent, "_oc_reason_acc", ""):
                        tree.thought_update(agent._oc_reason_acc, True)
                    tree.done("✓")
                except Exception:
                    pass

    agent._oc_session = agent._oc_session_id
    return out

def _msg_text_parts(m):
    if not isinstance(m, dict):
        return "", 0

    c = m.get("content")
    txt = ""

    if isinstance(c, str):
        txt = c
    elif isinstance(c, list):
        for it in c:
            if isinstance(it, dict):
                if it.get("type") == "text":
                    txt += f" {it.get('text', '')}"
                elif isinstance(it.get("text"), str):
                    txt += f" {it['text']}"

    args_n = 0
    for tc in (m.get("tool_calls") or []):
        if not isinstance(tc, dict):
            continue
        fn = tc.get("function")
        if isinstance(fn, dict):
            args_n += len(str(fn.get("arguments", "")))

    return txt, args_n


def memory_usage(agent, msgs=None, extra_text=""):
    if msgs is not None:
        items = list(msgs)
    else:
        items = [{"role": "system", "content": agent.system}] + list(agent.history)

    chars = 0
    words = 0

    for m in items:
        txt, args_n = _msg_text_parts(m)
        txt = (txt or "") + str(args_n or 0)
        chars += len(txt)
        words += len(txt.split())

    extra = str(extra_text or "")
    chars += len(extra)
    words += len(extra.split())

    used = max(int(round(words * 1.5)), int(round(chars / 3.2)))
    mx = agent.cfg.get("MAX_TOKEN", 8192)

    return used, mx, min(100.0, used / max(mx, 1) * 100)

MODEL_BAD_KEYS = (
    "image", "video", "audio", "tts", "embed", "whisper", "realtime",
    "flux", "veo", "dall", "sora", "moderation", "transcribe", "seedance",
    "voice", "stt"
)
_MODEL_CACHE = {}

def _prov_models(pc, timeout=120):
    h = {
        "Authorization": "Bearer " + (pc.get("key") or ""),
        "Content-Type": "application/json"
    }
    e = pc.get("extra") or {}
    h["User-Agent"] = _sanitize_ua(e.get("user_agent") or UA_OK_HARNESS)

    for hk, hv in (e.get("headers") or {}).items():
        if hv is None or str(hv) == "":
            continue
        h[str(hk)] = str(hv)

    b = pc["endpoint"]
    for s in ("/chat/completions", "/completions"):
        if b.endswith(s):
            b = b[:-len(s)]
            break

    try:
        r = requests.get(b.rstrip("/") + "/models", headers=h, timeout=timeout)
        if not r.ok:
            return []
        data = r.json()
    except Exception:
        return []

    out = []
    for m in data.get("data") or []:
        mid = (m.get("id") or "").strip()
        if not mid or any(k in mid.lower() for k in MODEL_BAD_KEYS):
            continue

        mods = m.get("modalities")
        if isinstance(mods, list) and mods:
            low = [str(x).lower() for x in mods]
            if "text" not in low and "chat" not in low:
                continue

        out.append(mid)

    return sorted(set(out))

def fetch_all_models(cfg, force=False):
    result = {}
    for pid, p in (cfg.get("_PROVIDERS") or {}).items():
        if not force and pid in _MODEL_CACHE:
            result[pid] = _MODEL_CACHE[pid]
            continue

        pc = _provider_cfg(p, cfg)
        try:
            ms = _prov_models(pc)
            _MODEL_CACHE[pid] = ms
        except Exception:
            ms = []

        result[pid] = ms
    return result

def probe_model(cfg, model, timeout=300):
    body = {
        "model": model,
        "messages": [{"role": "user", "content": "ping"}],
        "max_tokens": 5
    }
    h = _get_headers(cfg)

    try:
        r = requests.post(cfg["ENDPOINT"], headers=h, json=body, timeout=timeout)
    except requests.exceptions.RequestException:
        return True, "probe timeout"

    if r.status_code in (401, 402, 403):
        try:
            emsg = (r.json().get("error") or {}).get("message", "")[:90]
        except Exception:
            emsg = ""

        if r.status_code == 403 and "agentic harness" in (emsg or "").lower():
            h["User-Agent"] = UA_OK_HARNESS
            try:
                r = requests.post(cfg["ENDPOINT"], headers=h, json=body, timeout=timeout)
            except requests.exceptions.RequestException:
                return False, "probe gagal"
            if r.status_code == 200:
                return True, "OK"

        return False, f"HTTP {r.status_code}"

    return True, ""

def _switch_model(cfg, pid, m):
    provs = cfg.get("_PROVIDERS") or {}
    pc = _provider_cfg(provs.get(pid, {}), cfg)
    cfg_key = pc["key"]

    console.print(f"  [dim]… test[/dim] {m} [dim]@ {pid}[/dim]")

    try:
        ok, why = probe_model({
            "ENDPOINT": pc["endpoint"],
            "API_KEY": cfg_key,
            "MODEL": m,
            "EXTRA": pc["extra"]
        }, m)
    except Exception:
        ok, why = True, ""

    if not ok:
        console.print(f"  [red]✗ GAGAL[/red] {why}")
        return False

    if not _set_active_provider(pid, m):
        console.print("  [red]✗ gagal simpan[/red]")
        return False

    cfg["_CLI_MODEL"] = ""
    cfg["MODEL"] = m
    cfg["ENDPOINT"] = pc["endpoint"]
    cfg["API_KEY"] = pc["key"]
    cfg["EXTRA"] = pc["extra"] or {}
    cfg["_PROV_ID"] = pid

    console.print(f"  [green]✓ model →[/green] {m}\n  [green]✓ provider[/green] {pid}")
    return True


COMMANDS = [
    "/help", "/status", "/providers", "/sid", "/model", "/tools",
    "/routing", "/bypass", "/allow", "/memory", "/backup", "/sched",
    "/update", "/reset", "/new", "/clear", "/copy", "/retry", "/exit",
    "/quit", "/save", "/load", "/resume", "/export", "/system"
]

class CommandPathCompleter(Completer):
    def _filesystem_completions(self, token):
        if not token:
            token = "./"
        expanded = os.path.expanduser(token)

        if os.path.isdir(expanded):
            pattern = os.path.join(expanded, "*")
        else:
            pattern = expanded + "*"

        seen = set()
        try:
            matches = sorted(glob.glob(pattern))
        except Exception:
            matches = []

        for match in matches:
            shown = os.path.expanduser(match)
            if os.path.isdir(match):
                shown += "/"
            if token.startswith("~/"):
                home = os.path.expanduser("~")
                if shown.startswith(home):
                    shown = "~" + shown[len(home):]
            if shown in seen:
                continue
            seen.add(shown)
            yield shown

    def _complete_paths(self, document, token):
        start_position = -len(token)
        for path in self._filesystem_completions(token):
            yield Completion(path, start_position=start_position, display=path, display_meta="path")

    def get_completions(self, document, complete_event):
        text = document.text

        if text.startswith('/model '):
            word = document.get_word_before_cursor()
            for pid in sorted(_MODEL_CACHE):
                for model in _MODEL_CACHE[pid]:
                    if model.lower().startswith(word.lower()):
                        yield Completion(model, start_position=-len(word), display=model, display_meta=f"model · {pid}")
            return

        if text.startswith('/load ') or text.startswith('! '):
            token = text.split(None, 1)[1] if len(text.split(None, 1)) > 1 else ""
            yield from self._complete_paths(document, token)
            return

        if text.startswith('/') and ' ' not in text:
            for cmd in COMMANDS:
                if cmd.lower().startswith(text.lower()):
                    yield Completion(cmd, start_position=-len(text), display=cmd, display_meta="command")
            yield from self._complete_paths(document, text)
            return

        text_before = document.text_before_cursor
        last_space = text_before.rfind(' ')
        token = text_before[last_space + 1:] if last_space != -1 else text_before

        if token.startswith(('/', './', '../', '~/')):
            yield from self._complete_paths(document, token)

CMD_ALIASES = {
    "/h": "/help",
    "/?": "/help",
    "/q": "/exit",
    "/x": "/exit",
    "/m": "/model",
    "/s": "/status",
    "/p": "/providers",
    "/r": "/retry",
    "/c": "/clear",
    "/mem": "/memory"
}

def print_help():
    commands_help = [
        ("/help", "help"), ("/status", "status"), ("/model", "ganti model"),
        ("/providers", "list provider"), ("/sid", "generate x-session-id"),
        ("/routing", "mode fixed|roundrobin|failover"), ("/bypass", "cek UA"),
        ("/retry", "lanjut pesan hold"), ("/tools", "toggle tools"),
        ("/allow", "allow-all on|off"), ("/memory", "memory"),
        ("/backup", "backup data"), ("/sched", "scheduler jobs"),
        ("/update", "update agent"), ("/reset", "reset chat"),
        ("/clear", "clear screen"), ("/copy", "salin balasan"),
        ("/save", "simpan session"), ("/load", "muat session"),
        ("/resume", "lanjut autosave"), ("/export", "export chat"),
        ("/system", "ganti persona"), ("/exit", "keluar"), ("! cmd", "shell command")
    ]

    console.print("  [dim]" + "-" * 36 + "[/dim]")
    for cmd, desc in commands_help:
        console.print(f"  [cyan]{cmd.ljust(9)}[/cyan] {desc}")
    console.print("  [dim]" + "-" * 36 + "[/dim]")


def main():
    parser = argparse.ArgumentParser(prog="debz_ai")
    parser.add_argument("--model")
    parser.add_argument("--exec")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    cfg = _load_config()
    _migrate_legacy_sessions()

    if args.model:
        cfg["MODEL"] = cfg["_CLI_MODEL"] = args.model

    tty = sys.stdout.isatty()
    ui, agent = UI(), Agent(cfg, tty)
    _set_term_background(THEME.get("bg"))

    if args.exec:
        ui.banner(cfg, agent.tools_on)
        _rotate_for_rr(cfg)
        agent.cfg = cfg

        try:
            result = agent.chat(args.exec, ui)
        except KeyboardInterrupt:
            result = "Canceled by User !!"
            console.print(Text("  ✗ Canceled by User !!", style=THEME["red"]))

        output = {
            "status": "ok" if result is not None else "hold",
            "result": result,
            "pending": agent.pending is not None
        }

        if args.json:
            print(json.dumps(output, ensure_ascii=False))
        else:
            if result is None:
                console.print("  [yellow]⚠ pesan dihold[/yellow]")
            log_debug(f"exec: model={cfg['MODEL']} result={result is not None}")
        return

    if not cfg.get("MODEL"):
        console.print("  [yellow]⚠ PERINGATAN:[/yellow] MODEL kosong!")

    server_ok = check_tools_server(cfg)
    if server_ok:
        console.print(f"  [dim green]✓ Tools server {cfg['TOOLS_PORT']} aktif[/dim green]")

    ui.banner(cfg, agent.tools_on)
    threading.Thread(target=lambda: fetch_all_models(cfg), daemon=True).start()

    style = Style.from_dict({
        "prompt.border": f"{THEME['border']}",
        "prompt.label": f"{THEME['retro_orange_bright']}",
        "prompt.text": f"{THEME['text']}",
        "prompt.hint": f"{THEME['muted']}",
        "completion-menu": f"bg:{THEME['surface']} {THEME['text']}",
        "completion-menu.completion": f"bg:{THEME['surface']} {THEME['text']}",
        "completion-menu.completion.current": f"bg:{THEME['surface_alt']} {THEME['retro_orange_bright']}",
        "scrollbar.background": f"bg:{THEME['surface']}",
        "scrollbar.button": f"bg:{THEME['border']}",
    })

    session = PromptSession(
        completer=CommandPathCompleter(),
        complete_style=CompleteStyle.MULTI_COLUMN,
        complete_while_typing=True,
        reserve_space_for_menu=8,
        style=style,
        erase_when_done=True,
        multiline=False,
    )

    while True:
        refresh_cfg(cfg)
        agent.cfg = cfg

        prompt_message = FormattedText([
            ("class:prompt.label", "❯ "),
        ])

        try:
            line = session.prompt(
                prompt_message,
                bottom_toolbar=FormattedText([
                    ("class:prompt.hint", f"  {cfg['MODEL']}  ·  Enter kirim  ·  CTRL+C Batal  ·  /h  "),
                ]),
            )
        except (EOFError, KeyboardInterrupt):
            console.print(Text("👻 bye", style=THEME["green_soft"]))
            break

        while line.rstrip().endswith("\\") and not line.lstrip().startswith(("/", "!")):
            line = line.rstrip()[:-1]
            try:
                cont = session.prompt(FormattedText([
                    ("class:prompt.label", "… ❯ "),
                ]))
            except (EOFError, KeyboardInterrupt):
                line = ""
                break
            line = f"{line}\n{cont}"

        if not line.strip():
            continue

        c_line = line.strip()
        if not c_line:
            continue

        _first, _sep, _rest = c_line.partition(" ")
        if _first in CMD_ALIASES:
            c_line = (CMD_ALIASES[_first] + (" " + _rest if _rest else "")).strip()

        if c_line in ("/exit", "/quit"):
            console.print("\n  👋 bye", style=THEME["green_soft"])
            break

        elif c_line == "/help":
            print_help()

        elif c_line == "/clear":
            os.system("clear")
            ui.banner(cfg, agent.tools_on)
            ui.memory_bar(*memory_usage(agent))

        elif c_line == "/copy":
            last_ai = next((h["content"] for h in reversed(agent.history) if h["role"] == "assistant"), "")
            if last_ai:
                try:
                    subprocess.run(["termux-clipboard-set"], input=last_ai.encode("utf-8"), check=True)
                    console.print("  [green]✓ Disalin ke clipboard![/green]")
                except Exception:
                    try:
                        save_path = os.path.join(PROJECT_ROOT, "last_response.txt")
                        with open(save_path, "w", encoding="utf-8") as f:
                            f.write(last_ai)
                        console.print(f"  [green]✓ Disimpan ke {save_path}[/green]")
                    except Exception:
                        pass
            else:
                console.print("  [yellow]⚠ Belum ada balasan AI.[/yellow]")

        elif c_line.startswith("/export"):
            md_file = os.path.join(PROJECT_ROOT, f"export_{time.strftime('%Y%m%d_%H%M%S')}.md")
            try:
                with open(md_file, "w", encoding="utf-8") as f:
                    f.write(f"# Chat Export - {time.strftime('%Y-%m-%d %H:%M:%S')}\n\n")
                    f.write(f"**Model:** {cfg['MODEL']}\n**System:** {agent.system}\n\n---\n\n")
                    for h in agent.history:
                        role = "👾 **ROOT**" if h["role"] == "user" else "👻 **Debz AI**"
                        f.write(f"{role}\n\n{h['content']}\n\n---\n\n")
                console.print(f"  [green]✓ Chat berhasil diexport → {md_file}[/green]")
            except Exception as e:
                console.print(f"  [red]✗ gagal export: {e}[/red]")

        elif c_line.startswith("/system"):
            parts = c_line.split(maxsplit=1)
            if len(parts) > 1:
                agent.system = parts[1].strip()
                console.print(f"  [green]✓ System prompt diubah:[/green] {agent.system}")
            else:
                console.print(f"  [cyan]System prompt saat ini:[/cyan] {agent.system}")

        elif c_line == "/status":
            console.print("  [dim]" + "-" * 36 + "[/dim]")
            # PROXY-FREE BUILD: selalu direct, tidak ada pool.
            _cur = "DIRECT"

            console.print(f"  [cyan]model[/cyan]    {cfg['MODEL']}")
            if cfg.get("_PROV_ID"):
                console.print(f"  [cyan]provider[/cyan]  [magenta]{cfg.get('_PROV_ID')}[/magenta]")

            console.print(f"  [cyan]routing[/cyan]  {cfg.get('_ROUTING', 'fixed')}\n  [cyan]proxy[/cyan]     {_cur}")

            console.print(f"  [cyan]tools[/cyan]     " + ("[bold green]ON[/bold green]" if agent.tools_on else "[bold red]OFF[/bold red]"))
            console.print(f"  [cyan]version[/cyan]  c0n73xt v{VERSION}\n  [cyan]UA[/cyan]      {_get_headers(cfg).get('User-Agent')}\n  [cyan]turns[/cyan]    {len(agent.history) // 2}")

            if agent.pending:
                console.print("  [cyan]pending[/cyan]  [yellow]⚠ ada hold · /retry[/yellow]")
            console.print("  [dim]" + "-" * 36 + "[/dim]")

        elif c_line == "/providers":
            console.print(f"  [dim]" + "-" * 36 + "[/dim]\n  [cyan]providers[/cyan] · routing [magenta]{cfg.get('_ROUTING', 'fixed')}[/magenta]")
            for pid, p in (cfg.get("_PROVIDERS") or {}).items():
                mark = ' [green]← aktif[/green]' if pid == cfg.get("_PROV_ID") else ''
                en = '[green]ON[/green]' if (p.get("enabled", True) is not False) else '[dim]off[/dim]'
                console.print(f"  [magenta]▸[/magenta] {pid} [{en}]{mark}\n      [dim]{p.get('base_url', '?')}[/dim]")
            console.print("  [dim]" + "-" * 36 + "[/dim]")

        elif c_line.startswith("/sid"):
            refresh_cfg(cfg)
            agent.cfg = cfg
            parts = c_line.split(maxsplit=1)
            arg = parts[1].strip() if len(parts) == 2 else ""
            pid = cfg.get("_PROV_ID", "")
            provs = cfg.get("_PROVIDERS") or {}
            p = provs.get(pid, {})
            sid_now = ((p.get("extra") or {}).get("headers") or {}).get("x-session-id", "")

            if arg in ("", "cek", "show", "status"):
                console.print("  [dim]" + "-" * 36 + "[/dim]")
                console.print(f"  [cyan]provider[/cyan]  [magenta]{pid or '-'}[/magenta]")
                if sid_now:
                    console.print(f"  [cyan]session id[/cyan] {sid_now}")
                else:
                    console.print("  [yellow]⚠ belum ada x-session-id[/yellow]")
                console.print("  [dim]" + "-" * 36 + "[/dim]")

            elif arg in ("gen", "generate", "new", "fresh") or arg.startswith(("gen ", "generate ")):
                sub = arg.split(None, 1)[1].strip() if len(arg.split(None, 1)) > 1 else ""
                targets = {}
                if sub == "all":
                    targets = {q: qp for q, qp in provs.items() if (qp.get("enabled", True) is not False)}
                elif sub:
                    if sub in provs:
                        targets = {sub: provs[sub]}
                elif p and p.get("base_url"):
                    targets = {pid: p}

                if targets:
                    for tpid, tp in targets.items():
                        if not tp.get("base_url"):
                            continue
                        sid, _, src = _gen_session_id(tp.get("base_url", ""), tp.get("api_key", ""), (tp.get("extra") or {}).get("user_agent", ""))
                        if sid:
                            _set_provider_sid(tpid, sid)
                    refresh_cfg(cfg)
                    agent.cfg = cfg

            elif arg == "del":
                if _set_provider_sid(pid, None):
                    refresh_cfg(cfg)
                    agent.cfg = cfg

            elif arg.startswith("set "):
                new_sid = arg[4:].strip()
                if new_sid and _set_provider_sid(pid, new_sid):
                    refresh_cfg(cfg)
                    agent.cfg = cfg

        elif c_line.startswith("/routing"):
            refresh_cfg(cfg)
            agent.cfg = cfg
            parts = c_line.split(maxsplit=1)

            if len(parts) == 2:
                mode = parts[1].strip().lower()
                if mode in ("fixed", "roundrobin", "failover") and _set_routing(mode):
                    cfg["_ROUTING"] = mode
            else:
                console.print(f"  [cyan]routing saat ini:[/cyan] {cfg.get('_ROUTING', 'fixed')}")

        elif c_line.startswith("/bypass"):
            refresh_cfg(cfg)
            agent.cfg = cfg
            console.print("  [dim]" + "-" * 36 + "[/dim]")
            console.print(f"  [cyan]UA[/cyan]        {_get_headers(cfg).get('User-Agent')}")
            console.print("  [dim]" + "-" * 36 + "[/dim]")

        elif c_line == "/memory":
            ui.memory_bar(*memory_usage(agent))

        elif c_line == "/backup" or c_line.startswith("/backup "):
            parts = c_line.split(maxsplit=2)
            act = parts[1].lower() if len(parts) > 1 else "list"
            name = parts[2].strip() if len(parts) > 2 else ""
            r = agent.tools.backup(act, name=name)

            if "error" in r:
                console.print(f"  [red]✗ {r['error']}[/red]")
            else:
                if act == "list":
                    bks = r.get("backups", [])
                    console.print(f"  [green]✓ {len(bks)} backup[/green]")
                    for b in bks:
                        console.print(f"  [dim]-[/dim] {b['name']}  [dim]{b['bytes']:,}b[/dim]")
                elif act == "create":
                    console.print(f"  [green]✓ backup dibuat → {r.get('backup')}[/green]")
                elif act == "restore":
                    console.print(f"  [green]✓ restore → {r.get('backup')}[/green]")
                elif act == "delete":
                    console.print(f"  [green]✓ hapus → {r.get('deleted')}[/green]")

        elif c_line == "/sched" or c_line.startswith("/sched "):
            parts = c_line.split(maxsplit=1)
            rest = parts[1].strip() if len(parts) > 1 else ""

            if rest and rest[0].isdigit():
                args_list = rest.split("|")
                sched = args_list[0].strip()
                cmd = args_list[1].strip() if len(args_list) > 1 else ""
                nm = args_list[2].strip() if len(args_list) > 2 else ""

                if cmd:
                    r = agent.tools.scheduler("add", name=nm, command=cmd, schedule=sched)
                    if "error" in r:
                        console.print(f"  [red]✗ {r['error']}[/red]")
                    else:
                        console.print(f"  [green]✓ job ditambah → {r['job']['id']}[/green]")
            else:
                r = agent.tools.scheduler("list")
                if "error" in r:
                    console.print(f"  [red]✗ {r['error']}[/red]")
                else:
                    jobs = r.get("jobs", [])
                    console.print(f"  [green]✓ {len(jobs)} job[/green]")
                    for j in jobs:
                        console.print(f"  [cyan]{j['id']}[/cyan] {j.get('name','?')} [dim]· {j.get('schedule','')}[/dim]")

        elif c_line == "/update" or c_line.startswith("/update "):
            console.print("  [yellow]→ update agent dari git...[/yellow]")
            import subprocess as _sp
            try:
                p = _sp.Popen(["bash", os.path.join(PROJECT_ROOT, "scripts", "update.sh")], stdout=_sp.PIPE, stderr=_sp.STDOUT, text=True)
                for line in p.stdout:
                    console.print(f"  {line.rstrip()}")
                p.wait()
                console.print(f"  [green]✓ selesai[/green]")
            except Exception as e:
                console.print(f"  [red]✗ gagal: {e}[/red]")

        elif c_line.startswith("/model"):
            refresh_cfg(cfg)
            agent.cfg = cfg
            parts = c_line.split(maxsplit=1)
            arg = parts[1].strip() if len(parts) == 2 else ""

            allm = fetch_all_models(cfg)
            flat = [(pid, m) for pid in sorted(allm) for m in allm[pid]]

            if arg and arg.lower() != "list":
                hit_pid, hit_m = None, None
                for pid in allm:
                    if arg in allm[pid]:
                        hit_pid, hit_m = pid, arg
                        break

                if not hit_pid:
                    for pid in allm:
                        for m in allm[pid]:
                            if arg.lower() in m.lower():
                                hit_pid, hit_m = pid, m
                                break
                        if hit_pid: break

                if hit_pid:
                    _switch_model(cfg, hit_pid, hit_m)
                    agent.cfg = cfg
            else:
                if flat:
                    search_session = PromptSession(style=style)
                    console.print("[cyan]Model AI :[/cyan]")
                    selected_choice = None
                    try:
                        while True:
                            try:
                                sub_line = search_session.prompt(HTML("  <prompt.label> Cari 🔍 </prompt.label><prompt.border> ❯ </prompt.border> ")).strip().lower()
                            except (EOFError, KeyboardInterrupt):
                                break

                            if not sub_line:
                                break

                            filtered_flat = [(pid, m) for pid, m in flat if sub_line in m.lower() or sub_line in pid.lower()]
                            if not filtered_flat:
                                continue

                            for idx, (pid, m) in enumerate(filtered_flat, 1):
                                console.print(f"  [cyan]{str(idx).rjust(3)}[/cyan] [dim]│[/dim] {m}")

                            pick = console.input("  [cyan]pilih nomor[/cyan] [dim]:[/dim] ").strip()
                            if pick.isdigit() and 1 <= int(pick) <= len(filtered_flat):
                                selected_choice = filtered_flat[int(pick) - 1]
                                break
                    except KeyboardInterrupt:
                        pass

                    if selected_choice:
                        if selected_choice[1] != cfg["MODEL"]:
                            _switch_model(cfg, selected_choice[0], selected_choice[1])
                            agent.cfg = cfg

        elif c_line.startswith("/tools"):
            args_split = c_line.split()
            if len(args_split) == 2 and args_split[1] in ("on", "off"):
                agent.tools_on = (args_split[1] == "on")
                console.print(f"  [green]✓ tools[/green] {args_split[1]}")

        elif c_line == "/allow" or c_line.startswith("/allow "):
            arg = c_line[6:].strip().lower()
            if arg in ("on", "1", "yes"):
                agent.allow_all = True
                _allow_all_set(True)
                console.print("  [green]✓ allow-all ON[/green]")
            elif arg in ("off", "0", "no"):
                agent.allow_all = False
                _allow_all_set(False)
                console.print("  [green]✓ allow-all OFF[/green]")

        elif c_line in ("/reset", "/new"):
            agent.reset()
            agent.pending = None
            console.print("  [green]✓ cleared[/green]")

        elif c_line == "/save":
            try:
                os.makedirs(SESSIONS_DIR, exist_ok=True)
            except Exception:
                pass

            session_file = os.path.join(SESSIONS_DIR, _session_filename(agent))
            try:
                with open(session_file, "w", encoding="utf-8") as f:
                    json.dump({
                        "system": agent.system,
                        "history": agent.history,
                        "pending": agent.pending,
                        "oc_session": getattr(agent, "_oc_session", ""),
                        "title": _session_title(agent),
                        "turns": len(agent.history) // 2,
                    }, f, ensure_ascii=False, indent=2)
                console.print(f"  [green]✓ session disimpan[/green] [dim]→ 🗃️ {os.path.basename(session_file)}[/dim]")
            except Exception as e:
                console.print(f"  [red]✗ gagal save: {e}[/red]")

        elif c_line == "/resume":
            _as = os.path.join(SESSIONS_DIR, "autosave.json")
            if os.path.exists(_as):
                try:
                    _session_load(_as, agent)
                    console.print(f"  [green]✓ autosave dimuat[/green] [dim]· {len(agent.history)//2} turns[/dim]")
                    if getattr(agent, "_oc_session", ""):
                        console.print(f"  [dim]· lanjut session opencode {agent._oc_session[:12]}…[/dim]")
                except Exception as e:
                    console.print(f"  [red]✗ gagal resume: {e}[/red]")
            else:
                console.print("  [yellow]⚠ belum ada autosave[/yellow]")

        elif c_line.startswith("/load"):
            parts = c_line.split(maxsplit=1)
            def _sess_key(f):
                try: return os.path.getmtime(os.path.join(SESSIONS_DIR, f))
                except Exception: return 0
            sessions = sorted([f for f in (os.listdir(SESSIONS_DIR) if os.path.isdir(SESSIONS_DIR) else []) if f.startswith("session_") and f.endswith(".json")], key=_sess_key, reverse=True)

            if len(parts) == 2:
                arg_load = parts[1].strip()
                if arg_load.isdigit() and 1 <= int(arg_load) <= len(sessions):
                    target = os.path.join(SESSIONS_DIR, sessions[int(arg_load)-1])
                else:
                    target = os.path.join(SESSIONS_DIR, arg_load) if not arg_load.startswith("/") else arg_load
                try:
                    _session_load(target, agent)
                    console.print(f"  [green]✓ session dimuat[/green] [dim]· {len(agent.history)//2} turns[/dim]")
                    if getattr(agent, "_oc_session", ""):
                        console.print(f"  [dim]· lanjut session opencode {agent._oc_session[:12]}…[/dim]")
                    else:
                        console.print("  [yellow]· session lama tanpa id opencode — konteks akan disuntikkan otomatis[/yellow]")
                except Exception as e:
                    console.print(f"  [red]✗ gagal load: {e}[/red]")
            else:
                console.print("  [dim]" + "-" * 36 + "[/dim]")
                if sessions:
                    console.print("  [cyan]Session tersedia:[/cyan]")
                    for idx, s in enumerate(sessions, 1):
                        _t, _tu, _osid = _session_meta(os.path.join(SESSIONS_DIR, s))
                        _show = (_t.strip() or "tanpa judul")[:44]
                        _oc_tag = " ⟳" if _osid else ""
                        console.print(f"  [cyan]{str(idx).rjust(2)}[/cyan] [dim]│[/dim] {_show} [dim]({_tu} turns, {s})[/dim]{_oc_tag}")
                    console.print("  [dim]Gunakan: /load <nomor> atau /load <nama_file>[/dim]")
                else:
                    console.print("  [yellow]⚠ Tidak ada file session yang tersimpan.[/yellow]")
                console.print("  [dim]" + "-" * 36 + "[/dim]")

        elif c_line == "/retry":
            if agent.pending:
                pu, pm = agent.pending
                agent.pending = None
                console.print("  [cyan]▶ lanjut hold[/cyan]")
                _r = agent.chat(pu, ui, resume_msgs=pm)
                _session_autosave(agent)

                if _r is None:
                    console.print("  [yellow]⚠ masih overload[/yellow]")
            else:
                console.print("  [dim]tidak ada hold[/dim]")

        elif c_line.startswith("!"):
            cmd = c_line[1:].strip()
            if cmd:
                ui.user_box(c_line)
                try:
                    r = agent.tools.exec(cmd)
                    if r.get("need_approval"):
                        if _allow_all_get():
                            agent.allow_all = True
                        if agent.allow_all or agent.allow_session:
                            r = agent.tools.exec(cmd, approved=True)
                        else:
                            ans = _ask_permission("Jalankan perintah?", cmd)
                            if ans in ("once", "session", "always"):
                                r = agent.tools.exec(cmd, approved=True)
                            else:
                                console.print("  [red]✗ Ditolak user[/red]")
                                continue

                    out = "\n".join(filter(None, [r.get("stdout", ""), r.get("stderr", "")]))
                    for ln in out.split("\n")[:40]:
                        console.print(f"  [dim]│[/dim] {ln}")
                except KeyboardInterrupt:
                    console.print(Text("  ✗ Canceled by Debz !!", style=THEME["red"]))

        else:

            if agent.pending:
                agent.pending = None
                if agent.history and agent.history[-1]["role"] == "user":
                    agent.history.pop()

            refresh_cfg(cfg)
            agent.cfg = cfg
            _rotate_for_rr(cfg)
            agent.cfg = cfg

            try:
                result = agent.chat(c_line, ui)
            except KeyboardInterrupt:
                result = "Canceled by Debz !!"
                console.print(Text("  ✗ Canceled by Debz !!", style=THEME["red"]))

            _session_autosave(agent)

            if result is None:
                console.print("  [yellow]⚠ dihold[/yellow]")

            ui.memory_bar(*memory_usage(agent))

if __name__ == "__main__":
    main()
