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
