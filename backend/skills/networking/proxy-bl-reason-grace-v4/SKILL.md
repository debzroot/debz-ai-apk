# Proxy Grabber v4.0 — BL per-Reason + Grace Period + Multi-Endpoint Probe

## Root Cause Pool Tiris (429 beruntun)
1. **BL 24 jam flat** (patch v3.4) membuang proxy TERBAIK: 109 entry semua `fails=1`, proxy yang sudah lulus validasi HTTPS langsung dikucilkan 24 jam cuma karena kena 429 sekali. IP publik yang 429 biasanya cuma "panas" 15-30 menit.
2. **Probe single-endpoint**: proxy HIDUP tapi kena 429/403 dari endpoint AI → dianggap gagal → tidak masuk list. IP proxy publik dipakai ribuan orang, rate-limit per-endpoint itu normal.
3. **Known-good drop instan**: gagal re-test 1x = drop dari list. Fluktuasi jaringan → proxy sehat dibuang.

## Fix v4.0 (semua file, konsisten 3 entry point)
### Tier BL per-reason (agent.php `debz_proxy_bl_tier_for()`, debz-term.py `_proxy_bl_tier()`, manager `_bl_tier()`)
| reason | fails=1 | 2 | 3+ | 5+ |
|---|---|---|---|---|
| `rl` (429) | 15m | 30m | 1h | 24h |
| other (SSL/connect/timeout) | 15m | 30m | 2h | 24h |

- Entry BL format baru: `{"ts", "fails", "reason"}` — reason disimpan saat add, dipakai saat read tier.
- agent.php: `$GLOBALS['debz_bl_reason']` di-set `debz_proxy_failover($proxy, 'rl')` saat 429 sebelum `blacklist_add`.
- debz-term.py: `_proxy_failover(proxy, is_rate_limit=...)` sudah ada param — tinggal pakai.
- Manager: `_bl_add(proxy, reason='')` — semua call site pakai reason.

### Multi-endpoint probe (proxy_grabber.py `ai_probe` + `_probe_fallback`)
- Jika AI probe balas 429/403 → coba `https://www.cloudflare.com/cdn-cgi/trace` → `https://example.com` (keduanya GET 200 = tunnel sehat).
- Proxy yang hanya di-limit endpoint AI tapi tunnel-nya hidup tetap masuk list.

### Grace period known-good (`validate_one` + `_kg_fail` dict)
- Known-good gagal re-test 1x → ditahan 1 run (latency 99999, tetap di list). Gagal 2x → drop.
- `_kg_fail.clear()` di awal `validate()` tiap run.

### Cap supply
- `MAX_CANDIDATES = {"http": 4000, "socks4": 2500, "socks5": 2500}` (9000/run).

## Migrasi BL lama (1x saja)
Entry tanpa `reason` → set `reason='rl'` → tier jadi 15m → entry tua expired otomatis, proxy bagus balik ke sirkulasi. Script singkat json load → set → dump.

## Verifikasi
- `php -l agent.php`, `py_compile` semua file.
- Tier test konsisten: rl(1)=15m/rl(3)=60m/other(3)=2h/other(5)=1d.
- Manager restart → blacklist aktif turun 109 → 0 (migrasi + expiry otomatis), grabber auto-restart.

## Key Lesson
- 429 ≠ proxy mati. Diferensiasi reason sebelum blacklist, kalau tidak pool jadi tiris karena proxy bagus dibuang percuma.
- Probe multi-endpoint penting untuk proxy publik: IP satu bisa di-limit di satu provider tapi sehat di tempat lain.
