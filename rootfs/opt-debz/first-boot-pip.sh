# first-boot: install wheels python offline (jalan sekali di dalam proot).
# Urutan: pip bawaan > get-pip.py lokal (bundled di image). SEMUA offline
# (Debian ensurepip false-negative, jangan dipakai). Debian PEP 668
# (externally-managed) WAJIB --break-system-packages atau pip nolak.
# Trace ke firstboot.log + set -x biar hang ketahuan titiknya dari device.
mkdir -p /opt/debz/logs
exec >>/opt/debz/logs/firstboot.log 2>&1
set -x
export PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_NO_INPUT=1 PIP_NO_CACHE_DIR=1
export PIP_BREAK_SYSTEM_PACKAGES=1
if ! python3 -m pip --version >/dev/null 2>&1; then
  python3 /opt/debz/get-pip.py --no-index --no-build-isolation \
    --break-system-packages >/dev/null 2>&1 || true
fi
timeout 240 python3 -m pip install --no-index --only-binary=:all: \
  --break-system-packages --find-links /opt/wheels \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4 \
  || echo "pip install gagal/timeout, lanjut tanpa tools python"
touch /opt/debz/.pydeps-done
