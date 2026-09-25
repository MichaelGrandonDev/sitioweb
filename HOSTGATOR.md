# FluxusTerapia — HostGator + Instagram

Sitio estático listo para subir a **HostGator** y asociar con Instagram **[@fluxusterapia](https://www.instagram.com/fluxusterapia/)**.

## Qué hay en esta carpeta

- `public_html/` → contenido a subir al hosting (index, css, js, img)
- `deploy.py` → subida automática por FTP
- `.env.example` → plantilla de credenciales (copiar a `.env`)
- `templates/` + `app.py` → versión local Flask (opcional, para desarrollar)

## Deploy automático (recomendado)

1. En cPanel → **Dominios** → `fluxusterapia.com` → anotá el **Document Root** exacto
   (ej. `/home2/mikedeve/fluxusterapia.com` u otra ruta).
2. En cPanel → **Cuentas FTP** → cuenta `Fluxusterapia@fluxusterapia.com` →
   **Cambiar directorio** a ese Document Root (no al home de `mikedeve` ni a
   `public_html` del dominio principal `mike88developer.com`).
3. En esta carpeta:
   ```bash
   cp .env.example .env
   ```
4. En `.env`: `FTP_REMOTE_DIR=/` (la cuenta FTP debe apuntar a
   `/home2/mikedeve/fluxusterapia.com`).
5. Publicar:
   ```bash
   python3 deploy.py          # una vez
   python3 watch_deploy.py    # automático al guardar
   ```

Si el FTP apunta a `public_html` en vez del Document Root, en cPanel →
**Cuentas FTP** → cambiar directorio de `Fluxusterapia@…` a
`fluxusterapia.com` (ruta relativa al home).

## Subida manual (alternativa)

1. Entrá a **cPanel** de HostGator.
2. Abrí **Administrador de archivos** (o FileZilla / FTP).
3. Andá a la carpeta del dominio:
   - Si `fluxusterapia.com` es el dominio principal → `public_html/`
   - Si es un **addon domain** → la carpeta que creaste para ese dominio (a veces `public_html/fluxusterapia.com/`)
4. Subí **todo el contenido** de la carpeta local `public_html/` (no la carpeta en sí, sino lo de adentro):
   - `index.html`
   - `css/`
   - `js/`
   - `img/`
5. Visitá `https://fluxusterapia.com` (puede tardar unos minutos por DNS/caché).

### SSL (https)

En cPanel → **SSL/TLS Status** o **Let's Encrypt** → activá SSL para `fluxusterapia.com` y `www.fluxusterapia.com`.

### Si el dominio no abre

En cPanel → **Dominios** / **Zone Editor**, confirmá que el dominio apunta a HostGator (nameservers de HostGator). Si el dominio está en otro registrador, los nameservers deben ser los que te dio HostGator.

## Asociar con Instagram @fluxusterapia

El sitio ya enlaza a Instagram en el header, hero, sección dedicada, contacto y footer.

En Instagram (app del celular):

1. Abrí el perfil **@fluxusterapia**.
2. **Editar perfil** → **Sitio web** → poné `https://fluxusterapia.com`
3. En la biografía, algo así:  
   `Masoterapia · Coronel Suárez · Turnos por DM`
4. (Opcional) Activá **botón de acción** → “Reservar” / “Contactar” con el mismo link o WhatsApp cuando lo tengas.

Así el dominio y el Instagram quedan cruzados: web → IG e IG → web.

## Probar en local (sin HostGator)

```bash
cd public_html
python3 -m http.server 8080
```

Abrí http://localhost:8080

## Contacto en el sitio

- Email: `hola@fluxusterapia.com` (crealo en cPanel → Cuentas de correo si todavía no existe)
- Instagram: https://www.instagram.com/fluxusterapia/
- Consultorio: Incontro Consultorios, Coronel Suárez
