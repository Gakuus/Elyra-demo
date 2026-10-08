/**
 * mapa/util.js: cálculos puros del mapa (rumbo, agrupación de puntos, estado
 * de un traslado). No toca el DOM ni la red: son funciones que se pueden
 * leer y probar solas.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var fmt = root.ElyraFmt;

    /**
     * Rumbo, en grados, de un punto a otro. 0 es el norte y crece hacia el
     * este, que es como lo espera `rotate()` en CSS.
     *
     * Sirve para girar el ícono de la ambulancia y para mandarle el rumbo al
     * servidor en cada fix del GPS.
     */
    function rumboEntre(lat1, lng1, lat2, lng2) {
        if (typeof lat1 !== 'number' || typeof lng1 !== 'number' ||
            typeof lat2 !== 'number' || typeof lng2 !== 'number') {
            return 0;
        }

        var rad = function (g) { return g * Math.PI / 180; };

        var dLng = rad(lng2 - lng1);
        var y = Math.sin(dLng) * Math.cos(rad(lat2));
        var x = Math.cos(rad(lat1)) * Math.sin(rad(lat2)) -
            Math.sin(rad(lat1)) * Math.cos(rad(lat2)) * Math.cos(dLng);
        var grados = Math.atan2(y, x) * 180 / Math.PI;

        return (grados + 360) % 360;
    }

    /**
     * Dónde cae (lat, lng) sobre el segmento a→b, como fracción 0..1.
     *
     * Se trabaja en metros locales y no en grados: a la escala de un recorrido
     * urbano (kilómetros) la diferencia es despreciable, y hacerlo en grados
     * evita tener que traer el radio de la tierra a un archivo que solo quiere
     * ver qué tan cerca está el punto de la recta.
     */
    function fraccionSobreSegmento(lat, lng, a, b) {
        var latMedio = (a[0] + b[0]) / 2 * Math.PI / 180;
        var mPorGradoLat = 111320;
        var mPorGradoLng = 111320 * Math.cos(latMedio);

        var bx = (b[1] - a[1]) * mPorGradoLng;
        var by = (b[0] - a[0]) * mPorGradoLat;
        var px = (lng - a[1]) * mPorGradoLng;
        var py = (lat - a[0]) * mPorGradoLat;

        var largo2 = bx * bx + by * by;
        if (largo2 === 0) return 0;

        var f = (px * bx + py * by) / largo2;
        return f < 0 ? 0 : (f > 1 ? 1 : f);
    }

    /**
     * ¿El traslado sigue en curso? Los que ya terminaron o se cancelaron
     * quedan en la lista lateral como histórico, pero no se dibujan en el
     * mapa: sin rutas viejas ni sus pines, el mapa muestra dónde está la
     * ambulancia ahora y no dónde estuvo.
     */
    function esActivoAhora(t) {
        return t.estado !== 'completado' && t.estado !== 'cancelado';
    }

    var NOMBRES_ESTADO = {
        pendiente: 'Pendiente',
        en_curso: 'En curso',
        en_destino: 'En destino',
        en_retorno: 'En retorno',
        completado: 'Completado',
        cancelado: 'Cancelado'
    };

    function nombreEstado(estado) {
        return NOMBRES_ESTADO[estado] || estado;
    }

    /** "2026-10-06 14:35:00" -> "14:35" */
    function textoHora(datetime) {
        return datetime && datetime.length >= 16 ? datetime.substring(11, 16) : '';
    }

    /**
     * Agrupa ubicaciones que comparten coordenada.
     *
     * Los pabellones del hospital tienen la misma lat/lng, así que si se dibuja
     * un marcador por fila quedan tapados entre sí y el mapa muestra uno solo.
     * Sale un grupo por punto físico, con los pabellones listados en el popup.
     */
    function agruparPorCoordenada(ubicaciones) {
        var grupos = {};
        var orden = [];

        ubicaciones.forEach(function (u) {
            // 5 decimales ~ 1 m: dos lugares a menos de un metro son el mismo
            // punto a efectos de mapa.
            var clave = Number(u.latitud).toFixed(5) + ',' + Number(u.longitud).toFixed(5);
            if (!grupos[clave]) {
                grupos[clave] = {
                    latitud: Number(u.latitud),
                    longitud: Number(u.longitud),
                    nombres: []
                };
                orden.push(clave);
            }
            grupos[clave].nombres.push(u.nombre);
        });

        return orden.map(function (clave) { return grupos[clave]; });
    }

    /**
     * Título de un grupo de ubicaciones. Los pabellones se llaman
     * "Hospital de Clinicas - Cardiologia", así que comparten prefijo y el
     * nombre del hospital es lo que va arriba. Se elige el prefijo más
     * repetido y no el primero alfabéticamente: "Clinica Privada" ordena antes
     * que "Hospital de Clinicas" y daría un título mentiroso.
     */
    function tituloGrupo(nombres) {
        var conteo = {};
        nombres.forEach(function (n) {
            var base = n.split(' - ')[0];
            conteo[base] = (conteo[base] || 0) + 1;
        });

        var mejor = null;
        Object.keys(conteo).forEach(function (base) {
            if (!mejor || conteo[base] > conteo[mejor]) mejor = base;
        });
        return mejor;
    }

    mod.util = {
        rumboEntre: rumboEntre,
        fraccionSobreSegmento: fraccionSobreSegmento,
        esActivoAhora: esActivoAhora,
        nombreEstado: nombreEstado,
        textoHora: textoHora,
        agruparPorCoordenada: agruparPorCoordenada,
        tituloGrupo: tituloGrupo,
        escaparHtml: fmt.escaparHtml,
        formatearMinutos: fmt.formatearMinutos
    };
})(window);