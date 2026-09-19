<?php
/**
 * processes.php — Compliance process definitions: what must happen and how
 * often. Stored in config/processes.json (above docroot), same pattern as the
 * Map board (board.php). Read: any logged-in user. Write: admins only.
 * The dashboard only stores what is EXPECTED; the evidence lives in GLPI.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$file = dirname(__DIR__) . '/config/processes.json';
$load = function () use ($file) {
    $p = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    return array_values($p['processes'] ?? []);
};

$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    $out = ['processes' => $load(), 'canEdit' => !empty($_SESSION['isAdmin'])];
    if (isset($_GET['lists'])) {
        // Para el editor: categorías y entidades REALES de GLPI (las que ve el usuario)
        $tok = $_SESSION['glpi_token'];
        $fetchAll = function (string $type) use ($tok) {
            $acc = []; $start = 0; $page = 250;
            do {
                [$c, $b] = glpi_fetch('/' . $type, ['range' => $start . '-' . ($start + $page - 1)], $tok);
                if ($c >= 400 || !is_array($b)) { break; }
                $rows = (isset($b[0]) || $b === []) ? $b : [$b];
                foreach ($rows as $r) {
                    if (is_array($r) && isset($r['id'])) {
                        $acc[] = ['id' => (int)$r['id'], 'name' => (string)($r['completename'] ?? $r['name'] ?? $r['id'])];
                    }
                }
                $got = count($rows); $start += $got;
            } while ($got === $page && $start < 2000);
            usort($acc, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return $acc;
        };
        $out['cats'] = $fetchAll('ITILCategory');
        $out['ents'] = $fetchAll('Entity');
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($m === 'POST') {
    if (empty($_SESSION['isAdmin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin only']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $valid = ['daily', 'weekly', 'monthly', 'quarterly', 'halfyearly', 'yearly'];
    $out = []; $seen = [];
    foreach (array_slice((array)($in['processes'] ?? []), 0, 50) as $p) {
        if (!is_array($p)) continue;
        $name = trim((string)($p['name'] ?? ''));
        $by   = ($p['match']['by'] ?? '') === 'title' ? 'title' : 'category';
        $val  = $by === 'category' ? (int)($p['match']['value'] ?? 0) : trim((string)($p['match']['value'] ?? ''));
        if ($name === '' || ($by === 'category' && $val <= 0) || ($by === 'title' && $val === '')) continue;
        $id = trim((string)($p['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-z0-9-]{1,60}$/', $id)) {
            $id = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)), 0, 60);
            $id = trim($id, '-') ?: 'proc';
        }
        while (isset($seen[$id])) { $id .= 'x'; }
        $seen[$id] = 1;
        $eid = max(0, (int)($p['entities_id'] ?? 0));
        $out[] = [
            'id'          => $id,
            'name'        => mb_substr($name, 0, 120),
            'every'       => in_array($p['every'] ?? '', $valid, true) ? $p['every'] : 'monthly',
            'owner'       => mb_substr(trim((string)($p['owner'] ?? '')), 0, 80),
            'match'       => ['by' => $by, 'value' => $val],
            'grace_days'  => max(0, min(30, (int)($p['grace_days'] ?? 0))),
            'entities_id' => $eid, // 0 = todas las entidades
            'entity_name' => $eid ? mb_substr(trim((string)($p['entity_name'] ?? '')), 0, 120) : '',
        ];
    }
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ok = file_put_contents($file, json_encode(['processes' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    @chmod($file, 0664);
    echo json_encode(['ok' => $ok, 'processes' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo '{}';
