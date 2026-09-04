/**
 * usuarios.js: comportamiento del módulo "Usuarios" del dashboard.
 *
 * API pública: window.ElyraUsuario
 *   - buscar():  búsqueda en vivo. Escucha el input #buscarUsuario y, con un
 *                pequeño retardo (debounce), consulta /usuarios/buscar y
 *                reemplaza el contenedor #resultadoUsuarios con los resultados.
 *   - cambiarEstado(id, activo, btn): desactiva o reactiva a una persona
 *                (borrado lógico) sin recargar la página.
 */
(function () {
    'use strict';

    var input = null;
    var contenedor = null;
    var timer = null;

    /**
     * Inicializa la búsqueda en vivo cuando el DOM está listo. Solo aplica
     * si la página tiene el input #buscarUsuario (es decir, la vista /usuarios).
     */
    function init() {
        input = document.getElementById('buscarUsuario');
        contenedor = document.getElementById('resultadoUsuarios');
        if (!input || !contenedor) return;

        // Con "input" reaccionamos a cada tecla; con "debounce" esperamos un
        // instante antes de disparar la petición para no hacer una por tecla.
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(buscar, 250);
        });

        // Enter dentro del campo también dispara la búsqueda.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                buscar();
            }
        });

        // Buscar de inmediato si la URL trae ?q= (ej: al volver de la ficha).
        var params = new URLSearchParams(window.location.search);
        var q = params.get('q');
        if (q) {
            input.value = q;
            buscar();
        }
    }

    /**
     * Consulta el endpoint de búsqueda y pinta los resultados.
     * Devuelve las filas de la tabla en el contenedor; si no hay texto ni
     * resultados, muestra un mensaje neutro o vacío.
     */
    function buscar() {
        if (!input || !contenedor) return;

        var q = input.value.trim();

        // Sin texto: no mostramos nada (la vista no lista todos por defecto).
        if (q === '') {
            contenedor.innerHTML = '';
            return;
        }

        var url = (window.BASE_PATH || '') + '/usuarios/buscar?q=' + encodeURIComponent(q);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) return;
                contenedor.innerHTML = data.html || '';

                // Contador de resultados sobre la tabla.
                if (data.total > 0 && contenedor.querySelector('table')) {
                    var cab = '<div class="text-muted small mb-1"><i class="bi bi-people me-1"></i>'
                        + data.total + ' resultado' + (data.total === 1 ? '' : 's') + '</div>';
                    contenedor.insertAdjacentHTML('afterbegin', cab);
                }
            })
            .catch(function () { /* silencioso */ });
    }

    /**
     * cambiarEstado: desactiva o reactiva a una persona
     * (POST /usuarios/estado). Actualiza la fila y el botón sin recargar.
     * Es la misma lógica, usada tanto en el listado en vivo como en la ficha.
     */
    function cambiarEstado(id, activo, btn) {
        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/usuarios/estado', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id) + '&activo=' + encodeURIComponent(activo)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Si estamos en el listado (hay fila), actualiza celda de
                // estado y el badge "Inactivo" junto al nombre.
                var fila = document.querySelector('tr[data-usuario-id="' + id + '"]');
                if (fila) {
                    var celdaEstado = fila.children[4];
                    celdaEstado.innerHTML = '<span class="' + (activo ? 'estado-activo' : 'estado-inactivo') + '">'
                        + (activo ? 'Activo' : 'Inactivo') + '</span>';

                    var celdaNombre = fila.children[1];
                    var badge = celdaNombre.querySelector('.badge');
                    if (String(activo) === '0' && !badge) {
                        badge = document.createElement('span');
                        badge.className = 'badge bg-secondary ms-1';
                        badge.textContent = 'Inactivo';
                        celdaNombre.appendChild(badge);
                    } else if (String(activo) === '1' && badge) {
                        badge.remove();
                    }
                }

                // En la ficha (/usuarios/ver): actualiza el badge de estado.
                var badgeEstado = document.querySelector('.estado-activo, .estado-inactivo');
                if (badgeEstado && !fila) {
                    badgeEstado.className = activo ? 'estado-activo' : 'estado-inactivo';
                    badgeEstado.textContent = activo ? 'Activo' : 'Inactivo';
                }

                // Invierte el botón (desactivar ↔ reactivar).
                btn.disabled = false;
                if (String(activo) === '0') {
                    btn.className = 'btn btn-outline-success';
                    btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i> Reactivar';
                    btn.onclick = function () { ElyraUsuario.cambiarEstado(id, 1, btn); };
                } else {
                    btn.className = 'btn btn-outline-danger';
                    btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i> Desactivar';
                    btn.onclick = function () { ElyraUsuario.cambiarEstado(id, 0, btn); };
                }
            })
            .catch(function () { btn.disabled = false; });
    }

    // Inicializa al cargar el DOM.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // API pública.
    window.ElyraUsuario = {
        buscar: buscar,
        cambiarEstado: cambiarEstado
    };
})();