---
name: proxy-grabber-live-streaming-audit
description: "Audit live-streaming proxy grabber Termux hingga WebUI manager."
version: "1.0.0"
---

# Proxy Grabber Live-Streaming Audit & Fix (Debz AI / Termux)

## Lokasi
- Kode: `~/debz-ai/proxy-grabber/proxy_grabber.py` (v3.5+)
- Manager WebUI: `~/debz-ai/proxy-grabber/webui/proxy_manager.py` (port 8766)
- Agent pick: `~/debz-ai/agent.php` (`debz_proxy_pick`, `debz_proxy_list_file`)
- Output: `~/debz-ai/proxy-grabber/proxies_out/{http,socks4,socks5}.txt` + `summary.json` + `blacklist.json`
- Log: `~/debz-ai/proxy-grabber/run.log`

## Topologi (setelah fix v3.5)
1. `add_success()` → `save_type_live()` → `_write_type()` menulis `{ptype}.txt` **langsung** tiap proxy lolos validasi (live streaming, TIDAK nunggu run selesai).
2. Agent & WebUI baca `{ptype}.txt` → otomatis sync real-time.
3. `validate()` (v3.5): SEMUA proxy di list lama digabung ke pool → **wajib di-test ulang tiap run**; yang gagal otomatis DROP. Tidak ada lagi merge known-good latency 99999 yang bikin proxy mati nyangkut berjam-jam.
4. `parse_proxies()` + `_order_pool()`: skip proxy yang lagi aktif di blacklist → IP 429 tidak masuk hasil scan.
5. Blacklist 24 jam flat (`_bl_active` 86400s, `_bl_tier` di manager/agent selalu 86400).

## Gejala & Akar Masalah (yang pernah terjadi)
- **429 beruntun di log agent** → proxy basi nyangkut di list (merge known-good tanpa re-test). Fix: v3.5 re-test semua.
- **`live_*.txt` file sisa** → kode lama; kode baru langsung ke `{ptype}.txt`. Bisa diabaikan/dihapus.
- **http.txt kosong** → proxy http publik gratis lagi mati massal (bukan bug). Cek `xargs -P12 timeout 5 curl -x http://PROXY https://www.gstatic.com/generate_204`.
- **BL 24 jam agresif** → pool bisa menyusut drastis di awal (proxy yang dulu lolos sekarang ke-BL). Wajar; siklus berikutnya mengisi dari kandidat baru.

## Prosedur Audit Cepat
1. `ps aux | grep proxy_manager` — manager harus jalan.
2. `curl -s http://127.0.0.1:8766/api/status | python3 -m json.tool` — cek list_len, blacklist, progress.
3. `tail -20 run.log` — cek LIVE events & progress.
4. `stat -c '%y %n' proxies_out/*.txt` — cek timestamp: kalau update saat run masih jalan → live streaming OK.

## Prosedur Patch Aman
1. Backup: `cp proxy_grabber.py ~/.ai_staging/BACKUP/proxy_grabber.py.$(date +%Y%m%d_%H%M%S)`
2. Patch via Python replace (bukan sed) + `python3 -m py_compile` validasi.
3. Direct Patch ke file aktif (backup di `~/.ai_staging/BACKUP/`).
4. Restart manager: `pkill -f proxy_manager.py` lalu `cd webui && nohup python3 proxy_manager.py --port 8766 > ../manager.log 2>&1 &`
5. Trigger grab: `curl -s -X POST http://127.0.0.1:8766/api/grab/start`
6. Verifikasi live: tunggu `[LIVE]` di run.log, cek `{ptype}.txt` timestamp update sebelum run selesai.

## Pitfall
- JANGAN timpa `proxies_out/*.txt` dengan dummy saat test — restore dulu sebelum test, atau test pakai OUT_DIR temp.
- `pkill -f proxy_grabber` bisa ikut kill shell sendiri (pattern match) — pakai PID spesifik.
- Termux shell = `sh`, `<(...)` process substitution TIDAK didukung.
- Grab massal (100k+ kandidat) bikin CPU 99% → tool shell bisa timeout 60s; pakai `ps`/`tail` singkat.