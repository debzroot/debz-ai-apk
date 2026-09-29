# Skill: Fix SSL EOF (unexpected eof) → Auto-Ganti Proxy (WebUI + CLI)

## Gejala
- WebUI (`agent.php`) atau CLI (`debz-term.py`) error berulang:
  `cURL error: OpenSSL SSL_read: OpenSSL/3.6.3: error:0A000126:SSL routines::unexpected eof while reading, errno 0`
- Error muncul terus-menerus, tidak pernah pindah proxy / tidak pernah sukses.
- Log `logs/app-*.log` penuh `[PROXY] failover` atau `[AGENT] provider error` dengan isi SSL EOF.

## Akar Masalah (paling umum)
Mode proxy `direct` + SSL EOF:
- `$isSSLError = true` tapi `$shouldRotate` butuh `_debz_last_proxy` TIDAK kosong.
- Saat direct, `_debz_last_proxy = ''` → `$shouldRotate = false` → proxy tidak pernah dicoba.
- Retry direct terus → SSL error lagi → sampai maxRetry → error tampil ke user.

## Fix (v3.6) — Sinkron WebUI + CLI
1. **agent.php** `debz_proxy_apply()`: hormati `$opts['_forceProxy']` — kalau set, LEWATI cek mode direct/auto (paksa pakai proxy).
2. **agent.php** `native_chat_once()`: deteksi `$isSSLError && empty(_debz_last_proxy) && empty(opts['_sslProxyTried'])` → set `_forceProxy=true`, `_useProxy=true`, `_proxyFail=true`, `continue`.
3. **debz-term.py** `_proxy_apply()`: tambah param `force_proxy=False`; `if not force_proxy:` baru cek mode.
4. **debz-term.py** `Agent.__init__`: tambah `self._force_proxy = False`.
5. **debz-term.py** `_llm_request()`: pass `force_proxy`; tambah `except requests.exceptions.SSLError` — proxy → rotate; direct → `_force_proxy=True` + retry via proxy.

## Verifikasi
- `php -l agent.php` + `python3 -m py_compile debz-term.py` → OK.
- Test `_proxy_apply` CLI: direct+no-force → `None`; direct+force_proxy → dict proxy.
- Mock `requests.post` throw `SSLError` saat `proxies=None` → post#2 harus `proxies={'http': 'http://...'}`.
- Reload php-fpm setelah replace (`kill -USR2 <master_pid>`), cek `php -r 'require "agent.php";'` OK.

## Catatan
- Backup dulu ke `~/.ai_staging/BACKUP/`, Direct Patch ke file aktif (rules AGENTS.md).
- SSL EOF juga bisa terjadi karena proxy mati → setelah lewat proxy, kalau SSL lagi → `$shouldRotate` (proxy) yang rotate ke proxy baru.
- Jangan disable SSL verify permanen — fallback `_sslFallback` hanya sekali (guard `_sslRetried`).