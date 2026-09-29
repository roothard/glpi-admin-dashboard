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
require_once __DIR__ . '/GlpiProbe.php';
require_once __DIR__ . '/GlpiClientLegacy.php';
require_once __DIR__ . '/GlpiClientV2.php';

class GlpiClient implements GlpiApi
{
    private GlpiApi $backend;
    private string  $mode;        // modo efectivo tras resolver 'auto'
    private ?array  $detection;   // resultado del probe cuando api_mode = auto

    public function __construct(array $cfg)
    {
        $mode = strtolower((string)($cfg['api_mode'] ?? 'auto'));
        $this->detection = null;
        if ($mode === 'auto') {
            $this->detection = GlpiProbe::detect($cfg);
            $mode = $this->detection['mode'];
        }
        $this->mode    = $mode;
        $this->backend = self::makeBackend($mode, $cfg);
    }

    /** El back-end elegido (útil para diagnóstico/tests). */
    public function backend(): GlpiApi
    {
        return $this->backend;
    }

    /** Modo efectivo en uso: 'legacy' o 'v2' (ya resuelto si venía 'auto'). */
    public function mode(): string
    {
        return $this->mode;
    }

    /** Detalle del sondeo cuando api_mode = auto (null si el modo fue explícito). */
    public function detection(): ?array
    {
        return $this->detection;
    }

    /** Construir el back-end para un modo ya resuelto ('legacy'|'v2'). */
    private static function makeBackend(string $mode, array $cfg): GlpiApi
    {
        return $mode === 'v2' ? new GlpiClientV2($cfg) : new GlpiClientLegacy($cfg);
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
