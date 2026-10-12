/*
 * Comportamiento mínimo del panel de administración.
 *
 * El panel funciona entero sin JavaScript. Esto solo añade la confirmación
 * antes de las acciones destructivas, evita el doble envío y permite ver la
 * contraseña mientras se escribe.
 */
(function () {
  'use strict';

  // Antes la confirmación iba en un onsubmit con el nombre de la persona
  // metido dentro de una cadena JavaScript. Un nombre con salto de línea (la
  // API lo acepta) rompía esa cadena, el script fallaba y el formulario se
  // enviaba SIN preguntar. Leído de un atributo data-, el texto nunca llega a
  // interpretarse como código.
  document.addEventListener('submit', function (evento) {
    var form = evento.target;
    if (!(form instanceof HTMLFormElement)) return;
    // La búsqueda en vivo cancela el envío: no hay recarga, no hay doble envío.
    if (evento.defaultPrevented) return;

    var mensaje = form.getAttribute('data-confirmar');
    if (mensaje && !window.confirm(mensaje)) {
      evento.preventDefault();
      return;
    }

    // Un doble clic mandaba el mismo POST dos veces mientras la página recargaba.
    form.querySelectorAll('button[type="submit"]').forEach(function (boton) {
      boton.disabled = true;
    });
  });

  // Al volver con "Atrás" el navegador puede restaurar la página tal como
  // quedó, con los botones todavía deshabilitados.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('button[type="submit"][disabled]').forEach(function (boton) {
      boton.disabled = false;
    });
  });

  // Búsqueda de personas mientras se escribe. Sin JavaScript sigue
  // funcionando el botón «Buscar»; con él, a los 250 ms de dejar de escribir
  // se pide la misma página y se cambian solo los resultados y el enlace de
  // exportar (que lleva la búsqueda). La dirección se actualiza, así que
  // recargar o compartir el enlace muestra lo mismo.
  var campoBusqueda = document.getElementById('buscar');
  if (campoBusqueda && document.getElementById('resultados-personas')) {
    var temporizador = null;
    var ultimoPedido = 0;
    var buscar = function () {
      var parametros = new URLSearchParams(window.location.search);
      var texto = campoBusqueda.value.trim();
      parametros.set('seccion', 'personas');
      if (texto) parametros.set('q', texto); else parametros.delete('q');
      parametros.delete('p');
      var url = 'index.php?' + parametros.toString();
      var pedido = ++ultimoPedido;
      var zona = document.getElementById('resultados-personas');
      if (zona) zona.setAttribute('aria-busy', 'true');
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error(String(r.status))); })
        .then(function (html) {
          if (pedido !== ultimoPedido) return; // llegó una respuesta vieja
          var pagina = new DOMParser().parseFromString(html, 'text/html');
          var nuevos = pagina.getElementById('resultados-personas');
          if (!nuevos) { window.location.href = url; return; } // p. ej. la sesión venció
          ['resultados-personas', 'exportar-personas'].forEach(function (id) {
            var viejo = document.getElementById(id);
            var nuevo = pagina.getElementById(id);
            if (viejo && nuevo) viejo.replaceWith(nuevo);
          });
          window.history.replaceState(null, '', url);
        })
        .catch(function () { /* sin red: queda el botón Buscar */ })
        .finally(function () {
          var actual = document.getElementById('resultados-personas');
          if (actual && pedido === ultimoPedido) actual.removeAttribute('aria-busy');
        });
    };
    campoBusqueda.addEventListener('input', function () {
      clearTimeout(temporizador);
      temporizador = setTimeout(buscar, 250);
    });
    // Enter busca al instante, sin recargar la página.
    campoBusqueda.form.addEventListener('submit', function (evento) {
      evento.preventDefault();
      clearTimeout(temporizador);
      buscar();
    });
  }

  // El botón nace oculto: sin JavaScript no haría nada.
  document.querySelectorAll('[data-ver-clave]').forEach(function (boton) {
    var campo = document.getElementById(boton.getAttribute('data-ver-clave'));
    if (!campo) return;

    boton.hidden = false;
    boton.addEventListener('click', function () {
      var mostrar = campo.type === 'password';
      campo.type = mostrar ? 'text' : 'password';
      boton.textContent = mostrar ? 'Ocultar' : 'Ver';
      boton.setAttribute('aria-pressed', String(mostrar));
    });
  });
})();
