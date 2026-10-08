/**
 * traslado/pacientes.js: el buscador de pacientes del modal.
 *
 * No es un desplegable con la lista completa: eso le mandaría al conductor la
 * lista de pacientes del hospital dentro del HTML. Se escribe lo que se busca
 * (nombre, apellido o documento) y el server responde solo con lo que coincide.
 *
 * Cada búsqueda lleva su propio timer y su propio token, aparte de las
 * direcciones: antes compartían el debounce, así que escribir en un campo
 * cancelaba en silencio la consulta del otro.
 */
(function (root) {
    'use strict';

    var mod = root.ElyraTrasladoMod = root.ElyraTrasladoMod || {};
    var esc = root.ElyraFmt.escaparHtml;

    mod.pacientes = function (ctx, api) {
        var aj = mod.ajustes;
        var urls = root.ElyraMapaMod.urls;

        function ocultar() {
            var caja = ctx.dom.sugPaciente;
            if (!caja) return;

            caja.hidden = true;
            caja.innerHTML = '';

            var contenedor = caja.parentNode;
            if (contenedor) contenedor.classList.remove('sugerencias-arriba');
        }

        function pintar(resultados) {
            var caja = ctx.dom.sugPaciente;
            if (!caja) return;

            ctx.ultimosPacientes = resultados;

            if (!resultados.length) {
                caja.innerHTML = '<div class="sugerencia vacia">Ningún paciente coincide.</div>';
                api.ui.ubicar(caja);
                return;
            }

            var html = '';
            resultados.forEach(function (r, i) {
                html +=
                    '<div class="sugerencia" data-indice="' + i + '">' +
                    '<i class="bi bi-person"></i>' +
                    '<span class="sugerencia-texto"><b>' + esc(r.label) + '</b></span></div>';
            });

            caja.innerHTML = html;
            api.ui.ubicar(caja);

            caja.querySelectorAll('.sugerencia[data-indice]').forEach(function (nodo) {
                nodo.addEventListener('mousedown', function (e) {
                    e.preventDefault();

                    var elegido = resultados[parseInt(this.getAttribute('data-indice'), 10)];
                    ctx.dom.hiddenPacienteId.value = String(elegido.id);
                    ctx.dom.inPaciente.value = elegido.label;
                    ocultar();
                });
            });
        }

        function buscar(texto) {
            var consulta = (texto || '').trim();
            var estado = ctx.pacientes;

            if (consulta.length < aj.minCaracteresPaciente) {
                ocultar();
                if (estado.timer !== null) clearTimeout(estado.timer);
                if (estado.peticion) { estado.peticion.abort(); estado.peticion = null; }
                return;
            }

            if (estado.timer !== null) clearTimeout(estado.timer);
            if (estado.peticion) { estado.peticion.abort(); estado.peticion = null; }

            estado.timer = setTimeout(function () {
                var miToken = ++estado.token;
                var control = new AbortController();
                estado.peticion = control;

                fetch(urls.pacientes + '?q=' + encodeURIComponent(consulta), { signal: control.signal })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (miToken !== estado.token) return;
                        pintar(json && json.resultados ? json.resultados : []);
                    })
                    .catch(function (e) {
                        if (e && e.name === 'AbortError') return;
                        if (miToken !== estado.token) return;
                        ocultar();
                    });
            }, aj.esperaBusqueda);
        }

        return {
            buscar: buscar,
            pintar: pintar,
            ocultar: ocultar,
            ultimos: function () { return ctx.ultimosPacientes; }
        };
    };
})(window);