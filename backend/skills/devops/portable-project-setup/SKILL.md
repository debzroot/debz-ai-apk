---
name: portable-project-setup
description: Make a project portable — remove hardcoded paths, add setup.sh, update requirements
tags: portable, setup, refactor, hardening, clean-path
version: 1.0
---

# Portable Project Setup

## Problem
Project hanya jalan di environment tertentu karena hardcoded absolute paths seperti `/home/debz/...`.

## Strategy (Opsi A — Minimal)

### 1. Identify All Hardcoded Paths
```bash
grep -rn "/home/debz" --include="*.py" --include="*.php" --include="*.mjs" --include="*.html" | grep -v backups | grep -v venv | grep -v templates
```

### 2. Replace Per-File-Type

| File Type | Anchor | Replacement |
|---|---|---|
| PHP | `__DIR__` | `__DIR__ . '/relative/path'` |
| Python | `os.path.dirname(os.path.abspath(__file__))` | `os.path.join(PROJECT_ROOT, ...)` |
| Node.js | `__dirname` | `require('path').join(__dirname, ...)` |
| Node.js (home) | N/A | `require('os').homedir()` |

### 3. Platform-Specific Code
For platform-specific binaries (chromium, tmp dirs):
```javascript
// Use env override with sane default
const BIN = process.env.CHROMIUM_BIN || 'chromium-browser';
```

### 4. Create setup.sh
- Detect OS (Linux/Docker/Debian/Fedora/Mac/Arch)
- Install system deps via package manager
- pip install requirements.txt
- Generate .ai-config.ini with random token
- Create .ai-providers.json template
- Create .gitignore

### 5. Update requirements.txt
- Check all imports across all .py files
- Add missing deps (flask-cors, beautifulsoup4, etc.)

### 6. Backup & Verify
```bash
# Backup originals
mkdir -p ~/.ai_staging/BACKUP/pre-portable-$(date +%Y%m%d)
cp files... ~/.ai_staging/BACKUP/pre-portable-$(date +%Y%m%d)/

# Verify zero hardcoded paths
grep -rn "HARDCODED_PATH" --include="*.py" --include="*.php" | grep -v backups | grep -v dev

# Syntax check all patched files
php -l file.php && python3 -c "import py_compile; py_compile.compile('file.py', doraise=True)" && node --check file.mjs
```

## Results (Debz AI, 2026-09-10)
- **Files patched:** 7 (agent.php, debz-term.py, backend.py, pw_daemon.mjs, pw_browser.mjs, requirements.txt, setup.sh)
- **Hardcoded paths removed:** 20+
- **Syntax:** All 6 pass
- **New files:** setup.sh (one-command install)
- **Backup:** ~/.ai_staging/BACKUP/pre-portable-20260910/
