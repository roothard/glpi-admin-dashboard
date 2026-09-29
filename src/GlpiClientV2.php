<?php
/**
 * GlpiClientV2 — back-end de la API nueva de alto nivel de GLPI 11+ (OAuth2).
 *
 * Estado por tarea del proyecto #46:
 *   - #286 (esta): mapeo de endpoints/campos. Los métodos de lectura ya aplican
 *     GlpiFieldMap para devolver filas en shape legacy; el TRANSPORTE (las
 *     llamadas HTTP autenticadas) está aislado en los seams fetch*(), que se
 *     implementan en #285.
 *   - #285: OAuth2 client_credentials (token + refresco) y el transporte real.
 *
 * Separar transporte de normalización deja el mapeo testeable ya (subclase que
 * sustituye fetch*()), sin necesitar todavía el servidor GLPI 11.
 *
 * Los secretos (client_id/secret) llegan por Vault/entorno, nunca embebidos.
 *
 * @license MIT
 */
class GlpiClientV2 implements GlpiApi
{
    private string  $base;            // URL base de la API v2 (sin /apirest.php)
    private string  $clientId;
    private string  $clientSecret;
    private int     $timeout;
    private bool    $insecure;
    private string  $apiVersion;      // versión HL a pedir (>= 2.3 por los campos usados)
    private ?string $token = null;    // access token OAuth2 (lo completa #285)

    public function __construct(array $cfg)
    {
        $this->base         = rtrim((string)($cfg['url'] ?? ''), '/');
        $this->clientId     = (string)($cfg['oauth_client_id'] ?? '');
        $this->clientSecret = (string)($cfg['oauth_client_secret'] ?? '');
        $this->timeout      = (int)($cfg['timeout'] ?? 30);
        $this->insecure     = (bool)($cfg['insecure'] ?? false);
        $this->apiVersion   = (string)($cfg['api_version'] ?? GlpiFieldMap::MIN_API_VERSION);
    }

    public function initSession(): string
    {
        // #285: intercambiar client_credentials por un access token OAuth2.
        throw new RuntimeException('GlpiClientV2: OAuth2 aún no implementado (proyecto #46, tarea #285).');
    }

    public function useSession(string $token): void
    {
        // La API v2 usa un bearer token; el reuso de un token de usuario se define
        // junto con el flujo de login OAuth (#285).
        $this->token = $token;
    }

    public function killSession(): void
    {
        // Los access tokens OAuth2 expiran solos; normalmente no hay killSession.
        $this->token = null;
    }

    public function getAll(string $itemtype, array $params = [], int $page = 200): array
    {
        $rows = $this->fetchCollection($itemtype, $params, $page);
        return array_map(fn($r) => GlpiFieldMap::toLegacy($itemtype, $r), $rows);
    }

    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array
    {
        // La HL API no expone la mayoría de los pivotes legacy (p. ej.
        // KnowbaseItem_Item por proyecto). Sin endpoint mapeado devolvemos [],
        // igual que el legacy ante una relación inexistente (degradación limpia).
        if (!GlpiFieldMap::hasEndpoint($subtype)) {
            return [];
        }
        $rows = $this->fetchSubItems($itemtype, $id, $subtype, $params);
        return array_map(fn($r) => GlpiFieldMap::toLegacy($subtype, $r), $rows);
    }

    public function getItem(string $itemtype, int $id, array $params = []): ?array
    {
        $row = $this->fetchItem($itemtype, $id, $params);
        return $row === null ? null : GlpiFieldMap::toLegacy($itemtype, $row);
    }

    public function write(string $itemtype, string $method, array $input = [], ?int $id = null): array
    {
        // El mapeo inverso (legacy -> v2) del payload y el transporte PATCH/POST/
        // DELETE se implementan junto con OAuth2 en #285.
        throw new RuntimeException('GlpiClientV2::write aún no implementado (proyecto #46, tarea #285).');
    }

    /** Versión efectiva de la HL API que este cliente pedirá. */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    // ---- transporte (seams; se implementan en #285) ----------------------

    /**
     * Trae la colección cruda (filas del schema v2) de un itemtype. Lo
     * sobreescribe #285 con la llamada OAuth2 real; hoy lanza.
     * @return array<int,array<string,mixed>>
     */
    protected function fetchCollection(string $itemtype, array $params, int $page): array
    {
        throw new RuntimeException('GlpiClientV2: transporte de lectura aún no implementado (proyecto #46, tarea #285).');
    }

    /** Trae un ítem crudo por id, o null. Lo implementa #285. */
    protected function fetchItem(string $itemtype, int $id, array $params): ?array
    {
        throw new RuntimeException('GlpiClientV2: transporte de lectura aún no implementado (proyecto #46, tarea #285).');
    }

    /** Trae sub-ítems crudos. Lo implementa #285. */
    protected function fetchSubItems(string $itemtype, int $id, string $subtype, array $params): array
    {
        throw new RuntimeException('GlpiClientV2: transporte de lectura aún no implementado (proyecto #46, tarea #285).');
    }
}
