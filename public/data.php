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
$vis = [];
if (is_array($list)) {
    foreach ($list as $p) {
        if ($type !== '') {
            $t = $p['projecttypes_id'] ?? '';
            if (strcasecmp((string)$t, $type) !== 0) { continue; }
        }
        $vis[(int)$p['id']] = true;
    }
}

$cache = json_decode(@file_get_contents(dirname(__DIR__) . '/data-cache.json'), true) ?: ['projects' => [], 'states' => []];
$cache['projects'] = array_values(array_filter($cache['projects'], fn($p) => isset($vis[$p['id']])));
$cache['user']    = $_SESSION['user'];
$cache['isAdmin'] = $_SESSION['isAdmin'] ?? false;
$cache['isSuper'] = $_SESSION['isSuper'] ?? false;

echo json_encode($cache, JSON_UNESCAPED_UNICODE);
