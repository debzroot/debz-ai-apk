# Fix DNS Rusak di Termux/Android (Bypass via DoH)

## Gejala
- `socket.gaierror: [Errno 7] No address associated with hostname` di Python
- `cURL error: OpenSSL SSL_read: unexpected eof while reading` (PHP cURL / Guzzle)
- Resolve domain gagal, tapi `ping 8.8.8.8` & TCP ke IP langsung jalan
- `resolv.conf` (Termux & /etc) gak ngefek karena resolver bionic Android (`netd`) yang dipake app

## Root Cause
Device rooted (KSU/Magisk) + modul tweak (misal XTreme_Tweak_Debz) bisa bikin `netd`/bionic resolver rusak. App (PHP/Python/curl) resolve lewat bionic, bukan resolv.conf → semua request ke domain gagal. TCP keluar tetap sehat.

## Diagnosa Cepat
```bash
# 1. Cek resolve sistem (harusnya gagal)
python3 -c "import socket; print(socket.gethostbyname('example.com'))"
# 2. Cek UDP DNS langsung ke 8.8.8.8 (harusnya jalan) — pakai stub/udp query
# 3. Cek TCP ke IP (harusnya jalan)
curl -s --resolve example.com:443:93.184.215.14 -o /dev/null -w "%{http_code}" https://example.com
# 4. Test DoH (ini fix-nya)
curl -s --doh-url https://dns.google/dns-query --resolve dns.google:443:8.8.8.8 -o /dev/null -w "%{http_code}" https://example.com
```

## Fix: DNS-Over-HTTPS (tanpa sentuh resolver sistem)

### 1. curl binary — tambah flag
```bash
curl --doh-url https://dns.google/dns-query --resolve dns.google:443:8.8.8.8 URL
```
Cek support: `curl --help all | grep doh`

### 2. PHP cURL — tambah 2 option di setiap handle yang ke domain eksternal
```php
CURLOPT_DOH_URL => 'https://dns.google/dns-query',
CURLOPT_RESOLVE => ['dns.google:443:8.8.8.8'],
```
Cek support: `php -r 'var_dump(defined("CURLOPT_DOH_URL"));'`

### 3. Python requests — monkey-patch socket.getaddrinfo dengan resolver UDP ke 8.8.8.8:53
```python
import socket, struct, random
_orig = socket.getaddrinfo
def _doh_getaddrinfo(host, port=None, family=0, type=0, proto=0, flags=0):
    # query A record via UDP 8.8.8.8:53, fallback ke _orig
    ...
socket.getaddrinfo = _doh_getaddrinfo
```
Catatan: `socket.gethostbyname` TIDAK lewat getaddrinfo (jangan test pake itu). Test pake `requests.get`.

## Catatan Penting
- `resolv.conf` Termux (`$PREFIX/etc/resolv.conf`) TIDAK dipake bionic — jangan buang waktu edit itu.
- `setprop net.dns1 8.8.8.8` gak ngefek ke resolver bionic.
- `pkill -f "pattern"` bisa self-kill shell sendiri kalau pattern ada di command line — hati-hati.
- Restart backend: `debz.sh stop && debz.sh start` (jangan pkill backend.py dari shell tool, karena shell tool jalan lewat backend.py → self-kill)
- Backup file sebelum patch: `cp file file.bak-doh`
- Validasi: `php -l file.php` dan `python3 -m py_compile file.py`

## Verifikasi Fix
- `http_request` tool ke domain → HTTP 200 (bukan SSL EOF)
- PHP cURL ke api.openai.com → HTTP 401 (bukan SSL error; 401 = koneksi OK, key salah)
- `requests.get('https://example.com')` → 200