#!/usr/bin/env python3
"""Biblioteca del admin de turnos: sube libros y archivos de código por FTP (reusa deploy.py).

deploy.py nunca sube carpetas data/: los libros van a public_html/turnos/data/biblioteca/<sha256>.<ext>
(carpeta denegada por .htaccess) y se registran solos al abrir /turnos/biblioteca_lector.php, con los
datos de data/biblioteca/import.json (título, tipo, etiquetas, páginas, nombre original).

Uso:
  python3 biblioteca_upload.py space                    # espacio libre en el hosting (script PHP temporal)
  python3 biblioteca_upload.py code RUTA [RUTA...]      # sube archivos/carpetas de public_html/ (rutas del repo)
  python3 biblioteca_upload.py books [CARPETA]          # sube los libros (por defecto ~/Desktop/material-mtc)
       --dry-run  muestra qué subiría sin subir nada
"""

from __future__ import annotations

import hashlib
import io
import json
import re
import secrets
import shutil
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.request
from ftplib import error_perm
from pathlib import Path

from deploy import LOCAL_DIR, connect, ensure_dir, remote_join, require_config, upload_file

REMOTE_BOOKS = "turnos/data/biblioteca"
SITE = "https://fluxusterapia.com"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36"
BOOK_EXTS = {".pdf", ".pptx", ".ppt", ".jpg", ".jpeg", ".png"}
DEFAULT_SRC = Path.home() / "Desktop" / "material-mtc"

TITLE_OVERRIDES = {
    "met diag 1": "Métodos de diagnóstico 1",
    "met diag 2": "Métodos de diagnóstico 2",
    "fund mtc": "Fundamentos de MTC",
    "anat canales": "Anatomía de los canales",
    "fisiologiaa": "Fisiología",
    "etiologia": "Etiología",
    "moxib": "Moxibustión",
    "plan ter": "Plan terapéutico",
    "qi gong medico jaime": "Qi Gong médico (Jaime)",
    "qigong": "Qigong",
    "ventosas": "Ventosas",
    "su wen": "Su Wen",
    "a190840b-8baa-4846-bc1d-ec4dba265765": "Gráfico de reflexología auricular",
}
FILE_TAGS = {"a190840b-8baa-4846-bc1d-ec4dba265765": ["auriculoterapia", "puntos"]}
TAG_WORDS = {
    "acupuntura": "acupuntura", "atlas": "atlas", "diag": "diagnóstico", "qigong": "qigong", "qi gong": "qigong",
    "moxib": "moxibustión", "ventosa": "ventosas", "fund": "fundamentos", "psique": "psique", "emocion": "emociones",
    "fisiolog": "fisiología", "etiolog": "etiología", "su wen": "clásicos", "canales": "meridianos/canales",
    "plan ter": "plan terapéutico", "secador": "terapia del secador",
}


def http_get(url: str) -> tuple[int, bytes]:
    ctx = ssl.create_default_context(cafile="/etc/ssl/cert.pem") if Path("/etc/ssl/cert.pem").exists() else None
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=60) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def cmd_space() -> None:
    cfg = require_config()
    token = secrets.token_hex(16)
    name = f"bibspace-{secrets.token_hex(6)}.php"
    php = f"""<?php
if (!hash_equals('{token}', (string) ($_GET['t'] ?? ''))) {{ http_response_code(404); exit; }}
@unlink(__FILE__);
header('Content-Type: application/json');
$n = static fn ($v) => is_numeric($v) ? (float) $v : null;
$out = ['fs_free' => $n(@disk_free_space(__DIR__)), 'fs_total' => $n(@disk_total_space(__DIR__))];
$q = function_exists('shell_exec') ? (string) @shell_exec('quota -w 2>/dev/null') : '';
if (preg_match_all('/^\\s*\\S+\\s+(\\d+)\\*?\\s+(\\d+)\\s+(\\d+)\\s+\\S*\\s*(\\d+)\\*?\\s+(\\d+)\\s+(\\d+)/m', $q, $all, PREG_SET_ORDER)) {{
    usort($all, static fn ($a, $b) => (int) $b[1] <=> (int) $a[1]);
    $m = $all[0];
    $out += ['quota_used_kb' => (int) $m[1], 'quota_soft_kb' => (int) $m[2], 'quota_hard_kb' => (int) $m[3],
             'inodes_used' => (int) $m[4], 'inodes_soft' => (int) $m[5], 'inodes_hard' => (int) $m[6], 'quota_lines' => count($all)];
}}
echo json_encode($out);
"""
    ftp = connect(cfg)
    remote = remote_join(cfg["remote"], f"turnos/{name}")
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as tmp:
        tmp.write(php)
    try:
        upload_file(ftp, Path(tmp.name), remote)
        status, body = http_get(f"{SITE}/turnos/{name}?t={token}")
    finally:
        Path(tmp.name).unlink(missing_ok=True)
        try:
            ftp.delete(remote)
        except error_perm:
            pass
        ftp.quit()
    try:
        data = json.loads(body.decode("utf-8", "replace"))
    except ValueError:
        print(f"HTTP {status}: respuesta no numérica")
        sys.exit(1)
    gb = lambda b: round(b / 1024**3, 2) if b is not None else None  # noqa: E731
    print(json.dumps({
        "fs_free_gb": gb(data.get("fs_free")),
        "fs_total_gb": gb(data.get("fs_total")),
        "quota_used_mb": round(data["quota_used_kb"] / 1024) if "quota_used_kb" in data else None,
        "quota_hard_mb": round(data["quota_hard_kb"] / 1024) if data.get("quota_hard_kb") else ("sin límite" if "quota_hard_kb" in data else None),
        "inodes_used": data.get("inodes_used"),
        "inodes_hard": data.get("inodes_hard") or ("sin límite" if "inodes_hard" in data else None),
        "quota_lines": data.get("quota_lines"),
    }, ensure_ascii=False))


def cmd_code(paths: list[str]) -> None:
    root = LOCAL_DIR.parent
    files: list[Path] = []
    for p in paths:
        path = (root / p).resolve()
        if LOCAL_DIR not in path.parents:
            print(f"Fuera de public_html: {p}")
            sys.exit(1)
        files += [f for f in sorted(path.rglob("*")) if f.is_file()] if path.is_dir() else [path]
    files = [f for f in files if f.name != ".DS_Store" and f.name != "config.php"]
    cfg = require_config()
    ftp = connect(cfg)
    try:
        for f in files:
            rel = f.relative_to(LOCAL_DIR).as_posix()
            remote = remote_join(cfg["remote"], rel)
            ensure_dir(ftp, remote.rsplit("/", 1)[0] or "/")
            upload_file(ftp, f, remote)
    finally:
        ftp.quit()
    print(f"{len(files)} archivos subidos.")


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for block in iter(lambda: fh.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def clean_title(filename: str) -> str:
    t = Path(filename).stem
    if t.lower() in TITLE_OVERRIDES:
        return TITLE_OVERRIDES[t.lower()]
    if re.fullmatch(r"[0-9a-fA-F-]{30,}", t):
        return "Imagen de referencia"
    author = ""
    m = re.search(r"\{([^{}]*[A-Za-zÁÉÍÓÚáéíóúñ][^{}]*)\}", t)
    if m:
        author = m.group(1).strip()
    year = re.search(r"\((\d{4})[,)]", t)
    t = re.sub(r"\{[^{}]*\}|\([^()]*\)", " ", t)
    t = re.sub(r"(?i)libgen\.\w+|^(toaz\.info|feismo\.com|pdfcoffee\.com)[-_]|[-_]pr_[0-9a-f]{16,}|[-_][0-9a-f]{24,}|-pdf-free$", " ", t)
    t = t.replace("...", " ").replace("_ ", ": ")
    if "-" in t and " " not in t.strip():
        t = t.replace("-", " ")
    t = re.sub(r"[_\s]+", " ", t).strip(" -.:")
    key = t.lower()
    if key in TITLE_OVERRIDES:
        t = TITLE_OVERRIDES[key]
    elif t and t[0].islower():
        t = t[0].upper() + t[1:]
    if author:
        t += f" · {author}"
    if year:
        t += f" ({year.group(1)})"
    return t[:200] or filename


def guess_tags(filename: str) -> list[str]:
    low = filename.lower()
    return list(dict.fromkeys(tag for word, tag in TAG_WORDS.items() if word in low))[:6]


def guess_kind(filename: str, ext: str) -> str:
    low = filename.lower()
    if ext in {".jpg", ".jpeg", ".png"}:
        return "imagen"
    if "atlas" in low:
        return "atlas"
    if Path(filename).stem.lower() in TITLE_OVERRIDES and "su wen" not in low:
        return "apuntes"
    return "libro"


def count_pages(path: Path) -> int:
    if path.suffix.lower() != ".pdf":
        return 1 if path.suffix.lower() in {".jpg", ".jpeg", ".png"} else 0
    try:
        from pypdf import PdfReader
        return len(PdfReader(str(path)).pages)
    except Exception:
        pass
    try:
        out = subprocess.run(["mdls", "-raw", "-name", "kMDItemNumberOfPages", str(path)], capture_output=True, text=True, timeout=30).stdout
        return int(out) if out.strip().isdigit() else 0
    except Exception:
        return 0


def load_manifest(src: Path) -> dict[str, dict]:
    path = src / "_extraido" / "manifest.json"
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except ValueError:
        return {}
    docs = data.get("documents", data) if isinstance(data, dict) else data
    out: dict[str, dict] = {}
    for d in docs if isinstance(docs, list) else []:
        if isinstance(d, dict) and d.get("sha256"):
            out[d["sha256"]] = d
    return out


def convert_to_pdf(path: Path, outdir: Path) -> Path | None:
    soffice = shutil.which("soffice") or shutil.which("libreoffice")
    app = Path("/Applications/LibreOffice.app/Contents/MacOS/soffice")
    soffice = soffice or (str(app) if app.exists() else None)
    if not soffice:
        return None
    subprocess.run([soffice, "--headless", "--convert-to", "pdf", "--outdir", str(outdir), str(path)], capture_output=True, timeout=600)
    pdf = outdir / (path.stem + ".pdf")
    return pdf if pdf.exists() else None


def cmd_books(src: Path, dry: bool) -> None:
    manifest = load_manifest(src)
    tmpdir = Path(tempfile.mkdtemp(prefix="bib-"))
    items: dict[str, dict] = {}
    skipped: list[str] = []
    for f in sorted(src.iterdir()):
        ext = f.suffix.lower()
        if not f.is_file() or f.name.startswith(".") or ext not in BOOK_EXTS:
            if f.is_file() and not f.name.startswith("."):
                skipped.append(f"{f.name} (formato)")
            continue
        if f.stat().st_size == 0:
            skipped.append(f"{f.name} (vacío o descargando)")
            continue
        original = f
        if ext in {".pptx", ".ppt"}:
            pdf = convert_to_pdf(f, tmpdir)
            if pdf:
                f, ext = pdf, ".pdf"
        digest = sha256(original)
        meta = manifest.get(digest, {})
        file_digest = digest if f == original else sha256(f)
        if file_digest in items:
            skipped.append(f"{original.name} (duplicado)")
            continue
        title = str(meta.get("title") or "").strip()
        if not title or title == original.stem or re.search(r"[{}]|libgen|^[0-9a-f-]{30,}$", title):
            title = clean_title(original.name)
        tags = [str(t) for t in meta.get("tags", [])] if meta.get("tags") else FILE_TAGS.get(original.stem) or guess_tags(original.name)
        if ext in {".jpg", ".jpeg", ".png"}:
            meta = {**meta, "kind": "imagen"}
        items[file_digest] = {
            "path": f,
            "ext": ext.lstrip("."),
            "size": f.stat().st_size,
            "meta": {
                "title": title[:200],
                "kind": str(meta.get("kind") or guess_kind(original.name, ext)),
                "tags": tags[:12],
                "pages": int(meta.get("pages") or 0) or count_pages(f),
                "original_name": original.name,
                "source_sha256": digest,
            },
        }
    total = sum(i["size"] for i in items.values())
    print(f"{len(items)} documentos únicos · {total / 1048576:.1f} MB · manifest: {'sí' if manifest else 'no'}")
    for d, i in items.items():
        print(f"  {d[:10]} {i['size'] / 1048576:7.1f} MB  {i['meta']['pages']:5d} p  [{i['meta']['kind']}] {i['meta']['title']}  {i['meta']['tags']}")
    for s in skipped:
        print(f"  salteado: {s}")
    if dry:
        return

    cfg = require_config()
    ftp = connect(cfg)
    remote_dir = remote_join(cfg["remote"], REMOTE_BOOKS)
    uploaded, present, failed = 0, 0, []
    try:
        ensure_dir(ftp, remote_dir)
        ftp.voidcmd("TYPE I")
        existing: dict = {}
        buf = io.BytesIO()
        try:
            ftp.retrbinary(f"RETR {remote_dir}/import.json", buf.write)
            existing = json.loads(buf.getvalue().decode("utf-8"))
        except (error_perm, ValueError):
            existing = {}
        merged = {**existing, **{d: i["meta"] for d, i in items.items()}}
        imp = tmpdir / "import.json"
        imp.write_text(json.dumps(merged, ensure_ascii=False, indent=1), encoding="utf-8")
        upload_file(ftp, imp, f"{remote_dir}/import.json")
        for d, i in items.items():
            remote = f"{remote_dir}/{d}.{i['ext']}"
            try:
                if ftp.size(remote) == i["size"]:
                    present += 1
                    continue
            except error_perm:
                pass
            for attempt in range(3):
                try:
                    t0 = time.time()
                    upload_file(ftp, i["path"], remote)
                    print(f"    {i['size'] / 1048576:.1f} MB en {time.time() - t0:.0f} s")
                    uploaded += 1
                    break
                except Exception as e:  # noqa: BLE001
                    print(f"    reintento {attempt + 1}: {type(e).__name__}")
                    try:
                        ftp.quit()
                    except Exception:  # noqa: BLE001
                        pass
                    ftp = connect(cfg)
                    ftp.voidcmd("TYPE I")
            else:
                failed.append(i["meta"]["original_name"])
    finally:
        try:
            ftp.quit()
        except Exception:  # noqa: BLE001
            pass
        shutil.rmtree(tmpdir, ignore_errors=True)
    print(json.dumps({"subidos": uploaded, "ya_estaban": present, "fallidos": failed, "total_mb": round(total / 1048576, 1)}, ensure_ascii=False))


def main() -> None:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        print(__doc__)
        sys.exit(1)
    if args[0] == "space":
        cmd_space()
    elif args[0] == "code" and len(args) > 1:
        cmd_code(args[1:])
    elif args[0] == "books":
        cmd_books(Path(args[1]).expanduser() if len(args) > 1 else DEFAULT_SRC, "--dry-run" in sys.argv)
    else:
        print(__doc__)
        sys.exit(1)


if __name__ == "__main__":
    main()
