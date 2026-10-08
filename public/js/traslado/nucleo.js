/**
 * traslado/nucleo.js: estado compartido del modal de registro de traslados.
 *
 * Los archivos hermanos (ui, direcciones, pacientes, extremos, ruta, modal)
 * se registran en window.ElyraTrasladoMod y reciben este contexto: el mapa,
 * las capas de los pines, los extremos del recorrido (origen/destino) y los
 * nodos del formulario.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};

    mod.ajustes = {
        /**
         * Pausa al teclear antes de pedir sugerencias. 700ms y no 450 porque
         * Nominatim tolera ~1 consulta por segundo: con menos, escribir rápido
         * dispara consultas que el servidor termina tiene que poner en cola.
         */
        esperaBusqueda: 700,

        /** Pausa antes de pedir el nombre de un pin movido a mano. */
        esperaReversa: 900,

        /** Máximo de consultas guardadas en la caché de direcciones. */
        cacheBusquedasMax: 40,

        /** Por debajo de esto se considera que el usuario recién empezó a escribir. */
        minCaracteres: 3,
        minCaracteresPaciente: 2
    };

    mod.etiquetasTipo = {
        insumo: 'Insumo',
        equipamiento: 'Equipamiento',
        organo: 'Órgano'
    };

    mod.crearContexto = function () {
        return {
            /** Puente que publica mapa/app.js. */
            puente: null,

            /**
             * Ojo con la diferencia: ctx.mapa es la INSTANCIA del mapa (setView,
             * panBy, invalidateSize) y ctx.L es el namespace de Leaflet
             * (L.layerGroup, L.marker, L.polyline). Si L faltara, app.js
             * reventaba a mitad del montaje, los eventos nunca se conectaban y el
             * formulario se enviaba de forma nativa: la página se recargaba sin
             * registrar nada.
             */
            mapa: null,
            L: null,
            capaExtremos: null,
            capaRutaModal: null,

            /** Los dos extremos del recorrido. */
            origen: { lat: null, lng: null, label: '' },
            destino: { lat: null, lng: null, label: '' },
            extremoActivo: 'destino',
            marcadores: { origen: null, destino: null },

            /** El usuario está tipeando en el campo: su texto manda sobre el pin. */
            editando: { origen: false, destino: false },

            /** Últimos resultados mostrados, para elegir con el teclado. */
            ultimasDirecciones: { origen: [], destino: [] },
            ultimosPacientes: [],

            /** Búsqueda de direcciones: debounce, token y request en vuelo. */
            busqueda: {
                timer: null,
                token: 0,
                peticion: null,
                cache: {},
                ordenCache: []
            },

            /** Búsqueda de pacientes: estado propio, no comparte con direcciones. */
            pacientes: {
                timer: null,
                token: 0,
                peticion: null
            },

            /** Geocodificación inversa al soltar un pin. */
            inversa: { timer: null },

            /** Ruta del modal: evita pedir dos veces la misma y descarta viejas. */
            ruta: { ultimaClave: '', token: 0, minutos: null },

            /** Catálogo de ubicaciones, incrustado en la vista como JSON. */
            catalogo: [],

            /** Nodos del formulario. Los llena traslado/app.js. */
            dom: {}
        };
    };
})(window);