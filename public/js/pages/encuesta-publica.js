/**
 * encuesta-publica.js: validación del formulario que responde el PACIENTE
 * (vista pública /publico/encuesta, la que se abre por QR o enlace).
 *
 * ¿Por qué no alcanza con required de HTML? Los radios usan nombres de
 * array PHP (name="respuestas[0]", "respuestas[1]"...) y viven dentro de
 * <label> estilizados; en algunos navegadores el mensaje nativo no agrupa
 * bien esos radios y queda confuso. Este script hace la validación a mano:
 *   - cada grupo de radios requerido debe tener una opción marcada;
 *   - cada textarea/texto requerido debe tener contenido.
 * Si falta algo, cancela el envío y resalta (con scroll) la primera
 * pregunta incompleta.
 */
(function () {
    'use strict';

    var formulario = document.getElementById('encuestaRespForm');
    if (!formulario) return; // la vista pública sin preguntas no arma form

    formulario.addEventListener('submit', function (e) {
        // Agrupa los radio requeridos por nombre y verifica que cada grupo
        // tenga una opción marcada (la validación HTML nativa no agrupa bien
        // radios con name="respuestas[0]" dentro de labels).
        var grupos = {};
        formulario.querySelectorAll('input[type="radio"]').forEach(function (r) {
            if (!r.required) return;
            if (!grupos[r.name]) {
                grupos[r.name] = { checked: false, primero: r }; // "primero" para poder enfocar/scroll
            }
            if (r.checked) grupos[r.name].checked = true;
        });

        // Nombres de grupo sin ninguna opción marcada.
        var faltantes = Object.keys(grupos).filter(function (name) {
            return !grupos[name].checked;
        });

        // Textareas y textos requeridos vacíos (pregunta de texto libre).
        var vacios = Array.prototype.filter.call(
            formulario.querySelectorAll('textarea[required], input[type="text"][required]'),
            function (el) { return el.value.trim() === ''; }
        );

        if (faltantes.length > 0 || vacios.length > 0) {
            e.preventDefault(); // frena el submit: PHP tampoco lo recibiría mal, pero así es más amable

            // Toma el primer elemento incompleto para llevar al usuario ahí.
            var primero = null;
            if (faltantes.length > 0) {
                primero = grupos[faltantes[0]].primero;
            } else {
                primero = vacios[0];
            }

            // Resalta el bloque .pregunta-publica contenedor: scroll suave +
            // borde rojo a la izquierda que se apaga solo a los 2,5s.
            var bloque = primero ? primero.closest('.pregunta-publica') : null;
            if (bloque) {
                bloque.scrollIntoView({ behavior: 'smooth', block: 'center' });
                bloque.style.borderLeft = '3px solid #DC3545';
                bloque.classList.add('ps-2');
                setTimeout(function () {
                    bloque.style.borderLeft = '';
                    bloque.classList.remove('ps-2');
                }, 2500);
            }
        }
    });
})();
