/* app.js — vista 3D del rack del datacenter a partir del inventario de GLPI.
 *
 * Reusa el rack de 42U (modelos.js) y el motor de fichas (motor.js) del visor original;
 * los datos llegan de data.php, que lee GLPI con la sesión del usuario. Las alertas de
 * monitoreo son una capa aparte (opcional) que no se incluye en esta vista base.
 *
 * @license MIT
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
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
  set('bFrente', 'front'); set('bTrasera', 'back');
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
scene.add(Object.assign(new THREE.PointLight(0x9fb4ff, 6, 9, 1.6), { position: new THREE.Vector3(-2.2, 1.8, RACK_Z + 2.6) }));

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
  pintarChips();
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
  avisosEquipos.forEach(a => { const d = document.createElement('div'); d.className = 'fila'; d.innerHTML = ''; d.append(a.equipo); const s = document.createElement('small'); s.textContent = a.texto; d.appendChild(s); el.appendChild(d); });
}

/* ============ Navegación ============ */
let anim = null;
const _p = new THREE.Vector3(), _q = new THREE.Quaternion();
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
function enfocar(anchor) {
  const pos = anchor.getWorldPosition(new THREE.Vector3()), eq = equipoDe(anchor), r = racks[eq?.userData.rackIdx ?? 0];
  const f = r ? frenteDe(r) : new THREE.Vector3(0, 0, 1), der = r ? derechaDe(r) : new THREE.Vector3(1, 0, 0), dist = camera.aspect < 1 ? 1.1 : .8;
  const hud = $('hud'), px = hud.style.display === 'none' ? 0 : hud.getBoundingClientRect().right / 2;
  const objetivo = pos.clone().addScaledVector(der, -2 * dist * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.aspect * px / innerWidth);
  irA(objetivo.clone().addScaledVector(f, dist).add(new THREE.Vector3(0, .03, 0)), objetivo);
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
  $('selDisp').onchange = e => { disposicion = e.target.value; try { localStorage.setItem('cube3d-disp', disposicion); } catch {} aplicarDisposicion(); vistaSala(); };
  $('chkPuerta').onchange = e => racks.forEach(r => r.obj.setPuerta(e.target.checked));
  $('chkPaneles').onchange = e => racks.forEach(r => r.obj.setPaneles(e.target.checked));
  renderer.domElement.addEventListener('wheel', e => { if (!e.shiftKey) return; e.preventDefault(); e.stopImmediatePropagation(); mover(-Math.sign(e.deltaY || e.deltaX) * .08); }, { capture: true, passive: false });
  const rc = new THREE.Raycaster(), pt = new THREE.Vector2();
  renderer.domElement.addEventListener('dblclick', e => {
    pt.set(e.clientX / innerWidth * 2 - 1, -(e.clientY / innerHeight) * 2 + 1); rc.setFromCamera(pt, camera);
    const hit = rc.intersectObjects(racks.filter(r => r.grupo.visible).map(r => r.grupo), true).find(h => equipoDe(h.object));
    if (hit) enfocar(equipoDe(hit.object));
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
    const r = await fetch('data.php'); const d = await r.json();
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

/* ============ Loop ============ */
const clock = new THREE.Clock();
function loop() {
  const t0 = clock.getElapsedTime();
  pasoAnim();
  equipos.forEach(e => e.mesh.userData.tick?.(t0));
  racks.forEach(r => r.obj.tick());
  controls.update();
  const ty = THREE.MathUtils.clamp(controls.target.y, .15, 2.4);
  if (ty !== controls.target.y) { camera.position.y += ty - controls.target.y; controls.target.y = ty; }
  if (document.activeElement?.id !== 'rngAltura') $('rngAltura').value = controls.target.y;
  composer.render();
  requestAnimationFrame(loop);
}
addEventListener('resize', () => {
  camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix();
  renderer.setSize(innerWidth, innerHeight); composer.setSize(innerWidth, innerHeight);
});

cargarIdioma().then(cablearUI).then(cargar);
loop();
