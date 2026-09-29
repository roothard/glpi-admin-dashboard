# API GLPI v2 — compatibilidad con GLPI 11 y 12

> Estado: **pendiente / diseño** (rama `feat/glpi-api-v2`, repo público).
> Se implementa DESPUÉS del multi-tenant. Proyecto GLPI **#46**.

## Motivo

La herramienta habla la **API REST legacy** de GLPI (`apirest.php`, App-Token +
Session-Token), que es la de GLPI 9/10. En **GLPI 11** esa API quedó **deprecada**
(sigue existiendo pero puede estar desactivada, y a futuro puede desaparecer), y
**GLPI 12 está en camino**. Hay que dejar la herramienta lista para las dos.

Verificado en el homelab GLPI 11.0.8:

```
GET http://<glpi11>/apirest.php/initSession
→ 400  ["ERROR","API desactivada"]
```

`apirest.php` está presente (no da 404) pero la API está apagada en la config. Es
decir: hoy *funcionaría* si se habilita la legacy, pero conviene no depender de algo
deprecado.

## Objetivo

Soportar la **API nueva de alto nivel de GLPI 11** (basada en **OAuth2**) y quedar
**listos para GLPI 12**, sin perder compatibilidad con GLPI 9/10.

## Principio: diseño *version-agnostic*

El acceso se abstrae para que **agregar GLPI 12 no sea rehacer** nada: con el cliente
abstracto + auto-detección de versión, 12 entra sumando (si hace falta) su back-end y
su smoke test, no reescribiendo.

## Enfoque propuesto

- **Abstraer el acceso** detrás de `GlpiClient`: interfaz común
  (initSession/get/getAll/write) con back-ends intercambiables — **legacy** (9/10) y
  **v2** (11+).
- **Auto-detección** de versión/API del GLPI destino (probe de capacidades) para
  elegir el back-end.
- **OAuth2** (client credentials) para la API nueva: obtención y refresco de token, en
  vez de App-Token + Session-Token. `client_id`/`client_secret` en **Vault**.
- **Mapeo** de endpoints y campos que cambiaron entre legacy y v2.
- **Config** por conexión (o por tenant, si convive con el multi-tenant): elegir o
  autodetectar el modo de API.

## Alcance / criterios de aceptación

- Conectar a un **GLPI 11** por la API nueva (OAuth2) y listar Proyectos/Tickets/
  Entidades sin regresiones respecto de GLPI 10.
- Mantener GLPI 9/10 por la legacy sin romper nada.
- **GLPI 12**: correr su smoke test cuando la versión esté disponible; el cliente
  abstracto debería absorberlo con cambios mínimos.

## Notas

- No confundir con el multi-tenant (repo privado aparte): esto es una capacidad
  general de la herramienta y va al repo **público**.

## Referencias (doc oficial de GLPI)

Documentación de desarrollador de GLPI (rama `master`, cubre GLPI 11):

- **Developer API (índice):** https://glpi-developer-documentation.readthedocs.io/en/master/devapi/index.html
- **High-Level API (v2):** https://glpi-developer-documentation.readthedocs.io/en/master/devapi/hlapi/index.html
  - Schemas: `devapi/hlapi/schemas.html` · Search: `devapi/hlapi/search.html` · Versioning: `devapi/hlapi/versioning.html`
- **Guía de upgrade a GLPI 11.0:** https://glpi-developer-documentation.readthedocs.io/en/master/upgradeguides/glpi-11.0.html
- **Plugins:** https://glpi-developer-documentation.readthedocs.io/en/master/plugins/index.html
- **Checklists:** https://glpi-developer-documentation.readthedocs.io/en/master/checklists/index.html
- **Coding standards:** https://glpi-developer-documentation.readthedocs.io/en/master/codingstandards.html
- **Source code management:** https://glpi-developer-documentation.readthedocs.io/en/master/sourcecode.html
- **Packaging:** https://glpi-developer-documentation.readthedocs.io/en/master/packaging.html

### Hechos confirmados en la doc (base del diseño)

- La **HL API** existe desde **GLPI 11.0**; el front-controller es **`api.php`** (la
  guía muestra el path stateless `#^/front/api.php/#`). Negocia versión con el header
  **`GLPI-API-Version`** (se fija en el request y se lee la efectiva en la respuesta);
  rutas y schemas se filtran por versión pedida (o la última por defecto).
- La HL API usa métodos `getOneBySchema`/`searchBySchema`/`createBySchema`/
  `updateBySchema`/`deleteBySchema` (motor `\Glpi\Api\HL\Search`), desacoplados de las
  search options del legacy → el **mapeo de campos (#286)** parte de los *schemas*, no
  de las search options.
- **GLPI 11 elimina el auto-sanitize de entrada:** cualquier dato (formulario, base o
  **API**) viaja **en crudo**. El legacy de GLPI 9/10 devuelve el `content` con entidades
  HTML (`&#60;p&#62;`); en 11 llega sin codificar. El **render del tablero no debe asumir
  doble codificación** cuando el back-end es v2 (o legacy sobre 11). Detalle:
  `upgradeguides/glpi-11.0.html#removal-of-input-variables-auto-sanitize`.

### Detección de API (implementado en #284)

`GlpiProbe` clasifica sin autenticar: legacy viva = `GET /apirest.php/initSession` con
primer elemento `ERROR_*` específico o HTTP 200; v2 presente = header `GLPI-API-Version`
en `GET /api.php/`. Sin señal clara → **fallback legacy**. Pendiente para #285: endpoint
del token OAuth2 y grants soportados (client_credentials vs password) — confirmar en la
*getting started* de la HL API contra el homelab 11.
