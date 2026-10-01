# DebzAI — Asisten AI Mobile untuk Android (Hybrid APK)

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Platform: Android](https://img.shields.io/badge/Platform-Android-brightgreen.svg)](app/)
[![Backend: proot-mini](https://img.shields.io/badge/Backend-proot--mini-blue.svg)](rootfs/)

**DebzAI** adalah asisten AI mobile opensource khusus Android: APK native ringan yang membawa
agent AI langsung di HP — bisa ngoding, eksekusi tool, otomatisasi, dan akses sistem —
tanpa perlu VPS atau API key mahal.

> Install → buka → langsung chat. Rootfs-mini (~130MB) terbundle di APK, backend
> (php-fpm + nginx + opencode serve) jalan lokal di HP via proot.

---

## Kegunaan APK ini

- **Asisten ngoding di HP**: baca/tulis/edit file, cari kode, jalankan shell, git, build.
- **Agent otonom mobile**: tool calling (shell, file, HTTP, SQLite, arsip, proses,
  scheduler, backup), browser automation (CDP), kendali layar virtual (CUA).
- **Akses sistem Android**: via Android Bridge (root) — `pm`, `dumpsys`, `settings`,
  `input tap`, dsb. — plus WebView, terminal, dan foreground service agar backend tetap hidup.
- **Hemat & stabil**: provider opencode-cli tanpa API key (session per-device),
  routing multi-provider dengan **round-robin + failover ala 9Router**,
  proxy pool + blacklist otomatis.
- **Update gampang**: tiap ada `ROOTFS_EPOCH` baru, app wipe + extract ulang otomatis.
  Update kode app-layer via OTA dari GitHub Releases (`ci-latest`).

## Fitur utama

- **Hybrid engine**
  - `opencode` binary (`sst/opencode`, ARM64) — `run` / `serve`, session, tool use.
  - Native PHP agent (`backend/agent.php`, `debz-term.py`) — planner → worker → verifier,
    circuit breaker, prompt manager DB-driven, stats dashboard.
- **Round-robin + failover seperti 9Router**
  - Provider chain + proxy pool (`proxy-grabber/`), sticky proxy, health-check,
    failover saat 5xx / timeout / limit, blacklist sementara + auto-expire.
- **Tools lengkap** (22 via `backend.py`): exec, fs read/write/list/search, http,
  download, db, archive, ps/kill, skill, note (memori `notes.db`), pkg, web_search,
  backup, scheduler (cron), computer_use, browser, screenshot, rag.
- **AllowAll yang beneran auto**: toggle sidebar persist (`.approval_always`),
  Tools ON default + persist `localStorage`, `--auto` ikut ON saat AllowAll aktif.
  Tidak ada lagi approval hidden yang bikin agent diam.
- **Agent cepat paham**: `backend/AGENTS.md` canonical + auto-seed `notes.db`
  (`bootstrap`, `map-apk`, `rules-core-ai`, `tools-cheatsheet`) — agent langsung
  tahu peta tool tanpa `glob`/`grep` berulang.
- **Native Android**: WebView + Terminal + `BootstrapService`, `PortManager`
  (anti-bentrok port), `RootDetector`, `OtaManager` (poll `ci-latest`), `RootfsManager`
  (`ROOTFS_EPOCH`), `BridgeServer` (eksekusi root).

## Arsitektur singkat

```text
app/ (native: MainActivity, TerminalActivity, BootstrapService,
      PortManager, RootDetector, OtaManager, RootfsManager, BridgeServer)
backend/ (php-fpm + nginx serve /opt/debz/app: index.php WebUI,
      agent.php, api.php, providers.php, debz-term.py,
      backend.py :tools, debz_tools_mcp.py, cua/browser drivers,
      skills/*/SKILL.md, AGENTS.md, notes.db auto-seed)
rootfs/ (build-rootfs.sh: ubuntu-base ARM64 + php + nginx + python wheels
      + opencode binary + payload /opt/debz → rootfs-mini.tar.gz)
```

- `backend/` di-copy ke `$ROOTFS/opt/debz/app` saat build (lihat `rootfs/build-rootfs.sh` langkah 6).
- `opencode` config isolasi di `backend/opencode-bin/.cfg_home` (jangan dihapus).
- Port dinamis via `PortManager`, tercatat di `.ports.json` (jangan asumsi `9191`/`8091` tetap).

## Cara pakai

1. Install APK dari [Releases](https://github.com/debzroot/debz-ai-apk/releases/tag/ci-latest) (`ci-latest` = build terbaru `main`).
2. Buka app → rootfs extract otomatis → stack up (php-fpm + nginx + opencode serve).
3. Login WebUI password `1337` → chat langsung jalan.
4. Sidebar: **Tools ON** (agent bisa shell/file/search) + **AllowAll ON** (tanpa approval).

## Build & CI

- CI (`.github/workflows/build.yml`): job `rootfs` → job `android` (needs).
  Tiap push `main` → build rootfs + APK → upload ke Release rolling `ci-latest`.
- Alur dev: **HP dulu, GH ngikut** — oprek + verifikasi di rootfs HP,
  yang sudah terbukti baru di-commit/push. Naikkan `ROOTFS_EPOCH`
  (`app/.../RootfsManager.java`) tiap ada perubahan backend tak-kompatibel.

```sh
# build rootfs lokal
./rootfs/build-rootfs.sh
# APK via Android Studio / gradle
./gradlew assembleRelease
```

## Terinspirasi oleh

- [opencode](https://github.com/sst/opencode) — AI coding agent untuk terminal.
- [9Router](https://github.com/decolua/9router) — smart router multi-provider dengan round-robin + auto-fallback.
- [Hermes Agent](https://github.com/NousResearch/hermes-agent) — autonomous AI agent dengan persistent memory oleh Nous Research.

## Dukung developer

Kalau APK ini ngebantu kerja mobile kamu, traktir kopi biar lanjut stabil:

**[Donate via Saweria — https://saweria.co/debzroot](https://saweria.co/debzroot)**

## Lisensi

[MIT License](LICENSE) — bebas pakai, ubah, dan distribusi. Lihat file `LICENSE`.
