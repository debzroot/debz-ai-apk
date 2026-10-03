---
name: llm-rate-limit-smart-retry
description: "Smart retry LLM API untuk 429 dan 503 dengan backoff adaptif."
version: "1.0.0"
---

# SKILL: Smart Rate Limit & Retry untuk LLM API (429/503)

## Kapan dipakai
Agent/CLI yang manggil LLM API kena HTTP 429 ("Maximum 8 requests within 1 minutes") atau 503 gateway overload, dan retry-nya masih buta (cooldown fixed, gak belajar dari error).

## Pola solusi 3 lapis
1. **Parse error** → `OverloadedError(msg, retry_after, status)` bawa `Retry-After` header (detik atau HTTP-date) + status code.
2. **Belajar dari pesan 429** → regex `maximum (\d+) requests? within (\d+) (seconds?|minutes?|hours?)` → simpan `{limit, window}` per key `provider:model`, persist ke JSON (mis. `.ai-ratelimits.json`) biar nyambung antar sesi.
3. **Preventif** → sebelum kirim request, sliding-window tap: kalau tap dalam window >= limit, TUNGGU dulu (bukan nembak lalu kena 429). Cooldown reaktif: Retry-After > window dipelajari > backoff eksponensial `15*2^attempt + jitter` buat 5xx.

## Jebakan (dari kasus nyata debz-term.py + z-ai/glm-5.3-free)
- Cooldown fixed 300s itu overkill kalau limit cuma 8 req/menit — window 60s + buffer cukup.
- Jangan samakan 429 (rate limit, tunggu presisi) dengan 503 (gateway, backoff eksponensial + jitter anti-storm).
- Cap Retry-After (max 900s) — jangan percaya nilai gila-gilaan dari server.
- Regex di patch-script Python: `\\d` (double backslash) karena ditulis dalam string biasa, bukan `r""`.
- Test assertion backoff: hitung dulu formula (30+jitter2-8 → 32-38s), jangan ngasal.
- Import modul berat (rich/prompt_toolkit) buat unit test → stub permissive `types.ModuleType` dengan `__getattr__` dummy.

## Artefak kasus 2026-09-11
- Patch: `~/Workspaces/patches/patch_smart_ratelimit.py` (7 region, verifikasi count==1 per pattern)
- Test: `~/Workspaces/patches/test_smart_ratelimit.py` (9 test lulus)
- Fixed: `~/.ai_staging/BACKUP/debz-term.py` — Direct Patch: edit langsung `~/debz-ai/debz-term.py`
- File persist limit: `.ai-ratelimits.json` di project root (normal, bukan anomali — config runtime)
