#!/usr/bin/env python3
"""QA automático de www.fluxusterapia.com

Uso:
  python3 qa/run_qa.py
  python3 qa/run_qa.py --base https://www.fluxusterapia.com

Genera:
  qa/reports/qa-YYYYMMDD-HHMMSS.json
  qa/reports/qa-YYYYMMDD-HHMMSS.md
  qa/reports/latest.md  (copia del último)
"""

from __future__ import annotations

import argparse
import json
import re
import ssl
import sys
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[1]
REPORTS = Path(__file__).resolve().parent / "reports"
DEFAULT_BASE = "https://www.fluxusterapia.com"


class Check:
    def __init__(self, name: str, ok: bool, severity: str, detail: str = "", evidence: str = ""):
        self.name = name
        self.ok = ok
        self.severity = severity  # critical | high | medium | low | info
        self.detail = detail
        self.evidence = evidence

    def as_dict(self) -> dict:
        return {
            "name": self.name,
            "ok": self.ok,
            "severity": self.severity,
            "detail": self.detail,
            "evidence": self.evidence,
        }


def fetch(base: str, path: str, timeout: int = 25) -> tuple[int, bytes, str]:
    url = base.rstrip("/") + path
    # Prefer curl (macOS Python often fails SSL/cert chain with urllib)
    try:
        import subprocess

        proc = subprocess.run(
            [
                "/usr/bin/curl",
                "-sS",
                "-L",
                "--max-time",
                str(timeout),
                "-w",
                "\n__HTTP_STATUS__:%{http_code}",
                "-A",
                "FluxusQA/1.1",
                url,
            ],
            capture_output=True,
            timeout=timeout + 5,
        )
        out = proc.stdout
        marker = b"\n__HTTP_STATUS__:"
        if marker in out:
            body, _, status_raw = out.rpartition(marker)
            status = int(status_raw.decode().strip() or "0")
            return status, body, url
        if proc.returncode != 0:
            return 0, (proc.stderr or b"curl failed"), url
        return 0, out, url
    except Exception as exc:
        pass

    req = urllib.request.Request(url, headers={"User-Agent": "FluxusQA/1.1"})
    ctx = ssl.create_default_context()
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=timeout) as resp:
            return resp.status, resp.read(), resp.geturl()
    except HTTPError as exc:
        body = exc.read() if exc.fp else b""
        return exc.code, body, url
    except (URLError, TimeoutError, OSError) as exc:
        return 0, str(exc).encode(), url


def run_suite(base: str) -> list[Check]:
    checks: list[Check] = []

    def add(name: str, ok: bool, severity: str, detail: str = "", evidence: str = "") -> None:
        checks.append(Check(name, ok, severity, detail, evidence))

    # HTTP availability
    for path, expect in [
        ("/", 200),
        ("/galeria/", 200),
        ("/blogs/", 200),
        ("/turnos/", 200),
        ("/academia/login.php", 200),
        ("/css/style.css", 200),
        ("/js/main.js", 200),
        ("/img/hero.jpg", 200),
        ("/img/galeria/masoterapia.jpg", 200),
        ("/blogs/admin.php", 200),
        ("/turnos/admin.php", 200),
        ("/blogs/api.php?limit=3", 200),
        ("/turnos/api.php?action=therapies", 200),
    ]:
        status, body, final = fetch(base, path)
        ok = status == expect
        add(
            f"HTTP {path}",
            ok,
            "critical" if not ok else "info",
            f"status={status} expected={expect}",
            final,
        )

    # Home content / branding
    status, body, _ = fetch(base, "/")
    html = body.decode("utf-8", "replace")
    nav_m = re.search(r'<nav class="site-nav"[^>]*>(.*?)</nav>', html, re.S)
    nav = nav_m.group(1) if nav_m else ""
    social_m = re.search(r'<div class="social"[^>]*>(.*?)</div>\s*</header>', html, re.S)
    social = social_m.group(1) if social_m else ""

    content_checks = [
        ("Nav incluye Blogs", ">Blogs<" in html or "href=\"blogs/\"" in nav, "high"),
        ("Nav incluye AcademiaFluxus", "AcademiaFluxus" in html, "high"),
        ("Nav no incluye Turnos", "href=\"turnos/\"" not in nav and ">Turnos<" not in nav, "medium"),
        ("Servicio gym pre/post parto", "Curso gym pre y post parto" in html, "high"),
        ("Chi kung Online en servicios", "Chi kung Online" in html, "high"),
        ("Taichi Online en servicios", "Taichi Online" in html, "high"),
        ("Consultorios Punta Alta", "Punta Alta" in html, "medium"),
        ("Consultorios modalidad Online", "modalidad Online" in html, "medium"),
        ("Galería justified", "gallery-justified" in html, "medium"),
        ("Header Instagram brand", "social-ig" in social, "medium"),
        ("Header YouTube brand", "social-yt" in social and "#FF0000" in social, "medium"),
        ("Header sin WhatsApp", "wa.me" not in social and "WhatsApp" not in social, "high"),
        ("Float WhatsApp presente", "whatsapp-float" in html, "low"),
        ("Sin branding viejo AcademiFluxus", "AcademiFluxus" not in html, "medium"),
        ("Sin branding viejo FluxusAcademia", "FluxusAcademia" not in html, "medium"),
    ]
    for name, ok, sev in content_checks:
        add(name, ok, sev if not ok else "info", "ok" if ok else "faltante/incorrecto")

    # Assets
    _, css, _ = fetch(base, "/css/style.css")
    css_txt = css.decode("utf-8", "replace")
    _, js, _ = fetch(base, "/js/main.js")
    js_txt = js.decode("utf-8", "replace")
    add("CSS gallery-justified", "gallery-justified" in css_txt, "high", "ok" if "gallery-justified" in css_txt else "missing")
    add("JS initLandingBlogs", "initLandingBlogs" in js_txt, "medium")
    add("JS initGalleryLightbox", "initGalleryLightbox" in js_txt, "medium")

    # Turnos API therapies
    status, body, _ = fetch(base, "/turnos/api.php?action=therapies")
    try:
        data = json.loads(body.decode("utf-8"))
        names = [t.get("name", "") for t in data.get("therapies", [])]
        add("Turnos API JSON válido", status == 200, "critical", f"status={status}")
        for need in ["Curso gym pre y post parto", "Chi kung Online", "Taichi Online"]:
            add(f"Turnos incluye {need}", need in names, "high", ", ".join(names))
    except Exception as exc:
        add("Turnos API JSON válido", False, "critical", str(exc))

    # Blogs API
    status, body, _ = fetch(base, "/blogs/api.php?limit=3")
    try:
        data = json.loads(body.decode("utf-8"))
        posts = data.get("posts", [])
        add("Blogs API posts", isinstance(posts, list), "high", f"count={len(posts)}")
    except Exception as exc:
        add("Blogs API posts", False, "high", str(exc))

    # Turnos page consistency
    _, turnos_body, _ = fetch(base, "/turnos/")
    turnos_html = turnos_body.decode("utf-8", "replace")
    add("Turnos nav AcademiaFluxus", "AcademiaFluxus" in turnos_html, "medium")
    add("Turnos pie actualizado", "Punta Alta" in turnos_html, "low")
    add("Turnos sin Incontro viejo", "Incontro" not in turnos_html, "low")

    # Security: migrate scripts must require key (403 / No autorizado)
    for path in [
        "/academia/migrate_gym.php",
        "/academia/migrate_online.php",
        "/turnos/migrate_gym.php",
        "/turnos/migrate_online.php",
    ]:
        status, body, _ = fetch(base, path)
        text = body.decode("utf-8", "replace")
        if status == 0:
            add(
                f"Seguridad migrate bloqueado {path}",
                False,
                "critical",
                "sin respuesta HTTP (red/SSL)",
                text[:120],
            )
            continue
        open_ok = status == 200 and (
            "OK:" in text or "Listo" in text or "AVISO:" in text or "curso" in text.lower()
        )
        locked = status in (401, 403) or "no autorizado" in text.lower() or "clave" in text.lower()
        add(
            f"Seguridad migrate bloqueado {path}",
            locked and not open_ok,
            "high",
            f"status={status}; open={open_ok}; locked={locked}",
            text[:120],
        )

    return checks


def render_markdown(base: str, checks: list[Check], generated_at: str) -> str:
    failed = [c for c in checks if not c.ok]
    passed = [c for c in checks if c.ok]
    lines = [
        f"# QA Report · FluxusTerapia",
        "",
        f"- **Base:** {base}",
        f"- **Generado:** {generated_at}",
        f"- **Resultado:** {len(passed)} PASS / {len(failed)} FAIL / {len(checks)} total",
        "",
    ]
    if failed:
        lines.append("## Errores / bugs detectados")
        lines.append("")
        order = {"critical": 0, "high": 1, "medium": 2, "low": 3, "info": 4}
        for c in sorted(failed, key=lambda x: order.get(x.severity, 9)):
            lines.append(f"### [{c.severity.upper()}] {c.name}")
            lines.append(f"- Detalle: {c.detail}")
            if c.evidence:
                lines.append(f"- Evidencia: `{c.evidence}`")
            lines.append("")
        lines.append("## Acciones sugeridas")
        lines.append("")
        lines.append("1. Revisar los FAIL de severidad critical/high primero.")
        lines.append("2. Re-correr `python3 qa/run_qa.py` después de cada deploy.")
        lines.append("3. Guardar el reporte en `qa/reports/` para historial.")
        lines.append("")
    else:
        lines.append("## Sin errores")
        lines.append("")
        lines.append("No se detectaron fallos en la suite automática.")
        lines.append("")

    lines.append("## Checklist completo")
    lines.append("")
    for c in checks:
        mark = "PASS" if c.ok else "FAIL"
        lines.append(f"- [{mark}] ({c.severity}) {c.name} — {c.detail}")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="QA automático FluxusTerapia")
    parser.add_argument("--base", default=DEFAULT_BASE)
    args = parser.parse_args()

    REPORTS.mkdir(parents=True, exist_ok=True)
    generated_at = datetime.now(timezone.utc).astimezone().strftime("%Y-%m-%d %H:%M:%S %Z")
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")

    checks = run_suite(args.base)
    failed = [c for c in checks if not c.ok]
    payload = {
        "base": args.base,
        "generated_at": generated_at,
        "summary": {
            "total": len(checks),
            "pass": len(checks) - len(failed),
            "fail": len(failed),
        },
        "failures": [c.as_dict() for c in failed],
        "checks": [c.as_dict() for c in checks],
    }

    json_path = REPORTS / f"qa-{stamp}.json"
    md_path = REPORTS / f"qa-{stamp}.md"
    latest_md = REPORTS / "latest.md"
    latest_json = REPORTS / "latest.json"

    md = render_markdown(args.base, checks, generated_at)
    json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    md_path.write_text(md, encoding="utf-8")
    latest_md.write_text(md, encoding="utf-8")
    latest_json.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    print(md)
    print(f"\nReportes guardados:\n- {md_path}\n- {json_path}\n- {latest_md}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
