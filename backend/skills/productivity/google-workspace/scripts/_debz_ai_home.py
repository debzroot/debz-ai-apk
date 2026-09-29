"""Resolve DEBZ_AI_HOME for standalone skill scripts.

Skill scripts may run outside the Debz AI process (e.g. system Python,
nix env, CI) where ``debz_ai_constants`` is not importable.  This module
provides the same ``get_debz_ai_home()`` and ``display_debz_ai_home()``
contracts as ``debz_ai_constants`` without requiring it on ``sys.path``.

When ``debz_ai_constants`` IS available it is used directly so that any
future enhancements (profile resolution, Docker detection, etc.) are
picked up automatically.  The fallback path replicates the core logic
from ``debz_ai_constants.py`` using only the stdlib.

All scripts under ``google-workspace/scripts/`` should import from here
instead of duplicating the ``DEBZ_AI_HOME = Path(os.getenv(...))`` pattern.
"""

from __future__ import annotations

import os
from pathlib import Path

try:
    from debz_ai_constants import display_debz_ai_home as display_debz_ai_home
    from debz_ai_constants import get_debz_ai_home as get_debz_ai_home
except (ModuleNotFoundError, ImportError):

def get_debz_ai_home() -> Path:
        """Return the Debz AI home directory (default: ~/debz-ai).

        Mirrors ``debz_ai_constants.get_debz_ai_home()``."""
        val = os.environ.get("DEBZ_AI_HOME", "").strip()
        return Path(val) if val else Path.home() / "debz-ai"

    def display_debz_ai_home() -> str:
        """Return a user-friendly ``~/``-shortened display string.

        Mirrors ``debz_ai_constants.display_debz_ai_home()``."""
        home = get_debz_ai_home()
        try:
            return "~/" + str(home.relative_to(Path.home()))
        except ValueError:
            return str(home)
