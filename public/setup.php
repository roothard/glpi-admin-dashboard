<?php
/**
 * setup.php — configuration panel / web installer. Edit everything the app
 * needs (GLPI connection, project selection, branding, modules) and it writes
 * config/settings.json (above the docroot). Open on first run; admin-only after.
 * Secrets are write-only (masked; leave blank to keep the stored value).
 * UI is available in ES/EN/FR/DE/PT with a light/dark theme.
 * @license MIT
 */
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/src/Settings.php';
require_once dirname(__DIR__) . '/src/GlpiClient.php';

$cfg = Settings::load();
$configured = Settings::isConfigured($cfg);

panel_session();
$isAdmin = !empty($_SESSION['isAdmin']);
if ($configured && !$isAdmin) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup locked</title>'
       . '<div style="font:15px system-ui;max-width:520px;margin:12vh auto;text-align:center;color:#334">'
       . '<h2>🔒 Configuration is locked</h2><p>Sign in as a GLPI administrator from the dashboard, then reopen setup.</p>'
       . '<p><a href="index.html">← Back to the dashboard</a></p></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $in['action'] ?? 'save';
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'test') {
        $url  = trim($in['glpi_url'] ?? $cfg['glpi']['url']);
        $app  = trim($in['glpi_app_token'] ?? '') ?: $cfg['glpi']['app_token'];
        $user = trim($in['glpi_user_token'] ?? '') ?: $cfg['glpi']['user_token'];
        try {
            $c = new GlpiClient([
                'url' => $url, 'app_token' => $app, 'user_token' => $user,
                'tokens_in_query' => !empty($in['glpi_tokens_in_query']),
                'profile_id' => (int)($in['glpi_profile_id'] ?? $cfg['glpi']['profile_id']),
                'insecure' => !empty($in['glpi_insecure']),
            ]);
            $c->initSession();
            $n = count($c->getAll('ProjectState'));
            $c->killSession();
            echo json_encode(['ok' => true, 'n' => $n]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    $keepSecret = fn($new, $old) => (trim((string)$new) === '') ? $old : trim((string)$new);
    $csv = fn($s) => array_values(array_filter(array_map('trim', explode(',', (string)$s))));

    $cfg['glpi']['url']             = trim($in['glpi_url'] ?? $cfg['glpi']['url']);
    $cfg['glpi']['app_token']       = $keepSecret($in['glpi_app_token'] ?? '', $cfg['glpi']['app_token']);
    $cfg['glpi']['user_token']      = $keepSecret($in['glpi_user_token'] ?? '', $cfg['glpi']['user_token']);
    $cfg['glpi']['tokens_in_query'] = !empty($in['glpi_tokens_in_query']);
    $cfg['glpi']['profile_id']      = (int)($in['glpi_profile_id'] ?? 0);
    $cfg['glpi']['insecure']        = !empty($in['glpi_insecure']);
    $cfg['glpi']['resolve_host']    = trim($in['glpi_resolve_host'] ?? '');
    $cfg['glpi']['resolve_ip']      = trim($in['glpi_resolve_ip'] ?? '');

    $cfg['projects']['project_type']      = trim($in['project_type'] ?? '');
    $cfg['projects']['group_by']          = in_array(($in['group_by'] ?? 'parent'), ['parent', 'entity', 'type'], true) ? $in['group_by'] : 'parent';
    $cfg['projects']['include_only_leaf'] = !empty($in['include_only_leaf']);
    $cfg['projects']['area_strip_prefix'] = trim($in['area_strip_prefix'] ?? '');
    $cfg['projects']['state_inprogress']  = $csv($in['state_inprogress'] ?? '');
    $cfg['projects']['state_done']        = $csv($in['state_done'] ?? '');
    $cfg['projects']['state_planned']     = $csv($in['state_planned'] ?? '');

    $cfg['branding']['app_name']     = trim($in['app_name'] ?? '') ?: 'Projects Dashboard';
    $cfg['branding']['subtitle']     = trim($in['subtitle'] ?? '');
    $cfg['branding']['accent']       = preg_match('/^#[0-9a-fA-F]{6}$/', $in['accent'] ?? '') ? $in['accent'] : '#405cde';
    $cfg['branding']['logo_url']     = trim($in['logo_url'] ?? '');
    $cfg['branding']['default_lang'] = in_array(($in['default_lang'] ?? 'es'), ['es', 'en', 'fr', 'de', 'pt'], true) ? $in['default_lang'] : 'es';

    $cfg['modules']['gps']['enabled']    = !empty($in['gps_enabled']);
    $cfg['modules']['gps']['label']      = trim($in['gps_label'] ?? '') ?: 'GPS Check-ins';
    $cfg['modules']['gps']['app_url']    = trim($in['gps_app_url'] ?? '');
    $cfg['modules']['gps']['db']['host'] = trim($in['db_host'] ?? '');
    $cfg['modules']['gps']['db']['name'] = trim($in['db_name'] ?? '');
    $cfg['modules']['gps']['db']['user'] = trim($in['db_user'] ?? '');
    $cfg['modules']['gps']['db']['pass'] = $keepSecret($in['db_pass'] ?? '', $cfg['modules']['gps']['db']['pass']);

    $cfg['timezone'] = trim($in['timezone'] ?? 'UTC') ?: 'UTC';

    $ok = Settings::save($cfg);
    $gen = null;
    if ($ok && Settings::isConfigured($cfg)) {
        try {
            require_once dirname(__DIR__) . '/src/DashboardGenerator.php';
            $flat = Settings::flat($cfg);
            if (!empty($flat['timezone'])) { @date_default_timezone_set($flat['timezone']); }
            $client = new GlpiClient($flat);
            $g2 = new DashboardGenerator($client, $flat);
            [$np, $nkb] = $g2->run($flat['output']);
            $gen = ['projects' => $np, 'kb' => $nkb];
        } catch (\Throwable $e) {
            $gen = ['error' => $e->getMessage()];
        }
    }
    echo json_encode(['ok' => $ok, 'configured' => Settings::isConfigured($cfg), 'generated' => $gen]);
    exit;
}

// ---- render --------------------------------------------------------------
$b = $cfg['branding'];
$g = $cfg['glpi'];
$p = $cfg['projects'];
$gps = $cfg['modules']['gps'];
$mask = fn($v) => $v !== '' ? '••••••••' : '';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$deflang = in_array($b['default_lang'], ['es','en','fr','de','pt'], true) ? $b['default_lang'] : 'es';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<script>(function(){var s=localStorage.getItem('pd-theme');if(s)document.documentElement.setAttribute('data-theme',s);})();</script>
<title>Setup · <?= $h($b['app_name']) ?></title>
<style>
:root{--bg:#f3f6fc;--card:#fff;--bd:#e3e8f4;--bd2:#d3dbee;--tx:#141b30;--mu:#57627e;--ac:<?= $h($b['accent']) ?>}
@media(prefers-color-scheme:dark){:root:not([data-theme]){--bg:#0a0f1e;--card:#121a30;--bd:#24314f;--bd2:#31406a;--tx:#e9eef9;--mu:#96a2c1}}
:root[data-theme=dark]{--bg:#0a0f1e;--card:#121a30;--bd:#24314f;--bd2:#31406a;--tx:#e9eef9;--mu:#96a2c1}
:root[data-theme=light]{--bg:#f3f6fc;--card:#fff;--bd:#e3e8f4;--bd2:#d3dbee;--tx:#141b30;--mu:#57627e}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:24px 20px 90px}
.topbar{display:flex;align-items:center;gap:8px;margin:0 0 18px}
.topbar h1{font-size:22px;margin:0}.topbar .sp{flex:1}
.tbtn{width:36px;height:36px;display:grid;place-items:center;border:1px solid var(--bd2);border-radius:9px;background:var(--card);cursor:pointer;font-size:15px}
.tbtn:hover{border-color:var(--ac)}
#lang{font:600 12px system-ui;color:var(--tx);background:var(--card);border:1px solid var(--bd2);border-radius:9px;padding:9px}
.sub{color:var(--mu);margin:0 0 22px}
input[type=text],input[type=url],input[type=number],input[type=password],select{width:100%;font:500 14px inherit;color:var(--tx);background:var(--bg);border:1px solid var(--bd2);border-radius:9px;padding:9px 11px;outline:none}
input:focus,select:focus{border-color:var(--ac)}
input[type=color]{width:48px;height:36px;padding:2px}
.step{border:1px solid var(--bd);border-radius:14px;margin:0 0 16px;background:var(--card)}
.step.req{border:2px solid var(--ac)}
.step>summary,.step>.head{cursor:pointer;padding:15px 20px;font-weight:700;font-size:15px;list-style:none;display:flex;align-items:center;gap:9px}
.step>summary::-webkit-details-marker{display:none}
details.step>summary::before{content:"▸";color:var(--mu);font-size:13px;transition:transform .15s}
details.step[open]>summary::before{transform:rotate(90deg)}
.step .opt{color:var(--mu);font-weight:500;font-size:12.5px}
.step .body{padding:2px 20px 18px}
.field{margin:0 0 15px}
.field>label{display:block;font-weight:600;font-size:13.5px;margin:0 0 5px}
.req-star{color:var(--ac)}
.help{color:var(--mu);font-size:12.5px;line-height:1.5;margin:5px 0 0}
.help code{background:var(--bg);padding:1px 5px;border-radius:5px;font-size:.95em}
.help b{color:var(--tx)}
.chk2{display:flex;align-items:flex-start;gap:9px}
.chk2 input{width:auto;margin-top:3px}
.actions{position:sticky;bottom:0;background:var(--bg);padding:14px 0;display:flex;gap:10px;align-items:center;border-top:1px solid var(--bd)}
button.act{font:700 14px inherit;border:none;border-radius:10px;padding:11px 18px;cursor:pointer}
.save{background:var(--ac);color:#fff}.test{background:var(--card);color:var(--tx);border:1px solid var(--bd2)}
#msg{font-size:13.5px;font-weight:600}
</style></head>
<body><div class="wrap">
<div class="topbar">
  <h1 data-i="title">Instalación</h1><span class="sp"></span>
  <button class="tbtn" id="theme" title="Tema">🌙</button>
  <select id="lang">
    <option value="es">ES</option><option value="en">EN</option><option value="fr">FR</option><option value="de">DE</option><option value="pt">PT</option>
  </select>
</div>
<p class="sub" data-i="intro"></p>
<form id="f">

<div class="step req">
  <div class="head" data-i="s1">1 · Conectar con GLPI</div>
  <div class="body">
    <div class="field">
      <label><span data-i="l_url">URL de GLPI</span> <span class="req-star">*</span></label>
      <input type="url" name="glpi_url" value="<?= $h($g['url']) ?>" placeholder="https://soporte.tuempresa.com">
      <p class="help" data-i="h_url"></p>
    </div>
    <div class="field">
      <label>App-Token <span class="req-star">*</span></label>
      <input type="password" name="glpi_app_token" placeholder="<?= $mask($g['app_token']) ?>" autocomplete="new-password">
      <p class="help" data-i="h_app"></p>
    </div>
    <div class="field">
      <label data-i="l_user">Token de usuario</label>
      <input type="password" name="glpi_user_token" placeholder="<?= $mask($g['user_token']) ?>" autocomplete="new-password">
      <p class="help" data-i="h_user"></p>
    </div>
    <details class="step" style="border:1px dashed var(--bd2);margin:12px 0 0;background:transparent">
      <summary style="font-size:13.5px;font-weight:600"><span data-i="adv">Conexión avanzada</span></summary>
      <div class="body">
        <div class="field"><label data-i="l_prof">ID de perfil</label>
          <input type="number" name="glpi_profile_id" value="<?= (int)$g['profile_id'] ?>" placeholder="0">
          <p class="help" data-i="h_prof"></p></div>
        <div class="field chk2"><input type="checkbox" name="glpi_tokens_in_query" id="tq" <?= $g['tokens_in_query'] ? 'checked' : '' ?>>
          <label for="tq" style="margin:0"><span data-i="l_tq">Enviar tokens por query</span>
            <span class="help" style="display:block" data-i="h_tq"></span></label></div>
        <div class="field chk2"><input type="checkbox" name="glpi_insecure" id="ins" <?= $g['insecure'] ? 'checked' : '' ?>>
          <label for="ins" style="margin:0"><span data-i="l_ins">Permitir TLS autofirmado</span>
            <span class="help" style="display:block" data-i="h_ins"></span></label></div>
        <div class="field"><label data-i="l_res">Resolver host → IP (mismo servidor)</label>
          <input type="text" name="glpi_resolve_host" value="<?= $h($g['resolve_host']) ?>" placeholder="host">
          <input type="text" name="glpi_resolve_ip" value="<?= $h($g['resolve_ip']) ?>" placeholder="127.0.0.1" style="margin-top:8px">
          <p class="help" data-i="h_res"></p></div>
      </div>
    </details>
  </div>
</div>

<details class="step">
  <summary><span data-i="s2">2 · Proyectos</span> <span class="opt" data-i="s2opt">— opcional</span></summary>
  <div class="body">
    <div class="field"><label data-i="l_ptype">Tipo de proyecto</label>
      <input type="text" name="project_type" value="<?= $h($p['project_type']) ?>" placeholder="">
      <p class="help" data-i="h_ptype"></p></div>
    <div class="field"><label data-i="l_group">Agrupar zonas por</label>
      <select name="group_by"><option value="parent"<?= $p['group_by']==='parent'?' selected':'' ?> data-i="g_parent">Proyecto padre</option><option value="entity"<?= $p['group_by']==='entity'?' selected':'' ?> data-i="g_entity">Entidad</option><option value="type"<?= $p['group_by']==='type'?' selected':'' ?> data-i="g_type">Tipo</option></select>
      <p class="help" data-i="h_group"></p></div>
    <div class="field chk2"><input type="checkbox" name="include_only_leaf" id="leaf" <?= $p['include_only_leaf'] ? 'checked' : '' ?>>
      <label for="leaf" style="margin:0" data-i="l_leaf">Solo proyectos con proyecto padre</label></div>
    <div class="field"><label data-i="l_states">Estados → color</label>
      <input type="text" name="state_inprogress" value="<?= $h(implode(', ', $p['state_inprogress'])) ?>" placeholder="">
      <input type="text" name="state_done" value="<?= $h(implode(', ', $p['state_done'])) ?>" placeholder="" style="margin-top:8px">
      <input type="text" name="state_planned" value="<?= $h(implode(', ', $p['state_planned'])) ?>" placeholder="" style="margin-top:8px">
      <p class="help" data-i="h_states"></p></div>
    <div class="field"><label data-i="l_prefix">Quitar prefijo de zona</label>
      <input type="text" name="area_strip_prefix" value="<?= $h($p['area_strip_prefix']) ?>" placeholder="Portfolio · ">
      <p class="help" data-i="h_prefix"></p></div>
  </div>
</details>

<details class="step">
  <summary><span data-i="s3">3 · Apariencia</span> <span class="opt" data-i="s2opt2">— opcional</span></summary>
  <div class="body">
    <div class="field"><label data-i="l_name">Nombre de la app</label><input type="text" name="app_name" value="<?= $h($b['app_name']) ?>" placeholder="Projects Dashboard"><p class="help" data-i="h_name"></p></div>
    <div class="field"><label data-i="l_sub">Subtítulo</label><input type="text" name="subtitle" value="<?= $h($b['subtitle']) ?>"></div>
    <div class="field"><label data-i="l_accent">Color principal</label><input type="color" name="accent" value="<?= $h($b['accent']) ?>"></div>
    <div class="field"><label data-i="l_logo">URL del logo</label><input type="url" name="logo_url" value="<?= $h($b['logo_url']) ?>" placeholder="opcional"><p class="help" data-i="h_logo"></p></div>
    <div class="field"><label data-i="l_lang">Idioma por defecto</label><select name="default_lang"><?php foreach (['es'=>'Español','en'=>'English','fr'=>'Français','de'=>'Deutsch','pt'=>'Português'] as $k=>$v) echo '<option value="'.$k.'" '.($b['default_lang']===$k?'selected':'').">$v</option>"; ?></select></div>
  </div>
</details>

<details class="step">
  <summary><span data-i="s4">4 · Módulo Fichadas GPS</span> <span class="opt" data-i="s2opt3">— opcional</span></summary>
  <div class="body">
    <div class="field chk2"><input type="checkbox" name="gps_enabled" id="gps" <?= $gps['enabled'] ? 'checked' : '' ?>><label for="gps" style="margin:0" data-i="l_gpsen">Activar el módulo</label></div>
    <div class="field"><label data-i="l_gpslabel">Etiqueta de la pestaña</label><input type="text" name="gps_label" value="<?= $h($gps['label']) ?>" placeholder="GPS"></div>
    <div class="field"><label data-i="l_gpsurl">Link a la web de la app</label><input type="url" name="gps_app_url" value="<?= $h($gps['app_url']) ?>" placeholder="https://gps.tuempresa.com"><p class="help" data-i="h_gpsurl"></p></div>
    <p class="help" data-i="h_gpsdb" style="margin:2px 0 10px"></p>
    <div class="field"><label data-i="l_dbhost">Host de la DB</label><input type="text" name="db_host" value="<?= $h($gps['db']['host']) ?>" placeholder="127.0.0.1"></div>
    <div class="field"><label data-i="l_dbname">Nombre de la DB</label><input type="text" name="db_name" value="<?= $h($gps['db']['name']) ?>" placeholder="glpi"></div>
    <div class="field"><label data-i="l_dbuser">Usuario de la DB</label><input type="text" name="db_user" value="<?= $h($gps['db']['user']) ?>"></div>
    <div class="field"><label data-i="l_dbpass">Contraseña de la DB</label><input type="password" name="db_pass" placeholder="<?= $mask($gps['db']['pass']) ?>" autocomplete="new-password"></div>
  </div>
</details>

<details class="step">
  <summary><span data-i="adv2">Avanzado</span> <span class="opt" data-i="s2opt4">— opcional</span></summary>
  <div class="body">
    <div class="field"><label data-i="l_tz">Zona horaria</label><input type="text" name="timezone" value="<?= $h($cfg['timezone']) ?>" placeholder="UTC"><p class="help" data-i="h_tz"></p></div>
  </div>
</details>

<div class="actions">
  <button type="button" class="act test" id="test" data-i="b_test">Probar conexión</button>
  <button type="submit" class="act save" id="save" data-i="b_save">Guardar e instalar</button>
  <span id="msg"></span>
</div>
</form>
</div>
<script>
const DEF=<?= json_encode($deflang) ?>;
const I18N={
 es:{title:'Instalación',intro:'Solo necesitás la <b>URL de tu GLPI</b> y <b>dos tokens</b>. Lo demás es opcional y ya tiene valores por defecto. Los campos de contraseña son de solo-escritura: dejalos vacíos para conservar lo guardado.',
  s1:'1 · Conectar con GLPI',l_url:'URL de GLPI',h_url:'La dirección donde entrás a tu GLPI (sin <code>/apirest.php</code> — se agrega solo).',
  h_app:'En GLPI: <b>Configuración → General → pestaña «API»</b>. Activá <b>«Habilitar la API REST»</b>, después <b>«Agregar un cliente API»</b> y copiá el <b>«Token de aplicación (app_token)»</b>.',
  l_user:'Token de usuario',h_user:'Lo usa la <b>sincronización automática</b> para leer los proyectos. En GLPI: arriba a la derecha <b>tu nombre → Preferencias → «Claves de acceso remoto»</b> → generá un <b>«Token API»</b>. Usá un usuario con permiso de lectura de Proyectos.',
  adv:'Conexión avanzada (normalmente no hace falta tocar)',l_prof:'ID de perfil',h_prof:'Dejá <code>0</code> salvo que el usuario del token no vea los proyectos con su perfil por defecto. Es el ID del perfil a forzar (ej. Super-Admin suele ser <code>4</code>).',
  l_tq:'Enviar tokens por query',h_tq:'Activalo si tu GLPI está detrás de <b>Cloudflare</b> (borra el header Authorization).',l_ins:'Permitir TLS autofirmado',h_ins:'Solo si tu GLPI usa un certificado autofirmado.',
  l_res:'Resolver host → IP (mismo servidor)',h_res:'Solo si el tablero corre en el <b>mismo servidor</b> que GLPI. Normalmente dejá ambos vacíos.',
  s2:'2 · Proyectos',s2opt:'— opcional, ya funciona por defecto',l_ptype:'Tipo de proyecto',h_ptype:'Mostrar solo proyectos de este <b>«Tipo»</b> de GLPI. Vacío = todos.',
  l_group:'Agrupar zonas por',h_group:'Cómo se agrupan las <b>zonas</b> del tablero.',g_parent:'Proyecto padre',g_entity:'Entidad',g_type:'Tipo',
  l_leaf:'Solo proyectos que tengan un proyecto padre',l_states:'Estados → color',h_states:'Palabras clave (separadas por coma) que mapean el <b>nombre</b> de tus estados de GLPI a un color: en proceso (azul), terminado (verde), planificado (naranja). Funciona en cualquier idioma.',
  l_prefix:'Quitar prefijo de zona',h_prefix:'Si tus proyectos padre se llaman «Portfolio · Infra», poné <code>Portfolio · </code> para mostrar solo «Infra».',
  s3:'3 · Apariencia',l_name:'Nombre de la app',h_name:'Aparece en el encabezado y en el login.',l_sub:'Subtítulo',l_accent:'Color principal',l_logo:'URL del logo',h_logo:'Un PNG/SVG accesible por URL. Reemplaza el ícono por defecto.',l_lang:'Idioma por defecto',
  s4:'4 · Módulo Fichadas GPS',l_gpsen:'Activar el módulo de fichadas',l_gpslabel:'Etiqueta de la pestaña',l_gpsurl:'Link a la web de la app',h_gpsurl:'Se muestra como un botón dentro del módulo.',
  h_gpsdb:'<b>Base de datos:</b> este módulo lee tickets «Visita técnica» directo de la base de GLPI. Los datos de conexión están en el archivo <code>config/config_db.php</code> de tu GLPI.',
  l_dbhost:'Host de la DB',l_dbname:'Nombre de la DB',l_dbuser:'Usuario de la DB',l_dbpass:'Contraseña de la DB',adv2:'Avanzado',l_tz:'Zona horaria',h_tz:'Ej: <code>America/Argentina/Buenos_Aires</code>. Para el sello de «Actualizado».',
  b_test:'Probar conexión',b_save:'Guardar e instalar',m_testing:'Probando…',m_saving:'Guardando y sincronizando…',m_ok:'✓ Conectado · {n} estados visibles',m_saved:'✓ Guardado · {n} proyectos',m_savefail:'Error al guardar',m_reqfail:'Falló la solicitud',m_syncfail:'Guardado, pero la sincronización falló: '},
 en:{title:'Setup',intro:'You only need your <b>GLPI URL</b> and <b>two tokens</b>. Everything else is optional and pre-filled. Password fields are write-only: leave blank to keep the stored value.',
  s1:'1 · Connect to GLPI',l_url:'GLPI URL',h_url:'The address where you open GLPI (without <code>/apirest.php</code> — added automatically).',
  h_app:'In GLPI: <b>Setup → General → «API» tab</b>. Enable <b>«Enable REST API»</b>, then <b>«Add API client»</b> and copy its <b>«Application token (app_token)»</b>.',
  l_user:'User token',h_user:'Used by the <b>background sync</b> to read projects. In GLPI: top-right <b>your name → Preferences → «Remote access keys»</b> → generate an <b>«API token»</b>. Use a user with read access to Projects.',
  adv:'Advanced connection (usually not needed)',l_prof:'Profile id',h_prof:'Leave <code>0</code> unless the token user cannot see projects with its default profile. It is the profile id to force (e.g. Super-Admin is often <code>4</code>).',
  l_tq:'Send tokens as query params',h_tq:'Enable if your GLPI is behind <b>Cloudflare</b> (it strips the Authorization header).',l_ins:'Allow self-signed TLS',h_ins:'Only if your GLPI uses a self-signed certificate.',
  l_res:'Resolve host → IP (same server)',h_res:'Only if the dashboard runs on the <b>same server</b> as GLPI. Normally leave both empty.',
  s2:'2 · Projects',s2opt:'— optional, works by default',l_ptype:'Project type',h_ptype:'Only show projects of this GLPI <b>«Type»</b>. Empty = all.',
  l_group:'Group zones by',h_group:'How the board <b>zones</b> are grouped.',g_parent:'Parent project',g_entity:'Entity',g_type:'Type',
  l_leaf:'Only projects that have a parent project',l_states:'States → colour',h_states:'Comma-separated keywords mapping your GLPI state <b>names</b> to a colour: in-progress (blue), done (green), planned (orange). Works in any language.',
  l_prefix:'Strip zone prefix',h_prefix:'If your parent projects are named «Portfolio · Infra», set <code>Portfolio · </code> to show just «Infra».',
  s3:'3 · Appearance',l_name:'App name',h_name:'Shown in the header and on the login screen.',l_sub:'Subtitle',l_accent:'Accent colour',l_logo:'Logo URL',h_logo:'A PNG/SVG reachable by URL. Replaces the default icon.',l_lang:'Default language',
  s4:'4 · GPS Check-ins module',l_gpsen:'Enable the check-ins module',l_gpslabel:'Tab label',l_gpsurl:'App website link',h_gpsurl:'Shown as a button inside the module.',
  h_gpsdb:'<b>Database:</b> this module reads «Visita técnica» tickets straight from the GLPI database. The connection details are in your GLPI <code>config/config_db.php</code> file.',
  l_dbhost:'DB host',l_dbname:'DB name',l_dbuser:'DB user',l_dbpass:'DB password',adv2:'Advanced',l_tz:'Timezone',h_tz:'e.g. <code>America/Argentina/Buenos_Aires</code>. For the «Updated» stamp.',
  b_test:'Test connection',b_save:'Save & install',m_testing:'Testing…',m_saving:'Saving & syncing…',m_ok:'✓ Connected · {n} states visible',m_saved:'✓ Saved · {n} projects',m_savefail:'Save failed',m_reqfail:'Request failed',m_syncfail:'Saved, but sync failed: '},
 fr:{title:'Installation',intro:'Il vous faut seulement l’<b>URL de votre GLPI</b> et <b>deux jetons</b>. Le reste est optionnel et pré-rempli. Les champs mot de passe sont en écriture seule : laissez vide pour conserver la valeur.',
  s1:'1 · Se connecter à GLPI',l_url:'URL de GLPI',h_url:'L’adresse où vous ouvrez GLPI (sans <code>/apirest.php</code> — ajouté automatiquement).',
  h_app:'Dans GLPI : <b>Configuration → Général → onglet «API»</b>. Activez <b>«Activer l’API REST»</b>, puis <b>«Ajouter un client API»</b> et copiez le <b>«Jeton d’application (app_token)»</b>.',
  l_user:'Jeton utilisateur',h_user:'Utilisé par la <b>synchronisation automatique</b>. Dans GLPI : en haut à droite <b>votre nom → Préférences → «Clés d’accès distant»</b> → générez un <b>«Jeton API»</b>. Utilisez un compte ayant accès en lecture aux Projets.',
  adv:'Connexion avancée (généralement inutile)',l_prof:'ID de profil',h_prof:'Laissez <code>0</code> sauf si l’utilisateur du jeton ne voit pas les projets avec son profil par défaut. C’est l’ID du profil à forcer (ex. Super-Admin souvent <code>4</code>).',
  l_tq:'Envoyer les jetons en query',h_tq:'Activez si votre GLPI est derrière <b>Cloudflare</b> (il supprime l’en-tête Authorization).',l_ins:'Autoriser TLS auto-signé',h_ins:'Uniquement si votre GLPI utilise un certificat auto-signé.',
  l_res:'Résoudre hôte → IP (même serveur)',h_res:'Uniquement si le tableau tourne sur le <b>même serveur</b> que GLPI. Normalement laissez vide.',
  s2:'2 · Projets',s2opt:'— optionnel, fonctionne par défaut',l_ptype:'Type de projet',h_ptype:'N’afficher que les projets de ce <b>«Type»</b> GLPI. Vide = tous.',
  l_group:'Grouper les zones par',h_group:'Comment les <b>zones</b> sont regroupées.',g_parent:'Projet parent',g_entity:'Entité',g_type:'Type',
  l_leaf:'Uniquement les projets ayant un projet parent',l_states:'États → couleur',h_states:'Mots-clés (séparés par virgule) associant le <b>nom</b> de vos états GLPI à une couleur : en cours (bleu), terminé (vert), planifié (orange).',
  l_prefix:'Retirer le préfixe de zone',h_prefix:'Si vos projets parents s’appellent «Portfolio · Infra», mettez <code>Portfolio · </code> pour n’afficher que «Infra».',
  s3:'3 · Apparence',l_name:'Nom de l’app',h_name:'Affiché dans l’en-tête et sur la page de connexion.',l_sub:'Sous-titre',l_accent:'Couleur principale',l_logo:'URL du logo',h_logo:'Un PNG/SVG accessible par URL. Remplace l’icône par défaut.',l_lang:'Langue par défaut',
  s4:'4 · Module Pointages GPS',l_gpsen:'Activer le module de pointages',l_gpslabel:'Libellé de l’onglet',l_gpsurl:'Lien du site de l’app',h_gpsurl:'Affiché comme un bouton dans le module.',
  h_gpsdb:'<b>Base de données :</b> ce module lit les tickets «Visita técnica» directement dans la base GLPI. Les identifiants sont dans le fichier <code>config/config_db.php</code> de votre GLPI.',
  l_dbhost:'Hôte de la BD',l_dbname:'Nom de la BD',l_dbuser:'Utilisateur BD',l_dbpass:'Mot de passe BD',adv2:'Avancé',l_tz:'Fuseau horaire',h_tz:'ex : <code>America/Argentina/Buenos_Aires</code>. Pour l’horodatage «Mis à jour».',
  b_test:'Tester la connexion',b_save:'Enregistrer et installer',m_testing:'Test…',m_saving:'Enregistrement et sync…',m_ok:'✓ Connecté · {n} états visibles',m_saved:'✓ Enregistré · {n} projets',m_savefail:'Échec de l’enregistrement',m_reqfail:'Échec de la requête',m_syncfail:'Enregistré, mais la sync a échoué : '},
 de:{title:'Installation',intro:'Sie brauchen nur Ihre <b>GLPI-URL</b> und <b>zwei Tokens</b>. Der Rest ist optional und vorbelegt. Passwortfelder sind schreibgeschützt: leer lassen, um den Wert zu behalten.',
  s1:'1 · Mit GLPI verbinden',l_url:'GLPI-URL',h_url:'Die Adresse, unter der Sie GLPI öffnen (ohne <code>/apirest.php</code> — wird automatisch ergänzt).',
  h_app:'In GLPI: <b>Konfiguration → Allgemein → Reiter «API»</b>. Aktivieren Sie <b>«REST-API aktivieren»</b>, dann <b>«API-Client hinzufügen»</b> und kopieren Sie das <b>«Anwendungs-Token (app_token)»</b>.',
  l_user:'Benutzer-Token',h_user:'Wird von der <b>automatischen Synchronisierung</b> genutzt. In GLPI: oben rechts <b>Ihr Name → Einstellungen → «Fernzugriffsschlüssel»</b> → ein <b>«API-Token»</b> erzeugen. Nutzer mit Lesezugriff auf Projekte verwenden.',
  adv:'Erweiterte Verbindung (meist nicht nötig)',l_prof:'Profil-ID',h_prof:'Lassen Sie <code>0</code>, außer der Token-Nutzer sieht mit dem Standardprofil keine Projekte. Es ist die zu erzwingende Profil-ID (z. B. Super-Admin oft <code>4</code>).',
  l_tq:'Tokens als Query senden',h_tq:'Aktivieren, wenn GLPI hinter <b>Cloudflare</b> läuft (entfernt den Authorization-Header).',l_ins:'Selbstsigniertes TLS erlauben',h_ins:'Nur wenn GLPI ein selbstsigniertes Zertifikat nutzt.',
  l_res:'Host → IP auflösen (gleicher Server)',h_res:'Nur wenn das Dashboard auf dem <b>gleichen Server</b> wie GLPI läuft. Normalerweise leer lassen.',
  s2:'2 · Projekte',s2opt:'— optional, funktioniert standardmäßig',l_ptype:'Projekttyp',h_ptype:'Nur Projekte dieses GLPI-<b>«Typs»</b> zeigen. Leer = alle.',
  l_group:'Zonen gruppieren nach',h_group:'Wie die <b>Zonen</b> gruppiert werden.',g_parent:'Übergeordnetes Projekt',g_entity:'Entität',g_type:'Typ',
  l_leaf:'Nur Projekte mit übergeordnetem Projekt',l_states:'Status → Farbe',h_states:'Schlüsselwörter (kommagetrennt), die den <b>Namen</b> Ihrer GLPI-Status einer Farbe zuordnen: in Arbeit (blau), erledigt (grün), geplant (orange).',
  l_prefix:'Zonen-Präfix entfernen',h_prefix:'Heißen die übergeordneten Projekte «Portfolio · Infra», setzen Sie <code>Portfolio · </code>, um nur «Infra» zu zeigen.',
  s3:'3 · Aussehen',l_name:'App-Name',h_name:'Erscheint im Kopf und im Login.',l_sub:'Untertitel',l_accent:'Akzentfarbe',l_logo:'Logo-URL',h_logo:'Ein per URL erreichbares PNG/SVG. Ersetzt das Standardsymbol.',l_lang:'Standardsprache',
  s4:'4 · GPS-Check-ins-Modul',l_gpsen:'Modul aktivieren',l_gpslabel:'Tab-Beschriftung',l_gpsurl:'Link zur App-Website',h_gpsurl:'Wird als Button im Modul angezeigt.',
  h_gpsdb:'<b>Datenbank:</b> dieses Modul liest «Visita técnica»-Tickets direkt aus der GLPI-Datenbank. Die Zugangsdaten stehen in der Datei <code>config/config_db.php</code> Ihres GLPI.',
  l_dbhost:'DB-Host',l_dbname:'DB-Name',l_dbuser:'DB-Benutzer',l_dbpass:'DB-Passwort',adv2:'Erweitert',l_tz:'Zeitzone',h_tz:'z. B. <code>America/Argentina/Buenos_Aires</code>. Für den «Aktualisiert»-Stempel.',
  b_test:'Verbindung testen',b_save:'Speichern & installieren',m_testing:'Teste…',m_saving:'Speichern & Sync…',m_ok:'✓ Verbunden · {n} Status sichtbar',m_saved:'✓ Gespeichert · {n} Projekte',m_savefail:'Speichern fehlgeschlagen',m_reqfail:'Anfrage fehlgeschlagen',m_syncfail:'Gespeichert, aber Sync fehlgeschlagen: '},
 pt:{title:'Instalação',intro:'Você só precisa da <b>URL do seu GLPI</b> e <b>dois tokens</b>. O resto é opcional e já vem preenchido. Campos de senha são de escrita apenas: deixe em branco para manter o valor.',
  s1:'1 · Conectar ao GLPI',l_url:'URL do GLPI',h_url:'O endereço onde você abre o GLPI (sem <code>/apirest.php</code> — adicionado sozinho).',
  h_app:'No GLPI: <b>Configuração → Geral → aba «API»</b>. Ative <b>«Habilitar a API REST»</b>, depois <b>«Adicionar cliente API»</b> e copie o <b>«Token de aplicação (app_token)»</b>.',
  l_user:'Token de usuário',h_user:'Usado pela <b>sincronização automática</b>. No GLPI: canto superior direito <b>seu nome → Preferências → «Chaves de acesso remoto»</b> → gere um <b>«Token API»</b>. Use um usuário com leitura de Projetos.',
  adv:'Conexão avançada (normalmente não precisa)',l_prof:'ID de perfil',h_prof:'Deixe <code>0</code>, a menos que o usuário do token não veja os projetos com o perfil padrão. É o ID do perfil a forçar (ex. Super-Admin costuma ser <code>4</code>).',
  l_tq:'Enviar tokens por query',h_tq:'Ative se o seu GLPI está atrás do <b>Cloudflare</b> (remove o header Authorization).',l_ins:'Permitir TLS autoassinado',h_ins:'Somente se o GLPI usa certificado autoassinado.',
  l_res:'Resolver host → IP (mesmo servidor)',h_res:'Somente se o painel roda no <b>mesmo servidor</b> que o GLPI. Normalmente deixe vazio.',
  s2:'2 · Projetos',s2opt:'— opcional, já funciona por padrão',l_ptype:'Tipo de projeto',h_ptype:'Mostrar só projetos deste <b>«Tipo»</b> do GLPI. Vazio = todos.',
  l_group:'Agrupar zonas por',h_group:'Como as <b>zonas</b> são agrupadas.',g_parent:'Projeto pai',g_entity:'Entidade',g_type:'Tipo',
  l_leaf:'Só projetos que tenham um projeto pai',l_states:'Estados → cor',h_states:'Palavras-chave (separadas por vírgula) que mapeiam o <b>nome</b> dos seus estados do GLPI a uma cor: em andamento (azul), concluído (verde), planejado (laranja).',
  l_prefix:'Remover prefixo de zona',h_prefix:'Se seus projetos pai se chamam «Portfolio · Infra», ponha <code>Portfolio · </code> para mostrar só «Infra».',
  s3:'3 · Aparência',l_name:'Nome do app',h_name:'Aparece no cabeçalho e no login.',l_sub:'Subtítulo',l_accent:'Cor principal',l_logo:'URL do logo',h_logo:'Um PNG/SVG acessível por URL. Substitui o ícone padrão.',l_lang:'Idioma padrão',
  s4:'4 · Módulo Registros GPS',l_gpsen:'Ativar o módulo',l_gpslabel:'Rótulo da aba',l_gpsurl:'Link do site do app',h_gpsurl:'Exibido como um botão dentro do módulo.',
  h_gpsdb:'<b>Banco de dados:</b> este módulo lê tickets «Visita técnica» direto do banco do GLPI. Os dados de conexão estão no arquivo <code>config/config_db.php</code> do seu GLPI.',
  l_dbhost:'Host do BD',l_dbname:'Nome do BD',l_dbuser:'Usuário do BD',l_dbpass:'Senha do BD',adv2:'Avançado',l_tz:'Fuso horário',h_tz:'ex: <code>America/Argentina/Buenos_Aires</code>. Para o carimbo «Atualizado».',
  b_test:'Testar conexão',b_save:'Salvar e instalar',m_testing:'Testando…',m_saving:'Salvando e sincronizando…',m_ok:'✓ Conectado · {n} estados visíveis',m_saved:'✓ Salvo · {n} projetos',m_savefail:'Falha ao salvar',m_reqfail:'Falha na solicitação',m_syncfail:'Salvo, mas a sincronização falhou: '}
};
let LANG=localStorage.getItem('pd-lang')||DEF||'es';
if(!I18N[LANG])LANG='es';
const T=k=>(I18N[LANG]&&I18N[LANG][k])||(I18N.en[k])||k;
function applyI18n(){
  document.documentElement.lang=LANG;
  document.querySelectorAll('[data-i]').forEach(e=>{e.innerHTML=T(e.getAttribute('data-i'));});
  document.title='Setup · '+T('title');
}
const $=s=>document.querySelector(s);
$('#lang').value=LANG;
$('#lang').addEventListener('change',e=>{LANG=e.target.value;localStorage.setItem('pd-lang',LANG);applyI18n();});
// theme
const themeNow=()=>document.documentElement.getAttribute('data-theme')||(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
const updTheme=()=>$('#theme').textContent=themeNow()==='dark'?'☀️':'🌙';
$('#theme').addEventListener('click',()=>{const n=themeNow()==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',n);localStorage.setItem('pd-theme',n);updTheme();});
updTheme(); applyI18n();
// form
const f=$('#f'), msg=$('#msg');
const data=()=>{const o={};new FormData(f).forEach((v,k)=>o[k]=v);
  ['glpi_tokens_in_query','glpi_insecure','include_only_leaf','gps_enabled'].forEach(k=>o[k]=f.elements[k].checked?1:'');return o;};
const post=pl=>fetch('setup.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(pl)}).then(r=>r.json());
$('#test').onclick=()=>{msg.style.color='var(--mu)';msg.textContent=T('m_testing');
  post(Object.assign(data(),{action:'test'})).then(j=>{msg.style.color=j.ok?'#1f9d55':'#d33';msg.textContent=j.ok?T('m_ok').replace('{n}',j.n):('✕ '+j.msg);}).catch(()=>{msg.style.color='#d33';msg.textContent=T('m_reqfail');});};
f.onsubmit=e=>{e.preventDefault();msg.style.color='var(--mu)';msg.textContent=T('m_saving');
  post(Object.assign(data(),{action:'save'})).then(j=>{
    if(j.ok){const gg=j.generated;
      if(gg&&gg.error){msg.style.color='#d33';msg.textContent=T('m_syncfail')+gg.error;}
      else{msg.style.color='#1f9d55';msg.textContent=gg?T('m_saved').replace('{n}',gg.projects):'✓';setTimeout(()=>location.href='index.html',1400);}
    }else{msg.style.color='#d33';msg.textContent=T('m_savefail');}
  }).catch(()=>{msg.style.color='#d33';msg.textContent=T('m_reqfail');});};
</script>
</body></html>
