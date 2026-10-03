---
name: termux-broken-dns-doh-patch
description: "Patch DNS broken Termux bionic resolver dengan DoH."
version: "1.0.0"
---

# Fix DNS Broken di Termux (bionic resolver gaierror) — DoH Patch

## Gejala
- Semua script Python/curl gagal resolve hostname: `URLError`, `socket.gaierror: [Errno 7] No address associated with hostname`, `curl: (6) Could not resolve host`
- Tapi jaringan hidup: `curl --resolve host:443:IP https://host` jalan, raw UDP DNS ke 8.8.8.8:53 jalan, TCP ke IP langsung jalan
- `$PREFIX/etc/resolv.conf` udah bener (nameserver 8.8.8.8) tapi tetap gagal

## Root Cause
Android 13+ (Termux) resolve DNS via **netd/dnsproxyd** (`/dev/socket/dnsproxyd`), BUKAN baca `$PREFIX/etc/resolv.conf`. Kalau netd broken / bionic getaddrinfo error, semua resolve gagal. Port 53 lokal gak bisa dipake tanpa root (tsu sering Permission denied di device non-root).

## Diagnosa Cepat
```sh
# 1. jaringan hidup?
curl -sI --max-time 8 https://example.com          # exit 6 = DNS broken
curl -sS --max-time 8 --doh-url https://1.1.1.1/dns-query https://example.com  # 200 = DoH jalan, berarti bypass solusi
python3 -c "import socket; print(socket.gethostbyname('example.com'))"  # gaierror = bionic broken
# 2. cek resolver lib
ldd $(command -v python3) | grep bionic            # libc.so -> bionic = pakai netd
# 3. udp 53 outbound?
timeout 4 bash -c 'echo x > /dev/udp/8.8.8.8/53' && echo OK
```

## Solusi: Monkey-patch socket.getaddrinfo via DoH
Satu patch di awal script Python — kebagian SEMUA library (urllib, requests, http.client):

```python
import socket as _socket, struct, random, threading, time
from urllib.request import Request, urlopen

DOH = ["https://1.1.1.1/dns-query", "https://8.8.8.8/dns-query"]
_cache, _lock = {}, threading.Lock()

def _doh(host, qtype):
    qid = random.randint(0, 0xFFFF)
    qname = b"".join(bytes([len(l)]) + l.encode() for l in host.split(".")) + b"\x00"
    wire = struct.pack(">HHHHHH", qid, 0x0100, 1, 0, 0, 0) + qname + struct.pack(">HH", qtype, 1)
    for srv in DOH:
        try:
            req = Request(srv, data=wire, headers={"Content-Type": "application/dns-message", "Accept": "application/dns-message"})
            with urlopen(req, timeout=8) as r: resp = r.read()
            idx = 12
            while idx < len(resp):
                if resp[idx] == 0: idx += 1; break
                idx += resp[idx] + 1
            idx += 4
            n = struct.unpack(">H", resp[6:8])[0]; ips = []
            for _ in range(n):
                if idx + 10 > len(resp): break
                if resp[idx] & 0xC0 == 0xC0: idx += 2
                else: idx += resp[idx] + 1
                rt, rc, ttl, rl = struct.unpack(">HHIH", resp[idx:idx+10]); idx += 10
                if rt == 1 and rl == 4: ips.append(".".join(str(b) for b in resp[idx:idx+4]))
                elif rt == 28 and rl == 16: ips.append(":".join(f"{(resp[idx+i]<<8)|resp[idx+i+1]:x}" for i in range(0,16,2)))
                idx += rl
            if ips: return ips
        except Exception: continue
    return []

def _resolve(host, family=0):
    now = time.time()
    with _lock:
        if host in _cache and now - _cache[host][1] < 600: return _cache[host][0]
    ips = _doh(host, 28) if family == _socket.AF_INET6 else (_doh(host, 1) or _doh(host, 28))
    with _lock: _cache[host] = (ips, now)
    return ips

_orig = _socket.getaddrinfo
def _patched(host, port, family=0, type=0, proto=0, flags=0):
    if isinstance(host, str):
        is_ip = any(_socket.inet_pton(af, host) or True for af in (_socket.AF_INET, _socket.AF_INET6) if True) if False else False
        for af in (_socket.AF_INET, _socket.AF_INET6):
            try: _socket.inet_pton(af, host); is_ip = True; break
            except OSError: continue
        if not is_ip:
            ips = _resolve(host, family)
            if ips:
                out = []
                for ip in ips:
                    try: out.extend(_orig(ip, port, family, type, proto, flags))
                    except Exception: continue
                if out: return out
    return _orig(host, port, family, type, proto, flags)

_socket.getaddrinfo = _patched
```

## Catatan
- Jangan resolve via IP + rewrite URL (SSL cert verify gagal karena cert valid buat hostname, bukan IP). Patch getaddrinfo aja — hostname & SNI tetap utuh.
- DoH server 1.1.1.1 / 8.8.8.8 punya SAN IP di cert-nya, jadi urllib verify aman.
- Cache 10 menit biar gak spam DoH tiap request.
- Fallback ke bionic resolver kalau DoH gagal (biar tetep jalan di environment normal).
- Buat CLI: `curl --doh-url https://1.1.1.1/dns-query <url>` juga bisa bypass tanpa patch.