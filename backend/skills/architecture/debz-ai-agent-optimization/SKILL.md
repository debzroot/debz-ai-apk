---
name: debz-ai-agent-optimization
description: SOP optimasi framework agent PHP di ~/debz-ai (context cap, memory offloading, dynamic prompting DB-driven, circuit breaker, plan-execute-review multi-pass, dashboard stats). Dipakai saat menyempurnakan agent.php.
tags: [agent, php, llm, architecture, optimization, prompt-manager, stats]
---

# Optimasi Agent Framework (~/debz-ai/agent.php)

## Prinsip
- Jangan paksa model mengingat semua log di context window → offload ke notes.db / RAG.
- Pecah beban kognitif: Planner (temp 0.1) → Worker (loop tool) → Verifier (temp 0.0).
- Failsafe: circuit breaker 3-strikes per tool+args, auto-expire 1 jam.
- Dynamic prompting: sisipkan aturan PERSIS sebelum pesan user terbaru (recency bias), rotasi via DB.

## Konfigurasi (.ai-config.ini)
```
AI_CONTEXT_CAP=8           # max interaksi di context (system prompt selalu dipertahankan)
AI_STRIKE_LIMIT=3          # three-strikes rule
AI_PLANNER=1               # planner pre-flight
AI_PLANNER_RETRY=1
AI_VERIFIER=1              # verifier post-flight
AI_VERIFIER_RETRY=1
AI_VERIFIER_MAX_PASS=3     # batas multi-pass verifier (anti infinite loop)
AI_STATS=1                 # dashboard monitoring (.stats.json)
AI_PROMPT_MANAGER=1        # Fase 4: PromptManager DB-driven
AI_PROMPT_BUDGET=600       # budget token injeksi rules
```

## Fungsi Kunci di agent.php
- `native_context_cap(array $messages): array` — potong history, simpan system prompt + tail, offload ringkasan ke notes.db (`native_summarize_dropped`).
- `native_dynamic_rules(string $userText): string` — PromptManager: select rules relevan dari tabel `prompt_rules` (notes.db), sisipkan sebelum user msg terakhir.
- `native_prompt_rules_ensure/select/touch` — seed 6 rules (zero_fluff, ecosystem_best_practice, structure, critical_review, memory_offload, tool_honesty); rotasi: pakai → priority -5 & use_count+1; >24 jam idle → priority +5.
- `native_strike_key/check/track/reset` — circuit breaker, key = `tool:sha256(args)`, state di `.strikes.json`.
- `native_planner_plan(...)` — pre-flight, temp 0.1, hasil disisipkan sebagai system message.
- `native_verifier_check(...)` — post-flight, temp 0.0, fail-open (return '' = aman), multi-pass max `AI_VERIFIER_MAX_PASS`.
- `native_stats_record/summary` — event planner/verifier/strike/prompt/token ke `.stats.json` (max 2000 event).

## Aturan Patch
1. Backup dulu: `mkdir -p ~/.ai_staging/BACKUP/debz_ai && cp agent.php ~/.ai_staging/BACKUP/debz_ai/agent.php.<fase>.bak`
2. Edit LANGSUNG di `~/debz-ai/agent.php` (dilarang folder FIXED, dilarang .bak di Workspaces).
3. Validasi: `php -l agent.php` + unit test fungsi baru.
4. PHP-FPM load per-request → tidak perlu restart service.
5. Verifier WAJIB fail-open: jika baseUrl/model kosong, return '' (jangan blokir worker).
6. Seed prompt_rules: array WAJIB 4 elemen `[rule_id, category, content, priority]` — kalau cuma 3, `execute()` error `Undefined array key 3` dan tabel gagal di-seed (gejala: prompt_skip terus).

## Test Cepat
- Context cap: panggil `native_context_cap()` dengan 20 pesan → harus jadi 8 + ringkasan di system prompt.
- Strike: panggil tool gagal 3x → pesan blokir muncul, reset setelah sukses.
- PromptManager: `native_prompt_rules_ensure()` → 6 baris; `native_dynamic_rules("buatkan script python...")` → block ter-inject + stats `prompt_inject` naik.
- E2E: jalankan agent di background (`nohup php ... > /tmp/test.log &`) karena shell tool timeout 60s; poll dengan `tail`.