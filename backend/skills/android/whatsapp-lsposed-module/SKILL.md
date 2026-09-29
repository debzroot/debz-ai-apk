---
name: whatsapp-lsposed-module
description: "Develop/debug WhatsApp Xposed/LSPosed modules (WaEnhancer-based): DexKit hook resolution, CI builds, ANR/lag fixes, package rename. Proven on WhatsApp 2.26.35.75."
version: "1.0.0"
tags: [whatsapp, lsposed, xposed, zygisk, dexkit, hook, android, wamod, waenhancer]
---

# WhatsApp LSPosed Module Development (Playbook)

Proven workflow dari project nyata: module WhatsApp hook berbasis WaEnhancer,
rebrand, rebuild via GitHub Actions, fix ANR & lag. Target WhatsApp 2.26.35.75.

## 1. Arsitektur yang BENAR (jangan hardcode)

- WhatsApp 95% Java/Kotlin **obfuscated** — nama class berubah tiap versi.
- **JANGAN hardcode nama class/method** → pakai **DexKit** (resolver by string marker).
- WaEnhancer pattern: `Unobfuscator.kt` (134KB) berisi string marker yang dicari
  di dex WhatsApp via DexKit. Marker stabil antar versi (string SQL/log).
- Marker penting (terbukti di 2.26.35.75):
  - ViewOnce replay: `INSERT_VIEW_ONCE_SQL`
  - Fake pending/read receipt: `ReadReceiptUtils/buildReadReceiptHandler malformed`, `INSERT_RECEIPT_USER`
  - Silent call: `voip/callStateChangedOnUIThread`, `playMiddleTone`
  - Status download: `StatusData(`
  - Stealth status: `UpdatesViewModel/`
- ViewOnce replay (buka berulang, TANPA save): hook method, ubah returnValue `1→0`
  jika pesan bukan dari kita. Lebih aman dari save (tidak salin media).

## 2. Dependency Xposed API (repo yang hidup/mati)

- ❌ `api.lsposed.org` — **MATI** (DNS tidak resolve).
- ❌ Maven Central — TIDAK punya `de.robv.android.xposed:api:82`.
- ✅ **`api.xposed.info`** — repo Xposed API yang masih hidup (dipakai WaEnhancer).
- ✅ Alternatif: bundle `XposedBridgeApi-82.jar` lokal (mirror Maven Aliyun).
- ⚠️ Jar API 82 dari Aliyun HANYA punya overload `findAndHookMethod(String, ClassLoader, String, vararg)` —
  WAJIB pass `ClassLoader` eksplisit di semua hook (tidak ada overload 2-arg).
- Kotlin: `compileOnly(files("libs/XposedBridgeApi-82.jar"))`.

## 3. Build via GitHub Actions (WAJIB untuk ARM64)

- Build APK di Termux/ARM64 = menyakitkan (Gradle, dexing, dependency). **Selalu CI**.
- Workflow: `ubuntu-latest`, setup-java 17, `assembleWhatsappDebug`, upload artifact, release on tag `v*`.
- APK besar (>100MB) tidak bisa masuk repo — upload ke **release** sebagai asset, workflow download via `GITHUB_TOKEN`.
- Package rename: **JANGAN lupa folder `aidl/`** — compiler error:
  `WaeIIFace should be declared in a file called com/debz/wa/...aidl`.
  Grep `wmods`/package lama harus mencakup `.aidl`, bukan cuma `.kt/.java/.xml`.
- Submodule C++ (opus/ogg) kosong → **disable fitur native** (call recording) + hapus `ndkVersion` + `.gitmodules` biar CI bersih.
- Repo privat: API tanpa token = 404. Actions log redirect ke Azure blob → resolve IP Azure lalu `curl --resolve`.

## 4. Fix ANR (WhatsApp isn't responding saat buka)

- Penyebab: init berat (DexKit scan `UnobfuscatorCache.init`, `ReflectionUtils.initCache`,
  `initComponents`, plugins) jalan **sinkron di main thread** saat `callApplicationOnCreate`.
- Fix: pindahkan blok init berat ke **background thread** (executor/HandlerThread).
  Register Xposed hook aman dari thread mana pun.

## 5. Fix LAG di UI obrolan (paling sering)

Pola anti-pattern yang bikin lag (semua ketemu di project nyata):

1. **`notifyDataSetChanged()` per-bubble** = rebind SELURUH list → bubble lain miss →
   schedule lagi → **loop tak berujung**. Fix: **targeted view update** (update view yang visible saja).
2. **Reflection/DB query sinkron per item** saat bind (misal `getDeviceType(...) { cb() }`
   yang manggil refresh). Fix: cache + update targeted.
3. **`sendMethod.invoke(...)` (kirim presence ke server) di main thread per kontak** +
   TTL cache 1 detik → fetch tiap render. Fix: background thread + TTL ≥30 detik.
4. `notifyDataSetChanged` di event jarang (revoke message) = aman, jangan diubah.

Pola umum: **hook di method yang kepanggil super sering (render bubble/row) WAJIB
synchronous-fast + cached + no full-list refresh.**

## 6. UI spacing di bubble chat

- Dot `🟢`/`🔴` (pengganti centang) = **TextView terpisah** (tag `seen_view`) yang di-inject
  ke `date_wrapper`, BUKAN bagian string timestamp. Normalisasi string `text_in_hour`
  TIDAK ngefek ke dot. Fix: `setPaddingRelative(3dp,0,3dp,0)` di TextView dot.
- Template `text_in_hour` (`📱[TIME]🟢`) → normalisasi spasi di CustomTime.

## 7. Version check bypass

- `2.26.35.75` muncul "Unsupported version" → tambah ke `supported_versions_wpp` array
  + pastikan pref `bypass_version_check` default true. APK lama harus di-uninstall
  dulu kalau package berubah (data prefs beda).

## 8. Rebranding WaEnhancer (terbukti)

- Replace total source WaEnhancer → rebrand: app name, versionName/Code, UpdateChecker
  (nunjuk repo sendiri), folder download, strings. **Package tetap** biar hook & prefs jalan
  (atau rename total kalau mau, tapi harus include AIDL + xposed_init + provider authority).
- Toast branding: jangan ganti SEMUA toast jadi satu string — user butuh toast fungsional.
  Kalau mau branding, kombinasikan: `"👻 Debz ✨"` hanya untuk toast non-error.
- Hapus About: hapus `AboutActivity.java` + layout + menu item + handler di MainActivity.
  ⚠️ Regex hapus menu bisa merusak Java (`return true; else if` = syntax invalid) →
  selalu verifikasi brace balance & compile setelah edit.
- UI retro: ganti theme parent dari DynamicColors ke Material3 standar + palette custom
  + font serif. Pastikan `nav_background` gelap kalau tombol nav putih.

## 9. Environment Termux quirks (environment ini)

- `/tmp` read-only → pakai HOME (`~/Workspaces/`).
- DNS broken di shell → resolve via DoH (`http_request` ke `https://dns.google/resolve`),
  lalu `curl --resolve host:443:IP` atau SSH via IP + `HostKeyAlias`.
- GitHub API repo privat butuh token; jangan tampilkan token di output.

## 10. Fitur WaEnhancer yang ter-registrasi (62 fitur)

Kategori: customization (14) · others (15) · general (13) · privacy (11) · media (6) · listeners (3).
Fitur kunci: SeenTick (fake pending), HideSeen (stealth), CallPrivacy, ViewOnce (replay),
StatusDownload + MenuStatusListener, IGStatus. Default `downloadstatus` WAJIB true
biar menu download muncul.