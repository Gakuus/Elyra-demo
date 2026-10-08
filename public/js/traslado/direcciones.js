/**
 * traslado/direcciones.js: el buscador de direcciones de origen y destino.
 *
 * Contra /api/geocodificar, con tres controles porque escribir rápido es el
 * caso normal y cada consulta cuesta plata del proveedor (Geoapify):
 *   - debounce de 700 ms;
 *   - caché de las últimas 40 consultas, con la referencia (origen elegido o
 *     centro del mapa) como parte de la clave;
 *   - AbortController, para que la consulta por "avi" no siga gastando cuota
 *     cuando el usuario ya va por la quinta letra.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var fmt = root.ElyraFmt;

    mod.direcciones = function (ctx, api) {
        var esc = fmt.escaparHtml;
        var aj = mod.ajustes;
        var urls = root.ElyraMapaMod.urls;

        function cajaDe(cual) {
            return cual === 'origen' ? ctx.dom.sugOrigen : ctx.dom.sugDestino;
        }

        function ocultar(cual) {
            var caja = cajaDe(cual);
            if (!caja) return;

            caja.hidden = true;
            caja.innerHTML = '';

            var contenedor = caja.parentNode;
            if (contenedor) contenedor.classList.remove('sugerencias-arriba');
        }

        // La referencia puede venir nula (todavía no hay origen elegido y el mapa
        // todavía no está listo), y el '' del fallback no tiene toFixed: eso
        // reventaba la búsqueda entera.
        function claveCache(texto, lat, lng) {
            var l = (typeof lat === 'number' && isFinite(lat)) ? lat.toFixed(4) : 'na';
            var g = (typeof lng === 'number' && isFinite(lng)) ? lng.toFixed(4) : 'na';
            return texto.toLowerCase() + '|' + l + '|' + g;
        }

        function guardarEnCache(clave, valor) {
            var busqueda = ctx.busqueda;

            busqueda.cache[clave] = valor;
            busqueda.ordenCache.push(clave);

            while (busqueda.ordenCache.length > aj.cacheBusquedasMax) {
                delete busqueda.cache[busqueda.ordenCache.shift()];
            }
        }

        /** Referencia para ordenar las sugerencias por cercanía. */
        function referencia() {
            if (api.extremos.tieneCoordenadas(ctx.origen)) {
                return { lat: ctx.origen.lat, lng: ctx.origen.lng };
            }

            if (ctx.puente && ctx.puente.leaflet) {
                var centro = ctx.puente.leaflet.getCenter();
                return { lat: centro.lat, lng: centro.lng };
            }

            return { lat: null, lng: null };
        }

        function pintar(resultados, cual, consulta, error) {
            var caja = cajaDe(cual);
            if (!caja) return;

            ctx.ultimasDirecciones[cual] = resultados;

            if (error) {
                api.ui.mostrarMensaje(caja, error);
                return;
            }

            if (!resultados.length) {
                caja.innerHTML = '<div class="sugerencia vacia">' +
                    'Sin resultados para <b>' + esc(consulta || '') + '</b>.<br>' +
                    '<small>Probá con el nombre de la calle, el número, o ' +
                    '<i class="bi bi-crosshair"></i> usar tu ubicación.</small></div>';
                api.ui.ubicar(caja);
                return;
            }

            var html = '';
            resultados.forEach(function (r, i) {
                var partes = r.label.split(',');
                var principal = partes.shift();
                var resto = partes.join(',');

                // "A 350 m" es lo que permite elegir entre dos cosas con el mismo
                // nombre: sin eso el desplegable no dice cuál de las dos es.
                var meta = [];
                if (r.tipo) meta.push(esc(r.tipo));

                var distancia = fmt.formatearMetros(r.distancia);
                if (distancia) meta.push(distancia);

                // Un cruce de calles no es una dirección: se distingue con otro
                // ícono para que se lea como tal y no como una casa más.
                var esCruce = r.categoria === 'intersection';

                html +=
                    '<div class="sugerencia' + (esCruce ? ' es-cruce' : '') + '" data-indice="' + i + '">' +
                    '<i class="bi ' + (esCruce ? 'bi-signpost-2' : 'bi-geo-alt') + '"></i>' +
                    '<span class="sugerencia-texto">' +
                    '<b>' + api.ui.resaltar(principal, consulta) + '</b>' +
                    (resto ? '<small>' + esc(resto) + '</small>' : '') +
                    (meta.length ? '<em class="sugerencia-meta">' + meta.join(' · ') + '</em>' : '') +
                    '</span></div>';
            });

            caja.innerHTML = html;
            api.ui.ubicar(caja);

            caja.querySelectorAll('.sugerencia[data-indice]').forEach(function (nodo) {
                nodo.addEventListener('mousedown', function (e) {
                    // mousedown y no click: si se pierde el foco primero, el input
                    // dispara blur y el click nunca llega.
                    e.preventDefault();

                    var elegido = resultados[parseInt(this.getAttribute('data-indice'), 10)];
                    ctx.editando[cual] = false;
                    api.extremos.fijar(cual, elegido.lat, elegido.lng, elegido.label);
                    ocultar(cual);
                });
            });
        }

        function buscar(texto, cual) {
            var consulta = (texto || '').trim();
            var estado = ctx.busqueda;

            if (consulta.length < aj.minCaracteres) {
                ocultar(cual);
                if (estado.peticion) { estado.peticion.abort(); estado.peticion = null; }
                return;
            }

            var ref = referencia();
            var clave = claveCache(consulta, ref.lat, ref.lng);

            if (Object.prototype.hasOwnProperty.call(estado.cache, clave)) {
                if (estado.timer !== null) clearTimeout(estado.timer);
                pintar(estado.cache[clave], cual, consulta);
                return;
            }

            if (estado.timer !== null) clearTimeout(estado.timer);

            estado.timer = setTimeout(function () {
                var miToken = ++estado.token;

                var url = urls.geocodificar + '?q=' + encodeURIComponent(consulta);
                // Bias de proximidad para mejorar el orden de sugerencias cuando
                // hay una referencia cercana.
                if (ref.lat !== null && ref.lng !== null) {
                    url += '&prox_lat=' + encodeURIComponent(ref.lat) +
                        '&prox_lng=' + encodeURIComponent(ref.lng);
                }

                // Se corta la petición anterior: si el usuario ya va por la
                // quinta letra, la consulta por "avi" ya no le sirve a nadie.
                if (estado.peticion) estado.peticion.abort();
                var control = new AbortController();
                estado.peticion = control;

                api.ui.mostrarBuscando(cajaDe(cual), consulta);

                fetch(url, { signal: control.signal })
                    .then(function (r) {
                        // Sin sesión el backend responde con un 302 a /login, o
                        // sea una página de HTML. Antes se hacía r.json() a ciegas
                        // y eso reventaba con un error de sintaxis que se terminaba
                        // mostrando como "revisá la conexión", que no era cierto.
                        if (r.redirected || /\/login(\?|$)/.test(r.url || '')) {
                            throw { elyra: 'sesion' };
                        }
                        if (r.status === 401 || r.status === 403) {
                            throw { elyra: 'permiso' };
                        }
                        if (!r.ok) {
                            throw { elyra: 'servidor', codigo: r.status };
                        }
                        return r.json();
                    })
                    .then(function (json) {
                        if (miToken !== estado.token) return;

                        if (json && json.ok === false) {
                            pintar([], cual, consulta, json.error || 'No se pudo buscar.');
                            return;
                        }

                        var resultados = (json && json.resultados) ? json.resultados : [];
                        guardarEnCache(clave, resultados);
                        pintar(resultados, cual, consulta);
                    })
                    .catch(function (e) {
                        // Un abort es algo que provocamos nosotros, no un fallo que
                        // haya que mostrarle a alguien.
                        if (e && e.name === 'AbortError') return;
                        if (miToken !== estado.token) return;

                        var mensaje;
                        if (e && e.elyra === 'sesion') {
                            mensaje = '<i class="bi bi-box-arrow-right"></i> Tu sesión expiró. ' +
                                'Volvé a entrar para buscar direcciones.';
                        } else if (e && e.elyra === 'permiso') {
                            mensaje = 'No tenés permiso para buscar direcciones.';
                        } else if (e && e.elyra === 'servidor') {
                            mensaje = 'El servidor falló (' + e.codigo + '). Probá de nuevo.';
                        } else {
                            mensaje = 'No se pudo buscar. Revisá la conexión.';
                        }

                        pintar([], cual, consulta, mensaje);
                    });
            }, aj.esperaBusqueda);
        }

        return {
            buscar: buscar,
            pintar: pintar,
            ocultar: ocultar,
            ultimas: function (cual) { return ctx.ultimasDirecciones[cual]; }
        };
    };
})(window);