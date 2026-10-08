/**
 * mapa/animacion.js: desplazamiento suave de los conductores vistos desde otro
 * navegador.
 *
 * /api/mapa se consulta cada 5 s. Si el marcador se moviera directo a la
 * coordenada nueva, la ambulancia daría un salto cada 5 s y no se percibe que
 * está avanzando. Por eso entre una respuesta y la siguiente se interpola el
 * desplazamiento con un requestAnimationFrame.
 *
 * No cambia nada del lado del servidor: ni la frecuencia con la que se guarda
 * ni el contenido de lo guardado. Solo es cómo se dibuja.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};

    mod.animacion = function (ctx, api) {
        var DURACION = mod.ajustes.animacion;

        /** conductor_id -> {marcador, desde, hasta, actual, rumbo, t0} */
        var animaciones = {};
        var reloj = null;

        function interpolar(a, b, f) {
            return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];
        }

        function correrReloj(ahora) {
            var pendientes = {};

            Object.keys(animaciones).forEach(function (id) {
                var a = animaciones[id];
                if (!a.marcador) return;

                var f = DURACION > 0 ? (ahora - a.t0) / DURACION : 1;
                if (f > 1) f = 1;

                a.actual = interpolar(a.desde, a.hasta, f);
                a.marcador.setLatLng(a.actual);
                api.iconos.girar(a.marcador, a.rumbo);

                if (f < 1) {
                    pendientes[id] = a;
                } else {
                    delete animaciones[id];
                }
            });

            animaciones = pendientes;
            reloj = Object.keys(animaciones).length ? requestAnimationFrame(correrReloj) : null;
        }

        /**
         * Manda el marcador hacia una nueva posición, animado.
         *
         * Si el ícono ya está en vuelo se encadena desde donde se ve AHORA, no
         * desde el destino anterior: usar el destino previo haría retroceder la
         * ambulancia cada vez que dos respuestas del poll se pisan.
         */
        function mover(conductorId, marcador, hasta, rumbo, ahora) {
            var previa = animaciones[conductorId];
            var desde = previa && previa.actual ? previa.actual : marcador.getLatLng();

            animaciones[conductorId] = {
                marcador: marcador,
                desde: desde,
                hasta: hasta,
                actual: desde,
                rumbo: rumbo,
                t0: ahora
            };

            if (reloj === null) {
                reloj = requestAnimationFrame(correrReloj);
            }
        }

        /**
         * Corta la animación de un conductor: se usa cuando su fila desaparece
         * del mapa (terminó el traslado) para no seguir moviendo con el reloj
         * un marcador que ya no está en la capa.
         */
        function cortar(conductorId) {
            delete animaciones[conductorId];
        }

        return { mover: mover, cortar: cortar };
    };
})(window);