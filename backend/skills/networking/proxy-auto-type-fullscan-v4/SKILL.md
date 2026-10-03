---
name: proxy-auto-type-fullscan-v4
description: "Auto-type proxy dengan full-scan routing agent dan WebUI."
version: "1.0.0"
---

# Proxy Auto-Type + Full-Scan (v4.1)

## Konteks
Sistem proxy grabber Debz AI (`~/debz-ai/proxy-grabber/`) + routing di agent.php / debz-term.py / proxy_manager.py (webui).

## Fitur v4.1
1. **Type='auto'** — pilih tipe (http/socks4/socks5) dengan pool non-blacklist terbanyak.
   - Implementasi: `_resolve_type()` (proxy_manager.py), `debz_proxy_auto_type()` (agent.php), `_proxy_auto_type()` (debz-term.py).
   - Semua entry point resolve `auto` → tipe terbaik SEBELUM pick.
   - UI: `selType` punya opsi `auto` (index.html).
2. **Full-scan tanpa cap** — semua kandidat source di-test (304k proxy/run), bukan cap 8000.
   - Blok `[CAP]` dihapus dari run_once; diganti `[FULL]`.
   - Known-good tetap diuji pertama via `_order_pool()`.
3. **Dedup antar-run** — `_write_type()` pakai `atomic_write` (overwrite total, bukan append) + `_seen` dedup per-run. Proxy yang gagal re-test otomatis hilang; yang lolos tetap. Tidak ada duplikat.

## Entry point pick proxy (wajib sinkron saat patch)
- `agent.php`: `debz_proxy_pick()` + `debz_proxy_apply()` — resolve auto via `debz_proxy_auto_type()`.
- `debz-term.py`: `_proxy_pick()` + `_proxy_apply()` — resolve auto via `_proxy_auto_type()`.
- `proxy_manager.py`: `api_next()` + `api_current()` — resolve auto via `_resolve_type()`.

## Blacklist tier (v4.0, konsisten 3 entry point)
- reason='rl' (429): 15m/30m/1h/24h (fails 1/2/3/5+)
- reason lain (mati/SSL): 15m/30m/2h/24h
- `_bl_active()` di grabber: 86400 flat (filter saat parse_proxies).

## Validasi grabber (v3.7+)
- Single-stage: `ai_probe()` langsung (verify=True) → fallback Cloudflare trace → example.com.
- Timeout `(3,4)` connect/read, MAX_WORKERS=64, GRAB_WORKERS=16.
- Grace period known-good: gagal re-test 1x ditahan 1 run (`_kg_fail`), drop setelah 2x.

## Loop
- Grabber `main()`: setelah 100% → sleep 5s → langsung run berikutnya (bukan interval menit).
- Manager scheduler: restart 5s setelah run selesai (safety net).

## Catatan
- Proxy HTTP publik mayoritas HTTP-only (tidak support CONNECT HTTPS) — validasi AI probe (HTTPS) yang benar-benar relevan untuk chat AI.
- Full-scan 304k proxy butuh ~30-90 menit di Termux; progress bar webui baca regex `progress\s+(\d+)/(\d+).*?ok=(\d+)`.