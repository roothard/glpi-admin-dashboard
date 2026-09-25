<?php
/**
 * rh-auth.php — módulo de endurecimiento de login, reutilizable.
 *
 * Sin dependencias externas (composer no requerido). Provee:
 *   - Throttle contra fuerza bruta, por IP y por usuario.
 *   - Registro estructurado (JSON-lines) de intentos + reenvío a Wazuh (syslog).
 *   - TOTP (RFC 6238) para doble factor con apps autenticadoras.
 *   - OTP por email como segundo factor de respaldo.
 *   - Un store de 2FA por usuario (archivo JSON, chmod 600, fuera del docroot).
 *
 * Cada app lo configura una vez con rh_auth_config([...]) y usa las funciones
 * rh_* que necesite. Pensado para el dashboard GLPI, el panel GPS y Empleo.
 *
 * @license MIT
 */

/**
 * Configuración del módulo. Llamar una vez al inicio de cada app.
 * Claves:
 *   dir            directorio de estado (throttle, log, 2fa). Fuera del docroot.
 *   app            nombre de la app para el log (dashboard|gps|empleo|...).
 *   ip_max/ip_win  intentos por IP y ventana en segundos (def 10 / 900).
 *   user_max/user_win  intentos por usuario y ventana (def 5 / 900).
 *   wazuh_syslog   bool: emitir cada evento también por syslog (def true).
 *   otp_ttl        segundos de validez del OTP por email (def 300).
 */
function rh_auth_config(array $set = []): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'dir'          => sys_get_temp_dir() . '/rh-auth',
            'app'          => 'app',
            'ip_max'       => 10, 'ip_win'   => 900,
            'user_max'     => 5,  'user_win' => 900,
            'wazuh_syslog' => true,
            'otp_ttl'      => 300,
        ];
    }
    if ($set) { $cfg = array_merge($cfg, $set); }
    return $cfg;
}

/** Ruta a un archivo de estado dentro del dir configurado (lo crea si falta). */
function rh_auth_path(string $name): string
{
    $dir = rh_auth_config()['dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    return rtrim($dir, '/\\') . '/' . $name;
}

/** IP real del cliente (respeta Cloudflare, luego proxies, luego REMOTE_ADDR). */
function rh_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if ($ip !== '') { return $ip; }
        }
    }
    return '?';
}

// ─────────────────────────────────────────────────────────────────────────────
// Throttle contra fuerza bruta (por IP y por usuario, ventana deslizante)
// ─────────────────────────────────────────────────────────────────────────────

/** Lee el mapa de contadores, purgando los vencidos según la ventana máxima. */
function rh_throttle_read(): array
{
    $f   = rh_auth_path('login-throttle.json');
    $now = time();
    $win = max(rh_auth_config()['ip_win'], rh_auth_config()['user_win']);
    $all = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    return array_filter($all, fn($v) => ($now - ($v['t'] ?? 0)) < $win);
}

function rh_throttle_write(array $all): void
{
    @file_put_contents(rh_auth_path('login-throttle.json'), json_encode($all), LOCK_EX);
}

/**
 * ¿Está bloqueado este intento? Devuelve 0 si puede seguir, o los segundos que
 * faltan para desbloquear si superó el límite (por IP o por usuario).
 */
function rh_throttle_blocked(string $user): int
{
    $c   = rh_auth_config();
    $now = time();
    $all = rh_throttle_read();
    $checks = [
        ['k' => 'ip:'   . rh_client_ip(),         'max' => $c['ip_max'],   'win' => $c['ip_win']],
        ['k' => 'user:' . strtolower(trim($user)), 'max' => $c['user_max'], 'win' => $c['user_win']],
    ];
    $wait = 0;
    foreach ($checks as $ck) {
        $e = $all[$ck['k']] ?? null;
        if ($e && ($e['n'] ?? 0) >= $ck['max'] && ($now - ($e['t'] ?? 0)) < $ck['win']) {
            $wait = max($wait, $ck['win'] - ($now - $e['t']));
        }
    }
    return $wait;
}

/** Registra un intento fallido (incrementa IP y usuario). */
function rh_throttle_fail(string $user): void
{
    $now = time();
    $all = rh_throttle_read();
    foreach (['ip:' . rh_client_ip(), 'user:' . strtolower(trim($user))] as $k) {
        $all[$k] = ['n' => (($all[$k]['n'] ?? 0) + 1), 't' => $now];
    }
    rh_throttle_write($all);
}

/** Limpia los contadores tras un login exitoso. */
function rh_throttle_clear(string $user): void
{
    $all = rh_throttle_read();
    unset($all['ip:' . rh_client_ip()], $all['user:' . strtolower(trim($user))]);
    rh_throttle_write($all);
}

// ─────────────────────────────────────────────────────────────────────────────
// Registro de intentos → archivo JSON-lines + Wazuh (syslog)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Registra un evento de autenticación. Escribe una línea JSON en el archivo de
 * auditoría (fuente canónica, que el agente Wazuh sigue) y además la emite por
 * syslog con el tag "rhauth" para que Wazuh la decodifique y alerte.
 *
 * $event: login_ok | login_fail | throttled | 2fa_required | 2fa_ok | 2fa_fail |
 *         2fa_enrolled | 2fa_disabled | otp_sent
 */
function rh_auth_log(string $event, string $user = '', array $extra = []): void
{
    $c   = rh_auth_config();
    $rec = array_merge([
        'ts'    => date('c'),
        'app'   => $c['app'],
        'event' => $event,
        'user'  => $user,
        'ip'    => rh_client_ip(),
        'ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    ], $extra);
    $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents(rh_auth_path('auth-audit.log'), $line . "\n", FILE_APPEND | LOCK_EX);

    if ($c['wazuh_syslog']) {
        // Prefijo "rhauth:" → el decoder de Wazuh lo reconoce y parsea el JSON.
        openlog('rhauth', LOG_PID | LOG_ODELAY, LOG_AUTHPRIV);
        // login_fail / throttled / 2fa_fail son sospechosos → prioridad WARNING.
        $sev = in_array($event, ['login_fail', 'throttled', '2fa_fail'], true) ? LOG_WARNING : LOG_INFO;
        syslog($sev, 'rhauth: ' . $line);
        closelog();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TOTP (RFC 6238) — segundo factor con app autenticadora
// ─────────────────────────────────────────────────────────────────────────────

/** Decodifica Base32 (RFC 4648, sin padding) a binario. */
function rh_base32_decode(string $b32): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $ch) {
        $bits .= str_pad(decbin(strpos($map, $ch)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) { $out .= chr(bindec($byte)); }
    }
    return $out;
}

/** Codifica binario a Base32 (para mostrar/guardar el secreto). */
function rh_base32_encode(string $bin): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $map[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/** Genera un secreto TOTP nuevo (Base32, 160 bits por defecto). */
function rh_totp_secret(int $bytes = 20): string
{
    return rh_base32_encode(random_bytes($bytes));
}

/** Calcula el código TOTP para un contador de tiempo dado (RFC 6238/4226). */
function rh_totp_at(string $secretB32, int $timestamp, int $period = 30, int $digits = 6): string
{
    $key     = rh_base32_decode($secretB32);
    $counter = pack('N*', 0) . pack('N*', intdiv($timestamp, $period)); // 64-bit big-endian
    $hash    = hash_hmac('sha1', $counter, $key, true);
    $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
    $bin     = ((ord($hash[$offset]) & 0x7F) << 24)
             | ((ord($hash[$offset + 1]) & 0xFF) << 16)
             | ((ord($hash[$offset + 2]) & 0xFF) << 8)
             | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código TOTP con tolerancia de ±$window pasos (reloj desfasado).
 * Comparación en tiempo constante para no filtrar por timing.
 */
function rh_totp_verify(string $secretB32, string $code, int $window = 1, int $period = 30): bool
{
    $code = preg_replace('/\D/', '', $code);
    if ($code === '') { return false; }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(rh_totp_at($secretB32, $now + $i * $period, $period), $code)) {
            return true;
        }
    }
    return false;
}

/** URI otpauth:// para el QR de enrolamiento. */
function rh_totp_uri(string $secretB32, string $label, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ─────────────────────────────────────────────────────────────────────────────
// Store de 2FA por usuario (config/auth-2fa.json, mapa id → registro)
// ─────────────────────────────────────────────────────────────────────────────

function rh_2fa_all(): array
{
    $f = rh_auth_path('auth-2fa.json');
    return is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
}

function rh_2fa_get(string $id): ?array
{
    return rh_2fa_all()[$id] ?? null;
}

function rh_2fa_put(string $id, array $rec): void
{
    $all = rh_2fa_all();
    $all[$id] = $rec;
    $f = rh_auth_path('auth-2fa.json');
    @file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($f, 0600);
}

function rh_2fa_delete(string $id): void
{
    $all = rh_2fa_all();
    unset($all[$id]);
    @file_put_contents(rh_auth_path('auth-2fa.json'), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** ¿El usuario tiene el 2FA activado (enrolado y confirmado)? */
function rh_2fa_enabled(string $id): bool
{
    $r = rh_2fa_get($id);
    return !empty($r['enabled']) && !empty($r['secret']);
}

/** Genera códigos de recuperación de un solo uso (se muestran una vez, se guardan hasheados). */
function rh_2fa_recovery_codes(int $n = 8): array
{
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars
    }
    return $codes;
}

// ─────────────────────────────────────────────────────────────────────────────
// OTP por email — segundo factor de respaldo
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Genera un OTP de email de 6 dígitos, lo guarda hasheado con vencimiento en el
 * registro 2FA del usuario, y devuelve el código en claro para enviarlo por mail.
 */
function rh_email_otp_issue(string $id): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $rec  = rh_2fa_get($id) ?? [];
    $rec['email_otp'] = [
        'hash'    => password_hash($code, PASSWORD_BCRYPT),
        'expires' => time() + rh_auth_config()['otp_ttl'],
        'tries'   => 0,
    ];
    rh_2fa_put($id, $rec);
    return $code;
}

/** Verifica el OTP de email (un solo uso, con vencimiento y tope de intentos). */
function rh_email_otp_verify(string $id, string $code): bool
{
    $rec = rh_2fa_get($id);
    $otp = $rec['email_otp'] ?? null;
    if (!$otp || time() > ($otp['expires'] ?? 0) || ($otp['tries'] ?? 0) >= 5) {
        return false;
    }
    $rec['email_otp']['tries'] = ($otp['tries'] ?? 0) + 1;
    rh_2fa_put($id, $rec);
    if (password_verify(preg_replace('/\D/', '', $code), $otp['hash'])) {
        unset($rec['email_otp']);          // consumido
        rh_2fa_put($id, $rec);
        return true;
    }
    return false;
}
