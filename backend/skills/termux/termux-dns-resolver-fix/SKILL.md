---
name: termux-dns-resolver-fix
description: "Fix DNS resolver Termux gagal getaddrinfo errno 7."
version: "1.0.0"
---

# Fix DNS Resolver Gagal di Termux (getaddrinfo gaierror Errno 7)

## Gejala
- Semua request HTTP/HTTPS gagal dengan `ConnectionError` / `socket.gaierror: [Errno 7] No address associated with hostname`
- Ping ke IP (8.8.8.8) JALAN, tapi ping ke hostname `unknown host`
- `socket.gethostbyname('google.com')` gagal
- Tapi UDP DNS query manual ke 8.8.8.8:53 JALAN (bisa resolve)

## Diagnosis (urut)
1. Test koneksi IP: `ping -c 2 8.8.8.8` → jalan = koneksi hidup
2. Test resolve: `python3 -c "import socket; print(socket.gethostbyname('google.com'))"` → gaierror
3. Test UDP DNS manual (python struct query ke 8.8.8.8:53) → JALAN
4. **Kesimpulan**: bionic/glibc resolver sistem gagal pake resolv.conf Termux, padahal UDP DNS langsung jalan. Bukan masalah internet.

## Root Cause
Android bionic resolver (dipakai Python) gagal connect ke nameserver yang ditulis Termux di `/etc/resolv.conf` (biasanya 1.1.1.1 & 8.8.8.8). UDP DNS keluar sebenernya jalan.

## Solusi (tanpa root)
Override `socket.getaddrinfo` dengan resolver UDP manual yang query langsung ke 8.8.8.8/1.1.1.1. Inject di awal script setelah `import requests`:

```python
import socket as _socket
import struct as _struct

_DNS_SERVERS = ["8.8.8.8", "1.1.1.1"]
_dns_cache = {}

def _dns_query(host):
    host = host.rstrip('.')
    if host in _dns_cache:
        return _dns_cache[host]
    for server in _DNS_SERVERS:
        try:
            s = _socket.socket(_socket.AF_INET, _socket.SOCK_DGRAM)
            s.settimeout(4)
            txid = 0xD3B0
            header = _struct.pack('>HHHHHH', txid, 0x0100, 1, 0, 0, 0)
            qname = b''.join(bytes([len(p)]) + p.encode() for p in host.split('.')) + b'\x00'
            s.sendto(header + qname + _struct.pack('>HH', 1, 1), (server, 53))
            data, _ = s.recvfrom(2048)
            s.close()
            ancount = _struct.unpack('>H', data[6:8])[0]
            if ancount == 0:
                continue
            offset = 12
            while data[offset] != 0:
                offset += data[offset] + 1
            offset += 5
            ips = []
            for _ in range(ancount):
                if data[offset] & 0xC0 == 0xC0:
                    offset += 2
                else:
                    while data[offset] != 0:
                        offset += data[offset] + 1
                    offset += 1
                rtype, rclass, ttl, rdlen = _struct.unpack('>HHIH', data[offset:offset+10])
                offset += 10
                if rtype == 1 and rdlen == 4:
                    ips.append(_socket.inet_ntoa(data[offset:offset+4]))
                offset += rdlen
            if ips:
                _dns_cache[host] = ips
                return ips
        except Exception:
            continue
    _dns_cache[host] = []
    return []

_orig_getaddrinfo = _socket.getaddrinfo
def _patched_getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
    try:
        return _orig_getaddrinfo(host, port, family, type, proto, flags)
    except _socket.gaierror:
        pass
    ips = _dns_query(host)
    if not ips:
        raise _socket.gaierror(-2, 'Name or service not known')
    res = []
    socktype = type or _socket.SOCK_STREAM
    for ip in ips:
        try:
            res.append((_socket.AF_INET, socktype, proto or 6, '', (ip, port)))
        except Exception:
            pass
    if not res:
        raise _socket.gaierror(-2, 'Name or service not known')
    return res

_socket.getaddrinfo = _patched_getaddrinfo
```

## Catatan Penting
- `socket.gethostbyname()` TIDAK ikut ke-override (fungsi terpisah) — tapi `requests` & `getaddrinfo` jalan, cukup buat grabber.
- Test: `socket.getaddrinfo('host', 443)` harus return IP.
- HTTP request via `requests.get()` harus 200.
- Rate limit (HTTPError 429) di sebagian source itu NORMAL, bukan error DNS.
- Validasi ribuan proxy butuh waktu menit — output baru nulis di akhir, jangan kira error kalau masih 0.
