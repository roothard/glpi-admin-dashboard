<?php
/**
 * lib.php — shared backend helpers for the login-gated dashboard (BFF).
 *
 * Lives ONE LEVEL ABOVE the web docroot (/public). Reads config from ../.env.
 * The App-Token stays server-side; the browser only ever gets an HttpOnly
 * PHP session cookie.
 *
 * @license MIT
 */

/** Load and cache the .env (repo root, above docroot). */
function env()
{
    static $e = null;
    if ($e === null) {
        $f = __DIR__ . '/.env';
        $e = is_file($f) ? (parse_ini_file($f, false, INI_SCANNER_RAW) ?: []) : [];
        // Overlay real environment variables (handy for Docker).
        foreach (['GLPI_URL','GLPI_APP_TOKEN','GLPI_USER_TOKEN','PROJECT_TYPE',
                  'DB_HOST','DB_NAME','DB_USER','DB_PASS','GLPI_INSECURE',
                  'GLPI_RESOLVE_HOST','GLPI_RESOLVE_IP'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') { $e[$k] = $v; }
        }
    }
    return $e;
}

/** Absolute base of the GLPI REST API, e.g. https://glpi.example.com/apirest.php */
function glpi_api_base()
{
    $e = env();
    $u = rtrim($e['GLPI_URL'] ?? ($e['GLPI_API_URL'] ?? ''), '/');
    if ($u !== '' && !preg_match('#/apirest\.php$#i', $u)) {
        $u .= '/apirest.php';
    }
    return $u;
}

/** Common curl options (SSL, optional same-host DNS override). */
function glpi_curl_opts()
{
    $e = env();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => empty($e['GLPI_INSECURE']),
        CURLOPT_SSL_VERIFYHOST => empty($e['GLPI_INSECURE']) ? 2 : 0,
    ];
    // Optional: force a host to resolve to a specific IP (e.g. hit GLPI on
    // localhost when the dashboard shares the server). Leave unset otherwise.
    if (!empty($e['GLPI_RESOLVE_HOST']) && !empty($e['GLPI_RESOLVE_IP'])) {
        $port = (stripos(glpi_api_base(), 'https://') === 0) ? 443 : 80;
        $opts[CURLOPT_RESOLVE] = [$e['GLPI_RESOLVE_HOST'] . ':' . $port . ':' . $e['GLPI_RESOLVE_IP']];
    }
    return $opts;
}

/** GET a GLPI REST path. Returns [httpCode, decodedBody]. */
function glpi_fetch($path, $params = [], $sessionToken = null)
{
    $e = env();
    $url = glpi_api_base() . $path;
    if ($params) { $url .= '?' . http_build_query($params); }
    $h = ['App-Token: ' . ($e['GLPI_APP_TOKEN'] ?? ''), 'Accept: application/json'];
    if ($sessionToken) { $h[] = 'Session-Token: ' . $sessionToken; }
    $ch = curl_init($url);
    curl_setopt_array($ch, glpi_curl_opts() + [CURLOPT_HTTPHEADER => $h]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true)];
}

/** Write to a GLPI REST path (POST/PUT/DELETE). Returns [httpCode, decodedBody]. */
function glpi_write($path, $method, $body, $sessionToken)
{
    $e = env();
    $url = glpi_api_base() . $path;
    $h = ['App-Token: ' . ($e['GLPI_APP_TOKEN'] ?? ''), 'Session-Token: ' . $sessionToken,
          'Content-Type: application/json', 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, glpi_curl_opts() + [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_HTTPHEADER    => $h,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($r, true)];
}

/** Start a hardened PHP session (HttpOnly, SameSite=Lax, Secure when on HTTPS). */
function panel_session()
{
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime'  => 0, 'path' => '/', 'secure' => $https,
        'httponly'  => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
