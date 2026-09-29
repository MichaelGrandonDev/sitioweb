"""Carga la biblioteca extraída (~/Desktop/material-mtc/_extraido) en la Biblioteca MTC del servidor: solo texto + página.

Idempotente por sha256: los documentos ya importados y listos se saltean (usar --force para reimportarlos).
Uso:  .venv/bin/python import_library.py [--dry-run] [--force] [--only TEXTO]
"""

from __future__ import annotations

import argparse
import json
import sys

from common import BridgeHTTPError, iter_library_docs, library_dir, load_env, pages_of, server_from_env

MAX_PAGES = 150
MAX_BYTES = 1_500_000


def batches(pages: dict[int, str]):
    batch, size = [], 0
    for page, text in pages.items():
        item = {"page": page, "text": text}
        n = len(json.dumps(item, ensure_ascii=False).encode("utf-8"))
        if batch and (len(batch) >= MAX_PAGES or size + n > MAX_BYTES):
            yield batch
            batch, size = [], 0
        batch.append(item)
        size += n
    if batch:
        yield batch


def main() -> int:
    ap = argparse.ArgumentParser(description="Importa la biblioteca extraída al servidor (solo texto).")
    ap.add_argument("--dry-run", action="store_true", help="muestra qué haría, sin subir nada")
    ap.add_argument("--force", action="store_true", help="reimporta aunque ya esté")
    ap.add_argument("--only", default="", help="solo documentos cuyo título o archivo contenga este texto")
    args = ap.parse_args()

    env = load_env()
    lib = library_dir(env)
    docs = list(iter_library_docs(lib))
    if not docs:
        print(f"No hay documentos extraídos en {lib}. Cuando termine la extracción, volvé a correr este script.")
        return 1
    server = None if args.dry_run else server_from_env(env)
    existing = {}
    if server:
        existing = {d["sha256"]: d for d in server.post({"action": "library"}).get("docs", [])}

    done = skipped = failed = 0
    for path, doc in docs:
        title = str(doc.get("title") or doc.get("source_file") or path.stem)
        if args.only and args.only.lower() not in (title + " " + str(doc.get("source_file") or "")).lower():
            continue
        sha = str(doc.get("sha256") or "").lower()
        pages = pages_of(doc)
        if len(sha) != 64:
            print(f"  ✗ {title}: sin sha256 válido, se saltea")
            failed += 1
            continue
        if not args.force and existing.get(sha, {}).get("status") == "listo":
            print(f"  = {title}: ya estaba ({existing[sha].get('pages')} p.)")
            skipped += 1
            continue
        if args.dry_run:
            print(f"  + {title}: {len(pages)} páginas con texto")
            done += 1
            continue
        try:
            meta = {
                "sha256": sha,
                "title": title,
                "source_file": str(doc.get("source_file") or path.name),
                "kind": str(doc.get("kind") or "pdf"),
                "tags": doc.get("tags") or [],
                "pages": int(doc.get("pages") or (max(pages) if pages else 0)),
                "force": args.force,
            }
            begin = server.post({"action": "import_begin", "doc": meta})
            if begin.get("skip"):
                print(f"  = {title}: ya estaba")
                skipped += 1
                continue
            doc_id = begin["doc_id"]
            sent = 0
            for batch in batches(pages):
                server.post({"action": "import_pages", "doc_id": doc_id, "pages": batch}, timeout=120)
                sent += len(batch)
            fin = server.post({"action": "import_finish", "doc_id": doc_id, "pages": meta["pages"]})
            print(f"  ✓ {title}: {sent} páginas ({fin.get('status')})")
            done += 1
        except BridgeHTTPError as err:
            print(f"  ✗ {title}: el servidor respondió {err.status} {err.message}")
            failed += 1
        except Exception as err:
            print(f"  ✗ {title}: {type(err).__name__}")
            failed += 1
    print(f"Listo: {done} importados, {skipped} ya estaban, {failed} con error.")
    return 0 if failed == 0 else 2


if __name__ == "__main__":
    sys.exit(main())
