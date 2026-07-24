# Guía de instalación y manual de configuración

Panel de proyectos para GLPI. Se conecta a **cualquier GLPI** por su API y muestra
un tablero con los proyectos, avances, cronograma y base de conocimiento — con
login por usuario de GLPI. Opcionalmente incluye un módulo de **Fichadas GPS**.

---

## 1. Instalación rápida (plug & play)

Solo necesitás **3 datos**. El resto es opcional.

1. Abrí en el navegador: **`https://TU-DOMINIO/setup.php`**
2. Completá el **Paso 1 · Conectar con GLPI**:
   - **URL de GLPI**
   - **App-Token**
   - **Token de usuario**
3. Tocá **«Probar conexión»** → si dice *«✓ Conectado»*, tocá **«Guardar e instalar»**.
4. Listo. Entrás por **`https://TU-DOMINIO/`** y te logueás con tu **cuenta de GLPI**.

> El panel de instalación está en **español, inglés, francés, alemán y portugués**
> (selector arriba a la derecha) y tiene tema claro/oscuro.

Después de instalar, el panel de setup queda **bloqueado**: solo un usuario
**administrador** de GLPI puede volver a abrirlo.

---

## 2. Cómo obtener los datos de GLPI

### 2.1 App-Token (obligatorio)
Identifica a la aplicación ante GLPI.

1. En GLPI, andá a **Configuración → General → pestaña «API»**.
2. Activá **«Habilitar la API REST»** y guardá.
3. En **«Clientes API»**, tocá **«Agregar un cliente API»** (o editá el existente).
4. Guardá y copiá el valor del **«Token de aplicación (app_token)»**.

> Si tu GLPI está detrás de **Cloudflare**, activá la opción *«Enviar tokens por
> query»* en Conexión avanzada (Cloudflare borra el header Authorization).

### 2.2 Token de usuario (obligatorio)
Lo usa la **sincronización automática** (cron) para leer los proyectos. Conviene
un usuario con **permiso de lectura de Proyectos** (ej. un perfil técnico o admin).

1. Iniciá sesión en GLPI con ese usuario.
2. Arriba a la derecha, **tu nombre → Preferencias**.
3. En **«Claves de acceso remoto»**, generá/copiá el **«Token API»**.

> El login del tablero **no** usa este token: cada persona entra con **su propia
> cuenta de GLPI** y solo ve los proyectos que ya puede ver en GLPI.

---

## 3. Manual completo de opciones

Todo lo de abajo es **opcional**: viene con valores por defecto que funcionan.
Está agrupado en secciones plegables dentro de `/setup.php`.

### 3.1 Conexión avanzada
| Opción | Qué hace | Default | Cuándo tocarla |
|---|---|---|---|
| **ID de perfil** | Fuerza un perfil de GLPI al leer (por si el usuario del token tiene varios). | `0` (el de por defecto) | Si el token no ve los proyectos. Ej. Super-Admin suele ser `4`. |
| **Enviar tokens por query** | Manda los tokens como parámetros en vez de headers. | Off | **On si GLPI está detrás de Cloudflare.** |
| **Permitir TLS autofirmado** | No valida el certificado del GLPI. | Off | Solo si tu GLPI usa un certificado autofirmado. |
| **Resolver host → IP** | Fuerza que el dominio de GLPI resuelva a una IP local. | Vacío | Solo si el tablero corre en el **mismo servidor** que GLPI. |

### 3.2 Proyectos
| Opción | Qué hace | Default | Cuándo tocarla |
|---|---|---|---|
| **Tipo de proyecto** | Muestra solo proyectos de ese «Tipo» de GLPI. | Vacío = todos | Si querés filtrar un solo tipo (ej. `RootHard`). |
| **Agrupar zonas por** | Cómo se agrupan las zonas del tablero: proyecto padre / entidad / tipo. | Proyecto padre | Según cómo organices tus proyectos. |
| **Solo con proyecto padre** | Incluye únicamente proyectos que cuelgan de otro. | Off | Si usás proyectos «carpeta» como agrupadores. |
| **Estados → color** | Palabras clave que mapean el **nombre** de tus estados a un color (en proceso / terminado / planificado). | es/en comunes | Si tus estados tienen nombres propios (funciona en cualquier idioma). |
| **Quitar prefijo de zona** | Saca un prefijo del nombre de la zona. | Vacío | Si tus padres se llaman «Portfolio · Infra» y querés mostrar solo «Infra». |

> Los **colores** de las barras salen directamente de los colores que ya tienen tus
> estados en GLPI. El mapeo de arriba es solo para la lógica de «Requieren atención».

### 3.3 Apariencia
| Opción | Qué hace | Default |
|---|---|---|
| **Nombre de la app** | Título en el encabezado y en el login. | «Projects Dashboard» |
| **Subtítulo** | Texto secundario del encabezado. | — |
| **Color principal** | Color de acento del tablero. | Azul |
| **URL del logo** | Reemplaza el ícono por un PNG/SVG por URL. | — |
| **Idioma por defecto** | Idioma inicial (ES/EN/FR/DE/PT). Cada usuario puede cambiarlo. | Español |

### 3.4 Módulo Fichadas GPS (opcional)
Muestra una segunda app con la presencia de técnicos en campo. Lee tickets
**«Visita técnica»** directo de la base de datos de GLPI.

| Opción | Qué hace | Default |
|---|---|---|
| **Activar el módulo** | Muestra u oculta la app de Fichadas. | Off |
| **Etiqueta de la pestaña** | Nombre de la app en el tablero. | «GPS Check-ins» |
| **Link a la web de la app** | Botón hacia el sitio de la app móvil. | — |
| **Host / Nombre / Usuario / Contraseña de la DB** | Conexión (solo-lectura) a la base de GLPI. | — |

> **De dónde salen los datos de la DB:** del archivo `config/config_db.php` de tu
> instalación de GLPI (o preguntale a quien administra el servidor). Ideal usar un
> usuario MySQL **de solo lectura**.

### 3.5 Avanzado
| Opción | Qué hace | Default |
|---|---|---|
| **Zona horaria** | Para el sello de «Actualizado». | `UTC` |

---

## 4. Después de instalar

- **Refresco de datos:** una tarea programada (cron) actualiza el tablero cada 15
  minutos: `*/15 * * * * php /ruta/bin/generate.php`.
- **Reconfigurar:** entrá de nuevo a `/setup.php` estando logueado como
  **administrador** de GLPI.
- **Idioma / tema:** cada usuario los elige desde el tablero (se recuerdan en su
  navegador).

---

## 5. Seguridad

- La configuración y los secretos viven en **`config/settings.json`**, **fuera del
  docroot** y con permisos `600` — nunca se sirve por web.
- El navegador **nunca** recibe los tokens: el App-Token queda en el servidor y las
  personas entran con su propia cuenta de GLPI (cookie de sesión HttpOnly).
- Solo la carpeta **`public/`** se publica; serví **HTTPS**.
- La sincronización es **solo-lectura**; el token de usuario solo necesita permiso
  de lectura de Proyectos.

---

## 6. Requisitos

- PHP 7.4+ con `curl` y `json`.
- Un GLPI 10.x con la **API REST habilitada**.
- Un servidor web (Apache/Nginx) apuntando a la carpeta `public/`.
