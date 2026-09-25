#!/usr/bin/env python3
"""Vigila public_html/ y static/ y despliega a HostGator al guardar.

Uso:
  python3 watch_deploy.py
"""

from __future__ import annotations

import time
from pathlib import Path

import deploy as deploy_mod

ROOT = Path(__file__).resolve().parent
WATCH_DIRS = [ROOT / "public_html", ROOT / "static"]
DEBOUNCE_SEC = 3.0
SKIP_NAMES = {".DS_Store", ".git", "__pycache__", ".env", ".deploy.lock"}
SKIP_SUFFIXES = {".sqlite", ".sqlite-journal", ".pyc", ".log"}


def tracked(path: Path) -> bool:
    if any(part in SKIP_NAMES for part in path.parts):
        return False
    if path.suffix.lower() in SKIP_SUFFIXES:
        return False
    if path.name.startswith(".") and path.name not in {".htaccess"}:
        return False
    return True


def snapshot() -> dict[str, float]:
    files: dict[str, float] = {}
    for folder in WATCH_DIRS:
        if not folder.exists():
            continue
        for path in folder.rglob("*"):
            if not path.is_file() or not tracked(path):
                continue
            try:
                files[str(path)] = path.stat().st_mtime
            except OSError:
                pass
    return files


def main() -> None:
    print("Watch deploy activo.")
    print("Carpetas:", ", ".join(str(p.relative_to(ROOT)) for p in WATCH_DIRS))
    print("Ctrl+C para detener.\n")
    previous = snapshot()
    pending_since: float | None = None

    while True:
        time.sleep(1.0)
        current = snapshot()
        if current != previous:
            pending_since = time.time()
            previous = current
            changed = len(current)
            print(f"Cambio detectado ({changed} archivos vigilados)…")
            continue
        if pending_since is not None and (time.time() - pending_since) >= DEBOUNCE_SEC:
            print("Publicando en HostGator…")
            code = deploy_mod.deploy()
            print("OK publicado.\n" if code == 0 else "Falló el deploy. Revisá .env / FTP.\n")
            pending_since = None


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nWatch detenido.")
