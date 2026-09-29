# first-boot: install wheels python offline (jalan sekali di dalam proot)
python3 -m pip install --no-index --find-links /opt/wheels \
  flask flask-cors flask-sock requests rich prompt_toolkit beautifulsoup4
touch /opt/debz/.pydeps-done
