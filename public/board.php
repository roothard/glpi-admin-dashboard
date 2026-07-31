<?php
/**
 * board.php — the "Map" custom board: user-defined areas and per-project
 * placement, independent of GLPI. Stored in config/board.json (above docroot).
 * Read: any logged-in user. Write: admins only.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$file = dirname(__DIR__) . '/config/board.json';
$load = function () use ($file) {
    $b = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    return ['areas' => array_values($b['areas'] ?? []), 'assign' => $b['assign'] ?? []];
};

$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $b = $load();
    $b['canEdit'] = !empty($_SESSION['isAdmin']);
    echo json_encode($b, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($m === 'POST') {
    if (empty($_SESSION['isAdmin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin only']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    // Sanitize: unique non-empty area names; assign maps int projectId -> area string.
    $areas = [];
    foreach (($in['areas'] ?? []) as $a) {
        $a = trim((string)$a);
        if ($a !== '' && !in_array($a, $areas, true)) { $areas[] = $a; }
    }
    $assign = [];
    foreach (($in['assign'] ?? []) as $pid => $area) {
        $area = trim((string)$area);
        if ((int)$pid > 0 && $area !== '' && in_array($area, $areas, true)) {
            $assign[(string)(int)$pid] = $area;
        }
    }
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ok = file_put_contents($file, json_encode(['areas' => $areas, 'assign' => $assign], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    @chmod($file, 0664);
    echo json_encode(['ok' => $ok]);
    exit;
}

http_response_code(405);
echo '{}';
