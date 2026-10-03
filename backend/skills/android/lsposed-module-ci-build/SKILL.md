---
name: lsposed-module-ci-build
description: "Build LSPosed/Xposed APK via GitHub Actions x86_64 CI: XposedBridgeApi bundle, Kotlin hook gotchas, release via tag."
version: "1.0.0"
tags: [lsposed, xposed, android, apk, github-actions, ci, kotlin, whatsapp]
---

# LSPosed Module — Build APK via GitHub Actions (x86_64 CI)

## Konteks
Build APK Android di ARM64 (Termux/HP) itu susah & lambat. Solusi proper: GitHub Actions build di runner x86_64, lu tinggal download APK dari artifact/release.

## Dependency Xposed API (PENTING — ini jebakan)
- **`api.lsposed.org` UDAH MATI** (DNS gak resolve) — jangan pakai repo ini di gradle
- **Maven Central TIDAK punya** `de.robv.android.xposed:api:*`
- **Solusi paling robust**: bundle `XposedBridgeApi-82.jar` langsung di project
  - Download: `https://maven.aliyun.com/repository/public/de/robv/android/xposed/api/82/api-82.jar`
  - Taruh di `app/libs/XposedBridgeApi-82.jar`
  - Gradle: `compileOnly(files("libs/XposedBridgeApi-82.jar"))`
  - Self-contained, CI gak butuh repo eksternal

## Jebakan compile Kotlin (jar API 82 = Java lama)
Jar API 82 TIDAK punya overload `findAndHookMethod(String, String, vararg)`.
WAJIB pass `ClassLoader` eksplisit di SEMUA panggilan:
```kotlin
// BENAR:
XposedHelpers.findAndHookMethod("com.whatsapp.X", classLoader, "methodName", object : XC_MethodHook() {...})
// SALAH (gak ada di API 82):
XposedHelpers.findAndHookMethod("com.whatsapp.X", "methodName", object : XC_MethodHook() {...})
```
Simpan `classLoader` global dari `lpparam.classLoader` di entry point, terus dipakai semua feature hook.

## Gotcha lain
- `ImageButton.ScaleType` gak ada → pakai `ImageView.ScaleType`
- `compileOnly` (bukan implementation) karena API di-inject LSPosed saat runtime
- Kotlin `param.args[0]` bisa di-assign langsung buat modif argumen

## Workflow GitHub Actions (minimal, build debug + release on tag)
```yaml
name: Build APK
on:
  push:
    branches: [main]
    tags: ["v*"]        # ← wajib biar release ke-trigger pas tag
  workflow_dispatch: {}
jobs:
  build:
    runs-on: ubuntu-latest
    permissions: { contents: write }
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-java@v4
        with: { distribution: temurin, java-version: "17" }
      - uses: gradle/actions/setup-gradle@v4
      - run: chmod +x gradlew
      - run: ./gradlew assembleDebug --no-daemon
      - uses: actions/upload-artifact@v4
        with:
          name: whatsapp-hook-debug
          path: app/build/outputs/apk/debug/*.apk
      - name: Create Release
        if: startsWith(github.ref, 'refs/tags/')
        uses: softprops/action-gh-release@v2
        with: { files: app/build/outputs/apk/debug/*.apk }
```

## Alur release
1. Push ke main → build + artifact (tab Actions)
2. `git tag v1.0.0 && git push origin v1.0.0` → release otomatis + APK di release page
3. Kalau workflow di-update setelah tag dibuat → hapus & recreate tag biar ke-trigger:
   `git push origin :v1.0.0 && git tag -d v1.0.0 && git tag v1.0.0 && git push origin v1.0.0`

## Debug CI dari HP (Termux, DNS rusak)
- Resolve IP via DoH: `https://dns.google/resolve?name=api.github.com&type=A`
- Curl pake `--resolve api.github.com:443:<IP>` + header `Authorization: Bearer <PAT>`
- Log Actions redirect ke Azure blob → resolve IP blob juga, ambil via URL signed

## Catatan WhatsApp Hook (konteks project)
- Versi target: WhatsApp 2.26.35.75 (terbaru)
- Nama class/method WhatsApp obfuscated & berubah tiap versi → hook-point template perlu disesuaikan via deobfuscation map / jadx
- Fitur: fake pending, silent call, view-once save (paling rawan ban), status download, stealth status, header toggle