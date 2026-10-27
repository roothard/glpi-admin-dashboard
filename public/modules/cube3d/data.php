<?php
/**
 * data.php — rack(s) del inventario de GLPI para la vista 3D.
 *
 * Solo lectura, con la SESIÓN DEL USUARIO (GLPI aplica el aislamiento por
 * entidad). Devuelve, por cada rack que el usuario puede ver, sus equipos con
 * la posición (U), el alto en unidades (tomado de required_units del modelo),
 * modelo, fabricante y serie. Sin dependencias de monitoreo: las alertas son
 * una capa aparte y opcional.
 *
 * Forma de la respuesta:
 *   { "auth": true, "racks": [ { "id", "nombre", "unidades",
 *       "equipos": [ { "nombre","tipo","u","alto","alto_origen","hpos",
 *                      "modelo","fabricante","serie" } ] } ] }
 *
 * @license MIT
 */
require dirname(__DIR__, 3) . '/lib.php';   // public/modules/cube3d/ -> raíz del proyecto
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

// itemtype -> tipo de modelo del que sale el alto en U (required_units).
$MODELOS = [
    'Computer'            => 'ComputerModel',
    'NetworkEquipment'    => 'NetworkEquipmentModel',
    'Peripheral'          => 'PeripheralModel',
    'PassiveDCEquipment'  => 'PassiveDCEquipmentModel',
    'Enclosure'           => 'EnclosureModel',
    'PDU'                 => 'PDUModel',
    'Monitor'             => 'MonitorModel',
];

/** Normaliza a lista: GLPI puede devolver un error (array asociativo) en vez de filas. */
function filas($d) { return is_array($d) ? $d : []; }

// ── Racks visibles para el usuario ───────────────────────────────────────────
list($rc, $racks) = glpi_fetch('/Rack', ['range' => '0-200'], $tok);
if ($rc === 401 || $rc === 403) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }
$racks = filas($racks);

// ── Relación equipo↔rack (sin expand: racks_id numérico, para cruzar por id) ──
list($ic, $items) = glpi_fetch('/Item_Rack', ['range' => '0-5000'], $tok);
$porRack = [];
foreach (filas($items) as $it) {
    if (!is_array($it) || !isset($it['racks_id'])) { continue; }
    $porRack[(int)$it['racks_id']][] = $it;
}

// Caches para no repetir llamadas.
$detCache = [];   // "Itemtype#id" => item expandido
$modCache = [];   // "ModelType"   => [ nombreModelo => required_units ]

/** Mapa nombreModelo => alto en U, bajando una sola vez cada tabla de modelos. */
function alturasDeModelo($modType, $tok)
{
    global $modCache;
    if (!isset($modCache[$modType])) {
        list(, $d) = glpi_fetch("/$modType", ['range' => '0-2000', 'expand_dropdowns' => 'true'], $tok);
        $m = [];
        foreach (filas($d) as $row) {
            if (is_array($row) && isset($row['name'])) { $m[(string)$row['name']] = (int)($row['required_units'] ?? 0); }
        }
        $modCache[$modType] = $m;
    }
    return $modCache[$modType];
}

$salida = [];
foreach ($racks as $r) {
    if (!is_array($r) || !isset($r['id'])) { continue; }    // descarta errores de GLPI
    $rid = (int)$r['id'];
    $equipos = [];
    foreach (($porRack[$rid] ?? []) as $it) {
        $tipo = (string)($it['itemtype'] ?? '');
        $iid  = (int)($it['items_id'] ?? 0);
        if ($tipo === '' || !$iid) { continue; }

        $key = "$tipo#$iid";
        if (!isset($detCache[$key])) {
            list(, $dd) = glpi_fetch("/$tipo/$iid", ['expand_dropdowns' => 'true'], $tok);
            $detCache[$key] = is_array($dd) ? $dd : [];
        }
        $det = $detCache[$key];

        $nombre = (string)($det['name'] ?? $det['completename'] ?? ('#' . $iid));
        $campoModelo = strtolower($tipo) . 'models_id';
        $modelo = $det[$campoModelo] ?? '';
        if (!is_string($modelo)) { $modelo = ''; }

        $alto = null; $origen = null;
        if ($modelo !== '' && isset($MODELOS[$tipo])) {
            $u = alturasDeModelo($MODELOS[$tipo], $tok)[$modelo] ?? 0;
            if ($u > 0) { $alto = $u; $origen = 'modelo'; }
        }
        $fab = $det['manufacturers_id'] ?? '';

        $equipos[] = [
            'nombre'      => $nombre,
            'tipo'        => $tipo,
            'u'           => (int)($it['position'] ?? 1),
            'alto'        => $alto,
            'alto_origen' => $origen,
            'hpos'        => (int)($it['hpos'] ?? 0),
            'modelo'      => $modelo,
            'fabricante'  => is_string($fab) ? $fab : '',
            'serie'       => (string)($det['serial'] ?? ''),
        ];
    }
    usort($equipos, fn($a, $b) => $b['u'] <=> $a['u']);   // de arriba (U alta) hacia abajo
    $salida[] = [
        'id'       => $rid,
        'nombre'   => (string)($r['name'] ?? ('Rack ' . $rid)),
        'unidades' => ((int)($r['number_units'] ?? 42)) ?: 42,
        'equipos'  => $equipos,
    ];
}

echo json_encode(['auth' => true, 'racks' => $salida], JSON_UNESCAPED_UNICODE);
