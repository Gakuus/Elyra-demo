/**
 * traslado/app.js: montaje del modal de registro de traslados.
 *
 * El modal se apoya contra el borde derecho sin tapar el mapa, para poder ver
 * los pines mientras se acomodan. Acá se leen los nodos del formulario, se
 * espera a que el mapa esté listo y se conectan los eventos; la lógica de cada
 * cosa está en los módulos hermanos (ui, direcciones, pacientes, extremos,
 * ruta, modal).
 *
 * Depende de window.ElyraMapa, que publica public/js/mapa/app.js.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};

    /** Espera a que este elemento exista. Los scripts se cargan con defer. */
    function alCargar(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    /**
     * El mapa se arma asincrónicamente (Leaflet llega por CDN y mapa/app.js lo
     * inicializa después), así que casi nunca está listo en el primer intento.
     * Por eso recibe un callback en vez de devolver el puente: si solo devolviera
     * el valor, los reintentos se harían pero su resultado se descartaría y el
     * modal nunca se montaría.
     */
    function esperarMapa(callback, intentos) {
        if (root.ElyraMapa && root.ElyraMapa.leaflet) {
            callback(root.ElyraMapa);
            return;
        }

        if ((intentos || 0) > 100) {
            // Antes esto solo escribía en la consola y el modal quedaba muerto
            // sin que nadie lo dijera: el síntoma era "el buscador no funciona".
            // Ahora se avisa en la barra del mapa, que se ve aunque el modal no
            // llegue a abrirse.
            console.error('El mapa no se terminó de cargar; el modal queda deshabilitado.');

            var barra = document.getElementById('estadoMapa');
            if (barra) {
                barra.innerHTML = '<span>No se pudo cargar el mapa. Recargá la página: ' +
                    'sin mapa no se pueden elegir direcciones.</span>';
                barra.classList.add('aviso-error');
            }
            return;
        }

        setTimeout(function () { esperarMapa(callback, (intentos || 0) + 1); }, 100);
    }

    function leerDom(ctx) {
        var dom = ctx.dom;

        dom.velo = document.getElementById('veloTraslado');
        dom.modal = dom.velo ? dom.velo.querySelector('.modal-traslado') : null;
        dom.form = document.getElementById('formTraslado');
        dom.alerta = document.getElementById('alertaTraslado');
        dom.btnGuardar = document.getElementById('btnGuardarTraslado');
        dom.btnNuevo = document.getElementById('btnNuevoTraslado');
        dom.resumenRuta = document.getElementById('resumenRuta');
        dom.resumenKm = document.getElementById('resumenKm');
        dom.resumenMin = document.getElementById('resumenMin');
        dom.resumenLlegada = document.getElementById('resumenLlegada');

        dom.inOrigen = document.getElementById('inputOrigen');
        dom.inDestino = document.getElementById('inputDestino');
        dom.sugOrigen = document.getElementById('sugerenciasOrigen');
        dom.sugDestino = document.getElementById('sugerenciasDestino');
        dom.coordOrigen = document.getElementById('coordOrigen');
        dom.coordDestino = document.getElementById('coordDestino');

        dom.hiddenOrigenLat = document.getElementById('origenLat');
        dom.hiddenOrigenLng = document.getElementById('origenLng');
        dom.hiddenDestinoLat = document.getElementById('destinoLat');
        dom.hiddenDestinoLng = document.getElementById('destinoLng');

        dom.selRuta = document.getElementById('selRuta');
        dom.selTipo = document.getElementById('selTipo');
        dom.selCatalogoOrigen = document.getElementById('selCatalogoOrigen');
        dom.selCatalogoDestino = document.getElementById('selCatalogoDestino');

        dom.inPaciente = document.getElementById('inputPaciente');
        dom.sugPaciente = document.getElementById('sugerenciasPaciente');
        dom.hiddenPacienteId = document.getElementById('inputPacienteId');
    }

    function montarCapas(ctx) {
        // Ojo con la diferencia: ctx.mapa es la INSTANCIA del mapa (sirve para
        // setView, panBy, invalidateSize), mientras que ctx.L es el namespace de
        // Leaflet (sirve para L.layerGroup, L.marker, L.polyline).
        ctx.mapa = ctx.puente.leaflet;
        ctx.L = root.L;

        if (!ctx.L || typeof ctx.L.layerGroup !== 'function') {
            console.error('Leaflet no está disponible; el modal queda deshabilitado.');
            return false;
        }

        ctx.capaExtremos = ctx.L.layerGroup().addTo(ctx.mapa);
        ctx.capaRutaModal = ctx.L.layerGroup().addTo(ctx.mapa);
        return true;
    }

    function conectarAbrirYCerrar(ctx, api) {
        var dom = ctx.dom;

        // El botón "Registrar traslado" del panel abre el mismo modal. Y
        // /mapa?nuevo=1 lo abre directo: es el atajo del menú.
        if (dom.btnNuevo) {
            dom.btnNuevo.addEventListener('click', function () { api.modal.abrir(); });
        }

        api.ui.$('btnCerrarTraslado').addEventListener('click', api.modal.cerrar);
        api.ui.$('btnCancelarTraslado').addEventListener('click', api.modal.cerrar);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && api.modal.abierto()) api.modal.cerrar();
        });

        if (dom.btnNuevo && /[?&]nuevo=1(&|$)/.test(window.location.search)) {
            api.modal.abrir();
        }
    }

    /** Click en el mapa: mueve el extremo que esté activo. */
    function conectarClicEnMapa(ctx, api) {
        ctx.mapa.on('click', function (e) {
            if (!api.modal.abierto()) return;

            ctx.editando[ctx.extremoActivo] = false;
            api.extremos.fijar(ctx.extremoActivo, e.latlng.lat, e.latlng.lng, null);
            api.extremos.pedirNombre(ctx.extremoActivo);
        });

        api.ui.$('bloqueOrigen').addEventListener('click', function (e) {
            if (esControl(e.target)) return;
            api.modal.fijarExtremoActivo('origen');
        });

        api.ui.$('bloqueDestino').addEventListener('click', function (e) {
            if (esControl(e.target)) return;
            api.modal.fijarExtremoActivo('destino');
        });
    }

    /** El click en un input/select/botón no cambia el extremo activo. */
    function esControl(nodo) {
        return nodo && (nodo.tagName === 'INPUT' || nodo.tagName === 'SELECT' || nodo.tagName === 'BUTTON');
    }

    function conectarBuscadores(ctx, api) {
        var dom = ctx.dom;

        dom.inOrigen.addEventListener('input', function () {
            ctx.editando.origen = true;
            api.direcciones.buscar(this.value, 'origen');
        });

        dom.inDestino.addEventListener('input', function () {
            ctx.editando.destino = true;
            api.direcciones.buscar(this.value, 'destino');
        });

        // El paciente se busca por texto: se vacía el id oculto en cada
        // keystroke para que no se pueda mandar el paciente de una búsqueda
        // anterior con un nombre tipeado a medias.
        dom.inPaciente.addEventListener('input', function () {
            dom.hiddenPacienteId.value = '';
            api.pacientes.buscar(this.value);
        });

        dom.inPaciente.addEventListener('blur', function () {
            setTimeout(function () { api.pacientes.ocultar(); }, 150);
        });

        dom.inOrigen.addEventListener('blur', function () {
            setTimeout(function () { api.direcciones.ocultar('origen'); }, 150);
        });

        dom.inDestino.addEventListener('blur', function () {
            setTimeout(function () { api.direcciones.ocultar('destino'); }, 150);
        });

        // El foco en el campo marca cuál es el extremo activo.
        dom.inOrigen.addEventListener('focus', function () { api.modal.fijarExtremoActivo('origen'); });
        dom.inDestino.addEventListener('focus', function () { api.modal.fijarExtremoActivo('destino'); });

        // Elección con teclado para los tres buscadores.
        api.ui.conectarTeclado(dom.inPaciente, dom.sugPaciente,
            api.pacientes.ultimos,
            function (r) {
                dom.hiddenPacienteId.value = String(r.id);
                dom.inPaciente.value = r.label;
                api.pacientes.ocultar();
            });

        ['origen', 'destino'].forEach(function (cual) {
            api.ui.conectarTeclado(
                cual === 'origen' ? dom.inOrigen : dom.inDestino,
                cual === 'origen' ? dom.sugOrigen : dom.sugDestino,
                function () { return api.direcciones.ultimas(cual); },
                function (r) {
                    ctx.editando[cual] = false;
                    api.extremos.fijar(cual, r.lat, r.lng, r.label);
                    api.direcciones.ocultar(cual);
                }
            );
        });
    }

    function conectarControles(ctx, api) {
        var dom = ctx.dom;

        api.ui.$('btnMiUbicacion').addEventListener('click', api.modal.usarMiUbicacion);

        api.ui.$('btnInvertirExtremos').addEventListener('click', function () {
            var o = ctx.origen;
            var d = ctx.destino;
            var oLat = o.lat, oLng = o.lng, oLabel = o.label;
            var dLat = d.lat, dLng = d.lng, dLabel = d.label;

            if (api.extremos.tieneCoordenadas(o)) api.extremos.fijar('destino', oLat, oLng, oLabel);
            else api.extremos.limpiar('destino');

            if (api.extremos.tieneCoordenadas(d)) api.extremos.fijar('origen', dLat, dLng, dLabel);
            else api.extremos.limpiar('origen');

            if (oLabel) ctx.editando.origen = false;
            if (dLabel) ctx.editando.destino = false;

            api.ruta.invalidar();
            api.ruta.recalcular();
        });

        // Catálogo de ubicaciones: elige el extremo por nombre.
        dom.selCatalogoOrigen.addEventListener('change', function () {
            aplicarDesdeSelect(this, 'origen');
        });

        dom.selCatalogoDestino.addEventListener('change', function () {
            aplicarDesdeSelect(this, 'destino');
        });

        function aplicarDesdeSelect(select, cual) {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) return;

            ctx.editando[cual] = false;
            api.extremos.fijar(cual,
                parseFloat(opt.getAttribute('data-lat')),
                parseFloat(opt.getAttribute('data-lng')),
                opt.value);
        }

        // Ruta predefinida: completa origen y destino de una.
        dom.selRuta.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];

            if (!opt || !opt.value) {
                api.extremos.limpiar('origen');
                api.extremos.limpiar('destino');
                return;
            }

            // Las rutas guardan los lugares como texto; se buscan en el
            // catálogo para quedarse con las coordenadas.
            api.modal.aplicarDesdeCatalogo('origen', opt.getAttribute('data-origen'));
            api.modal.aplicarDesdeCatalogo('destino', opt.getAttribute('data-destino'));
        });

        // Tipo de elemento: paciente o catálogo.
        dom.selTipo.addEventListener('change', api.modal.aplicarTipo);
        api.modal.aplicarTipo();

        // La hora de llegada se recalcula cuando cambia la de salida.
        api.ui.$('inputHora').addEventListener('change', function () {
            if (!api.ruta.resumenVisible() || api.ruta.minutos() === null) return;
            dom.resumenLlegada.textContent = api.ruta.calcularLlegada(api.ruta.minutos());
        });

        dom.form.addEventListener('submit', api.modal.enviar);
    }

    var iniciado = false;

    function iniciar(puente) {
        if (iniciado) return;
        iniciado = true;

        var ctx = mod.crearContexto();
        ctx.puente = puente;
        leerDom(ctx);

        if (!ctx.dom.velo) return;

        ctx.dom.form.action = root.ElyraMapaMod.urls.nuevoTraslado;

        // El catálogo viene incrustado en la vista como JSON.
        var nodoCatalogo = document.getElementById('datosCatalogo');
        if (nodoCatalogo) {
            try {
                ctx.catalogo = JSON.parse(nodoCatalogo.textContent || '[]');
            } catch (e) {
                ctx.catalogo = [];
            }
        }

        if (!montarCapas(ctx)) return;

        // Los módulos comparten el mismo ctx y la misma tabla de APIs: se
        // construyen uno por uno y se van completando, así que el orden en que
        // se usan entre sí no importa.
        var api = {};
        api.ui = mod.ui(ctx);
        api.extremos = mod.extremos(ctx, api);
        api.direcciones = mod.direcciones(ctx, api);
        api.pacientes = mod.pacientes(ctx, api);
        api.ruta = mod.ruta(ctx, api);
        api.modal = mod.modal(ctx, api);

        conectarAbrirYCerrar(ctx, api);
        conectarClicEnMapa(ctx, api);
        conectarBuscadores(ctx, api);
        conectarControles(ctx, api);
    }

    alCargar(function () {
        esperarMapa(function (m) {
            try {
                iniciar(m);
            } catch (e) {
                console.error('No se pudo montar el modal de traslado:', e);
            }
        }, 0);
    });

    mod.app = { iniciar: iniciar };
})(window);