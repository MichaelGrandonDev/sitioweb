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
3. Ingresá con el usuario y la contraseña que definiste en `academia/config.php`
   (`admin_user` / `admin_pass`) **antes** de instalar. Ese archivo vive solo en el
   servidor: el repo trae `CHANGE_ME` y `deploy.py` nunca lo sube.
   Después cambiá la contraseña desde Admin → Administradores.
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

## Foto y descripción del curso

En Admin → Cursos, al crear o editar un curso se puede subir una **foto del curso** (JPG, PNG o WebP, hasta 15 MB) y escribir la **descripción (la ve el alumno)**. En la edición también se puede cambiar la foto o tildar **Quitar la foto**.

- La foto se convierte a **WebP** de hasta 1600 px de lado (las chicas no se agrandan) y se endereza según el EXIF.
- Es **privada**: se guarda en `data/uploads/` y se sirve por `cover.php` solo a quien tiene sesión y acceso al curso (sin sesión manda al login; sin acceso, 403).
- El alumno ve la foto en la tarjeta de **Mis cursos** y como cabecera del curso, con la descripción completa (respeta los saltos de línea).
- Test: `php qa/test_course_cover.php`.

## Clases grabadas: orden, vistas y bonus

En Admin → **Clases**, elegí el curso. Abajo aparecen dos listas: **Clases del curso** y **Bonus (x de 3)**.

- **Ordenar**: el alumno ve las clases numeradas (Clase 1, 2, 3…) en el orden de la lista. Usá las flechas ↑ ↓ de cada clase, o en «Editar» cambiá el **Número de clase**. Las clases nuevas se agregan al final.
- **Bonus**: hasta **3 por curso**. Para crear una, tildá **«Es una clase bonus»** al subir el video o pegar el link; para convertir una que ya existe, tocá **«Pasar a bonus»** (y **«Quitar de bonus»** para volverla a la lista normal, al final). Si ya hay 3, el panel avisa y no deja agregar otra.
- Las bonus **no cuentan para el progreso** del alumno. Por defecto el alumno las ve **con candado** hasta completar todas las clases del curso; ahí aparece un mensaje «¡Buen trabajo!» que lo invita a verlas. El bloqueo se valida también en el servidor (no se pueden abrir por URL).
- Para que las bonus estén **siempre visibles**, cambiá `RECORDING_BONUS_NEEDS_COMPLETION` a `false` en `academia/includes/recordings.php` (el máximo se cambia en `RECORDING_MAX_BONUS`).
- El alumno marca cada clase con **«Marcar como vista»** (en la lista o en la página de la clase; se puede desmarcar). Los videos subidos al campus se marcan solos al llegar a ~90 %; los de YouTube/Drive, solo con el botón.
- Test: `php qa/test_clases_progreso.php`.
