/**
 * mapa/historial.js: el panel con pestañas "En curso" / "Anteriores".
 *
 * El historial vive en un panel aparte y no en la lista del mapa: los traslados
 * terminados ya no se dibujan (dejaban un marcador en el origen, otro en el
 * destino y la línea por donde se pasó, y con el tiempo el mapa quedaba tapado
 * de lugares viejos), pero sí se pueden consultar acá.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraMapaMod = root.ElyraMapaMod || {};
    var cfg = mod.config;

    mod.historial = function (ctx, api) {
        var util = mod.util;
        var esc = util.escaparHtml;

        function vacio(icono, texto) {
            return '<div class="mapa-vacio"><i class="bi ' + icono + '"></i>' + texto + '</div>';
        }

        /** Fecha del traslado: la estimada si no se llegó a registrar la real. */
        function fecha(t) {
            var valor = t.hora_llegada_destino || t.hora_salida_efectiva || t.hora_salida_estimada;
            if (!valor) return '';

            var d = new Date(String(valor).replace(' ', 'T'));
            if (isNaN(d.getTime())) return '';

            return '<div class="fecha-historial"><i class="bi bi-calendar3"></i> ' +
                d.toLocaleDateString('es-UY', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
                ' · ' + d.toLocaleTimeString('es-UY', { hour: '2-digit', minute: '2-digit' }) +
                '</div>';
        }

        /**
         * Tarjeta del historial. Reutiliza la ficha de recorrido del panel para
         * que el km y los minutos se vean igual que en la lista del mapa, pero
         * sin los botones de estado: nada de lo que aparece acá se puede
         * cambiar de estado.
         */
        function tarjeta(t) {
            var color = cfg.coloresEstado[t.estado] || cfg.COLOR_GRIS;

            return '<div class="tarjeta-traslado' + (t.estado === 'cancelado' ? ' cancelado' : '') + '">' +
                '<div class="codigo">' + esc(t.codigo) +
                ' <span class="badge" style="font-size:.72rem;color:#fff;background:' + color + ';">' +
                esc(util.nombreEstado(t.estado)) + '</span></div>' +
                '<div class="conductor">' + esc(t.conductor_nombre) +
                (t.copiloto_nombre ? ' / ' + esc(t.copiloto_nombre) : '') +
                (t.vehiculo_patente ? ' · ' + esc(t.vehiculo_patente) : '') + '</div>' +
                '<div class="route">' +
                '<i class="bi bi-circle-fill" style="font-size:5px;color:#4CAF50;"></i> ' + esc(t.origen) +
                ' <i class="bi bi-arrow-right" style="font-size:9px;"></i> ' +
                '<i class="bi bi-circle-fill" style="font-size:5px;color:#f44336;"></i> ' + esc(t.destino) +
                '</div>' +
                api.panel.fichaRecorrido(t) +
                fecha(t) +
                '</div>';
        }

        function pintar(traslados) {
            var dom = ctx.dom;
            if (!dom.historialEnCurso || !dom.historialAnteriores) return;

            var enCurso = traslados.filter(util.esActivoAhora);
            var anteriores = traslados.filter(function (t) { return !util.esActivoAhora(t); });

            dom.historialEnCurso.innerHTML = enCurso.length
                ? enCurso.map(tarjeta).join('')
                : vacio('bi-truck', 'No tenés traslados en curso ahora');

            dom.historialAnteriores.innerHTML = anteriores.length
                ? anteriores.map(tarjeta).join('')
                : vacio('bi-clock-history', 'Todavía no tenés traslados anteriores');

            dom.cuentaEnCurso.textContent = enCurso.length;
            dom.cuentaAnteriores.textContent = anteriores.length;
        }

        function abierto() {
            return !!(ctx.dom.veloHistorial && !ctx.dom.veloHistorial.hidden);
        }

        function abrir() {
            var velo = ctx.dom.veloHistorial;
            if (!velo) return;

            pintar(ctx.traslados);
            velo.hidden = false;

            // El panel entra en el flujo flex y empuja al mapa, igual que el modal
            // de registro: sin esto Leaflet sigue creyendo que tiene el ancho
            // viejo y las líneas quedan corridas.
            if (ctx.mapa) ctx.mapa.invalidateSize();
            if (ctx.dom.btnCerrarHistorial) ctx.dom.btnCerrarHistorial.focus();
        }

        function cerrar() {
            var velo = ctx.dom.veloHistorial;
            if (!velo || velo.hidden) return;

            velo.hidden = true;
            if (ctx.mapa) ctx.mapa.invalidateSize();
            if (ctx.dom.btnHistorial) ctx.dom.btnHistorial.focus();
        }

        function cambiarPestana(nombre) {
            var dom = ctx.dom;
            var pestanas = document.querySelectorAll('.pestanas-historial .pestana');

            for (var i = 0; i < pestanas.length; i++) {
                var esEsta = pestanas[i].getAttribute('data-pestana') === nombre;
                pestanas[i].classList.toggle('activa', esEsta);
                pestanas[i].setAttribute('aria-selected', esEsta ? 'true' : 'false');
            }

            if (dom.historialEnCurso) dom.historialEnCurso.hidden = nombre !== 'en-curso';
            if (dom.historialAnteriores) dom.historialAnteriores.hidden = nombre !== 'anteriores';
        }

        return {
            pintar: pintar,
            abrir: abrir,
            cerrar: cerrar,
            abierto: abierto,
            cambiarPestana: cambiarPestana
        };
    };
})(window);