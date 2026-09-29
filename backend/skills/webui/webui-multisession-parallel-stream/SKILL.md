# WebUI Multi-Sesi Paralel (Stream Background per Session)

Prosedur reusable untuk `~/debz-ai/c0n73xt.js` — membuat stream AI jalan di background per-session, sehingga user bisa pindah chat / buka chat baru tanpa mem-pause stream lama.

## Masalah Asli
- `busy`, `abortController`, `SR` (stream renderer), `messages` semua **global** → cuma 1 stream aktif.
- Patch race-guard (`streamGen` + `streamSessionId`) bikin **deadlock**: pindah session saat busy → `finally` stream lama skip reset (gen basi) → `busy` nyangkut `true` → input disabled permanen, tombol stop/kirim no-op.

## Solusi: Session Stream Registry
- Ganti state global → **`sessionStreams = { [sid]: { gen, abort, busy, fullContent, rdItems, runId, el, kaId, timer, assistantIndex } }`**.
- Stream lama **TIDAK di-abort** saat pindah session — tetap jalan di background, nulis ke `mySessionObj.messages` (object session asli, bukan `messages` global).
- `mySessionId` di-capture saat submit → guard semua write: kalau `mySessionId !== activeSessionId`, jangan sentuh DOM global / `setBusy` global.
- `loadSessionView()` → kalau session punya stream aktif, re-attach SR ke elemen baru + render ulang konten partial.

## Guard yang WAJIB
1. **Submit handler**: `if (sessionStreams[activeSessionId]) return;` (bukan `if (busy) return;`) — biar bisa kirim pesan di session lain saat ada stream background.
2. **Tombol stop**: abort `sessionStreams[activeSessionId].abort` (bukan `abortController` global).
3. **HTTP error path**: nulis ke `mySessionObj.messages` + `persist()` per-session, bukan global.
4. **`loadSessionView` filter**: JANGAN hapus assistant message kosong (`!m.content.trim()`) kalau session masih streaming (content masih `''` saat stream jalan) — simpan `assistantIndex` di registry.
5. **AbortError handler**: cek `mySessionId === activeSessionId` sebelum nulis `(Dibatalkan)`.
6. **`setBusy` di `loadSessionView`**: kalau session yang dibuka tidak streaming, reset busy — input harus tetap aktif walau ada stream background.

## Reset Cepat (anti-deadlock)
Saat `switchSession`/`newChat` dengan stream aktif: `abortController = null; stopKeepaliveBeacon(); hideProgress(); setBusy(false);` — jangan nunggu `finally` stream lama (gen-nya sudah basi, finally skip reset).

## Catatan Arsitektur Server
- Mode gateway (`/runs` + `run_id`): run jalan di server **independen** dari koneksi client → polling `run_status` bisa resume. Cocok buat multi-sesi.
- Mode native/chat (`native_agent_run`): run jalan **di dalam request PHP** → kalau client abort, `clientIsGone()` → run mati. Multi-sesi penuh hanya mungkin di mode gateway.

## Verifikasi
- `node --check c0n73xt.js` syntax OK.
- Test live browser: kirim pesan session A (stream jalan) → pindah session B → cek `inputDisabled:false` + kirim pesan di B → balik ke A → konten stream harus lengkap (bukan paused).
- Cek localStorage `debz_sessions_v8` (format `{activeId, sessions}`) untuk bukti stream background selesai nulis.