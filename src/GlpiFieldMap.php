<?php
/**
 * GlpiFieldMap — mapeo de endpoints y campos entre la API legacy (GLPI 9/10) y
 * la High-Level API (v2, GLPI 11+). Proyecto #46, tarea #286.
 *
 * La app está escrita contra el shape legacy: itemtypes como Project/ProjectTask/
 * ProjectState/… y campos planos (`projectstates_id`, `entities_id`, `answer`, …).
 * La HL API cambia dos cosas:
 *
 *   1. Endpoints: los recursos viven en otras rutas (ver ENDPOINTS). La versión
 *      se negocia por el header GLPI-API-Version (no en la URL). Los campos de
 *      estado/fechas/percent son `x-version-introduced: 2.3`, así que hay que
 *      pedir API >= 2.3 para que aparezcan (ver MIN_API_VERSION).
 *   2. Forma: las claves foráneas legacy (`*_id`) se exponen como objetos
 *      dropdown anidados `{id, name}` (p. ej. `status`, `entity`, `type`, `user`,
 *      `parent`). Y GLPI 11 devuelve el HTML en crudo (sin las entidades que hoy
 *      manda el legacy).
 *
 * Este mapa NO hace HTTP: es la traducción pura. GlpiClientV2 la aplica sobre lo
 * que devuelva el transporte OAuth2 (que se implementa en #285), de modo que el
 * resto de la app siga recibiendo filas en shape legacy sin cambios.
 *
 * Fuente de los nombres: schemas de la HL API en el código de GLPI
 * (src/Glpi/Api/HL/Controller/*). Ver docs/GLPI-API-v2.md.
 *
 * @license MIT
 */
class GlpiFieldMap
{
    /** Versión mínima de la HL API con los campos que la app necesita. */
    public const MIN_API_VERSION = '2.3';

    /**
     * Endpoints v2 por itemtype legacy. Rutas relativas a la base api.php.
     * 'schema' = nombre del schema HL para pedir la versión/campos correctos.
     * El update en v2 es PATCH (no PUT); create POST; delete DELETE.
     */
    public const ENDPOINTS = [
        'Project'        => ['collection' => '/Project',              'item' => '/Project/{id}',              'schema' => 'Project'],
        'ProjectTask'    => ['collection' => '/Project/Task',         'item' => '/Project/Task/{id}',         'schema' => 'ProjectTask'],
        'ProjectState'   => ['collection' => '/Dropdowns/ProjectState','item' => '/Dropdowns/ProjectState/{id}','schema' => 'ProjectState'],
        'ProjectType'    => ['collection' => '/Dropdowns/ProjectType', 'item' => '/Dropdowns/ProjectType/{id}', 'schema' => 'ProjectType'],
        'Entity'         => ['collection' => '/Administration/Entity', 'item' => '/Administration/Entity/{id}', 'schema' => 'Entity'],
        'User'           => ['collection' => '/Administration/User',   'item' => '/Administration/User/{id}',   'schema' => 'User'],
        'KnowbaseItem'   => ['collection' => '/Knowledgebase/Article', 'item' => '/Knowledgebase/Article/{id}', 'schema' => 'KBArticle'],
    ];

    /**
     * Objetos dropdown de v2 y a qué clave foránea legacy aplanan, por itemtype.
     * (El `x-field` del schema HL confirma cada correspondencia.)
     */
    private const DROPDOWNS = [
        'Project' => [
            'status' => 'projectstates_id',
            'parent' => 'projects_id',
            'type'   => 'projecttypes_id',
            'entity' => 'entities_id',
            'user'   => 'users_id',
            'group'  => 'groups_id',
        ],
        'ProjectTask' => [
            'project'     => 'projects_id',
            'parent_task' => 'projecttasks_id',
            'status'      => 'projectstates_id',
            'type'        => 'projecttasktypes_id',
            'entity'      => 'entities_id',
            'user'        => 'users_id',
        ],
    ];

    /** ¿Hay endpoint v2 conocido para este itemtype? */
    public static function hasEndpoint(string $itemtype): bool
    {
        return isset(self::ENDPOINTS[$itemtype]);
    }

    /** Ruta de colección v2 para un itemtype legacy (o null si no mapeado). */
    public static function collectionPath(string $itemtype): ?string
    {
        return self::ENDPOINTS[$itemtype]['collection'] ?? null;
    }

    /** Ruta de ítem v2 (con {id} reemplazado) o null si no mapeado. */
    public static function itemPath(string $itemtype, int $id): ?string
    {
        $p = self::ENDPOINTS[$itemtype]['item'] ?? null;
        return $p === null ? null : str_replace('{id}', (string)$id, $p);
    }

    /**
     * Convierte una fila v2 al shape legacy que consume la app.
     * Idempotente: tolera filas ya planas (si no viene el dropdown, respeta el
     * `*_id` existente), para no romper si una versión devuelve el campo plano.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toLegacy(string $itemtype, array $row): array
    {
        // 1) Aplanar los dropdowns a sus claves foráneas legacy.
        foreach (self::DROPDOWNS[$itemtype] ?? [] as $prop => $fk) {
            if (array_key_exists($prop, $row)) {
                $row[$fk] = is_array($row[$prop]) ? (int)($row[$prop]['id'] ?? 0) : (int)$row[$prop];
                // Guardar el nombre embebido por si el consumidor lo aprovecha.
                if (is_array($row[$prop]) && isset($row[$prop]['name']) && !isset($row[$fk . '_name'])) {
                    $row[$fk . '_name'] = $row[$prop]['name'];
                }
            } elseif (!array_key_exists($fk, $row)) {
                $row[$fk] = 0;
            }
        }

        // 2) Ajustes por itemtype.
        switch ($itemtype) {
            case 'Project':
            case 'ProjectTask':
                // Booleanos v2 -> 0/1 legacy (la app usa !empty, pero normalizamos).
                foreach (['is_deleted', 'is_template', 'is_milestone', 'is_recursive'] as $b) {
                    if (array_key_exists($b, $row)) { $row[$b] = !empty($row[$b]) ? 1 : 0; }
                }
                break;

            case 'User':
                // La app arma "firstname realname" y cae a `name` (login). En v2 el
                // login puede venir como `username`.
                if (!isset($row['name']) && isset($row['username'])) { $row['name'] = $row['username']; }
                break;

            case 'KnowbaseItem':
                // El schema HL expone el cuerpo como propiedad con x-field 'answer';
                // suele llamarse `content`. La app lee `answer`.
                if (!isset($row['answer'])) {
                    $row['answer'] = $row['content'] ?? '';
                }
                break;

            case 'ProjectState':
            case 'ProjectType':
            case 'Entity':
                // id/name/(color|completename) ya vienen planos en el schema v2.
                break;
        }

        return $row;
    }
}
