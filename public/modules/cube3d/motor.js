/* Motor genérico de equipos: dibuja cualquier equipo a partir de una ficha JSON (carpeta fichas/).
 *
 *   cargarCatalogo()          -> lee fichas/catalogo.json y todas las fichas
 *   elegirFicha(cat, equipo)  -> decide qué ficha usar y devuelve avisos de datos por completar
 *   construirEquipo(ficha, e) -> THREE.Group con el contrato de userData que usa el visor:
 *        w, h, d, halo/haloMat/haloFill (resalte de alertas), tipo, cara ('trasera'|'frontal'),
 *        puertos {n: malla} (si tiene puertos al frente), puertoTrasero(conector, repetido) -> Vector3 local,
 *        establecerEnlaces(mapa) (LED de enlace por puerto), tick(t)
 *
 * Las fichas describen CARACTERÍSTICAS (alto, bahías, puertos, fuentes, acabado), nunca marcas ni logotipos.
 * La especificación completa del formato está en ESPECIFICACION-FICHAS-DE-EQUIPOS.md.
 */
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { U, ANCHO_19, lienzo, std, M, cuerpo, plano, led, base, fx, fy, trasera, hexagonos, rj45 } from './modelos.js';

const MM = .001;
let PXC = 2048;                                   // resolución de las caras; baja cuando hay muchos racks para cuidar la memoria
export const fijarCalidad = n => { PXC = n; };
const ACABADOS = {
  negro:    {frente: '#111214', oreja: '#26282d', texto: '#d9dce1', portadora: '#1b1c20', receso: '#020203', vent: '#07080a', ventTrazo: '#33363d'},
  grafito:  {frente: '#2a2d33', oreja: '#3a3d44', texto: '#e5e7eb', portadora: '#383b42', receso: '#0b0c0e', vent: '#0c0d10', ventTrazo: '#4a4e57'},
  plateado: {frente: '#aeb3ba', oreja: '#8b9098', texto: '#1c1d20', portadora: '#c4c8ce', receso: '#2a2c31', vent: '#3a3d44', ventTrazo: '#868b94'},
};

/* ============ catálogo y elección de ficha ============ */
export async function cargarCatalogo(carpeta = 'fichas/') {
  const cat = await (await fetch(carpeta + 'catalogo.json')).json();
  const fichas = new Map();
  await Promise.all(cat.fichas.map(async id => fichas.set(id, await (await fetch(`${carpeta}${id}.json`)).json())));
  return {...cat, fichas};
}
const mezclar = (a, b) => {
  for (const k of Object.keys(b || {})) {
    if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && a[k] && typeof a[k] === 'object') mezclar(a[k], b[k]); else a[k] = b[k];
  }
  return a;
};

/* Orden: ficha indicada en el equipo > asignación manual por nombre > regla por modelo > ficha genérica por tipo y alto > equipo genérico */
export function elegirFicha(cat, e) {
  const avisos = []; let id = null, ajustes = null;
  const asig = cat.asignaciones?.[e.nombre.toLowerCase()];
  if (e.ficha) id = e.ficha;
  else if (asig?.ficha) { id = asig.ficha; ajustes = asig.ajustes; }
  if (!id) for (const r of cat.reglas || []) if (e.modelo && new RegExp(r.modelo, 'i').test(e.modelo)) { id = r.ficha; break; }
  if (!id) {
    id = cat.por_tipo?.[e.tipo]?.[String(e.alto)] || null;
    if (id) avisos.push(`Sin ficha para el modelo "${e.modelo || 'sin modelo'}": se usó una ficha genérica de ${e.alto}U (${cat.fichas.get(id).nombre}).`);
  }
  if (!id) {
    id = cat.generica;
    avisos.push(`Sin ficha para el modelo "${e.modelo || 'sin modelo'}"${e.alto ? '' : ' ni alto en U'}: se dibuja una caja genérica.`);
  }
  const ficha = structuredClone(cat.fichas.get(id));
  if (ajustes) mezclar(ficha, ajustes);
  if (!e.alto && !ficha.alto_u) avisos.push('El inventario no define el alto en U: se supuso 1U.');
  else if (e.alto && ficha.alto_u && e.alto !== ficha.alto_u) avisos.push(`El inventario indica ${e.alto}U pero la ficha "${ficha.nombre}" es de ${ficha.alto_u}U.`);
  if (e.alto_origen === 'tabla') avisos.push('El alto en U no está en el modelo del inventario; se tomó de una tabla local.');
  (ficha.supuestos || []).forEach(s => avisos.push(s));
  return {ficha, id, avisos};
}
export const altoDe = (ficha, e) => ficha.alto_u || e.alto || 1;

/* ============ utilidades ============ */
function dims(f, e, opc) {
  if (f.montaje === 'sobremesa') { const [w, h, d] = f.dimensiones_mm; return {w: w * MM, h: h * MM, d: d * MM, wCuerpo: w * MM}; }
  const alto = altoDe(f, e);
  if (f.medio_ancho) return {w: opc.anchoMedio, h: alto * U - .003, d: (f.profundidad_mm || 400) * MM, wCuerpo: opc.anchoMedio};
  return {w: ANCHO_19, h: alto * U - .0016, d: (f.profundidad_mm || 400) * MM, wCuerpo: (f.ancho_chasis_mm || 447) * MM};
}
const cara = (w, h, fn) => { const k = PXC / (w * 1000); return lienzo(PXC, Math.max(32, Math.round(PXC * h / w)), (c, cw, ch) => fn(c, v => v * k, cw, ch)); };
const matCuerpo = (f) => {
  const c = f.cuerpo || 'chapa';
  return c === 'chapa' ? M.chapa() : c === 'negro' ? std(0x16181d, .5, .65) : std(new THREE.Color(c), .5, .6);
};
function fondo(c, p, cw, ch, color) {
  c.fillStyle = color; c.fillRect(0, 0, cw, ch);
  const g = c.createLinearGradient(0, 0, 0, ch); g.addColorStop(0, 'rgba(255,255,255,.05)'); g.addColorStop(1, 'rgba(0,0,0,.28)');
  c.fillStyle = g; c.fillRect(0, 0, cw, ch);
}
function textoVertical(c, p, texto, x, y, mm, color) {
  c.save(); c.translate(p(x), p(y)); c.rotate(-Math.PI / 2); c.fillStyle = color; c.font = `600 ${p(mm)}px Inter, Arial`; c.fillText(texto, 0, 0); c.restore();
}
const vga = (c, p, x, y, color) => { c.strokeStyle = '#9aa0a8'; c.lineWidth = 2; c.beginPath(); c.moveTo(p(x), p(y)); c.lineTo(p(x + 11), p(y)); c.lineTo(p(x + 9.5), p(y + 8)); c.lineTo(p(x + 1.5), p(y + 8)); c.closePath(); c.fillStyle = '#05060a'; c.fill(); c.stroke(); };
const usb = (c, p, x, y) => { c.fillStyle = '#05060a'; c.fillRect(p(x), p(y), p(8), p(4.5)); c.strokeStyle = '#7d828b'; c.lineWidth = 1.5; c.strokeRect(p(x), p(y), p(8), p(4.5)); };

/* ============ portadoras de disco ============ */
const memo = new Map();
const memoriza = (k, f) => { if (!memo.has(k)) memo.set(k, f()); return memo.get(k); };
function texPortadora(formato, orient, wmm, hmm, ac) {
  const clave = [formato, orient, wmm.toFixed(1), hmm.toFixed(1), ac.portadora].join('|');
  if (memo.has(clave)) return memo.get(clave);
  const k = 8, W = Math.round(wmm * k), H = Math.round(hmm * k);
  const t = lienzo(W, H, (c) => {
    c.fillStyle = ac.portadora; c.fillRect(0, 0, W, H);
    if (orient === 'vertical') {
      hexagonos(c, W * .12, H * .05, W * .76, H * .6, Math.max(5, W * .09), ac.vent, ac.ventTrazo);
      c.fillStyle = 'rgba(0,0,0,.35)'; c.beginPath(); c.roundRect(W * .08, H * .7, W * .84, H * .24, 6); c.fill();
      c.fillStyle = '#dcdfe3'; c.fillRect(W * .2, H * .66, W * .6, H * .03);
    } else {
      hexagonos(c, W * .02, H * .13, W * .62, H * .74, Math.max(6, H * .1), ac.vent, ac.ventTrazo);
      c.fillStyle = 'rgba(255,255,255,.07)'; c.beginPath(); c.roundRect(W * .66, H * .08, W * .32, H * .84, 10); c.fill();
      c.fillStyle = 'rgba(0,0,0,.45)'; c.beginPath(); c.roundRect(W * .7, H * .58, W * .24, H * .26, 8); c.fill();
      c.fillStyle = '#dcdfe3'; c.fillRect(W * .7, H * .16, W * .24, H * .26);
      c.fillStyle = '#666'; for (let i = 0; i < 3; i++) c.fillRect(W * .72, H * (.2 + i * .07), W * (.18 - i * .04), Math.max(2, H * .02));
    }
  });
  memo.set(clave, t); return t;
}

/* ============ FRENTE: bahías de disco ============ */
function frenteBahias(g, f, D, ac, e) {
  const fr = f.frente, B = fr.bahias, R = fr.region, wmm = D.w * 1000, hmm = D.h * 1000;
  const grupo = B.grupo_columnas || B.columnas, nGrupos = Math.ceil(B.columnas / grupo), sep = B.separacion_mm || 0, gap = B.holgura_mm ?? 1.2;
  const cw = (R.ancho_mm - sep * (nGrupos - 1)) / B.columnas, ch = R.alto_mm / B.filas;
  const celda = (r, c) => ({x: R.x_mm + c * cw + Math.floor(c / grupo) * sep, y: R.y_mm + r * ch});
  const total = B.filas * B.columnas, ocup = B.ocupadas === undefined || B.ocupadas === 'todas' ? total : Math.min(total, B.ocupadas);
  const oi = fr.oreja_izq || {ancho_mm: 0}, od = fr.oreja_der || {ancho_mm: 0};
  const frente = cara(D.w, D.h, (c, p, W, H) => {
    fondo(c, p, W, H, ac.frente);
    if (oi.ancho_mm) {
      c.fillStyle = ac.oreja; c.fillRect(0, 0, p(oi.ancho_mm), H);
      if (oi.vga) vga(c, p, 3.5, 18);
      if (oi.texto_vertical) textoVertical(c, p, e.nombre.toUpperCase().slice(0, 14), oi.ancho_mm / 2 + 2.2, hmm - 6, Math.min(5, oi.ancho_mm * .26), ac.texto);
    }
    if (od.ancho_mm) {
      const x0 = wmm - od.ancho_mm; c.fillStyle = ac.oreja; c.fillRect(p(x0), 0, p(od.ancho_mm), H);
      if (od.panel === 'control') {
        const ancho = od.ancho_mm >= 40, bx = ancho ? x0 + 12 : x0 + od.ancho_mm / 2, by = ancho ? hmm * .36 : 20;
        const ux = ancho ? x0 + 26 : x0 + od.ancho_mm / 2 - 4, uy = ancho ? [hmm * .2, hmm * .55] : [50, 60];
        for (let i = 0; i < (od.usb || 0); i++) usb(c, p, ux, uy[i] ?? uy[0] + i * 10);
        if (od.etiqueta) { c.fillStyle = '#d4d7dc'; c.fillRect(p(x0 + 4.4), p(hmm - 19), p(18), p(12)); c.fillStyle = '#222'; for (let i = 0; i < 3; i++) c.fillRect(p(x0 + 6.4), p(hmm - 17 + i * 3.2), p(14 - i * 3), p(1)); }
        g.userData._panel = {x: bx, y: by};
      }
    }
    if (fr.rejilla) hexagonos(c, p(fr.rejilla.x_mm), p(R.y_mm), p(fr.rejilla.ancho_mm), p(R.alto_mm), 9, ac.vent, ac.ventTrazo);
    for (let r = 0; r < B.filas; r++) for (let col = 0; col < B.columnas; col++) {        // recesos
      const q = celda(r, col); c.fillStyle = ac.receso; c.fillRect(p(q.x), p(q.y), p(cw - .2), p(ch - .2));
    }
    c.fillStyle = '#c4161c';
    if (B.acento_columnas) for (let col = 0; col < B.columnas; col++) c.fillRect(p(celda(0, col).x), p(R.y_mm), p(1.4), p(R.alto_mm));
    if (B.acento_superior) for (let col = 0; col < B.columnas; col++) c.fillRect(p(celda(0, col).x + 3), p(R.y_mm), p(Math.max(4, cw - 6)), p(2));
    for (let i = 0; i < (oi.leds || 0); i++) { c.fillStyle = i === 0 ? '#2bff6a' : '#202226'; c.beginPath(); c.arc(p(oi.ancho_mm / 2), p(14 + i * 8), p(1.6), 0, 7); c.fill(); }
  });
  g.add(plano(D.w, D.h, frente, {rough: .5, metal: .3}));

  const matPort = std(0xffffff, .42, .15, {map: texPortadora(B.formato, B.orientacion, cw - gap, ch - gap, ac)});
  const ledsDisco = [];
  for (let i = 0; i < ocup; i++) {
    const r = Math.floor(i / B.columnas), col = i % B.columnas, q = celda(r, col);
    const p = new THREE.Mesh(new RoundedBoxGeometry((cw - gap) * MM, (ch - gap) * MM, .014, 2, .0012), matPort);
    p.position.set(fx(D.w, q.x + cw / 2 - .1), fy(D.h, q.y + ch / 2 - .1), 0); p.castShadow = true; g.add(p);
    const l = led(0x2bff6a, .0012);
    if (B.orientacion === 'vertical') l.position.set(fx(D.w, q.x + cw / 2), fy(D.h, q.y + 4), .0075);
    else l.position.set(fx(D.w, q.x + cw - 4), fy(D.h, q.y + 3.6), .0075);
    g.add(l); ledsDisco.push(l);
  }
  if (g.userData._panel) {
    const {x, y} = g.userData._panel;
    const boton = new THREE.Mesh(new THREE.CylinderGeometry(.0042, .0042, .004, 20), std(0x2a2c31, .4, .6)); boton.rotation.x = Math.PI / 2; boton.position.set(fx(D.w, x), fy(D.h, y), .002); g.add(boton);
    const anillo = new THREE.Mesh(new THREE.RingGeometry(.0034, .0046, 24), new THREE.MeshBasicMaterial({color: new THREE.Color(0x2bff6a).multiplyScalar(2.2), toneMapped: false}));
    anillo.position.set(fx(D.w, x), fy(D.h, y), .0042); g.add(anillo);
    const idl = led(0x2f7bff, .0013); idl.position.set(fx(D.w, x), fy(D.h, y + 11), .0008); g.add(idl);
    delete g.userData._panel;
  }
  [oi.ancho_mm ? fx(D.w, 5) : null, od.ancho_mm ? fx(D.w, wmm - 5) : null].forEach(x => {
    if (x === null) return; const a = new THREE.Mesh(new RoundedBoxGeometry(.006, Math.min(.02, D.h * .45), .008, 2, .001), std(0x2b2d33, .45, .4)); a.position.set(x, fy(D.h, hmm * .5), .003); a.castShadow = true; g.add(a);
  });
  g.userData.tick = (t) => ledsDisco.forEach((l, i) => { l.visible = Math.sin(t * 9 + i * 2.7) > -.6; });
}

/* ============ FRENTE: puertos (switch, firewall, patchera) ============ */
function frentePuertos(g, f, D, ac, e) {
  const P = f.frente.puertos, R = P.rj45, X = P.extra, wmm = D.w * 1000, hmm = D.h * 1000, ore = f.frente.orejas_mm || 0;
  const patch = f.frente.estilo === 'patchera';
  const filas = R.filas || 1, cols = Math.ceil(R.cantidad / filas), paso = R.paso_mm;
  const yF = R.y_mm || (filas === 2 ? [hmm * .3, hmm * .68] : [hmm * .5]);
  const pw = Math.min(paso * .9, patch ? 15 : 12.4), ph = patch ? 14 : Math.min(hmm * (filas === 2 ? .25 : .4), 10.8);
  const numeracion = R.numeracion || (filas === 2 ? 'alterna' : 'secuencial');
  const pos = {};
  for (let n = 1; n <= R.cantidad; n++) {
    const r = numeracion === 'alterna' ? (n - 1) % filas : Math.floor((n - 1) / cols), c = numeracion === 'alterna' ? Math.floor((n - 1) / filas) : (n - 1) % cols;
    pos[n] = {x: R.x_ini_mm + c * paso, y: yF[r], w: pw, h: ph, fila: r, extra: false};
  }
  if (X) for (let i = 0; i < X.cantidad; i++) {
    const dos = (X.filas || 1) === 2, r = dos ? i % 2 : 0, c = dos ? Math.floor(i / 2) : i;
    pos[R.cantidad + i + 1] = {x: X.x_ini_mm + c * X.paso_mm, y: (X.y_mm || [hmm / 2])[r], w: 12.4, h: Math.min(ph, 10.8), fila: r, extra: true};
  }
  const cant = Object.keys(pos).length;
  const frente = cara(D.w, D.h, (c, p, W, H) => {
    c.fillStyle = ore ? '#9ea3ab' : ac.frente; c.fillRect(0, 0, W, H);
    if (ore) { c.fillStyle = ac.frente; c.fillRect(p(ore), 0, p(wmm - 2 * ore), H); c.fillStyle = '#05060a'; c.fillRect(p(ore / 3), p(hmm / 2 - 5.5), p(7), p(11)); c.fillRect(p(wmm - ore / 3 - 7), p(hmm / 2 - 5.5), p(7), p(11)); }
    else fondo(c, p, W, H, ac.frente);
    const A = P.area_izq;
    if (A) {
      if (A.barras) { c.fillStyle = ac.texto; [4, 7, 10, 7, 4, 10, 7, 4].forEach((a, i) => c.fillRect(p(A.x_mm + i * 3.4), p(9 - a / 2), p(1.6), p(a))); }
      c.fillStyle = ac.texto; c.font = `600 ${p(Math.min(5.5, hmm * .13))}px Inter, Arial`; c.fillText(e.nombre.toUpperCase().slice(0, 12), p(A.x_mm), p(A.barras ? 21 : Math.min(hmm * .5, 17)));
      if (P.leds && hmm > 40) { c.fillStyle = '#9aa0a8'; c.font = `${p(1.9)}px Inter, Arial`; c.fillText('SYS', p(A.x_mm), p(29.6)); }
    }
    c.font = `${p(patch ? 2.4 : 2)}px Inter, Arial`; c.textAlign = 'center';
    for (const [n, q] of Object.entries(pos)) {
      const num = +n; c.fillStyle = patch ? '#c7cad1' : '#7d828b';
      const arriba = patch || filas === 1 || q.fila === 0 || q.extra && !(X.filas === 2 && q.fila === 1);
      c.fillText(String(num), p(q.x), arriba ? p(q.y - q.h / 2 - 1.6) : p(q.y + q.h / 2 + 3.2));
      c.fillStyle = patch ? '#050506' : '#030304'; c.fillRect(p(q.x - q.w / 2 - .4), p(q.y - q.h / 2 - .4), p(q.w + .8), p(q.h + .8));
      if (patch) { c.fillStyle = '#2b2e35'; c.fillRect(p(q.x - q.w / 2 + 1), p(q.y - q.h / 2 + 1), p(q.w - 2), p(q.h - 2)); c.fillStyle = '#050506'; c.fillRect(p(q.x - q.w / 2 + 3), p(q.y - q.h / 2 + 3), p(q.w - 6), p(q.h - 6)); }
    }
    c.textAlign = 'left';
  });
  g.add(plano(D.w, D.h, frente, {rough: .55, metal: .3}));
  g.userData.puertos = {}; const ledsPuerto = {};
  for (const [n, q] of Object.entries(pos)) {
    const caja = new THREE.Mesh(new THREE.BoxGeometry(q.w * MM * .95, q.h * MM * .85, patch ? .006 : .01), new THREE.MeshStandardMaterial({color: patch ? 0x1b1d22 : 0x0a0b0d, roughness: .5, metalness: .6, emissive: 0x000000}));
    caja.position.set(fx(D.w, q.x), fy(D.h, q.y), patch ? -.001 : -.0038); g.add(caja); g.userData.puertos[+n] = caja;
    if (P.leds && !patch) {
      const dor = new THREE.Mesh(new THREE.PlaneGeometry(q.w * MM * .7, .0014), std(0xc9a24a, .35, .9)); dor.position.set(fx(D.w, q.x), fy(D.h, q.y) - q.h * MM * .24, .0007); g.add(dor);
      const l = led(0x2bff6a, .00085); l.position.set(fx(D.w, q.x) - q.w * MM * .34, fy(D.h, q.y) + q.h * MM * .36, .0008); l.visible = false; g.add(l); ledsPuerto[+n] = l;
    }
    if (q.extra) { const sfp = new THREE.Mesh(new THREE.PlaneGeometry(.0112, .0046), new THREE.MeshBasicMaterial({color: 0x15161a})); sfp.position.set(fx(D.w, q.x), fy(D.h, q.y + q.h * .8), .0007); g.add(sfp); }
  }
  if (P.leds) {
    const A = P.area_izq; if (A && hmm > 40) { const s = led(0x2bff6a, .0012); s.position.set(fx(D.w, A.x_mm + 9), fy(D.h, 28.6), .0008); g.add(s); }
    let enlaces = {};
    g.userData.establecerEnlaces = (mapa) => { enlaces = mapa; for (let n = 1; n <= cant; n++) if (ledsPuerto[n]) ledsPuerto[n].visible = !!mapa['gi' + n]; };
    g.userData.tick = (t) => { for (let n = 1; n <= cant; n++) if (ledsPuerto[n] && enlaces['gi' + n]) ledsPuerto[n].visible = Math.sin(t * 8 + n * 1.9) > -.75; };
  }
}

/* ============ FRENTE: UPS ============ */
function frenteUps(g, f, D, ac) {
  const torre = f.frente.forma === 'torre';
  const cu = cuerpo(D.w, D.h, D.d, std(0x14151a, .4, .15), torre ? .006 : .002); g.add(cu);
  const PX = 1024, ch = Math.round(PX * D.h / D.w);
  const frente = lienzo(PX, ch, (c) => {
    const gr = c.createLinearGradient(0, 0, 0, ch); gr.addColorStop(0, '#1d1f25'); gr.addColorStop(1, '#0d0e11'); c.fillStyle = gr; c.fillRect(0, 0, PX, ch);
    if (f.frente.pantalla) {
      c.fillStyle = '#6b8f6b'; c.beginPath(); c.roundRect(PX * .14, ch * .16, PX * .72, ch * .3, 14); c.fill();
      c.fillStyle = '#1c2a1c'; c.font = `700 ${ch * .17}px Inter, Arial`; c.fillText('100%', PX * .3, ch * .38);
    }
    c.fillStyle = '#34363c'; [.2, .4, .6, .8].forEach(x => { c.beginPath(); c.arc(PX * x, ch * .68, ch * .045, 0, 7); c.fill(); });
  });
  g.add(plano(D.w * .98, D.h * .96, frente, {rough: .35, metal: .15, z: .0006}));
  const on = led(0x2bff6a, .0022); on.position.set(D.w * .35, -D.h * .26, .0012); g.add(on);
  if (torre) { const estante = new THREE.Mesh(new THREE.BoxGeometry(D.w + .01, .003, .46), std(0x2a2d33, .6, .7)); estante.position.set(0, -D.h / 2 - .0015, -.2); g.add(estante); }
}

/* ============ FRENTE: paneles pasivos ============ */
function frentePanel(g, f, D, ac, e) {
  const v = f.frente.variante, wmm = D.w * 1000, hmm = D.h * 1000;
  if (v === 'bandeja') {
    const m = std(0x23262c, .6, .65);
    const piso = new THREE.Mesh(new THREE.BoxGeometry(D.wCuerpo, .0025, D.d), m); piso.position.set(0, -D.h / 2 + .0013, -D.d / 2); piso.receiveShadow = true; g.add(piso);
    const labio = new THREE.Mesh(new THREE.BoxGeometry(D.wCuerpo, .014, .003), m); labio.position.set(0, -D.h / 2 + .007, .0015); g.add(labio);
    const orejas = new THREE.Mesh(new THREE.BoxGeometry(D.w, .003, .003), m); orejas.position.set(0, -D.h / 2 + .0015, .0015); g.add(orejas);
    return;
  }
  const frente = cara(D.w, D.h, (c, p, W, H) => {
    fondo(c, p, W, H, ac.frente);
    if (v === 'organizador') {
      const n = f.frente.ranuras || 6;
      for (let i = 0; i < n; i++) { const x = W * (.05 + i * (.9 / n)); c.fillStyle = '#050506'; c.beginPath(); c.roundRect(x, H * .16, W * (.9 / n - .03), H * .68, 18); c.fill(); }
      c.fillStyle = '#30333a'; c.fillRect(0, 0, W, 6); c.fillRect(0, H - 6, W, 6);
    } else if (v === 'cajon') {
      c.fillStyle = '#05060a'; c.beginPath(); c.roundRect(W * .3, H * .38, W * .4, H * .24, 20); c.fill(); c.strokeStyle = '#4a4e57'; c.lineWidth = 3; c.stroke();
      c.fillStyle = '#9aa0a8'; c.beginPath(); c.arc(W * .88, H * .5, H * .08, 0, 7); c.fill(); c.fillStyle = '#05060a'; c.fillRect(W * .875, H * .44, W * .01, H * .12);
    } else if (v === 'ciego') {
      c.fillStyle = 'rgba(255,255,255,.03)'; c.fillRect(W * .02, H * .1, W * .96, H * .8);
    } else {
      c.strokeStyle = '#4a4e57'; c.lineWidth = 3; c.strokeRect(p(22), 4, W - p(44), H - 8);
      c.fillStyle = ac.texto; c.font = `600 ${Math.min(H * .4, p(6))}px Inter, Arial`; c.fillText(e.nombre.toUpperCase().slice(0, 18), p(30), H * .6);
    }
  });
  g.add(plano(D.w, D.h, frente, {rough: .55, metal: .4}));
}

/* ============ FRENTE: chasis ATX ============ */
function frenteChasis(g, f, D, ac, e) {
  const wmm = D.w * 1000, hmm = D.h * 1000, ore = f.frente.orejas_mm || 20;
  const frente = cara(D.w, D.h, (c, p, W, H) => {
    fondo(c, p, W, H, ac.frente);
    c.fillStyle = ac.oreja; c.fillRect(0, 0, p(ore), H); c.fillRect(p(wmm - ore), 0, p(ore), H);
    for (let i = 0; i < (f.frente.bahias_5_25 || 0); i++) { c.fillStyle = '#0b0c0e'; c.fillRect(p(100), p(8 + i * 17), p(150), p(14)); c.strokeStyle = '#2f3238'; c.lineWidth = 2; c.strokeRect(p(100), p(8 + i * 17), p(150), p(14)); }
    if (f.frente.rejilla) { c.fillStyle = '#0a0b0d'; c.fillRect(p(30), p(70), p(wmm - 60), p(hmm - 78)); hexagonos(c, p(34), p(74), p(wmm - 68), p(hmm - 86), 9, ac.vent, ac.ventTrazo); }
    c.fillStyle = ac.texto; c.font = `600 ${p(3.4)}px Inter, Arial`; c.fillText(e.nombre.toUpperCase().slice(0, 16), p(280), p(60));
    c.fillStyle = '#05060a'; c.beginPath(); c.arc(p(wmm - 42), p(30), p(4), 0, 7); c.fill(); c.strokeStyle = '#7d828b'; c.lineWidth = 2; c.stroke();
  });
  g.add(plano(D.w, D.h, frente, {rough: .5, metal: .35}));
  const boton = new THREE.Mesh(new THREE.CylinderGeometry(.004, .004, .004, 20), std(0x2a2c31, .4, .6)); boton.rotation.x = Math.PI / 2; boton.position.set(fx(D.w, 36), fy(D.h, 18), .002); g.add(boton);
  const a = led(0x2bff6a, .0013); a.position.set(fx(D.w, 48), fy(D.h, 18), .0008); g.add(a);
  [fx(D.w, 5), fx(D.w, wmm - 5)].forEach(x => { const asa = new THREE.Mesh(new RoundedBoxGeometry(.007, .05, .01, 2, .001), std(0x2b2d33, .45, .4)); asa.position.set(x, 0, .004); g.add(asa); });
}

/* ============ PARTE TRASERA ============ */
const idPuerto = (T, conector) => { for (const a of T.alias || []) if (new RegExp(a.patron, 'i').test(conector)) return a.id; return T.defecto || T.puertos?.[0]?.id; };
function traseraEquipo(g, f, D) {
  const T = f.trasera || {estilo: 'plano'}, rw = D.wCuerpo, h = D.h, d = D.d;
  const tr = memoriza(`tr|${f.id}|${PXC}|${(rw * 1000).toFixed(0)}|${(h * 1000).toFixed(1)}`, () => cara(rw, h, (c, p, W, H) => {
    const X = q => q * W, Y = q => q * H;
    c.fillStyle = T.estilo === 'plano' ? '#2b2d33' : '#3d4046'; c.fillRect(0, 0, W, H);
    const psu = (a, b) => {
      c.fillStyle = '#c9ccd1'; c.fillRect(X(a), Y(.44), X(b - a), Y(.54));
      c.fillStyle = '#0c0d0f'; c.beginPath(); c.arc(X(a + (b - a) * .38), Y(.71), Y(.2), 0, 7); c.fill();
      c.strokeStyle = '#5a5e66'; c.lineWidth = 3; for (let r = 1; r < 5; r++) { c.beginPath(); c.arc(X(a + (b - a) * .38), Y(.71), Y(.2) * r / 5, 0, 7); c.stroke(); }
      c.fillStyle = '#e0841c'; c.fillRect(X(b - .018), Y(.5), X(.012), Y(.4)); c.fillStyle = '#16171a'; c.fillRect(X(b - .05), Y(.56), X(.024), Y(.3));
    };
    if (T.estilo === 'servidor') {
      hexagonos(c, X(.01), Y(.1), X(.19), Y(.75), 15, '#0a0b0d', '#2a2d33');
      if (T.pcie) { c.fillStyle = '#25272c'; c.fillRect(X(.21), Y(.08), X(.39), Y(.5)); for (let i = 0; i < 3; i++) { c.fillStyle = '#17181c'; c.fillRect(X(.215), Y(.1 + i * .15), X(.38), Y(.1)); hexagonos(c, X(.22), Y(.115 + i * .15), X(.37), Y(.07), 8, '#07080a', '#33363d'); } hexagonos(c, X(.62), Y(.06), X(.35), Y(.34), 13, '#0a0b0d', '#2a2d33'); }
      if (T.fuentes >= 1) psu(.79, .96); if (T.fuentes >= 2) psu(.6, .76);
      c.fillStyle = '#05060a'; c.fillRect(X(.535), Y(.52), X(.02), Y(.08)); c.fillRect(X(.535), Y(.64), X(.02), Y(.08));
      c.beginPath(); c.roundRect(X(.4), Y(.5), X(.05), Y(.17), 8); c.fill();
    } else if (T.estilo === 'almacenamiento') {
      [[0, .18], [.82, 1]].forEach(([a, b]) => { c.fillStyle = '#c9ccd1'; c.fillRect(X(a) + 4, 6, X(b - a) - 8, H - 12); c.fillStyle = '#0c0d0f'; c.beginPath(); c.arc(X((a + b) / 2), Y(.5), Y(.28), 0, 7); c.fill(); c.strokeStyle = '#5a5e66'; c.lineWidth = 3; for (let r = 1; r < 5; r++) { c.beginPath(); c.arc(X((a + b) / 2), Y(.5), Y(.28) * r / 5, 0, 7); c.stroke(); } });
      [[.2, .49], [.51, .8]].forEach(([a, b]) => { c.fillStyle = '#23252a'; c.fillRect(X(a), 6, X(b - a), H - 12); for (let i = 0; i < 4; i++) { c.fillStyle = '#050506'; c.fillRect(X(a + .02 + i * .04), Y(.55), X(.03), Y(.22)); } c.fillStyle = '#d9dce1'; c.font = `${Y(.12)}px Inter, Arial`; c.fillText(a < .4 ? 'A' : 'B', X(a + .01), Y(.2)); });
    } else if (T.estilo === 'chasis') {
      c.fillStyle = '#0c0d0f'; c.fillRect(X(.06), Y(.04), X(.28), Y(.36)); c.fillStyle = '#17181c'; c.fillRect(X(.065), Y(.05), X(.27), Y(.34));
      for (let i = 0; i < 6; i++) { c.fillStyle = '#0c0d0f'; c.fillRect(X(.06 + i * .047), Y(.46), X(.038), Y(.5)); }
      c.fillStyle = '#c9ccd1'; c.fillRect(X(.62), Y(.04), X(.33), Y(.3)); c.fillStyle = '#0c0d0f'; c.beginPath(); c.arc(X(.78), Y(.19), Y(.12), 0, 7); c.fill();
      hexagonos(c, X(.38), Y(.04), X(.2), Y(.36), 12, '#08090b', '#2a2d33'); c.fillStyle = '#17181c'; c.fillRect(X(.62), Y(.42), X(.33), Y(.5));
    } else {
      c.fillStyle = '#1c1e23'; for (let i = 0; i < 12; i++) c.fillRect(X(.06 + i * .05), Y(.2), X(.03), Y(.6));
      c.fillStyle = '#0c0d0f'; c.fillRect(X(.86), Y(.3), X(.1), Y(.4));
    }
  }));
  const rp = plano(rw, h, tr, {rough: .6, metal: .5, z: 0}); rp.rotation.y = Math.PI; rp.position.z = -d - .0006; g.add(rp);
  const REAR = {}; (T.puertos || []).forEach(pt => {
    REAR[pt.id] = pt;
    if (pt.tipo === 'rj45') { const r = rj45(); r.position.copy(trasera(rw, h, d, pt.fx, pt.fy, 0)); r.rotation.y = Math.PI; g.add(r); }
  });
  g.userData.puertoTrasero = (conector, rep = 0) => {
    const pt = REAR[idPuerto(T, conector)];
    return pt ? trasera(rw, h, d, pt.fx + rep * .035, pt.fy, .0025) : new THREE.Vector3(0, 0, -d - .002);
  };
  // Fuentes (PSU): puntos de alimentación en la cara trasera, calculados desde el mismo dibujo
  // de la ficha. Así la energía enchufa en la fuente y no en los puertos de red, para cualquier equipo.
  const F = [];
  const addF = (fx, fy) => F.push(trasera(rw, h, d, fx, fy, .0025));   // fx/fy = fracción del dibujo trasero (coincide con trasera())
  const nf = T.fuentes || 0;
  if (T.estilo === 'servidor') { if (nf >= 1) addF(.855, .71); if (nf >= 2) addF(.661, .71); }
  else if (T.estilo === 'almacenamiento') { addF(.09, .5); addF(.91, .5); }
  else if (T.estilo === 'chasis') { addF(.78, .19); }
  g.userData.fuentes = F;
  g.userData.puertoFuente = (idx = 0) => (F.length ? F[Math.min(idx, F.length - 1)].clone() : g.userData.puertoTrasero('power', 0));
}

/* ============ construcción ============ */
export function construirEquipo(f, e, opc = {}) {
  const D = dims(f, e, opc), ac = ACABADOS[f.acabado || 'negro'] || ACABADOS.negro;
  const g = base(e.nombre, D.w, D.h, D.d);
  Object.assign(g.userData, {tipo: f.tipo, ficha: f.id, cara: f.cara_conectores || 'trasera'});
  const t = f.frente?.tipo;
  if (!f.frente?.sin_cuerpo && t !== 'ups') g.add(cuerpo(D.wCuerpo, D.h, D.d, matCuerpo(f), .0012));
  if (t === 'bahias') frenteBahias(g, f, D, ac, e);
  else if (t === 'puertos') frentePuertos(g, f, D, ac, e);
  else if (t === 'ups') frenteUps(g, f, D, ac);
  else if (t === 'chasis') frenteChasis(g, f, D, ac, e);
  else frentePanel(g, f, D, ac, e);
  traseraEquipo(g, f, D);
  return g;
}
