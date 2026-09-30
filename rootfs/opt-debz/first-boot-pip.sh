# first-boot: install wheels python offline (jalan sekali di dalam proot).
# WAJIB 100% offline: ensurepip tanpa --upgrade (upgrade nelpon PyPI!),
# PIP_DISABLE_PIP_VERSION_CHECK (pip phone-home), --only-binary (tanpa
# build source), timeout biar ga gantung boot kalau ada stall.
if ! python3 -m pip --version >/dev/null 2>&1; then
  python3 -m ensurepip --default-pip >/dev/null 2>&1 || true
fi
export PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_NO_INPUT=1
timeout 240 python3 -m pip install --no-index --only-binary=:all: \
  --find-links /opt/wheels \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4 \
  || echo "pip install gagal/timeout, lanjut tanpa tools python"
touch /opt/debz/.pydeps-done
