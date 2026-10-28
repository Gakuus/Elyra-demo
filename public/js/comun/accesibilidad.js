/**
 * Accesibilidad: panel de preferencias de todo el sitio.
 *
 * Las preferencias se guardan en localStorage y se aplican como atributos
 * data-acc-* en <html>. El CSS (public/css/accesibilidad.css) es el que
 * define los efectos; este archivo solo maneja estado, UI y lectura en voz
 * alta con la Web Speech API.
 */
(function () {
    'use strict';

    var root = document.getElementById('elyra-accesibilidad');
    if (!root) {
        return;
    }

    var html = document.documentElement;
    var boton = document.getElementById('accBoton');
    var panel = document.getElementById('accPanel');
    var cerrarBtn = document.getElementById('accCerrar');
    var resetBtn = document.getElementById('accReset');
    var nivel = document.getElementById('accTextoNivel');
    var estadoTexto = document.getElementById('accEstado');
    var guia = document.getElementById('accGuia');
    var notaVoz = document.getElementById('accVozNota');
    var leerPaginaBtn = document.getElementById('accLeerPagina');
    var leerSeleccionBtn = document.getElementById('accLeerSeleccion');
    var detenerBtn = document.getElementById('accDetener');

    var CLAVE = 'elyra_accesibilidad_v1';
    var ZOOMS = [1, 1.10, 1.25, 1.50, 1.75, 2.00];
    var COLORES = ['normal', 'contrast', 'invert', 'gray'];
    var INTERRUPTORES = ['spacing', 'font', 'links', 'headings', 'focus', 'cursor', 'guide', 'motion', 'images', 'clickRead'];
    var PREFERENCIAS = {
        text: 0,
        color: 'normal',
        spacing: false,
        font: false,
        links: false,
        headings: false,
        focus: false,
        cursor: false,
        guide: false,
        motion: false,
        images: false,
        clickRead: false
    };

    var synth = window.speechSynthesis;
    var soporteVoz = !!(synth && window.SpeechSynthesisUtterance);
    var estado = cargar();

    function normalizar(texto) {
        return String(texto == null ? '' : texto).replace(/\s+/g, ' ').trim();
    }

    function mensaje(nombre) {
        return root.getAttribute('data-msg-' + nombre) || '';
    }

    function cargar() {
        var preferencias = Object.assign({}, PREFERENCIAS);

        try {
            var crudo = localStorage.getItem(CLAVE);
            if (!crudo) {
                return preferencias;
            }
            var guardado = JSON.parse(crudo);
            if (!guardado || typeof guardado !== 'object') {
                return preferencias;
            }

            var texto = parseInt(guardado.text, 10);
            if (!isNaN(texto) && texto >= 0 && texto < ZOOMS.length) {
                preferencias.text = texto;
            }
            if (COLORES.indexOf(guardado.color) !== -1) {
                preferencias.color = guardado.color;
            }
            INTERRUPTORES.forEach(function (clave) {
                preferencias[clave] = guardado[clave] === true;
            });
        } catch (error) {
            // localStorage bloqueado o JSON inválido: se usan los valores por defecto.
        }

        return preferencias;
    }

    function guardar() {
        try {
            localStorage.setItem(CLAVE, JSON.stringify(estado));
        } catch (error) {
            // Sin localStorage las preferencias valen solo para esta página.
        }
    }

    function aplicar() {
        if (estado.text > 0) {
            html.setAttribute('data-acc-text', String(estado.text));
        } else {
            html.removeAttribute('data-acc-text');
        }

        if (estado.color !== 'normal') {
            html.setAttribute('data-acc-color', estado.color);
        } else {
            html.removeAttribute('data-acc-color');
        }

        ['spacing', 'font', 'links', 'headings', 'focus', 'cursor', 'motion', 'images'].forEach(function (clave) {
            if (estado[clave]) {
                html.setAttribute('data-acc-' + clave, '1');
            } else {
                html.removeAttribute('data-acc-' + clave);
            }
        });

        if (guia) {
            guia.hidden = !estado.guide;
        }
    }

    function sincronizar() {
        root.querySelectorAll('[data-acc-toggle]').forEach(function (elemento) {
            var clave = elemento.getAttribute('data-acc-toggle');
            elemento.setAttribute('aria-pressed', estado[clave] ? 'true' : 'false');
        });

        root.querySelectorAll('[data-acc-color-option]').forEach(function (elemento) {
            var opcion = elemento.getAttribute('data-acc-color-option');
            elemento.setAttribute('aria-pressed', opcion === estado.color ? 'true' : 'false');
        });

        if (nivel) {
            nivel.textContent = Math.round(ZOOMS[estado.text] * 100) + '%';
        }
    }

    function anunciar(texto) {
        if (!estadoTexto) {
            return;
        }
        estadoTexto.textContent = '';
        if (!texto) {
            return;
        }
        window.setTimeout(function () {
            estadoTexto.textContent = texto;
        }, 30);
    }

    function abrir() {
        panel.hidden = false;
        boton.setAttribute('aria-expanded', 'true');
        if (cerrarBtn) {
            cerrarBtn.focus();
        }
    }

    function cerrar() {
        if (panel.hidden) {
            return;
        }
        panel.hidden = true;
        boton.setAttribute('aria-expanded', 'false');
        boton.focus();
    }

    /* ---------- Lectura en voz alta ---------- */

    function hablar(texto) {
        texto = normalizar(texto);

        if (!soporteVoz) {
            anunciar(mensaje('vacio'));
            return;
        }
        if (!texto) {
            anunciar(mensaje('vacio'));
            return;
        }

        try {
            synth.cancel();
            var locucion = new SpeechSynthesisUtterance(texto);
            locucion.lang = root.getAttribute('data-lang') || 'es-UY';
            locucion.rate = 1;
            locucion.pitch = 1;
            locucion.onerror = function () {
                anunciar(mensaje('error'));
            };
            anunciar(mensaje('leyendo'));
            synth.speak(locucion);
        } catch (error) {
            anunciar(mensaje('error'));
        }
    }

    function detener() {
        if (soporteVoz) {
            synth.cancel();
        }
        anunciar(mensaje('detenida'));
    }

    function textoPagina() {
        var contenedor = document.querySelector(
            'main, [role="main"], .contenido-principal, .pagina-ingreso, .cuerpo-caja-ingreso'
        ) || document.body;

        var copia = contenedor.cloneNode(true);
        copia.querySelectorAll(
            'script, style, noscript, nav, header, footer, aside, #elyra-accesibilidad, ' +
            '.acc-guia, [aria-hidden="true"], .sr-only, .visually-hidden'
        ).forEach(function (nodo) {
            nodo.remove();
        });

        return normalizar(copia.textContent || '');
    }

    function leerPagina() {
        var texto = textoPagina();
        if (!texto) {
            anunciar(mensaje('vacio'));
            return;
        }
        hablar(texto.slice(0, 20000));
    }

    function leerSeleccion() {
        var seleccion = '';
        try {
            seleccion = window.getSelection ? String(window.getSelection().toString()) : '';
        } catch (error) {
            seleccion = '';
        }

        seleccion = normalizar(seleccion);
        if (!seleccion) {
            anunciar(mensaje('seleccion'));
            return;
        }
        hablar(seleccion);
    }

    /* ---------- Eventos ---------- */

    boton.addEventListener('click', function () {
        if (panel.hidden) {
            abrir();
        } else {
            cerrar();
        }
    });

    if (cerrarBtn) {
        cerrarBtn.addEventListener('click', cerrar);
    }

    root.querySelectorAll('[data-acc-text-step]').forEach(function (elemento) {
        elemento.addEventListener('click', function () {
            var paso = parseInt(elemento.getAttribute('data-acc-text-step'), 10);
            if (paso === 0) {
                estado.text = 0;
            } else {
                estado.text = Math.max(0, Math.min(ZOOMS.length - 1, estado.text + paso));
            }
            aplicar();
            guardar();
            sincronizar();
        });
    });

    root.querySelectorAll('[data-acc-color-option]').forEach(function (elemento) {
        elemento.addEventListener('click', function () {
            var opcion = elemento.getAttribute('data-acc-color-option');
            if (COLORES.indexOf(opcion) === -1) {
                opcion = 'normal';
            }
            estado.color = opcion;
            aplicar();
            guardar();
            sincronizar();
        });
    });

    root.querySelectorAll('[data-acc-toggle]').forEach(function (elemento) {
        elemento.addEventListener('click', function () {
            var clave = elemento.getAttribute('data-acc-toggle');
            if (!Object.prototype.hasOwnProperty.call(PREFERENCIAS, clave)) {
                return;
            }

            estado[clave] = !estado[clave];

            if (clave === 'clickRead' && !estado.clickRead && soporteVoz) {
                synth.cancel();
            }
            if (clave === 'guide' && estado.guide && guia) {
                guia.style.top = Math.max(0, (window.innerHeight / 2) - 21) + 'px';
            }

            aplicar();
            guardar();
            sincronizar();
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (soporteVoz) {
                synth.cancel();
            }
            estado = Object.assign({}, PREFERENCIAS);
            aplicar();
            guardar();
            sincronizar();
            anunciar(mensaje('reset'));
        });
    }

    if (leerPaginaBtn) {
        leerPaginaBtn.addEventListener('click', leerPagina);
    }

    if (leerSeleccionBtn) {
        // Evita que el clic colapse la selección antes de leerla.
        leerSeleccionBtn.addEventListener('mousedown', function (evento) {
            evento.preventDefault();
        });
        leerSeleccionBtn.addEventListener('click', leerSeleccion);
    }

    if (detenerBtn) {
        detenerBtn.addEventListener('click', detener);
    }

    if (!soporteVoz) {
        if (notaVoz) {
            notaVoz.hidden = false;
        }
        [leerPaginaBtn, leerSeleccionBtn, detenerBtn].forEach(function (elemento) {
            if (elemento) {
                elemento.disabled = true;
            }
        });
    }

    /* Cerrar al hacer clic fuera del panel. */
    document.addEventListener('click', function (evento) {
        if (!panel.hidden && !root.contains(evento.target)) {
            cerrar();
        }
    }, true);

    /* Escape cierra; Alt + A abre o cierra desde cualquier página. */
    document.addEventListener('keydown', function (evento) {
        if (evento.altKey && !evento.ctrlKey && !evento.metaKey
            && (evento.key === 'a' || evento.key === 'A' || evento.code === 'KeyA')
        ) {
            evento.preventDefault();
            if (panel.hidden) {
                abrir();
            } else {
                cerrar();
            }
            return;
        }

        if (evento.key === 'Escape' && !panel.hidden) {
            evento.preventDefault();
            cerrar();
        }
    });

    /* Mantiene el foco dentro del panel mientras está abierto. */
    panel.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Tab') {
            return;
        }

        var enfocables = panel.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), ' +
            'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!enfocables.length) {
            return;
        }

        var primero = enfocables[0];
        var ultimo = enfocables[enfocables.length - 1];

        if (evento.shiftKey && document.activeElement === primero) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primero.focus();
        }
    });

    /* Guía de lectura: sigue al puntero. */
    function moverGuia(evento) {
        if (!estado.guide || !guia) {
            return;
        }
        var y = typeof evento.clientY === 'number' ? evento.clientY : 0;
        guia.style.top = Math.max(0, y - 21) + 'px';
    }

    document.addEventListener('mousemove', moverGuia, { passive: true });
    document.addEventListener('touchmove', function (evento) {
        if (evento.touches && evento.touches[0]) {
            moverGuia(evento.touches[0]);
        }
    }, { passive: true });

    /* Lectura al hacer clic sobre bloques de texto. */
    document.addEventListener('click', function (evento) {
        if (!estado.clickRead || root.contains(evento.target)) {
            return;
        }

        var interactivo = evento.target.closest(
            'a, button, input, select, textarea, label, summary, [role="button"], [contenteditable="true"]'
        );
        if (interactivo) {
            return;
        }

        var bloque = evento.target.closest(
            'p, li, h1, h2, h3, h4, h5, h6, td, th, blockquote, figcaption, dt, dd'
        );
        if (!bloque) {
            return;
        }

        var texto = normalizar(bloque.innerText || bloque.textContent || '');
        if (texto) {
            hablar(texto.slice(0, 4000));
        }
    }, true);

    aplicar();
    sincronizar();
})();
