<?php
/**
 * lib.php — shared backend helpers for the login-gated portal (BFF).
 *
 * Lives ONE LEVEL ABOVE the web docroot (/public). All config comes from
 * Settings (config/settings.json, edited via /setup.php) — nothing hardcoded.
 * The App-Token stays server-side; the browser only gets an HttpOnly cookie.
 *
 * @license MIT
 */
require_once __DIR__ . '/src/Settings.php';

/** Cached full settings. */
function cfg()
{
    static $c = null;
    if ($c === null) { $c = Settings::load(); }
    return $c;
}

/** Absolute base of the GLPI REST API (…/apirest.php). */
function glpi_api_base()
{
    $u = rtrim(cfg()['glpi']['url'] ?? '', '/');
    if ($u !== '' && !preg_match('#/apirest\.php$#i', $u)) { $u .= '/apirest.php'; }
    return $u;
}

/** Common curl options (TLS + optional same-host DNS override). */
function glpi_curl_opts()
{
    $g = cfg()['glpi'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => empty($g['insecure']),
        CURLOPT_SSL_VERIFYHOST => empty($g['insecure']) ? 2 : 0,
    ];
    if (!empty($g['resolve_host']) && !empty($g['resolve_ip'])) {
        $port = (stripos(glpi_api_base(), 'https://') === 0) ? 443 : 80;
        $opts[CURLOPT_RESOLVE] = [$g['resolve_host'] . ':' . $port . ':' . $g['resolve_ip']];
    }
    return $opts;
}

/** App-Token header value. */
function glpi_app_token()
{
    return cfg()['glpi']['app_token'] ?? '';
}

/** GET a GLPI REST path. Returns [httpCode, decodedBody]. */
function glpi_fetch($path, $params = [], $sessionToken = null)
{
    $url = glpi_api_base() . $path;
    if ($params) { $url .= '?' . http_build_query($params); }
    $h = ['App-Token: ' . glpi_app_token(), 'Accept: application/json'];
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
    $url = glpi_api_base() . $path;
    $h = ['App-Token: ' . glpi_app_token(), 'Session-Token: ' . $sessionToken,
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

/** Start a hardened PHP session (HttpOnly, SameSite=Lax, Secure on HTTPS). */
function panel_session()
{
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
