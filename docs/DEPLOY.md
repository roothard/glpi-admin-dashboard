# Despliegue: staging (pda) y producción (apps)

Este panel corre en **dos sitios** del mismo servidor web:

| Sitio | URL | Rol | Se puede romper |
|---|---|---|---|
| **pda** | `pda.roothard.com.ar` | **staging** — sirve la rama en prueba | **Sí** |
| **apps** | `apps.roothard.com.ar` | **producción** — solo releases tagueadas y validadas | No |

La regla es simple y ahora es **herramienta, no memoria**: **todo pasa por pda primero**; a
apps solo llega lo que ya se validó en pda.

---

## Flujo de release

```
   rama de trabajo                tag de release
        │                              │
        ▼                              ▼
  deploy-pda <rama>   ──validás──►  promote-apps <tag>
   (pda = staging)      en pda       (apps = producción)
                                     └─ rechaza si ese tag
                                        no es lo que corre en pda
```

1. **Desplegar a staging**

   ```bash
   ./deploy/deploy-pda mi-rama        # o un tag, o un commit
   ```

   Hace, en orden: `git fetch` → arma una imagen limpia del ref → **`php -l`** de todo el
   PHP en el servidor → **backup** del sitio vivo → instala como usuario `pda` → escribe el
   marcador `env=staging`. En `pda.roothard.com.ar` aparece el **badge STAGING**.

2. **Validar en pda.** Probá lo que cambiaste en `https://pda.roothard.com.ar`.

3. **Taguear la release** (producción = releases, no ramas sueltas):

   ```bash
   git tag v1.6.0 && git push --tags
   ./deploy/deploy-pda v1.6.0         # dejá el TAG corriendo en pda
   ```

4. **Promover a producción**

   ```bash
   ./deploy/promote-apps v1.6.0
   ```

   Antes de tocar apps verifica dos cosas y **aborta** si fallan:
   - `v1.6.0` es un **tag** (no una rama).
   - el commit de `v1.6.0` es **exactamente** el que corre en pda ahora → *pda-first*.

   Si algo sale mal en producción:

   ```bash
   ./deploy/promote-apps --rollback   # restaura el último backup de apps
   ```

---

## Módulos drop-in (apps propias por sitio)

`public_html/modules/<id>` guarda **módulos drop-in**: apps site-específicas (por
ejemplo `firma`, el generador de firma de correo de 2050dest) que **no están en el
repo** y son contenido local de cada sitio. `promote-apps` mueve solo el código del
repo, así que estos módulos se promueven aparte:

```bash
./deploy/promote-module --list     # ver qué módulos hay en pda y en apps
./deploy/promote-module firma      # copiar 'firma' de pda (staging) a apps (producción)
```

`promote-module` va siempre **pda → apps**, hace `php -l` del módulo, backup del que
hubiera en apps (`backups/module-<id>-<ts>.tgz`) y **no borra** los demás módulos.
Un módulo se desarrolla y valida en pda; recién ahí se promueve a apps.

---

## Configuración (una sola vez)

Los scripts leen la infra de `deploy/deploy.env` (**git-ignored**, no lleva contraseñas —
la auth es por clave SSH). Copiá la plantilla y completala:

```bash
cp deploy/deploy.env.example deploy/deploy.env
$EDITOR deploy/deploy.env
```

| Variable | Qué es |
|---|---|
| `SSH_HOST` / `SSH_PORT` / `SSH_LOGIN` / `SSH_KEY` | acceso al server web (usuario con `sudo` sin password) |
| `PDA_USER` / `PDA_HOME` | usuario de sistema y raíz de instalación de staging |
| `APPS_USER` / `APPS_HOME` | ídem producción |
| `PDA_DEFAULT_BRANCH` | rama que despliega `deploy-pda` sin argumentos |
| `KEEP_BACKUPS` | cuántos backups conservar por sitio (default 10) |

---

## El marcador de entorno y el badge

Cada sitio tiene su propio `config/version.json` (un nivel arriba del docroot, escrito por el
deploy, git-ignored):

```json
{ "env": "staging", "version": "v1.6.0", "ref": "mi-rama",
  "commit": "abc123…", "deployed_at": "2026-09-29T14:00:00-03:00" }
```

- `Settings::env()` lo lee y `config.php` lo expone al front-end en el bloque `env`.
- El panel muestra el **badge «STAGING»** solo cuando `env=staging`.
- **Sin archivo → producción** (badge oculto): un sitio sin marcar nunca se confunde con staging.
- Se puede pisar con variables de entorno `APP_ENV` / `APP_VERSION` (útil en contenedores).

---

## Layout en el servidor (para no redescubrirlo)

Cada sitio vive en el home de su usuario; **el docroot es `public_html` y el resto cuelga un
nivel arriba**:

```
/home/<sitio>/
├── config/            settings.json (secreto, 600) · version.json (marcador) · estado runtime
├── src/               DashboardGenerator.php · GlpiClient.php · Settings.php
├── bin/generate.php   generador del caché (cron)
├── lib.php  rh-auth.php
└── public_html/       docroot (index.html, *.php, vendor/, y extras propios del sitio)
```

⚠️ **El docroot NO es un espejo del repo.** Cada sitio tiene archivos propios que **no están
en el repo** y que el deploy **preserva** (nunca borra): `assets/`, `icon/`, `modules/`, los
symlinks de awstats, `vendor/leaflet`, y el estado en `config/` (`board.json`, `processes.json`,
`login-throttle.json`, `auth-audit.log`). Por eso el deploy **copia sin `--delete`**.

Mapeo repo → servidor que hace el deploy:

| En el repo | En el servidor |
|---|---|
| `public/*` | `public_html/` |
| `docs/index.html` | `public_html/doc.html` |
| `docs/docDash.js` | `public_html/docDash.js` |
| `src/*` | `src/` |
| `lib.php`, `rh-auth.php` | raíz del home |
| `bin/generate.php` | `bin/generate.php` |
| `config/settings.example.json` | `config/settings.example.json` |
| *(generado)* | `config/version.json` |

**Nunca** se tocan: `config/settings.json`, el estado runtime de `config/`, ni los extras del
sitio. Los backups quedan en `/home/<sitio>/backups/<timestamp>.tgz` (se conservan los últimos
`KEEP_BACKUPS`).

---

## Problemas comunes

| Síntoma | Causa / solución |
|---|---|
| `Falta deploy/deploy.env` | copiá `deploy.env.example` → `deploy.env` y completá. |
| `'X' no es un tag` al promover | producción solo acepta tags: `git tag vX && git push --tags`. |
| `El tag no es lo que corre en pda` | desplegá ese tag a pda primero: `./deploy/deploy-pda vX`. |
| `pda no tiene marcador de versión` | nunca se desplegó por script; corré `deploy-pda` una vez. |
| `php -l FALLÓ` | hay un `.php` con error de sintaxis; el deploy abortó sin tocar el sitio. |
| Rompiste producción | `./deploy/promote-apps --rollback`. |
