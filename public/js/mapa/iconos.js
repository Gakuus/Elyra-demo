/**
 * mapa/iconos.js: los divIcon de Leaflet del mapa.
 *
 * Todos los iconos se arman con SVG de public/img/mapa/ y se cachean por
 * clave: antes cada marcador construía el suyo dentro del loop y se tiraba
 * entero en cada poll (cada 5 s), dejando nodos efímeros y rehaciendo el mismo
 * HTML para siempre.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    mod.iconos = function () {
        var cache = {};

        function cacheado(clave, armar) {
            if (!cache[clave]) {
                cache[clave] = armar();
            }
            return cache[clave];
        }

        /** Badge de estado alrededor de un vehículo. */
        function badgeVehiculo(estado, sinSenal, contenido) {
            var color = sinSenal ? cfg.COLOR_SIN_SENAL : (cfg.coloresEstado[estado] || cfg.COLOR_GRIS);
            var clases = 'mk-badge' + (sinSenal ? ' mk-badge--sin-senal' : '');
            return '<div class="' + clases + '" style="background:' + color + '">' + contenido + '</div>';
        }

        function hospital() {
            return cacheado('hospital', function () {
                return L.divIcon({
                    className: 'mk',
                    html: '<img src="' + cfg.IMG_MAPA + 'hospital.svg" alt="" class="mk-hosp">',
                    iconSize: [28, 30],
                    iconAnchor: [14, 30]
                });
            });
        }

        /**
         * sinSenal: el conductor dejó de reportar hace rato, así que se dibuja
         * desvanecido para no pasar por posición confiable.
         *
         * rumbo: hacia dónde avanza (grados desde el norte, en sentido horario).
         * Sin él la ambulancia queda siempre apuntando al este, y un ícono que
         * no gira no se lee como algo que se está moviendo. El SVG está
         * dibujado mirando a la derecha, que es 0°, así que el valor entra
         * directo.
         */
        function conductor(estado, sinSenal, rumbo) {
            var giro = '';
            if (typeof rumbo === 'number' && !isNaN(rumbo)) {
                giro = ' style="transform:rotate(' + Math.round(rumbo) + 'deg)"';
            }
            var contenido = '<img src="' + cfg.IMG_MAPA + 'ambulancia.svg" alt="" class="mk-ambulancia"' + giro + '>';

            return L.divIcon({
                className: 'mk',
                html: badgeVehiculo(estado, sinSenal, contenido),
                iconSize: [26, 26],
                iconAnchor: [13, 13]
            });
        }

        /** La misma ambulancia, pero del conductor que está mirando la página. */
        function propio(color, rumbo) {
            var contenido = '<img src="' + cfg.IMG_MAPA + 'ambulancia.svg" alt="" class="mk-ambulancia mk-propia"' +
                ' style="transform:rotate(' + Math.round(rumbo || 0) + 'deg)">';

            return L.divIcon({
                className: 'mk',
                html: '<div class="mk-badge" style="background:' + color + '">' + contenido + '</div>',
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            });
        }

        function origen() {
            return cacheado('origen', function () {
                return L.divIcon({
                    className: 'mk',
                    html: '<img src="' + cfg.IMG_MAPA + 'origen.svg" alt="" class="mk-pin">',
                    iconSize: [28, 38],
                    iconAnchor: [14, 36]
                });
            });
        }

        function destino() {
            return cacheado('destino', function () {
                return L.divIcon({
                    className: 'mk',
                    html: '<img src="' + cfg.IMG_MAPA + 'destino.svg" alt="" class="mk-pin">',
                    iconSize: [28, 38],
                    iconAnchor: [14, 36]
                });
            });
        }

        /**
         * Gira la ambulancia de un marcador rotando el nodo interno.
         *
         * Se rota el `<img>` de adentro y no se reconstruye el ícono con
         * setIcon(): rearmar el icono por cada fotograma de la animación (la
         * ambulancia se mueve muchas veces por segundo) devuelve el marcador al
         * fondo de la capa y se ve parpadeando, además de tirar la animación.
         *
         * Devuelve false cuando no encuentra el nodo, para que el llamador
         * pueda recrear el ícono como último recurso.
         */
        function girar(marcador, rumbo) {
            if (!marcador || typeof rumbo !== 'number' || isNaN(rumbo)) return false;

            var nodo = marcador._icon;
            if (!nodo || !nodo.querySelector) return false;

            var img = nodo.querySelector('.mk-ambulancia');
            if (!img) return false;

            img.style.transform = 'rotate(' + Math.round(rumbo) + 'deg)';
            return true;
        }

        return {
            hospital: hospital,
            conductor: conductor,
            propio: propio,
            origen: origen,
            destino: destino,
            girar: girar
        };
    };
})(window);