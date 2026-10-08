/**
 * mapa/simulacion.js: recorrer un traslado sin salir con el celular.
 *
 * Avanza una ambulancia falsa a lo largo de la traza real y escribe en la base
 * por el mismo endpoint que el GPS, así que el despacho ve el movimiento de
 * verdad y no un dibujo local. Al llegar, marca el traslado como "en destino",
 * que es el mismo cambio de estado que hace el botón del panel.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;
    var aj = mod.ajustes;

    mod.simulacion = function (ctx, api) {
        var esc = mod.util.escaparHtml;

        function sim() {
            return ctx.simulacion;
        }

        function estaActiva() {
            return sim().timer !== null;
        }

        /** El traslado que corresponde simular, o null si no hay ninguno activo. */
        function obtenerTrasladoParaSimular() {
            var candidatos = ctx.traslados.filter(function (t) {
                return mod.util.esActivoAhora(t) &&
                    t.origen_lat !== null && t.origen_lng !== null &&
                    t.destino_lat !== null && t.destino_lng !== null;
            });

            if (!candidatos.length) return null;

            // Prioridad 1: el traslado pedido explícitamente (recién registrado).
            if (sim().idPreferido !== null) {
                for (var j = 0; j < candidatos.length; j++) {
                    if (candidatos[j].id === sim().idPreferido) return candidatos[j];
                }
            }

            // Prioridad 2: si el usuario tiene abierto un traslado en la lista,
            // se simula ese y no el primero que aparezca: con varios traslados,
            // simular el que no está mirando es la forma más fácil de pensar que
            // "no se mueve".
            var lista = ctx.dom.listaTraslados;
            var activo = lista && lista.querySelector('.tarjeta-traslado.active');
            if (activo) {
                var idActivo = parseInt(activo.getAttribute('data-traslado'), 10);
                for (var i = 0; i < candidatos.length; i++) {
                    if (candidatos[i].id === idActivo) return candidatos[i];
                }
            }

            return candidatos[0];
        }

        /**
         * Encuadra la cámara en el recorrido completo, para que se vea de una
         * vez a dónde va la unidad y de dónde salió.
         *
         * No dibuja un trazo propio: el recorrido va en la capa de traslados,
         * partido entre lo hecho y lo que falta, y dibujarlo otra vez acá encima
         * solo tapaba esa división.
         */
        function encuadrar() {
            var s = sim().estado;
            if (!s || !s.puntos || s.puntos.length < 2) return;
            ctx.mapa.fitBounds(L.latLngBounds(s.puntos), { padding: [40, 40] });
        }

        function detener(mensaje) {
            var s = seg();

            if (sim().timer !== null) {
                clearInterval(sim().timer);
                sim().timer = null;
            }
            sim().estado = null;

            // Borra el marcador y el radio de precisión simulados: si quedan, el
            // conductor ve una ambulancia parada en un lugar donde no está.
            if (s.marcadorPropio && ctx.capas.propia) {
                ctx.capas.propia.removeLayer(s.marcadorPropio);
                s.marcadorPropio = null;
            }
            if (s.circuloPrecision && ctx.capas.propia) {
                ctx.capas.propia.removeLayer(s.circuloPrecision);
                s.circuloPrecision = null;
            }
            s.ultimaPosicion = null;
            s.activo = false;

            // Al detenerse (a mano o por llegar al destino) se olvida el traslado
            // pedido: si no, el próximo "Simular" volvería a engancharse al viejo.
            sim().idPreferido = null;

            if (ctx.dom.btnSimular) {
                ctx.dom.btnSimular.innerHTML = '<i class="bi bi-broadcast"></i> Simular recorrido';
                ctx.dom.btnSimular.classList.remove('activo');
            }

            if (mensaje) {
                api.seguimiento.mostrar('esperando', 'Simulación detenida', esc(mensaje));
            }

            // Vuelve al GPS real: al empezar la simulación se había cortado el
            // watchPosition para que no se peleasen por el marcador.
            if (ctx.esConductor && s.idWatch === null) {
                api.seguimiento.iniciar();
            }
        }

        function seg() {
            return ctx.seguimiento;
        }

        /**
         * Avanza la ambulancia falsa la distancia dada a lo largo de la traza.
         * El sobrante queda guardado para el próximo tick, así el movimiento no
         * se pierde entre ticks. Devuelve false cuando llegó al final.
         */
        function avanzar(metros) {
            var s = sim().estado;
            var puntos = s.puntos;
            s.restante += metros;

            // Consume segmentos completos mientras de el: la traza de OSRM
            // viene con cientos de puntos y casi todos son mucho más cortos que
            // el avance de un tick.
            var intentos = 0;
            while (s.segmento < puntos.length - 1 && intentos < puntos.length) {
                intentos++;
                var tramo = ctx.mapa.distance(puntos[s.segmento], puntos[s.segmento + 1]);
                if (tramo <= 0.5) {
                    // Segmento degenerado (puntos repetidos): lo saltea.
                    s.segmento++;
                    continue;
                }
                if (s.restante < tramo) break;
                s.restante -= tramo;
                s.segmento++;
            }

            if (s.segmento >= puntos.length - 1) return false;

            // Quedó a mitad de camino: interpola el punto exacto dentro del
            // segmento.
            var desde = puntos[s.segmento];
            var hasta = puntos[s.segmento + 1];
            var largo = ctx.mapa.distance(desde, hasta);
            var f = largo > 0 ? s.restante / largo : 0;
            s.puntoActual = [
                desde[0] + (hasta[0] - desde[0]) * f,
                desde[1] + (hasta[1] - desde[1]) * f
            ];
            return true;
        }

        /**
         * Un paso de la simulación (uno por segundo).
         */
        function paso() {
            if (!sim().estado) return;

            var metrosPorTick = (aj.simVelocidad * aj.simAceleracion / 3.6) * (aj.simTick / 1000);

            if (!avanzar(metrosPorTick)) {
                // Llegó al final del recorrido. Se anotan traslado y código
                // ANTES de detener(), que deja el estado en null.
                var terminado = sim().estado.traslado;
                detener('Llegó al destino de ' + (terminado ? terminado.codigo : '') + '.');

                // Llegar es el gesto de "en destino": es el mismo cambio de
                // estado que hace el botón del panel.
                if (terminado && terminado.estado !== 'en_destino') {
                    marcarEstado(terminado.id, 'en_destino');
                }
                return;
            }

            seg().activo = true;

            // marcarPropio() es la que calcula el rumbo a partir del avance
            // sobre la traza, así que el heading se manda después de llamarla,
            // nunca antes.
            var punto = sim().estado.puntoActual;
            api.seguimiento.marcarPropio(punto[0], punto[1], 8);
            api.seguimiento.reportar(punto[0], punto[1], 8, aj.simVelocidad, seg().rumbo);
        }

        /**
         * Manda el traslado al estado que corresponde al avance de la simulación,
         * por el mismo endpoint que usan los botones del panel.
         *
         * Sin esto el traslado se queda en 'pendiente' durante todo el recorrido.
         * Y eso no es solo cosmético: guardarUbicacionConductor() resuelve el
         * trasladoId del conductor mirando el estado, así que mientras está
         * pendiente la posición se guardaría con traslado_id NULL — la
         * ambulancia se movería en el mapa del despacho sin código al lado y el
         * paciente no vería nada, porque su fila se filtra justamente por esa
         * columna.
         *
         * Es un cambio de estado real (queda en historial_estado), igual que si
         * lo hubiera hecho un dedo en el botón.
         */
        function marcarEstado(trasladoId, estado) {
            var data = new FormData();
            data.append('traslado_id', trasladoId);
            data.append('estado', estado);

            return fetch(mod.urls.estado, { method: 'POST', headers: window.Elyra.csrfHeaders(), body: data })
                .then(function (r) {
                    return r.json().catch(function () { return { ok: false }; });
                })
                .then(function (json) {
                    if (json && json.ok) {
                        // El traslado cambió de estado: su color en el mapa
                        // cambia y la ruta se vuelve a pintar. El refresco normal
                        // NO alcanza acá porque reiniciaría la simulación.
                        return api.datos.actualizar();
                    }
                    return null;
                })
                .catch(function () { return null; });
        }

        function iniciar(idTraslado) {
            if (idTraslado !== undefined && idTraslado !== null) {
                sim().idPreferido = idTraslado;
            }

            var traslado = obtenerTrasladoParaSimular();
            if (!traslado) {
                api.seguimiento.mostrar('esperando', 'Sin traslado',
                    'Para simular hace falta un traslado activo con origen y destino. Pedile a un administrador que cree uno.');
                return;
            }

            var puntos = api.ruta.trazaDe(traslado.id);
            if (!puntos || puntos.length < 2) {
                // Todavía no llegó la ruta de OSRM: se reintenta en un segundo.
                api.seguimiento.mostrar('esperando', 'Calculando ruta…', 'Esperando el trazado de ' +
                    esc(traslado.codigo) + ' para empezar la simulación.');
                setTimeout(function () {
                    if (!estaActiva()) iniciar();
                }, 1000);
                return;
            }

            // El GPS real y la simulación se pisan: si se está simulando, el
            // watchPosition se corta para que no se pelen por el marcador.
            var s = seg();
            if (s.idWatch !== null) api.seguimiento.detener();

            // La ambulancia simulada arranca en el origen con el color del
            // estado del traslado, y sin rumbo previo hasta que se mueva un poco.
            s.color = cfg.coloresEstado[traslado.estado] || cfg.COLOR_PROPIO;
            s.rumbo = 0;
            s.ultimaPosicion = null;

            sim().estado = {
                traslado: traslado,
                puntos: puntos,
                segmento: 0,
                restante: 0,
                puntoActual: puntos[0]
            };

            if (ctx.dom.btnSimular) {
                ctx.dom.btnSimular.innerHTML = '<i class="bi bi-stop-circle"></i> Detener simulación';
                ctx.dom.btnSimular.classList.add('activo');
            }
            api.seguimiento.mostrar('simulando', 'Simulando', 'Recorriendo <strong>' + esc(traslado.codigo) +
                '</strong> a ' + aj.simVelocidad + ' km/h simulados. El despacho ve el movimiento.');

            // Si el traslado todavía no arrancó, lo arranca la simulación: es el
            // mismo gesto que el botón "Iniciar". Sin esto la unidad se movería
            // por el mapa pero su posición se guardaría sin traslado asociado.
            if (traslado.estado === 'pendiente') {
                marcarEstado(traslado.id, 'en_curso');
            }

            encuadrar();
            seg().ultimoEnvioMs = 0; // Que el primer paso salga de inmediato.
            paso();
            sim().timer = setInterval(paso, aj.simTick);
        }

        /** Botón "Simular recorrido" / "Detener simulación". */
        function alternar() {
            if (estaActiva()) {
                detener('La simulación se detuvo a pedido.');
            } else {
                iniciar();
            }
        }

        return {
            iniciar: iniciar,
            detener: detener,
            alternar: alternar,
            estaActiva: estaActiva,
            /** El punto donde está la ambulancia simulada, o null. */
            puntoActual: function () {
                return sim().estado ? sim().estado.puntoActual : null;
            },
            /** Fija el traslado a simular (recién registrado desde el modal). */
            preferir: function (idTraslado) {
                sim().idPreferido = idTraslado;
            }
        };
    };
})(window);