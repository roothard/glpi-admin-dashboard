/* Utilidades de dibujo y el rack de 42U.
 *
 * Los equipos ya no están programados uno por uno: se describen con fichas JSON (carpeta fichas/) y los dibuja motor.js.
 * Medidas de referencia: 1U = 44,45 mm, frente de 19" = 482,6 mm, orificios de riel a 465,1 mm.
 * Convención de los equipos: el origen es el CENTRO de la cara frontal; el cuerpo se extiende hacia -z.
 */
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';

export const U = 0.04445;
export const ANCHO_19 = 0.4826;
export const ANCHO_CHASIS = 0.447;
const MM = 0.001;

/* ============ utilidades ============ */
export function lienzo(w, h, dibujar, repetir) {
  const c = document.createElement('canvas'); c.width = w; c.height = h;
  dibujar(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 8;
  if (repetir) { t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(repetir[0], repetir[1]); }
  return t;
}
export const std = (color, rough = .5, metal = .2, extra = {}) => new THREE.MeshStandardMaterial({color, roughness: rough, metalness: metal, ...extra});
const memo = {};
const una = (k, f) => (memo[k] ??= f());

function texChapa() {
  return una('chapa', () => lienzo(512, 512, (g, w, h) => {
    g.fillStyle = '#b4b9c0'; g.fillRect(0, 0, w, h);
    for (let i = 0; i < 1400; i++) {
      g.globalAlpha = .05 + Math.random() * .08; g.strokeStyle = Math.random() < .5 ? '#ffffff' : '#222';
      const y = Math.random() * h; g.beginPath(); g.moveTo(0, y); g.lineTo(w, y + Math.random() * 2 - 1); g.stroke();
    }
  }, [2, 2]));
}
export const M = {
  chapa: () => una('mChapa', () => std(0xffffff, .42, .8, {map: texChapa()})),
  negroMetal: () => una('mNegroM', () => std(0x16181d, .5, .65)),
  plastico: () => una('mPlastico', () => std(0x0f1013, .38, .08)),
  gris: () => una('mGris', () => std(0x3a3d44, .55, .5)),
  oscuro: () => una('mOscuro', () => std(0x050506, .8, .1)),
};

export function cuerpo(w, h, d, mat, r = .0015) {
  const m = new THREE.Mesh(new RoundedBoxGeometry(w, h, d, 2, r), mat);
  m.position.z = -d / 2; m.castShadow = m.receiveShadow = true; return m;
}
export function plano(w, h, map, {rough = .45, metal = .25, z = .0006, color = 0xffffff, lado = THREE.FrontSide} = {}) {
  const m = new THREE.Mesh(new THREE.PlaneGeometry(w, h), std(color, rough, metal, {map, side: lado}));
  m.position.z = z; m.receiveShadow = true; return m;
}
export function led(color, r = .0012, brillo = 2.4) {
  const mat = new THREE.MeshBasicMaterial({color: new THREE.Color(color).multiplyScalar(brillo), toneMapped: false});
  return new THREE.Mesh(new THREE.CircleGeometry(r, 14), mat);
}

export function base(nombre, w, h, d) {
  const g = new THREE.Group(); g.name = nombre;
  // resalte de alertas: borde brillante (con bloom) y relleno apenas visible, para no tapar el frente del equipo
  const aditivo = (extra = {}) => new THREE.MeshBasicMaterial({color: 0xffffff, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, ...extra});
  const haloMat = aditivo({toneMapped: false}), haloFill = aditivo();
  const halo = new THREE.Group(); halo.position.z = .014; g.add(halo);
  const t = .004;
  [[0, h / 2 + t / 2, w + .008, t], [0, -h / 2 - t / 2, w + .008, t], [-w / 2 - t / 2, 0, t, h], [w / 2 + t / 2, 0, t, h]].forEach(([x, y, ww, hh]) => {
    const m = new THREE.Mesh(new THREE.PlaneGeometry(ww, hh), haloMat); m.position.set(x, y, 0); halo.add(m);
  });
  halo.add(new THREE.Mesh(new THREE.PlaneGeometry(w, h), haloFill));
  g.userData = {w, h, d, halo, haloMat, haloFill, tick: null, puertoTrasero: () => new THREE.Vector3(0, 0, -d - .002)};
  return g;
}

/* posición en la cara frontal desde un punto en mm medido desde la esquina superior izquierda (mirando de frente) */
export const fx = (w, mm) => -w / 2 + mm * MM;
export const fy = (h, mm) => h / 2 - mm * MM;
/* posición en la cara trasera por fracciones (vista desde atrás: izquierda del observador = +x del equipo) */
export const trasera = (w, h, d, fracX, fracY, sobresale = .002) => new THREE.Vector3(w / 2 - fracX * w, h / 2 - fracY * h, -d - sobresale);

export function hexagonos(g, x, y, w, h, radio, relleno = '#08090b', trazo = '#2c2f36') {
  const dx = radio * 1.75, dy = radio * 1.52;
  g.save(); g.beginPath(); g.rect(x, y, w, h); g.clip();
  for (let j = 0, yy = y; yy < y + h + radio; j++, yy += dy) {
    for (let xx = x + (j % 2 ? dx / 2 : 0); xx < x + w + radio; xx += dx) {
      g.beginPath();
      for (let k = 0; k < 6; k++) { const a = Math.PI / 3 * k + Math.PI / 6; g.lineTo(xx + radio * .8 * Math.cos(a), yy + radio * .8 * Math.sin(a)); }
      g.closePath(); g.fillStyle = relleno; g.fill(); g.strokeStyle = trazo; g.lineWidth = 1; g.stroke();
    }
  }
  g.restore();
}
export function rj45(color = 0x0a0b0d, activo = true) {            // conector RJ45 hembra: carcasa, cavidad y dos LED
  const g = new THREE.Group();
  const car = new THREE.Mesh(new THREE.BoxGeometry(.0146, .0138, .012), std(0x8d9299, .4, .85)); car.position.z = -.006; g.add(car);
  const cav = new THREE.Mesh(new THREE.PlaneGeometry(.0118, .0092), new THREE.MeshBasicMaterial({color: 0x040405})); cav.position.set(0, -.0006, .0004); g.add(cav);
  const l1 = led(activo ? 0x2bff6a : 0x1a1a1a, .0012); l1.position.set(-.0052, .0054, .0006);
  const l2 = led(activo ? 0xffb02e : 0x1a1a1a, .0012); l2.position.set(.0052, .0054, .0006);
  g.add(l1, l2); g.userData.leds = [l1, l2]; return g;
}

/* ============ Rack de 42U ============ */
export function crearRack(unidades = 42) {
  const W = .6, D = 1.07, BASE = .105, ALTO_U = unidades * U, TECHO = .04;
  const H = BASE + ALTO_U + TECHO, zf = D / 2 - .06, zRiel = zf - .75;
  const g = new THREE.Group();
  const acero = M.negroMetal();
  const caja = (a, b, c, x, y, z, m = acero) => { const q = new THREE.Mesh(new THREE.BoxGeometry(a, b, c), m); q.position.set(x, y, z); q.castShadow = q.receiveShadow = true; g.add(q); return q; };

  // postes de esquina, marcos superior e inferior
  for (const sx of [-1, 1]) for (const sz of [-1, 1]) caja(.04, H - .02, .04, sx * (W / 2 - .02), H / 2, sz * (D / 2 - .02));
  for (const sx of [-1, 1]) { caja(.03, .03, D, sx * (W / 2 - .015), BASE + ALTO_U + .015 + .01, 0); caja(.03, .05, D, sx * (W / 2 - .015), BASE - .01, 0); }
  caja(W, .03, .03, 0, BASE + ALTO_U + .025, D / 2 - .015); caja(W, .03, .03, 0, BASE + ALTO_U + .025, -D / 2 + .015);
  caja(W, .05, .03, 0, BASE - .01, D / 2 - .015); caja(W, .05, .03, 0, BASE - .01, -D / 2 + .015);
  caja(W, .02, D, 0, H - .01, 0, std(0x101216, .5, .6));                                     // techo

  // ventiladores del techo (dibujados) y ranura de entrada de cables
  const techoTex = una('rack|techoTex|' + unidades, () => lienzo(512, 1024, (c) => {
    c.fillStyle = '#101216'; c.fillRect(0, 0, 512, 1024);
    [250, 700].forEach(y => { c.fillStyle = '#050506'; c.beginPath(); c.arc(256, y, 150, 0, 7); c.fill(); c.strokeStyle = '#3b3e46'; c.lineWidth = 5; for (let r = 1; r < 6; r++) { c.beginPath(); c.arc(256, y, 150 * r / 6, 0, 7); c.stroke(); } for (let k = 0; k < 7; k++) { c.beginPath(); c.moveTo(256, y); c.lineTo(256 + 140 * Math.cos(k * .9), y + 140 * Math.sin(k * .9)); c.stroke(); } });
  }));
  const tp = new THREE.Mesh(new THREE.PlaneGeometry(W - .02, D - .02), std(0xffffff, .5, .5, {map: techoTex})); tp.rotation.x = -Math.PI / 2; tp.position.set(0, H + .0002, 0); g.add(tp);

  // rieles de montaje con orificios y numeración de U
  const texRiel = una('rack|texRiel|' + unidades, () => lienzo(64, unidades * 64, (c, w, h) => {
    c.fillStyle = '#2a2d33'; c.fillRect(0, 0, w, h);
    for (let i = 0; i < unidades; i++) {
      const yTop = h - (i + 1) * 64;
      [9.1, 32, 54.9].forEach(o => { c.fillStyle = '#030304'; c.fillRect(16, yTop + 64 - o - 7, 14, 14); c.strokeStyle = '#4b4f58'; c.lineWidth = 1.5; c.strokeRect(15, yTop + 64 - o - 8, 16, 16); });
      c.fillStyle = '#c7cad1'; c.font = '600 13px Inter, Arial'; c.textAlign = 'center'; c.fillText(String(i + 1), 49, yTop + 38);
      c.fillStyle = '#4b4f58'; c.fillRect(0, yTop, w, 1);
    }
  }));
  const matRiel = std(0xffffff, .5, .6, {map: texRiel, side: THREE.DoubleSide});
  [[-1, zf - .0007], [1, zf - .0007], [-1, zRiel], [1, zRiel]].forEach(([sx, z]) => {
    const r = new THREE.Mesh(new THREE.PlaneGeometry(.025, ALTO_U), matRiel); r.position.set(sx * .2379, BASE + ALTO_U / 2, z); g.add(r);
    caja(.003, ALTO_U, .04, sx * .2254, BASE + ALTO_U / 2, z - .02, std(0x1b1d22, .5, .6));
  });

  // base con ruedas
  for (const sx of [-1, 1]) for (const sz of [-1, 1]) {
    const rueda = new THREE.Mesh(new THREE.CylinderGeometry(.03, .03, .022, 20), std(0x0a0a0c, .8, .1)); rueda.rotation.z = Math.PI / 2; rueda.position.set(sx * (W / 2 - .05), .03, sz * (D / 2 - .08)); rueda.castShadow = true; g.add(rueda);
    caja(.04, .05, .05, sx * (W / 2 - .05), .07, sz * (D / 2 - .08), std(0x1a1c21, .5, .6));
  }

  // PDU verticales con tomas C13 y su cable de alimentación
  const pduTex = una('rack|pduTex|' + unidades, () => lienzo(128, 1024, (c) => {
    c.fillStyle = '#17181c'; c.fillRect(0, 0, 128, 1024);
    for (let i = 0; i < 12; i++) { const y = 40 + i * 78; c.fillStyle = '#050506'; c.beginPath(); c.roundRect(30, y, 68, 52, 6); c.fill(); c.fillStyle = '#2b2e35'; [[44, 12], [64, 12], [84, 12]].forEach(([a, b]) => c.fillRect(a, y + 8 + (a === 64 ? 14 : 0), 6, 14)); }
    c.fillStyle = '#2bff6a'; c.beginPath(); c.arc(64, 14, 7, 0, 7); c.fill();
  }));
  for (const sx of [-1, 1]) {
    const pdu = caja(.05, 1.5, .06, sx * (W / 2 - .06), BASE + .1 + .75, -D / 2 + .08, std(0x15171b, .5, .5));
    const pp = new THREE.Mesh(new THREE.PlaneGeometry(.045, 1.45), std(0xffffff, .5, .3, {map: pduTex})); pp.rotation.y = Math.PI; pp.position.set(sx * (W / 2 - .06), BASE + .1 + .75, -D / 2 + .08 - .0305); g.add(pp);
    const cable = new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3([
      new THREE.Vector3(sx * (W / 2 - .06), BASE + .1, -D / 2 + .08), new THREE.Vector3(sx * (W / 2 - .06), BASE + .02, -D / 2 + .1),
      new THREE.Vector3(sx * (W / 2 + .05), .01, -D / 2 - .12), new THREE.Vector3(sx * (W / 2 + .3), .01, -D / 2 - .5)]), 32, .008, 8), std(0x0b0b0d, .7, .1));
    g.add(cable);
  }

  // tornillos de jaula (instanciados) en las orejas de cada equipo
  let tornillos = null;
  function colocarTornillos(lista) {
    if (tornillos) g.remove(tornillos);
    const pts = []; lista.forEach(({u0, u1}) => { for (const sx of [-1, 1]) { pts.push([sx * .2326, BASE + (u0 - 1) * U + 6.35 * MM]); pts.push([sx * .2326, BASE + u1 * U - 6.35 * MM]); } });
    tornillos = new THREE.InstancedMesh(new THREE.CylinderGeometry(.0048, .0048, .003, 12), std(0xcfd2d8, .35, .9), pts.length);
    const m = new THREE.Matrix4().makeRotationX(Math.PI / 2);
    pts.forEach(([x, y], i) => { m.setPosition(x, y, zf + .0034); tornillos.setMatrixAt(i, m); });
    tornillos.castShadow = true; g.add(tornillos);
  }

  // paneles laterales y puerta perforada con bisagra
  const paneles = new THREE.Group(); g.add(paneles); paneles.visible = false;
  const ventTex = una('rack|ventTex', () => lienzo(512, 1024, (c) => { c.fillStyle = '#16181d'; c.fillRect(0, 0, 512, 1024); for (let i = 0; i < 22; i++) { c.fillStyle = '#050506'; c.fillRect(120, 90 + i * 40, 270, 10); } }));
  for (const sx of [-1, 1]) { const pa = new THREE.Mesh(new THREE.BoxGeometry(.012, H - .15, D - .06), std(0xffffff, .5, .5, {map: ventTex})); pa.position.set(sx * (W / 2 + .004), (H - .15) / 2 + .1, 0); paneles.add(pa); }
  const puertaTex = una('rack|puertaTex|' + unidades, () => lienzo(1024, 2048, (c) => {
    c.fillStyle = '#1c1e24'; c.fillRect(0, 0, 1024, 2048);
    c.globalCompositeOperation = 'destination-out';
    const r = 9, dx = r * 2.1, dy = r * 1.82;
    for (let j = 0, y = 60; y < 2000; j++, y += dy) for (let x = 60 + (j % 2 ? dx / 2 : 0); x < 970; x += dx) { c.beginPath(); c.arc(x, y, r, 0, 7); c.fill(); }
    c.globalCompositeOperation = 'source-over';
  }));
  const puerta = new THREE.Group(); puerta.position.set(-W / 2 + .004, BASE + (H - BASE) / 2 - .02, zf + .05); g.add(puerta);
  const hoja = new THREE.Mesh(new THREE.PlaneGeometry(W - .02, H - BASE - .03), new THREE.MeshStandardMaterial({color: 0x1c1e24, map: puertaTex, alphaTest: .5, side: THREE.DoubleSide, metalness: .6, roughness: .5}));
  hoja.position.x = (W - .02) / 2; hoja.castShadow = true; puerta.add(hoja);
  const marco = new THREE.Mesh(new THREE.BoxGeometry(.018, H - BASE - .03, .03), acero); marco.position.x = (W - .02) - .009; puerta.add(marco);
  const manija = new THREE.Mesh(new THREE.BoxGeometry(.02, .12, .02), std(0xb9bec6, .35, .9)); manija.position.set(W - .045, 0, .025); puerta.add(manija);
  const aperturas = {actual: -1.9, objetivo: -1.9};
  puerta.rotation.y = aperturas.actual;
  function setPuerta(abierta) { aperturas.objetivo = abierta ? -1.9 : 0; }
  function setPaneles(v) { paneles.visible = v; }
  function tick() { aperturas.actual += (aperturas.objetivo - aperturas.actual) * .12; puerta.rotation.y = aperturas.actual; }

  return {group: g, base: BASE, zFrente: zf, profundidad: D, ancho: W, alto: H, colocarTornillos, setPuerta, setPaneles, tick};
}
