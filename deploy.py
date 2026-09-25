#!/usr/bin/env python3
"""Sube public_html/ al Document Root de HostGator por FTP.

IMPORTANTE: la cuenta FTP debe tener como directorio home:
  /home2/mikedeve/fluxusterapia.com
(cPanel → Cuentas FTP → directorio de la cuenta)

Uso:
  python3 deploy.py
  python3 watch_deploy.py   # auto al guardar cambios
"""

from __future__ import annotations

import os
import sys
import time
from ftplib import FTP, FTP_TLS, error_perm
from pathlib import Path

ROOT = Path(__file__).resolve().parent
LOCAL_DIR = ROOT / "public_html"
ENV_FILE = ROOT / ".env"
LOCK_FILE = ROOT / ".deploy.lock"

SKIP_NAMES = {".DS_Store", ".git", "__pycache__", ".env", ".ftpquota"}
SKIP_SUFFIXES = {".sqlite", ".sqlite-journal", ".pyc", ".log"}


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def require_config() -> dict:
    env = {**os.environ, **load_env(ENV_FILE)}
    needed = ["FTP_HOST", "FTP_USER", "FTP_PASS"]
    missing = [k for k in needed if not env.get(k)]
    if missing:
        print("Faltan variables en .env:", ", ".join(missing))
        sys.exit(1)
    remote = env.get("FTP_REMOTE_DIR", "/").strip() or "/"
    if remote != "/" and not remote.startswith("/"):
        remote = "/" + remote
    return {
        "host": env["FTP_HOST"],
        "user": env["FTP_USER"],
        "password": env["FTP_PASS"],
        "port": int(env.get("FTP_PORT", "21")),
        "remote": remote.rstrip("/") or "/",
        "tls": env.get("FTP_TLS", "1") not in {"0", "false", "False", "no"},
    }


def sync_local_assets() -> None:
    mapping = [
        (ROOT / "static/css/style.css", LOCAL_DIR / "css/style.css"),
        (ROOT / "static/js/main.js", LOCAL_DIR / "js/main.js"),
        (ROOT / "static/img/logo.png", LOCAL_DIR / "img/logo.png"),
        (ROOT / "static/img/logo-circle.png", LOCAL_DIR / "img/logo-circle.png"),
    ]
    for src, dst in mapping:
        if src.exists():
            dst.parent.mkdir(parents=True, exist_ok=True)
            dst.write_bytes(src.read_bytes())
            print(f"  sync {src.relative_to(ROOT)} → {dst.relative_to(ROOT)}")


def should_skip(path: Path, local_root: Path) -> bool:
    rel_parts = path.relative_to(local_root).parts
    if any(part in SKIP_NAMES for part in rel_parts):
        return True
    if path.suffix.lower() in SKIP_SUFFIXES:
        return True
    # Allow .htaccess; skip other dotfiles
    if path.name.startswith(".") and path.name not in {".htaccess"}:
        return True
    # Keep live secrets on the server; repo uses CHANGE_ME placeholders
    if path.name == "config.php" and path.is_file():
        return True
    return False


def connect(cfg: dict) -> FTP:
    if cfg["tls"]:
        ftp: FTP = FTP_TLS()
        ftp.connect(cfg["host"], cfg["port"], timeout=60)
        ftp.login(cfg["user"], cfg["password"])
        try:
            ftp.prot_p()
        except Exception:
            pass
    else:
        ftp = FTP()
        ftp.connect(cfg["host"], cfg["port"], timeout=60)
        ftp.login(cfg["user"], cfg["password"])
    ftp.set_pasv(True)
    return ftp


def ensure_dir(ftp: FTP, path: str) -> None:
    if path in {"", "/"}:
        ftp.cwd("/")
        return
    parts = [p for p in path.split("/") if p]
    current = ""
    for part in parts:
        current += "/" + part
        try:
            ftp.cwd(current)
        except error_perm:
            try:
                ftp.mkd(current)
            except error_perm:
                pass
            ftp.cwd(current)


def remote_join(remote_root: str, rel: str) -> str:
    if remote_root in {"", "/"}:
        return "/" + rel if rel else "/"
    return f"{remote_root.rstrip('/')}/{rel}"


def upload_file(ftp: FTP, local: Path, remote_path: str) -> None:
    with local.open("rb") as handle:
        ftp.storbinary(f"STOR {remote_path}", handle)
    try:
        ftp.sendcmd(f"SITE CHMOD 644 {remote_path}")
    except Exception:
        pass
    print(f"  ↑ {remote_path}")


def upload_tree(ftp: FTP, local_root: Path, remote_root: str) -> int:
    if remote_root in {"", "/"}:
        ftp.cwd("/")
    else:
        ensure_dir(ftp, remote_root)
    count = 0
    for path in sorted(local_root.rglob("*")):
        if should_skip(path, local_root):
            continue
        rel = path.relative_to(local_root).as_posix()
        remote_path = remote_join(remote_root, rel)
        if path.is_dir():
            ensure_dir(ftp, remote_path)
            try:
                ftp.sendcmd(f"SITE CHMOD 755 {remote_path}")
            except Exception:
                pass
            continue
        parent = remote_path.rsplit("/", 1)[0] or "/"
        ensure_dir(ftp, parent)
        upload_file(ftp, path, remote_path)
        count += 1
    return count


def acquire_lock() -> bool:
    if LOCK_FILE.exists():
        age = time.time() - LOCK_FILE.stat().st_mtime
        if age < 120:
            print("Deploy ya en curso, salteo.")
            return False
    LOCK_FILE.write_text(str(os.getpid()), encoding="utf-8")
    return True


def release_lock() -> None:
    try:
        LOCK_FILE.unlink(missing_ok=True)
    except Exception:
        pass


def deploy() -> int:
    if not LOCAL_DIR.is_dir():
        print(f"No existe {LOCAL_DIR}")
        return 1
    if not acquire_lock():
        return 0
    try:
        cfg = require_config()
        print("1) Sincronizando assets locales…")
        sync_local_assets()
        print(f"2) Conectando a {cfg['host']}:{cfg['port']}…")
        ftp = connect(cfg)
        try:
            print(f"   PWD FTP: {ftp.pwd()}")
            print(f"3) Subiendo {LOCAL_DIR.name}/ → {cfg['remote']} …")
            count = upload_tree(ftp, LOCAL_DIR, str(cfg["remote"]))
            print(f"Listo: {count} archivos → https://fluxusterapia.com")
            return 0
        finally:
            try:
                ftp.quit()
            except Exception:
                ftp.close()
    finally:
        release_lock()


def main() -> None:
    raise SystemExit(deploy())


if __name__ == "__main__":
    main()
