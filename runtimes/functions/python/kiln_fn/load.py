"""Loads the function's entrypoint ($KILN_ENTRYPOINT, main.py) from /app as a module."""

from __future__ import annotations

import importlib.util
import os
import sys
from pathlib import Path
from types import ModuleType


def entrypoint() -> str:
    return os.environ.get("KILN_ENTRYPOINT", "main.py")


def load() -> ModuleType:
    entry = entrypoint()
    path = Path(os.getcwd()) / entry
    if not path.is_file():
        print(f"kiln: entrypoint {entry} not found", file=sys.stderr)
        sys.exit(1)
    # Sibling modules (`from helpers import x`) resolve from the entrypoint's directory.
    sys.path.insert(0, str(path.parent))
    name = path.stem
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        print(f"kiln: cannot load {entry}", file=sys.stderr)
        sys.exit(1)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    try:
        spec.loader.exec_module(module)
    except Exception:
        import traceback

        print(f"kiln: failed to load {entry}:", file=sys.stderr)
        traceback.print_exc()
        sys.exit(1)
    return module
