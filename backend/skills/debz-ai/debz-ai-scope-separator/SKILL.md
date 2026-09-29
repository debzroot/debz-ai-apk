# Scope-Aware Active Provider — Debz AI (CLI vs WebUI beda aktif)

## Kapan dipakai
Saat user minta "sinkron proxy & daftar provider tapi **pilihan provider aktif beda** antara
CLI (debz-term.py) dan WebUI (agent.php/api.php/providers.php)".

## Akar masalah
`.ai-providers.json` cuma punya **satu** field `active`. Dua sisi (CLI py via
`_set_active_provider`/`_read_providers`, WebUI php via `providers_load`/`providers_mask`/
`api.php activate`) baca & tulis field yg sama → saling nimpa.

## Solusi (terbukti, minim-patch)
Tambahkan **2 scope field** di `.ai-providers.json` (bukan file terpisah — proxy & daftar
provider tetap sinkron global):
- `active_cli`   → dibaca/ditulis CLI (`debz-term.py`)
- `active_webui` → dibaca/ditulis WebUI (`api.php` + `providers.php`)

`active` global tetap sebagai **default/fallback** (backfill kalau scope field kosong/ilang).

## Titik patch (versi file per 20260912 — sesuaikan dgn current)
- `providers.php`:
  - `providers_defaults()`: tambah `'active_cli' => 'debz', 'active_webui' => 'debz',`
  - `providers_load()`: backfill scope dari `active` (helper `providers_scope_defaults`)
    → jangan simpan ke file (runtime-only, ini yang bikin CLI & WebUI bisa baca beda
    TANPA nimpa file asli).
  - `providers_mask()`: output tambah `active_cli` & `active_webui` (buat JS liat dua-duanya).
- `api.php`:
  - Pilih provider default (non-explicit, non-rr): baca `active_webui` bukan `active`.
  - POST op=activate: tulis `active_webui` (scope WebUI) bukan `active` global.
- `debz-term.py`:
  - `_read_providers()`: `a = d.get("active_cli") or d.get("active","")`.
  - `_set_active_provider()`: tulis `d["active_cli"] = prov_id` (BUKAN `d["active"]`).

## Aturan eksekusi
1. **Backup dulu** semua file ke `~/.ai_staging/BACKUP/` (copy, jangan .bak di repo).
2. **Deterministik patch**: pakai Python `str.replace`/regex + `assert match`, tulis ke
   `.tmp`, `php -l`/`php -l .tmp` gate syntax, baru `os.replace` final. JANGAN sed -i blind.
3. Selalu `php -l` + `python3 -c "import ast; ast.parse(...)"` setelah.
4. Jangan sentuh `routing`/roundrobin/failover — itu tetap global.
5. `.ai-providers.json` **bukan** bagian dari repo rules (sensitive), jangan di-patch blak-blakan;
   kalau perlu test, copy ke /tmp & jangan restore setengah.

## Verifikasi
- `grep -n "active_cli\|active_webui" providers.php api.php debz-term.py`
- Cek file json: `active` global tetap ada; `active_cli` & `active_webui` beda → dua scope jalan.
- Test: activasi provider di WebUI → `active_webui` berubah, `active_cli` TETAP (bukti terpisah).
