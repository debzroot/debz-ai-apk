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

mkdir -p "$WORK" "$OUT" "$DEBS" "$ROOTFS"

echo ">> [1/7] ubuntu-base arm64"
curl -sSL -o "$WORK/base.tar.gz" "$UBUNTU_BASE_URL"
tar -xzf "$WORK/base.tar.gz" -C "$ROOTFS"

echo ">> [2/7] unduh paket arm64 (deps auto-resolve)"
sudo dpkg --add-architecture arm64 2>/dev/null || true
if ! grep -q ports.ubuntu.com /etc/apt/sources.list /etc/apt/sources.list.d/*.list 2>/dev/null; then
  echo "deb [arch=arm64] http://ports.ubuntu.com/ubuntu-ports noble main universe" | sudo tee /etc/apt/sources.list.d/arm64-ports.list
  echo "deb [arch=arm64] http://ports.ubuntu.com/ubuntu-ports noble-updates main universe" | sudo tee -a /etc/apt/sources.list.d/arm64-ports.list
fi
sudo apt-get update -qq
sudo apt-get install --download-only -y --no-install-recommends \
  -o Dir::Cache::archives="$DEBS" \
  bash:arm64 dash:arm64 procps:arm64 \
  php8.3-fpm:arm64 php8.3-cli:arm64 \
  nginx:arm64 ca-certificates:arm64 \
  python3:arm64 python3-pip:arm64 python3-venv:arm64
sudo chown -R "$(id -u):$(id -g)" "$DEBS"

echo ">> [3/7] unpack .deb ke rootfs"
for f in "$DEBS"/*.deb; do dpkg-deb -x "$f" "$ROOTFS"; done

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
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp -r "$SCRIPT_DIR/opt-debz" "$ROOTFS/opt/debz"
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
