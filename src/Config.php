<?php
/**
 * Config — loads settings from a .env file and/or real environment variables.
 * Environment variables win over the .env file (handy for Docker).
 *
 * @license MIT
 */
class Config
{
    /** @return array<string,mixed> normalized config for GlpiClient + Generator */
    public static function load(?string $envFile = null): array
    {
        $env = [];
        if ($envFile && is_file($envFile)) {
            $env = parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [];
        }
        $get = function (string $key, $default = null) use ($env) {
            $v = getenv($key);
            if ($v === false || $v === '') {
                $v = $env[$key] ?? null;
            }
            return ($v === null || $v === '') ? $default : $v;
        };
        $bool = fn($v) => in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
        $list = fn($v) => array_values(array_filter(array_map('trim', explode(',', (string)$v))));

        $url = (string)$get('GLPI_URL', '');
        if ($url === '') {
            throw new RuntimeException('GLPI_URL is required (see .env.example).');
        }

        return [
            // connection
            'url'             => $url,
            'app_token'       => (string)$get('GLPI_APP_TOKEN', ''),
            'user_token'      => (string)$get('GLPI_USER_TOKEN', ''),
            'tokens_in_query' => $bool($get('GLPI_TOKENS_IN_QUERY', 'false')),
            'timeout'         => (int)$get('GLPI_TIMEOUT', 30),
            // active context (optional — for tokens whose default profile lacks rights)
            'profile_id'       => (int)$get('GLPI_PROFILE_ID', 0),
            'entity_id'        => $get('GLPI_ENTITY_ID', ''),
            'entity_recursive' => $bool($get('GLPI_ENTITY_RECURSIVE', 'true')),
            // selection / grouping
            'project_type'      => (string)$get('PROJECT_TYPE', ''),
            'group_by'          => (string)$get('GROUP_BY', 'parent'),
            'include_only_leaf' => $bool($get('INCLUDE_ONLY_LEAF', 'false')),
            'area_strip_prefix' => (string)$get('AREA_STRIP_PREFIX', ''),
            // state name → UI code
            'state_map' => [
                'inprogress' => $list($get('STATE_INPROGRESS', 'proceso,progress,curso,doing')),
                'done'       => $list($get('STATE_DONE', 'cerrado,closed,done,finished,prod')),
                'planned'    => $list($get('STATE_PLANNED', 'espera,nuevo,new,planned,hold,waiting')),
            ],
            // output (defaults above docroot for login mode; data.php reads it)
            'output'   => (string)$get('OUTPUT', __DIR__ . '/../data-cache.json'),
            'timezone' => (string)$get('TIMEZONE', 'UTC'),
        ];
    }
}
