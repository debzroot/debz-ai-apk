# WebUI Patch: Dark Mode Toggle + Stop Button + New Chat Saat Streaming

Prosedur reusable untuk project `~/debz-ai/` (webui `index.php` + `c0n73xt.css` + `c0n73xt.js`).

## 1. Dark Mode Toggle (theme)
- Semua warna webui dipegang CSS variables di `:root` (`c0n73xt.css` baris 2–24: `--bg`, `--txt`, `--panel`, dst).
- Tambah blok `html[data-theme="dark"]` override semua variables + elemen hardcoded (halftone dots, hover putih, inline code, link, backdrop).
- Tombol toggle 🌙/☀️ taruh di `.header-right` (index.php), sebelum tombol terminal.
- Persist via `localStorage` key `c0n73xt-theme`; anti-flicker script inline di `<head>` (baca localStorage sebelum render).
- Meta `theme-color` ikut berubah untuk PWA.
- Default tetap light (bawaan).

## 2. Stop Button (ganti spinner di tombol kirim)
- `setBusy(true)` di `c0n73xt.js` → render ikon kotak stop (SVG `rect`) di tombol kirim, sembunyikan spinner tombol.
- Spinner progress work tetap di progress bar (jangan dihapus).
- `setBusy(false)` → balik ke ikon kirim (panah atas).
- Klik tombol saat busy = cancel/abort stream (fungsi lama tetap jalan).

## 3. New Chat Saat Streaming (tanpa nunggu AI selesai)
- `newChat()` → kalau `busy`, abort `abortController` dulu, baru buat session baru.
- WAJIB race condition guard (generation counter + session guard):
  - `streamGen` (increment tiap stream baru) → cek di handler AbortError & finally: kalau gen beda, jangan reset busy/abortController stream baru.
  - `streamSessionId` → cek sebelum nulis ke `messages`: kalau session beda, jangan nulis `(Dibatalkan)` ke session baru.
- `switchSession` (klik chat lain di sidebar) tetap diblokir saat busy — aman, biar data session tidak korup.

## Prosedur patch (rules_ai)
1. Backup original → `~/.ai_staging/BACKUP/`.
2. Direct Patch ke file aktif (backup di `~/.ai_staging/BACKUP/`).
3. Validasi: `php -l` (PHP), `node --check` (JS), diff file asli vs FIXED.
4. Test live via browser tool (login auth, cek toggle & busy state).
5. Screenshot → `~/Workspaces/<project>/screenshots/`.

## Pitfall
- `rsplit('</body>', 1)` bisa kena `</body>` halaman login (file punya 2 body) — pakai `rfind` biar kena yang terakhir.
- Provider API bisa 429 (rate limit) saat test live — verifikasi UI via simulasi DOM/console, bukan nunggu AI beneran.