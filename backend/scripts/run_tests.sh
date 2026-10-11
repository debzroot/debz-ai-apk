#!/bin/bash
# Runner smoke test poin 4: backend (python) + api (php) + syntax gate.
set -u
D="$(cd "$(dirname "$0")/.." && pwd)"
cd "$D" || exit 1
fail=0
echo "== syntax gate =="
php -l api.php >/dev/null || fail=1
php -l agent.php >/dev/null || fail=1
php -l logger.php >/dev/null || fail=1
php -l tests/smoke_api.php >/dev/null || fail=1
node --check c0n73xt.js >/dev/null || fail=1
sh -n scripts/log_rotate.sh || fail=1
sh -n scripts/provider_heartbeat.sh || fail=1
python3 -c "import ast;ast.parse(open('backend.py',encoding='utf-8').read())" || fail=1
python3 -c "import ast;ast.parse(open('scripts/sessions_index.py',encoding='utf-8').read())" || fail=1
python3 -c "import ast;ast.parse(open('tests/smoke_backend.py',encoding='utf-8').read())" || fail=1
echo "syntax: $([ $fail -eq 0 ] && echo PASS || echo FAIL)"
echo "== php smoke =="
php tests/smoke_api.php || fail=1
echo "== backend smoke =="
if curl -s -m 3 "http://127.0.0.1:${TOOLS_PORT:-9191}/api/health" | grep -q healthy; then
  python3 tests/smoke_backend.py || fail=1
else
  echo "SKIP backend smoke (tool-server mati) — jalankan: python3 backend.py &"
fi
echo "== result: $([ $fail -eq 0 ] && echo SEMUA_LOLOS || echo ADA_GAGAL) =="
exit $fail
