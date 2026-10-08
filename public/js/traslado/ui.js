/**
 * traslado/ui.js: avisos del modal y comportamiento de los desplegables de
 * sugerencias (resaltado, navegación con teclado, apertura hacia arriba).
 *
 * No sabe nada de direcciones ni de pacientes: solo cómo se pinta y cómo se
 * maneja lo que ya está pintado.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var esc = root.ElyraFmt.escaparHtml;

    mod.ui = function (ctx) {
        /** Atajo de getElementById: el modal trabaja por id casi siempre. */
        function $(id) {
            return document.getElementById(id);
        }

        /** Aviso dentro del modal. tipo: 'info' | 'error' */
        function avisar(mensaje, tipo) {
            var alerta = ctx.dom.alerta;
            if (!alerta) return;

            alerta.innerHTML = '<i class="bi bi-' +
                (tipo === 'error' ? 'exclamation-triangle' : 'info-circle') + '"></i> ' + esc(mensaje);
            alerta.className = 'alerta-traslado ' + (tipo === 'error' ? 'error' : 'info');
            alerta.hidden = false;
        }

        function limpiarAviso() {
            var alerta = ctx.dom.alerta;
            if (!alerta) return;

            alerta.hidden = true;
            alerta.innerHTML = '';
        }

        /**
         * Resalta dentro de un texto la parte que el usuario escribió, ignorando
         * acentos y mayúsculas. Sin eso "clinica" no resalta nada en "Clínica",
         * que es literalmente el caso más común acá: el hospital se llama
         * "Hospital de Clínicas" y todo el mundo lo busca sin tilde.
         */
        function resaltar(texto, consulta) {
            var base = esc(texto);
            var limpio = (consulta || '').trim();
            if (limpio.length < 2) return base;

            var quitarAcentos = function (s) {
                return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
            };

            // Se recorre carácter por carácter guardando, para cada posición del
            // texto normalizado, su índice real en el original. Sin ese mapa no
            // se puede volver del texto sin acentos al acentuado para recortar.
            var mapa = [];
            var normalizado = '';
            Array.from(texto).forEach(function (c, i) {
                var n = quitarAcentos(c);
                normalizado += n;
                for (var k = 0; k < n.length; k++) mapa.push(i);
            });

            var consultaNorm = quitarAcentos(limpio);
            if (consultaNorm === '') return base;

            var pos = normalizado.indexOf(consultaNorm);
            if (pos === -1) return base;

            var ini = mapa[pos];
            var fin = mapa[pos + consultaNorm.length - 1] + 1;

            return esc(texto.slice(0, ini)) +
                '<mark>' + esc(texto.slice(ini, fin)) + '</mark>' +
                esc(texto.slice(fin));
        }

        /**
         * Evita que el desplegable de sugerencias quede cortado.
         *
         * El cuerpo del modal (modal-traslado-cuerpo) tiene overflow-y: auto, así
         * que una lista posicionada hacia abajo se recorta cuando el input queda
         * cerca del final. Acá se mide el espacio libre y, si no alcanza, la lista
         * se abre hacia arriba.
         */
        function ubicar(caja) {
            if (!caja) return;

            var contenedor = caja.parentNode;
            if (!contenedor) return;

            contenedor.classList.remove('sugerencias-arriba');
            caja.hidden = false;

            // getBoundingClientRect da 0 sin layout; en ese caso se deja la
            // posición por defecto.
            if (!caja.getBoundingClientRect || !caja.getBoundingClientRect().height) return;

            var cajaRect = caja.getBoundingClientRect();
            var limite = window.innerHeight || 0;
            if (!limite) return;

            if (cajaRect.bottom > limite - 8) {
                contenedor.classList.add('sugerencias-arriba');
            }
        }

        /** Estado "buscando" dentro de la propia caja de sugerencias. */
        function mostrarBuscando(caja, texto) {
            if (!caja) return;

            caja.innerHTML = '<div class="sugerencia vacia"><span class="spinner"></span> Buscando "' +
                esc(texto) + '"…</div>';
            caja.hidden = false;
            ubicar(caja);
        }

        /** Mensaje de error dentro de la caja de sugerencias. */
        function mostrarMensaje(caja, mensaje) {
            if (!caja) return;

            // Los mensajes que arma esta función ya traen HTML a propósito (el
            // ícono de sesión expirada, por ejemplo). Lo que venga del servidor
            // sí se escapa, porque no sabemos qué es.
            var esHtml = mensaje.charAt(0) === '<';

            caja.innerHTML = '<div class="sugerencia vacia">' +
                (esHtml ? mensaje : esc(mensaje)) + '</div>';
            ubicar(caja);
        }

        /**
         * Conecta un input con su desplegable para que se pueda elegir con el
         * teclado: flechas para moverse, Enter para elegir la resaltada (o la
         * primera si no hay ninguna) y Escape para cerrar.
         *
         * Hace falta porque la selección va con mousedown (para que el input no
         * pierda el foco antes de tiempo), y con solo mousedown el teclado no
         * tenía forma de elegir nada.
         */
        function conectarTeclado(input, caja, obtenerResultados, elegir) {
            if (!input || !caja) return;

            var indiceActivo = -1;

            function items() {
                return caja.querySelectorAll('.sugerencia[data-indice]');
            }

            function mover(delta) {
                var nodos = items();
                if (!nodos.length) return;

                indiceActivo = (indiceActivo + delta + nodos.length) % nodos.length;
                nodos.forEach(function (n, i) { n.classList.toggle('sugerencia-activa', i === indiceActivo); });

                if (nodos[indiceActivo].scrollIntoView) {
                    nodos[indiceActivo].scrollIntoView({ block: 'nearest' });
                }
            }

            function confirmar() {
                var nodos = items();
                if (!nodos.length) return;

                var elegido = obtenerResultados()[indiceActivo >= 0 ? indiceActivo : 0];
                if (!elegido) return;

                indiceActivo = -1;
                nodos.forEach(function (n) { n.classList.remove('sugerencia-activa'); });
                elegir(elegido);
            }

            input.addEventListener('keydown', function (e) {
                if (caja.hidden) return;

                if (e.key === 'ArrowDown') { e.preventDefault(); mover(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
                else if (e.key === 'Enter') { e.preventDefault(); confirmar(); }
                else if (e.key === 'Escape') { indiceActivo = -1; caja.hidden = true; }
            });

            // Si el usuario vuelve a tipear, el resaltado anterior ya no corresponde.
            input.addEventListener('input', function () { indiceActivo = -1; });
        }

        /**
         * Un mensaje en la barra del mapa, que se ve aunque el modal no llegue a
         * abrirse. Vuelve solo al estado normal a los 4 s.
         */
        function avisarEnMapa(texto, ok) {
            var barra = document.getElementById('estadoMapa');
            if (!barra) return;

            barra.innerHTML = '<span>' + esc(texto) + '</span>';
            barra.classList.add(ok ? 'aviso-ok' : 'aviso-error');

            setTimeout(function () {
                barra.classList.remove('aviso-ok', 'aviso-error');
                barra.innerHTML = '<div class="punto-pulso"></div><span>Actualizando cada 5s</span>';
            }, 4000);
        }

        return {
            $: $,
            avisar: avisar,
            limpiarAviso: limpiarAviso,
            avisarEnMapa: avisarEnMapa,
            resaltar: resaltar,
            ubicar: ubicar,
            mostrarBuscando: mostrarBuscando,
            mostrarMensaje: mostrarMensaje,
            conectarTeclado: conectarTeclado
        };
    };
})(window);