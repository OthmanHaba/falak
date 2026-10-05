#!/usr/bin/env python3
"""One-shot Kiln -> Falak rename: file contents, then paths (deepest first, via git mv)."""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(subprocess.check_output(["git", "rev-parse", "--show-toplevel"], text=True).strip())

ORDERED = [
    (re.compile(r"github\.com/kiln/agent"), "github.com/OthmanHaba/falak/agent"),
    (re.compile(r"OthmanHaba/kiln-website"), "OthmanHaba/falak-website"),
    (re.compile(r"OthmanHaba/kiln\b"), "OthmanHaba/falak"),
    (re.compile(r"othmanhaba/kiln"), "othmanhaba/falak"),
    (re.compile(r"https://kiln\.dev\b"), "https://falak.sh"),
    (re.compile(r"\bkiln\.dev\b"), "falak.sh"),
    (re.compile(r"KILN"), "FALAK"),
    (re.compile(r"Kiln"), "Falak"),
    (re.compile(r"kiln"), "falak"),
]

SKIP_DIRS = {"vendor", "node_modules", ".git"}
SKIP_FILES = {".gitleaksignore", "rename.py", "RENAME_FALAK.md"}
BINARY_EXT = {".png", ".jpg", ".jpeg", ".gif", ".ico", ".webp", ".woff", ".woff2", ".ttf", ".otf", ".pdf", ".zip", ".gz", ".mp4"}


def tracked():
    out = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    return [p for p in out.decode().split("\0") if p]


def skipped(rel: str) -> bool:
    parts = rel.split("/")
    return bool(SKIP_DIRS.intersection(parts)) or parts[-1] in SKIP_FILES or Path(rel).suffix.lower() in BINARY_EXT


def rewrite(text: str) -> str:
    for pattern, repl in ORDERED:
        text = pattern.sub(repl, text)
    return text


def main() -> int:
    files = tracked()
    changed = 0
    for rel in files:
        if skipped(rel):
            continue
        path = ROOT / rel
        if path.is_symlink() or not path.is_file():
            continue
        raw = path.read_bytes()
        if b"\0" in raw or not re.search(rb"(?i)kiln", raw):
            continue
        text = raw.decode("utf-8")
        new = rewrite(text)
        if new != text:
            path.write_bytes(new.encode("utf-8"))
            changed += 1
    print(f"contents: {changed} files")

    # Paths: rename every path component containing kiln, deepest first.
    comps = set()
    for rel in files:
        if skipped(rel) and Path(rel).name not in {"rename.py"}:
            pass
        parts = rel.split("/")
        for i in range(len(parts)):
            if re.search(r"(?i)kiln", parts[i]):
                comps.add("/".join(parts[: i + 1]))
    moved = 0
    for old in sorted(comps, key=lambda p: (-p.count("/"), p)):
        if "tools/rebrand" in old:
            continue
        parent, _, name = old.rpartition("/")
        new = (parent + "/" if parent else "") + rewrite(name)
        subprocess.check_call(["git", "mv", old, new], cwd=ROOT)
        moved += 1
    print(f"paths: {moved} moved")
    return 0


if __name__ == "__main__":
    sys.exit(main())
