/**
 * mapa/ruta.js: trazado de la ruta real por calles (OSRM) y progreso del
 * recorrido.
 *
 * Cada traslado traza su ruta contra /api/ruta/real, con caché por par de
 * coordenadas; si el proveedor no responde, dibuja la línea recta entre origen
 * y destino. La traza se guarda por traslado porque sirve para tres cosas: la
 * línea que se ve en el mapa, el cálculo del progreso (qué parte ya se hizo)
 * y el avance de la simulación.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    mod.ruta = function (ctx) {
        var util = mod.util;

        /** Par de coordenadas -> respuesta de OSRM. */
        var cacheRespuestas = {};
        /** traslado_id -> puntos de la traza. */
        var trazas = {};
        /** traslado_id -> fracción 0..1 de la ruta ya recorrida. */
        var progreso = {};

        function claveRuta(oLat, oLng, dLat, dLng) {
            return oLat.toFixed(5) + '_' + oLng.toFixed(5) + '_' + dLat.toFixed(5) + '_' + dLng.toFixed(5);
        }

        function obtenerRuta(oLat, oLng, dLat, dLng) {
            var key = claveRuta(oLat, oLng, dLat, dLng);
            if (cacheRespuestas[key]) {
                return Promise.resolve(cacheRespuestas[key]);
            }

            var url = mod.urls.ruta +
                '?origen_lat=' + oLat + '&origen_lng=' + oLng +
                '&destino_lat=' + dLat + '&destino_lng=' + dLng;

            return fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    cacheRespuestas[key] = data;
                    return data;
                });
        }

        /**
         * Qué parte de la ruta ya recorrió la unidad: busca el punto de la traza
         * más cercano a su posición actual y devuelve la fracción 0..1 del
         * recorrido total. Es lo que permite partir la línea en "hecho" y
         * "falta".
         *
         * El valor se guarda y NUNCA retrocede. El GPS dentro de una ambulancia
         * oscila unos metros, y en el arranque —donde los puntos de la traza
         * están bastante juntos— eso hace que el punto más cercano salte entre
         * el primer y el segundo segmento; sin este tope la parte "recorrida"
         * retrocedería en cada refresco. Para el retroceso de verdad
         * (en_retorno, de vuelta al hospital) el trazo queda completo, que es
         * lo que corresponde.
         */
        function calcularProgreso(trasladoId, lat, lng) {
            var puntos = trazas[trasladoId];
            if (!puntos || puntos.length < 2) return null;
            if (typeof lat !== 'number' || typeof lng !== 'number') return null;

            var total = 0;
            var acumulada = 0;
            var mejorAcumulada = 0;
            var mejorDistancia = Infinity;

            for (var i = 0; i < puntos.length - 1; i++) {
                var tramo = ctx.mapa.distance(puntos[i], puntos[i + 1]);
                total += tramo;

                var f = util.fraccionSobreSegmento(lat, lng, puntos[i], puntos[i + 1]);
                var px = puntos[i][0] + (puntos[i + 1][0] - puntos[i][0]) * f;
                var py = puntos[i][1] + (puntos[i + 1][1] - puntos[i][1]) * f;
                var d = ctx.mapa.distance([lat, lng], [px, py]);

                if (d < mejorDistancia) {
                    mejorDistancia = d;
                    mejorAcumulada = acumulada + tramo * f;
                }

                acumulada += tramo;
            }

            if (total <= 0) return null;

            var fraccion = mejorAcumulada / total;
            var previo = progreso[trasladoId] || 0;
            if (fraccion < previo) fraccion = previo;
            if (fraccion > 1) fraccion = 1;

            progreso[trasladoId] = fraccion;
            return fraccion;
        }

        /**
         * Parte la traza en dos en la fracción f: lo que ya se recorrió y lo que
         * falta. Ambas mitades comparten el punto de corte para que no se vea
         * una costura entre las dos líneas.
         */
        function partir(puntos, f) {
            if (f <= 0) return { recorrido: [], restante: puntos };
            if (f >= 1) return { recorrido: puntos, restante: [] };

            var total = 0;
            for (var i = 0; i < puntos.length - 1; i++) {
                total += ctx.mapa.distance(puntos[i], puntos[i + 1]);
            }
            if (total <= 0) return { recorrido: [], restante: puntos };

            var objetivo = total * f;
            var acumulada = 0;

            for (var j = 0; j < puntos.length - 1; j++) {
                var tramo = ctx.mapa.distance(puntos[j], puntos[j + 1]);
                if (acumulada + tramo >= objetivo) {
                    var local = tramo > 0 ? (objetivo - acumulada) / tramo : 0;
                    var corte = [
                        puntos[j][0] + (puntos[j + 1][0] - puntos[j][0]) * local,
                        puntos[j][1] + (puntos[j + 1][1] - puntos[j][1]) * local
                    ];
                    return {
                        recorrido: puntos.slice(0, j + 1).concat([corte]),
                        restante: [corte].concat(puntos.slice(j + 1))
                    };
                }
                acumulada += tramo;
            }

            return { recorrido: puntos, restante: [] };
        }

        /** La traza de un traslado, para la simulación y el progreso. */
        function trazaDe(trasladoId) {
            return trazas[trasladoId];
        }

        /**
         * Dibuja la ruta de un traslado en la capa de traslados, partida según
         * por dónde va la unidad.
         */
        function dibujar(traslado, avance) {
            if (traslado.destino_lat === null || traslado.destino_lng === null) return;

            var color = cfg.coloresEstado[traslado.estado] || cfg.COLOR_GRIS;
            var oLat = traslado.origen_lat;
            var oLng = traslado.origen_lng;
            var dLat = traslado.destino_lat;
            var dLng = traslado.destino_lng;

            obtenerRuta(oLat, oLng, dLat, dLng).then(function (result) {
                var coords = result.coordinates || [];
                var esReal = !result.fallback && coords.length >= 2;

                var puntos;
                if (esReal) {
                    puntos = [];
                    coords.forEach(function (c) {
                        if (Array.isArray(c) && c.length >= 2) puntos.push([c[0], c[1]]);
                    });
                    esReal = puntos.length >= 2;
                }

                if (!esReal) {
                    // Sin trazada por calles se dibuja la recta entre origen y
                    // destino. La simulación también la recorre, así que se
                    // guarda igual que la real.
                    puntos = [[oLat, oLng], [dLat, dLng]];
                }

                // La traza se guarda por traslado: la simulación la recorre y
                // calcularProgreso() la usa en cada refresco.
                trazas[traslado.id] = puntos;

                var f = (typeof avance === 'number') ? avance : 0;
                var partes = partir(puntos, f);

                // Recorrido: la parte que la unidad ya hizo. Sólida y marcada,
                // para que se lea como lo que ya pasó.
                if (partes.recorrido.length >= 2) {
                    L.polyline(partes.recorrido, {
                        color: color,
                        weight: 5,
                        opacity: 0.95,
                        lineCap: 'round',
                        dashArray: esReal ? null : '8 6'
                    }).addTo(ctx.capas.traslados);
                }

                // Restante: lo que falta, punteado y apagado. Sin esto la ruta
                // se veía entera desde el arranque y no había forma de saber por
                // dónde iba la ambulancia.
                if (partes.restante.length >= 2) {
                    L.polyline(partes.restante, {
                        color: color,
                        weight: esReal ? 3 : 4,
                        opacity: 0.32,
                        dashArray: '6 8'
                    }).addTo(ctx.capas.traslados);
                }
            });
        }

        return {
            dibujar: dibujar,
            calcularProgreso: calcularProgreso,
            trazaDe: trazaDe
        };
    };
})(window);