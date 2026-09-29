# debz-ai-apk

Hybrid Android APK buat **debz-ai**: cangkang native (WebView + terminal + service)
+ backend di **proot-mini** + update kode via **OTA app-layer** (tanpa rebuild APK).

Status: **0.2.0-run** — install langsung jalan offline. Rootfs-mini
(~130MB) dibundle di APK assets, backend debz-ai + provider opencode-cli
(tanpa API key) kebake di rootfs. Pertama buka: ekstrak otomatis →
stack up (php-fpm + nginx + opencode serve) → WebView ke backend lokal.

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

CI (`.github/workflows/build.yml`): job `rootfs` → job `android` (needs).
Artefak ada di tab Actions → run → Artifacts.

### Wajib: secret OPENCODE_SESSION_ID

Provider opencode-cli otentikasi via header `x-session-id` (tanpa API key).
Repo menyimpan placeholder `__OPENCODE_SESSION_ID__`, CI inject dari secret:

1. Salin `x-session-id` dari `.ai-providers.json` di mesin dev.
2. GitHub repo → Settings → Secrets → Actions → New secret
   `OPENCODE_SESSION_ID` = nilainya.
3. Tanpa secret: APK tetap kebuild, tapi chat 401.

### Alur first-run di HP

1. Install APK → buka → `BootstrapService` ekstrak rootfs dari assets
   (tanpa download, tanpa token) → status `extract-rootfs`.
2. `StackSupervisor` jalanin `first-boot-pip.sh` (bootstrap pip +
   install wheels offline) → `start-stack.sh`
   (php-fpm + nginx serve `/opt/debz/app` + opencode serve di port API).
3. WebView load backend lokal → login password `1337` → chat langsung jalan.
