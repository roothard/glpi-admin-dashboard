<?php
/**
 * setup.php — the configuration panel. Edit EVERYTHING the app needs to run:
 * GLPI connection, project selection, branding and modules. Nothing is
 * hardcoded — this writes config/settings.json (above the docroot).
 *
 * Access: open on first run (not configured yet); admin-only afterwards.
 * Secrets are write-only (shown masked; leave blank to keep the current value).
 * @license MIT
 */
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/src/Settings.php';
require_once dirname(__DIR__) . '/src/GlpiClient.php';

$cfg = Settings::load();
$configured = Settings::isConfigured($cfg);

// ---- access control ------------------------------------------------------
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

// ---- POST handlers -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $in['action'] ?? 'save';
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'test') {
        // Test a connection with provided values (falling back to stored secrets).
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
            echo json_encode(['ok' => true, 'msg' => "Connected · $n project states visible"]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // action = save -------------------------------------------------------
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
    echo json_encode(['ok' => $ok, 'configured' => Settings::isConfigured($cfg)]);
    exit;
}

// ---- render the form -----------------------------------------------------
$b = $cfg['branding'];
$g = $cfg['glpi'];
$p = $cfg['projects'];
$gps = $cfg['modules']['gps'];
$mask = fn($v) => $v !== '' ? '••••••••' : '';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup · <?= $h($b['app_name']) ?></title>
<style>
:root{--bg:#f3f6fc;--card:#fff;--bd:#e3e8f4;--bd2:#d3dbee;--tx:#141b30;--mu:#57627e;--ac:<?= $h($b['accent']) ?>}
@media(prefers-color-scheme:dark){:root{--bg:#0a0f1e;--card:#121a30;--bd:#24314f;--bd2:#31406a;--tx:#e9eef9;--mu:#96a2c1}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:32px 20px 80px}
h1{font-size:22px;margin:0 0 4px}.sub{color:var(--mu);margin:0 0 26px}
fieldset{border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin:0 0 18px;background:var(--card)}
legend{font-weight:700;padding:0 8px;font-size:14px}
.row{display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:center;margin:10px 0}
.row label{color:var(--mu);font-size:13.5px}
input[type=text],input[type=url],input[type=number],input[type=password],select{width:100%;font:500 14px inherit;color:var(--tx);background:var(--bg);border:1px solid var(--bd2);border-radius:9px;padding:9px 11px;outline:none}
input:focus,select:focus{border-color:var(--ac)}
input[type=color]{width:46px;height:34px;padding:2px;border:1px solid var(--bd2);border-radius:9px;background:var(--bg)}
.hint{grid-column:2;color:var(--mu);font-size:12px;margin-top:-4px}
.chk{display:flex;align-items:center;gap:8px}
.actions{position:sticky;bottom:0;background:var(--bg);padding:14px 0;display:flex;gap:10px;align-items:center;border-top:1px solid var(--bd)}
button{font:700 14px inherit;border:none;border-radius:10px;padding:11px 18px;cursor:pointer}
.save{background:var(--ac);color:#fff}.test{background:var(--card);color:var(--tx);border:1px solid var(--bd2)}
#msg{font-size:13.5px;font-weight:600}
</style></head>
<body><div class="wrap">
<h1>⚙️ <?= $h($b['app_name']) ?> — Setup</h1>
<p class="sub">Configure everything the dashboard needs. Secrets are write-only — leave blank to keep the stored value.</p>
<form id="f">
<fieldset><legend>GLPI connection</legend>
  <div class="row"><label>GLPI URL *</label><input type="url" name="glpi_url" value="<?= $h($g['url']) ?>" placeholder="https://glpi.example.com"></div>
  <div class="row"><label>App-Token *</label><input type="password" name="glpi_app_token" placeholder="<?= $mask($g['app_token']) ?>" autocomplete="new-password"></div>
  <div class="row"><label>User token</label><input type="password" name="glpi_user_token" placeholder="<?= $mask($g['user_token']) ?>" autocomplete="new-password"></div>
  <div class="hint">Personal API token used by the generator (cron). Login mode uses each user's own credentials.</div>
  <div class="row"><label>Profile id</label><input type="number" name="glpi_profile_id" value="<?= (int)$g['profile_id'] ?>" placeholder="0 = token default"></div>
  <div class="row"><label class="chk"><input type="checkbox" name="glpi_tokens_in_query" <?= $g['tokens_in_query'] ? 'checked' : '' ?>> Send tokens as query params</label><span class="hint" style="grid-column:auto">Enable if GLPI is behind Cloudflare.</span></div>
  <div class="row"><label class="chk"><input type="checkbox" name="glpi_insecure" <?= $g['insecure'] ? 'checked' : '' ?>> Allow self-signed TLS</label><span></span></div>
  <div class="row"><label>Same-host resolve</label><input type="text" name="glpi_resolve_host" value="<?= $h($g['resolve_host']) ?>" placeholder="host (optional)"></div>
  <div class="row"><label>… to IP</label><input type="text" name="glpi_resolve_ip" value="<?= $h($g['resolve_ip']) ?>" placeholder="127.0.0.1 (optional)"></div>
</fieldset>
<fieldset><legend>Projects</legend>
  <div class="row"><label>Project type</label><input type="text" name="project_type" value="<?= $h($p['project_type']) ?>" placeholder="empty = all types"></div>
  <div class="row"><label>Group by</label><select name="group_by"><?php foreach (['parent','entity','type'] as $o) echo '<option '.($p['group_by']===$o?'selected':'').">$o</option>"; ?></select></div>
  <div class="row"><label class="chk"><input type="checkbox" name="include_only_leaf" <?= $p['include_only_leaf'] ? 'checked' : '' ?>> Only projects with a parent</label><span></span></div>
  <div class="row"><label>Strip zone prefix</label><input type="text" name="area_strip_prefix" value="<?= $h($p['area_strip_prefix']) ?>" placeholder="e.g. 'Portfolio · '"></div>
  <div class="row"><label>State: in-progress</label><input type="text" name="state_inprogress" value="<?= $h(implode(', ', $p['state_inprogress'])) ?>"></div>
  <div class="row"><label>State: done</label><input type="text" name="state_done" value="<?= $h(implode(', ', $p['state_done'])) ?>"></div>
  <div class="row"><label>State: planned</label><input type="text" name="state_planned" value="<?= $h(implode(', ', $p['state_planned'])) ?>"></div>
  <div class="hint">Comma-separated keywords that map your GLPI state names to a status color (any language).</div>
</fieldset>
<fieldset><legend>Branding</legend>
  <div class="row"><label>App name</label><input type="text" name="app_name" value="<?= $h($b['app_name']) ?>"></div>
  <div class="row"><label>Subtitle</label><input type="text" name="subtitle" value="<?= $h($b['subtitle']) ?>"></div>
  <div class="row"><label>Accent color</label><input type="color" name="accent" value="<?= $h($b['accent']) ?>"></div>
  <div class="row"><label>Logo URL</label><input type="url" name="logo_url" value="<?= $h($b['logo_url']) ?>" placeholder="optional"></div>
  <div class="row"><label>Default language</label><select name="default_lang"><?php foreach (['es'=>'Español','en'=>'English','fr'=>'Français','de'=>'Deutsch','pt'=>'Português'] as $k=>$v) echo '<option value="'.$k.'" '.($b['default_lang']===$k?'selected':'').">$v</option>"; ?></select></div>
</fieldset>
<fieldset><legend>Module · GPS Check-ins</legend>
  <div class="row"><label class="chk"><input type="checkbox" name="gps_enabled" <?= $gps['enabled'] ? 'checked' : '' ?>> Enable module</label><span></span></div>
  <div class="row"><label>Tab label</label><input type="text" name="gps_label" value="<?= $h($gps['label']) ?>"></div>
  <div class="row"><label>App website link</label><input type="url" name="gps_app_url" value="<?= $h($gps['app_url']) ?>" placeholder="https://gps.example.com"></div>
  <div class="hint">Shown as a link in the module. Reads "Visita técnica" tickets from the GLPI DB:</div>
  <div class="row"><label>DB host</label><input type="text" name="db_host" value="<?= $h($gps['db']['host']) ?>" placeholder="127.0.0.1"></div>
  <div class="row"><label>DB name</label><input type="text" name="db_name" value="<?= $h($gps['db']['name']) ?>"></div>
  <div class="row"><label>DB user</label><input type="text" name="db_user" value="<?= $h($gps['db']['user']) ?>"></div>
  <div class="row"><label>DB password</label><input type="password" name="db_pass" placeholder="<?= $mask($gps['db']['pass']) ?>" autocomplete="new-password"></div>
</fieldset>
<fieldset><legend>General</legend>
  <div class="row"><label>Timezone</label><input type="text" name="timezone" value="<?= $h($cfg['timezone']) ?>" placeholder="UTC"></div>
</fieldset>
<div class="actions">
  <button type="button" class="test" id="test">Test connection</button>
  <button type="submit" class="save">Save configuration</button>
  <span id="msg"></span>
</div>
</form>
</div>
<script>
const f=document.getElementById('f'), msg=document.getElementById('msg');
const data=()=>{const o={};new FormData(f).forEach((v,k)=>o[k]=v);
  ['glpi_tokens_in_query','glpi_insecure','include_only_leaf','gps_enabled'].forEach(k=>o[k]=f.elements[k].checked?1:'');return o;};
function post(payload){return fetch('setup.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json());}
document.getElementById('test').onclick=()=>{msg.style.color='var(--mu)';msg.textContent='Testing…';
  post(Object.assign(data(),{action:'test'})).then(j=>{msg.style.color=j.ok?'#1f9d55':'#d33';msg.textContent=(j.ok?'✓ ':'✕ ')+j.msg;}).catch(()=>{msg.style.color='#d33';msg.textContent='Request failed';});};
f.onsubmit=e=>{e.preventDefault();msg.style.color='var(--mu)';msg.textContent='Saving…';
  post(Object.assign(data(),{action:'save'})).then(j=>{if(j.ok){msg.style.color='#1f9d55';msg.textContent='✓ Saved';setTimeout(()=>location.href='index.html',700);}else{msg.style.color='#d33';msg.textContent='Save failed';}});};
</script>
</body></html>
