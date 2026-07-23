<?php
/**
 * GlpiClient — minimal read-only client for the GLPI REST API (apirest.php).
 *
 * Handles session lifecycle and paginated GET/search. Designed to work even
 * when GLPI sits behind Cloudflare, which strips the `Authorization` header:
 * set `tokens_in_query = true` to also pass the user token as a query param.
 *
 * @license MIT
 */

if (!function_exists('array_is_list')) {
    // Polyfill for PHP < 8.0
    function array_is_list(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) === range(0, count($a) - 1);
    }
}

class GlpiClient
{
    private string $base;          // e.g. https://glpi.example.com/apirest.php
    private string $appToken;
    private string $userToken;
    private bool   $tokensInQuery;
    private int    $timeout;
    private int    $profileId;       // 0 = keep token's default profile
    private int    $entityId;        // -1 = keep default entity
    private bool   $entityRecursive;
    private ?string $session = null;

    public function __construct(array $cfg)
    {
        $url = rtrim($cfg['url'] ?? '', '/');
        // Accept both the base host and a full .../apirest.php URL.
        if (!preg_match('#/apirest\.php$#i', $url)) {
            $url .= '/apirest.php';
        }
        $this->base            = $url;
        $this->appToken        = (string)($cfg['app_token'] ?? '');
        $this->userToken       = (string)($cfg['user_token'] ?? '');
        $this->tokensInQuery   = (bool)($cfg['tokens_in_query'] ?? false);
        $this->timeout         = (int)($cfg['timeout'] ?? 30);
        $this->profileId       = (int)($cfg['profile_id'] ?? 0);
        $this->entityId        = array_key_exists('entity_id', $cfg) && $cfg['entity_id'] !== '' ? (int)$cfg['entity_id'] : -1;
        $this->entityRecursive = (bool)($cfg['entity_recursive'] ?? true);
    }

    /**
     * Open a session; returns the session token.
     *
     * Tries the standard `Authorization: user_token …` header first. Many
     * setups (Cloudflare, some Apache/FastCGI configs) strip that header, so
     * on failure we transparently retry with the tokens as query parameters.
     * Set `tokens_in_query = true` to skip straight to the query method.
     */
    public function initSession(): string
    {
        $attempts = $this->tokensInQuery ? ['query'] : ['header', 'query'];
        $last = null;
        foreach ($attempts as $mode) {
            $headers = ['App-Token: ' . $this->appToken];
            $query   = [];
            if ($mode === 'header') {
                $headers[] = 'Authorization: user_token ' . $this->userToken;
            } else { // query
                $query['app_token']  = $this->appToken;
                $query['user_token'] = $this->userToken;
            }
            [$code, $body] = $this->raw('GET', '/initSession', $query, $headers);
            if ($code === 200 && isset($body['session_token'])) {
                $this->session = $body['session_token'];
                $this->applyActiveContext();
                return $this->session;
            }
            $last = "HTTP $code: " . (is_string($body) ? $body : json_encode($body));
        }
        throw new RuntimeException("initSession failed ($last)");
    }

    /** Switch the active profile/entity if configured (some tokens default to a limited profile). */
    private function applyActiveContext(): void
    {
        if ($this->profileId > 0) {
            $this->raw('POST', '/changeActiveProfile', [], $this->authHeaders(),
                ['profiles_id' => $this->profileId]);
        }
        if ($this->entityId >= 0) {
            $this->raw('POST', '/changeActiveEntities', [], $this->authHeaders(),
                ['entities_id' => $this->entityId, 'is_recursive' => $this->entityRecursive]);
        }
    }

    /** Close the session (best effort). */
    public function killSession(): void
    {
        if ($this->session === null) {
            return;
        }
        try { $this->raw('GET', '/killSession', [], $this->authHeaders()); } catch (\Throwable $e) { /* ignore */ }
        $this->session = null;
    }

    /**
     * GET every row of an itemtype, following GLPI's Content-Range pagination.
     * @return array<int,array<string,mixed>>
     */
    public function getAll(string $itemtype, array $params = [], int $page = 200): array
    {
        $out = [];
        $start = 0;
        do {
            $params['range'] = $start . '-' . ($start + $page - 1);
            [$code, $body] = $this->raw('GET', '/' . ltrim($itemtype, '/'), $params, $this->authHeaders());
            if ($code === 401 || $code === 403) {
                throw new RuntimeException("Unauthorized on $itemtype (HTTP $code). Check tokens / rights.");
            }
            if (!is_array($body)) {
                break;
            }
            // GLPI returns a bare list for collections; a single object has string keys.
            $rows = array_is_list($body) ? $body : [$body];
            foreach ($rows as $row) {
                if (is_array($row)) { $out[] = $row; }
            }
            $got = count($rows);
            $start += $got;
            // Stop when the page came back short (last page) or empty.
        } while ($got === $page);
        return $out;
    }

    /**
     * GET the sub-items of a parent (e.g. /Project/12/ProjectTask).
     * Returns [] on 404/empty so callers can treat "no relation" gracefully.
     * @return array<int,array<string,mixed>>
     */
    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array
    {
        $params += ['range' => '0-999'];
        [$code, $body] = $this->raw('GET', "/$itemtype/$id/$subtype", $params, $this->authHeaders());
        if ($code >= 400 || !is_array($body)) {
            return [];
        }
        $rows = array_is_list($body) ? $body : [$body];
        return array_values(array_filter($rows, 'is_array'));
    }

    /** GET a single item by id, or null if missing. */
    public function getItem(string $itemtype, int $id, array $params = []): ?array
    {
        [$code, $body] = $this->raw('GET', "/$itemtype/$id", $params, $this->authHeaders());
        return ($code === 200 && is_array($body)) ? $body : null;
    }

    private function authHeaders(): array
    {
        $h = ['App-Token: ' . $this->appToken];
        if ($this->session !== null) {
            $h[] = 'Session-Token: ' . $this->session;
        }
        return $h;
    }

    /**
     * Perform one HTTP request. Returns [httpCode, decodedBody].
     * @return array{0:int,1:mixed}
     */
    private function raw(string $method, string $path, array $query, array $headers, ?array $body = null): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP transport error for $path: $err");
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($resp, true);
        return [$code, $decoded === null && $resp !== 'null' ? $resp : $decoded];
    }
}
