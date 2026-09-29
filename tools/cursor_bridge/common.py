"""Configuración y HTTP compartidos por el puente y el importador. Nunca imprime secretos ni datos clínicos."""

from __future__ import annotations

import json
import os
import ssl
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENV_FILE = ROOT / ".env"
DEFAULT_URL = "https://fluxusterapia.com/turnos/pacientes_bridge.php"
DEFAULT_LIBRARY = Path.home() / "Desktop" / "material-mtc" / "_extraido"
BROWSER_UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0 Safari/537.36 FluxusBridge/1.0"
)


def load_env() -> dict[str, str]:
    """Lee ~/Desktop/Fluxus/.env (KEY=valor) sin exportarlo al entorno de otros procesos."""
    values: dict[str, str] = {}
    if ENV_FILE.is_file():
        for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                value = value[1:-1]
            values[key.strip()] = value
    for key in ("PACIENTES_BRIDGE_URL", "PACIENTES_BRIDGE_TOKEN", "CURSOR_API_KEY", "MTC_LIBRARY_DIR", "CURSOR_MODEL", "BRIDGE_MOCK"):
        if os.environ.get(key):
            values[key] = os.environ[key]
    return values


def ssl_context() -> ssl.SSLContext:
    cafile = "/etc/ssl/cert.pem"
    return ssl.create_default_context(cafile=cafile) if os.path.exists(cafile) else ssl.create_default_context()


class BridgeHTTPError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(f"HTTP {status}: {message}")
        self.status = status
        self.message = message


class Server:
    def __init__(self, url: str, token: str):
        if not url.startswith("https://") and not url.startswith("http://127.0.0.1"):
            raise SystemExit("PACIENTES_BRIDGE_URL tiene que ser https://")
        self.url = url
        self.token = token
        self.ctx = ssl_context() if url.startswith("https://") else None

    def post(self, payload: dict, timeout: float = 40) -> dict:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            self.url,
            data=body,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Authorization": "Bearer " + self.token,
                "X-Bridge-Token": self.token,
                "User-Agent": BROWSER_UA,
                "Accept": "application/json",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout, context=self.ctx) as resp:
                return json.loads(resp.read().decode("utf-8") or "{}")
        except urllib.error.HTTPError as err:
            try:
                data = json.loads(err.read().decode("utf-8") or "{}")
                msg = str(data.get("error") or "")
            except Exception:
                msg = ""
            raise BridgeHTTPError(err.code, msg[:200]) from None


def server_from_env(env: dict[str, str]) -> Server:
    token = env.get("PACIENTES_BRIDGE_TOKEN", "")
    if len(token) < 32:
        raise SystemExit(
            "Falta PACIENTES_BRIDGE_TOKEN en ~/Desktop/Fluxus/.env "
            "(crealo en Pacientes > Ajustes de IA > «Crear el token del puente»)."
        )
    return Server(env.get("PACIENTES_BRIDGE_URL") or DEFAULT_URL, token)


def library_dir(env: dict[str, str]) -> Path:
    return Path(env.get("MTC_LIBRARY_DIR") or DEFAULT_LIBRARY).expanduser()


def iter_library_docs(lib: Path):
    """Documentos extraídos: un JSON por documento con {id,title,source_file,sha256,pages,kind,tags,chunks:[{page,text}]}."""
    if not lib.is_dir():
        return
    for path in sorted(lib.glob("*.json")):
        if path.name in ("manifest.json",) or path.name.startswith("."):
            continue
        try:
            doc = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        if isinstance(doc, dict) and isinstance(doc.get("chunks"), list):
            yield path, doc


def pages_of(doc: dict) -> dict[int, str]:
    """Une los chunks por página (varios chunks de la misma página se concatenan)."""
    pages: dict[int, list[str]] = {}
    for ch in doc.get("chunks") or []:
        if not isinstance(ch, dict):
            continue
        try:
            page = int(ch.get("page") or 0)
        except (TypeError, ValueError):
            continue
        text = str(ch.get("text") or "").strip()
        if page >= 1 and text:
            pages.setdefault(page, []).append(text)
    return {p: "\n".join(t) for p, t in sorted(pages.items())}
