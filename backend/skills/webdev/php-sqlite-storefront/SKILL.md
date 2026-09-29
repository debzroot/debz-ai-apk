# PHP + SQLite Storefront & Admin Dashboard (Sample Toko Online)

Prosedur reusable untuk membangun sample toko online di `~/Linux_server/var/www/localhost/htdocs/<nama>/` yang langsung serve via nginx + php-fpm dan bisa diakses publik via cloudflared (contoh: `https://debz.online/<nama>/`).

## Kapan dipakai
- User minta sample toko online / landing page + admin dashboard demo
- Butuh CRUD produk beneran (tambah/edit/hapus) tanpa setup DB server
- Target: mobile + PC responsive, tampilan anti-template/custom

## Stack
- PHP 8.5 (pdo_sqlite, sqlite3 tersedia)
- SQLite (file `.db` di folder `data/`, auto-seed)
- Tailwind TIDAK dipakai — pakai CSS custom (lebih unik & anti-plagiat)
- Vanilla JS untuk keranjang (localStorage), drawer, toast
- Font: Google Fonts

## Struktur
```
htdocs/<nama>/
├── index.php          → storefront (hero, grid, kategori, search)
├── product.php        → detail produk + rekomendasi
├── admin/
│   ├── index.php      → dashboard + tabel + hapus
│   ├── login.php      → form password + tombol demo 1 klik
│   ├── logout.php
│   ├── product-form.php → tambah/edit + upload gambar
│   ├── delete.php
│   ├── includes/auth.php → session guard + helpers
│   └── assets/admin.css
├── includes/
│   ├── db.php         → PDO sqlite + seed + helper rupiah()/e()
│   └── layout.php     → header/footer/cart drawer
├── assets/
│   ├── style.css
│   ├── app.js
│   └── img/           → gambar produk (download dari Unsplash)
└── data/toko.db       → auto-buat
```

## Trap penting (sudah ketemu & solved)
1. **Path `__DIR__` di admin/includes/auth.php**: di environment ini `__DIR__` bisa resolve ke path server lain. Solusi: pakai `dirname(__DIR__)` relatif dari file, dan jangan hardcode path server.
2. **Folder web server = `htdocs`** (bukan `htdoc`). Cek dulu: `ls ~/Linux_server/var/www/localhost/`
3. **Shell default `sh` (dash)**: TIDAK support `declare -a` dan brace expansion `{a,b}` → bikin folder literal aneh. Solusi: pakai `bash -c '...'` atau mkdir satu-satu.
4. **Download gambar**: kalau `cd` gagal, file nyasar di cwd (`~/debz-ai/`). Selalu verifikasi lokasi setelah download & pindahkan.
5. **Screenshot browser**: pakai `PW_PORT=9222 node ~/debz-ai/pw_browser.mjs <cmd>` (daemon CDP port 9222). `goto`, `screenshot <path>`, `eval <js>`.
6. **Cek gambar broken**: scroll dulu sebelum eval naturalWidth, karena lazy-load.
7. **Test CRUD via browser**: login demo = submit form dengan hidden input value=demo; tambah produk = isi input + submit form; verifikasi via DB `php -r` dan storefront.

## Verifikasi sebelum selesai
- `php -l` semua file
- curl storefront/admin login/detail produk → HTTP 200, admin index → 302 (belum login)
- CRUD test: tambah produk via browser, cek muncul di storefront, lalu hapus
- Cek DB tidak bisa diakses publik (harus 404)
- Screenshot desktop + mobile + admin dashboard

## Desain custom yang sudah dipakai (2 gaya beda)
### 1. NOIRÉ — Fashion Store (dark editorial)
- Dark theme: bg #0b0b0d, ink #f4efe6, accent #ff4d2e, gold #e8b04b
- Serif Fraunces heading + Manrope body, grain overlay, marquee, rotated tag, numbered cards
### 2. VOLT — Gadget/Tech Store (light futuristic glassmorphism)
- Light theme: bg #f4f7fb, ink #0b1220, gradient cyan #06b6d4 → violet #7c3aed
- Font Space Grotesk + Inter, glassmorphism cards (backdrop-filter), grid pattern hero, floating chips, marquee dark strip, compare table, feature cards
- Admin: light glassmorphism konsisten, stat cards gradient, badge kategori, upload gambar JPG/PNG/WEBP

## Tips variasi sample berikutnya (anti monoton)
- Ganti total: tema (dark/light/warm), font pairing, layout hero (split/centered/asymmetric), aksen warna, bentuk kartu (rounded/radius/arch), animasi
- Jangan ulang struktur grid yang sama persis; bedakan section (marquee vs banner vs carousel)