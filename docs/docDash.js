// docDash.js — navegación de la guía: scroll-spy del índice + menú lateral en móvil.
// Externo (no inline) para cumplir con CSP `script-src 'self'` en cualquier host.
(function () {
  var links = [].slice.call(document.querySelectorAll('.sb-link'));
  var secs = links.map(function (l) {
    var id = l.getAttribute('href').slice(1);
    return document.getElementById(id);
  }).filter(Boolean);
  function onScroll() {
    var y = window.scrollY + 120, cur = secs[0];
    for (var i = 0; i < secs.length; i++) { if (secs[i].offsetTop <= y) cur = secs[i]; }
    links.forEach(function (l) {
      l.classList.toggle('active', cur && l.getAttribute('href') === '#' + cur.id);
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  var mb = document.getElementById('menuBtn'), sc = document.getElementById('scrim');
  function close() { document.body.classList.remove('nav-open'); }
  if (mb) mb.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  if (sc) sc.addEventListener('click', close);
  document.querySelectorAll('.sb-link').forEach(function (l) { l.addEventListener('click', close); });
})();
