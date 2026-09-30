# first-boot: sediakan deps python (jalan sekali di dalam proot).
# Metode UTAMA: unpack langsung semua wheel /opt/wheels ke dist-packages
# via zipfile stdlib — tanpa pip/setuptools/network (terbukti di device:
# pip Debian mati (PEP 668), ensurepip false-negative, get-pip butuh
# network). Pure-python + manylinux-aarch64 langsung importable.
# Trace ke firstboot.log + set -x biar hang ketahuan titiknya dari device.
mkdir -p /opt/debz/logs
exec >>/opt/debz/logs/firstboot.log 2>&1
set -x
python3 -c "import zipfile, glob; ws=sorted(glob.glob('/opt/wheels/*.whl')); [zipfile.ZipFile(w).extractall('/usr/lib/python3/dist-packages') for w in ws]; print('wheels unpacked', len(ws))"
python3 -c "import flask, flask_cors, flask_sock, requests; print('pydeps ok')"
touch /opt/debz/.pydeps-done
