<?php
/**
 * index.php — página del módulo "Datacenter 3D" (se embebe en el dashboard por iframe).
 *
 * Solo el armazón: estilos, el importmap a la copia LOCAL de three.js (la CSP del
 * panel es script-src 'self', no se puede CDN) y el HUD. La lógica vive en app.js,
 * que lee la sesión del usuario a través de data.php (rack) y, si están configurados,
 * alertas.php y cables.php (capas opcionales).
 *
 * @license MIT
 */
require dirname(__DIR__, 3) . '/lib.php';
panel_session();
header('X-Content-Type-Options: nosniff');
if (empty($_SESSION['glpi_token'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="margin:0;font-family:system-ui;background:#0b0f1a;color:#9fb0e8;display:grid;place-items:center;height:100vh">Iniciá sesión en el panel.</body>';
    exit;
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Datacenter 3D</title>
<style>
  html,body{margin:0;height:100%;background:#0b0f1a;color:#e6e9f2;font-family:Inter,system-ui,sans-serif;overflow:hidden}
  canvas{display:block}
  #hud{position:fixed;top:12px;left:12px;z-index:5;background:#121a2ce6;border:1px solid #2a3556;border-radius:10px;padding:10px 12px;font-size:12px;width:280px;max-height:calc(100% - 24px);overflow:auto}
  #hud b{color:#7b93ff}
  .sub{color:#8a96bd;font-size:12px;line-height:1.35}
  #info{margin:8px 0;color:#9fb0e8;min-height:18px}
  .grupo{margin-top:10px;font-weight:600;font-size:12px;color:#9fb0e8;text-transform:uppercase;letter-spacing:.04em}
  .fila{padding:4px 7px;border-left:4px solid #e8a317;margin-top:3px;background:#0b0f1a99;font-size:12px;line-height:1.3;cursor:pointer}
  .fila:hover{background:#1b2440}
  .fila small{display:block;color:#8a96bd}
  .fila.noloc{cursor:default;opacity:.85}
  button{background:#44527a;color:#fff;border:0;border-radius:6px;padding:5px 10px;margin:8px 4px 0 0;cursor:pointer;font-size:12px}
  button:hover{background:#55639a}
  label.chk{display:block;margin-top:8px;font-size:12px;color:#c3cdee}
  select{background:#0b0f1a;color:#e6e9f2;border:1px solid #2a3556;border-radius:4px;padding:2px 4px;margin-left:6px}
  #estado{color:#6f7da8;font-size:11px;margin-top:8px}
  #capa{position:fixed;inset:0;pointer-events:none;z-index:4;overflow:hidden}
  #lineas{position:absolute;inset:0;width:100%;height:100%}
  .etiqueta{position:absolute;left:0;top:0;padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;color:#fff;border:1px solid #fff5;white-space:nowrap;pointer-events:none;box-shadow:0 2px 8px #0008;max-width:300px}
  .etiqueta small{display:block;font-weight:400;opacity:.9;font-size:10px}
  #nav{position:fixed;right:10px;top:50%;transform:translateY(-50%);z-index:6;background:#121a2ce6;border:1px solid #2a3556;border-radius:10px;padding:6px 4px;display:flex;flex-direction:column;align-items:center;gap:4px;font-size:10px;color:#8a96bd}
  #nav button{margin:0;padding:3px 9px;font-size:13px}
  #rngAltura{writing-mode:vertical-lr;direction:rtl;height:200px;margin:2px 0;accent-color:#7b93ff}
</style>
</head>
<body>
<div id="hud">
  <b id="t-title">Datacenter 3D</b> · <span id="rackNombre"></span>
  <div class="sub" id="t-hint"></div>
  <div id="info"></div>
  <div id="detRacks" style="display:none">
    <div class="grupo" id="t-racks">Racks</div><div id="chips"></div>
    <button id="bPrev"></button><button id="bNext"></button><button id="bSala"></button>
    <div class="grupo" style="margin-top:8px" id="t-rows">Filas</div><div id="filas"></div>
    <label class="chk"><span id="t-disp">Disposición:</span>
      <select id="selDisp"><option value="linea" id="t-displine"></option><option value="real" id="t-dispreal"></option></select></label>
  </div>
  <label class="chk"><input type="checkbox" id="chkInfo" checked> <span id="t-info-hide"></span></label>
  <label class="chk"><input type="checkbox" id="chkCables" checked> <span id="t-cables-show"></span></label>
  <label class="chk"><input type="checkbox" id="chkEnergia" checked> <span id="t-energia-show"></span></label>
  <label class="chk"><span id="t-ruteo"></span>
    <select id="selRuteo"><option value="centro" id="t-ruteo-centro"></option><option value="costados" id="t-ruteo-costados"></option></select></label>
  <label class="chk"><input type="checkbox" id="chkPuerta" checked> <span id="t-door"></span></label>
  <label class="chk"><input type="checkbox" id="chkPaneles"> <span id="t-panels"></span></label>
  <details id="detCables" style="display:none"><summary class="grupo" style="cursor:pointer" id="t-cables">Cableado</summary>
    <div class="sub" style="margin-top:4px" id="t-cables-sub"></div><div id="listaCables"></div></details>
  <details id="detEnergia" style="display:none"><summary class="grupo" style="cursor:pointer" id="t-energia">Energía</summary>
    <div class="sub" style="margin-top:4px" id="t-energia-sub"></div><div id="listaEnergia"></div></details>
  <details id="detAvisos" style="display:none"><summary class="grupo" style="cursor:pointer" id="t-todo">Datos por completar</summary>
    <div class="sub" style="margin-top:4px" id="t-todosub"></div><div id="listaAvisos"></div></details>
  <div id="lista"></div>
  <button id="bFrente"></button><button id="bTrasera"></button><button id="bRefrescar"></button>
  <div id="estado"></div>
</div>
<div id="capa"><svg id="lineas"></svg></div>
<div id="nav"><button id="bArriba" title="▲">▲</button><input id="rngAltura" type="range" min="0.15" max="2.4" step="0.01" value="1.0"><button id="bAbajo" title="▼">▼</button><span id="t-height">altura</span></div>
<script type="importmap">
{"imports":{"three":"./vendor/three/three.module.js","three/addons/":"./vendor/three/addons/"}}
</script>
<script type="module" src="app.js"></script>
</body>
</html>
