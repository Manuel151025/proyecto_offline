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
