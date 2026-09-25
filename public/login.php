<?php
/**
 * login.php — authenticate against GLPI with the user's own credentials.
 *
 * Two-step when the account has 2FA enabled:
 *   1) POST {usuario, password}  → validates against GLPI. If the user has 2FA
 *      on, the GLPI session is parked server-side and {twofa:"required"} comes
 *      back — nothing sensitive reaches the browser until the code is verified.
 *   2) POST {code[, method]}     → verifies TOTP / email OTP / recovery code and
 *      finalizes the panel session.
 *
 * Brute-force throttle (per IP and per user), and every attempt is logged and
 * forwarded to Wazuh, via the shared rh-auth module.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/rh-auth.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

$sec = cfg()['security'] ?? [];
rh_auth_config([
    'dir'          => dirname(__DIR__) . '/config',
    'app'          => 'dashboard',
    'ip_max'       => (int)($sec['throttle_ip_max'] ?? 10),
    'ip_win'       => (int)($sec['throttle_ip_window'] ?? 900),
    'user_max'     => (int)($sec['throttle_user_max'] ?? 5),
    'user_win'     => (int)($sec['throttle_user_window'] ?? 900),
    'wazuh_syslog' => (bool)($sec['wazuh_syslog'] ?? true),
    'otp_ttl'      => (int)($sec['otp_ttl'] ?? 300),
]);

/** Finalize the panel session from a validated GLPI session token. */
function finalize_login(string $tok, array $s): array
{
    $prof = strtolower($s['glpiactiveprofile']['name'] ?? '');
    session_regenerate_id(true); // new session id on privilege change (anti-fixation)
    unset($_SESSION['p2fa']);
    $_SESSION['glpi_token'] = $tok;
    $_SESSION['glpiID']     = (int)($s['glpiID'] ?? 0);
    $_SESSION['user']       = $s['glpifriendlyname'] ?? $s['glpiname'] ?? '';
    $_SESSION['profile']    = $s['glpiactiveprofile']['name'] ?? '';
    $_SESSION['isAdmin']      = in_array($prof, ['super-admin', 'admin'], true);
    $_SESSION['isSuper']      = (strpos($prof, 'superv') !== false);
    $_SESSION['isSuperAdmin'] = ($prof === 'super-admin');   // solo el perfil Super-Admin (acceso a la config)
    return [
        'ok' => true, 'user' => $_SESSION['user'],
        'isAdmin' => $_SESSION['isAdmin'], 'isSuper' => $_SESSION['isSuper'],
        'isSuperAdmin' => $_SESSION['isSuperAdmin'],
    ];
}

/** Best-effort OTP email (uses PHP mail(); returns false if it can't send). */
function send_otp_email(string $to, string $code): bool
{
    if ($to === '' || !function_exists('mail')) { return false; }
    $from = cfg()['security']['mail_from'] ?? '';
    $app  = cfg()['branding']['app_name'] ?? 'Dashboard';
    $subj = "$app — código de verificación";
    $body = "Tu código de verificación es: $code\n\n"
          . "Vence en pocos minutos. Si no fuiste vos, cambiá tu contraseña de GLPI.";
    $headers = "Content-Type: text/plain; charset=utf-8\r\n";
    if ($from !== '') { $headers .= "From: $from\r\n"; }
    return @mail($to, $subj, $body, $headers);
}

$m = $_SERVER['REQUEST_METHOD'];
if ($m === 'GET') {
    echo json_encode([
        'auth'          => !empty($_SESSION['glpi_token']),
        'user'          => $_SESSION['user'] ?? null,
        'isAdmin'       => $_SESSION['isAdmin'] ?? false,
        'isSuper'       => $_SESSION['isSuper'] ?? false,
        'isSuperAdmin'  => ($_SESSION['isSuperAdmin'] ?? false) || (strtolower($_SESSION['profile'] ?? '') === 'super-admin'),
        'twofa_pending' => !empty($_SESSION['p2fa']),
    ]);
    exit;
}
if ($m !== 'POST') { http_response_code(405); echo '{}'; exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Step 2: a parked 2FA session is waiting for its code ─────────────────────
if (!empty($_SESSION['p2fa']) && (isset($in['code']) || !empty($in['send_email']))) {
    $p = $_SESSION['p2fa'];
    if (time() > ($p['exp'] ?? 0)) {
        unset($_SESSION['p2fa']);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'expired']);
        exit;
    }
    $id = $p['id'];

    // Ask for an email OTP (backup factor).
    if (!empty($in['send_email'])) {
        if (empty(cfg()['security']['email_otp']) || empty($p['email'])) {
            echo json_encode(['ok' => false, 'error' => 'mail_unavailable']); exit;
        }
        $code = rh_email_otp_issue($id);
        $sent = send_otp_email($p['email'], $code);
        rh_auth_log('otp_sent', $p['user'], ['sent' => $sent]);
        echo json_encode($sent ? ['ok' => true, 'sent' => true, 'to' => $p['email_masked']]
                               : ['ok' => false, 'error' => 'mail_unavailable']);
        exit;
    }

    // Throttle also protects the code step.
    if (($wait = rh_throttle_blocked($p['user'])) > 0) {
        rh_auth_log('throttled', $p['user'], ['stage' => '2fa']);
        header('Retry-After: ' . $wait);
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'throttled']);
        exit;
    }

    $code   = (string)($in['code'] ?? '');
    $method = $in['method'] ?? 'auto';
    $rec    = rh_2fa_get($id) ?: [];
    $ok = false; $used = '';
    if (($method === 'auto' || $method === 'totp') && !empty($rec['secret']) && rh_totp_verify($rec['secret'], $code)) {
        $ok = true; $used = 'totp';
    } elseif (($method === 'auto' || $method === 'email') && rh_email_otp_verify($id, $code)) {
        $ok = true; $used = 'email';
    } elseif ($method === 'auto' || $method === 'recovery') {
        // Recovery codes: one-time, stored hashed.
        foreach (($rec['recovery'] ?? []) as $k => $h) {
            if (password_verify(strtoupper(trim($code)), $h)) {
                unset($rec['recovery'][$k]);
                rh_2fa_put($id, $rec);
                $ok = true; $used = 'recovery';
                break;
            }
        }
    }

    if ($ok) {
        rh_throttle_clear($p['user']);
        rh_auth_log('2fa_ok', $p['user'], ['method' => $used]);
        echo json_encode(finalize_login($p['tok'], $p['s']));
        exit;
    }
    rh_throttle_fail($p['user']);
    rh_auth_log('2fa_fail', $p['user']);
    http_response_code(401);
    echo json_encode(['ok' => false, 'twofa' => 'required', 'error' => 'bad_code']);
    exit;
}

// ── Step 1: username + password ──────────────────────────────────────────────
$u = trim($in['usuario'] ?? '');
$p = (string)($in['password'] ?? '');
if ($u === '' || $p === '') { http_response_code(400); echo json_encode(['ok' => false]); exit; }

if (glpi_app_token() === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'App-Token not configured — open /setup.php']);
    exit;
}

if (($wait = rh_throttle_blocked($u)) > 0) {
    rh_auth_log('throttled', $u);
    header('Retry-After: ' . $wait);
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'throttled']);
    exit;
}

// Credentials travel in a Basic header, never in the URL (query params end up
// in web-server access logs). See tokens_in_query note in /setup.php.
if (!empty(cfg()['glpi']['tokens_in_query'])) {
    list($c, $d) = glpi_fetch('/initSession', ['login' => $u, 'password' => $p]);
} else {
    list($c, $d) = glpi_fetch('/initSession', [], null,
        ['Authorization: Basic ' . base64_encode($u . ':' . $p)]);
}
$tok = $d['session_token'] ?? null;
if (!$tok) {
    rh_throttle_fail($u);
    rh_auth_log('login_fail', $u);
    http_response_code(401); echo json_encode(['ok' => false]); exit;
}

list($c2, $full) = glpi_fetch('/getFullSession', [], $tok);
$s   = $full['session'] ?? $full ?? [];
$gid = (int)($s['glpiID'] ?? 0);
$id  = 'glpi:' . $gid;
$uname = $s['glpifriendlyname'] ?? $s['glpiname'] ?? $u;

// Password is valid. If the account has 2FA enabled, park the session and ask
// for the code — nothing is written to the panel session until it's verified.
if (rh_2fa_enabled($id)) {
    // Fetch the user's email for the backup OTP (best effort).
    $email = '';
    if (!empty(cfg()['security']['email_otp'])) {
        list($ec, $ed) = glpi_fetch('/User/' . $gid, [], $tok);
        $email = $ed['_useremails'][0]['email'] ?? ($ed['email'] ?? '');
        if (is_array($email)) { $email = $email[0] ?? ''; }
    }
    $mask = $email !== '' ? preg_replace('/^(.).*(.)(@.*)$/', '$1***$2$3', $email) : '';
    $_SESSION['p2fa'] = [
        'id' => $id, 'tok' => $tok, 's' => $s, 'user' => $uname,
        'email' => $email, 'email_masked' => $mask, 'exp' => time() + 300,
    ];
    rh_throttle_clear($u);
    rh_auth_log('2fa_required', $uname);
    echo json_encode([
        'ok' => false, 'twofa' => 'required',
        'has_email' => ($email !== '' && !empty(cfg()['security']['email_otp'])),
        'email_masked' => $mask,
    ]);
    exit;
}

// No 2FA on this account → log in, but tell the UI whether enrollment is needed.
rh_throttle_clear($u);
rh_auth_log('login_ok', $uname, ['twofa' => false]);
$out = finalize_login($tok, $s);
$out['twofa_setup'] = !rh_2fa_enabled($id);          // never enrolled
$out['require_2fa'] = (bool)($sec['require_2fa'] ?? false);
echo json_encode($out);
