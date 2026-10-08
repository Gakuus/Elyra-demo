/**
 * mapa/nucleo.js: registro de módulos del mapa, configuración y estado
 * compartido.
 *
 * El mapa en vivo quedó repartido en varios archivos (iconos, ruta, render,
 * seguimiento, simulación, panel, historial, datos). Cada módulo se registra
 * acá y recibe el mismo objeto de contexto, así ninguno necesita variables
 * globales sueltas y se puede ver de un vistazo qué estado se comparte.
 *
 * El contexto tiene tres bloques:
 *   - mapa / capas / dom: lo que existe en la página.
 *   - rol: quién entró, leído de los data-attributes del #map.
 *   - seguimiento: la posición del propio conductor. GPS real y simulación
 *     escriben el mismo marcador, por eso la comparten en vez de duplicarla.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};

    var base = root.BASE_PATH || '';

    /**
     * Endpoints. Se arman con BASE_PATH para que la app funcione igual en la
     * raíz del dominio o bajo una subcarpeta (/proyectos/elyra).
     */
    mod.urls = {
        mapa: base + '/api/mapa',
        ruta: base + '/api/ruta/real',
        estado: base + '/traslados/estado',
        ubicacion: base + '/api/ubicacion',
        geocodificar: base + '/api/geocodificar',
        pacientes: base + '/api/pacientes/buscar',
        nuevoTraslado: base + '/traslados/nuevo'
    };

    /**
     * Constantes de dibujo. El color del estado ya no viaja en el icono sino
     * en el borde del badge, para que la ambulancia pueda girar con el rumbo
     * sin deformarse.
     */
    mod.config = {
        MONTEVIDEO: [-34.9011, -56.1645],
        ZOOM_INICIAL: 13,
        ZOOM_TARJETA: 15,
        ZOOM_SEGUIMIENTO: 16,

        TILE_URL: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        TILE_ATRIBUCION: '&copy; OpenStreetMap contributors',
        TILE_ZOOM_MAX: 19,

        LEAFLET_CSS: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        LEAFLET_JS: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',

        // Los iconos son SVG propios (public/img/mapa/) en vez de glifos de
        // Bootstrap Icons: a 28px un glifo de fuente se ve borroso, y el SVG se
        // mantiene nítido en cualquier zoom.
        IMG_MAPA: 'img/mapa/',

        COLOR_PROPIO: '#2196F3',
        COLOR_SIN_SENAL: '#90A4AE',
        COLOR_GRIS: '#9E9E9E',
        COLOR_RUTA_MODAL: '#3B5998',

        coloresEstado: {
            pendiente: '#9E9E9E',
            en_curso: '#4CAF50',
            en_destino: '#FF9800',
            en_retorno: '#2196F3',
            completado: '#4CAF50',
            cancelado: '#f44336'
        },

        coloresAccion: {
            en_curso: '#4CAF50',
            en_destino: '#FF9800',
            en_retorno: '#2196F3',
            completado: '#4CAF50',
            cancelado: '#f44336'
        },

        etiquetasAccion: {
            en_curso: 'Iniciar',
            en_destino: 'En destino',
            en_retorno: 'En retorno',
            completado: 'Completar',
            cancelado: 'Cancelar'
        },

        /** Sin señal: el conductor dejó de reportar hace rato. */
        ESTADO_SIN_SENAL: 'sin_senal'
    };

    /**
     * Ajustes de tiempo. Son números de una línea porque cambiarlos seguido
     * (más polling, más postcards de posición) tiene que ser fácil.
     */
    mod.ajustes = {
        /** Cada cuánto se consulta /api/mapa. */
        polling: 5000,

        /**
         * Duração del desplazamiento del ícono de un conductor entre dos
         * respuestas del poll. Casi todo el intervalo de refresco (5 s): con
         * 1400 ms la ambulancia pasaba 3.6 s quieta entre posicionamientos y
         * parecía congelada, que era el bug reportado.
         */
        animacion: 4500,

        /** Cada cuánto manda la posición al server. */
        envioCada: 5000,

        /** Descarta fixes con menos precisión que esto. */
        precisionMaxima: 50,

        /** Fix más viejo que esto se considera rancio. */
        fixViejo: 20000,

        simTick: 1000,          // Cada cuánto avanza la ambulancia falsa.
        simVelocidad: 50,       // Velocidad "real" que se simula (km/h).
        simAceleracion: 4       // Multiplicador para que se vea rápido en la demo.
    };

    /**
     * Estado compartido. Se crea una sola vez, en app.js, antes de construir
     * los módulos: es el único lugar donde se toca document o Leaflet.
     */
    mod.crearContexto = function () {
        return {
            /** Instancia de Leaflet y sus capas, creadas en app.js. */
            mapa: null,
            capas: {
                ubicaciones: null,
                traslados: null,
                conductores: null,
                propia: null
            },

            /** Nodos del panel, del historial y del bloque de seguimiento. */
            dom: {
                mapa: null,
                panel: null,
                btnAlternar: null,
                listaTraslados: null,
                contadorActivos: null,
                estadoBar: null,

                veloHistorial: null,
                btnHistorial: null,
                btnCerrarHistorial: null,
                historialEnCurso: null,
                historialAnteriores: null,
                cuentaEnCurso: null,
                cuentaAnteriores: null,

                estadoSeguimiento: null,
                detalleSeguimiento: null,
                btnSeguir: null,
                btnSimular: null
            },

            /** Lo dejan los data-attributes del #map. */
            rol: '',
            usuarioId: 0,
            esConductor: false,
            esChofer: false,
            esPaciente: false,

            /** Últimos datos que devolvió /api/mapa (histórico incluido). */
            traslados: [],

            /** conductor_id -> marcador, para no recrearlos en cada poll. */
            marcadoresConductores: {},

            /**
             * Posición del propio conductor. GPS real y simulación escriben acá
             * los dos, y el render la lee para no dibujar un segundo marcador
             * de la ambulancia propia.
             */
            seguimiento: {
                idWatch: null,
                marcadorPropio: null,
                circuloPrecision: null,
                activo: false,
                ultimoEnvioMs: 0,
                ultimoEnvioOkMs: 0,
                ultimoError: '',
                seguirCamara: true,
                enviando: false,
                rumbo: 0,
                ultimaPosicion: null,
                color: mod.config.COLOR_PROPIO,
                colorIcono: mod.config.COLOR_PROPIO
            },

            /** Estado de la simulación del recorrido. */
            simulacion: {
                timer: null,
                estado: null,
                /** Traslado pedido explícitamente (recién registrado). */
                idPreferido: null
            }
        };
    };
})(window);