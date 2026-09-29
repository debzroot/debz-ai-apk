---
name: skills-hermes-to-debz-ai-migration
description: "SOP migrasi branding/path Hermes Agent -> Debz AI di seluruh skills/ (frontmatter, CLI, env vars, path runtime, metadata internal)."
version: 1.0.0
author: Debz AI
license: MIT
platforms: [android, linux]
metadata:
  debz_ai:
    tags: [debz-ai, migration, rebrand, hermes, skills, sop]
---

# Migrasi Hermes Agent → Debz AI (skills/)

## Kapan dipakai
- Ada sisa referensi "Hermes"/"hermes"/"HERMES" di `~/debz-ai/skills/`.
- Skill baru diimpor dari repo Hermes/Claude/Codex dan perlu di-rebrand.

## Mapping wajib (single source of truth)
| Dari | Ke |
|---|---|
| `Hermes Agent` / `Hermes` | `Debz AI` |
| `author: Hermes Agent` | `author: Debz AI` |
| `metadata.hermes:` | `metadata.debz_ai:` |
| `hermes-agent` (skill ref) | `debz-ai-agent` |
| `~/.hermes` / `HERMES_HOME` | `~/debz-ai` / `DEBZ_AI_HOME` |
| `hermes_constants` / `_hermes_home` | `debz_ai_constants` / `_debz_ai_home` |
| CLI `hermes <cmd>` | `debz <cmd>` |
| `HERMES_*` env vars | `DEBZ_AI_*` |
| `.hermes/plans/` | `.debz_ai/plans/` (atau `~/debz-ai/plans/`) |
| Binary path `~/.local/bin/hermes` | `~/debz-ai/debz-term` |

## Yang TIDAK boleh diubah
- `LICENSE` copyright (legal — tetap atribusi asli).
- Kutipan/review pihak ketiga yang menyebut Hermes (fakta historis).
- Palette `PAL_*`, konstanta teknis non-branding.

## Prosedur
1. Backup: `cp -r ~/debz-ai/skills ~/.ai_staging/BACKUP/skills_backup_$(date +%Y%m%d_%H%M%S)`
2. Frontmatter: `find . -name "SKILL.md" -exec sed -i 's/^author: Hermes Agent$/author: Debz AI/; s/^  hermes:$/  debz_ai:/' {} +`
3. Rename folder skill: `mv .../hermes-agent .../debz-ai-agent` (+ headless-autostart, messaging-gateway, skill-authoring, inspecting-*-dom)
4. Sed massal (urutan: spesifik → generic):
   - env vars `HERMES_*` → `DEBZ_AI_*`
   - path `~/.hermes` → `~/debz-ai`, `HERMES_HOME` → `DEBZ_AI_HOME`
   - CLI `hermes <subcmd>` → `debz <subcmd>` (daftar lengkap: gateway, webhook, skills, config, login, logout, doctor, status, model, fallback, moa, memory, mcp, logs, kanban, hooks, desktop, dashboard, cron, completion, bundles, auth, acp, sessions, send, secrets, proxy, project, profile, portal, pets, skin, pairing, --tui, --resume, --continue, -w)
   - `@hermes/plugin-sdk` → `@debz-ai/plugin-sdk`
   - `PAL_HERMES` → `PAL_DEBZ` (jika ada)
5. Rename file `_hermes_home.py` → `_debz_ai_home.py` + update import.
6. Metadata internal: `.usage.json`, `.bundled_manifest` (rename keys), `.curator_state` (path).
7. Validasi:
   - `grep -ril hermes . | wc -l` → 0
   - `find . -iname '*hermes*' | wc -l` → 0
   - `python3 -m py_compile` semua .py → exit 0
   - `bash -n` semua .sh → exit 0
   - `python3 -c "import json; json.load(open('.usage.json'))"` → VALID