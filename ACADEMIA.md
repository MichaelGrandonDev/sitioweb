# Fluxus Academia (plataforma de cursos)

Mini LMS en PHP + SQLite, integrado al sitio en `/academia/`.

## Qué incluye

- Login con usuario/contraseña (sin registro público)
- Admin crea alumnos y les asigna cursos
- Cursos: Chikung, Hipopresivos, Taichi, Kinesiología (y los que agregues)
- Clases con link de **YouTube** o **Google Drive**
- Tareas por clase (el alumno marca y puede dejar nota)
- Diseño alineado a FluxusTerapia

## Roles: administrador general y profesor

Hay tres tipos de cuenta:

| Rol | Quién es | Qué puede hacer |
|-----|----------|-----------------|
| **Administrador general** (`admin`) | Quien lleva toda la academia | Todo: alumnos, inscripciones, pagos, cursos, sitio, recordatorios, crear otros admins y profesores |
| **Profesor** (`teacher`) | Subadministrador de uno o más cursos | Solo lo que el admin le marque: clases/materiales, datos del curso, ver alumnos (lectura), responder consultas de *sus* cursos |
| **Alumno** (`student`) | Quien cursa | Campus: clases, materiales, consultas |

### Cómo dar de alta un profesor

1. Entrá como administrador general → **Administradores / Profesores**.
2. En **Nuevo profesor**: nombre, usuario, contraseña.
3. Marcá los **cursos** que va a dictar.
4. Revisá los **permisos** (por defecto puede subir clases/materiales, editar info del curso, ver alumnos y responder consultas; *no* puede crear alumnos ni tocar pagos).
5. El profesor entra por el **Portal de profesores**: `login.php?as=profesor` (también hay un link en la pantalla de ingreso del alumno). Llega a `/academia/profesor/` con su panel, perfil, cursos y consultas.

### Qué no puede hacer el profesor (aunque abra la URL a mano)

Crear/borrar alumnos, inscripciones, pagos/cuotas/tienda, recordatorios globales, editar el sitio, crear otros admins, crear o borrar cursos ajenos. El servidor responde con un aviso y lo manda de vuelta al panel.

### Portal de profesores (`/academia/profesor/`)

Plataforma propia del docente (misma lógica que el subadministrador, con menú y branding aparte):

- **Panel** — avance de sus cursos y accesos rápidos  
- **Mi perfil** — foto, nombre, email, presentación y contraseña; opción de aplicar esos datos a “Tu profesor” en sus cursos  
- **Clases / Materiales / Mis cursos / Alumnos / Consultas** — según los permisos que le dio el admin general  

El administrador general sigue en `/academia/admin/`. Si un profesor abre una URL de admin, el sistema lo manda al portal de profesores.

### Perfil del profesor en el curso

Sigue existiendo el bloque “Tu profesor” (nombre, bio, foto) por curso. Al asignar un profesor a un curso, si el nombre del profesor del curso estaba vacío, se completa con el nombre de la cuenta. El profesor puede editarlo en **Mis cursos** o desde **Mi perfil** (con “Aplicar a mis cursos”).

Test: `php qa/test_roles_profesor.php`.

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

## Profesor del curso

Cada curso puede tener su propio **profesor**: nombre, breve descripción y foto (opcionales). Se editan en Admin → Cursos, en el mismo formulario del curso.

- La foto del profesor se guarda como **WebP** de hasta 1000 px y se sirve por `teacher.php` (misma privacidad que la portada).
- El alumno lo ve en la página del curso (“Tu profesor”) y, de forma compacta, en **Mis cursos** y en **Clases**.
- Si no hay datos cargados, el bloque no se muestra. Test: `php qa/test_teacher_profile.php`.

## Clases grabadas: orden, vistas y bonus

En Admin → **Clases**, elegí el curso. Abajo aparecen dos listas: **Clases del curso** y **Bonus (x de 3)**.

- **Ordenar**: el alumno ve las clases numeradas (Clase 1, 2, 3…) en el orden de la lista. Usá las flechas ↑ ↓ de cada clase, o en «Editar» cambiá el **Número de clase**. Las clases nuevas se agregan al final.
- **Bonus**: hasta **3 por curso**. Para crear una, tildá **«Es una clase bonus»** al subir el video o pegar el link; para convertir una que ya existe, tocá **«Pasar a bonus»** (y **«Quitar de bonus»** para volverla a la lista normal, al final). Si ya hay 3, el panel avisa y no deja agregar otra.
- Las bonus **no cuentan para el progreso** del alumno. Por defecto el alumno las ve **con candado** hasta completar todas las clases del curso; ahí aparece un mensaje «¡Buen trabajo!» que lo invita a verlas. El bloqueo se valida también en el servidor (no se pueden abrir por URL).
- Para que las bonus estén **siempre visibles**, cambiá `RECORDING_BONUS_NEEDS_COMPLETION` a `false` en `academia/includes/recordings.php` (el máximo se cambia en `RECORDING_MAX_BONUS`).
- El alumno marca cada clase con **«Marcar como vista»** (en la lista o en la página de la clase; se puede desmarcar). Los videos subidos al campus se marcan solos al llegar a ~90 %; los de YouTube/Drive, solo con el botón.
- Test: `php qa/test_clases_progreso.php`.

## Recordatorios por mail a alumnos

Desde **Admin → Recordatorios** podés recordarles a los alumnos que ya tienen acceso al campus y que todavía les quedan clases por ver (las bonus no cuentan).

### Qué hace el admin

1. Activá la **automatización global** y elegí cada cuántos días (por defecto 7).
2. Editá el asunto y el texto de introducción del mail.
3. En la lista, **Activar** o **Dejar de enviar** alumno por alumno.
4. El estado muestra: Activo / Pausado / Completó (ya no se envía) / Sin email / último envío.
5. «Simular corrida» cuenta candidatos **sin** mandar mails.

El sistema deja de enviar solo cuando el alumno **completó todas las clases** de sus cursos (sin bonus) o cuando el admin lo pausa a mano.

### Cron en HostGator

1. En el servidor, en `academia/config.php`, poné una clave secreta en `reminder_cron_key` (no dejes `CHANGE_ME`).
2. Programá un cron **diario** (por ejemplo a las 10:00) que llame:

```bash
curl -fsS "https://www.fluxusterapia.com/academia/cron_reminders.php?key=TU_CLAVE"
```

También podés probar en seco: agregá `&dry=1` (no envía mails).
En el panel de HostGator: Cron Jobs → comando `curl` como arriba → frecuencia diaria.

### Seguridad y mail

- El cron exige la clave (`?key=` o header `X-Reminder-Key`).
- Hay un tope de envíos por corrida y un mínimo de 30 s entre corridas.
- Los mails usan el mismo `mail()` / remitente que la bienvenida (`mail_from` en `config.php`).
- Test de lógica: `php qa/test_reminders.php`.
