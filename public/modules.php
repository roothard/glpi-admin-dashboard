<?php
/**
 * modules.php — descubrimiento genérico de módulos "drop-in".
 *
 * Escanea public/modules/<id>/module.json y devuelve los módulos VISIBLES para
 * el usuario logueado, filtrados por la clave "entidades" del manifiesto contra
 * las entidades GLPI del usuario (su propia sesión). Sin esto, un módulo solo se
 * alcanza por URL directa.
 *
 * Contrato del manifiesto module.json (compromiso de API del producto):
 *   { "id":"crm", "nombre":"CRM", "descripcion":"…", "icono":"briefcase",
 *     "entrada":"index.php", "orden":50, "entidades":[15] }
 *   - entidades ausente o [] = visible para cualquier usuario logueado.
 *   - entidades:[…] = visible si el usuario tiene acceso a alguna de ellas
 *     (o si es admin/supervisor, que supervisan todo).
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok     = $_SESSION['glpi_token'];
$isAdmin = !empty($_SESSION['isAdmin']) || !empty($_SESSION['isSuper']);

// Entidades a las que el usuario tiene acceso (para el filtro por manifiesto).
$myEnts = [];
list($ec, $ed) = glpi_fetch('/getMyEntities', ['is_recursive' => 'true'], $tok);
if (is_array($ed)) {
    $rows = $ed['myentities'] ?? (array_is_list_safe($ed) ? $ed : [$ed]);
    foreach ((array)$rows as $e) {
        if (is_array($e) && isset($e['id'])) { $myEnts[(int)$e['id']] = true; }
    }
}

/** Polyfill acotado (evita depender de array_is_list). */
function array_is_list_safe($a): bool
{
    if (!is_array($a) || $a === []) { return is_array($a); }
    return array_keys($a) === range(0, count($a) - 1);
}

$out = [];
foreach (glob(__DIR__ . '/modules/*/module.json') as $mf) {
    $m = json_decode((string)@file_get_contents($mf), true);
    if (!is_array($m) || empty($m['id']) || empty($m['entrada'])) { continue; }
    $mid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$m['id']);   // sanea (es parte de la URL)
    if ($mid === '' || $mid !== basename(dirname($mf))) { continue; } // id debe coincidir con la carpeta
    $ents = array_map('intval', (array)($m['entidades'] ?? []));

    $vis = empty($ents) || $isAdmin;
    if (!$vis) {
        foreach ($ents as $eid) { if (isset($myEnts[$eid])) { $vis = true; break; } }
    }
    if (!$vis) { continue; }

    $entrada = ltrim((string)$m['entrada'], '/');
    if (strpos($entrada, '..') !== false) { continue; }              // sin traversal
    $out[] = [
        'id'     => $mid,
        'nombre' => (string)($m['nombre'] ?? $mid),
        'desc'   => (string)($m['descripcion'] ?? ''),
        'icono'  => (string)($m['icono'] ?? 'box'),
        'orden'  => (int)($m['orden'] ?? 100),
        'url'    => 'modules/' . rawurlencode($mid) . '/' . $entrada,
    ];
}
usort($out, fn($a, $b) => ($a['orden'] <=> $b['orden']) ?: strcasecmp($a['nombre'], $b['nombre']));
echo json_encode(['modules' => $out], JSON_UNESCAPED_UNICODE);
