# first-boot: install wheels python offline (jalan sekali di dalam proot)
if ! python3 -m pip --version >/dev/null 2>&1; then
  python3 -m ensurepip --upgrade >/dev/null 2>&1 || python3 /opt/debz/get-pip.py --no-index --find-links /opt/wheels
fi
python3 -m pip install --no-index --find-links /opt/wheels \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4
touch /opt/debz/.pydeps-done
