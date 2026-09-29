<?php
/**
 * GlpiProbe — auto-detección de versión/API del GLPI destino (proyecto #46).
 *
 * Decide qué back-end usar cuando api_mode = 'auto', sondeando el servidor sin
 * autenticar y clasificando por señales estables (no por texto localizado):
 *
 *   - LEGACY (apirest.php, GLPI 9/10): un GET a /apirest.php/initSession sin
 *     tokens. Si la API está habilitada, GLPI responde con un código de error
 *     ESPECÍFICO ("ERROR_APP_TOKEN...", "ERROR_LOGIN_PARAMETERS_MISSING", ...)
 *     o 200. Si está desactivada devuelve ["ERROR","..."] genérico, y en GLPI 11
 *     apirest.php suele dar 404. => primer elemento "ERROR_*" o HTTP 200 = viva.
 *
 *   - V2 (High-Level API, GLPI 11+): un GET a /api.php/ (o /front/api.php/). La
 *     HL API negocia versión con el header GLPI-API-Version; su sola presencia
 *     en la respuesta (aunque sea 401/403 por falta de OAuth) delata la API nueva.
 *
 * Decisión: legacy-viva -> legacy; si no, v2-presente -> v2; si no hay señal
 * clara (o falla la red) -> **fallback seguro a legacy**, para no romper nunca
 * una instalación legacy que anda por un probe con problemas.
 *
 * El resultado se memoiza por proceso y, opcionalmente, en un archivo con TTL,
 * para que 'auto' no sondee en cada request del modelo por-sesión.
 *
 * @license MIT
 */
class GlpiProbe
{
    /** @var array<string,array> memo por proceso, clave = URL base normalizada */
    private static array $memo = [];

    /**
     * Detecta el modo de API. Devuelve:
     *   ['mode'=>'legacy'|'v2', 'api_version'=>?string, 'reason'=>string, 'cached'=>bool]
     */
    public static function detect(array $cfg): array
    {
        $base = self::hostBase($cfg['url'] ?? '');
        if ($base === '') {
            return ['mode' => 'legacy', 'api_version' => null, 'reason' => 'sin URL: fallback legacy', 'cached' => false];
        }
        if (isset(self::$memo[$base])) {
            return ['cached' => true] + self::$memo[$base];
        }

        $ttl = (int)($cfg['probe_cache_ttl'] ?? 3600);
        if (empty($cfg['probe_no_cache']) && $ttl > 0) {
            $hit = self::cacheGet($cfg, $base, $ttl);
            if ($hit !== null) {
                self::$memo[$base] = $hit;
                return ['cached' => true] + $hit;
            }
        }

        $res = self::runProbe($cfg, $base);
        self::$memo[$base] = $res;
        if (empty($cfg['probe_no_cache']) && $ttl > 0) {
            self::cachePut($cfg, $base, $res);
        }
        return ['cached' => false] + $res;
    }

    /** Fuerza un sondeo fresco (sin cache), útil para el test de conexión del setup. */
    public static function detectFresh(array $cfg): array
    {
        return self::detect(['probe_no_cache' => true] + $cfg);
    }

    // ---- sondeo -----------------------------------------------------------

    private static function runProbe(array $cfg, string $base): array
    {
        // 1) ¿Legacy viva?
        [$lCode, $lBody] = self::http('GET', $base . '/apirest.php/initSession', $cfg);
        if (self::legacyAlive($lCode, $lBody)) {
            return ['mode' => 'legacy', 'api_version' => null,
                    'reason' => "apirest.php responde (HTTP $lCode): API legacy habilitada"];
        }

        // 2) ¿HL API (v2) presente? Probar candidatos de path.
        foreach (['/api.php/', '/front/api.php/'] as $path) {
            [$vCode, , $vHeaders] = self::http('GET', $base . $path, $cfg, true);
            $ver = self::headerValue($vHeaders, 'glpi-api-version');
            if ($ver !== null) {
                return ['mode' => 'v2', 'api_version' => $ver,
                        'reason' => "HL API detectada en $path (GLPI-API-Version: $ver, HTTP $vCode)"];
            }
        }

        // 3) Sin señal clara: fallback seguro a legacy.
        return ['mode' => 'legacy', 'api_version' => null,
                'reason' => "sin señal concluyente (legacy HTTP $lCode, sin HL API): fallback legacy"];
    }

    /**
     * Legacy viva = HTTP 200, o cuerpo GLPI cuyo primer elemento es un código
     * ERROR_* específico (la API contestó, solo rechazó la falta de token).
     * Desactivada/ausente = ["ERROR","..."] genérico, 404, u otra cosa.
     */
    private static function legacyAlive(int $code, $body): bool
    {
        if ($code === 200) { return true; }
        if (is_array($body) && isset($body[0]) && is_string($body[0])) {
            $first = strtoupper($body[0]);
            return $first !== 'ERROR' && strpos($first, 'ERROR_') === 0;
        }
        return false;
    }

    // ---- helpers HTTP / cache --------------------------------------------

    /** Normaliza a esquema+host(+puerto), sin path (le agregamos /apirest.php|/api.php). */
    private static function hostBase(string $url): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        // Quitar un /apirest.php final si vino la URL completa del legacy.
        $url = preg_replace('#/apirest\.php/?$#i', '', $url);
        $url = rtrim($url, '/');
        $p = parse_url($url);
        if (!$p || empty($p['host'])) { return ''; }
        $scheme = $p['scheme'] ?? 'https';
        $auth   = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        // Conservar un posible sub-path (p. ej. https://host/glpi).
        $path = rtrim($p['path'] ?? '', '/');
        return $auth . $path;
    }

    /**
     * GET/POST liviano. Devuelve [httpCode, bodyDecodificado, headersCrudos].
     * No lanza: ante error de transporte devuelve [0, null, ''].
     * @return array{0:int,1:mixed,2:string}
     */
    private static function http(string $method, string $url, array $cfg, bool $wantHeaders = false): array
    {
        $timeout = (int)($cfg['probe_timeout'] ?? 6);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_HEADER         => $wantHeaders,
        ];
        if (!empty($cfg['insecure'])) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if (!empty($cfg['resolve_host']) && !empty($cfg['resolve_ip'])) {
            $port = stripos($url, 'https://') === 0 ? 443 : 80;
            $opts[CURLOPT_RESOLVE] = [$cfg['resolve_host'] . ':' . $port . ':' . $cfg['resolve_ip']];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) { curl_close($ch); return [0, null, '']; }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = $wantHeaders ? (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
        curl_close($ch);
        $headers = $wantHeaders ? substr($resp, 0, $hsz) : '';
        $bodyRaw = $wantHeaders ? substr($resp, $hsz) : $resp;
        $decoded = json_decode((string)$bodyRaw, true);
        return [$code, $decoded, $headers];
    }

    /** Busca un header (case-insensitive) en el bloque crudo; null si no está. */
    private static function headerValue(string $rawHeaders, string $name): ?string
    {
        $name = strtolower($name);
        foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) { continue; }
            if (strtolower(trim(substr($line, 0, $pos))) === $name) {
                return trim(substr($line, $pos + 1));
            }
        }
        return null;
    }

    private static function cacheFile(array $cfg, string $base): string
    {
        $dir = $cfg['cache_dir'] ?? sys_get_temp_dir();
        return rtrim((string)$dir, '/\\') . '/glpi_api_probe_' . md5($base) . '.json';
    }

    private static function cacheGet(array $cfg, string $base, int $ttl): ?array
    {
        $f = self::cacheFile($cfg, $base);
        if (!is_file($f)) { return null; }
        if (time() - (int)@filemtime($f) > $ttl) { return null; }
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || empty($d['mode'])) { return null; }
        $d['reason'] = ($d['reason'] ?? '') . ' (cache)';
        return $d;
    }

    private static function cachePut(array $cfg, string $base, array $res): void
    {
        $f = self::cacheFile($cfg, $base);
        @file_put_contents($f, json_encode($res));
        @chmod($f, 0600);
    }
}
