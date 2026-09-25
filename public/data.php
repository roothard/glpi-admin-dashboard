<?php
/**
 * data.php — tablero POR SESIÓN. Construye la data EN VIVO con el token de la
 * sesión del usuario logueado (su propio alcance en GLPI), la cachea en la
 * sesión PHP, la refresca cuando envejece, y muere con la sesión.
 *
 * No se guarda ningún token de servicio en ningún lado: la data sale de la
 * credencial que el usuario ya tiene por haber iniciado sesión.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';                       // ya carga Settings (require_once)
require_once dirname(__DIR__) . '/src/GlpiClient.php';
require_once dirname(__DIR__) . '/src/DashboardGenerator.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$ttl   = (int)(cfg()['projects']['live_ttl'] ?? 300);  // refresco mientras la sesión vive (seg)
$force = isset($_GET['refresh']);
$now   = time();
$fromCache = false;
$buildMs   = 0;
$data = null;

if (!$force && !empty($_SESSION['board']) && ($now - (int)($_SESSION['board_ts'] ?? 0)) < $ttl) {
    $data = $_SESSION['board'];                       // cache de sesión aún fresco
    $fromCache = true;
} else {
    try {
        $flat   = Settings::flat();
        $client = new GlpiClient($flat);
        $client->useSession($_SESSION['glpi_token']); // ← la sesión del usuario, sin token de servicio
        $gen    = new DashboardGenerator($client, $flat);
        $t0     = microtime(true);
        $data   = $gen->buildLive();
        $buildMs = (int)round((microtime(true) - $t0) * 1000);
        $_SESSION['board']    = $data;
        $_SESSION['board_ts'] = $now;
    } catch (\Throwable $e) {
        // Sesión vencida / sin permisos: servir lo último si hay, si no re-login.
        if (!empty($_SESSION['board'])) { $data = $_SESSION['board']; $fromCache = true; }
        else { http_response_code(401); echo json_encode(['auth' => false, 'error' => 'build_failed']); exit; }
    }
}

$projs = $data['projects'] ?? [];
$data['user']    = $_SESSION['user'];
$data['isAdmin'] = $_SESSION['isAdmin'] ?? false;
$data['isSuper'] = $_SESSION['isSuper'] ?? false;
$data['isSuperAdmin'] = ($_SESSION['isSuperAdmin'] ?? false) || (strtolower($_SESSION['profile'] ?? '') === 'super-admin');
$data['stats']   = [
    // Compatibles con el front. En el modelo por-sesión el usuario construye su
    // propio universo, así que "total" = "shown" (ve todo lo suyo, no hay un set mayor).
    'shown'       => count($projs),
    'total'       => count($projs),
    'untyped'     => count(array_filter($projs, fn($p) => !empty($p['nt']))),
    'type_filter' => trim((string)(cfg()['projects']['project_type'] ?? '')),
    // Diagnóstico del modelo live:
    'live'        => true,
    'from_cache'  => $fromCache,
    'age_s'       => $now - (int)($_SESSION['board_ts'] ?? $now),
    'ttl_s'       => $ttl,
    'build_ms'    => $buildMs,
];
echo json_encode($data, JSON_UNESCAPED_UNICODE);
