<?php
/**
 * GlpiClientV2 — back-end de la API nueva de alto nivel de GLPI 11+ (OAuth2).
 *
 * Transporte OAuth2 (tarea #285, alcance servicio):
 *   - initSession(): grant `password` (usuario/clave de servicio) contra
 *     {host}/api.php/token → access_token (Bearer) + refresh_token. El grant
 *     client_credentials NO sirve (el Router de GLPI necesita un usuario:
 *     user_id nulo → 401), verificado en código y en vivo. Ver docs/GLPI-API-v2.md.
 *   - fetch*(): HTTP real contra {host}/api.php/v2/… con Authorization: Bearer y
 *     GLPI-API-Version, paginando con ?start=&limit= (Content-Range da el total).
 *   - El resultado se normaliza con GlpiFieldMap (tarea #286) → shape legacy.
 *
 * ProjectState no tiene endpoint en v2 (no es dropdown de la HL API): se
 * sintetiza el catálogo desde los `status` embebidos en Project/ProjectTask
 * (nombre real; el color no existe en v2 → cae a un gris neutro).
 *
 * Los secretos (client_id/secret, usuario/clave de servicio) llegan por
 * Vault/entorno, nunca embebidos.
 *
 * @license MIT
 */
class GlpiClientV2 implements GlpiApi
{
    private string  $apiBase;         // {host}/api.php
    private string  $clientId;
    private string  $clientSecret;
    private string  $username;        // usuario de servicio (grant password)
    private string  $password;
    private string  $scope;
    private int     $timeout;
    private bool    $insecure;
    private array   $resolve;         // [host, ip] opcional
    private string  $apiVersion;      // se pide por header GLPI-API-Version (>= 2.3)

    private ?string $token = null;    // access token OAuth2
    private int     $tokenExpires = 0;// epoch; 0 = token externo (no auto-refresca)
    private ?string $refresh = null;
    /** @var array<string,array> caché por build de filas crudas v2 por itemtype */
    private array   $rawCache = [];

    private const NEUTRAL_COLOR = '#888';

    public function __construct(array $cfg)
    {
        $this->apiBase      = self::apiRoot((string)($cfg['url'] ?? ''));
        $this->clientId     = (string)($cfg['oauth_client_id'] ?? '');
        $this->clientSecret = (string)($cfg['oauth_client_secret'] ?? '');
        $this->username     = (string)($cfg['oauth_username'] ?? '');
        $this->password     = (string)($cfg['oauth_password'] ?? '');
        $this->scope        = (string)($cfg['oauth_scope'] ?? 'api');
        $this->timeout      = (int)($cfg['timeout'] ?? 30);
        $this->insecure     = (bool)($cfg['insecure'] ?? false);
        $ver = (string)($cfg['api_version'] ?? '');
        $this->apiVersion   = $ver !== '' ? $ver : GlpiFieldMap::MIN_API_VERSION;
        $this->resolve      = (!empty($cfg['resolve_host']) && !empty($cfg['resolve_ip']))
            ? [$cfg['resolve_host'], $cfg['resolve_ip']] : [];
    }

    /** Normaliza cualquier forma de URL a {host}/api.php. */
    private static function apiRoot(string $url): string
    {
        $u = rtrim(trim($url), '/');
        $u = preg_replace('#/apirest\.php$#i', '', $u);
        $u = preg_replace('#/api\.php(/v\d+)?$#i', '', $u);
        return rtrim($u, '/') . '/api.php';
    }

    // ---- sesión / token ---------------------------------------------------

    /** Abre sesión de servicio (grant password) y devuelve el access token. */
    public function initSession(): string
    {
        if ($this->username === '') {
            throw new RuntimeException('GlpiClientV2: falta usuario de servicio (oauth_username) para el grant password.');
        }
        return $this->requestToken([
            'grant_type' => 'password',
            'username'   => $this->username,
            'password'   => $this->password,
        ]);
    }

    /** Reutiliza un access token ya obtenido (no se auto-refresca). */
    public function useSession(string $token): void
    {
        $this->token = $token;
        $this->tokenExpires = 0;
        $this->refresh = null;
    }

    public function killSession(): void
    {
        $this->token = null;
        $this->tokenExpires = 0;
        $this->refresh = null;
    }

    /** Pide un token al endpoint OAuth2 y lo guarda. */
    private function requestToken(array $grant): string
    {
        $body = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scope,
        ] + $grant;
        [$code, $resp] = $this->httpForm($this->apiBase . '/token', $body);
        if ($code !== 200 || !is_array($resp) || empty($resp['access_token'])) {
            $err = is_array($resp) ? ($resp['error_description'] ?? $resp['error'] ?? json_encode($resp)) : (string)$resp;
            throw new RuntimeException("GlpiClientV2: fallo al obtener token OAuth2 (HTTP $code): $err");
        }
        $this->token = (string)$resp['access_token'];
        $this->tokenExpires = time() + max(30, (int)($resp['expires_in'] ?? 3600)) - 30;
        $this->refresh = isset($resp['refresh_token']) ? (string)$resp['refresh_token'] : null;
        return $this->token;
    }

    /** Garantiza un token válido: refresca o re-autentica si venció. */
    private function ensureToken(): void
    {
        if ($this->token === null) {
            $this->initSession();
            return;
        }
        if ($this->tokenExpires > 0 && time() >= $this->tokenExpires) {
            if ($this->refresh !== null) {
                try {
                    $this->requestToken(['grant_type' => 'refresh_token', 'refresh_token' => $this->refresh]);
                    return;
                } catch (\Throwable $e) { /* cae a re-login */ }
            }
            $this->initSession();
        }
    }

    // ---- lectura (normaliza con GlpiFieldMap) -----------------------------

    public function getAll(string $itemtype, array $params = [], int $page = 200): array
    {
        $rows = $this->fetchCollection($itemtype, $params, $page);
        return array_map(fn($r) => GlpiFieldMap::toLegacy($itemtype, $r), $rows);
    }

    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array
    {
        // La HL API embebe las relaciones en el ítem (p. ej. Project.tasks); no hay
        // pivotes legacy sueltos. Sin endpoint mapeado devolvemos [] como el legacy.
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

    // ---- escritura --------------------------------------------------------

    public function write(string $itemtype, string $method, array $input = [], ?int $id = null): array
    {
        $method = strtoupper($method);
        $this->ensureToken();
        if ($method === 'POST') {
            $path = GlpiFieldMap::collectionPath($itemtype);
            if ($path === null) { throw new RuntimeException("GlpiClientV2: itemtype no mapeado a v2: $itemtype"); }
            return $this->httpJson('POST', $this->v2($path), GlpiFieldMap::toV2Input($itemtype, $input));
        }
        if ($id === null) { throw new RuntimeException("GlpiClientV2::write $method requiere id."); }
        $path = GlpiFieldMap::itemPath($itemtype, $id);
        if ($path === null) { throw new RuntimeException("GlpiClientV2: itemtype no mapeado a v2: $itemtype"); }
        if ($method === 'PUT' || $method === 'PATCH') {
            return $this->httpJson('PATCH', $this->v2($path), GlpiFieldMap::toV2Input($itemtype, $input));
        }
        if ($method === 'DELETE') {
            return $this->httpJson('DELETE', $this->v2($path) . '?force=true', null);
        }
        throw new RuntimeException("GlpiClientV2::write método no soportado: $method");
    }

    /** Versión efectiva de la HL API que se pide. */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    // ---- transporte -------------------------------------------------------

    /**
     * Colección normalizada-a-cruda (filas del schema v2). ProjectState se
     * sintetiza porque no tiene endpoint en v2.
     * @return array<int,array<string,mixed>>
     */
    protected function fetchCollection(string $itemtype, array $params, int $page): array
    {
        if ($itemtype === 'ProjectState') {
            return $this->synthProjectStates();
        }
        return $this->rawCollection($itemtype, $page);
    }

    /** Ítem crudo por id, o null si no existe. */
    protected function fetchItem(string $itemtype, int $id, array $params): ?array
    {
        $path = GlpiFieldMap::itemPath($itemtype, $id);
        if ($path === null) { return null; }
        $this->ensureToken();
        [$code, $body] = $this->httpJson('GET', $this->v2($path), null);
        return ($code === 200 && is_array($body)) ? $body : null;
    }

    /** Sub-ítems: la HL API los embebe, no se piden sueltos en alcance servicio. */
    protected function fetchSubItems(string $itemtype, int $id, string $subtype, array $params): array
    {
        return [];
    }

    /** Trae TODAS las filas crudas v2 de un itemtype con endpoint, paginando. Cachea por build. */
    private function rawCollection(string $itemtype, int $page = 200): array
    {
        if (array_key_exists($itemtype, $this->rawCache)) {
            return $this->rawCache[$itemtype];
        }
        $route = GlpiFieldMap::collectionPath($itemtype);
        if ($route === null) {
            throw new RuntimeException("GlpiClientV2: itemtype no mapeado a v2: $itemtype");
        }
        $this->ensureToken();
        $limit = max(1, $page);
        $out = [];
        $start = 0;
        do {
            [$code, $body, $total] = $this->httpGet($route, ['start' => $start, 'limit' => $limit]);
            if ($code === 401 || $code === 403) {
                throw new RuntimeException("GlpiClientV2: no autorizado en $itemtype (HTTP $code).");
            }
            if ($code >= 400 || !is_array($body)) {
                break;
            }
            $rows = array_is_list($body) ? $body : [$body];
            foreach ($rows as $r) { if (is_array($r)) { $out[] = $r; } }
            $got = count($rows);
            $start += $got;
        } while ($got > 0 && ($total < 0 || $start < $total));
        $this->rawCache[$itemtype] = $out;
        return $out;
    }

    /**
     * Catálogo sintético de ProjectState: estados distintos vistos en los
     * `status` embebidos de Project y ProjectTask. Nombre real; color neutro
     * (la HL API no expone el color del estado).
     * @return array<int,array<string,mixed>>
     */
    private function synthProjectStates(): array
    {
        $seen = [];
        foreach (['Project', 'ProjectTask'] as $it) {
            foreach ($this->rawCollection($it) as $r) {
                $st = $r['status'] ?? null;
                if (is_array($st) && isset($st['id'])) {
                    $seen[(int)$st['id']] = (string)($st['name'] ?? '');
                }
                // Los ProjectTask embebidos dentro de Project.tasks también aportan.
                foreach ($r['tasks'] ?? [] as $t) {
                    $ts = is_array($t) ? ($t['status'] ?? null) : null;
                    if (is_array($ts) && isset($ts['id'])) { $seen[(int)$ts['id']] = (string)($ts['name'] ?? ''); }
                }
            }
        }
        $out = [];
        foreach ($seen as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name, 'color' => self::NEUTRAL_COLOR];
        }
        return $out;
    }

    // ---- helpers HTTP -----------------------------------------------------

    /** URL absoluta de un path v2. */
    private function v2(string $path): string
    {
        return $this->apiBase . '/v2/' . ltrim($path, '/');
    }

    /** Cabeceras de autenticación/versión para la API v2. */
    private function apiHeaders(): array
    {
        return [
            'Authorization: Bearer ' . (string)$this->token,
            'GLPI-API-Version: ' . $this->apiVersion,
            'Accept: application/json',
        ];
    }

    /**
     * GET de colección: devuelve [httpCode, filasDecodificadas, total].
     * total sale del header Content-Range (…/N); -1 si no está.
     * @return array{0:int,1:mixed,2:int}
     */
    private function httpGet(string $route, array $query): array
    {
        $url = $this->v2($route);
        if ($query) { $url .= '?' . http_build_query($query); }
        [$code, $body, $headers] = $this->raw('GET', $url, $this->apiHeaders(), null, true);
        $total = -1;
        if (preg_match('#content-range:\s*[^/]+/(\d+)#i', $headers, $m)) { $total = (int)$m[1]; }
        return [$code, $body, $total];
    }

    /**
     * Request JSON (POST/PATCH/DELETE/GET item). Devuelve [httpCode, cuerpo].
     * @return array{0:int,1:mixed}
     */
    private function httpJson(string $method, string $url, ?array $body): array
    {
        $headers = $this->apiHeaders();
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body);
        }
        [$code, $decoded] = $this->raw($method, $url, $headers, $payload, false);
        return [$code, $decoded];
    }

    /**
     * POST form-urlencoded (endpoint del token). Devuelve [httpCode, cuerpo].
     * @return array{0:int,1:mixed}
     */
    private function httpForm(string $url, array $fields): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        [$code, $decoded] = $this->raw('POST', $url, $headers, http_build_query($fields), false);
        return [$code, $decoded];
    }

    /**
     * Una petición HTTP. Con $wantHeaders devuelve [code, body, headers].
     * Protegido para poder sustituirlo en tests (mock del transporte).
     * @return array{0:int,1:mixed}|array{0:int,1:mixed,2:string}
     */
    protected function raw(string $method, string $url, array $headers, ?string $payload, bool $wantHeaders)
    {
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => $wantHeaders,
        ];
        if ($this->insecure) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($this->resolve) {
            $port = stripos($url, 'https://') === 0 ? 443 : 80;
            $opts[CURLOPT_RESOLVE] = [$this->resolve[0] . ':' . $port . ':' . $this->resolve[1]];
        }
        if ($payload !== null) { $opts[CURLOPT_POSTFIELDS] = $payload; }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("GlpiClientV2: error de transporte en $url: $err");
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = $wantHeaders ? (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
        curl_close($ch);
        $headers = $wantHeaders ? substr($resp, 0, $hsz) : '';
        $rawBody = $wantHeaders ? substr($resp, $hsz) : $resp;
        $decoded = json_decode((string)$rawBody, true);
        $decoded = ($decoded === null && $rawBody !== 'null') ? $rawBody : $decoded;
        return $wantHeaders ? [$code, $decoded, $headers] : [$code, $decoded];
    }
}
