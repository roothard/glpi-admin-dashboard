<?php
/**
 * energia.php — cableado de energía (equipo → PDU/UPS) desde GLPI.
 *
 * Lee los Cables de GLPI cuyo TIPO parece de energía (nombre con
 * "aliment"/"power"/"energ") con la SESIÓN DEL USUARIO, y los devuelve mapeados
 * a nombre de equipo y destino. No pasa por el relay: es dato de GLPI, que el
 * panel sí alcanza (mismo server). Si no hay cables de energía, la capa no se
 * dibuja.
 *
 * Convención de los cables: un extremo es el equipo y el otro la fuente. La
 * fuente es un PDU (itemtype PDU) o, si no, el extremo B (un UPS u otra fuente).
 *
 * Respuesta: { "auth": true, "energia": [ { "equipo","destino","destino_tipo" } ] }
 *   - destino_tipo: "pdu" (va a la regleta) | "ups" (va a ese equipo del rack).
 *
 * @license MIT
 */
require dirname(__DIR__, 3) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

list($rc, $cables) = glpi_fetch('/Cable', ['range' => '0-2000', 'expand_dropdowns' => 'true'], $tok);
if ($rc === 401 || $rc === 403) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }

$nombreCache = [];
/** Nombre de un item del inventario (cacheado). */
function nombreItem($itemtype, $id, $tok, &$cache)
{
    $itemtype = preg_replace('/[^A-Za-z]/', '', (string)$itemtype);
    $id = (int)$id;
    if ($itemtype === '' || !$id) { return ''; }
    $k = "$itemtype#$id";
    if (!array_key_exists($k, $cache)) {
        list(, $d) = glpi_fetch("/$itemtype/$id", [], $tok);
        $cache[$k] = is_array($d) ? (string)($d['name'] ?? $d['completename'] ?? ('#' . $id)) : '';
    }
    return $cache[$k];
}

$out = [];
foreach ((is_array($cables) ? $cables : []) as $c) {
    if (!is_array($c)) { continue; }
    $tipo = (string)($c['cabletypes_id'] ?? '');      // con expand_dropdowns es el nombre del tipo
    if (!preg_match('/aliment|power|energ/i', $tipo)) { continue; }

    $ta = (string)($c['itemtype_endpoint_a'] ?? ''); $ia = (int)($c['items_id_endpoint_a'] ?? 0);
    $tb = (string)($c['itemtype_endpoint_b'] ?? ''); $ib = (int)($c['items_id_endpoint_b'] ?? 0);
    if (!$ia || !$ib) { continue; }

    if ($ta === 'PDU') {                 // la fuente es el PDU (extremo A)
        $equipo = nombreItem($tb, $ib, $tok, $nombreCache); $destino = 'PDU'; $dtipo = 'pdu';
    } elseif ($tb === 'PDU') {           // la fuente es el PDU (extremo B)
        $equipo = nombreItem($ta, $ia, $tok, $nombreCache); $destino = 'PDU'; $dtipo = 'pdu';
    } else {                             // sin PDU: A = equipo, B = fuente (UPS)
        $equipo = nombreItem($ta, $ia, $tok, $nombreCache);
        $destino = nombreItem($tb, $ib, $tok, $nombreCache); $dtipo = 'ups';
    }
    if ($equipo === '') { continue; }
    $out[] = ['equipo' => $equipo, 'destino' => $destino, 'destino_tipo' => $dtipo];
}

echo json_encode(['auth' => true, 'energia' => $out], JSON_UNESCAPED_UNICODE);
