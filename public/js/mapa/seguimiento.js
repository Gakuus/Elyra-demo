/**
 * mapa/seguimiento.js: la posición del propio conductor.
 *
 * Si el usuario que entró tiene rol 'conductor', la página hace el camino
 * inverso al despacho: toma la ubicación del dispositivo con
 * navigator.geolocation.watchPosition, la dibuja en el mapa y la reporta a
 * /api/ubicacion para que el resto vea la ambulancia en vivo.
 *
 * Todo el estado mutable vive en ctx.seguimiento porque la simulación
 * (simulacion.js) escribe el mismo marcador y tiene que respectar el mismo
 * throttle de envío.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;
    var aj = mod.ajustes;

    mod.seguimiento = function (ctx, api) {
        var esc = mod.util.escaparHtml;

        /** Atajo al bloque de estado compartido. */
        function seg() {
            return ctx.seguimiento;
        }

        /**
         * Pinta el estado del GPS del conductor en el panel lateral.
         * clase: 'ok' | 'esperando' | 'error' | 'simulando'
         */
        function mostrar(clase, titulo, detalle) {
            var dom = ctx.dom;
            if (dom.estadoSeguimiento) {
                dom.estadoSeguimiento.className = 'seguimiento-estado ' + clase;
                dom.estadoSeguimiento.textContent = titulo;
            }
            if (dom.detalleSeguimiento) {
                dom.detalleSeguimiento.innerHTML = detalle;
            }
        }

        /**
         * Pone la posición propia en el mapa y, si el conductor lo dejó,
         * recentra la cámara. Se llama en cada fix del GPS (muchas veces por
         * minuto), por eso no escribe nada al servidor: eso lo hace reportar().
         */
        function marcarPropio(lat, lng, exactitud) {
            var s = seg();
            var latlng = [lat, lng];

            // El rumbo se calcula contra la posición anterior. Con un solo fix
            // no hay hacia dónde apuntar, así que se deja el que hubiera.
            if (s.ultimaPosicion) {
                s.rumbo = mod.util.rumboEntre(s.ultimaPosicion[0], s.ultimaPosicion[1], lat, lng);
            }
            s.ultimaPosicion = [lat, lng];

            if (!s.marcadorPropio) {
                s.colorIcono = s.color;
                s.marcadorPropio = L.marker(latlng, {
                    icon: api.iconos.propio(s.color, s.rumbo),
                    zIndexOffset: 1000,
                    interactive: false
                }).addTo(ctx.capas.propia);

                s.circuloPrecision = L.circle(latlng, {
                    radius: exactitud || 0,
                    color: cfg.COLOR_PROPIO,
                    weight: 1,
                    opacity: 0.5,
                    fillColor: cfg.COLOR_PROPIO,
                    fillOpacity: 0.12,
                    interactive: false
                }).addTo(ctx.capas.propia);
            } else {
                s.marcadorPropio.setLatLng(latlng);

                // El color solo cambia al arrancar otra simulación (depende del
                // estado del traslado). El giro cambia en cada tick: se rota el
                // div interno y, si eso no se puede, se recrea el icono.
                if (s.colorIcono !== s.color) {
                    s.colorIcono = s.color;
                    s.marcadorPropio.setIcon(api.iconos.propio(s.color, s.rumbo));
                } else if (!api.iconos.girar(s.marcadorPropio, s.rumbo)) {
                    s.marcadorPropio.setIcon(api.iconos.propio(s.color, s.rumbo));
                }
            }

            if (s.circuloPrecision && exactitud) {
                s.circuloPrecision.setLatLng(latlng);
                s.circuloPrecision.setRadius(exactitud);
            }

            if (s.seguirCamara) {
                ctx.mapa.setView(latlng, ctx.mapa.getZoom(), { animate: true });
            }
        }

        /**
         * Manda la posición al servidor. Es el único camino por el que sale una
         * ubicación, lo usan tanto el GPS real como la simulación.
         *
         * rumbo es opcional: el navegador lo da solo cuando el dispositivo tiene
         * brújula, y la simulación lo calcula del avance sobre la traza. Sin él
         * el servidor guarda heading NULL y la ambulancia se ve sin orientación
         * en el mapa de los demás.
         */
        function reportar(lat, lng, exactitud, velocidadKmh, rumbo) {
            var s = seg();
            if (s.enviando) return;

            var ahora = Date.now();
            if (ahora - s.ultimoEnvioMs < aj.envioCada) return;
            s.ultimoEnvioMs = ahora;
            s.enviando = true;

            var data = new FormData();
            data.append('latitud', lat);
            data.append('longitud', lng);
            data.append('exactitud', exactitud === null || exactitud === undefined ? '' : Math.round(exactitud));
            data.append('velocidad', velocidadKmh === null || velocidadKmh === undefined ? '' : velocidadKmh.toFixed(1));
            data.append('ts', ahora);
            // Vacío en vez de omitirlo: el servidor trata '' y ausente igual
            // (lo pasa a NULL), pero mandarlo siempre deja claro que el campo es
            // opcional a propósito y no un olvido.
            data.append('heading', (typeof rumbo === 'number' && !isNaN(rumbo))
                ? Math.round((rumbo % 360 + 360) % 360)
                : '');

            fetch(mod.urls.ubicacion, { method: 'POST', headers: window.Elyra.csrfHeaders(), body: data })
                .then(function (r) {
                    return r.json().catch(function () {
                        return { ok: false, error: 'Respuesta inválida del servidor.' };
                    });
                })
                .then(function (json) {
                    if (json && json.ok) {
                        s.ultimoEnvioOkMs = Date.now();
                        s.ultimoError = '';
                        mostrar('ok', 'Reportando', 'Última posición enviada al despacho a las <strong>' +
                            new Date(s.ultimoEnvioOkMs).toLocaleTimeString() + '</strong>.');
                    } else {
                        s.ultimoError = (json && json.error) ? json.error : 'No se pudo enviar la ubicación.';
                        mostrar('error', 'Sin enviar', esc(s.ultimoError));
                    }
                })
                .catch(function () {
                    s.ultimoError = 'Sin conexión con el servidor.';
                    mostrar('error', 'Sin enviar', esc(s.ultimoError));
                })
                .then(function () {
                    s.enviando = false;
                });
        }

        /** Convierte la velocidad del navegador (m/s) a km/h. */
        function velocidadAKmH(metrosPorSegundo) {
            if (typeof metrosPorSegundo !== 'number' || isNaN(metrosPorSegundo) || metrosPorSegundo < 0) return null;
            return Math.round(metrosPorSegundo * 3.6 * 10) / 10;
        }

        function alRecibirPosicion(pos) {
            var c = pos.coords;

            // Fix viejo (el navegador puede devolver uno cacheado): se ignora
            // para no mover la ambulancia hacia atrás en el mapa.
            if (pos.timestamp && (Date.now() - pos.timestamp) > aj.fixViejo) return;

            // Fix demasiado impreciso (dentro de un edificio, GPS sin señal): se
            // dibuja localmente pero no se manda al server, para no pisear la
            // última posición buena que había.
            if (typeof c.accuracy === 'number' && c.accuracy > aj.precisionMaxima) {
                mostrar('esperando', 'Señal débil', 'Precisión de <strong>' + Math.round(c.accuracy) +
                    ' m</strong>: esperando mejor señal para reportar.');
                return;
            }

            seg().activo = true;

            var exactitud = typeof c.accuracy === 'number' ? c.accuracy : null;
            marcarPropio(c.latitude, c.longitude, exactitud);
            reportar(c.latitude, c.longitude, exactitud, velocidadAKmH(c.speed),
                typeof c.heading === 'number' ? c.heading : null);
        }

        var MENSAJES_ERROR = {
            1: ['Permiso denegado', 'El navegador no permite usar la ubicación. Habilitalo desde el candado de la barra de direcciones.'],
            2: ['GPS sin señal', 'No se pudo determinar la posición. Salí a un lugar más despejado o prendé el GPS.'],
            3: ['GPS sin responder', 'El GPS tardó demasiado en responder. Probá de nuevo en unos segundos.']
        };

        function alFallarPosicion(err) {
            var m = MENSAJES_ERROR[err && err.code] ||
                ['Ubicación no disponible', 'No se pudo obtener la posición del dispositivo.'];
            mostrar('error', m[0], esc(m[1]));
        }

        function detener() {
            var s = seg();
            if (s.idWatch !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(s.idWatch);
                s.idWatch = null;
            }
            s.activo = false;
        }

        function iniciar() {
            var s = seg();
            if (!ctx.dom.estadoSeguimiento) return;

            // El navegador exige un origen seguro. En http:// la geolocalización
            // está bloqueada siempre, así que conviene decirlo claro en vez de
            // dejar un GPS que nunca arranca.
            if (typeof navigator.geolocation === 'undefined') {
                mostrar('error', 'No disponible',
                    'Este navegador no soporta geolocalización. Probá con Chrome, Edge o Safari.');
                return;
            }

            if (!window.isSecureContext) {
                mostrar('error', 'Necesita HTTPS',
                    'El navegador solo permite la ubicación en sitios <strong>https</strong>. Abrí el mapa por ' +
                    'la dirección segura para activar el GPS.');
                return;
            }

            mostrar('esperando', 'Buscando GPS…', 'Aceptá el permiso de ubicación del navegador.');

            s.idWatch = navigator.geolocation.watchPosition(
                alRecibirPosicion,
                alFallarPosicion,
                {
                    enableHighAccuracy: true,
                    // Que reutilice un fix de hasta 10 s en vez de esperar uno
                    // nuevo: con intervalo de 5 s, siempre hay algo fresco.
                    maximumAge: 10000,
                    timeout: 20000
                }
            );
        }

        return {
            iniciar: iniciar,
            detener: detener,
            mostrar: mostrar,
            marcarPropio: marcarPropio,
            reportar: reportar,
            velocidadAKmH: velocidadAKmH
        };
    };
})(window);