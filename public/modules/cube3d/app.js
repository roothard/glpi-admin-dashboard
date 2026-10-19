/* app.js — vista 3D del datacenter a partir del inventario de GLPI.
 *
 * Reusa el rack de 42U (modelos.js) y el motor de fichas (motor.js) del visor original.
 * Tres entradas independientes, cada una por su endpoint (las dos ultimas son opcionales):
 *   data.php     -> rack(s) y equipos de GLPI (sesion del usuario)
 *   alertas.php  -> alertas de monitoreo ya mapeadas a equipo/puerto (capa opcional)
 *   cables.php   -> cableado (origen/puerto -> equipo/conector) (capa opcional)
 * Si un endpoint no esta configurado, su capa simplemente no se dibuja.
 *
 * @license MIT
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/addons/renderers/CSS2DRenderer.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';
import { crearRack, U, ANCHO_CHASIS } from './modelos.js';
import { cargarCatalogo, elegirFicha, construirEquipo, altoDe, fijarCalidad } from './motor.js';

/* ============ Idioma (sigue al tablero por localStorage rh-lang) ============ */
const LANG = (() => { try { return (localStorage.getItem('rh-lang') || 'es').slice(0, 2); } catch { return 'es'; } })();
let T = {};
const t = (k, vars = {}) => String(T[k] ?? k).replace(/\{(\w+)\}/g, (_, n) => vars[n] ?? '');
const $ = id => document.getElementById(id);
async function cargarIdioma() {
  try { const all = await (await fetch('i18n.json')).json(); T = all[LANG] || all.es || {}; } catch { T = {}; }
  const set = (id, k) => { const el = $(id); if (el) el.textContent = t(k); };
  set('t-title', 'title'); set('t-hint', 'hint'); set('t-racks', 'racks'); set('t-rows', 'rows');
  set('t-disp', 'disp'); set('t-displine', 'disp_line'); set('t-dispreal', 'disp_real');
  set('t-door', 'door'); set('t-panels', 'panels'); set('t-todo', 'todo'); set('t-todosub', 'todo_sub');
  set('t-height', 'height'); set('bPrev', 'prev'); set('bNext', 'next'); set('bSala', 'room');
  set('bFrente', 'front'); set('bTrasera', 'back'); set('bRefrescar', 'refresh');
  set('t-info-hide', 'info_hide'); set('t-cables-show', 'cables_show'); set('t-ruteo', 'ruteo');
  set('t-ruteo-centro', 'ruteo_centro'); set('t-ruteo-costados', 'ruteo_costados');
  set('t-cables-sub', 'cables_sub');
  document.documentElement.lang = LANG;
}

/* ============ Escena ============ */
const RACK_Z = -2.6;
const scene = new THREE.Scene();
scene.background = new THREE.Color(0x0b0f1a);
scene.fog = new THREE.Fog(0x0b0f1a, 14, 34);
const camera = new THREE.PerspectiveCamera(50, innerWidth / innerHeight, 0.02, 100);
const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setSize(innerWidth, innerHeight);
renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.05;
document.body.appendChild(renderer.domElement);
scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), .04).texture;
scene.environmentIntensity = .5;
const composer = new EffectComposer(renderer, new THREE.WebGLRenderTarget(innerWidth, innerHeight, { type: THREE.HalfFloatType, samples: 4 }));
composer.setPixelRatio(Math.min(devicePixelRatio, 2)); composer.setSize(innerWidth, innerHeight);
composer.addPass(new RenderPass(scene, camera));
composer.addPass(new UnrealBloomPass(new THREE.Vector2(innerWidth, innerHeight), .32, .45, 1.3));
composer.addPass(new OutputPass());
const labelRenderer = new CSS2DRenderer();
labelRenderer.setSize(innerWidth, innerHeight);
Object.assign(labelRenderer.domElement.style, { position: 'fixed', top: 0, left: 0, pointerEvents: 'none' });
document.body.appendChild(labelRenderer.domElement);

const controls = new OrbitControls(camera, renderer.domElement);
controls.maxPolarAngle = Math.PI / 2 - 0.02;
controls.minDistance = 0.25; controls.maxDistance = 25;
controls.zoomToCursor = true;
controls.screenSpacePanning = true; controls.panSpeed = 1.1;
controls.mouseButtons = { LEFT: THREE.MOUSE.ROTATE, MIDDLE: THREE.MOUSE.DOLLY, RIGHT: THREE.MOUSE.PAN };
controls.listenToKeyEvents(window); controls.keyPanSpeed = 30;
controls.keys = { LEFT: 'ArrowLeft', RIGHT: 'ArrowRight', UP: 'sin-uso', BOTTOM: 'sin-uso' };
function vistaFrontal() { camera.position.set(-0.1, 1.25, RACK_Z + 3.4); controls.target.set(-0.65, 1.02, RACK_Z); }
vistaFrontal();

scene.add(new THREE.HemisphereLight(0xaab8ff, 0x1a1f33, 0.45));
const sun = new THREE.DirectionalLight(0xfff4e8, 2.2);
sun.position.set(2, 6, 1); sun.target.position.set(0, 1, -1.6); sun.castShadow = true;
sun.shadow.mapSize.set(4096, 4096); sun.shadow.bias = -.0003; sun.shadow.normalBias = .02;
Object.assign(sun.shadow.camera, { left: -6, right: 6, top: 6, bottom: -6, near: .5, far: 16 });
scene.add(sun, sun.target);
const relleno = new THREE.PointLight(0x9fb4ff, 6, 9, 1.6); relleno.position.set(-2.2, 1.8, RACK_Z + 2.6); scene.add(relleno);
const luzTrasera = new THREE.PointLight(0xe3e9ff, 3.5, 7, 1.4); luzTrasera.position.set(.4, 2.2, RACK_Z - 1.2); scene.add(luzTrasera);

// piso técnico (baldosas de 60 cm) y paredes lejanas
const texPiso = (() => {
  const c = document.createElement('canvas'); c.width = c.height = 512; const g = c.getContext('2d');
  g.fillStyle = '#343a47'; g.fillRect(0, 0, 512, 512);
  for (let i = 0; i < 2600; i++) { g.fillStyle = `rgba(255,255,255,${Math.random() * .035})`; g.fillRect(Math.random() * 512, Math.random() * 512, 3, 3); }
  g.strokeStyle = '#161a22'; g.lineWidth = 6; g.strokeRect(0, 0, 512, 512);
  g.strokeStyle = '#4a5162'; g.lineWidth = 2; g.strokeRect(5, 5, 502, 502);
  const texx = new THREE.CanvasTexture(c); texx.colorSpace = THREE.SRGBColorSpace; texx.wrapS = texx.wrapT = THREE.RepeatWrapping; texx.repeat.set(24 / .6, 16 / .6); texx.anisotropy = 8; return texx;
})();
const piso = new THREE.Mesh(new THREE.PlaneGeometry(24, 16), new THREE.MeshStandardMaterial({ map: texPiso, roughness: .72, metalness: .05 }));
piso.rotation.x = -Math.PI / 2; piso.position.set(0, 0, -1); piso.receiveShadow = true; scene.add(piso);
const matPared = c => new THREE.MeshStandardMaterial({ color: c, roughness: .85, metalness: 0 });
[[0, 1.6, -8, 24, .15], [0, 1.6, 7, 24, .15], [-12, 1.6, -.5, .15, 15], [12, 1.6, -.5, .15, 15]].forEach(([x, y, z, a, c]) => {
  const q = new THREE.Mesh(new THREE.BoxGeometry(a, 3.2, c), matPared(0x2b3245)); q.position.set(x, y, z); q.receiveShadow = true; scene.add(q);
});

const capa = $('capa'), lineas = $('lineas');

/* ============ Rack y equipos (fichas + motor.js) ============ */
const ANCHO_MEDIO = (ANCHO_CHASIS - .008) / 2;
const equipos = new Map();           // nombre en minúsculas -> {mesh, glpi, ficha, rack}
let racks = [];                      // {obj, grupo, nombre, caja, fila, real}
let catalogo = null, avisosEquipos = [], rackCaja = null, rackActual = 0;
const filasVisibles = new Set();
let disposicion = 'linea';
try { disposicion = localStorage.getItem('cube3d-disp') || 'linea'; } catch {}
const PASO_RACK = .62;

function construirSala(datos) {
  racks.forEach(r => scene.remove(r.grupo)); racks = [];
  equipos.clear(); avisosEquipos = [];
  const lista = datos.racks && datos.racks.length ? datos.racks : [];
  fijarCalidad(lista.length > 2 ? 1024 : 2048);
  lista.forEach((d, i) => {
    const obj = crearRack(d.unidades || 42), grupo = obj.group;
    grupo.position.set(d.x ?? 0, 0, d.z ?? RACK_Z); grupo.rotation.y = d.rot ?? 0; scene.add(grupo);
    const tornillos = [];
    for (const e of (d.equipos || [])) {
      const sel = elegirFicha(catalogo, e), f = sel.ficha, alto = altoDe(f, e);
      const m = construirEquipo(f, e, { anchoMedio: ANCHO_MEDIO });
      const mitad = !!f.medio_ancho;
      m.position.set(mitad ? (e.hpos === 1 ? -ANCHO_MEDIO / 2 - .002 : ANCHO_MEDIO / 2 + .002) : 0, obj.base + (e.u - 1 + alto / 2) * U, obj.zFrente);
      m.userData.glpi = e; m.userData.rackIdx = i; grupo.add(m);
      equipos.set(e.nombre.toLowerCase(), { mesh: m, glpi: e, ficha: f, rack: i });
      if (!mitad || e.hpos === 1) tornillos.push({ u0: e.u, u1: e.u + alto - 1 });
      sel.avisos.forEach(x => avisosEquipos.push({ equipo: e.nombre, texto: x }));
    }
    obj.colocarTornillos(tornillos);
    obj.setPuerta($('chkPuerta').checked); obj.setPaneles($('chkPaneles').checked);
    grupo.updateMatrixWorld(true);
    racks.push({ obj, grupo, nombre: d.nombre || `Rack ${i + 1}`, caja: new THREE.Box3(), fila: d.fila || 'A', real: { x: d.x ?? 0, z: d.z ?? RACK_Z, rot: d.rot ?? 0 } });
  });
  filasVisibles.clear(); racks.forEach(r => filasVisibles.add(r.fila));
  pintarAvisos(); pintarFilas(); aplicarDisposicion();
}

function cajaDe(r) {
  const caja = new THREE.Box3();
  for (const x of [-r.obj.ancho / 2, r.obj.ancho / 2]) for (const y of [0, r.obj.alto]) for (const z of [-r.obj.profundidad / 2, r.obj.profundidad / 2]) caja.expandByPoint(r.grupo.localToWorld(new THREE.Vector3(x, y, z)));
  return caja;
}
const indicesVisibles = () => racks.map((r, i) => i).filter(i => racks[i].grupo.visible);
function aplicarDisposicion() {
  racks.forEach(r => { r.grupo.visible = filasVisibles.has(r.fila); });
  const vis = racks.filter(r => r.grupo.visible);
  if (disposicion === 'linea') vis.forEach((r, i) => { r.grupo.position.set((i - (vis.length - 1) / 2) * PASO_RACK, 0, RACK_Z); r.grupo.rotation.y = 0; });
  else racks.forEach(r => { r.grupo.position.set(r.real.x, 0, r.real.z); r.grupo.rotation.y = r.real.rot; });
  racks.forEach(r => { r.grupo.updateMatrixWorld(true); r.caja = cajaDe(r); });
  rackCaja = new THREE.Box3(); vis.forEach(r => rackCaja.union(r.caja));
  if (!racks[rackActual]?.grupo.visible) rackActual = Math.max(0, racks.findIndex(r => r.grupo.visible));
  pintarChips(); construirCables(); dibujarAlertas();
}
function pintarFilas() {
  const nombres = [...new Set(racks.map(r => r.fila))], el = $('filas'); el.replaceChildren();
  if (nombres.length < 2) return;
  nombres.forEach(n => {
    const l = document.createElement('label'); l.className = 'chk'; l.style.cssText = 'display:inline-block;margin:4px 10px 0 0';
    const c = document.createElement('input'); c.type = 'checkbox'; c.checked = filasVisibles.has(n);
    c.onchange = () => { if (c.checked) filasVisibles.add(n); else filasVisibles.delete(n); if (!filasVisibles.size) { filasVisibles.add(n); c.checked = true; } aplicarDisposicion(); vistaSala(); };
    l.append(c, ` ${t('row')} ${n} (${racks.filter(r => r.fila === n).length})`); el.appendChild(l);
  });
  $('selDisp').value = disposicion;
}
function pintarChips() {
  const el = $('chips'); el.replaceChildren();
  racks.forEach((r, i) => {
    if (!r.grupo.visible) return;
    const b = document.createElement('button'); b.textContent = r.nombre; b.style.cssText = 'margin:3px 3px 0 0;padding:3px 7px;font-size:11px' + (i === rackActual ? ';background:#7b93ff' : '');
    b.onclick = () => irARack(i); el.appendChild(b);
  });
  $('detRacks').style.display = racks.length > 1 ? '' : 'none';
}
function pintarAvisos() {
  const el = $('listaAvisos'); el.replaceChildren();
  document.querySelector('#detAvisos summary').textContent = `${t('todo')} (${avisosEquipos.length})`;
  $('detAvisos').style.display = avisosEquipos.length ? '' : 'none';
  avisosEquipos.forEach(a => { const d = document.createElement('div'); d.className = 'fila'; d.append(a.equipo); const s = document.createElement('small'); s.textContent = a.texto; d.appendChild(s); el.appendChild(d); });
}

/* ============ Navegación ============ */
let anim = null;
const _p = new THREE.Vector3(), _q = new THREE.Quaternion(), _w = new THREE.Vector3();
function irA(pos, obj, dur = .85) { anim = { t0: performance.now(), d: dur * 1000, p0: camera.position.clone(), p1: pos.clone(), a0: controls.target.clone(), a1: obj.clone() }; }
function pasoAnim() {
  if (!anim) return;
  const k = Math.min(1, (performance.now() - anim.t0) / anim.d), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
  camera.position.lerpVectors(anim.p0, anim.p1, e); controls.target.lerpVectors(anim.a0, anim.a1, e);
  if (k >= 1) anim = null;
}
controls.addEventListener('start', () => { anim = null; });
const frenteDe = r => new THREE.Vector3(0, 0, 1).applyQuaternion(r.grupo.getWorldQuaternion(_q));
const derechaDe = r => new THREE.Vector3(1, 0, 0).applyQuaternion(r.grupo.getWorldQuaternion(_q));
const alFrente = r => { r.grupo.getWorldPosition(_p); return _w.subVectors(camera.position, _p).dot(frenteDe(r)) > 0; };
function rackCercano() {
  let mejor = null, dm = Infinity;
  racks.forEach(r => { if (!r.grupo.visible) return; r.grupo.getWorldPosition(_p); const d = _p.distanceToSquared(controls.target); if (d < dm) { dm = d; mejor = r; } });
  return mejor;
}
function espacioLibre(r, dir) {
  r.grupo.getWorldPosition(_p);
  const o = new THREE.Vector3(_p.x, .3, _p.z).addScaledVector(dir, r.obj.profundidad / 2 + .01), ray = new THREE.Ray(o, dir), hit = new THREE.Vector3();
  let libre = 6;
  racks.forEach(otro => { if (otro !== r && otro.grupo.visible && ray.intersectBox(otro.caja, hit)) libre = Math.min(libre, o.distanceTo(hit)); });
  return r.obj.profundidad / 2 + libre - .15;
}
function irARack(i, trasera = false) {
  const r = racks[i]; if (!r) return; rackActual = i;
  r.grupo.getWorldPosition(_p);
  const f = frenteDe(r), der = derechaDe(r), alto = Math.min(r.obj.alto * .5, 1.05), dir = trasera ? f.clone().negate() : f;
  const c = new THREE.Vector3(_p.x, alto, _p.z).addScaledVector(der, trasera ? .55 : -.55);
  const dist = Math.max(1.0, Math.min(trasera ? 1.9 : 3.2, espacioLibre(r, dir)));
  irA(c.clone().addScaledVector(dir, dist).setY(Math.min(1.3, alto + .35)), c);
  pintarChips();
}
function vistaSala(dur = .85) {
  const vis = racks.filter(r => r.grupo.visible);
  if (vis.length < 2) return irARack(Math.max(0, racks.findIndex(r => r.grupo.visible)));
  const c = rackCaja.getCenter(new THREE.Vector3()), sz = rackCaja.getSize(new THREE.Vector3());
  const hud = $('hud'), px = hud.style.display === 'none' ? 0 : hud.getBoundingClientRect().right / 2;
  if (disposicion === 'linea') {
    const hf = 2 * Math.atan(Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.aspect);
    const libre = Math.max(.4, 1 - 2 * px / innerWidth);
    const dist = Math.max(3.4, (sz.x / 2 + .5) / Math.tan(hf / 2) / libre), obj = new THREE.Vector3(c.x - 2 * dist * Math.tan(hf / 2) * px / innerWidth, 1.0, c.z);
    irA(new THREE.Vector3(obj.x, 1.5, c.z + sz.z / 2 + dist), obj, dur);
  } else irA(new THREE.Vector3(c.x - .9, 3.4, rackCaja.max.z + 4.6), new THREE.Vector3(c.x - .9, .9, c.z), dur);
}
const siguienteRack = d => { const v = indicesVisibles(), k = v.indexOf(rackActual); if (v.length) irARack(v[(k + d + v.length) % v.length]); };
function mover(dy) { anim = null; const y = THREE.MathUtils.clamp(controls.target.y + dy, .15, 2.4), d = y - controls.target.y; controls.target.y += d; camera.position.y += d; }
const equipoDe = o => { while (o) { if (o.userData.rackIdx !== undefined) return o; o = o.parent; } return null; };
function enfocar(anchor, puerto) {
  const pos = anchor.getWorldPosition(new THREE.Vector3()), eq = equipoDe(anchor), r = racks[eq?.userData.rackIdx ?? 0];
  const f = r ? frenteDe(r) : new THREE.Vector3(0, 0, 1), der = r ? derechaDe(r) : new THREE.Vector3(1, 0, 0), dist = puerto ? .42 : (camera.aspect < 1 ? 1.1 : .8);
  const hud = $('hud'), px = hud.style.display === 'none' ? 0 : hud.getBoundingClientRect().right / 2;
  const objetivo = pos.clone().addScaledVector(der, -2 * dist * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.aspect * px / innerWidth);
  irA(objetivo.clone().addScaledVector(f, dist).add(new THREE.Vector3(0, .03, 0)), objetivo);
}

/* ============ Alertas (capa opcional) ============
   Las alertas llegan de alertas.php ya mapeadas a un equipo del inventario
   (campo "equipo") y, si corresponde, a un puerto ("interfaz"). El front solo
   las ubica y dibuja una etiqueta con una flecha al equipo o a la boca. */
const COLOR = s => s >= 5 ? 0xe5253a : s === 4 ? 0xff7a1a : s === 3 ? 0xe8a317 : s === 2 ? 0xd9cf2e : 0x5b8bd9;
const hex = c => '#' + c.toString(16).padStart(6, '0');
let marcas = [];                 // {anchor, div, linea, punta, w, h, orig, sev, pos, puerto}
let ultimo = [];                 // ultimas alertas recibidas

function ubicarAlerta(p) {
  const nombre = String(p.equipo ?? p.host ?? '').toLowerCase();
  const eq = equipos.get(nombre); if (!eq) return null;
  let anchor = eq.mesh, puerto = null;
  if (p.interfaz && eq.mesh.userData.puertos && /^(gi|gig|ge|te|fa|eth|port|p)?\d+$/i.test(String(p.interfaz).trim())) {
    const num = parseInt(String(p.interfaz).replace(/^\D+/, ''));
    if (eq.mesh.userData.puertos[num]) { anchor = eq.mesh.userData.puertos[num]; puerto = p.interfaz; }
  }
  return { anchor, titulo: eq.glpi.nombre, puerto, clave: eq.glpi.nombre.toLowerCase() + '|' + (puerto || '') };
}
function pintarAnclaje(m, color, k) {
  const u = m.anchor.userData;
  if (u?.haloMat) { u.haloMat.color.setHex(color).multiplyScalar(1.2 + 1.6 * k); u.haloMat.opacity = 1; u.haloFill.color.setHex(color); u.haloFill.opacity = .02 + .06 * k; }
  else if (m.anchor.material?.emissive) { m.anchor.material.emissive.setHex(color).multiplyScalar(k); m.anchor.material.emissiveIntensity = 1.6; }
}
function limpiarAnclaje(m) {
  const u = m.anchor.userData;
  if (u?.haloMat) { u.haloMat.opacity = 0; u.haloFill.opacity = 0; } else m.anchor.material?.emissive?.setHex(m.orig || 0);
}
function dibujarAlertas() {
  marcas.forEach(limpiarAnclaje); marcas = [];
  if (lineas) lineas.replaceChildren();
  if (capa) capa.querySelectorAll('.etiqueta').forEach(e => e.remove());
  const ocultarInfo = $('chkInfo')?.checked;
  const visibles = ultimo.filter(p => !(ocultarInfo && (p.severidad ?? 0) <= 1));
  const grupos = new Map(), sinLugar = [], ocultas = [];
  for (const p of visibles) {
    delete p._u; delete p._h;
    const u = ubicarAlerta(p); if (!u) { sinLugar.push(p); continue; }
    const ir = equipoDe(u.anchor)?.userData.rackIdx;
    if (ir !== undefined && !racks[ir].grupo.visible) { p._h = u; p._ir = ir; ocultas.push(p); continue; }
    p._u = u;
    if (!grupos.has(u.clave)) grupos.set(u.clave, { u, lista: [] }); grupos.get(u.clave).lista.push(p);
  }
  const items = [...grupos.values()].map(g => {
    g.lista.sort((a, b) => (b.severidad ?? 0) - (a.severidad ?? 0));
    const pos = new THREE.Vector3(); g.u.anchor.getWorldPosition(pos);
    return { ...g, pos, sev: g.lista[0].severidad ?? 0 };
  });
  if (capa) capa.style.display = 'block';
  for (const it of items) {
    const c = COLOR(it.sev), peor = it.lista[0];
    const div = document.createElement('div'); div.className = 'etiqueta'; div.style.background = hex(c) + 'ee'; div.style.pointerEvents = 'auto'; div.style.cursor = 'pointer';
    div.onclick = () => enfocar(it.u.anchor, !!it.u.puerto);
    const extra = it.u.puerto ? ' · ' + t('port') + ' ' + it.u.puerto : '';
    const mas = it.lista.length > 1 ? ' · +' + (it.lista.length - 1) : '';
    div.append(String(peor.nombre || '').slice(0, 60));
    const s = document.createElement('small'); s.textContent = it.u.titulo + extra + mas; div.appendChild(s);
    capa.appendChild(div);
    const linea = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    linea.setAttribute('stroke', hex(c)); linea.setAttribute('stroke-width', '1.6'); linea.setAttribute('stroke-opacity', '.85');
    const punta = document.createElementNS('http://www.w3.org/2000/svg', 'polygon'); punta.setAttribute('fill', hex(c));
    lineas.append(linea, punta);
    const orig = it.u.anchor.material?.emissive ? it.u.anchor.material.emissive.getHex() : 0;
    marcas.push({ anchor: it.u.anchor, div, linea, punta, w: div.offsetWidth, h: div.offsetHeight, orig, sev: it.sev, pos: it.pos, puerto: !!it.u.puerto });
  }
  pintarListaAlertas(visibles, sinLugar, ocultas);
}
const _v = new THREE.Vector3();
const aPantalla = p => { _v.copy(p).project(camera); return { x: (_v.x * .5 + .5) * innerWidth, y: (-_v.y * .5 + .5) * innerHeight, ok: _v.z < 1 }; };
function acomodarEtiquetas(porDelante) {
  const hay = porDelante && marcas.length && rackCaja;
  if (capa) capa.style.display = hay ? 'block' : 'none';
  if (!hay) return;
  const W = innerWidth, H = innerHeight, G = 6, M = 8, MARGEN = 90;
  let rx0 = Infinity, rx1 = -Infinity;
  for (const x of [rackCaja.min.x, rackCaja.max.x]) for (const y of [rackCaja.min.y, rackCaja.max.y]) for (const z of [rackCaja.min.z, rackCaja.max.z]) {
    const s = aPantalla(new THREE.Vector3(x, y, z)); rx0 = Math.min(rx0, s.x); rx1 = Math.max(rx1, s.x);
  }
  const hud = $('hud');
  const hudDer = hud.style.display === 'none' ? 0 : hud.getBoundingClientRect().right + 10;
  const maxW = Math.max(...marcas.map(m => m.w)), centro = (rx0 + rx1) / 2;
  const items = marcas.map(m => ({ m, s: aPantalla(m.pos) }));
  const izqCabe = rx0 - MARGEN - maxW >= hudDer;
  const lados = { izq: [], der: [] };
  items.forEach(it => lados[it.s.x < centro && izqCabe ? 'izq' : 'der'].push(it));
  for (const [lado, arr] of Object.entries(lados)) {
    arr.sort((a, b) => a.s.y - b.s.y);
    const cols = [[]]; let usado = 0;
    arr.forEach(it => { const h = it.m.h + G; if (usado + h > H - 2 * M && cols[cols.length - 1].length) { cols.push([]); usado = 0; } cols[cols.length - 1].push(it); usado += h; });
    cols.forEach((col, j) => {
      let tope = M;
      col.forEach(it => { it.top = Math.max(it.s.y - it.m.h / 2, tope); tope = it.top + it.m.h + G; });
      let lim = H - M;
      for (let i = col.length - 1; i >= 0; i--) { col[i].top = Math.min(col[i].top, lim - col[i].m.h); lim = col[i].top - G; }
      col.forEach(it => { it.x = lado === 'izq' ? rx0 - MARGEN - j * (maxW + 14) - it.m.w : Math.min(rx1 + MARGEN + j * (maxW + 14), W - it.m.w - M - 44); it.lado = lado; });
    });
  }
  for (const it of items) {
    const { m, s } = it, vis = s.ok && it.x !== undefined;
    m.div.style.display = vis ? 'block' : 'none';
    m.linea.style.display = m.punta.style.display = vis ? '' : 'none';
    if (!vis) continue;
    m.div.style.transform = `translate(${it.x}px, ${it.top}px)`;
    const x0 = it.lado === 'izq' ? it.x + m.w : it.x, y0 = it.top + m.h / 2;
    m.linea.setAttribute('x1', x0); m.linea.setAttribute('y1', y0); m.linea.setAttribute('x2', s.x); m.linea.setAttribute('y2', s.y);
    const ang = Math.atan2(s.y - y0, s.x - x0), L = 9;
    const pf = (a, r) => `${s.x - Math.cos(ang + a) * r},${s.y - Math.sin(ang + a) * r}`;
    m.punta.setAttribute('points', `${s.x},${s.y} ${pf(.4, L)} ${pf(-.4, L)}`);
  }
}
function pintarListaAlertas(visibles, sinLugar, ocultas) {
  const el = $('lista'); if (!el) return; el.innerHTML = '';
  const bloque = (titulo, arr, ubicadas) => {
    if (!arr.length) return;
    const h = document.createElement('div'); h.className = 'grupo'; h.textContent = `${titulo} (${arr.length})`; el.appendChild(h);
    arr.sort((a, b) => (b.severidad ?? 0) - (a.severidad ?? 0)).forEach(p => {
      const d = document.createElement('div'); d.className = 'fila' + (ubicadas ? '' : ' noloc'); d.style.borderColor = hex(COLOR(p.severidad ?? 0));
      d.append(String(p.nombre || '')); const s = document.createElement('small');
      s.textContent = ubicadas ? p._u.titulo + (p._u.puerto ? ' · ' + t('port') + ' ' + p._u.puerto : '') : String(p.equipo ?? p.host ?? '');
      d.appendChild(s);
      if (ubicadas) d.onclick = () => enfocar(p._u.anchor, !!p._u.puerto);
      el.appendChild(d);
    });
  };
  bloque(t('al_rack'), visibles.filter(p => p._u), true);
  if (ocultas.length) {
    const h = document.createElement('div'); h.className = 'grupo'; h.textContent = `${t('al_hidden')} (${ocultas.length})`; el.appendChild(h);
    ocultas.sort((a, b) => (b.severidad ?? 0) - (a.severidad ?? 0)).forEach(p => {
      const d = document.createElement('div'); d.className = 'fila'; d.style.borderColor = hex(COLOR(p.severidad ?? 0)); d.style.opacity = '.8';
      d.append(String(p.nombre || '')); const s = document.createElement('small'); s.textContent = `${p._h.titulo} · ${t('row')} ${racks[p._ir].fila}`; d.appendChild(s);
      d.onclick = () => { filasVisibles.add(racks[p._ir].fila); pintarFilas(); aplicarDisposicion(); enfocar(p._h.anchor, !!p._h.puerto); };
      el.appendChild(d);
    });
  }
  bloque(t('al_noloc'), sinLugar, false);
}

/* ============ Cableado (capa opcional) ============
   Recorrido: puerto del switch -> ordenador de cables -> (puerto trasero del equipo
   | parcheo corto a otra boca). Dos estilos: por el centro o por los costados. */
const PALETA_CABLE = [0x3aa0ff, 0x35c27a, 0xb46bff, 0xffb02e, 0xff4d6d, 0x2fd0d0, 0x8a7dff, 0x4bd1a0];
const colorEquipo = n => PALETA_CABLE[[...String(n)].reduce((a, ch) => a + ch.charCodeAt(0), 0) % PALETA_CABLE.length];
let cableGrupo = null, cablesDatos = [], cableSel = null, etiquetasTraseras = [];
const cableMallas = new Map();

function organizadorCercano(y, rackIdx) {
  let mejor = null;
  equipos.forEach(({ mesh, rack }) => {
    if (mesh.userData.tipo !== 'ordenador-cables' || rack !== rackIdx) return;
    const v = new THREE.Vector3(); mesh.getWorldPosition(v);
    if (!mejor || Math.abs(v.y - y) < Math.abs(mejor.y - y)) mejor = v;
  });
  return mejor;
}
function construirCables() {
  if (cableGrupo) scene.remove(cableGrupo);
  cableGrupo = new THREE.Group(); scene.add(cableGrupo); cableMallas.clear(); etiquetasTraseras = [];
  cableGrupo.visible = $('chkCables') ? $('chkCables').checked : true;
  if (!racks.length || !cablesDatos.length) { pintarCables(); return; }
  racks.forEach(r => r.grupo.updateMatrixWorld(true));
  const repetidos = new Map(), etiquetas = new Map();
  const modoRuteo = $('selRuteo') ? $('selRuteo').value : 'centro';
  const V = (x, y, z) => new THREE.Vector3(x, y, z);
  cablesDatos.forEach((c, k) => {
    const orig = equipos.get(String(c.origen || '').toLowerCase()), dest = equipos.get(String(c.equipo || '').toLowerCase());
    if (!orig || !dest || orig.rack === undefined) return;
    if (dest.rack !== undefined && dest.rack !== orig.rack) return;      // troncales entre racks: pendiente
    if (!racks[orig.rack].grupo.visible) return;
    const R = racks[orig.rack], zF = R.obj.zFrente;
    const aL = v => R.grupo.worldToLocal(v.clone()), aW = v => R.grupo.localToWorld(v.clone());
    const pm = orig.mesh.userData.puertos?.[c.num]; if (!pm) return;
    const pw = aL(pm.getWorldPosition(V(0, 0, 0)));
    const zc = zF + .03 + (k % 5) * .004, zLane = -.39 - (k % 8) * .014;
    const lado = pw.x < 0 ? -1 : 1, xS = lado * (.249 + (k % 2) * .0065), yDesp = ((k % 6) - 2.5) * .0065, zS = zF - .04 - (k % 10) * .007;
    let pts, rear = null;
    if (c.destPuerto) {
      const pb = dest.mesh.userData.puertos?.[c.destPuerto]; if (!pb) return;
      const pe = aL(pb.getWorldPosition(V(0, 0, 0)));
      if (modoRuteo === 'costados') {
        const oa = organizadorCercano(pw.y, orig.rack), ob = organizadorCercano(pe.y, orig.rack), ya = (oa ? oa.y : pw.y + .045) + yDesp, yb = (ob ? ob.y : pe.y + .045) + yDesp;
        pts = [V(pw.x, pw.y, zF + .014), V(pw.x, pw.y, zc), V(pw.x, ya, zc), V(xS, ya, zc), V(xS, ya, zS), V(xS, yb, zS), V(xS, yb, zc), V(pe.x, yb, zc), V(pe.x, pe.y, zc), V(pe.x, pe.y, zF + .01)];
      } else {
        const o = organizadorCercano((pw.y + pe.y) / 2, orig.rack), yO = o ? o.y : (pw.y + pe.y) / 2 + .02;
        pts = [V(pw.x, pw.y, zF + .014), V(pw.x, pw.y, zc), V(pw.x, yO, zc), V(pe.x, yO, zc), V(pe.x, pe.y, zc), V(pe.x, pe.y, zF + .01)];
      }
    } else {
      const o = organizadorCercano(pw.y, orig.rack), yOrg = o ? o.y : pw.y + .045;
      const clave = c.equipo + '|' + String(c.conector || '').toLowerCase(), rep = repetidos.get(clave) || 0; repetidos.set(clave, rep + 1);
      rear = aL(dest.mesh.localToWorld(dest.mesh.userData.puertoTrasero(c.conector, rep)));
      if (modoRuteo === 'costados') {
        const yR = yOrg + yDesp;
        pts = [V(pw.x, pw.y, zF + .014), V(pw.x, pw.y + .006, zc), V(pw.x, yR, zc), V(xS, yR, zc), V(xS, yR, zLane), V(xS, rear.y, zLane), V(rear.x, rear.y, zLane), V(rear.x, rear.y, rear.z - .035), V(rear.x, rear.y, rear.z - .004)];
      } else {
        pts = [V(pw.x, pw.y, zF + .014), V(pw.x, pw.y + .006, zc), V(pw.x, yOrg - .004, zc), V(pw.x, yOrg, zF - .1), V(pw.x, yOrg, zLane), V(rear.x, yOrg - .05, zLane), V(rear.x, rear.y + .04, zLane), V(rear.x, rear.y, rear.z - .035), V(rear.x, rear.y, rear.z - .004)];
      }
    }
    const base = colorEquipo(c.equipo);
    const color = c.dudoso ? 0xd9d9d9 : c.enlace === 'down' ? 0x6b7280 : base;
    const malla = new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts.map(aW), false, 'catmullrom', .15), 160, .0032, 8),
      new THREE.MeshStandardMaterial({ color, roughness: .45, metalness: .05, transparent: true, opacity: c.enlace === 'down' ? .55 : 1 }));
    malla.castShadow = true; malla.userData = c; cableGrupo.add(malla); cableMallas.set(c.origen + '|' + c.puerto, malla);
    if (rear) {
      const d = document.createElement('div'); d.textContent = String(c.conector || '') + (c.dudoso ? ' ?' : '');
      d.style.cssText = 'font-size:9px;color:#c3cdee;background:#0b0f1acc;padding:1px 4px;border-radius:3px;white-space:nowrap';
      const o = new CSS2DObject(d); const nEt = etiquetas.get(c.equipo) || 0; etiquetas.set(c.equipo, nEt + 1);
      o.position.copy(aW(V(rear.x, rear.y - .05 - nEt * .024, rear.z - .05))); cableGrupo.add(o); etiquetasTraseras.push({ o, rack: orig.rack });
    }
  });
  pintarCables();
}
function pintarCables() {
  const el = $('listaCables'); if (!el) return; el.innerHTML = '';
  const sum = document.querySelector('#detCables summary'); if (sum) sum.textContent = `${t('cables_title')} (${cablesDatos.length})`;
  if ($('detCables')) $('detCables').style.display = cablesDatos.length ? '' : 'none';
  cablesDatos.forEach(c => {
    const d = document.createElement('div'); d.className = 'fila'; d.style.borderColor = c.dudoso ? '#d9d9d9' : hex(colorEquipo(c.equipo));
    d.append(`${c.puerto} → ${c.equipo} ${c.conector || ''}`);
    const s = document.createElement('small'); s.textContent = c.enlace === 'down' ? t('cab_down') : t('cab_up'); d.appendChild(s);
    const idc = c.origen + '|' + c.puerto; d.onclick = () => resaltarCable(cableSel === idc ? null : idc);
    el.appendChild(d);
  });
}
function resaltarCable(puerto) {
  cableSel = puerto;
  cableMallas.forEach((m, p) => {
    const sel = puerto === null || p === puerto, up = m.userData.enlace !== 'down';
    m.material.opacity = sel ? (up ? 1 : .55) : .08;
    m.material.emissive.setHex(puerto === p ? 0x666666 : 0x000000);
  });
}

/* ============ Controles de la interfaz ============ */
function cablearUI() {
  const rng = $('rngAltura');
  rng.oninput = () => mover(+rng.value - controls.target.y);
  $('bArriba').onclick = () => mover(.2);
  $('bAbajo').onclick = () => mover(-.2);
  $('bPrev').onclick = () => siguienteRack(-1);
  $('bNext').onclick = () => siguienteRack(1);
  $('bSala').onclick = () => vistaSala();
  $('bFrente').onclick = () => irARack(rackActual);
  $('bTrasera').onclick = () => irARack(rackActual, true);
  $('bRefrescar').onclick = () => { actualizar(); cargarCables(); };
  $('selDisp').onchange = e => { disposicion = e.target.value; try { localStorage.setItem('cube3d-disp', disposicion); } catch {} aplicarDisposicion(); vistaSala(); };
  $('chkPuerta').onchange = e => racks.forEach(r => r.obj.setPuerta(e.target.checked));
  $('chkPaneles').onchange = e => racks.forEach(r => r.obj.setPaneles(e.target.checked));
  if ($('chkInfo')) $('chkInfo').onchange = dibujarAlertas;
  if ($('chkCables')) $('chkCables').onchange = e => { if (cableGrupo) cableGrupo.visible = e.target.checked; };
  if ($('selRuteo')) { try { const g = localStorage.getItem('cube3d-ruteo'); if (g) $('selRuteo').value = g; } catch {} $('selRuteo').onchange = e => { try { localStorage.setItem('cube3d-ruteo', e.target.value); } catch {} construirCables(); }; }
  renderer.domElement.addEventListener('wheel', e => { if (!e.shiftKey) return; e.preventDefault(); e.stopImmediatePropagation(); mover(-Math.sign(e.deltaY || e.deltaX) * .08); }, { capture: true, passive: false });
  const rc = new THREE.Raycaster(), pt = new THREE.Vector2();
  renderer.domElement.addEventListener('dblclick', e => {
    pt.set(e.clientX / innerWidth * 2 - 1, -(e.clientY / innerHeight) * 2 + 1); rc.setFromCamera(pt, camera);
    const hit = rc.intersectObjects(racks.filter(r => r.grupo.visible).map(r => r.grupo), true).find(h => equipoDe(h.object));
    if (hit) enfocar(equipoDe(hit.object), false);
  });
  addEventListener('keydown', e => {
    if (['SELECT', 'INPUT', 'TEXTAREA'].includes(e.target.tagName) || !racks.length) return;
    if (e.key === ']' || e.key === 'PageDown') { e.preventDefault(); siguienteRack(1); }
    else if (e.key === '[' || e.key === 'PageUp') { e.preventDefault(); siguienteRack(-1); }
    else if (e.key === 'Home') { e.preventDefault(); vistaSala(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); mover(e.shiftKey ? .3 : .1); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); mover(e.shiftKey ? -.3 : -.1); }
  });
}

/* ============ Datos ============ */
async function cargar() {
  const info = $('info'); info.textContent = t('loading');
  try {
    const d = await (await fetch('data.php')).json();
    if (d.auth === false) { info.textContent = t('err', { msg: '401' }); return; }
    if (!d.racks || !d.racks.length) { info.textContent = t('empty'); return; }
    catalogo = await cargarCatalogo();
    construirSala(d);
    const total = d.racks.reduce((a, x) => a + (x.equipos ? x.equipos.length : 0), 0);
    $('rackNombre').textContent = d.racks.length === 1 ? d.racks[0].nombre : '';
    info.textContent = t('loaded', { n: total });
    if (racks.length > 1) vistaSala(.01); else irARack(0);
  } catch (e) { info.textContent = t('err', { msg: e.message }); }
}
async function pedirOpcional(ruta) {        // devuelve null si el endpoint no existe/estA vacio
  try { const r = await fetch(ruta); if (!r.ok) return null; const d = await r.json(); if (d.error || d.auth === false) return null; return d; } catch { return null; }
}
async function actualizar() {
  const d = await pedirOpcional('alertas.php'); if (!d) return;
  ultimo = d.alertas || [];
  const est = $('estado'); if (est) est.textContent = t('al_status', { n: ultimo.length, h: new Date((d.actualizado || Date.now() / 1000) * 1000).toLocaleTimeString() });
  dibujarAlertas();
}
async function cargarCables() {
  const d = await pedirOpcional('cables.php'); if (!d) return;
  cablesDatos = d.cables || [];
  equipos.get(String(cablesDatos[0]?.origen || '').toLowerCase())?.mesh.userData.establecerEnlaces?.(d.enlaces || {});
  construirCables();
}

/* ============ Loop ============ */
const clock = new THREE.Clock();
function loop() {
  const t0 = clock.getElapsedTime();
  pasoAnim();
  const rc = rackCercano(), porDelante = camera.position.y > 3.2 || !rc || alFrente(rc);
  etiquetasTraseras.forEach(({ o, rack }) => { o.visible = !alFrente(racks[rack]); });
  marcas.forEach(m => pintarAnclaje(m, COLOR(m.sev), .35 + .65 * (.5 + .5 * Math.sin(t0 * 4))));
  equipos.forEach(e => e.mesh.userData.tick?.(t0));
  racks.forEach(r => r.obj.tick());
  controls.update();
  const ty = THREE.MathUtils.clamp(controls.target.y, .15, 2.4);
  if (ty !== controls.target.y) { camera.position.y += ty - controls.target.y; controls.target.y = ty; }
  if (document.activeElement?.id !== 'rngAltura') $('rngAltura').value = controls.target.y;
  composer.render(); labelRenderer.render(scene, camera);
  acomodarEtiquetas(porDelante);
  requestAnimationFrame(loop);
}
addEventListener('resize', () => {
  camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix();
  renderer.setSize(innerWidth, innerHeight); composer.setSize(innerWidth, innerHeight); labelRenderer.setSize(innerWidth, innerHeight);
});

cargarIdioma().then(cablearUI).then(cargar).then(() => Promise.all([actualizar(), cargarCables()]));
loop();
