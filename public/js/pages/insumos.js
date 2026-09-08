/**
 * insumos.js: comportamiento de la página "Insumos" del dashboard.
 * Se carga con el layout del dashboard, así que su API vive en window.ElyraInsumos
 * y se invoca desde los atributos onclick del HTML que genera InsumoController.
 *
 * Responsabilidad:
 *   - Activar/desactivar un insumo (baja lógica) con confirmación previa.
 */
(function () {
    'use strict';

    /**
     * toggle: activa o desactiva un insumo (POST /insumos/toggle).
     * Pide confirmación primero y, si el servidor responde ok, actualiza el
     * estado y el botón de la fila sin recargar la página. El endpoint
     * devuelve el estado nuevo (activo) para pintarlo al toque.
     */
    function toggle(id, btn) {
        var fila = document.querySelector('tr[data-insumo-id="' + id + '"]');
        var estaActivo = !!(fila && fila.querySelector('.text-bg-success'));
        var mensaje = estaActivo
            ? '¿Seguro que querés desactivar este insumo?'
            : '¿Seguro que querés activar este insumo?';
        if (!window.confirm(mensaje)) return;

        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/insumos/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Localiza la fila por data-insumo-id; si no está (caso raro),
                // recarga para reflejar el estado real del servidor.
                if (!fila) { window.location.reload(); return; }

                var activo = !!(data.activo);

                // Estado (Activo/Inactivo): se pinta el badge nuevo.
                fila.children[4].innerHTML = '<span class="badge ' + (activo ? 'text-bg-success' : 'text-bg-secondary') + '">'
                    + (activo ? 'Activo' : 'Inactivo') + '</span>';

                // El botón pasa a ofrecer la acción inversa.
                btn.disabled = false;
                btn.className = 'btn btn-sm ' + (activo ? 'btn-outline-warning' : 'btn-outline-success');
                btn.title = activo ? 'Desactivar insumo' : 'Activar insumo';
                btn.onclick = function () { ElyraInsumos.toggle(id, btn); };
            })
            .catch(function () {
                btn.disabled = false;
                window.alert('No se pudo cambiar el estado del insumo. Intentá de nuevo.');
            });
    }

    // API pública: los onclick inline del listado llaman a esta función.
    window.ElyraInsumos = {
        toggle: toggle
    };
})();