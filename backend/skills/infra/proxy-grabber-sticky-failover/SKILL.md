---
name: proxy-grabber-sticky-failover
description: "Sticky proxy dengan failover dan auto-grab fresh sources."
version: "1.0.0"
---

# Proxy Grabber: Fresh Sources + Sticky Failover + Auto-Grab

Prosedur upgrade sistem proxy grabber supaya: (1) dapet proxy fresh (bukan rebutan repo mainstream), (2) proxy sticky — 1 proxy dipakai terus sampai error, (3) failover beneran — ganti proxy cuma pas error, (4) auto-grab tiap 5-10 menit, hasil langsung kepake tanpa restart.

## Arsitektur (Termux/Android PHP-FPM)

- `proxy_grabber.py` → grab + validasi → tulis `proxies_out/{http,socks4,socks5}.txt`
- `proxy_manager.py` (WebUI port 8766) → scheduler auto-grab + state `proxy_state.json`
- `agent.php` (root web nginx) → baca state + list proxy tiap request, apply ke curl chat
- WebUI root nginx: cek `nginx.conf` (bisa beda dari folder Workspaces — verifikasi dulu file mana yang kepake!)

## Sumber Proxy Fresh (update cepat, bukan mainstream doang)

- `https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http&timeout=1000&country=all&ssl=all&anonymity=all` (update tiap menit)
- `https://raw.githubusercontent.com/roosterkid/openproxylist/main/HTTPS_RAW.txt`
- `https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/http/data.txt` (update 10-30 menit)
- `https://www.proxy-list.download/api/v1/get?type=http`
- Tambahin AI probe: tiap proxy di-test ke endpoint AI (misal `opencode.ai/zen/v1/chat/completions`) → yang balas 429 dibuang. Filter: konek + bukan 429 + latency wajar.

## Patch Penting

### 1. Auto-grab scheduler (proxy_manager.py)
- **Bug umum**: scheduler butuh `enabled: true` → auto-grab ikut mati kalau routing dimatiin. Fix: auto-grab jalan terus terpisah dari `enabled` (list harus tetap fresh walau proxy off).
- **Bug umum**: cek tiap 10 detik, begitu grabber kelar langsung start ulang = loop rebutan. Fix: hormatin `auto_interval` (default 420s, range 120-3600) — jangan start ulang kalau belum lewat interval.
- Guard anti-overlap: jangan spawn grabber dobel kalau proses lama masih jalan.

### 2. Sticky + Failover (agent.php)
- `debz_proxy_pick()`: kalau `sticky=true` dan `sticky_proxy` masih hidup → pakai itu terus; ganti cuma pas `forceNew` (error/429/timeout).
- Blacklist 429: proxy yang kena rate-limit di-blacklist 30 menit.
- **Bug bahaya**: jangan pernah nulis state parsial — state file bisa ke-overwrite jadi `{"rr_index":1}` doang kalau `load_state()` return null (enabled false) lalu path non-sticky nulis. Fix: fungsi save selalu merge dengan state existing.
- Mode `auto` = direct dulu, proxy dipakai kalau kena 429 (`_useProxy` flag). ⚠️ **JANGAN ada fallback ke direct** saat mode `proxy`/`auto` aktif tapi list kosong — tunggu proxy-grabber ngasih proxy baru (minta grab via `_proxy_request_grab` / `debz_proxy_request_grab`, nunggu live probe), kalau masih kosong → hold/abort, jangan lempar request ke direct.

### 3. Atomic write (anti race)
- Tulis file via `tmp` + `os.replace()` — agent.php gak akan pernah baca file setengah jadi.

### 4. WebUI
- Tambah toggle Sticky & Auto-Grab di index.html + handler POST `/api/config` (field: `sticky`, `auto_grab`, `auto_interval`).
- Setting persisten di `proxy_state.json` — sekali ON tetap ON walau restart.

## Verifikasi End-to-End

1. `curl -s http://localhost:8766/api/status` → cek `sticky:true`, `auto_grab:true`, `running`
2. Test sticky: panggil `debz_proxy_pick()` 2x → harus balikin proxy sama; `forceNew` → ganti
3. Cek `http.txt` gak berisi dummy (`1.1.1.1:1111` dll) — bersihin kalau ada
4. Validasi 28k proxy butuh ~10-20 menit; hasil langsung kepake tanpa restart
5. Proxy cuma ngaruh ke WebUI chat (`agent.php`) — CLI (`debz-term.py`) & tools server (`backend.py`) tetep direct, kecuali env proxy global di-set

## Catatan

- Proxy publik gratis = lotere: mayoritas mati/lemot. Buat production stabil → datacenter/residential proxy.
- Kalau `enabled:false` di state → `debz_proxy_load_state()` return null → proxy gak kepake sama sekali (sesuai kode line 28 agent.php).
- Restart manager bersih: kill grabber + manager lama dulu, baru start manager baru (biar state proses konsisten).