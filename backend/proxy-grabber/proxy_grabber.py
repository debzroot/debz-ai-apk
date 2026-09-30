#!/usr/bin/env python3
import concurrent.futures as cf
import json
import os
from pathlib import Path
import random
import re
import socket
import struct
import sys
import threading
import time
import warnings

import requests

warnings.filterwarnings("ignore", category=requests.packages.urllib3.exceptions.InsecureRequestWarning)
try:
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
except Exception:
    pass


_DNS_SERVERS = ["8.8.8.8", "1.1.1.1"]
_dns_cache = {}
_dns_lock = threading.Lock()


def _dns_query(host):
    host = host.rstrip(".")
    with _dns_lock:
        if host in _dns_cache:
            return _dns_cache[host]

    for server in _DNS_SERVERS:
        s = None
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.settimeout(4)
            txid = random.randrange(0, 65536)
            header = struct.pack(">HHHHHH", txid, 0x0100, 1, 0, 0, 0)
            qname = b"".join(
                bytes([len(part)]) + part.encode("idna")
                for part in host.split(".")
            ) + b"\x00"
            s.sendto(header + qname + struct.pack(">HH", 1, 1), (server, 53))
            data, _ = s.recvfrom(2048)

            if len(data) < 12:
                continue

            ancount = struct.unpack(">H", data[6:8])[0]
            if ancount == 0:
                continue

            offset = 12
            while offset < len(data) and data[offset] != 0:
                offset += data[offset] + 1
            offset += 5

            ips = []
            for _ in range(ancount):
                if offset + 2 > len(data):
                    break

                if data[offset] & 0xC0 == 0xC0:
                    offset += 2
                else:
                    while offset < len(data) and data[offset] != 0:
                        offset += data[offset] + 1
                    offset += 1

                if offset + 10 > len(data):
                    break

                rtype, _rclass, _ttl, rdlen = struct.unpack(
                    ">HHIH", data[offset:offset + 10]
                )
                offset += 10

                if offset + rdlen > len(data):
                    break

                if rtype == 1 and rdlen == 4:
                    ips.append(socket.inet_ntoa(data[offset:offset + 4]))

                offset += rdlen

            if ips:
                with _dns_lock:
                    _dns_cache[host] = ips
                return ips

        except Exception:
            continue
        finally:
            if s is not None:
                try:
                    s.close()
                except Exception:
                    pass

    with _dns_lock:
        _dns_cache[host] = []
    return []


_orig_getaddrinfo = socket.getaddrinfo


def _patched_getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
    try:
        return _orig_getaddrinfo(host, port, family, type, proto, flags)
    except socket.gaierror:
        pass

    ips = _dns_query(host)
    if not ips:
        raise socket.gaierror(-2, "Name or service not known")

    socktype = type or socket.SOCK_STREAM
    result = []
    for ip in ips:
        result.append((socket.AF_INET, socktype, proto or 6, "", (ip, port)))

    if not result:
        raise socket.gaierror(-2, "Name or service not known")
    return result


socket.getaddrinfo = _patched_getaddrinfo


SOURCES = {
    "http": [

        "https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&protocol=http&proxy_format=ipport&format=text&timeout=10000",
        "https://proxyspace.pro/http.txt",
        "https://raw.githubusercontent.com/vakhov/fresh-proxy-list/master/proxylist/http.txt",
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt",
        "https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-http.txt",
        "https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/http/data.txt",
        "https://www.proxy-list.download/api/v1/get?type=http",
        "https://raw.githubusercontent.com/mmpx12/proxy-list/master/https.txt",

        "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
        "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt",
        "https://raw.githubusercontent.com/roosterkid/openproxylist/main/HTTPS_RAW.txt",
        "https://raw.githubusercontent.com/MuRongPIG/Proxy-Master/main/http.txt",
        "https://raw.githubusercontent.com/officialputuid/KangProxy/KangProxy/http/http.txt",
        "https://raw.githubusercontent.com/prxchk/proxy-list/main/http.txt",
        "https://raw.githubusercontent.com/ALIILAPRO/Proxy/main/http.txt",
        "https://raw.githubusercontent.com/Zaeem20/FREE_PROXIES_LIST/master/http.txt",
        "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http",
        "https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-https.txt",
        "https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/https/data.txt",
        "https://proxyspace.pro/https.txt",
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies_geosite/http.txt",
        "https://raw.githubusercontent.com/mmpx12/proxy-list/master/http.txt",
        "https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt",
        "https://raw.githubusercontent.com/ErcinDedeoglu/proxies/master/proxies/http.txt",
        "https://raw.githubusercontent.com/MasoShark/proxy-list/main/http.txt",
        "https://raw.githubusercontent.com/Young-bash/proxy-list/main/http.txt",
        "https://openproxylist.xyz/http.txt",
    ],
    "socks4": [
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/socks4.txt",
        "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks4.txt",
        "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/socks4.txt",
        "https://raw.githubusercontent.com/roosterkid/openproxylist/main/SOCKS4_RAW.txt",
        "https://raw.githubusercontent.com/MuRongPIG/Proxy-Master/main/socks4.txt",
        "https://raw.githubusercontent.com/officialputuid/KangProxy/KangProxy/socks4/socks4.txt",
        "https://raw.githubusercontent.com/prxchk/proxy-list/main/socks4.txt",
        "https://raw.githubusercontent.com/ALIILAPRO/Proxy/main/socks4.txt",
        "https://raw.githubusercontent.com/Zaeem20/FREE_PROXIES_LIST/master/socks4.txt",
        "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=socks4",
        "https://www.proxy-list.download/api/v1/get?type=socks4",
        "https://proxyspace.pro/socks4.txt",
        "https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-socks4.txt",
        "https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/socks4/data.txt",
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies_geosite/socks4.txt",
        "https://raw.githubusercontent.com/mmpx12/proxy-list/master/socks4.txt",
        "https://openproxylist.xyz/socks4.txt",
        "https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&protocol=socks4&proxy_format=ipport&format=text&timeout=10000",
    ],
    "socks5": [
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/socks5.txt",
        "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt",
        "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/socks5.txt",
        "https://raw.githubusercontent.com/roosterkid/openproxylist/main/SOCKS5_RAW.txt",
        "https://raw.githubusercontent.com/MuRongPIG/Proxy-Master/main/socks5.txt",
        "https://raw.githubusercontent.com/officialputuid/KangProxy/KangProxy/socks5/socks5.txt",
        "https://raw.githubusercontent.com/prxchk/proxy-list/main/socks5.txt",
        "https://raw.githubusercontent.com/ALIILAPRO/Proxy/main/socks5.txt",
        "https://raw.githubusercontent.com/Zaeem20/FREE_PROXIES_LIST/master/socks5.txt",
        "https://raw.githubusercontent.com/hookzof/socks5_list/master/proxy.txt",
        "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=socks5",
        "https://www.proxy-list.download/api/v1/get?type=socks5",
        "https://proxyspace.pro/socks5.txt",
        "https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-socks5.txt",
        "https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/socks5/data.txt",
        "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies_geosite/socks5.txt",
        "https://raw.githubusercontent.com/mmpx12/proxy-list/master/socks5.txt",
        "https://openproxylist.xyz/socks5.txt",
        "https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&protocol=socks5&proxy_format=ipport&format=text&timeout=10000",
        "https://raw.githubusercontent.com/hookzof/socks5_list/master/all.txt",
    ],
}


TEST_URL = "https://www.gstatic.com/generate_204"


CHAT_MODEL = os.environ.get("AI_PROXY_CHAT_MODEL", "big-pickle")
CHAT_MAX_LATENCY_MS = int(os.environ.get("AI_PROXY_MAX_LATENCY", "15000"))
CHAT_SMOKE_TIMEOUT_S = float(os.environ.get("AI_PROXY_CHAT_TIMEOUT", "15"))
CHAT_SMOKE_SEM = threading.Semaphore(int(os.environ.get("AI_PROXY_CHAT_SEM", "24")))


STRICT_TLS = os.environ.get("AI_PROXY_STRICT_TLS", "0") != "0"


TEST_TIMEOUT = (6, 8)
HTTPS_TUNNEL_TIMEOUT = 6
GRAB_TIMEOUT = 30
MAX_WORKERS = 128
GRAB_WORKERS = 32
OUT_DIR = Path("proxies_out")
PROBE_FILE = Path("probe_config.json")
STATE_FILE = Path("proxy_state.json")
PID_FILE = Path("grabber.pid")
AUTO_INTERVAL = 900
KEEP_BEST = {"http": 600, "socks4": 250, "socks5": 250}
MAX_CANDIDATES = {"http": 10000, "socks4": 2000, "socks5": 2000}


FRESH_TOP = int(os.environ.get("AI_PROXY_FRESH_TOP", "4"))
FRESH_BUDGET = int(os.environ.get("AI_PROXY_FRESH_BUDGET", "400"))
USER_AGENT = "Mozilla/5.0 (X11; Linux x86_64) ProxyGrabber/4.0"


_state_lock = threading.RLock()
_write_locks = {
    "http": threading.RLock(),
    "socks4": threading.RLock(),
    "socks5": threading.RLock(),
}

_results = {"http": [], "socks4": [], "socks5": []}
_seen = {"http": set(), "socks4": set(), "socks5": set()}
_DEAD = {"http": set(), "socks4": set(), "socks5": set()}
_HTTPS_CAPABLE = set()
_counter = 0
_total = 0
_success = 0
_FRESH = set()
_FRESH_OK = {"http": set(), "socks4": set(), "socks5": set()}

_probe_cfg_cache = None
_probe_cfg_ts = 0.0


def acquire_pidfile():
    try:
        if PID_FILE.exists():
            try:
                other = int(PID_FILE.read_text().strip())
                os.kill(other, 0)
                return False
            except (ValueError, ProcessLookupError):
                pass
            except PermissionError:
                return False
        PID_FILE.write_text(str(os.getpid()))
        return True
    except Exception:
        return True


def release_pidfile():
    try:
        if PID_FILE.exists() and PID_FILE.read_text().strip() == str(os.getpid()):
            PID_FILE.unlink()
    except Exception:
        pass


def sync_probe_from_providers():
    candidates = []
    env_pf = os.environ.get("DEBZ_PROVIDERS_FILE")
    if env_pf:
        candidates.append(Path(env_pf))
    here = Path(__file__).resolve()
    candidates.append(here.parent.parent / ".ai-providers.json")
    candidates.append(here.parent / ".ai-providers.json")
    candidates.append(Path.cwd() / ".ai-providers.json")
    candidates.append(Path.cwd().parent / ".ai-providers.json")

    providers_file = None
    data = None
    for c in candidates:
        try:
            if c.is_file():
                providers_file = c
                break
        except Exception:
            continue
    if providers_file is None:
        return None
    try:
        with providers_file.open("r", encoding="utf-8") as f:
            data = json.load(f)
        active = data.get("active")
        prov = (data.get("providers") or {}).get(active) or {}
        if not prov.get("enabled", True) or not prov.get("base_url"):
            return None

        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        ua = ((prov.get("extra") or {}).get("user_agent")) or "opencode/1.0 (linux; x64)"
        headers["User-Agent"] = ua
        for h, v in ((prov.get("extra") or {}).get("headers") or {}).items():
            headers[str(h)] = str(v)

        api_key = prov.get("api_key") or ""
        if api_key:
            headers["Authorization"] = "Bearer " + api_key

        url = str(prov["base_url"]).rstrip("/") + "/models"
        cfg = {
            "url": url,
            "method": "GET",
            "headers": headers,
            "body": {},
            "accept_codes": [200, 204],
            "source": f"auto-sync:{active}",
            "synced_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
        atomic_write(PROBE_FILE, json.dumps(cfg, indent=2))
        return cfg
    except Exception:
        return None


def load_probe():
    global _probe_cfg_cache, _probe_cfg_ts

    if not PROBE_FILE.exists():
        return {}
    try:
        with PROBE_FILE.open("r", encoding="utf-8") as f:
            return json.load(f) or {}
    except Exception as exc:
        print(f"  [WARN] probe config tidak bisa dibaca: {exc}", flush=True)
        return {}


def proxy_map(proxy, ptype):
    scheme = ptype
    return {
        "http": f"{scheme}://{proxy}",
        "https": f"{scheme}://{proxy}",
    }


def ai_probe(proxy, ptype, session=None):
    pc = load_probe()
    if not pc or not pc.get("url"):
        return True

    session = session or requests.Session()
    headers = dict(pc.get("headers") or {})
    headers.setdefault("User-Agent", USER_AGENT)

    method = (pc.get("method") or "POST").upper()
    try:

        if method == "GET":
            r = session.get(
                pc["url"],
                headers=headers,
                proxies=proxy_map(proxy, ptype),
                timeout=TEST_TIMEOUT,
                verify=STRICT_TLS,
            )
        else:
            r = session.post(
                pc["url"],
                json=pc.get("body") or {},
                headers=headers,
                proxies=proxy_map(proxy, ptype),
                timeout=TEST_TIMEOUT,
                verify=STRICT_TLS,
            )

        codes = pc.get("accept_codes")
        if isinstance(codes, list):
            ok = r.status_code in codes
        elif codes == "any-except-429" or not codes:

            ok = r.status_code in (200, 204, 401, 403)
        else:
            ok = r.status_code in (200, 204, 401, 403)
        if r.status_code in (401, 403):
            return True
        if not ok:
            print(
                f"    [X:{r.status_code}] {ptype:<7} {proxy:<22} "
                f"endpoint AI nolak/blokir — skip pool",
                flush=True,
            )
            return False

        cres = _chat_ok(proxy, ptype, session)
        if cres is None:
            return True
        kind, val = cres
        if kind == "ok":
            return True
        if kind == "rl":
            print(
                f"    [CHAT-RL] {ptype:<7} {proxy:<22} "
                f"Rate limit backend (429) — IP proxy keburu, skip pool",
                flush=True,
            )
            return False
        if kind == "block":
            print(
                f"    [CHAT-BLOCK] {ptype:<7} {proxy:<22} "
                f"backend nolak (HTTP {val}) — skip pool",
                flush=True,
            )
            return False
        if kind == "upstream":
            print(
                f"    [CHAT-5XX] {ptype:<7} {proxy:<22} "
                f"backend HTTP {val} — skip pool",
                flush=True,
            )
            return False
        if kind == "code":
            print(
                f"    [CHAT:X{val}] {ptype:<7} {proxy:<22} "
                f"POST chat HTTP {val} — skip pool",
                flush=True,
            )
            return False
        if kind == "slow":
            print(
                f"    [CHAT-SLOW] {ptype:<7} {proxy:<22} "
                f"{val:.0f} ms — lewat batas, skip pool",
                flush=True,
            )
            return False
        if kind == "mitm":
            print(
                f"    [MITM-TLS] {ptype:<7} {proxy:<22} "
                f"sertifikat tidak valid (proxy MITM) — skip pool",
                flush=True,
            )
            return False
        print(
            f"    [CHAT-FAIL] {ptype:<7} {proxy:<22} "
            f"gagal ({kind}:{val}) — skip pool",
            flush=True,
        )
        return False
    except requests.exceptions.SSLError:
        print(
            f"    [MITM-TLS] {ptype:<7} {proxy:<22} "
            f"TLS verify gagal (proxy MITM) — skip pool",
            flush=True,
        )
        return False
    except requests.RequestException:
        return False


def _base_from_probe(pc):
    url = str(pc.get("url") or "").rstrip("?&").strip()
    if not url:
        return ""
    if url.endswith("/models"):
        return url[: -len("/models")]
    return url.rsplit("/models", 1)[0]


def _chat_ok(proxy, ptype, session):
    pc = load_probe()
    base = _base_from_probe(pc)
    if not base:
        return None
    if not pc.get("stream_allowed", True):
        return None

    target = base + "/chat/completions"
    headers = dict(pc.get("headers") or {})
    headers.setdefault("User-Agent", USER_AGENT)
    headers.setdefault("Content-Type", "application/json")
    headers.setdefault("Accept", "text/event-stream")
    headers.setdefault("Authorization", "Bearer public")

    body = {
        "model": CHAT_MODEL,
        "messages": [{"role": "user", "content": "ping"}],
        "max_tokens": 8,
        "stream": False,
    }

    t0 = time.monotonic()
    with CHAT_SMOKE_SEM:
        try:
            r = session.post(
                target,
                json=body,
                headers=headers,
                proxies=proxy_map(proxy, ptype),
                timeout=TEST_TIMEOUT,
                verify=True,
            )
        except requests.exceptions.SSLError:
            return ("mitm", 0)
        except requests.RequestException:
            return ("conn", 0)

    code = r.status_code
    if code == 429:

        time.sleep(2)
        try:
            r2 = session.post(
                target,
                json=body,
                headers=headers,
                proxies=proxy_map(proxy, ptype),
                timeout=TEST_TIMEOUT,
                verify=True,
            )
        except requests.exceptions.SSLError:
            return ("mitm", 0)
        except requests.RequestException:
            return ("conn", 0)
        r = r2
        code = r.status_code
        if code == 429:
            return ("rl", code)
    if code != 200:
        if code == 429:
            return ("rl", code)
        if code in (401, 403):

            lat = (time.monotonic() - t0) * 1000
            return ("ok", lat)
        if 500 <= code < 600:
            return ("upstream", code)
        return ("code", code)
    lat = (time.monotonic() - t0) * 1000
    if lat > CHAT_MAX_LATENCY_MS:
        return ("slow", lat)
    return ("ok", lat)


def _probe_fallback(proxy, ptype, session):
    try:
        r = session.get(
            "https://www.cloudflare.com/cdn-cgi/trace",
            proxies=proxy_map(proxy, ptype),
            timeout=TEST_TIMEOUT,
            verify=False,
        )
        if r.status_code == 200:
            return True
    except requests.RequestException:
        pass
    try:
        r = session.get(
            "https://example.com",
            proxies=proxy_map(proxy, ptype),
            timeout=TEST_TIMEOUT,
            verify=False,
        )
        if r.status_code == 200:
            return True
    except requests.RequestException:
        pass
    return False


def grab(url, timeout=GRAB_TIMEOUT):
    for attempt in range(2):
        try:
            kwargs = {
                "headers": {"User-Agent": USER_AGENT},
                "timeout": timeout,
            }
            if attempt > 0:
                kwargs["verify"] = False
            r = requests.get(url, **kwargs)
            r.close()
            r.raise_for_status()
            return r.text.splitlines()
        except requests.exceptions.SSLError:
            if attempt == 0:
                print(
                    "  [RETRY] SSL error fetching source, retrying without verify...",
                    flush=True,
                )
                time.sleep(1)
                continue
            raise
        except (requests.exceptions.ConnectionError, requests.exceptions.Timeout):
            if attempt == 0:
                time.sleep(2)
                continue
            raise
        except Exception:
            if attempt == 0:
                time.sleep(1)
                continue
            raise


def _bl_load():
    try:
        with open("proxies_out/blacklist.json", encoding="utf-8") as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except Exception:
        return {}


def _bl_active(p):
    try:
        bl = _bl_load()
        e = bl.get(p)
        if not e:
            return False
        ts = int(e.get("ts", 0)) if isinstance(e, dict) else int(e)
        return (time.time() - ts) < 86400
    except Exception:
        return False


def parse_proxies(lines):
    out = set()
    for raw in lines:
        line = raw.strip()
        if not line or line.startswith("#"):
            continue

        match = re.search(
            r"(?<!\d)(\d{1,3}(?:\.\d{1,3}){3})[:\s]+(\d{1,5})(?!\d)",
            line,
        )
        if not match:
            continue

        ip, port_text = match.groups()
        try:
            port = int(port_text)
            octets = [int(x) for x in ip.split(".")]
        except ValueError:
            continue

        if len(octets) != 4 or any(x < 0 or x > 255 for x in octets):
            continue

        if 1 <= port <= 65535:
            p = f"{ip}:{port}"
            if _bl_active(p):
                continue
            out.add(p)

    return out


def grab_all():
    global _HTTPS_CAPABLE
    pool = {ptype: set() for ptype in SOURCES}
    _HTTPS_CAPABLE = set()

    for ptype, urls in SOURCES.items():
        budget = MAX_CANDIDATES.get(ptype, 1000)
        per_source = max(1, budget // len(urls))
        collected = {}

        with cf.ThreadPoolExecutor(max_workers=GRAB_WORKERS) as ex:
            future_map = {ex.submit(grab, url): url for url in urls}

            for future in cf.as_completed(future_map):
                url = future_map[future]
                got = set()
                try:
                    lines = future.result()
                    got = parse_proxies(lines)
                    fname = url.rsplit("/", 1)[-1].lower()
                    if (
                        "https" in fname
                        or "https_raw" in fname
                        or "/https/" in url.lower()
                    ):
                        _HTTPS_CAPABLE.update(got)
                    print(
                        f"  [OK] {ptype:<7} "
                        f"{url.rsplit('/', 1)[-1][:28]:<28} "
                        f"+{len(got):<5}",
                        flush=True,
                    )
                except Exception as exc:
                    print(
                        f"  [XX] {ptype:<7} "
                        f"{url.rsplit('/', 1)[-1][:28]:<28} "
                        f"gagal={type(exc).__name__}",
                        flush=True,
                    )
                collected[url] = got

        sampled = set()
        for url in urls:
            src = collected.get(url)
            if not src:
                continue
            items = list(src)
            random.shuffle(items)
            sampled.update(items[:per_source])

        if len(sampled) < budget:
            need = budget - len(sampled)
            extras = [
                (url, [p for p in collected.get(url, ()) if p not in sampled])
                for url in urls
            ]
            random.shuffle(extras)
            for _url, leftovers in extras:
                if need <= 0:
                    break
                items = list(leftovers)
                random.shuffle(items)
                add = items[:need]
                sampled.update(add)
                need -= len(add)

        pool[ptype] = sampled
        print(
            f"  [CAP] {ptype:<7} budget={budget:<5} "
            f"diambil={len(sampled)} (~{per_source}/sumber)",
            flush=True,
        )

    return pool


def _grab_fresh():
    """v5.5 — Ambil kandidat khusus dari source TERATAS (yang update tiap
    menit), diurutkan source-priority. Dipakai tiap refresh biar pool selalu
    nyedot IP segar sebelum yang lama (IP fresh = belum kena quota zen).
    """
    fresh = {ptype: set() for ptype in SOURCES}

    for ptype in SOURCES:
        urls = SOURCES[ptype][:FRESH_TOP]
        budget = FRESH_BUDGET
        per_source = max(1, budget // len(urls))
        collected = {}

        with cf.ThreadPoolExecutor(max_workers=GRAB_WORKERS) as ex:
            future_map = {ex.submit(grab, url): url for url in urls}
            for future in cf.as_completed(future_map):
                url = future_map[future]
                try:
                    collected[url] = parse_proxies(future.result())
                    print(
                        f"  [FRESH] {ptype:<7} "
                        f"{url.rsplit('/', 1)[-1][:28]:<28} "
                        f"+{len(collected[url])}",
                        flush=True,
                    )
                except Exception as exc:
                    print(
                        f"  [FRESH-XX] {ptype:<7} "
                        f"{url.rsplit('/', 1)[-1][:28]:<28} "
                        f"gagal={type(exc).__name__}",
                        flush=True,
                    )

        got = set()
        for url in urls:
            src = collected.get(url)
            if not src:
                continue
            items = list(src)
            random.shuffle(items)
            add = items[:per_source]

            if len(got) < budget:
                got.update(add)
            else:
                break

        fresh[ptype] = got
        print(
            f"  [FRESH-CAP] {ptype:<7} budget={budget:<4} "
            f"diambil={len(got)} (prioritas source top-{FRESH_TOP})",
            flush=True,
        )

    return fresh


def _seed_fresh_scores(fresh_ok_map):
    """v5.5 — Naikin skor proxy yang lolos chat-200 dari source FRESH supaya
    agent.php (debz_proxy_get_score) pilih proxy segar itu LEBIH DULU daripada
    yang lama/terbakar. Format file sama persis `proxy_scores.json` runtime."""
    sf = OUT_DIR / "proxy_scores.json"
    try:
        scores = json.loads(sf.read_text(encoding="utf-8")) if sf.exists() else {}
        if not isinstance(scores, dict):
            scores = {}
    except Exception:
        scores = {}

    now = int(time.time())
    changed = False
    for proxies in fresh_ok_map.values():
        for p in proxies:
            e = scores.get(p)
            if not isinstance(e, dict):
                e = {"ok": 0, "fail": 0, "last": now}
            if int(e.get("ok", 0)) < 1:
                e["ok"] = 1
            e["last"] = now
            scores[p] = e
            changed = True

    if changed:
        atomic_write(sf, json.dumps(scores, ensure_ascii=False))
        print(f"  [FEED] {len(fresh_ok_map.get('http', ()))} fresh chat-ok "
              "score diprioritaskan (proxy_scores.json)", flush=True)


def atomic_write(path, content):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)

    tmp = path.with_name(
        f".{path.name}.{os.getpid()}.{threading.get_ident()}.tmp"
    )

    try:
        with tmp.open("w", encoding="utf-8", newline="\n") as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    finally:
        try:
            tmp.unlink(missing_ok=True)
        except Exception:
            pass


def _write_type(ptype):
    fresh_rows = sorted(_results[ptype], key=lambda item: item[0])
    fresh_map = {p: lat for lat, p in fresh_rows}
    dead = _DEAD.get(ptype, set())
    disk = []
    try:
        lf = OUT_DIR / f"{ptype}.txt"
        if lf.exists():
            for line in lf.read_text(encoding="utf-8", errors="ignore").splitlines():
                p = line.strip()
                if not p or p in fresh_map or p in dead or p in _seen[ptype]:
                    continue
                if _bl_active(p):
                    continue
                disk.append((1e12, p))
    except Exception:
        pass
    rows = (fresh_rows + disk)[:KEEP_BEST.get(ptype, 300)]
    _results[ptype] = [r for r in fresh_rows if r[1] not in dead][:KEEP_BEST.get(ptype, 300)]

    content = "".join(f"{proxy}\n" for _lat, proxy in rows)
    atomic_write(OUT_DIR / f"{ptype}.txt", content)

    return rows


def save_type_live(ptype):
    with _write_locks[ptype]:
        rows = _write_type(ptype)

        summary = {
            "count": len(rows),
            "file": str(OUT_DIR / f"{ptype}.txt"),
            "top5": [proxy for _lat, proxy in rows[:5]],

            "hold": {
                p: _kg_fail.get((p, ptype), 1)
                for p in sorted(_kg_hold[ptype])
            },
        }

        atomic_write(
            OUT_DIR / f"{ptype}.json",
            json.dumps(summary, indent=2),
        )

    return summary


def save_all():
    summary = {}
    for ptype in _results:
        summary[ptype] = save_type_live(ptype)

    atomic_write(
        OUT_DIR / "summary.json",
        json.dumps(summary, indent=2),
    )
    return summary


def add_success(ptype, proxy, latency_ms):
    global _success

    with _state_lock:
        if proxy in _seen[ptype]:
            return False

        _seen[ptype].add(proxy)
        _results[ptype].append((latency_ms, proxy))
        _success += 1

        _results[ptype].sort(key=lambda item: item[0])
        _keep = KEEP_BEST.get(ptype, 300)
        if len(_results[ptype]) > _keep:
            _results[ptype] = _results[ptype][:_keep]

    save_type_live(ptype)
    return True


_thread_local = threading.local()


def _get_session():
    s = getattr(_thread_local, "sess", None)
    if s is None:
        s = requests.Session()
        _thread_local.sess = s
    return s


def _in_old_list(proxy, ptype):
    try:
        lf = OUT_DIR / f"{ptype}.txt"
        return proxy in set(
            l.strip() for l in lf.read_text().splitlines() if l.strip()
        )
    except Exception:
        return False


_kg_fail = {}
_kg_hold = {"http": set(), "socks4": set(), "socks5": set()}


def validate_one(proxy, ptype):
    global _counter

    t0 = time.monotonic()
    session = _get_session()
    try:
        ok = ai_probe(proxy, ptype, session=session)
        if not ok:
            with _state_lock:

                n = _kg_fail.get((proxy, ptype), 0)
                known = (proxy, ptype) in _kg_fail or _in_old_list(proxy, ptype)
                if known and n < 2:
                    _kg_fail[(proxy, ptype)] = n + 1
                    _kg_hold[ptype].add(proxy)
                    print(
                        f"  [GRACE] {ptype:<7} {proxy:<22} ditahan "
                        f"(fail #{n+1}, masih 1x kesempatan)",
                        flush=True,
                    )
                    return
                _kg_fail.pop((proxy, ptype), None)
                _kg_hold[ptype].discard(proxy)
                _DEAD[ptype].add(proxy)
                if known:
                    print(
                        f"  [DROP] {ptype:<7} {proxy:<22} gagal {n+1}x "
                        f"beruntun — dibuang dari pool",
                        flush=True,
                    )
                else:
                    print(
                        f"  [DROP] {ptype:<7} {proxy:<22} kandidat baru "
                        f"gagal live — dibuang",
                        flush=True,
                    )
            return
        with _state_lock:
            _kg_fail.pop((proxy, ptype), None)
            _kg_hold[ptype].discard(proxy)

        latency_ms = (time.monotonic() - t0) * 1000
        if add_success(ptype, proxy, latency_ms):
            if (proxy, ptype) in _FRESH:
                with _state_lock:
                    _FRESH_OK.setdefault(ptype, set()).add(proxy)
            print(
                f"  [LIVE] {ptype:<7} {proxy:<22} "
                f"{latency_ms:7.1f} ms  "
                f"available={len(_results[ptype])}",
                flush=True,
            )
    except requests.RequestException:
        pass
    except Exception:
        pass
    finally:
        with _state_lock:
            _counter += 1
            current = _counter
            total = _total
            success = _success

        if current % 50 == 0 or current == total:
            print(
                f"  progress {current}/{total} "
                f"({current / total * 100:.1f}%) ok={success}",
                flush=True,
            )


def _order_pool(ptype, proxies):

    lf = OUT_DIR / f"{ptype}.txt"
    try:
        old = set(l.strip() for l in lf.read_text().splitlines() if l.strip())
    except Exception:
        old = set()
    kg = [p for p in proxies if p in old]
    rest = [p for p in proxies if p not in old]
    return kg + rest


def validate(pool, priority=None):
    global _counter, _total, _success, _FRESH, _FRESH_OK

    with _state_lock:
        _counter = 0
        _success = 0
        _FRESH = set()
        _FRESH_OK = {ptype: set() for ptype in _results}
        for ptype in _DEAD:
            _DEAD[ptype].clear()
        if priority:
            _FRESH = {
                (p, ptype)
                for ptype, plist in priority.items()
                for p in plist
            }
        for ptype in _results:
            _results[ptype].clear()
            _seen[ptype].clear()
            lf = OUT_DIR / f"{ptype}.txt"
            if lf.exists():
                try:

                    for l in lf.read_text().splitlines():
                        p = l.strip()
                        if p:
                            pool.setdefault(ptype, set()).add(p)
                except Exception:
                    pass

            try:
                lf2 = OUT_DIR / f"{ptype}.json"
                if lf2.exists():
                    m = json.loads(lf2.read_text(encoding="utf-8"))
                    h = m.get("hold", {}) or {}
                    if isinstance(h, list):
                        h = {str(x).strip(): 1 for x in h if str(x).strip()}
                    for p, cnt in h.items():
                        p = str(p).strip()
                        if p:
                            pool.setdefault(ptype, set()).add(p)
                            _kg_fail[(p, ptype)] = max(1, int(cnt))
                            _kg_hold[ptype].add(p)
            except Exception:
                pass

        _total = sum(len(ps) for ps in pool.values())
        if priority:
            for ptype, plist in priority.items():
                pool.setdefault(ptype, set()).update(plist)
            _total = sum(len(ps) for ps in pool.values())

    print(f"  total proxy untuk dites: {_total}", flush=True)

    if _total == 0:
        return

    jobs = []
    seen = set()
    if priority:
        for ptype, plist in priority.items():
            for proxy in plist:
                key = (proxy, ptype)
                if key not in seen:
                    seen.add(key)
                    jobs.append(key)
    for ptype, proxies in pool.items():
        for proxy in _order_pool(ptype, proxies):
            key = (proxy, ptype)
            if key not in seen:
                seen.add(key)
                jobs.append(key)

    with cf.ThreadPoolExecutor(max_workers=MAX_WORKERS) as ex:
        futures = [
            ex.submit(validate_one, proxy, ptype)
            for proxy, ptype in jobs
        ]

        for future in cf.as_completed(futures):
            try:
                future.result()
            except Exception:
                pass


def update_state(run_no, summary=None):
    state = {}
    if STATE_FILE.exists():
        try:
            with STATE_FILE.open("r", encoding="utf-8") as f:
                state = json.load(f) or {}
        except Exception:
            state = {}

    state.update({
        "last_run": time.strftime("%Y-%m-%d %H:%M:%S"),
        "run_no": run_no,
        "keep_best": KEEP_BEST,
        "live_output": True,
    })

    if summary is not None:
        state["summary"] = summary

    atomic_write(STATE_FILE, json.dumps(state, indent=2))


def run_once(run_no=1):
    print(
        f"\n===== GRAB #{run_no} — "
        f"{time.strftime('%Y-%m-%d %H:%M:%S')} =====",
        flush=True,
    )

    pcfg = sync_probe_from_providers()
    if pcfg:
        print(
            f"  [SYNC] probe → {pcfg['url']} (dari provider aktif: "
            f"{pcfg['source']})",
            flush=True,
        )
    else:
        pcfg = load_probe()
        if pcfg and pcfg.get("url"):
            print(f"  [SYNC] probe → pakai probe_config.json manual", flush=True)

    print("[1/3] Grabbing proxy dari sumber publik...", flush=True)
    print("  [1a] Tarik FRESH dulu (source update tiap menit)...", flush=True)
    fresh_pool = _grab_fresh()
    for ptype, proxies in fresh_pool.items():
        print(f"  fresh {ptype:<7}: {len(proxies)} kandidat (uji duluan)",
              flush=True)
    pool = grab_all()

    for ptype, proxies in pool.items():
        print(f"  {ptype:<7}: {len(proxies)} unique", flush=True)

    for ptype, proxies in pool.items():
        print(
            f"  [FULL] {ptype}: {len(proxies)} kandidat — FRESH diuji pertama, "
            f"live-save tiap ada yang lolos (langsung bisa di-rotate)",
            flush=True,
        )

    pc = load_probe()
    if pc and pc.get("url"):
        print(
            f"[2/3] Validasi AI PROBE "
            f"(→ {pc['url']})...",
            flush=True,
        )
    else:
        print(
            "[2/3] Validasi HTTPS TUNNEL (multi-endpoint, verify=False)...",
            flush=True,
        )

    validate(pool, priority=fresh_pool)

    print("[3/3] Feed skor fresh + finalisasi...", flush=True)
    _seed_fresh_scores(_FRESH_OK)
    summary = save_all()

    print("========== HASIL =", flush=True)
    for ptype, info in summary.items():
        print(
            f"  {ptype:<7} healthy={info['count']} "
            f"-> {info['file']}",
            flush=True,
        )
        if info["top5"]:
            print(
                f"            contoh: {', '.join(info['top5'])}",
                flush=True,
            )

    update_state(run_no, summary)
    return summary


def parse_interval(argv):
    interval = AUTO_INTERVAL
    if "--interval" in argv:
        try:
            idx = argv.index("--interval")
            interval = int(argv[idx + 1])
        except (ValueError, IndexError):
            print("[WARN] --interval tidak valid; memakai default.", flush=True)
    return max(120, interval)


def refresh_only(run_no=1):
    """Refresh RINGAN + FRESH-FIRST (v5.5):
    1. Tarik kandidat baru khusus dari source TOP yang update tiap menit
       (proxyscrape live, proxyspace, vakhov/fresh, monosans, ...).
    2. Kandidat FRESH di-test chat-200 PALING DULU (IP nya belum terbakar
       quota zen → peluang lolos jauh lebih tinggi).
    3. Yang lolos dapat score boost di proxy_scores.json supaya agent.php
       pilih proxy segar lebih dulu dari yang lama.
    Jauh lebih cepat dari grab penuh (detik~1 menit) & ringan CPU — pas buat
    dijaga terus-terusan biar pool selalu nyedot IP segar, karena proxy gratis
    cepet mati & quota zen cepet habis per-IP.
    """
    print(
        f"\n===== REFRESH #{run_no} (FRESH-FIRST) — "
        f"{time.strftime('%Y-%m-%d %H:%M:%S')} =====",
        flush=True,
    )

    pcfg = sync_probe_from_providers() or load_probe()
    if pcfg and pcfg.get("url"):
        print(
            f"  [SYNC] probe → {pcfg['url']} (refresh fresh-first)",
            flush=True,
        )
    else:
        print(
            "  [SYNC] probe config kosong — validation gak optimal. "
            "Jalankan --once dulu.",
            flush=True,
        )

    print("[1/3] Tarik kandidat baru dari source FRESH (update menit)...",
          flush=True)
    fresh_pool = _grab_fresh()
    for ptype, proxies in fresh_pool.items():
        print(f"  fresh {ptype:<7}: {len(proxies)} kandidat (uji duluan)",
              flush=True)

    print("[2/3] Validasi chat-200 — FRESH didahulukan, lalu pool+hold lama...",
          flush=True)
    validate({}, priority=fresh_pool)

    print("[3/3] Feed skor fresh + finalisasi...", flush=True)
    _seed_fresh_scores(_FRESH_OK)
    summary = save_all()

    print("========== HASIL REFRESH =", flush=True)
    for ptype, info in summary.items():
        print(
            f"  {ptype:<7} healthy={info['count']} -> {info['file']}",
            flush=True,
        )
        if info["top5"]:
            print(f"            contoh: {', '.join(info['top5'])}", flush=True)

    update_state(run_no, summary)
    return summary


def main():

    once = ("--once" in sys.argv) or ("--refresh" in sys.argv)
    refresh = "--refresh" in sys.argv
    interval = parse_interval(sys.argv)

    if not acquire_pidfile():
        print(
            f"[LOCK] Grabber lain lagi jalan (pidfile {PID_FILE}). Keluar.",
            flush=True,
        )
        sys.exit(0)

    try:
        run_no = 0
        while True:
            run_no += 1
            try:
                if refresh:
                    refresh_only(run_no)
                else:
                    run_once(run_no)
            except KeyboardInterrupt:
                print("\n[STOP] Dihentikan pengguna.", flush=True)
                break
            except Exception as exc:
                print(
                    f"[ERR] grab/validasi gagal: "
                    f"{type(exc).__name__}: {exc}",
                    flush=True,
                )

            if once:
                break

            print(
                f"\n🔄 Run #{run_no} selesai — restart otomatis dalam 5s "
                f"(grab fresh + re-test semua proxy)...",
                flush=True,
            )
            try:
                time.sleep(5)
            except KeyboardInterrupt:
                print("\n[STOP] Dihentikan pengguna.", flush=True)
                break
    finally:
        release_pidfile()


if __name__ == "__main__":
    main()
