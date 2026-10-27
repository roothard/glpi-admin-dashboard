<?php
/**
 * cables.php — cableado (puerto de origen -> equipo/conector de destino) para la
 * capa opcional de la vista 3D.
 *
 * Igual que alertas.php, se alimenta de un archivo-feed que un relay en la LAN
 * escribe: config/cube3d-cables.json (arriba del docroot). Si no existe, no se
 * dibuja el cableado.
 *
 * Forma del feed:
 *   { "cables": [ { "origen","puerto","num","equipo","conector","destPuerto"?,"enlace","dudoso" } ],
 *     "enlaces": { "<puerto>": true|false }, "actualizado": <epoch> }
 *   - "origen": equipo donde arranca el cable (switch/patchera), nombre del inventario.
 *   - "puerto"/"num": identificador y numero de la boca de origen.
 *   - "equipo"/"conector": destino; "destPuerto" si termina en otra boca (parcheo).
 *   - "enlace": "up" | "down".
 *
 * @license MIT
 */
require dirname(__DIR__, 3) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$feed = dirname(__DIR__, 3) . '/config/cube3d-cables.json';
$out = ['cables' => [], 'enlaces' => new stdClass(), 'actualizado' => time()];

if (is_readable($feed)) {
    $d = json_decode((string)@file_get_contents($feed), true);
    if (is_array($d) && isset($d['cables']) && is_array($d['cables'])) {
        foreach ($d['cables'] as $c) {
            if (!is_array($c) || ($c['equipo'] ?? '') === '') { continue; }
            $out['cables'][] = [
                'origen'    => (string)($c['origen'] ?? ''),
                'puerto'    => (string)($c['puerto'] ?? ''),
                'num'       => (int)($c['num'] ?? 0),
                'equipo'    => (string)$c['equipo'],
                'conector'  => (string)($c['conector'] ?? ''),
                'destPuerto' => isset($c['destPuerto']) && $c['destPuerto'] !== '' ? (int)$c['destPuerto'] : null,
                'enlace'    => ($c['enlace'] ?? 'up') === 'down' ? 'down' : 'up',
                'dudoso'    => !empty($c['dudoso']),
            ];
        }
        if (isset($d['enlaces']) && is_array($d['enlaces'])) { $out['enlaces'] = $d['enlaces']; }
        if (isset($d['actualizado'])) { $out['actualizado'] = (int)$d['actualizado']; }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
