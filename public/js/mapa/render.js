/**
 * mapa/render.js: lo que se dibuja en el mapa en cada refresco.
 *
 * Tres capas, en el orden en que se apilan: hospitales (abajo), traslados (en
 * el medio) y conductores (arriba). Cada capa se borja y se vuelve a pintar en
 * cada poll salvo los marcadores de conductor, que se reutilizan para que la
 * ambulancia pueda moverse interpolada en vez de saltar.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    mod.render = function (ctx, api) {
        var util = mod.util;
        var esc = util.escaparHtml;

        function ubicaciones(lista) {
            ctx.capas.ubicaciones.clearLayers();

            // Los marcadores del hospital van por debajo de los traslados: si
            // no, una ambulancia en el hospital queda tapada por el edificio.
            var z = -100;

            util.agruparPorCoordenada(lista).forEach(function (grupo) {
                var nombres = grupo.nombres.slice().sort();
                var contenido = '<strong>' + esc(util.tituloGrupo(nombres)) + '</strong>' +
                    (nombres.length > 1
                        ? '<ul class="lista-pabellones">' +
                          nombres.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') +
                          '</ul>'
                        : '');

                L.marker([grupo.latitud, grupo.longitud], {
                    icon: api.iconos.hospital(),
                    zIndexOffset: z
                })
                    .bindPopup(contenido)
                    .addTo(ctx.capas.ubicaciones);
            });
        }

        /**
         * Traslados: marcador en el origen, marcador en el destino y la ruta
         * partida según por dónde va la unidad.
         *
         * posPorConductor indexa la última posición conocida por conductor_id:
         * es lo que permite calcular el progreso de este traslado y no del de
         * al lado cuando hay varias ambulancias en el mapa.
         */
        function traslados(lista, posPorConductor) {
            ctx.capas.traslados.clearLayers();

            lista.forEach(function (t) {
                if (t.origen_lat === null || t.origen_lng === null) return;

                var color = cfg.coloresEstado[t.estado] || cfg.COLOR_GRIS;

                // Los pines de origen/destino van por debajo de las ambulancias:
                // son referencia de la ruta, no el elemento que hay que seguir.
                var marcador = L.marker([t.origen_lat, t.origen_lng], {
                    icon: api.iconos.origen(),
                    zIndexOffset: 100
                }).addTo(ctx.capas.traslados);
                marcador._trasladoId = t.id;

                marcador.bindPopup(
                    '<div style="font-weight:600;">' + esc(t.codigo) +
                    ' <span class="badge" style="font-size:.72rem;color:#fff;background:' + color + ';">' +
                    esc(util.nombreEstado(t.estado)) + '</span></div>' +
                    '<div class="popup-conductor">' + esc(t.conductor_nombre) +
                    (t.copiloto_nombre ? ' / ' + esc(t.copiloto_nombre) : '') +
                    (t.vehiculo_patente ? ' · ' + esc(t.vehiculo_patente) : '') + '</div>' +
                    '<div class="popup-route">' +
                    '<i class="bi bi-circle-fill" style="font-size:6px;color:#4CAF50;"></i> ' + esc(t.origen) +
                    ' <i class="bi bi-arrow-right" style="font-size:10px;"></i> ' +
                    '<i class="bi bi-circle-fill" style="font-size:6px;color:#f44336;"></i> ' + esc(t.destino) +
                    '</div>' +
                    (util.textoHora(t.hora_salida_efectiva)
                        ? '<div style="font-size:0.8rem;color:#666;margin-top:4px;"><i class="bi bi-clock me-1"></i>Salida ' +
                          esc(util.textoHora(t.hora_salida_efectiva)) + '</div>'
                        : ''),
                    { maxWidth: 280 }
                );

                if (t.destino_lat === null || t.destino_lng === null) return;

                L.marker([t.destino_lat, t.destino_lng], {
                    icon: api.iconos.destino(),
                    zIndexOffset: 100
                })
                    .bindPopup('<strong>Destino:</strong> ' + esc(t.destino))
                    .addTo(ctx.capas.traslados);

                // La posición viva de la unidad es la que parte la ruta. Si no
                // hay ninguna (aún no reportó, o sin señal), se dibuja entera
                // como pendiente.
                var pos = posPorConductor ? posPorConductor[t.conductor_id] : null;
                var progreso = pos ? api.ruta.calcularProgreso(t.id, pos.latitud, pos.longitud) : null;

                api.ruta.dibujar(t, progreso);
            });
        }

        /**
         * Posiciones de los conductores vistos desde otros navegadores.
         *
         * El ícono NO se pone en la coordenada nueva directo: se encola una
         * interpolación corta para que se vea avanzar en vez de saltar cada
         * 5 segundos.
         */
        function conductores(lista) {
            var idsExistentes = {};
            var ahora = Date.now();

            lista.forEach(function (c) {
                var latlng = [c.latitud, c.longitud];
                var estado = c.traslado_estado || '';
                var sinSenal = !!c[cfg.ESTADO_SIN_SENAL];

                // OJO: se marca como visto ANTES de cualquier return. Si la fila
                // propia del conductor se saltea más abajo (porque su posición la
                // dibuja el marcador local, que se mueve sin delay del polling),
                // el cleanup del final la tomaba por un conductor que se fue y
                // le borraba el marcador recién creado.
                idsExistentes[c.conductor_id] = true;

                if (c.conductor_id === ctx.usuarioId && ctx.seguimiento.activo) {
                    return;
                }

                // El rumbo lo manda el navegador con cada fix (o la simulación
                // con el ángulo que calcula). Sin él la ambulancia queda siempre
                // apuntando al norte y no se lee como algo en movimiento.
                var rumbo = (typeof c.heading === 'number' && !isNaN(c.heading)) ? c.heading : null;

                // Se calcula una vez y se guarda en el marcador: sirve para no
                // repintar el ícono cuando el estado no cambió (setIcon()
                // recrea el nodo y el marcador parpadea).
                var estadoPintado = (estado || '') + '|' + (sinSenal ? '1' : '0');
                var marcador = ctx.marcadoresConductores[c.conductor_id];

                if (!marcador) {
                    marcador = L.marker(latlng, {
                        icon: api.iconos.conductor(estado, sinSenal, rumbo),
                        zIndexOffset: 500
                    }).addTo(ctx.capas.conductores);
                    marcador._conductorId = c.conductor_id;
                    marcador._estadoPintado = estadoPintado;
                    ctx.marcadoresConductores[c.conductor_id] = marcador;
                } else {
                    api.animacion.mover(c.conductor_id, marcador, latlng, rumbo, ahora);

                    // El giro no va por setIcon(): se rota el div interno, que
                    // deja el mismo nodo en el mapa.
                    if (marcador._estadoPintado !== estadoPintado) {
                        marcador._estadoPintado = estadoPintado;
                        marcador.setIcon(api.iconos.conductor(estado, sinSenal, rumbo));
                    }
                }

                api.iconos.girar(marcador, rumbo);

                marcador.setPopupContent(
                    '<div style="font-weight:600;">' + (c.traslado_codigo
                        ? esc(c.traslado_codigo)
                        : 'Sin traslado activo') + '</div>' +
                    '<div class="popup-conductor">' + esc(c.conductor_nombre) + '</div>' +
                    (c.traslado_origen
                        ? '<div class="popup-route">' +
                          '<i class="bi bi-circle-fill" style="font-size:6px;color:#4CAF50;"></i> ' + esc(c.traslado_origen) +
                          ' <i class="bi bi-arrow-right" style="font-size:10px;"></i> ' +
                          '<i class="bi bi-circle-fill" style="font-size:6px;color:#f44336;"></i> ' + esc(c.traslado_destino) +
                          '</div>'
                        : '') +
                    (c.velocidad !== null && c.velocidad !== undefined
                        ? '<div style="font-size:0.8rem;color:#666;margin-top:4px;">' + c.velocidad.toFixed(1) + ' km/h</div>'
                        : '') +
                    (sinSenal
                        ? '<div style="font-size:0.8rem;color:#F57C00;margin-top:4px;"><i class="bi bi-exclamation-triangle me-1"></i>' +
                          'Sin señal: no reporta posición</div>'
                        : '')
                );
            });

            // Limpieza: los conductores que desaparecieron de la respuesta.
            Object.keys(ctx.marcadoresConductores).forEach(function (id) {
                var conductorId = parseInt(id, 10);
                if (idsExistentes[conductorId]) return;

                ctx.capas.conductores.removeLayer(ctx.marcadoresConductores[id]);
                delete ctx.marcadoresConductores[id];

                // La animación se corta con el marcador: si no, el reloj la
                // seguiría moviendo algo que ya no está en la capa.
                api.animacion.cortar(conductorId);
            });
        }

        return {
            ubicaciones: ubicaciones,
            traslados: traslados,
            conductores: conductores
        };
    };
})(window);