# Pendiente — Soporte de la API nueva de GLPI 11

> Estado: **pendiente / diseño** (rama `feat/glpi11-api`).
> Se implementa DESPUÉS del multi-tenant. GLPI Projects (OSS) tarea #282.

## Motivo

La herramienta habla la **API REST legacy** de GLPI (`apirest.php`, App-Token +
Session-Token), que es la de GLPI 9/10. En **GLPI 11** esa API quedó **deprecada**:
sigue existiendo pero puede estar desactivada, y a futuro puede desaparecer.

Verificado en el homelab GLPI 11.0.8:

```
GET http://<glpi11>/apirest.php/initSession
→ 400  ["ERROR","API desactivada"]
```

`apirest.php` está presente (no da 404) pero la API está apagada en la config del
GLPI. Es decir: hoy **funcionaría** si se habilita la API legacy, pero conviene no
depender de algo deprecado.

## Objetivo

Soportar la **API nueva de alto nivel de GLPI 11** (basada en **OAuth2**), sin perder
la compatibilidad con GLPI 9/10.

## Enfoque propuesto

- **Abstraer el acceso** detrás de `GlpiClient`: dos back-ends (legacy vs. GLPI 11),
  con una interfaz común (initSession/get/getAll/write).
- **Auto-detección** de versión/API del GLPI destino (probe de capacidades).
- **OAuth2** (client credentials) para la API nueva: obtención y refresco de token,
  en vez de App-Token + Session-Token.
- **Mapeo** de endpoints y campos que cambiaron entre la legacy y la nueva.
- **Config**: por conexión (o por tenant, si convive con el multi-tenant), elegir/
  autodetectar el modo de API. Secretos (client_id/secret) en Vault.

## Alcance / criterios de aceptación

- Conectar a un GLPI 11 por la API nueva (OAuth2) y listar Proyectos/Tickets/Entidades.
- Mantener GLPI 9/10 por la legacy sin regresiones.
- Smoke test contra un GLPI 11 real (initSession-equivalente + lecturas básicas).

## Notas

- No confundir con el multi-tenant (repo privado aparte): esto es una capacidad
  general de la herramienta y va al repo público.
