<?php
/**
 * Settings — single source of truth for ALL configuration.
 *
 * Stored as JSON in `config/settings.json` (kept ABOVE the web docroot).
 * Real environment variables override file values (handy for Docker). Nothing
 * in the codebase is hardcoded: connection, project selection, branding and
 * modules all come from here and are edited through the setup panel.
 *
 * Secrets (tokens, DB password) live only in this server-side file and are
 * NEVER included in the public config sent to the browser.
 *
 * @license MIT
 */
class Settings
{
    /** Default location of the config file (above docroot). */
    public static function path(): string
    {
        return getenv('DASHBOARD_CONFIG') ?: (__DIR__ . '/../config/settings.json');
    }

    /**
     * Paletas de color pre-establecidas que el panel general ofrece.
     * Cada una define el accent para claro y oscuro. La UI muestra estas 3;
     * 'custom' deja elegir un color libre. Agregar una paleta = una entrada acá.
     */
    public static function palettes(): array
    {
        return [
            'roothard'  => ['name' => 'Azul RootHard', 'accent' => '#405cde', 'accent_dark' => '#6a83f2'],
            'esmeralda' => ['name' => 'Esmeralda',     'accent' => '#0d9488', 'accent_dark' => '#2dd4bf'],
            'violeta'   => ['name' => 'Violeta',        'accent' => '#6d28d9', 'accent_dark' => '#a78bfa'],
        ];
    }

    /**
     * Registro declarativo de SECCIONES de configuración. Es el backbone modular:
     * cada app del tablero aporta su propia sección de config acá, en vez de
     * hardcodear todo en un único formulario. 'core' = ajustes del tablero;
     * 'app' = una app concreta (se puede prender/apagar). El orden es el de la UI.
     *
     * Para sumar la config de una app nueva: agregá una entrada 'app' con su id,
     * su ícono y su clave i18n; el panel la lista y renderiza sus campos.
     */
    public static function sections(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::load();
        return [
            ['id' => 'glpi',     'kind' => 'core', 'icon' => '🔌', 'i18n' => 's1',  'required' => true],
            ['id' => 'general',  'kind' => 'core', 'icon' => '🎨', 'i18n' => 's3',  'required' => false],
            ['id' => 'projects', 'kind' => 'app',  'icon' => '📁', 'i18n' => 's2',  'required' => false, 'enabled' => true],
            ['id' => 'crm',      'kind' => 'app',  'icon' => '💼', 'i18n' => 'scrm', 'required' => false, 'enabled' => (bool)($cfg['crm']['enabled'] ?? false)],
            ['id' => 'gps',      'kind' => 'app',  'icon' => '📍', 'i18n' => 's4',  'required' => false, 'enabled' => (bool)($cfg['modules']['gps']['enabled'] ?? false)],
            ['id' => 'contact',  'kind' => 'app',  'icon' => '💬', 'i18n' => 's5c', 'required' => false, 'enabled' => (bool)($cfg['contact']['enabled'] ?? true)],
            ['id' => 'advanced', 'kind' => 'core', 'icon' => '⚙️', 'i18n' => 'adv2', 'required' => false],
        ];
    }

    /** Accent efectivo: color de la paleta elegida, o el 'accent' libre si 'custom'. */
    public static function accentOf(array $cfg, bool $dark = false): string
    {
        $b = $cfg['branding'] ?? [];
        $pal = $b['palette'] ?? 'roothard';
        $palettes = self::palettes();
        if ($pal !== 'custom' && isset($palettes[$pal])) {
            return $dark ? $palettes[$pal]['accent_dark'] : $palettes[$pal]['accent'];
        }
        return preg_match('/^#[0-9a-fA-F]{6}$/', $b['accent'] ?? '') ? $b['accent'] : '#405cde';
    }

    /** Full default schema — also documents every configurable key. */
    public static function defaults(): array
    {
        return [
            'glpi' => [
                'url' => '', 'app_token' => '', 'user_token' => '',
                'tokens_in_query' => false, 'profile_id' => 0,
                'insecure' => false, 'resolve_host' => '', 'resolve_ip' => '',
            ],
            'projects' => [
                'project_type' => '', 'group_by' => 'parent',
                'include_untyped' => true,   // with a type filter set, still show untyped projects (flagged)
                'include_only_leaf' => false, 'area_strip_prefix' => '',
                'state_inprogress' => ['proceso', 'progress', 'curso', 'doing', 'en cours'],
                'state_done'       => ['cerrado', 'closed', 'done', 'finished', 'terminé'],
                'state_planned'    => ['espera', 'nuevo', 'new', 'planned', 'hold', 'waiting', 'à faire'],
            ],
            'branding' => [
                'app_name' => 'Projects Dashboard',
                'subtitle' => 'Projects Center',
                'accent'   => '#405cde',
                'logo_url' => '',
                'default_lang' => 'es',
                // Paleta pre-establecida (clave de self::palettes()) o 'custom'
                // para usar el color 'accent' de arriba.
                'palette'  => 'roothard',
                // Marca PROPIA de la pantalla de login (si algo queda vacío,
                // cae al valor de la app: login_name→app_name, etc.).
                'login_name'     => '',
                'login_subtitle' => '',
                'login_logo_url' => '',
            ],
            'modules' => [
                'gps' => [
                    'enabled' => false,
                    'label'   => 'GPS Check-ins',
                    'app_url' => '',
                    'db' => ['host' => '', 'name' => '', 'user' => '', 'pass' => ''],
                ],
            ],
            'contact' => [
                'enabled'      => true,
                'google_chat'  => true,
                'msg_default'  => 'Hola {nombre}, te contacto desde el panel.',
                'msg_reminder' => 'Hola {nombre}, ¿podés registrar tu visita de hoy?',
            ],
            'crm' => [
                'enabled' => true,   // muestra el cubo CRM a admin/supervisor
                // Entidades "empresa/padre" que administran clientes (sus hijas = clientes).
                // Vacío = autodescubre: cualquier entidad que el usuario ve y que tiene hijas.
                'parents' => [],
                'label'   => 'CRM',
            ],
            'security' => [
                'require_2fa'    => false,  // when true, the UI forces enrollment before showing data
                'throttle_ip_max'   => 10, 'throttle_ip_window'   => 900,
                'throttle_user_max' => 5,  'throttle_user_window' => 900,
                'wazuh_syslog'   => true,   // emit each auth event to syslog for the Wazuh agent
                'email_otp'      => true,   // allow email OTP as a backup second factor
                'otp_ttl'        => 300,
                'mail_from'      => '',     // From: for the OTP email (blank → PHP default)
            ],
            'output'   => '',   // defaults to ../data-cache.json
            'timezone' => 'UTC',
        ];
    }

    /** Deep-merge helper (values from $b win). */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = (is_array($v) && isset($a[$k]) && is_array($a[$k])) ? self::merge($a[$k], $v) : $v;
        }
        return $a;
    }

    /** Load settings = defaults ⊕ file ⊕ environment overrides. */
    public static function load(?string $path = null): array
    {
        $path = $path ?: self::path();
        $file = is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
        $cfg = self::merge(self::defaults(), $file);

        // Environment overrides (Docker-friendly). Only the common ones.
        $env = fn($k, $d) => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;
        $bool = fn($v) => is_bool($v) ? $v : in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
        $cfg['glpi']['url']       = $env('GLPI_URL', $cfg['glpi']['url']);
        $cfg['glpi']['app_token'] = $env('GLPI_APP_TOKEN', $cfg['glpi']['app_token']);
        $cfg['glpi']['user_token'] = $env('GLPI_USER_TOKEN', $cfg['glpi']['user_token']);
        $cfg['projects']['project_type'] = $env('PROJECT_TYPE', $cfg['projects']['project_type']);
        if (getenv('GLPI_TOKENS_IN_QUERY') !== false) { $cfg['glpi']['tokens_in_query'] = $bool(getenv('GLPI_TOKENS_IN_QUERY')); }
        foreach (['DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass'] as $e => $k) {
            $cfg['modules']['gps']['db'][$k] = $env($e, $cfg['modules']['gps']['db'][$k]);
        }
        return $cfg;
    }

    /** Persist settings JSON (creates the config dir, chmod 600). */
    public static function save(array $cfg, ?string $path = null): bool
    {
        $path = $path ?: self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        $ok = file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
        return $ok !== false;
    }

    /** True once the minimum required config (GLPI url + app token) is present. */
    public static function isConfigured(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::load();
        return $cfg['glpi']['url'] !== '' && $cfg['glpi']['app_token'] !== '';
    }

    /** Flatten to the shape GlpiClient + DashboardGenerator expect. */
    public static function flat(?array $cfg = null): array
    {
        $c = $cfg ?? self::load();
        $g = $c['glpi']; $p = $c['projects'];
        return [
            'url' => $g['url'], 'app_token' => $g['app_token'], 'user_token' => $g['user_token'],
            'tokens_in_query' => (bool)$g['tokens_in_query'], 'profile_id' => (int)$g['profile_id'],
            'insecure' => (bool)$g['insecure'], 'timeout' => 30,
            'project_type' => $p['project_type'], 'group_by' => $p['group_by'],
            'include_untyped' => (bool)($p['include_untyped'] ?? true),
            'include_only_leaf' => (bool)$p['include_only_leaf'], 'area_strip_prefix' => $p['area_strip_prefix'],
            'state_map' => [
                'inprogress' => $p['state_inprogress'], 'done' => $p['state_done'], 'planned' => $p['state_planned'],
            ],
            'output'   => $c['output'] !== '' ? $c['output'] : (__DIR__ . '/../data-cache.json'),
            'timezone' => $c['timezone'],
        ];
    }

    /** The NON-SECRET subset safe to expose to the browser. */
    public static function publicConfig(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::load();
        $b = $cfg['branding'];
        // La marca del login cae a la de la app cuando está vacía.
        $b['login_name']     = ($b['login_name'] ?? '')     !== '' ? $b['login_name']     : ($b['app_name'] ?? '');
        $b['login_subtitle'] = ($b['login_subtitle'] ?? '') !== '' ? $b['login_subtitle'] : ($b['subtitle'] ?? '');
        $b['login_logo_url'] = ($b['login_logo_url'] ?? '') !== '' ? $b['login_logo_url'] : ($b['logo_url'] ?? '');
        // Accent efectivo según la paleta elegida (el front lo aplica directo).
        $b['accent']      = self::accentOf($cfg, false);
        $b['accent_dark'] = self::accentOf($cfg, true);
        return [
            'branding' => $b,
            'palettes' => self::palettes(),   // para pintar los swatches del panel
            'modules'  => [
                'gps' => [
                    'enabled' => (bool)$cfg['modules']['gps']['enabled'],
                    'label'   => $cfg['modules']['gps']['label'],
                    'app_url' => $cfg['modules']['gps']['app_url'],
                ],
            ],
            'contact'  => $cfg['contact'] ?? [],
            'crm'      => [
                'enabled' => (bool)($cfg['crm']['enabled'] ?? false),
                'label'   => $cfg['crm']['label'] ?? 'CRM',
            ],
            'configured' => self::isConfigured($cfg),
        ];
    }
}
