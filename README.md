# debz-ai-apk

Hybrid Android APK buat **debz-ai**: cangkang native (WebView + terminal + service)
+ backend di **proot-mini** + update kode via **OTA app-layer** (tanpa rebuild APK).

Status: **scaffold** — struktur kompilabel, modul inti nyata
(`PortManager`, `RootDetector`), sisanya stub bertahap.

## Arsitektur

- `app/` native: `MainActivity` (WebView → backend lokal),
  `TerminalActivity` (shell → nanti proot session + `debz-term`),
  `BootstrapService` (foreground service, jaga backend tetap hidup),
  `PortManager` (anti-bentrok port, auto free-port + offset profil),
  `RootDetector` (mode root / non-root), `OtaManager` (stub update bundle).
- Backend (menyusul): proot-mini (opencode + php-fpm + nginx, slim ~300-400MB,
  download saat install pertama) + Python backend native.
- OTA (menyusul): bundle versioned (`debz-term.py`, `index.php`, `agent.php`, …)
  dari GitHub Releases, verifikasi SHA256, swap atomik + rollback.

## Build lokal / CI

CI (`.github/workflows/android.yml`) build `app-debug.apk` otomatis tiap push.
Artefak ada di tab Actions → run → Artifacts.
