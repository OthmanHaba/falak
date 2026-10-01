"""kiln-fn-install: installs a function's Python dependencies into /app/.venv.

Dependencies come from a PEP 723 inline script metadata block in the entrypoint (``# /// script`` …
``dependencies = [...]`` … ``# ///``) and/or a ``requirements.txt``. They are resolved once into
``/app/requirements.lock``; when that file already exists (the agent restores it for the same code), exactly those
versions are installed. The virtualenv is created with ``--system-site-packages`` so the image's uvicorn is
visible. The package cache is /cache/uv (shared by every release on the server).
"""

from __future__ import annotations

import os
import re
import subprocess
import sys
import tomllib
from pathlib import Path

APP = Path(os.getcwd())
VENV = APP / ".venv"
LOCK = APP / "requirements.lock"

# PEP 723 reference regex.
BLOCK = re.compile(r"(?m)^# /// (?P<type>[a-zA-Z0-9-]+)$\s(?P<content>(^#(| .*)$\s)+)^# ///$")


def script_dependencies(source: str) -> list[str]:
    """The ``dependencies`` of the PEP 723 ``script`` block, or [] when there is none."""
    blocks = [m for m in BLOCK.finditer(source) if m.group("type") == "script"]
    if len(blocks) > 1:
        fail("more than one `# /// script` block")
    if not blocks:
        return []
    content = "".join(
        line[2:] if line.startswith("# ") else line[1:]
        for line in blocks[0].group("content").splitlines(keepends=True)
    )
    try:
        meta = tomllib.loads(content)
    except tomllib.TOMLDecodeError as err:
        fail(f"the `# /// script` block is not valid TOML: {err}")
    deps = meta.get("dependencies", [])
    if not isinstance(deps, list) or not all(isinstance(d, str) for d in deps):
        fail("`dependencies` in the `# /// script` block must be a list of strings")
    return deps


def fail(message: str) -> None:
    print(f"kiln: {message}", file=sys.stderr)
    sys.exit(1)


def uv(*args: str) -> None:
    print("$ uv " + " ".join(args), flush=True)
    result = subprocess.run(["uv", *args], cwd=APP)
    if result.returncode != 0:
        sys.exit(result.returncode)


def main() -> None:
    entry = os.environ.get("KILN_ENTRYPOINT", "main.py")
    path = APP / entry
    if not path.is_file():
        fail(f"entrypoint {entry} not found")

    deps = script_dependencies(path.read_text(encoding="utf-8"))
    requirements = APP / "requirements.txt"

    uv("venv", str(VENV), "--system-site-packages", "--python", sys.executable, "--quiet", "--allow-existing")
    python = str(VENV / "bin" / "python")

    if LOCK.is_file():
        print("kiln: installing the locked dependency versions (requirements.lock)", flush=True)
    elif deps or requirements.is_file():
        sources = ", ".join(filter(None, ["the # /// script block" if deps else "", "requirements.txt" if requirements.is_file() else ""]))
        print(f"kiln: dependencies from {sources}", flush=True)
        spec = Path("/tmp/kiln-requirements.in")
        lines = list(deps)
        if requirements.is_file():
            lines.append(f"-r {requirements}")
        spec.write_text("\n".join(lines) + "\n", encoding="utf-8")
        uv("pip", "compile", str(spec), "--python", python, "--output-file", str(LOCK), "--no-header", "--quiet")
    else:
        print("kiln: no dependencies", flush=True)
        return

    uv("pip", "install", "--python", python, "--requirement", str(LOCK))


if __name__ == "__main__":
    main()
