# first-boot: install wheels python offline (jalan sekali di dalam proot).
# Urutan: pip bawaan > ensurepip lokal > get-pip.py lokal (bundled di
# image). SEMUA offline: tanpa --upgrade, tanpa version-check, tanpa
# source-build, plus timeout — stall network = boot gantung selamanya.
# (Debian patch ensurepip: sering false-negative, jangan diandalkan.)
export PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_NO_INPUT=1 PIP_NO_CACHE_DIR=1
if ! python3 -m pip --version >/dev/null 2>&1; then
  python3 /opt/debz/get-pip.py --no-index --no-build-isolation >/dev/null 2>&1 || true
fi
timeout 240 python3 -m pip install --no-index --only-binary=:all: \
  --find-links /opt/wheels \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4 \
  || echo "pip install gagal/timeout, lanjut tanpa tools python"
touch /opt/debz/.pydeps-done
