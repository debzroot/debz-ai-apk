# Skill: Audit & Fix Proxy Pipeline (grabber → list → webui → agent)

## Kapan dipakai
- Proxy grabber hasil validasi gak work buat komunikasi AI agent (curl error, CONNECT failed)
- List proxy menyusut drastis tiap run baru
- Agent muter-muter proxy mati yang sama
- Webui tampil semua proxy merah (ok:0 semua)

## Pola bug yang sering muncul (checklist audit)
1. **Sukses gak dicatat** — grep semua pemanggilan `score_record|_score_record`: kalau cuma ada `(false)` tanpa `(true)`, reputasi gak pernah kebentuk → sort-by-score buta. Fix: catat sukses di titik setelah request sukses (curl OK + httpCode < 400).
2. **List di-clobber tiap run** — kalau `validate()` bersihkan `_results` lalu file output ditimpa hasil run baru → list menyusut. Fix: merge known-good < 4 jam (seed `_results` + `_seen` dari file lama sebelum validasi, skip proxy yang udah ada di pool).
3. **Run kegedean** — MAX_CANDIDATES × 3 type vs throughput nyata (workers × timeout). 20000×3=60k job ≈ 35 jam. Fix: 1500×3=4500 job ≈ 12 menit, pas dengan auto_interval.
4. **Known-good gak diprioritaskan beneran** — `set()` order gak menjamin urutan eksekusi. Fix: helper `_order_pool()` return list `kg + rest`, jobs dibangun dari list ordered.
5. **CLI vs WebUI logika beda** — CLI `_proxy_pick` random tanpa sort skor; webui sort skor + health check. Fix: align (sort skor + tried-exclusion + blacklist transport error, bukan cuma 429).
6. **Blacklist cuma 429** — transport error (proxy mati/SSL/reset) gak di-blacklist → proxy mati dipilih ulang terus. Fix: semua kegagalan proxy → blacklist tiered.
7. **Pidfile stale** — grabber mati kagkill tapi pidfile nyisa → run baru langsung exit "[LOCK]". Fix: rm grabber.pid sebelum restart.

## Prosedur verifikasi (tanpa nyemak API asli)
- Import grabber via importlib + stub `requests` (perlu rantai `requests.packages.urllib3.exceptions.InsecureRequestWarning` = subclass Warning)
- Sandbox OUT_DIR pakai fake Path class (str subclass dengan `__truediv__`, `exists()`, `stat()`, `read_text()`)
- Stub ThreadPoolExecutor + as_completed (generator sederhana) buat capture job ordering
- Test agent.php funcs via `php -r` + extract fungsi via regex `function NAME\(.*?\n\}` — override fungsi path-file di command line, JANGAN pakai /tmp di Termux (gak writable dari PHP), pakai getenv('HOME')
- Verifikasi production gak terkontaminasi: cek scores.json sebelum/sesudah test

## Metrik sukses
- Run kelar < 15 menit (bukan 35 jam)
- http.txt stabil/growing antar run (merge known-good)
- scores.json ada entry ok>0 (reputasi kebentuk)
- Sticky proxy dipertahankan selama sukses, rotasi HANYA saat: cURL error, timeout, 407, 429, connection reset. Provider 5xx → tetap proxy sama (bukan salah proxy)

## File terkait
- `~/debz-ai/agent.php` (webui agent) — debz_proxy_pick v4, score_record, sticky_ok
- `~/debz-ai/proxy-grabber/proxy_grabber.py` — grab + validate + merge known-good
- `~/debz-ai/debz-term.py` (CLI) — _proxy_pick/_proxy_failover sinkron webui
- Output: `proxies_out/{http,socks4,socks5}.txt` + `.json` summary (dibaca webui port 8766)
