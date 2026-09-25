<?php
/**
 * 2fa.php — enroll / manage the second factor for the logged-in user.
 *
 * Requires an active panel session. The identity is the GLPI user id, so 2FA
 * follows the person across the federated login (the panel has no user table).
 *
 * Actions (POST JSON, ?action= or "action" field):
 *   status    → { enabled, enrolled, has_recovery }
 *   begin     → start enrollment: returns a fresh secret + otpauth URI
 *   confirm   → { code } verify the secret works, enable 2FA, return recovery codes (once)
 *   disable   → { code } turn 2FA off (needs a valid code)
 *   recodes   → { code } regenerate recovery codes (needs a valid code)
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/rh-auth.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token']) || empty($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

$sec = cfg()['security'] ?? [];
rh_auth_config([
    'dir'          => dirname(__DIR__) . '/config',
    'app'          => 'dashboard',
    'wazuh_syslog' => (bool)($sec['wazuh_syslog'] ?? true),
]);

$id     = 'glpi:' . (int)$_SESSION['glpiID'];
$user   = $_SESSION['user'] ?? '';
$issuer = cfg()['branding']['app_name'] ?? 'Dashboard';
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? 'status');

/** Verify a TOTP or recovery code for the current user (used to gate changes). */
function verify_current(string $id, string $code): bool
{
    $rec = rh_2fa_get($id) ?: [];
    if (!empty($rec['secret']) && rh_totp_verify($rec['secret'], $code)) { return true; }
    foreach (($rec['recovery'] ?? []) as $k => $h) {
        if (password_verify(strtoupper(trim($code)), $h)) {
            unset($rec['recovery'][$k]); rh_2fa_put($id, $rec); return true;
        }
    }
    return false;
}

if ($action === 'status') {
    $rec = rh_2fa_get($id) ?: [];
    echo json_encode([
        'ok'           => true,
        'enabled'      => rh_2fa_enabled($id),
        'has_recovery' => !empty($rec['recovery']),
    ]);
    exit;
}

if ($action === 'begin') {
    $rec = rh_2fa_get($id) ?: [];
    $secret = rh_totp_secret();
    $rec['pending_secret'] = $secret;          // not active until confirmed
    rh_2fa_put($id, $rec);
    echo json_encode([
        'ok'      => true,
        'secret'  => $secret,
        'grouped' => trim(chunk_split($secret, 4, ' ')),
        'uri'     => rh_totp_uri($secret, $user !== '' ? $user : $id, $issuer),
    ]);
    exit;
}

if ($action === 'confirm') {
    $rec = rh_2fa_get($id) ?: [];
    $secret = $rec['pending_secret'] ?? '';
    $code   = (string)($in['code'] ?? '');
    if ($secret === '') { echo json_encode(['ok' => false, 'error' => 'no_pending']); exit; }
    if (!rh_totp_verify($secret, $code)) {
        echo json_encode(['ok' => false, 'error' => 'bad_code']); exit;
    }
    $plain = rh_2fa_recovery_codes();
    $rec['secret']   = $secret;
    $rec['enabled']  = true;
    $rec['enrolled_at'] = date('c');
    $rec['recovery'] = array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $plain);
    unset($rec['pending_secret']);
    rh_2fa_put($id, $rec);
    rh_auth_log('2fa_enrolled', $user);
    echo json_encode(['ok' => true, 'recovery' => $plain]);
    exit;
}

if ($action === 'disable') {
    if (!rh_2fa_enabled($id)) { echo json_encode(['ok' => true]); exit; }
    if (!verify_current($id, (string)($in['code'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'bad_code']); exit;
    }
    rh_2fa_delete($id);
    rh_auth_log('2fa_disabled', $user);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'recodes') {
    if (!rh_2fa_enabled($id)) { echo json_encode(['ok' => false, 'error' => 'not_enabled']); exit; }
    if (!verify_current($id, (string)($in['code'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'bad_code']); exit;
    }
    $rec = rh_2fa_get($id) ?: [];
    $plain = rh_2fa_recovery_codes();
    $rec['recovery'] = array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $plain);
    rh_2fa_put($id, $rec);
    echo json_encode(['ok' => true, 'recovery' => $plain]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
