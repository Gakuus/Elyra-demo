/**
 * equipamiento.js: comportamiento de la página "Equipamiento médico" del dashboard.
 * Su API vive en window.ElyraEquipamiento y se invoca desde los atributos onclick
 * del HTML que genera EquipamientoController.
 *
 * Responsabilidad:
 *   - Activar/desactivar un equipo (baja lógica) con confirmación previa.
 */
(function () {
    'use strict';

    function tr(texto, parametros) {
        return window.ElyraIdioma ? window.ElyraIdioma.t(texto, parametros) : texto;
    }

    /**
     * toggle: activa o desactiva un equipo (POST /equipamiento/toggle).
     * Pide confirmación primero y, si el servidor responde ok, actualiza el
     * estado y el botón de la fila sin recargar la página.
     */
    function toggle(id, btn) {
        var fila = document.querySelector('tr[data-equipamiento-id="' + id + '"]');
        var estaActivo = !!(fila && fila.querySelector('.text-bg-success'));
        var mensaje = estaActivo
            ? tr('¿Seguro que querés desactivar este equipamiento?')
            : tr('¿Seguro que querés activar este equipamiento?');
        if (!window.confirm(mensaje)) return;

        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/equipamiento/toggle', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, window.Elyra.csrfHeaders()),
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                if (!fila) { window.location.reload(); return; }

                var activo = !!(data.activo);

                fila.children[4].innerHTML = '<span class="badge ' + (activo ? 'text-bg-success' : 'text-bg-secondary') + '">'
                    + tr(activo ? 'Activo' : 'Inactivo') + '</span>';

                btn.disabled = false;
                btn.className = 'btn btn-sm ' + (activo ? 'btn-outline-warning' : 'btn-outline-success');
                btn.title = tr(activo ? 'Desactivar equipamiento' : 'Activar equipamiento');
                btn.onclick = function () { ElyraEquipamiento.toggle(id, btn); };
            })
            .catch(function () {
                btn.disabled = false;
                window.alert(tr('No se pudo cambiar el estado del equipamiento. Intentá de nuevo.'));
            });
    }

    window.ElyraEquipamiento = {
        toggle: toggle
    };
})();
