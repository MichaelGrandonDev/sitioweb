# Smoke QA · FluxusTerapia

- **Base:** https://www.fluxusterapia.com
- **Generado:** 2026-09-25 13:42:54 -03
- **Resultado:** 61 PASS / 0 FAIL / 61 total

## Resumen por área

- **acceso:** 10/10 OK
- **velocidad:** 14/14 OK
- **fluidez:** 8/8 OK
- **privacidad:** 29/29 OK

## Sin errores críticos

Accesos, velocidad, fluidez y privacidad dentro de lo esperado.

## Checklist

- [PASS] (info/acceso) HTTP / — status=200 ttfb=0.248s total=0.248s size=18491
- [PASS] (info/velocidad) Rápido / — ttfb=0.248s
- [PASS] (info/acceso) HTTP /galeria/ — status=200 ttfb=0.247s total=0.247s size=9605
- [PASS] (info/velocidad) Rápido /galeria/ — ttfb=0.247s
- [PASS] (info/acceso) HTTP /blogs/ — status=200 ttfb=0.188s total=0.188s size=4839
- [PASS] (info/velocidad) Rápido /blogs/ — ttfb=0.188s
- [PASS] (info/acceso) HTTP /turnos/ — status=200 ttfb=0.187s total=0.187s size=4279
- [PASS] (info/velocidad) Rápido /turnos/ — ttfb=0.187s
- [PASS] (info/acceso) HTTP /academia/login.php — status=200 ttfb=0.194s total=0.194s size=1490
- [PASS] (info/velocidad) Rápido /academia/login.php — ttfb=0.194s
- [PASS] (info/acceso) HTTP /css/style.css — status=200 ttfb=0.251s total=0.251s size=14937
- [PASS] (info/velocidad) Rápido /css/style.css — ttfb=0.251s
- [PASS] (info/acceso) HTTP /js/main.js — status=200 ttfb=0.185s total=0.185s size=6445
- [PASS] (info/velocidad) Rápido /js/main.js — ttfb=0.185s
- [PASS] (info/acceso) HTTP /blogs/api.php?limit=3 — status=200 ttfb=0.191s total=0.191s size=248
- [PASS] (info/velocidad) Rápido /blogs/api.php?limit=3 — ttfb=0.191s
- [PASS] (info/acceso) HTTP /turnos/api.php?action=therapies — status=200 ttfb=0.194s total=0.194s size=2824
- [PASS] (info/velocidad) Rápido /turnos/api.php?action=therapies — ttfb=0.194s
- [PASS] (info/velocidad) TTFB promedio páginas clave — avg=0.210s n=9
- [PASS] (info/velocidad) Home TTFB warm (x3) — samples=[0.242, 0.241, 0.238] avg=0.240s
- [PASS] (info/fluidez) Link galería en home — ok
- [PASS] (info/fluidez) Link blogs en home — ok
- [PASS] (info/fluidez) Link AcademiaFluxus — ok
- [PASS] (info/fluidez) CTA/contacto o WhatsApp float — ok
- [PASS] (info/fluidez) Galería justified + lightbox hooks — ok
- [PASS] (high/fluidez) Galería carga imágenes — status=200
- [PASS] (high/fluidez) Turnos UI + API hooks — ok
- [PASS] (high/fluidez) Blogs API usable — count=1
- [PASS] (info/privacidad) Bloqueado /academia/data/ — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /turnos/data/ — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /blogs/data/ — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /academia/data/academia.sqlite — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /turnos/data/turnos.sqlite — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /blogs/data/blogs.sqlite — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /academia/migrate_gym.php — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /turnos/migrate_gym.php — status=403 leaked=False
- [PASS] (info/privacidad) Bloqueado /.env — status=406 leaked=False
- [PASS] (info/privacidad) Bloqueado /.git/config — status=404 leaked=False
- [PASS] (info/privacidad) Bloqueado /ACCESOS.md — status=404 leaked=False
- [PASS] (info/privacidad) Config sin leak /academia/config.php — status=200 size=0
- [PASS] (info/privacidad) Config sin leak /turnos/config.php — status=200 size=0
- [PASS] (info/privacidad) Config sin leak /blogs/config.php — status=200 size=0
- [PASS] (high/privacidad) Admin gate /academia/admin/users.php — status=200 url=https://www.fluxusterapia.com/academia/login.php
- [PASS] (high/privacidad) Admin gate /academia/dashboard.php — status=200 url=https://www.fluxusterapia.com/academia/login.php
- [PASS] (high/privacidad) Admin gate /turnos/admin.php — status=200
- [PASS] (high/privacidad) Admin gate /blogs/admin.php — status=200
- [PASS] (high/privacidad) API sin listado appointments — status=400 body={"ok":false,"error":"Acción no válida"}
- [PASS] (high/privacidad) API sin listado bookings — status=400 body={"ok":false,"error":"Acción no válida"}
- [PASS] (high/privacidad) API sin listado list — status=400 body={"ok":false,"error":"Acción no válida"}
- [PASS] (high/privacidad) API sin listado export — status=400 body={"ok":false,"error":"Acción no válida"}
- [PASS] (high/privacidad) API sin listado admin — status=400 body={"ok":false,"error":"Acción no válida"}
- [PASS] (info/privacidad) Sin secretos en / — ok
- [PASS] (info/privacidad) Sin secretos en /turnos/ — ok
- [PASS] (info/privacidad) Sin secretos en /blogs/ — ok
- [PASS] (info/privacidad) Sin secretos en /academia/login.php — ok
- [PASS] (info/privacidad) Sin secretos en /css/style.css — ok
- [PASS] (info/privacidad) Sin secretos en /js/main.js — ok
- [PASS] (medium/acceso) HTTP→HTTPS redirect — final=https://fluxusterapia.com/ status=200
- [PASS] (low/velocidad) Cache headers /css/style.css — presente
- [PASS] (low/velocidad) Cache headers /js/main.js — presente
- [PASS] (low/velocidad) Cache headers /img/hero.jpg — presente
