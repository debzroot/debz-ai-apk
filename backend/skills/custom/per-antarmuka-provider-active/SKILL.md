---
name: per-antarmuka-provider-active
description: "Sinkron multi-UI provider aktif per-antarmuka CLI vs WebUI tanpa saling geser."
version: "1.0.0"
---

# Per-Antarmuka Active Provider (Multi-UI Sync)

## Konteks
Di sistem AI server lokal (`~/debz-ai/`), satu file config `.ai-providers.json` dibaca IRTUA-dua: **WebUI** (`agent.php` → `api.php`/`providers.php`) dan **CLI** (`debz-term.py`). Dulu cuma ada satu field `active` global → ganti provider di satu sisi bikin sisi lain kegeser.

## Tujuan user
Proxy + daftar provider tetap sinkron global, tapi **provider aktif bisa beda per antarmuka** (mis. WebUI = GLM, CLI = big-pickle).

## Pola solusi (scope-aware)
1. Tambah 2 field di config: `active_cli` & `active_webui` (satu file sama = daftar provider & proxy tetap sinkron).
2. `active` tetap jadi **default/fallback** (file lama tanpa scope <=> kompatibel, `providers_scope_defaults()` nge-backfill ke `active` kalau scope kosong/ilang).
3. Tiap sisi baca/tulis scope sendiri:
   - CLI (`debz-term.py`): `_read_providers` baca `d.get("active_cli") or d.get("active","")`; `_set_active_provider` nulis `d["active_cli"]`.
   - WebUI (`api.php` line ~50 & ~129): baca/`OP activate` tulis `active_webui`.
   - `providers.php`: `providers_scope_defaults($d)` + `providers_mask()` mengirim BOTH scope ke browser.

## Checklist hardening (jangan diulang!)
- ROUTING/round-robin/failover chain TIDAK disentuh — hanya jalur fixed/default yang di-scope.
- Selalu backup asli ke `~/.ai_staging/BACKUP/` + syntax-check (`php -l`, py_compile) sebelum & sesudah.
- Hati2: `providers_mask` fatal kalau manggil fungsi yang gak ada (`data_scopes`). Panggil helper yang bener: `providers_scope_defaults($data)['active_...']`.
- Patch **deterministik**: dan TIDAK pakai heredoc dengan escape yang bisa bikin string rusak; tulis `.tmp` dulu, `assert pattern in s`, baru `mv`.
- Beda test: simulasi file dengan `active_cli != active_webui` → assert CLI & WebUI dapat id beda namun list provider & proxy sama.

## Catatan
- Kalau user mau 1 provider di dua sisi: set `active_webui` via Settings WebUI, `active_cli` via menu CLI.
- Jangan restore `active_cli`/`active_webui` dengan file lama (bakal ketimpa value → state pindah global lagi).
