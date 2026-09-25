# Fluxus Academia (plataforma de cursos)

Mini LMS en PHP + SQLite, integrado al sitio en `/academia/`.

## Qué incluye

- Login con usuario/contraseña (sin registro público)
- Admin crea alumnos y les asigna cursos
- Cursos: Chikung, Hipopresivos, Taichi, Kinesiología (y los que agregues)
- Clases con link de **YouTube** o **Google Drive**
- Tareas por clase (el alumno marca y puede dejar nota)
- Diseño alineado a FluxusTerapia

## Instalación en HostGator

1. Subí la carpeta `public_html/academia/` dentro de `fluxusterapia.com/`
   (queda en `.../fluxusterapia.com/academia/`).
2. Abrí una sola vez:  
   `https://fluxusterapia.com/academia/install.php`
3. Ingresá con:
   - Usuario: `admin`
   - Contraseña: `FluxusAdmin2026!`  
   (cambiala después en Admin → Usuarios / o recreando admin)
4. En Admin:
   - Creá alumnos y asignales cursos
   - Agregá clases con links de YouTube/Drive
   - Agregá tareas

## Local

```bash
cd public_html
php -S 127.0.0.1:8080
```

Abrí http://127.0.0.1:8080/academia/install.php

## Acceso público

El sitio principal tiene el botón **FluxusAcademia** → `/academia/`.

## Mail de bienvenida

Al dar de alta un alumno (Admin → Alumnos) se pide el **email** y, si está tildado, se envía un correo con:
- mensaje de bienvenida
- usuario y contraseña asignados
- link de ingreso

Configurá el remitente en `academia/config.php` (`mail_from`, `mail_from_name`). En HostGator conviene que exista `hola@fluxusterapia.com`.

## Clase en vivo (Google Meet)

En Admin → Cursos y clases → **Clase en vivo (Meet) por curso**, cargá el link de Meet y el horario semanal. El alumno ve el botón **Unirme a clase en vivo** en el dashboard y dentro de cada curso.
