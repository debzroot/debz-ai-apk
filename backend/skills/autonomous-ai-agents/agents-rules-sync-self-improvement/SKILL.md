---
name: agents-rules-sync-self-improvement
description: SOP sinkronisasi rules/memori AI agar single-source-of-truth dengan AGENTS.md aktif. Wajib dipakai saat ada perubahan aturan, patch SOP, atau update knowledge base.
category: autonomous-ai-agents
---

# Agents Rules Sync & Self-Improvement

## Prinsip Inti
- **Single source of truth**: `~/debz-ai/AGENTS.md` (aktif) + `notes.db` (rules) + `skills/` — TIDAK BOLEH ada paralel.
- **Dilarang** membuat rules/memory paralel yang bertentangan dengan AGENTS.md aktif.
- Setiap update SOP → sinkronkan ke 3 tempat: AGENTS.md, notes.db, skills.

## SOP Live Patch (Direct Patch — wajib, bukan FIXED)
1. **Backup original**: `cp <file> ~/.ai_staging/BACKUP/<nama-project>/` (jangan pernah buat `.bak` di `~/debz-ai/` atau `~/Workspaces/`).
2. **Direct Patch**: edit LANGSUNG file di lokasi aktif (`~/debz-ai/` atau `~/Workspaces/<project>/`).
3. **NO JUNK**: dilarang `sed -i.bak`, `tmp_*`, `session_*.json` di Workspaces/core AI.
4. **Tampilkan showdiff**: `diff -u <backup> <file-aktif>` di chat agar developer tahu perubahan.
5. **Rollback**: jika error, restore dari `~/.ai_staging/BACKUP/` ke lokasi aktif.

## Sinkronisasi Rules (notes.db)
- Update note `rules-core-ai` setiap kali AGENTS.md berubah.
- Hapus note lama yang bertentangan (mis. SOP FIXED/`~/Workspaces/BACKUP/`).
- Verifikasi: `note list` + `grep -rn "Workspaces/BACKUP\|Workspaces/FIXED" ~/debz-ai/skills/` harus bersih.

## Audit Berkala
- `grep -rn "Workspaces/BACKUP\|Workspaces/FIXED" ~/debz-ai --include="*.py" --include="*.js" --include="*.sh" --include="*.md"` → harus 0 hasil (kecuali dokumentasi arsip).
- Cek duplikat AGENTS.md di Workspaces → arsipkan ke `~/.ai_staging/ARCHIVE/`.
- Cek file `.bak/.orig/session_*` di Workspaces → pindah ke `~/.ai_staging/ARCHIVE/`.