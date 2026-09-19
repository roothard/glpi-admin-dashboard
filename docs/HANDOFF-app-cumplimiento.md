# App Cumplimiento — punto de partida

Documento para retomar en otra sesión. Contiene la idea, **las decisiones ya tomadas con su
razonamiento**, los hallazgos técnicos verificados, y qué falta.

> **Estado al 19-sep-2026:** diseño acordado, **sin código escrito**. Registrado en GLPI como
> tarea **#225** del proyecto **#34 (GLPI Projects Dashboard OSS)**.
>
> **ACTUALIZACIÓN (mismo día): v1 IMPLEMENTADA y en producción** (tarea #225 cerrada). Dos
> cambios sobre este diseño, pedidos al probarla: el criterio es **solo por categoría** con un
> desplegable de las categorías reales de GLPI (el modo "texto en título" quedó soportado en el
> backend pero sin UI), y cada proceso lleva **entidad opcional** (desplegable de entidades de
> GLPI): la evidencia se filtra por ella y la lista se agrupa por entidad, porque con varios
> clientes el mismo proceso se repite por cliente. Nombres definidos: **Compliance** (esta app)
> y **Health** (el futuro panel de Estado).

---

## 1. Qué es

Cuarta app del dock de `glpi-admin-dashboard`, junto a Proyectos, Fichadas y Tickets.

A diferencia de las tres actuales, que miran hacia adentro del área de IT, **esta se proyecta en
una reunión de dirección**. Responde una sola pregunta:

> **¿Cumplimos los procesos críticos?**

Verificación de backups de VM y de archivos, pruebas de restauración y de DRP, revisión de
parches, revisión de alertas de seguridad.

---

## 2. La distinción que define todo el diseño

Lo que se pidió originalmente mezclaba dos naturalezas distintas. Separarlas fue la decisión
más importante:

| | Ejemplo | Qué es | Fuente de verdad |
|---|---|---|---|
| **Proceso** | Backup verificado, prueba de DRP | Algo que **debe ocurrir cada N tiempo** y deja evidencia | **GLPI** (tickets) |
| **Estado** | Alertas activas, parches pendientes | Una **foto del momento** | El sistema que lo mide |

**Decisión: dos paneles separados.** Cumplimiento primero; Estado como quinto cubo, después.

Razones: audiencias distintas (dirección vs. operación), cadencia distinta (mensual vs. ahora),
y dependencias distintas — **Cumplimiento funciona solo con GLPI; Estado necesita conectores
externos que quien instale el producto puede no tener**.

### El enfoque que hace que esto sirva

> **El panel no verifica los backups. Verifica que alguien verificó los backups.**

Es la diferencia entre monitoreo y gobierno. A una gerencia no le sirve "el disco está al 80%";
le sirve "el proceso de verificación se cumplió 12 de los últimos 12 ciclos".

Y es lo que evita competir con Grafana/Zabbix, que hacen lo otro mucho mejor.

---

## 3. ⚠️ Hallazgo que cambió el modelo

**La primera idea era usar los tickets recurrentes de GLPI** (`TicketRecurrent`) como definición
de cada proceso: la periodicidad sería el compromiso y los tickets generados, la evidencia.

**Se verificó contra la API y no es viable.** GLPI **no expone el vínculo** entre un ticket y el
recurrente que lo generó:

```
GET /listSearchOptions/Ticket   → ningún campo de búsqueda relacionado con recurrencia
GET /Ticket/<id>                → el objeto individual tampoco lo trae
```

El campo existe en la base (`glpi_tickets.tickets_id_recurrent`) pero la API no lo publica.

`TicketRecurrent` **sí** se expone y trae `name`, `periodicity`, `is_active`,
`next_creation_date`, `begin_date`, `end_date`, `calendars_id`, `tickettemplates_id`. Lo que no
hay es forma de saber **qué tickets salieron de él**.

---

## 4. Modelo adoptado

Los procesos se definen **en el dashboard**, siguiendo el patrón que el producto ya usa para las
áreas del Kanban (`config/board.json` + `public/board.php`).

```json
{
  "id": "backup-archivos",
  "name": "Verificación de respaldo de archivos",
  "every": "weekly",
  "owner": "Infraestructura",
  "match": { "by": "category", "value": 14 },
  "grace_days": 2
}
```

- `every`: `daily` · `weekly` · `monthly` · `quarterly` · `yearly`
- `match.by`: `category` (recomendado) o `title` (texto contenido, para quien no quiera crear
  categorías en GLPI)
- `grace_days`: margen antes de marcar vencido

**La evidencia son tickets reales de GLPI**, buscados por ese criterio.

### Por qué así

1. La API no permite lo otro (sección 3)
2. **La mayoría de las instalaciones de GLPI no usa tickets recurrentes**; esto funciona igual
3. Si alguien **sí** los usa, funciona también: el recurrente crea el ticket con su categoría y
   el panel lo encuentra
4. El producto ya tiene este patrón, probado y con permisos resueltos
5. **GLPI sigue siendo la fuente de verdad de lo que ocurrió**; el dashboard solo guarda lo que
   se espera. Esa división es la correcta

---

## 5. Los cuatro estados

| Estado | Cuándo |
|---|---|
| **Al día** | El último ticket cerrado cae dentro del período |
| **En curso** | Hay uno abierto y el período no venció |
| **Vencido** | El período venció sin ticket cerrado |
| **Sin datos** | Nunca hubo uno |

⚠️ **"Sin datos" y "Vencido" no son lo mismo** y hay que mostrarlos distinto: uno dice que se
dejó de hacer algo, el otro que nunca se empezó.

---

## 6. La pantalla

```
┌────────────────────────────────────────────────────────────────┐
│  6 de 8 al día        2 vencidos        1 vence esta semana     │
├────────────────────────────────────────────────────────────────┤
│  ● Verificación de backups de VM     hace 2 d    ▓▓▓▓▓▓▓▓▓▓▓▓  │
│  ● Respaldo de archivos              hace 5 d    ▓▓▓▓▓▓▓▓░▓▓▓  │
│  ▲ Revisión de parches            vence en 3 d   ▓▓▓▓▓▓▓░▓▓▓▓  │
│  ■ Prueba de restauración        VENCIDO 23 d    ▓░░▓▓░░▓▓▓▓▓  │
└────────────────────────────────────────────────────────────────┘
```

La barra de la derecha son **los últimos ciclos**: el histórico es lo que convierte un semáforo
en un argumento de auditoría. Sin él, el panel solo dice "hoy estamos bien", que no prueba nada.

Al hacer clic en un proceso: los tickets que lo respaldan, con fecha y quién lo cerró.

---

## 7. Archivos

Ninguna de las tres apps existentes cambia su lógica.

| Archivo | Qué | Patrón a seguir |
|---|---|---|
| `public/data-cumplimiento.php` | Backend | `public/data-tickets.php` |
| `public/processes.php` | Alta/edición de procesos (admin) | `public/board.php` |
| `public/index.html` | Cubo, render, ruteo, i18n ×5 | ver abajo |
| `config/processes.json` | Config, **arriba del docroot** | `config/board.json` |
| `scratchpad/cmp-demo.js` | Fixture de demo para el README | `scratchpad/tk-demo.js` |

### Puntos de enganche en `index.html`

```
appsList()        ~línea 746   agregar {k:'cumplimiento', t:T('app_cmp')}
showApp(app)      ~línea 1331  agregar la rama
renderTickets()   ~línea 1104  modelo de referencia para renderCumplimiento()
```

### Campos de búsqueda de GLPI (ya mapeados en `data-tickets.php`)

```php
'title' => 1,  'status' => 12,  'date' => 15,  'close' => 16,
'solve' => 17, 'cat' => 7,      'ent' => 80,   'tech' => 5,
```

`cat` (7) es el que usa el modo `match.by = "category"`.

⚠️ **La búsqueda devuelve IDs numéricos para usuarios y grupos** — hay que resolverlos con
`/User` y `/Group`, como ya hace `data-tickets.php` (función `nameMap`).

---

## 8. Alcance acordado para la v1

**Solo el núcleo**: procesos alimentados por tickets de GLPI, **sin ningún conector externo**.

Entrega el grueso del valor, funciona en cualquier instalación, y —lo importante— **define el
modelo de datos y la estructura visual** que después reutiliza el panel de Estado. Los conectores
son incrementales: enriquecen tarjetas que ya existen, sin rediseñar nada.

---

## 9. Lo que falta decidir antes de escribir código

1. **Confirmar el modelo** (estados, criterio de vínculo, pantalla). Conviene cerrarlo antes de
   empezar: queda fijado en **cinco idiomas**, y cambiarlo después es caro.
2. **El nombre en inglés.** `Compliance` funciona directo. Para el futuro panel de Estado,
   *Status* es tibio — evaluar **Health** u **Operations**. Definir el par completo ahora evita
   renombrar traducciones.

---

## 10. Siguiente fase (fuera de la v1)

**Panel de Estado**, quinto cubo, con conectores opcionales activables desde `setup.php`
(Wazuh, Zabbix, PBS, Ansible).

**Dos reglas de diseño acordadas:**

- **Solo entra lo que implica que alguien haga algo.** La prueba para cada tarjeta propuesta es
  *"¿esto significa que alguien tiene que hacer algo?"*. Si no, no va.
  - ❌ "CPU al 62 %" · "12.284 items recolectando"
  - ✅ "PRORH04 sin backup hace 3 días" · "2 agentes sin reportar hace 6 días"
- **El cubo no aparece en el dock si no hay conectores configurados**, para que nadie instale el
  producto y vea un panel vacío.

**El primer conector debería ser Ansible**, y no por capacidad técnica sino por rol: no solo
reporta, **cierra los tickets de Cumplimiento con la evidencia adjunta**. Alimenta los dos
paneles y convierte el cumplimiento manual en automático.

```
Ansible  →  verifica que el backup salió bien
         →  CIERRA el ticket en GLPI, con la evidencia
                        ↓
                  El panel lee GLPI
```

El dashboard nunca se entera de que Ansible existe: una sola fuente de verdad, sin API nueva
que mantener.

---

## 11. Contexto del entorno (para no redescubrirlo)

- **Repo local:** `C:\Users\fabio\Documents\glpi-projects-dashboard`, rama `main`, limpio
- **Repo público:** `github.com/roothard/glpi-admin-dashboard`, MIT
- **Producción:** `apps.roothard.com.ar` · **Pruebas:** `pda.roothard.com.ar`
- **Commits sin trailer de autoría** (regla global del proyecto)
- Las capturas del README usan la demo ficticia **"Acme IT"** — nunca datos reales

### Verificado en esta sesión

- `TicketRecurrent` **sí** se expone por API (1 definido en el GLPI de referencia)
- El vínculo ticket ↔ recurrente **no** se expone (sección 3)
- `itilcategories_id` está disponible tanto en el objeto como en la búsqueda (campo 7)
- El patrón de `board.php` (config propia, lectura logueado / escritura admin) es reutilizable
  tal cual
