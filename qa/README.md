# QA automatizado · FluxusTerapia

## Suites

```bash
# Contenido + branding + seguridad migrate
python3 qa/run_qa.py

# Smoke: velocidad, fluidez, accesos, privacidad
python3 qa/run_smoke.py
```

## Reportes

- `qa/reports/latest.md` — último QA completo
- `qa/reports/smoke-latest.md` — último smoke
- Copias con timestamp `.md` / `.json`

Exit code `1` si hay FAIL (útil para CI). En smoke, los FAIL `low` (p.ej. cache) no rompen el exit.

## Qué cubre el smoke

- **Accesos:** páginas públicas, HTTPS
- **Velocidad:** TTFB / total (umbrales 0.8s warn / 2s fail)
- **Fluidez:** links home→galería/blogs/academia/turnos, APIs
- **Privacidad:** SQLite/data, configs, admin gates, migrate, `.env`/`.git`, APIs sin listados

## Migrates

Requieren `?key=FluxusMigrate2026!`
