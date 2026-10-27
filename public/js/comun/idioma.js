/**
 * Traducción del contenido dinámico de Elyra.
 *
 * El HTML que entrega el servidor ya viene traducido (I18n::traducirHtml),
 * pero buena parte de la interfaz se arma en el navegador: listados AJAX,
 * mensajes de confirmación, estados del mapa, etc. Este archivo expone
 * window.ElyraIdioma para traducir esos textos con el mismo diccionario que
 * usa PHP y traduce automáticamente el DOM que se va insertando.
 */
(function () {
    'use strict';

    var idioma = window.ElyraIdiomaActual || 'es';
    var traducciones = window.ElyraTraducciones || {};

    function normalizar(texto) {
        return String(texto).replace(/\s+/g, ' ').trim();
    }

    function t(texto, parametros) {
        if (texto === null || texto === undefined) {
            return '';
        }

        var original = String(texto);
        var clave = normalizar(original);
        var traducido = Object.prototype.hasOwnProperty.call(traducciones, clave)
            ? traducciones[clave]
            : original;

        if (parametros && typeof parametros === 'object') {
            Object.keys(parametros).forEach(function (nombre) {
                var valor = String(parametros[nombre]);
                traducido = traducido
                    .split(':' + nombre).join(valor)
                    .split('{' + nombre + '}').join(valor);
            });
        }

        return traducido;
    }

    function traducirTextoNodo(nodo) {
        var original = nodo.nodeValue;
        if (!original || !/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/.test(original)) {
            return;
        }

        var clave = normalizar(original);
        if (!clave || !Object.prototype.hasOwnProperty.call(traducciones, clave)) {
            return;
        }

        var inicio = (original.match(/^\s*/) || [''])[0];
        var fin = (original.match(/\s*$/) || [''])[0];
        var nuevo = inicio + traducciones[clave] + fin;

        if (nuevo !== original) {
            nodo.nodeValue = nuevo;
        }
    }

    function traducirAtributos(elemento) {
        ['placeholder', 'title', 'aria-label', 'alt'].forEach(function (atributo) {
            var valor = elemento.getAttribute(atributo);
            if (!valor) {
                return;
            }

            var clave = normalizar(valor);
            if (Object.prototype.hasOwnProperty.call(traducciones, clave)) {
                elemento.setAttribute(atributo, traducciones[clave]);
            }
        });
    }

    function traducirArbol(raiz) {
        if (!raiz || raiz.nodeType !== 1) {
            if (raiz && raiz.nodeType === 3) {
                traducirTextoNodo(raiz);
            }
            return;
        }

        var etiqueta = raiz.tagName ? raiz.tagName.toLowerCase() : '';
        if (etiqueta === 'script' || etiqueta === 'style' || etiqueta === 'textarea' ||
            etiqueta === 'code' || etiqueta === 'pre' ||
            raiz.hasAttribute('data-no-traducir')) {
            return;
        }

        traducirAtributos(raiz);

        var caminante = document.createTreeWalker(raiz, NodeFilter.SHOW_TEXT, null);
        var nodo = caminante.nextNode();
        while (nodo) {
            traducirTextoNodo(nodo);
            nodo = caminante.nextNode();
        }
    }

    /**
     * Cambia el idioma. Usa la API JSON cuando hay token CSRF (páginas del
     * panel) y, si no, cae al selector GET /idioma, que también persiste la
     * preferencia en sesión y cookie.
     */
    function cambiar(nuevoIdioma) {
        if (nuevoIdioma !== 'es' && nuevoIdioma !== 'en') {
            return Promise.resolve(false);
        }

        if (nuevoIdioma === idioma) {
            return Promise.resolve(true);
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';

        if (!token) {
            return Promise.resolve(redirigirSelector(nuevoIdioma));
        }

        var url = new URL('api/idioma', document.baseURI).toString();
        var cuerpo = new URLSearchParams();
        cuerpo.set('lang', nuevoIdioma);

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: cuerpo.toString()
        }).then(function (respuesta) {
            if (!respuesta.ok) {
                throw new Error('HTTP ' + respuesta.status);
            }
            return respuesta.json();
        }).then(function (datos) {
            if (!datos || !datos.ok) {
                throw new Error('Respuesta inválida');
            }
            window.location.reload();
            return true;
        }).catch(function () {
            return redirigirSelector(nuevoIdioma);
        });
    }

    function redirigirSelector(nuevoIdioma) {
        var destino = new URL('idioma', document.baseURI);
        destino.searchParams.set('lang', nuevoIdioma);
        destino.searchParams.set('volver', window.location.pathname + window.location.search);
        window.location.href = destino.toString();
        return true;
    }

    window.ElyraIdioma = {
        actual: idioma,
        t: t,
        cambiar: cambiar,
        traducir: traducirArbol,
        normalizar: normalizar
    };

    // Los mensajes nativos del navegador también salen en el idioma activo.
    if (idioma !== 'es' && Object.keys(traducciones).length) {
        var alertOriginal = window.alert;
        var confirmOriginal = window.confirm;
        var promptOriginal = window.prompt;

        window.alert = function (mensaje) {
            return alertOriginal.call(window, t(mensaje));
        };

        window.confirm = function (mensaje) {
            return confirmOriginal.call(window, t(mensaje));
        };

        window.prompt = function (mensaje, valorPorDefecto) {
            return promptOriginal.call(window, t(mensaje), valorPorDefecto);
        };
    }

    // Traduce lo que ya está en pantalla y lo que los scripts inserten después.
    function iniciar() {
        if (idioma === 'es' || !Object.keys(traducciones).length) {
            return;
        }

        traducirArbol(document.body);

        var observador = new MutationObserver(function (mutaciones) {
            mutaciones.forEach(function (mutacion) {
                if (mutacion.type !== 'childList') {
                    return;
                }

                Array.prototype.forEach.call(mutacion.addedNodes, function (nodo) {
                    if (nodo.nodeType === 1) {
                        traducirArbol(nodo);
                    } else if (nodo.nodeType === 3) {
                        traducirTextoNodo(nodo);
                    }
                });
            });
        });

        observador.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
