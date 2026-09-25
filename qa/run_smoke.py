#!/usr/bin/env python3
"""Smoke QA · velocidad, fluidez, accesos y privacidad

Uso:
  python3 qa/run_smoke.py
  python3 qa/run_smoke.py --base https://www.fluxusterapia.com

Genera:
  qa/reports/smoke-YYYYMMDD-HHMMSS.md|.json
  qa/reports/smoke-latest.md
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPORTS = Path(__file__).resolve().parent / "reports"
DEFAULT_BASE = "https://www.fluxusterapia.com"

# Umbrales de velocidad (segundos)
TTFB_WARN = 0.80
TTFB_FAIL = 2.00
TOTAL_FAIL = 4.00

SENSITIVE_RE = re.compile(
    r"admin_pass|FluxusAdmin|FluxusTurnos|FluxusMigrate|FTP_PASS|FTP_USER|"
    r"password_hash|BEGIN TRANSACTION|SQLite format|migrate_key",
    re.I,
)


class Check:
    def __init__(self, area: str, name: str, ok: bool, severity: str, detail: str = ""):
        self.area = area
        self.name = name
        self.ok = ok
        self.severity = severity
        self.detail = detail

    def as_dict(self) -> dict:
        return {
            "area": self.area,
            "name": self.name,
            "ok": self.ok,
            "severity": self.severity,
            "detail": self.detail,
        }


def curl(url: str, timeout: int = 25, method: str = "GET", include_headers: bool = False) -> dict:
    cmd = [
        "/usr/bin/curl",
        "-sS",
        "-L",
        "--max-time",
        str(timeout),
        "-A",
        "FluxusSmoke/1.0",
        "-w",
        "\n__META__:%{http_code}|%{time_starttransfer}|%{time_total}|%{size_download}|%{url_effective}",
        "-o",
        "-",
    ]
    if include_headers:
        cmd.insert(1, "-D")
        cmd.insert(2, "-")
    if method != "GET":
        cmd.extend(["-X", method])
    cmd.append(url)
    try:
        proc = subprocess.run(cmd, capture_output=True, timeout=timeout + 5)
    except Exception as exc:
        return {"status": 0, "ttfb": 0.0, "total": 0.0, "size": 0, "body": b"", "url": url, "error": str(exc)}
    out = proc.stdout
    marker = b"\n__META__:"
    headers = b""
    if include_headers and b"\r\n\r\n" in out:
        # With -D -, headers go to stdout before body; meta still at end
        pass
    if marker not in out:
        return {
            "status": 0,
            "ttfb": 0.0,
            "total": 0.0,
            "size": 0,
            "body": proc.stderr or out,
            "url": url,
            "error": "meta missing",
            "headers": headers,
        }
    body, _, meta = out.rpartition(marker)
    # If -D - was used, body starts with HTTP headers then blank line then content
    if include_headers and b"\r\n\r\n" in body:
        headers, _, body = body.partition(b"\r\n\r\n")
        # There may be multiple header blocks from redirects; keep last content only
        while b"\r\n\r\n" in body and body.lstrip().startswith(b"HTTP/"):
            headers2, _, body = body.partition(b"\r\n\r\n")
            headers = headers2
    parts = meta.decode(errors="replace").strip().split("|")
    status = int(parts[0] or 0)
    ttfb = float(parts[1] or 0)
    total = float(parts[2] or 0)
    size = int(float(parts[3] or 0))
    effective = parts[4] if len(parts) > 4 else url
    return {
        "status": status,
        "ttfb": ttfb,
        "total": total,
        "size": size,
        "body": body,
        "url": effective,
        "headers": headers,
        "error": "",
    }


def run_suite(base: str) -> list[Check]:
    checks: list[Check] = []
    base = base.rstrip("/")

    def add(area: str, name: str, ok: bool, severity: str, detail: str = "") -> None:
        checks.append(Check(area, name, ok, severity, detail))

    # --- Accesos / procesos públicos ---
    public_paths = [
        ("/", 200),
        ("/galeria/", 200),
        ("/blogs/", 200),
        ("/turnos/", 200),
        ("/academia/login.php", 200),
        ("/css/style.css", 200),
        ("/js/main.js", 200),
        ("/blogs/api.php?limit=3", 200),
        ("/turnos/api.php?action=therapies", 200),
    ]
    timings: list[tuple[str, float, float]] = []
    for path, expect in public_paths:
        r = curl(f"{base}{path}")
        ok = r["status"] == expect
        add(
            "acceso",
            f"HTTP {path}",
            ok,
            "critical" if not ok else "info",
            f"status={r['status']} ttfb={r['ttfb']:.3f}s total={r['total']:.3f}s size={r['size']}",
        )
        if ok:
            timings.append((path, r["ttfb"], r["total"]))
            if r["ttfb"] >= TTFB_FAIL or r["total"] >= TOTAL_FAIL:
                add("velocidad", f"Lento {path}", False, "high", f"ttfb={r['ttfb']:.3f}s total={r['total']:.3f}s")
            elif r["ttfb"] >= TTFB_WARN:
                add("velocidad", f"TTFB alto {path}", False, "medium", f"ttfb={r['ttfb']:.3f}s (umbral {TTFB_WARN}s)")
            else:
                add("velocidad", f"Rápido {path}", True, "info", f"ttfb={r['ttfb']:.3f}s")

    if timings:
        avg_ttfb = sum(t for _, t, _ in timings) / len(timings)
        add("velocidad", "TTFB promedio páginas clave", avg_ttfb < TTFB_WARN, "medium" if avg_ttfb >= TTFB_WARN else "info", f"avg={avg_ttfb:.3f}s n={len(timings)}")

    # Home warm samples
    home_ttfbs = []
    for i in range(3):
        r = curl(f"{base}/")
        home_ttfbs.append(r["ttfb"])
    warm = sum(home_ttfbs) / len(home_ttfbs)
    add("velocidad", "Home TTFB warm (x3)", warm < TTFB_WARN, "medium" if warm >= TTFB_WARN else "info", f"samples={[round(x,3) for x in home_ttfbs]} avg={warm:.3f}s")

    # --- Fluidez / flujo de contenido ---
    home = curl(f"{base}/")
    html = home["body"].decode("utf-8", "replace")
    flow = [
        ("Link galería en home", "galeria/" in html),
        ("Link blogs en home", "blogs/" in html or ">Blogs<" in html),
        ("Link AcademiaFluxus", "AcademiaFluxus" in html),
        ("CTA/contacto o WhatsApp float", "whatsapp-float" in html or "contacto" in html.lower()),
        ("Galería justified + lightbox hooks", "gallery-justified" in html),
    ]
    for name, ok in flow:
        add("fluidez", name, ok, "high" if not ok else "info", "ok" if ok else "faltante")

    gal = curl(f"{base}/galeria/")
    gal_html = gal["body"].decode("utf-8", "replace")
    add("fluidez", "Galería carga imágenes", "img/" in gal_html and gal["status"] == 200, "high", f"status={gal['status']}")

    turnos = curl(f"{base}/turnos/")
    turnos_html = turnos["body"].decode("utf-8", "replace")
    add("fluidez", "Turnos UI + API hooks", "api.php" in turnos_html or "therap" in turnos_html.lower(), "high", "ok" if turnos["status"] == 200 else "fail")

    blogs_api = curl(f"{base}/blogs/api.php?limit=3")
    try:
        posts = json.loads(blogs_api["body"].decode()).get("posts", [])
        add("fluidez", "Blogs API usable", isinstance(posts, list), "high", f"count={len(posts)}")
    except Exception as exc:
        add("fluidez", "Blogs API usable", False, "high", str(exc))

    # --- Privacidad / seguridad de datos ---
    blocked_expect = [
        ("/academia/data/", {403, 404, 401}),
        ("/turnos/data/", {403, 404, 401}),
        ("/blogs/data/", {403, 404, 401}),
        ("/academia/data/academia.sqlite", {403, 404, 401}),
        ("/turnos/data/turnos.sqlite", {403, 404, 401}),
        ("/blogs/data/blogs.sqlite", {403, 404, 401}),
        ("/academia/migrate_gym.php", {403, 401}),
        ("/turnos/migrate_gym.php", {403, 401}),
        ("/.env", {403, 404, 406, 401}),
        ("/.git/config", {403, 404, 401}),
        ("/ACCESOS.md", {403, 404, 401}),
    ]
    for path, ok_codes in blocked_expect:
        r = curl(f"{base}{path}")
        text = r["body"].decode("utf-8", "replace")
        leaked = bool(SENSITIVE_RE.search(text)) or text.startswith("SQLite format")
        ok = r["status"] in ok_codes and not leaked
        add(
            "privacidad",
            f"Bloqueado {path}",
            ok,
            "critical" if not ok else "info",
            f"status={r['status']} leaked={leaked}",
        )

    # config.php must not leak secrets even if reachable
    for path in ["/academia/config.php", "/turnos/config.php", "/blogs/config.php"]:
        r = curl(f"{base}{path}")
        text = r["body"].decode("utf-8", "replace")
        leaked = bool(SENSITIVE_RE.search(text)) or "admin_pass" in text or "<?php" in text
        add(
            "privacidad",
            f"Config sin leak {path}",
            not leaked,
            "critical" if leaked else "info",
            f"status={r['status']} size={r['size']}",
        )

    # Auth gates
    for path, must in [
        ("/academia/admin/users.php", "redirect_or_login"),
        ("/academia/dashboard.php", "redirect_or_login"),
        ("/turnos/admin.php", "login_form"),
        ("/blogs/admin.php", "login_form"),
    ]:
        r = curl(f"{base}{path}")
        text = r["body"].decode("utf-8", "replace").lower()
        if must == "login_form":
            ok = r["status"] == 200 and ("password" in text or "contraseña" in text) and "alumno" not in text
            # turnos admin logged-out shouldn't list appointments table of people
            no_pii = "mailto:" not in text and "@gmail" not in text
            add("privacidad", f"Admin gate {path}", ok and no_pii, "high", f"status={r['status']}")
        else:
            # follow redirects → should land on login, not dashboard data
            ok = ("ingresar" in text or "login" in text or "password" in text) and "crear alumno" not in text
            add("privacidad", f"Admin gate {path}", ok or r["status"] in (401, 403), "high", f"status={r['status']} url={r['url']}")

    # API must not expose appointments list
    for action in ["appointments", "bookings", "list", "export", "admin"]:
        r = curl(f"{base}/turnos/api.php?action={action}")
        text = r["body"].decode("utf-8", "replace")
        ok = r["status"] in (400, 401, 403, 404) and "email" not in text.lower()
        add("privacidad", f"API sin listado {action}", ok, "high", f"status={r['status']} body={text[:80]}")

    # Public pages must not contain secrets
    for path in ["/", "/turnos/", "/blogs/", "/academia/login.php", "/css/style.css", "/js/main.js"]:
        r = curl(f"{base}{path}")
        text = r["body"].decode("utf-8", "replace")
        leaked = bool(SENSITIVE_RE.search(text))
        add("privacidad", f"Sin secretos en {path}", not leaked, "critical" if leaked else "info", "ok" if not leaked else "match sensible")

    # HTTPS redirect
    http = curl("http://fluxusterapia.com/")
    add(
        "acceso",
        "HTTP→HTTPS redirect",
        http["url"].startswith("https://") or "https://" in http["url"],
        "medium",
        f"final={http['url']} status={http['status']}",
    )

    # Cache headers (fluidez / repetición)
    for path in ["/css/style.css", "/js/main.js", "/img/hero.jpg"]:
        r = curl(f"{base}{path}", include_headers=True)
        hdr = r.get("headers", b"").decode("utf-8", "replace").lower()
        has_cache = "cache-control" in hdr or "expires" in hdr
        add(
            "velocidad",
            f"Cache headers {path}",
            has_cache,
            "low",
            "presente" if has_cache else "ausente (recomendado para assets estáticos)",
        )

    return checks


def render_md(base: str, checks: list[Check], generated_at: str) -> str:
    failed = [c for c in checks if not c.ok]
    passed = [c for c in checks if c.ok]
    by_area: dict[str, list[Check]] = {}
    for c in checks:
        by_area.setdefault(c.area, []).append(c)

    lines = [
        "# Smoke QA · FluxusTerapia",
        "",
        f"- **Base:** {base}",
        f"- **Generado:** {generated_at}",
        f"- **Resultado:** {len(passed)} PASS / {len(failed)} FAIL / {len(checks)} total",
        "",
        "## Resumen por área",
        "",
    ]
    for area, items in by_area.items():
        f = sum(1 for i in items if not i.ok)
        lines.append(f"- **{area}:** {len(items) - f}/{len(items)} OK" + (f" · {f} FAIL" if f else ""))
    lines.append("")

    if failed:
        lines.append("## Hallazgos")
        lines.append("")
        order = {"critical": 0, "high": 1, "medium": 2, "low": 3, "info": 4}
        for c in sorted(failed, key=lambda x: order.get(x.severity, 9)):
            lines.append(f"### [{c.severity.upper()}] ({c.area}) {c.name}")
            lines.append(f"- {c.detail}")
            lines.append("")
    else:
        lines.append("## Sin errores críticos")
        lines.append("")
        lines.append("Accesos, velocidad, fluidez y privacidad dentro de lo esperado.")
        lines.append("")

    lines.append("## Checklist")
    lines.append("")
    for c in checks:
        mark = "PASS" if c.ok else "FAIL"
        lines.append(f"- [{mark}] ({c.severity}/{c.area}) {c.name} — {c.detail}")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Smoke QA FluxusTerapia")
    parser.add_argument("--base", default=DEFAULT_BASE)
    args = parser.parse_args()

    REPORTS.mkdir(parents=True, exist_ok=True)
    generated_at = datetime.now(timezone.utc).astimezone().strftime("%Y-%m-%d %H:%M:%S %Z")
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")

    checks = run_suite(args.base)
    failed = [c for c in checks if not c.ok]
    # Only fail exit on medium+ (low = recomendaciones)
    hard_fail = [c for c in failed if c.severity in {"critical", "high", "medium"}]

    payload = {
        "base": args.base,
        "generated_at": generated_at,
        "summary": {
            "total": len(checks),
            "pass": len(checks) - len(failed),
            "fail": len(failed),
            "hard_fail": len(hard_fail),
        },
        "failures": [c.as_dict() for c in failed],
        "checks": [c.as_dict() for c in checks],
    }
    md = render_md(args.base, checks, generated_at)
    (REPORTS / f"smoke-{stamp}.md").write_text(md, encoding="utf-8")
    (REPORTS / f"smoke-{stamp}.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    (REPORTS / "smoke-latest.md").write_text(md, encoding="utf-8")
    (REPORTS / "smoke-latest.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    print(md)
    print(f"\nReportes: qa/reports/smoke-{stamp}.md · smoke-latest.md")
    return 1 if hard_fail else 0


if __name__ == "__main__":
    raise SystemExit(main())
