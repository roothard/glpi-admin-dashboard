<?php
/**
 * alertas.php — alertas de monitoreo ya mapeadas a un equipo del inventario
 * (y, si corresponde, a un puerto), para la capa opcional de la vista 3D.
 *
 * El panel corre en un server que NO alcanza la LAN de monitoreo, asi que esta
 * capa se alimenta de un archivo-feed que un relay en la LAN escribe (empuja)
 * desde el monitoreo: config/cube3d-alertas.json (arriba del docroot). Si el
 * archivo no existe, no hay alertas y la vista simplemente no dibuja la capa.
 *
 * Forma del feed:  { "alertas": [ { "equipo","interfaz","severidad","nombre","desde" } ], "actualizado": <epoch> }
 *   - "equipo": nombre tal cual figura en el inventario de GLPI (asi el front lo ubica).
 *   - "interfaz": puerto (ej. "gi12"), opcional.
 *   - "severidad": 0..5.
 *
 * @license MIT
 */
require dirname(__DIR__, 3) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$feed = dirname(__DIR__, 3) . '/config/cube3d-alertas.json';
$out = ['alertas' => [], 'actualizado' => time()];

if (is_readable($feed)) {
    $d = json_decode((string)@file_get_contents($feed), true);
    if (is_array($d) && isset($d['alertas']) && is_array($d['alertas'])) {
        foreach ($d['alertas'] as $a) {
            if (!is_array($a) || ($a['equipo'] ?? '') === '') { continue; }
            $out['alertas'][] = [
                'equipo'    => (string)$a['equipo'],
                'interfaz'  => isset($a['interfaz']) && $a['interfaz'] !== '' ? (string)$a['interfaz'] : null,
                'severidad' => max(0, min(5, (int)($a['severidad'] ?? 0))),
                'nombre'    => (string)($a['nombre'] ?? ''),
                'desde'     => (int)($a['desde'] ?? 0),
            ];
        }
        if (isset($d['actualizado'])) { $out['actualizado'] = (int)$d['actualizado']; }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
