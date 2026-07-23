<?php
/**
 * login.php — authenticate against GLPI with the user's own credentials.
 * On success stores the GLPI session token + role flags in the PHP session.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

$m = $_SERVER['REQUEST_METHOD'];
if ($m === 'GET') {
    echo json_encode([
        'auth'    => !empty($_SESSION['glpi_token']),
        'user'    => $_SESSION['user'] ?? null,
        'isAdmin' => $_SESSION['isAdmin'] ?? false,
        'isSuper' => $_SESSION['isSuper'] ?? false,
    ]);
    exit;
}
if ($m !== 'POST') { http_response_code(405); echo '{}'; exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$u = trim($in['usuario'] ?? '');
$p = (string)($in['password'] ?? '');
if ($u === '' || $p === '') { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$e = env();
if (empty($e['GLPI_APP_TOKEN'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'App-Token not configured on the server']);
    exit;
}

// GLPI accepts login/password as query params — works through Cloudflare too.
list($c, $d) = glpi_fetch('/initSession', ['login' => $u, 'password' => $p]);
$tok = $d['session_token'] ?? null;
if (!$tok) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

list($c2, $full) = glpi_fetch('/getFullSession', [], $tok);
$s = $full['session'] ?? $full ?? [];
$prof = strtolower($s['glpiactiveprofile']['name'] ?? '');

$_SESSION['glpi_token'] = $tok;
$_SESSION['glpiID']     = (int)($s['glpiID'] ?? 0);
$_SESSION['user']       = $s['glpifriendlyname'] ?? $s['glpiname'] ?? $u;
$_SESSION['profile']    = $s['glpiactiveprofile']['name'] ?? '';
$_SESSION['isAdmin']    = in_array($prof, ['super-admin', 'admin'], true);
$_SESSION['isSuper']    = (strpos($prof, 'superv') !== false);

echo json_encode([
    'ok' => true, 'user' => $_SESSION['user'],
    'isAdmin' => $_SESSION['isAdmin'], 'isSuper' => $_SESSION['isSuper'],
]);
