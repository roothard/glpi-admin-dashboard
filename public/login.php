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

if (glpi_app_token() === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'App-Token not configured — open /setup.php']);
    exit;
}

// Brute-force throttle: after 10 failed attempts per client IP, block for 15 min.
// (Each bad attempt can also cost GLPI seconds of LDAP fallback, so this caps that too.)
$thrFile = dirname(__DIR__) . '/config/login-throttle.json';
$thrKey  = hash('sha256', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?');
$now     = time();
$thr     = is_file($thrFile) ? (json_decode((string)file_get_contents($thrFile), true) ?: []) : [];
$thr     = array_filter($thr, fn($v) => ($now - ($v['t'] ?? 0)) < 900);
if (($thr[$thrKey]['n'] ?? 0) >= 10) {
    header('Retry-After: 900');
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'throttled']);
    exit;
}

// Credentials travel in a Basic header, never in the URL (query params end up
// in web-server access logs). If your GLPI strips the Authorization header
// (some Cloudflare/FastCGI setups), enable tokens_in_query in /setup.php —
// with the caveat that GLPI's own access log will then see the credentials.
if (!empty(cfg()['glpi']['tokens_in_query'])) {
    list($c, $d) = glpi_fetch('/initSession', ['login' => $u, 'password' => $p]);
} else {
    list($c, $d) = glpi_fetch('/initSession', [], null,
        ['Authorization: Basic ' . base64_encode($u . ':' . $p)]);
}
$tok = $d['session_token'] ?? null;
if (!$tok) {
    $thr[$thrKey] = ['n' => ($thr[$thrKey]['n'] ?? 0) + 1, 't' => $now];
    @file_put_contents($thrFile, json_encode($thr), LOCK_EX);
    http_response_code(401); echo json_encode(['ok' => false]); exit;
}
unset($thr[$thrKey]);
@file_put_contents($thrFile, json_encode($thr), LOCK_EX);
session_regenerate_id(true); // new session id on privilege change (anti-fixation)

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
