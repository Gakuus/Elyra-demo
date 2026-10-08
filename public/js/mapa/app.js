/**
 * mapa/app.js: arranque y armado del mapa en vivo del dashboard.
 *
 * Este archivo es el que junta todo: lee el DOM, crea la instancia de Leaflet y
 * sus capas, construye los módulos (iconos, ruta, render, seguimiento,
 * simulación, panel, historial, datos) y conecta los botones. Toda la lógica
 * está en los demás archivos de public/js/mapa/.
 *
 * El módulo publica window.ElyraMapa, que es el puente con el modal de registro
 * de traslados (public/js/traslado/app.js).
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    function cargarEstilos(href) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    function cargarScript(ctx, src, alCargar) {
        var script = document.createElement('script');
        script.src = src;
        script.onload = alCargar;
        script.onerror = function () {
            if (ctx.dom.estadoBar) {
                ctx.dom.estadoBar.innerHTML = '<span>Error al cargar el mapa</span>';
            }
        };
        document.head.appendChild(script);
    }

    /** Lee del DOM todo lo que el mapa y el panel necesitan. */
    function leerDom(ctx) {
        var dom = ctx.dom;
        var divMapa = document.getElementById('map');

        dom.mapa = divMapa;
        dom.panel = document.getElementById('panel-mapa');
        dom.btnAlternar = document.getElementById('btnAlternarBarra');
        dom.listaTraslados = document.getElementById('listaTraslados');
        dom.contadorActivos = document.getElementById('contadorActivos');
        dom.estadoBar = document.getElementById('estadoMapa');

        dom.veloHistorial = document.getElementById('veloHistorial');
        dom.btnHistorial = document.getElementById('btnHistorial');
        dom.btnCerrarHistorial = document.getElementById('btnCerrarHistorial');
        dom.historialEnCurso = document.getElementById('historialEnCurso');
        dom.historialAnteriores = document.getElementById('historialAnteriores');
        dom.cuentaEnCurso = document.getElementById('cuentaEnCurso');
        dom.cuentaAnteriores = document.getElementById('cuentaAnteriores');

        dom.estadoSeguimiento = document.getElementById('estadoSeguimiento');
        dom.detalleSeguimiento = document.getElementById('detalleSeguimiento');
        dom.btnSeguir = document.getElementById('btnSeguir');
        dom.btnSimular = document.getElementById('btnSimular');

        // Rol e id del usuario que entró: los deja el #map como
        // data-attributes para que el JS no tenga que adivinarlo.
        ctx.rol = divMapa.getAttribute('data-rol') || '';
        ctx.usuarioId = parseInt(divMapa.getAttribute('data-usuario-id') || '0', 10) || 0;
        ctx.esConductor = ctx.rol === 'conductor';
        ctx.esChofer = ctx.rol === 'conductor' || ctx.rol === 'copiloto';

        // El paciente ve una versión recortada del mismo mapa: solo los traslados
        // en los que participa y la ambulancia que lo está llevando. El recorte
        // lo hace el servidor (obtenerDatosMapa()); acá solo cambia el texto del
        // panel para no llamarle "activos" a una lista que incluye el histórico.
        ctx.esPaciente = ctx.rol === 'paciente';
    }

    function crearCapas(ctx) {
        ctx.mapa = L.map('map', {
            center: cfg.MONTEVIDEO,
            zoom: cfg.ZOOM_INICIAL,
            zoomControl: true
        });

        L.tileLayer(cfg.TILE_URL, {
            attribution: cfg.TILE_ATRIBUCION,
            maxZoom: cfg.TILE_ZOOM_MAX
        }).addTo(ctx.mapa);

        ctx.capas.ubicaciones = L.layerGroup().addTo(ctx.mapa);
        ctx.capas.traslados = L.layerGroup().addTo(ctx.mapa);
        ctx.capas.conductores = L.layerGroup().addTo(ctx.mapa);
        ctx.capas.propia = L.layerGroup().addTo(ctx.mapa);
    }

    function conectarEventos(ctx, api) {
        var dom = ctx.dom;

        if (dom.panel && dom.btnAlternar) {
            dom.btnAlternar.addEventListener('click', function () {
                dom.panel.classList.toggle('collapsed');
            });
        }

        // Si el conductor mueve el mapa a mano, se deja de recentrar solo: la
        // cámara no puede pelearle al que está mirando.
        ctx.mapa.on('dragstart', function () {
            ctx.seguimiento.seguirCamara = false;
        });

        if (dom.btnSeguir) {
            dom.btnSeguir.addEventListener('click', function () {
                ctx.seguimiento.seguirCamara = true;

                if (ctx.seguimiento.marcadorPropio) {
                    ctx.mapa.setView(ctx.seguimiento.marcadorPropio.getLatLng(), cfg.ZOOM_SEGUIMIENTO, { animate: true });
                } else if (api.simulacion.puntoActual()) {
                    ctx.mapa.setView(api.simulacion.puntoActual(), cfg.ZOOM_SEGUIMIENTO, { animate: true });
                } else {
                    api.seguimiento.mostrar('esperando', 'Sin posición',
                        'Todavía no hay ninguna posición tuya para centrar.');
                }
            });
        }

        if (dom.btnSimular) {
            dom.btnSimular.addEventListener('click', api.simulacion.alternar);
        }

        if (dom.btnHistorial) {
            dom.btnHistorial.addEventListener('click', api.historial.abrir);
        }

        if (dom.btnCerrarHistorial) {
            dom.btnCerrarHistorial.addEventListener('click', api.historial.cerrar);
        }

        // Cierra con Escape, como el modal de registro.
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && api.historial.abierto()) {
                api.historial.cerrar();
            }
        });

        var pestanas = document.querySelectorAll('.pestanas-historial .pestana');
        for (var i = 0; i < pestanas.length; i++) {
            (function (pestana) {
                pestana.addEventListener('click', function () {
                    api.historial.cambiarPestana(pestana.getAttribute('data-pestana'));
                });
            })(pestanas[i]);
        }
    }

    /**
     * Puente con public/js/traslado/app.js: el modal de registro necesita la
     * instancia de Leaflet (para poner y mover sus pines) y tiene que poder
     * pausar el refresco automático mientras está abierto.
     */
    function publicarPuente(ctx, api) {
        root.ElyraMapa = {
            leaflet: ctx.mapa,
            urlRuta: mod.urls.ruta,
            pausarPolling: api.datos.pausar,
            esChofer: ctx.esChofer,

            /**
             * Se llama cuando el modal terminó de registrar un traslado.
             * Fuerza un refresco inmediato para que el traslado nuevo esté en la
             * lista (y se dispare el trazado de su ruta), lo marca como
             * seleccionado y arranca la simulación de la ambulancia. Si la ruta
             * de OSRM todavía no llegó, la simulación reintenta sola.
             */
            refrescarYSimular: function (idTraslado) {
                api.simulacion.preferir(idTraslado);

                return api.datos.actualizar().then(function () {
                    if (idTraslado !== undefined && idTraslado !== null) {
                        api.panel.seleccionar(idTraslado);
                    }
                    api.simulacion.iniciar(idTraslado);
                });
            }
        };
    }

    function iniciarMapa(ctx, api) {
        if (typeof window.L === 'undefined') return;

        crearCapas(ctx);
        conectarEventos(ctx, api);
        publicarPuente(ctx, api);

        api.datos.actualizar().then(function () {
            if (ctx.dom.estadoBar) {
                ctx.dom.estadoBar.innerHTML = '<div class="punto-pulso"></div><span>Actualizando cada 5s</span>';
            }
            api.datos.programar();
        });

        // El GPS solo arranca si el usuario es conductor. Para el resto del
        // personal, la página se comporta exactamente como antes.
        if (ctx.esConductor) {
            api.seguimiento.iniciar();
        }
    }

    mod.app = {
        iniciado: false,

        /** Solo actúa en la página del mapa, y una sola vez. */
        iniciar: function () {
            if (mod.app.iniciado) return;
            if (!document.getElementById('map')) return;
            mod.app.iniciado = true;

            var ctx = mod.crearContexto();
            leerDom(ctx);

            // En pantallas chicas el panel arranca cerrado: el mapa es lo que
            // importa primero y la lista se abre con la pestaña flotante.
            // La excepción es el conductor, que necesita ver de entrada el
            // estado del GPS y el botón de centrar.
            if (ctx.dom.panel && window.matchMedia('(max-width: 768px)').matches &&
                    !document.getElementById('bloqueSeguimiento')) {
                ctx.dom.panel.classList.add('collapsed');
            }

            // Todos los módulos comparten el mismo ctx y la misma tabla de APIs:
            // se construyen uno por uno y se van completando, así que el orden
            // en que se usan entre sí no importa.
            var api = {};
            api.iconos = mod.iconos(ctx);
            api.animacion = mod.animacion(ctx, api);
            api.ruta = mod.ruta(ctx);
            api.render = mod.render(ctx, api);
            api.seguimiento = mod.seguimiento(ctx, api);
            api.simulacion = mod.simulacion(ctx, api);
            api.panel = mod.panel(ctx, api);
            api.historial = mod.historial(ctx, api);
            api.datos = mod.datos(ctx, api);

            // Si la página ya tiene Leaflet (layout.html u otra vista) se
            // reusa; si no, se carga por CDN junto con sus estilos. Reusarlo
            // evita una segunda descarga y que el mapa espere a unpkg: si ese
            // CDN falla, el mapa quedaría en blanco aun teniendo la librería.
            if (typeof window.L !== 'undefined') {
                iniciarMapa(ctx, api);
            } else {
                cargarEstilos(cfg.LEAFLET_CSS);
                cargarScript(ctx, cfg.LEAFLET_JS, function () { iniciarMapa(ctx, api); });
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        mod.app.iniciar();
    });
})(window);