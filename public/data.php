<?php
/**
 * data.php — serve the cached board, filtered to the projects the LOGGED-IN
 * user can actually see in GLPI (per-user scoping via their session token).
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

// Which projects can this user see? (expand_dropdowns → readable type name)
list($c, $list) = glpi_fetch('/Project', ['range' => '0-500', 'expand_dropdowns' => 'true'], $tok);
if ($c === 401 || $c === 403) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }

$type = trim((string)(cfg()['projects']['project_type'] ?? '')); // empty = all types
$incU = (bool)(cfg()['projects']['include_untyped'] ?? true);    // show untyped (flagged) even with a filter
$vis = [];
if (is_array($list)) {
    foreach ($list as $p) {
        if ($type !== '') {
            $t = trim((string)($p['projecttypes_id'] ?? ''));
            $untyped = ($t === '' || $t === '0' || $t === '&nbsp;');
            if (strcasecmp($t, $type) !== 0 && !($untyped && $incU)) { continue; }
        }
        $vis[(int)$p['id']] = true;
    }
}

$cache = json_decode(@file_get_contents(dirname(__DIR__) . '/data-cache.json'), true) ?: ['projects' => [], 'states' => []];
$total = count($cache['projects']);
$cache['projects'] = array_values(array_filter($cache['projects'], fn($p) => isset($vis[$p['id']])));
$cache['user']    = $_SESSION['user'];
$cache['isAdmin'] = $_SESSION['isAdmin'] ?? false;
$cache['isSuper'] = $_SESSION['isSuper'] ?? false;
// Honest visibility stats: what this user sees vs. everything on the board,
// plus how many shown projects have no type set in GLPI (flagged in the UI).
$cache['stats'] = [
    'shown'       => count($cache['projects']),
    'total'       => $total,
    'untyped'     => count(array_filter($cache['projects'], fn($p) => !empty($p['nt']))),
    'type_filter' => $type,
];

echo json_encode($cache, JSON_UNESCAPED_UNICODE);
