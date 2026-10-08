/**
 * traslado/modal.js: la vida del modal de registro: abrir, cerrar, validar,
 * mandar y limpiar. También el GPS de "Usar mi ubicación" y los selects que
 * completan el recorrido (catálogo y tipo de elemento).
 *
 * Al guardar manda las coordenadas, no los nombres, así que el traslado queda
 * con el punto exacto aunque después el catálogo cambie.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var fmt = root.ElyraFmt;

    mod.modal = function (ctx, api) {

        function abierto() {
            return !ctx.dom.velo.hidden;
        }

        function fijarExtremoActivo(cual) {
            ctx.extremoActivo = cual;

            var $ = api.ui.$;
            var bloque = $('bloque' + (cual === 'origen' ? 'Origen' : 'Destino'));
            var otro = $('bloque' + (cual === 'origen' ? 'Destino' : 'Origen'));

            if (bloque) bloque.classList.add('activo');
            if (otro) otro.classList.remove('activo');
        }

        /** Rellena fecha y hora de salida con un valor por defecto razonable. */
        function valoresPorDefecto() {
            var $ = api.ui.$;
            var hoy = new Date();

            var fecha = $('inputFecha');
            if (fecha && !fecha.value) {
                fecha.value = hoy.getFullYear() + '-' +
                    fmt.dosDigitos(hoy.getMonth() + 1) + '-' +
                    fmt.dosDigitos(hoy.getDate());
            }

            var hora = $('inputHora');
            if (hora && !hora.value) {
                // Se proyecta 15 minutos: registrar un traslado es siempre para
                // más adelante, nunca para el minuto siguiente.
                var salida = new Date(hoy.getTime() + 15 * 60000);
                salida.setSeconds(0, 0);
                salida.setMinutes(Math.ceil(salida.getMinutes() / 5) * 5);

                hora.value = fmt.dosDigitos(salida.getHours()) + ':' + fmt.dosDigitos(salida.getMinutes());
            }
        }

        function abrir() {
            if (!ctx.dom.velo) return;

            ctx.dom.velo.hidden = false;
            api.ui.limpiarAviso();

            if (ctx.puente && ctx.puente.pausarPolling) ctx.puente.pausarPolling(true);

            // El mapa encoge porque el modal entra en el flujo: sin
            // invalidateSize Leaflet sigue pensando que tiene el ancho viejo y
            // todo queda corrido.
            setTimeout(function () {
                ctx.mapa.invalidateSize();

                if (api.extremos.tieneCoordenadas(ctx.origen) && api.extremos.tieneCoordenadas(ctx.destino)) {
                    api.ruta.encuadrar(ctx.origen, ctx.destino);
                }
            }, 320);

            valoresPorDefecto();
            fijarExtremoActivo('destino');
        }

        function cerrar() {
            if (!ctx.dom.velo) return;

            ctx.dom.velo.hidden = true;
            api.direcciones.ocultar('origen');
            api.direcciones.ocultar('destino');

            if (ctx.puente && ctx.puente.pausarPolling) ctx.puente.pausarPolling(false);
        }

        function reiniciar() {
            var dom = ctx.dom;

            dom.form.reset();
            api.extremos.limpiar('origen');
            api.extremos.limpiar('destino');
            ctx.capaRutaModal.clearLayers();
            api.ruta.mostrarResumen(null, null);
            api.ruta.invalidar();

            if (dom.selRuta) dom.selRuta.value = '';
            if (dom.selCatalogoOrigen) dom.selCatalogoOrigen.value = '';
            if (dom.selCatalogoDestino) dom.selCatalogoDestino.value = '';
            if (dom.hiddenPacienteId) dom.hiddenPacienteId.value = '';
            if (dom.inPaciente) dom.inPaciente.value = '';

            api.pacientes.ocultar();
        }

        /** "Usar mi ubicación": GPS del navegador y geocodificación inversa. */
        function usarMiUbicacion() {
            if (!navigator.geolocation) {
                api.ui.avisar('Este navegador no tiene GPS. Marcá el origen en el mapa o elegilo del catálogo.', 'error');
                return;
            }

            api.ui.avisar('Buscando tu ubicación...', 'info');

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude;
                    var lng = pos.coords.longitude;

                    ctx.editando.origen = false;
                    api.extremos.fijar('origen', lat, lng, 'Mi ubicación');
                    api.ruta.fijarVista(lat, lng);
                    api.ui.avisar('Ubicación tomada. Ajustá el pin si hace falta.', 'info');
                },
                function (err) {
                    api.ui.avisar(err.code === 1
                        ? 'Denegaste el permiso de ubicación. Marcá el origen en el mapa o elegilo del catálogo.'
                        : 'No se pudo obtener el GPS. Marcá el origen en el mapa o elegilo del catálogo.', 'error');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        }

        /** Devuelve el primer problema del formulario, o null si está todo bien. */
        function validar() {
            var $ = api.ui.$;

            if (!api.extremos.tieneCoordenadas(ctx.origen)) {
                return 'Elegí el origen: usá tu ubicación, escribí una dirección o arrastrá el pin verde.';
            }
            if (!api.extremos.tieneCoordenadas(ctx.destino)) {
                return 'Elegí el destino: escribí una dirección o arrastrá el pin rojo.';
            }
            if (ctx.origen.label.trim() === '' || ctx.destino.label.trim() === '') {
                return 'Falta el nombre del origen o del destino.';
            }
            if (ctx.origen.label.trim() === ctx.destino.label.trim()) {
                return 'El origen y el destino son el mismo lugar.';
            }
            if (!$('selConductor').value) return 'Seleccioná un conductor.';
            if (!$('selVehiculo').value) return 'Seleccioná un vehículo.';

            var tipo = ctx.dom.selTipo.value;
            if (tipo === 'paciente') {
                if (!ctx.dom.hiddenPacienteId.value) {
                    return 'Elegí el paciente de la lista de sugerencias.';
                }
            } else if (!$('selElemento').value) {
                return 'Seleccioná el ' + (mod.etiquetasTipo[tipo] || 'elemento').toLowerCase() + ' a trasladar.';
            }

            if (!$('inputFecha').value || !$('inputHora').value) {
                return 'Completá la fecha y la hora de salida.';
            }

            return null;
        }

        function enviar(e) {
            if (e) e.preventDefault();

            var problema = validar();
            if (problema) {
                api.ui.avisar(problema, 'error');
                return;
            }

            var dom = ctx.dom;
            var data = new FormData(dom.form);

            // El nombre del extremo no es un input visible (viene del pin o de la
            // búsqueda), así que se agrega al FormData antes de mandar.
            data.set('origen', ctx.origen.label.trim());
            data.set('destino', ctx.destino.label.trim());

            dom.btnGuardar.disabled = true;
            var htmlOriginal = dom.btnGuardar.innerHTML;
            dom.btnGuardar.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando...';

            var restaurarBoton = function () {
                dom.btnGuardar.disabled = false;
                dom.btnGuardar.innerHTML = htmlOriginal;
            };

            fetch(dom.form.action, {
                method: 'POST',
                headers: window.Elyra.csrfHeaders(),
                body: data
            })
                .then(function (resp) {
                    return resp.json().catch(function () {
                        return { ok: false, error: 'Respuesta inválida del servidor.' };
                    }).then(function (json) {
                        if (!resp.ok) json.ok = false;
                        return json;
                    });
                })
                .then(function (json) {
                    restaurarBoton();

                    if (!json || !json.ok) {
                        api.ui.avisar(json && json.error ? json.error : 'No se pudo guardar el traslado.', 'error');
                        return;
                    }

                    reiniciar();
                    cerrar();
                    api.ui.avisarEnMapa('Traslado ' + (json.codigo || '') + ' registrado.', true);

                    // Conductor y copiloto registran su propio traslado: se
                    // refresca la lista y se arranca la simulación de la
                    // ambulancia sin que tenga que apretar "Simular". Para
                    // admin/superadmin no, porque no van manejando.
                    if (ctx.puente && ctx.puente.esChofer && ctx.puente.refrescarYSimular && json.traslado_id) {
                        ctx.puente.refrescarYSimular(json.traslado_id);
                    }
                })
                .catch(function () {
                    restaurarBoton();
                    api.ui.avisar('Error de conexión: no se pudo guardar el traslado.', 'error');
                });
        }

        /**
         * Busca un nombre en el catálogo y lo pone como extremo. Las rutas viejas
         * referencian lugares que podrían no estar en la tabla, así que si no se
         * encuentra se geocodifica el texto como último recurso.
         */
        function aplicarDesdeCatalogo(cual, nombre) {
            if (!nombre) {
                api.extremos.limpiar(cual);
                return;
            }

            var encontrado = null;
            for (var i = 0; i < ctx.catalogo.length; i++) {
                if (ctx.catalogo[i].nombre === nombre) {
                    encontrado = ctx.catalogo[i];
                    break;
                }
            }

            if (encontrado) {
                ctx.editando[cual] = false;
                api.extremos.fijar(cual,
                    parseFloat(encontrado.latitud),
                    parseFloat(encontrado.longitud),
                    encontrado.nombre);
                return;
            }

            api.ui.avisar('"' + nombre + '" no está en el catálogo; buscándolo por dirección...', 'info');
            ctx.editando[cual] = false;

            fetch(root.ElyraMapaMod.urls.geocodificar + '?q=' + encodeURIComponent(nombre))
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var res = json && json.resultados ? json.resultados[0] : null;

                    if (res) {
                        api.extremos.fijar(cual, res.lat, res.lng, res.label);
                    } else {
                        api.ui.avisar('No se encontró "' + nombre + '". Elegí el punto en el mapa.', 'error');
                    }
                })
                .catch(function () {
                    api.ui.avisar('No se pudo resolver "' + nombre + '".', 'error');
                });
        }

        /** Muestra el campo de paciente o el de catálogo, según el tipo elegido. */
        function aplicarTipo() {
            var $ = api.ui.$;
            var tipo = ctx.dom.selTipo.value;

            var campoPaciente = $('campoPaciente');
            var campoCatalogo = $('campoCatalogo');
            var selElemento = $('selElemento');

            if (tipo === 'paciente') {
                campoPaciente.hidden = false;
                campoCatalogo.hidden = true;

                // El id oculto se limpia al cambiar de tipo: si no, un paciente
                // elegido para un traslado anterior volvería viajar en el
                // siguiente envío.
                ctx.dom.hiddenPacienteId.value = '';
                ctx.dom.inPaciente.value = '';
                api.pacientes.ocultar();
                return;
            }

            campoPaciente.hidden = true;
            campoCatalogo.hidden = false;
            $('labelCatalogo').innerHTML = (mod.etiquetasTipo[tipo] || 'Elemento') + ' <em>*</em>';

            for (var i = 0; i < selElemento.options.length; i++) {
                var opt = selElemento.options[i];
                if (!opt.getAttribute('data-tipo')) continue;

                var muestra = opt.getAttribute('data-tipo') === tipo;
                opt.hidden = !muestra;
                opt.disabled = !muestra;
            }
            selElemento.value = '';
        }

        return {
            abierto: abierto,
            abrir: abrir,
            cerrar: cerrar,
            reiniciar: reiniciar,
            validar: validar,
            enviar: enviar,
            usarMiUbicacion: usarMiUbicacion,
            fijarExtremoActivo: fijarExtremoActivo,
            aplicarDesdeCatalogo: aplicarDesdeCatalogo,
            aplicarTipo: aplicarTipo
        };
    };
})(window);