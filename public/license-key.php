<?php
/**
 * license-key.php — OPTIONAL example module: read/write a small marker in the
 * root entity's comment (used by a companion mobile app to self-provision a
 * license key). Admin-only for writes. Remove if unused.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];
$RE = '/__RHGPSLIC:(\{.*?\})__/s';
$m = $_SERVER['REQUEST_METHOD'];

if ($m === 'GET') {
    list($c, $e) = glpi_fetch('/Entity/0', [], $tok);
    $lic = null;
    if (is_array($e) && preg_match($RE, $e['comment'] ?? '', $mm)) { $lic = json_decode($mm[1], true); }
    echo json_encode([
        'canEdit' => !empty($_SESSION['isAdmin']),
        'key'     => $lic['key'] ?? '',
        'plan'    => $lic['plan'] ?? '',
    ]);
    exit;
}

if ($m === 'POST') {
    if (empty($_SESSION['isAdmin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin only']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = trim($in['key'] ?? '');
    $plan = trim($in['plan'] ?? 'standard');
    list($c, $e) = glpi_fetch('/Entity/0', [], $tok);
    $comment = is_array($e) ? ($e['comment'] ?? '') : '';
    $comment = trim(preg_replace($RE, '', $comment));
    if ($key !== '') {
        $marker = '__RHGPSLIC:' . json_encode(['key' => $key, 'plan' => $plan], JSON_UNESCAPED_SLASHES) . '__';
        $comment = ($comment !== '' ? $comment . "\n" : '') . $marker;
    }
    list($pc, $pr) = glpi_write('/Entity/0', 'PUT', ['input' => ['id' => 0, 'comment' => $comment]], $tok);
    echo json_encode(['ok' => ($pc >= 200 && $pc < 300), 'code' => $pc, 'key' => $key, 'plan' => $plan]);
    exit;
}

http_response_code(405);
echo '{}';
