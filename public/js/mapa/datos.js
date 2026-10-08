/**
 * mapa/datos.js: consulta a /api/mapa y el refresco automático.
 *
 * Es un setTimeout encadenado y no un setInterval a propósito: mientras el modal
 * de registro está abierto el refresco se pausa, porque cada pasada redibuja
 * las capas del mapa y se comería el pin que el usuario está moviendo. Al
 * cerrar el modal, el ciclo sigue solo.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};

    mod.datos = function (ctx, api) {
        var timer = null;
        var pausado = false;

        function pintarError() {
            if (ctx.dom.estadoBar) ctx.dom.estadoBar.innerHTML = '<span>Sin conexión</span>';
        }

        function pintarActualizando() {
            if (ctx.dom.estadoBar) {
                ctx.dom.estadoBar.innerHTML = '<div class="punto-pulso"></div><span>Actualizando...</span>';
            }
        }

        function actualizar() {
            return fetch(mod.urls.mapa)
                .then(function (r) { return r.json(); })
                .then(function (datos) {
                    var conductores = datos.conductores || [];

                    // Traslados y conductores llegan en dos listas separadas. Se
                    // indexan las posiciones por conductor_id para que, al pintar
                    // cada traslado, se pueda calcular el progreso con la unidad
                    // que le corresponde y no con la de otro.
                    var posPorConductor = {};
                    conductores.forEach(function (c) {
                        posPorConductor[c.conductor_id] = c;
                    });

                    api.render.ubicaciones(datos.ubicaciones || []);
                    ctx.traslados = datos.traslados || [];

                    // El mapa dibuja solo lo que está pasando ahora. El paciente
                    // además recibe sus traslados ya terminados —"¿ya me
                    // trasladaron?"— y sin esto el mapa se le llenaba de rutas
                    // viejas: un marcador en el origen, otro en el destino y la
                    // línea por la calle de cada traslado anterior. El histórico
                    // sigue íntegro en la lista lateral; lo que no va es pintado.
                    var paraMapa = ctx.traslados.filter(mod.util.esActivoAhora);

                    api.render.traslados(paraMapa, posPorConductor);
                    api.render.conductores(conductores);
                    api.panel.actualizar(ctx.traslados);

                    // El panel de historial, si está abierto, se actualiza con lo
                    // mismo para que no muestre un traslado ya completado.
                    if (api.historial.abierto()) {
                        api.historial.pintar(ctx.traslados);
                    }

                    return datos;
                })
                .catch(function (err) {
                    pintarError();
                    console.error('Error al obtener datos del mapa:', err);
                });
        }

        function programar() {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }
            if (pausado) return;

            timer = setTimeout(function () {
                pintarActualizando();
                actualizar().then(programar, programar);
            }, mod.ajustes.polling);
        }

        /** El modal de registro pausa el refresco mientras está abierto. */
        function pausar(valor) {
            pausado = valor;

            if (valor) {
                if (timer !== null) {
                    clearTimeout(timer);
                    timer = null;
                }
                return;
            }

            // Al retomar se fuerza una pasada inmediata: el traslado que se acaba
            // de registrar tiene que verse sin esperar 5 segundos.
            actualizar().then(programar, programar);
        }

        return {
            actualizar: actualizar,
            programar: programar,
            pausar: pausar,
            pintarActualizando: pintarActualizando
        };
    };
})(window);