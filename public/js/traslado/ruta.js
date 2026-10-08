/**
 * traslado/ruta.js: la ruta real por calles del modal y su resumen
 * (kilómetros, minutos y hora de llegada).
 *
 * Dos controles de concurrencia:
 *   - ultimaClave evita pedir dos veces la misma ruta (mover un pin un
 *     centímetro no puede cambiar la clave: se redondea a 5 decimales, que es
 *     justo lo que usa el backend como clave de caché de OSRM).
 *   - token descarta la respuesta si mientras tanto se pidió otra, que es lo
 *     que pasa al arrastrar rápido: si no, la ruta vieja se dibuja encima de la
 *     nueva.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var fmt = root.ElyraFmt;

    mod.ruta = function (ctx, api) {
        var urls = root.ElyraMapaMod.urls;

        function clave(o, d) {
            return [o.lat, o.lng, d.lat, d.lng]
                .map(function (n) { return n.toFixed(5); })
                .join('_');
        }

        function recalcular() {
            var estadoRuta = ctx.ruta;

            if (!api.extremos.tieneCoordenadas(ctx.origen) || !api.extremos.tieneCoordenadas(ctx.destino)) {
                ctx.capaRutaModal.clearLayers();
                mostrarResumen(null, null);
                return;
            }

            var o = ctx.origen;
            var d = ctx.destino;
            var claveRuta = clave(o, d);

            if (claveRuta === estadoRuta.ultimaClave) return;
            estadoRuta.ultimaClave = claveRuta;

            var miToken = ++estadoRuta.token;

            if (!ctx.puente) return;

            var url = urls.ruta +
                '?origen_lat=' + o.lat + '&origen_lng=' + o.lng +
                '&destino_lat=' + d.lat + '&destino_lng=' + d.lng;

            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (miToken !== estadoRuta.token || !data) return;

                    var coords = (data.coordinates || []).filter(function (c) {
                        return Array.isArray(c) && c.length >= 2;
                    });

                    ctx.capaRutaModal.clearLayers();

                    var esReal = !data.fallback && coords.length >= 2;
                    var puntos = esReal ? coords : [[o.lat, o.lng], [d.lat, d.lng]];

                    ctx.L.polyline(puntos, {
                        color: '#3B5998',
                        weight: 4,
                        opacity: 0.85,
                        dashArray: esReal ? null : '8 6'
                    }).addTo(ctx.capaRutaModal);

                    encuadrar(o, d);
                    mostrarResumen(
                        esReal ? data.distance_km : null,
                        esReal ? data.duration_min : null
                    );
                })
                .catch(function () {
                    if (miToken === estadoRuta.token) mostrarResumen(null, null);
                });
        }

        function esPantallaChica() {
            return window.innerWidth < 992;
        }

        /**
         * Como el modal ocupa la franja derecha, la vista se desplaza para que
         * el recorrido quede en la parte visible del mapa y no debajo del
         * formulario.
         */
        function panPorModal() {
            var modal = ctx.dom.modal;
            var anchoModal = modal && !esPantallaChica() ? modal.offsetWidth : 0;

            if (anchoModal > 0) {
                // setView centra en el punto; panBy lo corre a la izquierda
                // para dejarlo en el medio de la zona visible.
                ctx.mapa.panBy([anchoModal / 2, 0], { animate: true, duration: 0.4 });
            }
        }

        /** Encuadra los dos extremos del recorrido. */
        function encuadrar(o, d) {
            ctx.mapa.setView([(o.lat + d.lat) / 2, (o.lng + d.lng) / 2], 14, { animate: true });
            panPorModal();
        }

        /** Recentra la cámara en un punto suelto (el de la ubicación del GPS). */
        function fijarVista(lat, lng) {
            ctx.mapa.setView([lat, lng], 16, { animate: true });
            panPorModal();
        }

        function mostrarResumen(km, min) {
            var dom = ctx.dom;
            ctx.ruta.minutos = min;

            if (!dom.resumenRuta) return;

            if (km === null || min === null) {
                dom.resumenRuta.hidden = true;
                return;
            }

            dom.resumenRuta.hidden = false;
            dom.resumenKm.textContent = km.toFixed(1) + ' km';
            dom.resumenMin.textContent = fmt.formatearMinutos(min);
            dom.resumenLlegada.textContent = calcularLlegada(min);
        }

        /** Hora de llegada = hora de salida elegida + duración de la ruta. */
        function calcularLlegada(minutos) {
            var hora = api.ui.$('inputHora');
            if (!hora || !hora.value) return '—';

            var partes = hora.value.split(':');
            if (partes.length < 2) return '—';

            var total = parseInt(partes[0], 10) * 60 + parseInt(partes[1], 10) + Math.round(minutos);
            var dia = Math.floor(total / 1440) > 0 ? ' (+1d)' : '';
            total = total % 1440;

            return fmt.dosDigitos(Math.floor(total / 60)) + ':' + fmt.dosDigitos(total % 60) + dia;
        }

        return {
            recalcular: recalcular,
            encuadrar: encuadrar,
            fijarVista: fijarVista,
            mostrarResumen: mostrarResumen,
            calcularLlegada: calcularLlegada,

            /** Minutos de la última ruta, para recalcular la hora de llegada. */
            minutos: function () { return ctx.ruta.minutos; },
            resumenVisible: function () { return !!ctx.dom.resumenRuta && !ctx.dom.resumenRuta.hidden; },

            /** Obliga a que el próximo recalcular vuelva a pedir la ruta. */
            invalidar: function () { ctx.ruta.ultimaClave = ''; }
        };
    };
})(window);