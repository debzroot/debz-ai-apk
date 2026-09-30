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

### Session per-device (opsional: secret OPENCODE_SESSION_ID)

Provider opencode-cli otentikasi via header `x-session-id` (tanpa API key).
Repo menyimpan placeholder `__OPENCODE_SESSION_ID__`. Saat backend pertama
jalan di HP, `providers_autofix_sid()` generate UUID v4 unik per-device +
simpan ke `.ai-providers.json` — jadi tiap user otomatis dapat session sendiri,
anti 401 massal, siap install banyak user.

Secret `OPENCODE_SESSION_ID` (repo Settings → Secrets → Actions) sifatnya
OPSIONAL: kalau diisi, CI inject sebagai seed awal; kalau kosong, build tetap
jalan (warning) dan HP generate sendiri.

### Alur first-run di HP

1. Install APK → buka → `BootstrapService` ekstrak rootfs dari assets
   (tanpa download, tanpa token) → status `extract-rootfs`.
2. `StackSupervisor` jalanin `first-boot-pip.sh` (bootstrap pip +
   install wheels offline) → `start-stack.sh`
   (php-fpm + nginx serve `/opt/debz/app` + opencode serve di port API).
3. WebView load backend lokal → login password `1337` → chat langsung jalan.

## Alur dev (HP dulu, GH ngikut)

Repo ini PRIVATE sampai stabil — yang nentuin open public nanti owner.

1. **HP = meja operasi.** Semua fix dioprek + diverifikasi langsung di rootfs
   HP (`/data/data/ai.debz/files/rootfs/...`) via bridge, tanpa reinstall.
   Backend PHP aktif per-request, jadi hot-patch langsung ngefek.
2. **GH = cermin yang terbukti.** Yang udah verified di HP doang yang
   di-commit/push. Tiap push `main` → CI build rootfs+APK → `ci-latest`.
3. **User = terima beres.** Tiap ada `ROOTFS_EPOCH` baru, app wipe + extract
   ulang otomatis. Notif update muncul via `OtaManager` (poll `ci-latest`).
4. Aturan epoch: naikkan `ROOTFS_EPOCH` tiap ada perubahan rootfs/backend
   tak-kompatibel — itu satu-satunya cara HP narik state baru.
