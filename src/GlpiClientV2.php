<?php
/**
 * GlpiClientV2 — back-end de la API nueva de alto nivel de GLPI 11+ (OAuth2).
 *
 * Esqueleto: implementa el contrato GlpiApi para que la fachada pueda seleccionarlo,
 * pero todavía no habla con el servidor. Se completa en las tareas siguientes del
 * proyecto #46:
 *   - OAuth2 client_credentials: obtención y refresco del access token (#285).
 *   - Mapeo de endpoints/campos legacy → v2 (#286).
 *
 * Guarda la configuración (URL base y credenciales OAuth) para esas etapas. Los
 * secretos (client_id/secret) llegan por Vault/entorno, nunca embebidos.
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
    private ?string $token = null;    // access token OAuth2 (lo completa #285)

    public function __construct(array $cfg)
    {
        $this->base         = rtrim((string)($cfg['url'] ?? ''), '/');
        $this->clientId     = (string)($cfg['oauth_client_id'] ?? '');
        $this->clientSecret = (string)($cfg['oauth_client_secret'] ?? '');
        $this->timeout      = (int)($cfg['timeout'] ?? 30);
        $this->insecure     = (bool)($cfg['insecure'] ?? false);
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
        throw new RuntimeException('GlpiClientV2::getAll aún no implementado (proyecto #46, tarea #286).');
    }

    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array
    {
        throw new RuntimeException('GlpiClientV2::getSubItems aún no implementado (proyecto #46, tarea #286).');
    }

    public function getItem(string $itemtype, int $id, array $params = []): ?array
    {
        throw new RuntimeException('GlpiClientV2::getItem aún no implementado (proyecto #46, tarea #286).');
    }

    public function write(string $itemtype, string $method, array $input = [], ?int $id = null): array
    {
        throw new RuntimeException('GlpiClientV2::write aún no implementado (proyecto #46, tarea #286).');
    }
}
