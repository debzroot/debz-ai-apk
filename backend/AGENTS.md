# AGENTS.md — Debz AI (single source of truth)

> Wajib dibaca & dipatuhi setiap sesi. File ini di-inject otomatis oleh
> `debz-term.py` (RULES WAJIB) + auto-load native opencode dari `cwd`.
> Lokasi canonical: `/opt/debz/app/AGENTS.md`. Dilarang duplikat paralel.

## 1. Identitas lingkungan (hafal, jangan cari lagi)
- `PROJECT_ROOT=/opt/debz/app` (= `cwd` default semua tool exec/opencode)
- Backend Flask: `backend.py` port `9191`, token di `.ai-config.ini` (`AI_TOOLS_TOKEN`)
- Opencode server: port `8092`, cfg isolasi di `opencode-bin/.cfg_home`, data di `opencode-bin/.data_home`
- Android Bridge (Java): `127.0.0.1:8098`, token di `/opt/debz/.android_bridge.json`, flag `root:true`
- DB memori: `/opt/debz/app/notes.db` tabel `notes(key,content,updated_at)`
- Skills: `/opt/debz/app/skills/*/SKILL.md`, discan via `/api/skill action=list/search/get`
- Jaringan: PROXY-FREE — semua request direct, tidak ada proxy pool/grabber.
- Workspaces user: `/opt/debz/Workspaces/<nama_project>/` — semua output kerja ke sini
- Screenshots: `/opt/debz/Workspaces/debz_ai_screenshots/`
- Kamu jalan sebagai uid non-root di dalam proot (`id` ~10314). BUKAN root Android langsung.

## 2. DUA SHELL — jangan tertukar (paling penting)
| Mau ngapain | Pakai | Contoh |
|---|---|---|
| File/proses di dalam proot: `ls cat grep python3 php pip git` | `/api/exec` / `shell` / MCP `shell_exec` | `ls /opt/debz/app` |
| Full root Android: `pm dumpsys settings input svc cmd am` | `/api/android` command | `{"command":"dumpsys battery"}` |
| Cek bridge hidup? | baca `/opt/debz/.android_bridge.json` | harus ada `port`+`token` |
| Kalau bridge mati | jawab jujur "butuh APK baru + reboot", JANGAN ngaku-ngaku sukses | — |

Rule: butuh akses HP (buka app, tap, setting sistem) → SELALU via `/api/android` atau `computer_use`/`browser`, BUKAN `shell_exec` biasa.

## 3. Peta tool cepat (task → tool, tanpa eksplor)
- Baca/tulis/edit file: `file_read` / `file_write` / `file_edit` (MCP) = `read_file`/`write_file`/`list_dir`/`search` (native). Edit = exact-string unik, kalau muncul Nx pakai `replaceAll`.
- `shell_exec` timeout default 60s maks 300s. Output dipotong 8000 char tail. Untuk job lama: `nohup ... > /tmp/x.log &` lalu `tail`.- HTTP/API: `http_request` (`/api/http`), download: `download_file` (`/api/download`).
- SQLite: `db_query` (`/api/db`) — notes.db, RAG, dsb.
- Proses: `process_list`/`process_kill` (`/api/ps`, `/api/kill`).
- Package proot: `app_install` (`/api/pkg`, apk/pkg). BUKAN untuk app Android.
- Web: `web_search` (DuckDuckGo), `browser` (CDP/Playwright): pola `goto → screenshot → click/type → eval`.
- Layar virtual: `computer_use` (`/api/cua`: status/screenshot/open/launch/click/dblclick/rightclick/move/drag/type/key/scroll) + `screenshot` (`/api/screenshot`). Wajib `status` dulu sebelum aksi.
- Jadwal: `scheduler` (list/add/remove/toggle/run, cron expr).
- Backup: `backup` (create/list/restore/delete). Prune otomatis.
- Memori: `note` (list/get/add/delete/search) → notes.db. `skill` (list/search/get/create/delete/stats) → skills/.
- Arsip: `archive` (create/extract zip/tar/tar.gz).
- Dilarang: `rm -rf /`, `dd/mkfs/fdisk`, `chmod -R 777 /`, `curl|sh` tanpa approval. Backend return `need_approval` → minta user, ulang dengan `approved=true`.

## 4. SOP kerja efisien (no buang waktu)
1. Awal sesi: baca AGENTS.md ini (kamu sudah), lalu `note get bootstrap` + `note get map-apk`. JANGAN `glob`/`grep` buta kalau sudah ada di memori.
2. Pilih tool dari tabel §3 langsung. Jangan `coba-coba semua tool`.
3. Patch file: backup dulu ke `~/.ai_staging/BACKUP/<project>/`, edit LANGSUNG di lokasi aktif, dilarang `.bak/.orig/tmp_*/session_*` di `app/` atau `Workspaces/`. Tampilkan `diff -u`.
4. Validasi: `php -l` untuk php, `python3 -m py_compile` untuk py, `opencode` config ubah → test throwaway.
5. Setiap temuan permanen (jalur baru, password, gotcha): `note add` + sinkron ke AGENTS.md bila SOP. Single-source-of-truth: AGENTS.md + notes.db + skills/, hapus yang bertentangan.

## 5. Computer_use & browser (ringkas)
- CUA: `status` → `screenshot` lihat → `click/type/key` koordinat → verifikasi `screenshot` lagi. Jangan asumsi posisi UI.
- Browser CDP: `goto <url>` → `text/title` cek load → `screenshot` → `click/type` selector → `wait` bila SPA. Timeout browser 120s.
- MCP `browser` didelegasikan ke `cdp_chrome.py` (stdlib only).

## 6. Skill yang sering dipakai
- `skill action=search pattern="<kata>"` dulu sebelum baca full. Kategori: `debz-ai`, `autonomous-ai-agents`, `architecture/debz-ai-agent-optimization`, `webui`, `networking`, `android`.
- Kalau ubah SOP → wajib sinkron 3 tempat (AGENTS.md, notes.db `rules-core-ai`, skills) — lihat `agents-rules-sync-self-improvement/SKILL.md`.

## 7. SYNTAX GATE (WAJIB — Anti-Sabotase File Sendiri)
- **WAJIB** abis edit `.sh` / `.php` / `.py`, validasi **sebelum** bilang "selesai":
  - `.sh` → `sh -n <file>` · `.php` → `php -l <file>` · `.py` → `python3 -c "import ast,sys;ast.parse(open(sys.argv[1],encoding='utf-8').read(),sys.argv[1])" <file>` · `.js` → `node --check <file>`
- **Gagal validasi = ROLLBACK INSTAN** (isi lama yang udah dibaca, atau `/root/.ai-stagging/BACKUP/`). **DILARANG** ninggalin file rusak — `start-stack.sh` rusak = **seluruh stack mati**, `agent.php`/`api.php` rusak = chat mati.
- Gate otomatis sudah nempel di tool `file_write`/`file_edit` (`backend.py` + `debz_tools_mcp.py`): file `.sh/.php/.py` rusak **otomatis dibalik**. Kalau muncul `file dibalik (sintaks rusak)` → patch-mu salah, perbaiki. Jangan dipaksa, jangan di-backup.
- **Anti-pola saat nulis `.sh`** (ini yang pernah bikin stack mati total):
  - **Jangan nulis quote ter-escape** (`\"`, `\\0`, `\\.`) — gejala write/heredoc yang nge-escape 2x, `sh` langsung `Syntax error: "(" unexpected`, dan baru meledak **saat restart**, bukan saat edit.
  - Heredoc pakai delimiter ter-quote (`<<'EOF'`) kalau isinya nyisipin `$var`/`$(...)`/backtick.
  - **Patch per blok** (oldString → newString), jangan tulis ulang seluruh file dari ingatan.
- File yang dipakai proot/startup (`*.conf`, `*.template`, `start-stack.sh`, `watchdog.sh`) wajib **render + tes** sebelum declare OK: `sh -n`, `nginx -t -c <render>`, `php -l`.
