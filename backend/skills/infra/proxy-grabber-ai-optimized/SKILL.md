---
name: proxy-grabber-ai-optimized
description: "Optimasi proxy grabber untuk bypass rate limit AI provider."
version: "1.0.0"
---

# Proxy Grabber v3.1 — AI Provider Optimized

Patch proxy_grabber.py supaya proxy yang dihasilkan beneran bisa dipake buat bypass rate limit AI provider (OpenRouter, OpenAI, dll).

## Masalah yang di-fix

1. **Validasi terlalu ringan** — cuma test ke gstatic.com/generate_204. Proxy yang lolos belum tentu bisa tunnel HTTPS ke domain AI provider.
2. **`grab()` nggak ada retry** — source fetch gagal (SSL/DNS di Termux) → pool kandidat kecil → hasil jelek.
3. **CURLOPT_CONNECTTIMEOUT terlalu lama** — 15s bikin user nunggu lama kalau proxy broken.
4. **`verify=True` di validation** — beberapa proxy MITM cert, harusnya yang penting tunnel works.

## Patch Summary

### proxy_grabber.py (v3.0 → v3.1)

| Change | Detail |
|---|---|
| Multi-endpoint HTTPS validation | Test ke `gstatic` + `api.ipify.org` + `httpbin.org/get` + `my-ip.io`. Proxy yang cuma bisa gstatic tapi nggak bisa HTTPS tunnel → **auto-reject** |
| `grab()` retry | SSL error / connection error → retry 1x dengan `verify=False` sebelum skip |
| `verify=False` di validation | Proxy MITM cert tidak masalah; yang penting tunnel works |
| `HTTPS_TUNNEL_URLS` config | 3 secondary HTTPS endpoints yang di-test setelah gstatic lolos |
| Timeout naik | `TEST_TIMEOUT` 8→12s, `GRAB_TIMEOUT` 20→30s (SOCKS lebih lambat) |
| Suppress InsecureRequestWarning | `warnings.filterwarnings` + `urllib3.disable_warnings` |

### agent.php

| Change | Detail |
|---|---|
| `CURLOPT_CONNECTTIMEOUT` 15→10 | Bad proxy fail lebih cepat, user nggak nunggu 15s percuma |

## File Locations

- **Original**: `~/.ai_staging/BACKUP/proxy_grabber.py.original`, `~/.ai_staging/BACKUP/agent.php.original`
- **Fixed**: `~/.ai_staging/BACKUP/proxy_grabber.py`
- **Applied**: `~/debz-ai/agent.php` (patched in-place, 1 line)

## Apply

```bash
cp ~/.ai_staging/BACKUP/proxy_grabber.py ~/Workspaces/proxy-grabber/proxy_grabber.py
# agent.php sudah di-patch langsung
```

## Validation Flow (v3.1)

```
Proxy → [1] gstatic 200 OK? → NO → REJECT
       → [2] HTTPS tunnel (ipify/httpbin/my-ip)? → NO → REJECT  
       → [3] AI probe (opencode.ai)? → NO → REJECT
       → PASS → Write to file + score
```

## Key Insight

Free public proxies yang bisa akses `gstatic.com` tapi nggak bisa tunnel HTTPS ke domain lain = **useless buat AI provider bypass**. Multi-endpoint validation adalah fix paling impact-ful.