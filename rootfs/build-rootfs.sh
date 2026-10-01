#!/usr/bin/env bash
# build-rootfs.sh — rakit rootfs-mini ARM64 (tanpa qemu: unpack .deb + tarball).
# Output: out/rootfs-mini.tar.gz  (+ out/SHA256SUMS)
set -euo pipefail

UBUNTU_BASE_URL="${UBUNTU_BASE_URL:-https://cdimage.ubuntu.com/ubuntu-base/releases/24.04/release/ubuntu-base-24.04.5-base-arm64.tar.gz}"
OPENCODE_VER="${OPENCODE_VER:-v1.18.33}"
OPENCODE_URL="${OPENCODE_URL:-https://github.com/sst/opencode/releases/download/${OPENCODE_VER}/opencode-linux-arm64.tar.gz}"
APP_SRC_DIR="${APP_SRC_DIR:-}"

WORK="${WORK:-$(pwd)/.work-rootfs}"
OUT="${OUT:-$(pwd)/out}"
ROOTFS="$WORK/rootfs"
DEBS="$WORK/debs"
WHEELS="$ROOTFS/opt/wheels"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_SRC_DIR="${APP_SRC_DIR:-$SCRIPT_DIR/../backend}"

mkdir -p "$WORK" "$OUT" "$DEBS" "$ROOTFS"

echo ">> [1/7] ubuntu-base arm64"
curl -sSL -o "$WORK/base.tar.gz" "$UBUNTU_BASE_URL"
tar -xzf "$WORK/base.tar.gz" -C "$ROOTFS"

echo ">> [2/7] unduh paket arm64 langsung dari ports (tanpa apt host)"
python3 "$SCRIPT_DIR/fetch-arm64-debs.py" "$DEBS" \
  bash dash procps curl git \
  php8.3-fpm php8.3-cli php8.3-curl php8.3-sqlite3 php8.3-mbstring \
  nginx ca-certificates \
  python3 python3-venv
echo ">> [2b/7] get-pip.py bootstrap"
curl -sSL -o "$ROOTFS/opt/debz-get-pip-tmp" https://bootstrap.pypa.io/get-pip.py 2>/dev/null && mkdir -p "$ROOTFS/opt/debz" && mv "$ROOTFS/opt/debz-get-pip-tmp" "$ROOTFS/opt/debz/get-pip.py" || echo "   ! get-pip.py gagal diunduh, first-boot pakai ensurepip"

echo ">> [3/7] unpack .deb ke rootfs"
for f in "$DEBS"/*.deb; do dpkg-deb -x "$f" "$ROOTFS"; done

echo ">> [3b/7] aktifkan ekstensi PHP (postinst deb tak jalan via dpkg-deb -x)"
# Tanpa ini curl/mbstring/sqlite3 ada .so-nya tapi tak pernah diload ->
# curl_init() fatal -> save provider 500 kosong di HP.
for m in curl mbstring sqlite3 fileinfo; do
  echo "extension=$m.so" > "$ROOTFS/etc/php/8.3/mods-available/$m.ini"
done
mkdir -p "$ROOTFS/etc/php/8.3/cli/conf.d" "$ROOTFS/etc/php/8.3/fpm/conf.d"
for sapi in cli fpm; do
  ln -sf "../../mods-available/curl.ini" "$ROOTFS/etc/php/8.3/$sapi/conf.d/20-curl.ini"
  ln -sf "../../mods-available/mbstring.ini" "$ROOTFS/etc/php/8.3/$sapi/conf.d/20-mbstring.ini"
  ln -sf "../../mods-available/sqlite3.ini" "$ROOTFS/etc/php/8.3/$sapi/conf.d/20-sqlite3.ini"
  ln -sf "../../mods-available/fileinfo.ini" "$ROOTFS/etc/php/8.3/$sapi/conf.d/20-fileinfo.ini"
done
# skrip/AI kadang manggil `php` generik (cuma php8.3 yg ada) — symlink biar ga ENOENT
ln -sf php8.3 "$ROOTFS/usr/bin/php"
ls "$ROOTFS/etc/php/8.3/fpm/conf.d/"

echo ">> [4/7] opencode $OPENCODE_VER (arm64)"
curl -sSL -o "$WORK/opencode.tgz" "$OPENCODE_URL"
mkdir -p "$ROOTFS/opt/opencode"
tar -xzf "$WORK/opencode.tgz" -C "$ROOTFS/opt/opencode"

echo ">> [5/7] wheels python (aarch64-correct, install saat first-boot)"
mkdir -p "$WHEELS"
pip download --dest "$WHEELS" \
  --platform manylinux2014_aarch64 --python-version 3.12 \
  --implementation cp --abi cp312 --only-binary=:all: \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4

echo ">> [6/7] payload /opt/debz + app opsional"
# trailing /. : copy ISI opt-debz, jangan pernah nested (opt/debz/opt-debz)
# — step 2b sudah bikin $ROOTFS/opt/debz duluan buat get-pip.py
mkdir -p "$ROOTFS/opt/debz"
cp -r "$SCRIPT_DIR/opt-debz/." "$ROOTFS/opt/debz/"
if [ -n "$APP_SRC_DIR" ] && [ -d "$APP_SRC_DIR" ]; then
  echo "   + app payload dari $APP_SRC_DIR"
  mkdir -p "$ROOTFS/opt/debz/app"
  cp -r "$APP_SRC_DIR"/. "$ROOTFS/opt/debz/app/"
fi
echo "$OPENCODE_VER" > "$ROOTFS/opt/debz/OPENCODE_VER"

echo ">> [7/7] prune + tarball"
rm -rf "$ROOTFS/usr/share/doc" "$ROOTFS/usr/share/man" "$ROOTFS/usr/share/locale"
rm -rf "$ROOTFS/var/cache/apt" "$ROOTFS/var/lib/apt/lists"
tar -czf "$OUT/rootfs-mini.tar.gz" -C "$ROOTFS" .
(cd "$OUT" && sha256sum rootfs-mini.tar.gz > SHA256SUMS)
ls -la "$OUT"
