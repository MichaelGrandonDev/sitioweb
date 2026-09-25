# FluxusTerapia

Sitio web en producción para **FluxusTerapia** (masoterapia, rehabilitación y bienestar).

**Live:** [https://www.fluxusterapia.com](https://www.fluxusterapia.com)

Autor: [Michael Grandon](https://github.com/MichaelGrandonDev) — desarrollo + QA.

---

## Qué incluye

| Módulo | Descripción |
|--------|-------------|
| Landing | Página pública de servicios, contacto e Instagram |
| AcademiaFluxus | Campus online: cursos, lecciones, alumnos, cuotas, multi-admin |
| Cursos grabados | Tienda pública con carrito, pago invitado y acceso por email |
| Galería | CMS de fotos con panel admin |
| Blogs | CMS de artículos con panel admin |
| Turnos | Reservas y seña / depósito (Mercado Pago) |
| QA | Smoke tests y reportes automatizados contra el sitio live |
| Deploy | Publicación por FTP a HostGator |

---

## Lenguajes y tecnologías

### Lenguajes
- **HTML5** — estructura del sitio y landing
- **CSS3** — estilos responsive (landing, academia, cursos, blogs, turnos)
- **JavaScript** — interacción del front (UI, turnos)
- **PHP** — backend de academia, cursos, galería, blogs, turnos, webhooks
- **Python 3** — deploy FTP, watch-deploy y suite de QA/smoke
- **SQL** (SQLite) — persistencia de alumnos, cursos, pagos, contenidos
- **Apache / `.htaccess`** — rewrite, HTTPS, protección de carpetas `data/`

### Stack y herramientas
- **SQLite** — bases embebidas por módulo (`academia`, `blogs`, `galería`, `turnos`)
- **Mercado Pago** — pagos / seña (API + webhooks)
- **SMTP / `mail()`** — mails de acceso e inscripción (HostGator)
- **FTP / FTPS** — deploy automatizado (`deploy.py`, `watch_deploy.py`)
- **cPanel / HostGator** — hosting, SSL, DNS, File Manager
- **Git / GitHub** — control de versiones
- **QA automatizado** — `qa/run_smoke.py`, `qa/run_qa.py` + reportes JSON/Markdown

### Rol QA (ejemplos cubiertos)
- Smoke de rutas públicas y privadas
- Checks de acceso / login gate en admin
- Redirect HTTP→HTTPS
- Reportes versionados en `qa/reports/`

---

## Estructura

```
public_html/          → sitio publicado en HostGator
  index.html          → landing
  academia/           → campus + admin
  cursos/             → tienda de cursos grabados
  galeria/ blogs/ turnos/
qa/                   → scripts y reportes de QA
deploy.py             → subida FTP
watch_deploy.py       → deploy al guardar
docs/                 → flujo para administradores
```

---

## Cómo correr QA (local)

```bash
python3 qa/run_smoke.py --base https://www.fluxusterapia.com
python3 qa/run_qa.py --base https://www.fluxusterapia.com
```

Los reportes quedan en `qa/reports/`.

---

## Deploy

1. Copiá `.env.example` → `.env` y completá FTP (sin subir `.env` a Git).
2. `python3 deploy.py`

Los `config.php` del repo usan placeholders (`CHANGE_ME`). Las credenciales reales viven solo en el servidor.

---

## Nota de seguridad

Este repositorio es portfolio-friendly: **no incluye** `.env`, contraseñas reales ni bases SQLite de producción.
