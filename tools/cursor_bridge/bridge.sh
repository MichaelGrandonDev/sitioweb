#!/bin/zsh
# Puente Pacientes ↔ Cursor: setup | start | stop | status | logs | test | import | install-autostart | uninstall-autostart
set -u
DIR="${0:A:h}"
cd "$DIR"
PY="$DIR/.venv/bin/python"
RUN_DIR="$HOME/Library/Application Support/FluxusCursorBridge"
PID_FILE="$RUN_DIR/bridge.pid"
LOG_FILE="$HOME/Library/Logs/fluxus-cursor-bridge.log"
LABEL="com.fluxusterapia.cursor-bridge"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
export SSL_CERT_FILE=/etc/ssl/cert.pem
mkdir -p "$RUN_DIR" "${LOG_FILE:h}"

running() { [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; }

case "${1:-help}" in
  setup)
    PYBIN="$(command -v python3.13 || command -v python3.12 || command -v python3.11 || command -v python3.10 || true)"
    [[ -z "$PYBIN" ]] && { echo "Hace falta Python 3.10 o más nuevo (python.org)."; exit 1; }
    [[ -x "$PY" ]] || "$PYBIN" -m venv "$DIR/.venv"
    "$PY" -m pip install -q --upgrade pip && "$PY" -m pip install -q -r requirements.txt && echo "Listo: entorno instalado en tools/cursor_bridge/.venv"
    ;;
  start)
    if launchctl list 2>/dev/null | grep -q "$LABEL"; then echo "El puente corre con el inicio automático (launchd)."; exit 0; fi
    running && { echo "Ya está corriendo (pid $(cat "$PID_FILE"))."; exit 0; }
    [[ -x "$PY" ]] || { echo "Primero: ./bridge.sh setup"; exit 1; }
    nohup "$PY" bridge.py ${BRIDGE_ARGS:-} >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    sleep 2
    running && echo "Puente iniciado (pid $(cat "$PID_FILE")). Log: $LOG_FILE" || { echo "No arrancó. Mirá: ./bridge.sh logs"; exit 1; }
    ;;
  stop)
    if running; then kill "$(cat "$PID_FILE")" && rm -f "$PID_FILE" && echo "Puente detenido."; else echo "No estaba corriendo (manual)."; fi
    launchctl list 2>/dev/null | grep -q "$LABEL" && echo "Ojo: también está el inicio automático; para sacarlo: ./bridge.sh uninstall-autostart"
    ;;
  status)
    if running; then echo "Corriendo (manual, pid $(cat "$PID_FILE"))."
    elif launchctl list 2>/dev/null | grep -q "$LABEL"; then echo "Corriendo con inicio automático (launchd)."
    else echo "Detenido."; fi
    [[ -f "$LOG_FILE" ]] && { echo "Últimas líneas del log:"; tail -n 5 "$LOG_FILE"; }
    ;;
  logs)
    tail -n 40 "$LOG_FILE" 2>/dev/null || echo "Todavía no hay log."
    ;;
  test)
    "$PY" bridge.py --once --mock
    ;;
  import)
    shift; "$PY" import_library.py "$@"
    ;;
  install-autostart)
    [[ -x "$PY" ]] || { echo "Primero: ./bridge.sh setup"; exit 1; }
    running && "$0" stop
    mkdir -p "${PLIST:h}"
    sed -e "s#__PYTHON__#$PY#g" -e "s#__DIR__#$DIR#g" -e "s#__LOG__#$LOG_FILE#g" "$DIR/$LABEL.plist.template" > "$PLIST"
    launchctl unload "$PLIST" 2>/dev/null
    launchctl load -w "$PLIST" && echo "Inicio automático instalado: el puente arranca solo al iniciar sesión en la Mac."
    ;;
  uninstall-autostart)
    [[ -f "$PLIST" ]] && { launchctl unload -w "$PLIST" 2>/dev/null; rm -f "$PLIST"; echo "Inicio automático quitado."; } || echo "No estaba instalado."
    ;;
  *)
    cat <<'TXT'
Puente Pacientes ↔ Cursor
  ./bridge.sh setup                 instala el entorno (una vez)
  ./bridge.sh test                  prueba la conexión con el servidor sin usar Cursor (procesa 1 trabajo de prueba si hay)
  ./bridge.sh start | stop | status | logs
  ./bridge.sh import [--dry-run]    carga la biblioteca extraída en el servidor (solo texto)
  ./bridge.sh install-autostart     que arranque solo con la Mac (launchd)
  ./bridge.sh uninstall-autostart
Necesita en ~/Desktop/Fluxus/.env:  CURSOR_API_KEY=...  y  PACIENTES_BRIDGE_TOKEN=...
TXT
    ;;
esac
