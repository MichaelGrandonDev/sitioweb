"""Puente Pacientes ↔ Cursor (runtime local en esta Mac).

Pide trabajos al servidor cada ~5 s, corre un agente local de Cursor de solo lectura sobre una copia de la
biblioteca extraída y devuelve un JSON validado. Si algo falla, avisa al servidor para que use sus protocolos.
En el log solo quedan ids de trabajo/agente/run y estados: nunca el caso ni la respuesta.

Uso:  .venv/bin/python bridge.py [--once] [--mock]
"""

from __future__ import annotations

import argparse
import hashlib
import json
import logging
import os
import re
import shutil
import signal
import stat
import sys
import threading
import time
from pathlib import Path

from common import BridgeHTTPError, iter_library_docs, library_dir, load_env, pages_of, server_from_env

VERSION = "1.1"
POLL_SECONDS = 5
HEARTBEAT_SECONDS = 20
RUN_LIMIT_SECONDS = 200
CACHE = Path.home() / "Library" / "Caches" / "FluxusCursorBridge"
LINE_CHARS = 700

log = logging.getLogger("cursor_bridge")
stop = threading.Event()


# ---------- Biblioteca de solo lectura para el agente ----------

def library_signature(lib: Path) -> str:
    h = hashlib.sha256()
    if lib.is_dir():
        for p in sorted(lib.glob("*.json")):
            st = p.stat()
            h.update(f"{p.name}:{st.st_size}:{int(st.st_mtime)}".encode())
    return h.hexdigest()[:16]


def make_writable(path: Path) -> None:
    for root, dirs, files in os.walk(path):
        os.chmod(root, 0o755)
        for f in files:
            try:
                os.chmod(os.path.join(root, f), 0o644)
            except OSError:
                pass


def lock_read_only(path: Path) -> None:
    for root, dirs, files in os.walk(path, topdown=False):
        for f in files:
            os.chmod(os.path.join(root, f), stat.S_IRUSR | stat.S_IRGRP)
        os.chmod(root, stat.S_IRUSR | stat.S_IXUSR | stat.S_IRGRP | stat.S_IXGRP)


def slug(text: str) -> str:
    s = re.sub(r"[^A-Za-z0-9]+", "-", text).strip("-")
    return s[:60] or "doc"


def ensure_workspace(lib: Path) -> Path:
    """Copia de solo lectura: textos/<doc>.txt con cada línea marcada «[p. N]» e INDICE.md. Se rehace si cambia la biblioteca."""
    sig = library_signature(lib)
    ws = CACHE / f"biblioteca-{sig}"
    if (ws / "INDICE.md").is_file():
        return ws
    CACHE.mkdir(parents=True, exist_ok=True)
    for old in CACHE.glob("biblioteca-*"):
        make_writable(old)
        shutil.rmtree(old, ignore_errors=True)
    tmp = CACHE / f"tmp-{sig}"
    if tmp.exists():
        make_writable(tmp)
        shutil.rmtree(tmp)
    (tmp / "textos").mkdir(parents=True)
    index = [
        "# Biblioteca MTC (copia de solo lectura)",
        "",
        "Cada archivo de `textos/` es un documento. Cada línea empieza con la página del original: `[p. 123]`.",
        "Buscá con grep (signos de lengua y saburra, patrones, etiología, síntomas, técnicas, puntos como `E36` o `Zusanli`) y citá título + página.",
        "",
        "| Archivo | Título | Tipo | Páginas |",
        "| --- | --- | --- | --- |",
    ]
    count = 0
    for _path, doc in iter_library_docs(lib):
        title = str(doc.get("title") or doc.get("source_file") or "Documento").strip()
        kind = str(doc.get("kind") or "pdf")
        unit = "diap." if kind in ("pptx", "ppt") else "p."
        name = f"{slug(str(doc.get('id') or title))}.txt"
        with open(tmp / "textos" / name, "w", encoding="utf-8") as out:
            out.write(f"# {title}\n")
            for page, text in pages_of(doc).items():
                flat = re.sub(r"\s+", " ", text).strip()
                for i in range(0, len(flat), LINE_CHARS):
                    out.write(f"[{unit} {page}] {flat[i:i + LINE_CHARS]}\n")
        index.append(f"| textos/{name} | {title.replace('|', '/')} | {kind} | {doc.get('pages') or ''} |")
        count += 1
    if count == 0:
        index += ["", "(Todavía no hay documentos extraídos: usá solo los pasajes y protocolos que vienen en el pedido.)"]
    (tmp / "INDICE.md").write_text("\n".join(index) + "\n", encoding="utf-8")
    tmp.rename(ws)
    lock_read_only(ws)
    log.info("biblioteca lista: %d documentos (copia de solo lectura)", count)
    return ws


# ---------- Prompt y validación ----------

TECHNIQUES = ("tuina", "chikung", "moxibustion", "ventosas", "auriculoterapia")


def library_map(lib: Path) -> dict[str, dict]:
    """textos/<archivo>.txt → sha256, archivo original y título (para enlazar las citas al lector PDF)."""
    out = {}
    for _path, doc in iter_library_docs(lib):
        title = str(doc.get("title") or doc.get("source_file") or "Documento").strip()
        name = f"textos/{slug(str(doc.get('id') or title))}.txt"
        out[name] = {"sha256": str(doc.get("sha256") or ""), "source_file": str(doc.get("source_file") or ""), "title": title}
    return out


def build_prompt(payload: dict, with_photo: bool) -> str:
    schema = payload.get("esquema")
    if not isinstance(schema, str) or "diagnostico_mtc" not in schema:
        raise RunFailed("el pedido no trae el esquema nuevo: actualizá el servidor")
    lines = [
        "Sos un asistente de apoyo para una terapeuta de Medicina Tradicional China (MTC). La decisión clínica es de ella.",
        "",
        "FORMA DE TRABAJAR:",
        "A. NO modifiques, crees ni borres archivos. Solo leé y buscá (ls, glob, grep, read). Esta carpeta es de solo lectura.",
        "B. El diagnóstico se hace con la GLOSODIAGNOSIS (base), la información del paciente y la teoría de la biblioteca. "
        "El pulso solo cuenta si viene cargado.",
        "C. Buscá a fondo en la biblioteca (INDICE.md y textos/*.txt; cada línea empieza con su página «[p. N]»):",
        "   1) cada signo de la lengua del caso (color, forma, marcas dentales, grietas, puntos rojos, petequias, saburra, zonas) "
        "en los textos de diagnóstico y glosodiagnosis;",
        "   2) para cada patrón candidato: etiología, fisiopatología, fundamentos (órganos, sustancias) y cuadro clínico;",
        "   3) para cada técnica (tuina, chi kung, moxibustión, ventosas, auriculoterapia) lo que digan los textos sobre esos patrones.",
        "   Probá sinónimos (p. ej. «saburra»/«capa»/«revestimiento», «Qi»/«Chi», «Bazo»/«Bazo-Páncreas»).",
        "D. Citá documento + página exactos de lo que leíste; en «citas» poné también «archivo» (textos/…). No inventes citas ni páginas. "
        "Podés citar los `pasajes_del_servidor` con su cita tal cual.",
        "E. El caso está anonimizado: no intentes identificar a la persona.",
    ]
    if with_photo:
        lines.append("F. Se adjunta una foto de la lengua: describí lo que se ve y contrastalo con la glosodiagnosis escrita (si difieren, decilo).")
    lines += [
        "",
        "REGLAS CLÍNICAS:",
        str(payload.get("reglas") or ""),
        "",
        "Respondé SOLO con un objeto JSON válido (sin texto antes ni después, sin ```), con esta forma:",
        schema,
        "Escribí en español rioplatense, claro y concreto.",
        "",
        "PEDIDO (JSON):",
        json.dumps({k: v for k, v in payload.items() if k not in ("esquema", "reglas")}, ensure_ascii=False, indent=1),
    ]
    return "\n".join(lines)


def extract_json(text: str) -> dict:
    text = (text or "").strip()
    fence = re.search(r"```(?:json)?\s*(\{.*\})\s*```", text, re.S)
    if fence:
        text = fence.group(1)
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end <= start:
        raise ValueError("la respuesta no trae JSON")
    return json.loads(text[start:end + 1])


def _code(text: str) -> str:
    m = re.match(r"\s*([A-Z]{1,2})\s?-?\s?(\d{1,2})\b", str(text or "").upper())
    return f"{m.group(1)}{m.group(2)}" if m else ""


def validate(result: dict, payload: dict, docs: dict[str, dict] | None = None) -> dict:
    if not isinstance(result, dict):
        raise ValueError("la respuesta no es un objeto")
    for key in ("resumen", "principio"):
        if not isinstance(result.get(key), str) or not result[key].strip():
            raise ValueError(f"falta {key}")
    dx = result.get("diagnostico_mtc")
    if not isinstance(dx, dict) or not str(dx.get("texto") or "").strip() or not isinstance(dx.get("patrones"), list) or not dx["patrones"]:
        raise ValueError("falta diagnostico_mtc")
    if not all(isinstance(p, dict) and str(p.get("nombre") or "").strip() for p in dx["patrones"]):
        raise ValueError("patrones inválidos")
    tech = result.get("tecnicas")
    if not isinstance(tech, dict) or any(t not in tech for t in TECHNIQUES):
        raise ValueError("faltan técnicas")
    for key in ("sesiones", "citas"):
        if not isinstance(result.get(key), list):
            raise ValueError(f"falta la lista {key}")
    avoid = {str(c).upper() for c in payload.get("evitar_puntos") or []}
    if avoid:
        mox = tech.get("moxibustion")
        if isinstance(mox, dict) and isinstance(mox.get("puntos"), list):
            mox["puntos"] = [p for p in mox["puntos"] if not (isinstance(p, dict) and _code(p.get("punto")) in avoid)]
        tui = tech.get("tuina")
        if isinstance(tui, dict) and isinstance(tui.get("acupresion"), list):
            tui["acupresion"] = [p for p in tui["acupresion"] if _code(p) not in avoid]
    by_title = {v["title"].lower(): v for v in (docs or {}).values()}
    for c in result["citas"]:
        if not isinstance(c, dict):
            continue
        ref = (docs or {}).get(str(c.get("archivo") or "").strip().lstrip("./")) or by_title.get(str(c.get("documento") or "").strip().lower())
        if ref:
            c["sha256"], c["source_file"] = ref["sha256"], ref["source_file"]
    for key in ("meridianos", "precauciones", "sugerencias_generales", "diferenciales", "conflictos"):
        if key in result and not isinstance(result[key], (list, str)):
            result[key] = []
    if len(json.dumps(result)) > 190_000:
        raise ValueError("respuesta demasiado larga")
    return result


def fake_result(payload: dict) -> dict:
    """Modo prueba (--mock): arma una respuesta con los protocolos del pedido, sin llamar a Cursor."""
    protos = payload.get("protocolos_del_terapeuta") or []
    passages = payload.get("pasajes_del_servidor") or []
    avoid = {c.upper() for c in payload.get("evitar_puntos") or []}
    names = [p.get("nombre", "Patrón") for p in protos] or ["Patrón a confirmar"]
    codes = []
    for p in protos:
        codes += re.findall(r"\b(?:PC|IG|ID|SJ|VB|RM|DU|E|B|V|R|H|C|P)\d{1,2}\b", p.get("puntos_para_moxar", ""))
    codes = [c for c in dict.fromkeys(codes) if c not in avoid][:6] or ["E36"]
    citas = [{"id": f"C{i + 1}", "documento": re.sub(r"^«|»,.*$", "", s.get("cita", "")), "pagina": (re.findall(r"(\d+)$", s.get("cita", "")) or [""])[0], "cita": s.get("texto", "")[:150]} for i, s in enumerate(passages[:3])]
    ref = ["C1"] if citas else "sugerencia general"
    text = ", ".join([names[0]] + [n[:1].lower() + n[1:] for n in names[1:]]) + "."
    return {
        "resumen": "[PRUEBA sin Cursor] Resumen armado por el puente en modo prueba.",
        "glosodiagnosis": "Lectura de prueba de la lengua: " + str(payload.get("glosodiagnosis") or "sin datos")[:200],
        "diagnostico_mtc": {
            "texto": text,
            "patrones": [{"nombre": n, "justificacion_lengua": "signos de lengua del protocolo (modo prueba)", "justificacion_paciente": "datos del paciente (modo prueba)", "citas": ref} for n in names],
        },
        "diferenciales": [{"nombre": p.get("nombre", ""), "por_que_no": "faltan signos de lengua (modo prueba)", "citas": "sugerencia general"} for p in payload.get("protocolos_considerados") or []][:3],
        "principio": "Tonificar y armonizar según los patrones (modo prueba).",
        "meridianos": ["Bazo", "Estómago"],
        "tecnicas": {
            "tuina": {"maniobras": "amasado y frotación (modo prueba)", "zonas": "abdomen y dorso", "acupresion": codes[:3], "duracion": "20 min", "citas": ref},
            "chikung": {"ba_duan_jin": "3.ª pieza", "respiracion": "abdominal lenta", "liu_zi_jue": "HU", "en_sesion": "10 min", "en_casa": "10 min por día", "citas": "sugerencia general"},
            "moxibustion": {"puntos": [{"punto": c, "metodo": "bastón indirecta", "tiempo": "10 min", "precaucion": "a 2-3 cm", "alternativa": ""} for c in codes], "notas": "modo prueba", "citas": ref},
            "ventosas": {"zonas": "dorso", "tipo": "fija", "tiempo": "5 min", "contraindicaciones": "piel lesionada", "citas": "sugerencia general"},
            "auriculoterapia": {"puntos": ["Shenmen", "Punto Cero"], "material": "semillas de vaccaria", "presion_en_casa": "3 veces por día", "oreja": "alternar", "citas": "sugerencia general"},
        },
        "sesiones": [
            {"sesion": "1", "objetivo": "Diagnóstico y tonificación general", "tuina": "general", "chikung": "enseñar", "moxibustion": "E36", "ventosas": "no", "auriculoterapia": "Shenmen"},
            {"sesion": "2-5", "objetivo": "Tratar los patrones", "tuina": "del patrón", "chikung": "revisar", "moxibustion": ", ".join(codes), "ventosas": "fijas en dorso", "auriculoterapia": "alternar oreja"},
            {"sesion": "6-25", "objetivo": "Controles semanales", "tuina": "según evolución", "chikung": "en casa", "moxibustion": "según evolución", "ventosas": "si hace falta", "auriculoterapia": "semanal"},
        ],
        "precauciones": ["Modo prueba: revisar todo."],
        "texto_paciente": "Texto de prueba para el paciente.",
        "citas": citas,
        "sugerencias_generales": ["Respuesta de prueba generada sin Cursor."],
    }


# ---------- Cursor ----------

class RunFailed(Exception):
    pass


def run_cursor(payload: dict, env: dict[str, str], lib: Path, photo: dict | None) -> dict:
    from cursor_sdk import Agent, AgentOptions, CursorAgentError, LocalAgentOptions, SDKImage, UserMessage

    api_key = env.get("CURSOR_API_KEY", "").strip()
    if not api_key:
        raise RunFailed("falta CURSOR_API_KEY en .env")
    ws = ensure_workspace(lib)
    model = env.get("CURSOR_MODEL") or "composer-2.5"
    prompt = build_prompt(payload, photo is not None)
    message = UserMessage(text=prompt, images=[SDKImage.data_image(photo["data"], photo["mime"])]) if photo else prompt
    try:
        with Agent.create(
            AgentOptions(
                api_key=api_key,
                model=model,
                name="fluxus-pacientes",
                local=LocalAgentOptions(cwd=str(ws)),
                tools=["read", "grep", "glob", "ls"],
            )
        ) as agent:
            run = agent.send(message)
            log.info("cursor: agente %s run %s%s", agent.agent_id, run.id, " (con foto)" if photo else "")
            timer = threading.Timer(RUN_LIMIT_SECONDS, lambda: run.cancel() if run.supports("cancel") else None)
            timer.start()
            try:
                result = run.wait()
            finally:
                timer.cancel()
            if result.status != "finished":
                raise RunFailed(f"run {result.id} terminó en estado {result.status}")
            return extract_json(result.result or "")
    except CursorAgentError as err:
        log.warning("cursor no arrancó: %s (reintentable=%s, request_id=%s)", type(err).__name__, err.is_retryable, getattr(err, "request_id", None))
        raise RunFailed("Cursor no arrancó (" + type(err).__name__ + ")") from None


def fetch_photo(server, job_id, claim) -> dict | None:
    """Foto de la lengua del trabajo (solo mientras está tomado). Si no se puede, se sigue sin foto."""
    try:
        resp = server.post({"action": "photo", "id": job_id, "claim": claim}, timeout=60)
    except Exception as err:
        log.info("trabajo %s: sin foto (%s)", job_id, type(err).__name__)
        return None
    photo = resp.get("photo")
    if isinstance(photo, dict) and isinstance(photo.get("data"), str) and str(photo.get("mime", "")).startswith("image/"):
        return {"data": photo["data"], "mime": photo["mime"]}
    return None


# ---------- Bucle ----------

def heartbeat_loop(server, busy: threading.Event, info: str) -> None:
    while not stop.is_set():
        if busy.wait(timeout=1) and not stop.is_set():
            try:
                server.post({"action": "heartbeat", "info": info + " ocupado"}, timeout=15)
            except Exception:
                pass
            stop.wait(HEARTBEAT_SECONDS)


def handle(job: dict, server, env: dict[str, str], lib: Path, mock: bool, info: str) -> None:
    job_id, claim, payload = job.get("id"), job.get("claim"), job.get("payload") or {}
    started = time.time()
    log.info("trabajo %s: tomado", job_id)
    try:
        photo = fetch_photo(server, job_id, claim) if payload.get("foto") and not mock else None
        result = fake_result(payload) if mock else run_cursor(payload, env, lib, photo)
        result = validate(result, payload, library_map(lib))
        server.post({"action": "result", "id": job_id, "claim": claim, "result": result, "info": info}, timeout=60)
        log.info("trabajo %s: listo en %.0f s", job_id, time.time() - started)
    except (RunFailed, ValueError, json.JSONDecodeError) as err:
        reason = str(err)[:150]
        log.warning("trabajo %s: sin resultado (%s)", job_id, reason)
        try:
            server.post({"action": "result", "id": job_id, "claim": claim, "error": reason, "info": info}, timeout=30)
        except Exception as post_err:
            log.warning("trabajo %s: no se pudo avisar el error (%s)", job_id, type(post_err).__name__)
    except BridgeHTTPError as err:
        log.warning("trabajo %s: el servidor rechazó el resultado (HTTP %s)", job_id, err.status)


def main() -> int:
    parser = argparse.ArgumentParser(description="Puente Pacientes ↔ Cursor")
    parser.add_argument("--once", action="store_true", help="procesa como máximo un trabajo y sale")
    parser.add_argument("--mock", action="store_true", help="no llama a Cursor: responde con datos de prueba")
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    env = load_env()
    server = server_from_env(env)
    lib = library_dir(env)
    mock = args.mock or env.get("BRIDGE_MOCK") == "1"
    if not mock and not env.get("CURSOR_API_KEY"):
        log.error("Falta CURSOR_API_KEY en ~/Desktop/Fluxus/.env (Cursor Dashboard > Integrations). Uso --mock para probar sin Cursor.")
        return 1
    info = f"puente {VERSION} {'prueba' if mock else (env.get('CURSOR_MODEL') or 'composer-2.5')}"
    if not mock:
        ensure_workspace(lib)

    def _stop(*_):
        stop.set()

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)
    busy = threading.Event()
    threading.Thread(target=heartbeat_loop, args=(server, busy, info), daemon=True).start()
    log.info("puente iniciado (%s) contra %s", "modo prueba" if mock else "Cursor local", server.url.split("/turnos/")[0])

    backoff = POLL_SECONDS
    while not stop.is_set():
        try:
            resp = server.post({"action": "claim", "info": info}, timeout=30)
            backoff = POLL_SECONDS
            job = resp.get("job")
            if job:
                busy.set()
                try:
                    handle(job, server, env, lib, mock, info)
                finally:
                    busy.clear()
                if args.once:
                    break
                continue
            if args.once:
                break
        except BridgeHTTPError as err:
            log.warning("servidor: HTTP %s %s", err.status, err.message)
            if err.status == 401:
                log.error("Token rechazado: revisá PACIENTES_BRIDGE_TOKEN en .env.")
                backoff = 120
            else:
                backoff = min(backoff * 2, 60)
        except Exception as err:
            log.warning("sin conexión con el servidor (%s)", type(err).__name__)
            backoff = min(backoff * 2, 60)
        stop.wait(backoff)
    log.info("puente detenido")
    return 0


if __name__ == "__main__":
    sys.exit(main())
