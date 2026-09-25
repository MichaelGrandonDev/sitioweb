# QA Report · FluxusTerapia

- **Base:** https://www.fluxusterapia.com
- **Generado:** 2026-09-25 12:58:11 -03
- **Resultado:** 43 PASS / 0 FAIL / 43 total

## Sin errores

No se detectaron fallos en la suite automática.

## Checklist completo

- [PASS] (info) HTTP / — status=200 expected=200
- [PASS] (info) HTTP /galeria/ — status=200 expected=200
- [PASS] (info) HTTP /blogs/ — status=200 expected=200
- [PASS] (info) HTTP /turnos/ — status=200 expected=200
- [PASS] (info) HTTP /academia/login.php — status=200 expected=200
- [PASS] (info) HTTP /css/style.css — status=200 expected=200
- [PASS] (info) HTTP /js/main.js — status=200 expected=200
- [PASS] (info) HTTP /img/hero.jpg — status=200 expected=200
- [PASS] (info) HTTP /img/galeria/masoterapia.jpg — status=200 expected=200
- [PASS] (info) HTTP /blogs/admin.php — status=200 expected=200
- [PASS] (info) HTTP /turnos/admin.php — status=200 expected=200
- [PASS] (info) HTTP /blogs/api.php?limit=3 — status=200 expected=200
- [PASS] (info) HTTP /turnos/api.php?action=therapies — status=200 expected=200
- [PASS] (info) Nav incluye Blogs — ok
- [PASS] (info) Nav incluye AcademiaFluxus — ok
- [PASS] (info) Nav no incluye Turnos — ok
- [PASS] (info) Servicio gym pre/post parto — ok
- [PASS] (info) Chi kung Online en servicios — ok
- [PASS] (info) Taichi Online en servicios — ok
- [PASS] (info) Consultorios Punta Alta — ok
- [PASS] (info) Consultorios modalidad Online — ok
- [PASS] (info) Galería justified — ok
- [PASS] (info) Header Instagram brand — ok
- [PASS] (info) Header YouTube brand — ok
- [PASS] (info) Header sin WhatsApp — ok
- [PASS] (info) Float WhatsApp presente — ok
- [PASS] (info) Sin branding viejo AcademiFluxus — ok
- [PASS] (info) Sin branding viejo FluxusAcademia — ok
- [PASS] (high) CSS gallery-justified — ok
- [PASS] (medium) JS initLandingBlogs — 
- [PASS] (medium) JS initGalleryLightbox — 
- [PASS] (critical) Turnos API JSON válido — status=200
- [PASS] (high) Turnos incluye Curso gym pre y post parto — Masoterapia, Rehabilitación kinésica, Medicina china, Talleres hipopresivos, Chi kung Online, Taichi Online, Curso gym pre y post parto
- [PASS] (high) Turnos incluye Chi kung Online — Masoterapia, Rehabilitación kinésica, Medicina china, Talleres hipopresivos, Chi kung Online, Taichi Online, Curso gym pre y post parto
- [PASS] (high) Turnos incluye Taichi Online — Masoterapia, Rehabilitación kinésica, Medicina china, Talleres hipopresivos, Chi kung Online, Taichi Online, Curso gym pre y post parto
- [PASS] (high) Blogs API posts — count=1
- [PASS] (medium) Turnos nav AcademiaFluxus — 
- [PASS] (low) Turnos pie actualizado — 
- [PASS] (low) Turnos sin Incontro viejo — 
- [PASS] (high) Seguridad migrate bloqueado /academia/migrate_gym.php — status=403; open=False; locked=True
- [PASS] (high) Seguridad migrate bloqueado /academia/migrate_online.php — status=403; open=False; locked=True
- [PASS] (high) Seguridad migrate bloqueado /turnos/migrate_gym.php — status=403; open=False; locked=True
- [PASS] (high) Seguridad migrate bloqueado /turnos/migrate_online.php — status=403; open=False; locked=True
