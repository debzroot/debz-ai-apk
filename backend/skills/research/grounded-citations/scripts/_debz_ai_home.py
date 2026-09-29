"""Resolve DEBZ_AI_HOME for standalone skill scripts.

Skill scripts may run outside the Debz AI process (system Python, nix env,
CI) where ``debz_ai_constants`` is not importable.  This module provides the
same ``get_debz_ai_home()`` contract without requiring it on ``sys.path``.

When ``debz_ai_constants`` IS available it is used directly so profile
resolution and any future enhancements are picked up automatically.
"""

from __future__ import annotations

import os
from pathlib import Path

try:
    from debz_ai_constants import get_debz_ai_home as get_debz_ai_home
except (ModuleNotFoundError, ImportError):

    def get_debz_ai_home() -> Path:
        """Return the Debz AI home directory (default: ``~/debz-ai``)."""
        val = os.environ.get("DEBZ_AI_HOME", "").strip()
        return Path(val) if val else Path.home() / "debz-ai"
