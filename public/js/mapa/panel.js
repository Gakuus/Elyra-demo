/**
 * mapa/panel.js: la lista de traslados del panel lateral y sus botones de
 * estado.
 *
 * El contador describe la lista que se ve, que es lo que anuncia el rótulo de
 * arriba ("Mis traslados" para el paciente, "Traslados activos" para el
 * personal).
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    mod.panel = function (ctx, api) {
        var util = mod.util;
        var esc = util.escaparHtml;

        /**
         * Línea de resumen del recorrido en la tarjeta de un traslado:
         * distancia, duración estimada y hora de llegada prevista. Si el traslado
         * se registró sin ruta calculada, muestra el aviso para que quede claro
         * que falta.
         */
        function fichaRecorrido(t) {
            var km = typeof t.distancia_km === 'number' ? t.distancia_km : null;
            var min = typeof t.duracion_min === 'number' ? t.duracion_min : null;

            if (km === null && min === null) {
                return '<div class="ficha-recorrido vacio"><i class="bi bi-exclamation-circle"></i> Sin ruta calculada</div>';
            }

            var salida = util.textoHora(t.hora_salida_estimada);
            var llegada = util.textoHora(t.hora_llegada_estimada);

            return '<div class="ficha-recorrido">' +
                (km !== null ? '<span><i class="bi bi-signpost-split"></i> ' + km.toFixed(1) + ' km</span>' : '') +
                (min !== null ? '<span><i class="bi bi-clock"></i> ' + util.formatearMinutos(min) + '</span>' : '') +
                (salida && llegada ? '<span class="horario">' + salida +
                    ' <i class="bi bi-arrow-right"></i> ' + llegada + '</span>' : '') +
                '</div>';
        }

        /** Tarjeta de un traslado: la comparten la lista del mapa y el historial. */
        function lineaRuta(t) {
            return '<div class="route">' +
                '<i class="bi bi-circle-fill" style="font-size:5px;color:#4CAF50;"></i> ' + esc(t.origen) +
                ' <i class="bi bi-arrow-right" style="font-size:9px;"></i> ' +
                '<i class="bi bi-circle-fill" style="font-size:5px;color:#f44336;"></i> ' + esc(t.destino) +
                '</div>';
        }

        function lineaPersonal(t) {
            return esc(t.conductor_nombre) +
                (t.copiloto_nombre ? ' / ' + esc(t.copiloto_nombre) : '') +
                (t.vehiculo_patente ? ' · ' + esc(t.vehiculo_patente) : '');
        }

        function badgeEstado(estado) {
            var color = cfg.coloresEstado[estado] || cfg.COLOR_GRIS;
            return ' <span class="badge" style="font-size:.72rem;color:#fff;background:' + color + ';">' +
                esc(util.nombreEstado(estado)) + '</span>';
        }

        function actualizar(lista) {
            var dom = ctx.dom;
            if (!dom.listaTraslados) return;

            if (lista.length === 0) {
                dom.listaTraslados.innerHTML = ctx.esPaciente
                    ? '<div class="mapa-vacio"><i class="bi bi-geo-alt"></i>No tenés traslados registrados</div>'
                    : '<div class="mapa-vacio"><i class="bi bi-geo-alt"></i>Sin traslados activos</div>';
                dom.contadorActivos.textContent = '0 traslados';
                return;
            }

            // Cuando además hay traslados ya terminados se aclara el desglose:
            // es lo que el mapa está mostrando ahora.
            var activos = lista.filter(util.esActivoAhora).length;
            if (ctx.esPaciente && activos !== lista.length) {
                dom.contadorActivos.textContent = lista.length + ' en total · ' + activos + ' en curso';
            } else {
                dom.contadorActivos.textContent = lista.length + ' traslado' + (lista.length !== 1 ? 's' : '');
            }

            var html = '';

            lista.forEach(function (t) {
                html +=
                    '<div class="tarjeta-traslado" data-traslado="' + t.id + '">' +
                    '<div class="codigo">' + esc(t.codigo) + badgeEstado(t.estado) + '</div>' +
                    '<div class="conductor">' + lineaPersonal(t) + '</div>' +
                    lineaRuta(t);

                // Distancia y tiempo real de la ruta, calculados al registrar el
                // traslado. Es lo primero que necesita ver el chofer: cuánto
                // tiene que manejar y cuánto tarda.
                html += fichaRecorrido(t);

                var transiciones = t.transiciones || [];
                if (t.puede_cambiar_estado && transiciones.length) {
                    var botones = '';
                    transiciones.forEach(function (est) {
                        var aColor = cfg.coloresAccion[est] || '#6c757d';
                        botones +=
                            '<button type="button" class="btn-accion" data-traslado="' + t.id + '" data-estado="' + est + '" ' +
                            'style="border-color:' + aColor + ';color:' + aColor + ';">' +
                            (cfg.etiquetasAccion[est] || est) + '</button>';
                    });
                    html += '<div class="acciones">' + botones + '</div>';
                }

                html += '</div>';
            });

            dom.listaTraslados.innerHTML = html;
            conectarTarjetas();
        }

        function conectarTarjetas() {
            var tarjetas = ctx.dom.listaTraslados.querySelectorAll('.tarjeta-traslado');

            tarjetas.forEach(function (card) {
                card.addEventListener('click', function () {
                    seleccionar(parseInt(this.getAttribute('data-traslado'), 10));
                });

                card.querySelectorAll('.btn-accion').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        cambiarEstado(
                            parseInt(this.getAttribute('data-traslado'), 10),
                            this.getAttribute('data-estado')
                        );
                    });
                });
            });
        }

        function cambiarEstado(trasladoId, estado) {
            if (!ctx.dom.estadoBar) return;

            var motivo = '';
            if (estado === 'cancelado') {
                motivo = (window.prompt('Motivo de la cancelación:') || '').trim();
                if (!motivo) {
                    ctx.dom.estadoBar.innerHTML = '<span>Para cancelar indicá el motivo.</span>';
                    return;
                }
            }

            var data = new FormData();
            data.append('traslado_id', trasladoId);
            data.append('estado', estado);
            if (motivo) data.append('motivo', motivo);

            fetch(mod.urls.estado, { method: 'POST', headers: window.Elyra.csrfHeaders(), body: data })
                .then(function (r) {
                    return r.json().catch(function () {
                        return { ok: false, error: 'Respuesta inválida del servidor.' };
                    });
                })
                .then(function (json) {
                    if (json && json.ok) {
                        ctx.dom.estadoBar.innerHTML = '<span>Estado actualizado. Refrescando...</span>';
                        api.datos.actualizar();
                    } else {
                        ctx.dom.estadoBar.innerHTML = '<span>' +
                            esc(json && json.error ? json.error : 'Error al actualizar el estado') + '</span>';
                    }
                })
                .catch(function () {
                    ctx.dom.estadoBar.innerHTML = '<span>Sin conexión</span>';
                });
        }

        /** Centra la cámara en un traslado y marca su tarjeta. */
        function seleccionar(trasladoId) {
            var encontrado = false;
            var marcadores = ctx.capas.traslados.getLayers();

            for (var i = 0; i < marcadores.length; i++) {
                if (marcadores[i]._trasladoId === trasladoId) {
                    ctx.mapa.setView(marcadores[i].getLatLng(), cfg.ZOOM_TARJETA, { animate: true });
                    marcadores[i].openPopup();
                    encontrado = true;
                    break;
                }
            }

            var tarjetas = ctx.dom.listaTraslados.querySelectorAll('.tarjeta-traslado');
            tarjetas.forEach(function (c) {
                c.classList.toggle('active', parseInt(c.getAttribute('data-traslado'), 10) === trasladoId);
            });

            // Los traslados ya terminados están en la lista pero no se dibujan en
            // el mapa, así que no hay marcador al que ir. Se avisa en vez de
            // dejar el click sin respuesta, que parecía una tarjeta muerta.
            if (!encontrado) {
                ctx.dom.estadoBar.innerHTML = '<span>Ese traslado ya terminó: no se muestra en el mapa</span>';
            }
        }

        return {
            actualizar: actualizar,
            cambiarEstado: cambiarEstado,
            seleccionar: seleccionar,
            fichaRecorrido: fichaRecorrido
        };
    };
})(window);