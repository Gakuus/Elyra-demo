/**
 * Chatbot de Elyra: mascota flotante y panel de conversación.
 *
 * La conversación vive en sessionStorage (se borra al cerrar la pestaña) y
 * las respuestas las genera el backend en POST /api/chat. Acá solo se maneja
 * la interfaz: abrir/cerrar, enviar, sugerencias, teclado y estados de error.
 *
 * Seguridad: todo lo que devuelve el servidor se pinta con textContent, nunca
 * con innerHTML, para que un texto malicioso no pueda inyectar HTML.
 */
(function () {
    'use strict';

    var raiz = document.getElementById('elyra-chat');
    if (!raiz) {
        return;
    }

    var boton = document.getElementById('chatBoton');
    var burbuja = document.getElementById('chatBurbuja');
    var panel = document.getElementById('chatPanel');
    var cerrarBtn = document.getElementById('chatCerrar');
    var limpiarBtn = document.getElementById('chatLimpiar');
    var mensajes = document.getElementById('chatMensajes');
    var sugerencias = document.getElementById('chatSugerencias');
    var form = document.getElementById('chatForm');
    var entrada = document.getElementById('chatEntrada');
    var enviarBtn = document.getElementById('chatEnviar');

    if (!boton || !panel || !form || !entrada || !mensajes) {
        return;
    }

    var CLAVE = 'elyra_chat_v1';
    var MAX_HISTORIAL = 8;
    var MAX_GUARDADO = 20;

    var api = raiz.getAttribute('data-api') || 'api/chat';
    var csrf = raiz.getAttribute('data-csrf') || '';
    var configurado = raiz.getAttribute('data-configurado') === '1';
    var avatar = boton.querySelector('img') ? boton.querySelector('img').getAttribute('src') : '';

    var historial = [];
    var abierto = false;
    var enviando = false;
    var indicador = null;
    var avisoConfig = false;

    /** Texto traducido guardado en data-msg-*. */
    function msg(nombre) {
        return raiz.getAttribute('data-msg-' + nombre) || '';
    }

    function crear(tag, clase) {
        var el = document.createElement(tag);
        if (clase) {
            el.className = clase;
        }
        return el;
    }

    /** Lee la conversación guardada, ignorando cualquier cosa rara. */
    function cargar() {
        try {
            var crudo = sessionStorage.getItem(CLAVE);
            if (!crudo) {
                return;
            }
            var datos = JSON.parse(crudo);
            if (!Array.isArray(datos)) {
                return;
            }
            historial = datos.filter(function (m) {
                return m && typeof m.texto === 'string' && m.texto !== ''
                    && (m.rol === 'user' || m.rol === 'assistant');
            }).slice(-MAX_GUARDADO);
        } catch (e) {
            historial = [];
        }
    }

    function guardar() {
        try {
            sessionStorage.setItem(CLAVE, JSON.stringify(historial.slice(-MAX_GUARDADO)));
        } catch (e) {
            /* sessionStorage puede estar bloqueado; la conversación sigue en memoria. */
        }
    }

    function alFinal() {
        mensajes.scrollTop = mensajes.scrollHeight;
    }

    /** Agrega una burbuja. rol: 'bot' | 'user'. */
    function agregar(rol, texto, esError) {
        var fila = crear('div', 'chat-mensaje chat-mensaje-' + rol
            + (esError ? ' chat-mensaje-error' : ''));

        if (rol === 'bot' && avatar) {
            var mini = crear('img', 'chat-mini');
            mini.src = avatar;
            mini.alt = '';
            mini.width = 28;
            mini.height = 28;
            fila.appendChild(mini);
        }

        var cuerpo = crear('div', 'chat-texto');
        cuerpo.textContent = texto;
        fila.appendChild(cuerpo);
        mensajes.appendChild(fila);

        // La conversación no crece sin límite en el DOM.
        while (mensajes.children.length > 60) {
            mensajes.removeChild(mensajes.firstChild);
        }

        alFinal();
        return fila;
    }

    function mostrarEscribiendo() {
        indicador = crear('div', 'chat-mensaje chat-mensaje-bot chat-escribiendo');
        if (avatar) {
            var mini = crear('img', 'chat-mini');
            mini.src = avatar;
            mini.alt = '';
            mini.width = 28;
            mini.height = 28;
            indicador.appendChild(mini);
        }
        var cuerpo = crear('div', 'chat-texto');
        var puntos = crear('span', 'chat-puntos');
        puntos.setAttribute('aria-hidden', 'true');
        puntos.appendChild(crear('span'));
        puntos.appendChild(crear('span'));
        puntos.appendChild(crear('span'));
        cuerpo.appendChild(puntos);
        var soloLector = crear('span', 'chat-sr');
        soloLector.textContent = msg('pensando');
        cuerpo.appendChild(soloLector);
        indicador.appendChild(cuerpo);
        mensajes.appendChild(indicador);
        alFinal();
    }

    function quitarEscribiendo() {
        if (indicador && indicador.parentNode) {
            indicador.parentNode.removeChild(indicador);
        }
        indicador = null;
    }

    /** Pinta la conversación guardada. */
    function pintarHistorial() {
        mensajes.innerHTML = '';
        historial.forEach(function (m) {
            agregar(m.rol === 'assistant' ? 'bot' : 'user', m.texto, false);
        });
    }

    function actualizarSugerencias() {
        if (sugerencias) {
            sugerencias.hidden = historial.length > 0;
        }
    }

    function saludar() {
        agregar('bot', msg('saludo'), false);
    }

    function bloquearPorConfiguracion() {
        if (configurado) {
            return false;
        }
        if (!avisoConfig) {
            agregar('bot', msg('noconfig'), true);
            avisoConfig = true;
        }
        entrada.disabled = true;
        if (enviarBtn) {
            enviarBtn.disabled = true;
        }
        if (sugerencias) {
            sugerencias.hidden = true;
        }
        return true;
    }

    function abrir() {
        if (abierto) {
            return;
        }
        abierto = true;
        panel.hidden = false;
        boton.setAttribute('aria-expanded', 'true');
        if (burbuja) {
            burbuja.hidden = true;
        }

        if (historial.length === 0) {
            if (!bloquearPorConfiguracion()) {
                saludar();
            }
        } else {
            pintarHistorial();
            bloquearPorConfiguracion();
            actualizarSugerencias();
        }

        alFinal();
        if (!entrada.disabled) {
            entrada.focus();
        } else if (cerrarBtn) {
            cerrarBtn.focus();
        }
    }

    function cerrar() {
        if (!abierto) {
            return;
        }
        abierto = false;
        panel.hidden = true;
        boton.setAttribute('aria-expanded', 'false');
        boton.focus();
    }

    function alternar() {
        if (abierto) {
            cerrar();
        } else {
            abrir();
        }
    }

    /** Envía el texto al backend y pinta la respuesta. */
    function enviar(texto) {
        texto = (texto || '').trim();
        if (texto === '' || enviando) {
            return;
        }
        if (!configurado) {
            bloquearPorConfiguracion();
            return;
        }

        var previos = historial.slice(-MAX_HISTORIAL);
        agregar('user', texto, false);
        historial.push({ rol: 'user', texto: texto });
        guardar();
        actualizarSugerencias();

        entrada.value = '';
        ajustarAltura();
        enviando = true;
        if (enviarBtn) {
            enviarBtn.disabled = true;
        }
        mensajes.setAttribute('aria-busy', 'true');
        mostrarEscribiendo();

        var terminar = function () {
            enviando = false;
            if (enviarBtn) {
                enviarBtn.disabled = false;
            }
            mensajes.removeAttribute('aria-busy');
            quitarEscribiendo();
            if (!entrada.disabled) {
                entrada.focus();
            }
        };

        if (typeof window.fetch !== 'function') {
            terminar();
            agregar('bot', msg('errorRed'), true);
            return;
        }

        window.fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ mensaje: texto, historial: previos })
        }).then(function (respuesta) {
            return respuesta.text().then(function (crudo) {
                var datos = null;
                try {
                    datos = JSON.parse(crudo);
                } catch (e) {
                    datos = null;
                }
                return { estado: respuesta.status, datos: datos };
            });
        }).then(function (res) {
            terminar();

            if (res.datos && res.datos.ok && typeof res.datos.respuesta === 'string') {
                var limpio = res.datos.respuesta.trim();
                agregar('bot', limpio, false);
                historial.push({ rol: 'assistant', texto: limpio });
                guardar();
                return;
            }

            var error = res.datos && typeof res.datos.error === 'string' && res.datos.error !== ''
                ? res.datos.error
                : (res.estado === 429 ? msg('limite') : msg('error'));
            agregar('bot', error, true);
        }).catch(function () {
            terminar();
            agregar('bot', msg('errorRed'), true);
        });
    }

    function ajustarAltura() {
        var max = parseFloat(window.getComputedStyle(entrada).maxHeight);
        if (!isFinite(max) || max <= 0) {
            max = 96;
        }
        entrada.style.height = 'auto';
        entrada.style.height = Math.min(entrada.scrollHeight, max) + 'px';
    }

    function limpiar() {
        historial = [];
        guardar();
        mensajes.innerHTML = '';
        avisoConfig = false;
        actualizarSugerencias();
        agregar('bot', msg('limpiada'), false);
        if (!bloquearPorConfiguracion()) {
            saludar();
            entrada.focus();
        }
    }

    /** Devuelve los elementos enfocables visibles dentro del panel. */
    function enfocables() {
        var selector = 'button:not([disabled]), textarea:not([disabled]), input:not([disabled]), '
            + 'a[href], [tabindex]:not([tabindex="-1"])';
        return Array.prototype.filter.call(panel.querySelectorAll(selector), function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    // ---------- Eventos ----------
    boton.addEventListener('click', alternar);
    if (cerrarBtn) {
        cerrarBtn.addEventListener('click', cerrar);
    }
    if (limpiarBtn) {
        limpiarBtn.addEventListener('click', limpiar);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        enviar(entrada.value);
    });

    entrada.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            enviar(entrada.value);
        }
    });

    entrada.addEventListener('input', ajustarAltura);

    if (sugerencias) {
        sugerencias.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('[data-chat-prompt]') : null;
            if (!chip) {
                return;
            }
            enviar(chip.getAttribute('data-chat-prompt') || chip.textContent);
        });
    }

    document.addEventListener('click', function (e) {
        if (abierto && !raiz.contains(e.target)) {
            cerrar();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'm' || e.key === 'M')) {
            e.preventDefault();
            alternar();
            return;
        }

        if (!abierto) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            cerrar();
            return;
        }

        if (e.key === 'Tab') {
            var lista = enfocables();
            if (lista.length === 0) {
                return;
            }
            var primero = lista[0];
            var ultimo = lista[lista.length - 1];
            if (e.shiftKey && document.activeElement === primero) {
                e.preventDefault();
                ultimo.focus();
            } else if (!e.shiftKey && document.activeElement === ultimo) {
                e.preventDefault();
                primero.focus();
            }
        }
    });

    // Si cambia el tamaño de texto de accesibilidad, el campo y el scroll se
    // reajustan sin recargar la página.
    if (window.MutationObserver) {
        new MutationObserver(function () {
            window.requestAnimationFrame(function () {
                ajustarAltura();
                alFinal();
            });
        }).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-acc-text']
        });
    }

    // ---------- Arranque ----------
    cargar();
    if (historial.length > 0) {
        pintarHistorial();
        actualizarSugerencias();
    }
    ajustarAltura();
})();
