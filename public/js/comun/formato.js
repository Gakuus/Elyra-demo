/**
 * formato.js: formateo de textos compartido por el mapa y el modal de registro
 * de traslados. Se publica como window.ElyraFmt.
 *
 * Vive aparte de los dos porque ambos archivos necesitan exactamente lo mismo
 * (escapar HTML, "1 h 35 min", "350 m") y la copia duplicada era el comienzo
 * de la divergencia: el mismo cálculo de minutos en dos lugares que un día
 * dejaron de dar lo mismo.
 */
(function (root) {
    'use strict';

    /**
     * Escapa texto para meterlo dentro de innerHTML.
     *
     * No se reemplaza por un replace de caracteres: se usa el propio motor del
     * navegador con un nodo de texto, que escapa exactamente lo que hay que
     * escapar y no deja pasar ni atributos ni etiquetas.
     */
    function escaparHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function dosDigitos(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    /** 95 -> "1 h 35 min"; 120 -> "2 h"; 45 -> "45 min". */
    function formatearMinutos(min) {
        var n = Math.round(min);
        if (n < 60) return n + ' min';
        var h = Math.floor(n / 60);
        var m = n % 60;
        return m === 0 ? h + ' h' : h + ' h ' + m + ' min';
    }

    /** "-34.90114, -56.16452" */
    function formatearCoordenada(lat, lng) {
        return lat.toFixed(5) + ', ' + lng.toFixed(5);
    }

    /**
     * Distancia para el desplegable de sugerencias: 350 -> "350 m",
     * 1500 -> "1,5 km". Es lo que permite elegir entre dos direcciones con el
     * mismo nombre.
     */
    function formatearMetros(metros) {
        if (typeof metros !== 'number' || isNaN(metros) || metros < 0) return '';
        if (metros < 1000) {
            var redondeado = Math.round(metros / 10) * 10;
            // 999 m redondea a 1000: mejor "1 km" que "1000 m".
            return redondeado >= 1000 ? '1 km' : redondeado + ' m';
        }
        return (metros / 1000).toFixed(metros < 10000 ? 1 : 0).replace('.', ',') + ' km';
    }

    root.ElyraFmt = {
        escaparHtml: escaparHtml,
        dosDigitos: dosDigitos,
        formatearMinutos: formatearMinutos,
        formatearCoordenada: formatearCoordenada,
        formatearMetros: formatearMetros
    };
})(window);