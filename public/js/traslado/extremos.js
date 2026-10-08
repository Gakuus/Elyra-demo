/**
 * traslado/extremos.js: los pines de origen y destino del modal.
 *
 * Un extremo son tres cosas a la vez: la coordenada (lo que se guarda), el
 * nombre legible (lo que se muestra y se manda) y el pin arrastrable. Los tres
 * se actualizan juntos desde acá, con fijar(), para que no puedan quedar
 * desincronizados.
 *
 * Cada extremo se puede poner de tres formas, igual que en Google Maps:
 *   - "Usar mi ubicación": GPS del navegador + nombre por geocodificación
 *     inversa.
 *   - Escribiendo: el input autocompleta contra /api/geocodificar.
 *   - Arrastrando el pin sobre el mapa (o con un click), que además pide el
 *     nombre del lugar.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var fmt = root.ElyraFmt;

    mod.extremos = function (ctx, api) {
        var esc = fmt.escaparHtml;
        var urls = root.ElyraMapaMod.urls;

        function estadoDe(cual) {
            return cual === 'origen' ? ctx.origen : ctx.destino;
        }

        function tieneCoordenadas(estado) {
            return estado.lat !== null && estado.lng !== null;
        }

        function icono(color, letra) {
            return ctx.L.divIcon({
                className: '',
                html: '<div style="background:' + color + ';width:26px;height:26px;border-radius:50% 50% 50% 0;' +
                    'transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);' +
                    'display:flex;align-items:center;justify-content:center;">' +
                    '<span style="transform:rotate(45deg);color:#fff;font-weight:700;font-size:12px;">' + letra + '</span></div>',
                iconSize: [26, 26],
                iconAnchor: [13, 26],
                popupAnchor: [0, -26]
            });
        }

        /** Guarda un extremo y redibuja su pin. */
        function fijar(cual, lat, lng, label) {
            var estado = estadoDe(cual);
            estado.lat = lat;
            estado.lng = lng;
            estado.label = label || '';

            var dom = ctx.dom;
            var oculto = cual === 'origen' ? dom.hiddenOrigenLat : dom.hiddenDestinoLat;
            var ocultoLng = cual === 'origen' ? dom.hiddenOrigenLng : dom.hiddenDestinoLng;
            oculto.value = lat;
            ocultoLng.value = lng;

            var campo = cual === 'origen' ? dom.inOrigen : dom.inDestino;
            if (campo && campo.value !== estado.label) {
                campo.value = estado.label;
            }

            var coord = cual === 'origen' ? dom.coordOrigen : dom.coordDestino;
            if (coord) {
                coord.textContent = fmt.formatearCoordenada(lat, lng);
            }

            var marcador = ctx.marcadores[cual];
            if (marcador) {
                marcador.setLatLng([lat, lng]);
            } else {
                var esOrigen = cual === 'origen';
                marcador = ctx.L.marker([lat, lng], {
                    icon: icono(esOrigen ? '#4CAF50' : '#f44336', esOrigen ? 'A' : 'B'),
                    draggable: true,
                    autoPan: true,
                    zIndexOffset: 1000
                }).addTo(ctx.capaExtremos);

                marcador.on('dragend', function () {
                    var pos = marcador.getLatLng();
                    fijar(cual, pos.lat, pos.lng, null);
                    ctx.editando[cual] = false;
                    pedirNombre(cual);
                    api.ruta.recalcular();
                });

                ctx.marcadores[cual] = marcador;
            }

            api.ruta.recalcular();
        }

        function limpiar(cual) {
            var estado = estadoDe(cual);
            estado.lat = null;
            estado.lng = null;
            estado.label = '';
            ctx.editando[cual] = false;

            var dom = ctx.dom;
            var oculto = cual === 'origen' ? dom.hiddenOrigenLat : dom.hiddenDestinoLat;
            var ocultoLng = cual === 'origen' ? dom.hiddenOrigenLng : dom.hiddenDestinoLng;
            oculto.value = '';
            ocultoLng.value = '';

            var campo = cual === 'origen' ? dom.inOrigen : dom.inDestino;
            if (campo) campo.value = '';

            var coord = cual === 'origen' ? dom.coordOrigen : dom.coordDestino;
            if (coord) coord.textContent = '';

            var marcador = ctx.marcadores[cual];
            if (marcador) {
                ctx.capaExtremos.removeLayer(marcador);
                ctx.marcadores[cual] = null;
            }
        }

        /**
         * Nombre de un pin que se movió a mano. Se consulta al backend con una
         * pausa para no pegarle una petición por cada píxel de arrastre. Si el
         * proveedor no responde, se deja el nombre genérico.
         */
        function pedirNombre(cual) {
            var estado = estadoDe(cual);
            if (!tieneCoordenadas(estado)) return;

            if (ctx.inversa.timer !== null) clearTimeout(ctx.inversa.timer);

            ctx.inversa.timer = setTimeout(function () {
                // Si el usuario está escribiendo en ese momento, su texto manda.
                if (ctx.editando[cual]) return;

                fetch(urls.geocodificar + '?lat=' + estado.lat + '&lng=' + estado.lng)
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        var res = json && json.resultados ? json.resultados[0] : null;

                        if (res && !ctx.editando[cual]) {
                            fijar(cual, res.lat, res.lng, res.label);
                        } else if (!estado.label) {
                            fijar(cual, estado.lat, estado.lng, 'Punto en el mapa');
                        }
                    })
                    .catch(function () {
                        if (!estado.label) {
                            fijar(cual, estado.lat, estado.lng, 'Punto en el mapa');
                        }
                    });
            }, mod.ajustes.esperaReversa);
        }

        return {
            estadoDe: estadoDe,
            tieneCoordenadas: tieneCoordenadas,
            fijar: fijar,
            limpiar: limpiar,
            pedirNombre: pedirNombre
        };
    };
})(window);