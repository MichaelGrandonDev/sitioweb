# FluxusTerapia — Configurar DNS (HostGator)

El sitio **ya está subido** al servidor. Si `https://fluxusterapia.com` no abre, casi siempre es DNS.

## Qué tiene que quedar configurado

| Tipo | Nombre | Valor |
|------|--------|--------|
| **A** | `@` | `162.241.61.0` |
| **A** | `www` | `162.241.61.0` |

Alternativa para `www`:
| Tipo | Nombre | Valor |
|------|--------|--------|
| **CNAME** | `www` | `fluxusterapia.com` |

Nameservers (ya los tenías bien):
- `ns002.hostgator.net`
- `ns003.hostgator.net`

## Pasos en cPanel

1. Entrá a [https://billing.hostgator.ar/](https://billing.hostgator.ar/)
2. Abrí tu hosting → **cPanel**
3. Buscá **Zone Editor** (o **Editor de zona DNS**)
4. Elegí el dominio **fluxusterapia.com**
5. Confirmá o creá:
   - Registro **A** para `@` (o `fluxusterapia.com`) → `162.241.61.0`
   - Registro **A** para `www` → `162.241.61.0`
6. Guardá los cambios

## Activar HTTPS (SSL)

Cuando el dominio ya resuelva:

1. En cPanel → **SSL/TLS Status** o **Let's Encrypt**
2. Activá SSL para `fluxusterapia.com` y `www.fluxusterapia.com`

## Cuánto tarda

- A veces minutos
- Puede tardar hasta **24–48 horas** en propagar

Para probar si ya resolvió:
- Abrí https://fluxusterapia.com
- O en terminal: `dig +short fluxusterapia.com`

## Volver a publicar el sitio

Cuando el DNS esté bien, si cambiás el sitio en tu Mac:

```bash
cd ~/Desktop/Fluxus
python3 deploy.py
```

## Seguridad

Si compartiste la contraseña FTP en el chat, cambiala en cPanel → **Cuentas FTP** → **Cambiar contraseña**, y actualizá el archivo `.env` local.
