<?php
/**
 * GlpiClient — fachada version-agnostic de acceso a GLPI.
 *
 * No habla HTTP directamente: elige un back-end que implementa GlpiApi y le
 * delega todo. Así los consumidores (DashboardGenerator, generate.php, data.php,
 * setup.php) siguen haciendo `new GlpiClient($cfg)` sin enterarse de si detrás
 * hay API legacy (apirest.php, GLPI 9/10) o API v2 (OAuth2, GLPI 11+).
 *
 * El back-end se elige por `api_mode` en la config:
 *   - 'legacy' → GlpiClientLegacy.
 *   - 'v2'     → GlpiClientV2.
 *   - 'auto'   → autodetección (proyecto #46, tarea #284); por ahora cae a legacy.
 *
 * @license MIT
 */
require_once __DIR__ . '/GlpiApi.php';
require_once __DIR__ . '/GlpiClientLegacy.php';
require_once __DIR__ . '/GlpiClientV2.php';

class GlpiClient implements GlpiApi
{
    private GlpiApi $backend;

    public function __construct(array $cfg)
    {
        $this->backend = self::makeBackend($cfg);
    }

    /** El back-end elegido (útil para diagnóstico/tests). */
    public function backend(): GlpiApi
    {
        return $this->backend;
    }

    /** Elegir el back-end según api_mode. */
    private static function makeBackend(array $cfg): GlpiApi
    {
        $mode = strtolower((string)($cfg['api_mode'] ?? 'auto'));
        switch ($mode) {
            case 'v2':
                return new GlpiClientV2($cfg);
            case 'legacy':
                return new GlpiClientLegacy($cfg);
            case 'auto':
            default:
                // #284: probar capacidades del GLPI destino para decidir. Hasta
                // entonces, el comportamiento estable es la API legacy.
                return new GlpiClientLegacy($cfg);
        }
    }

    // ---- delegación al back-end -----------------------------------------

    public function initSession(): string
    {
        return $this->backend->initSession();
    }

    public function useSession(string $token): void
    {
        $this->backend->useSession($token);
    }

    public function killSession(): void
    {
        $this->backend->killSession();
    }

    public function getAll(string $itemtype, array $params = [], int $page = 200): array
    {
        return $this->backend->getAll($itemtype, $params, $page);
    }

    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array
    {
        return $this->backend->getSubItems($itemtype, $id, $subtype, $params);
    }

    public function getItem(string $itemtype, int $id, array $params = []): ?array
    {
        return $this->backend->getItem($itemtype, $id, $params);
    }

    public function write(string $itemtype, string $method, array $input = [], ?int $id = null): array
    {
        return $this->backend->write($itemtype, $method, $input, $id);
    }
}
