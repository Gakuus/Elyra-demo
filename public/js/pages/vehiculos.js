/**
 * vehiculos.js: comportamiento de la página "Vehículos" del dashboard.
 * Se carga con el layout del dashboard, así que su API vive en window.ElyraVehiculos
 * y se invoca desde los atributos onclick del HTML que genera VehiculoController.
 *
 * Responsabilidad:
 *   - Activar/desactivar un vehículo (baja lógica) con confirmación previa.
 */
(function () {
    'use strict';

    /**
     * toggle: activa o desactiva un vehículo (POST /vehiculos/toggle).
     * Pide confirmación primero y, si el servidor responde ok, actualiza el
     * estado y el botón de la fila sin recargar la página. El endpoint
     * devuelve el estado nuevo (activo) para pintarlo al toque.
     */
    function toggle(id, btn) {
        var fila = document.querySelector('tr[data-vehiculo-id="' + id + '"]');
        var estaActivo = !!(fila && fila.querySelector('.estado-activo'));
        var mensaje = estaActivo
            ? '¿Seguro que querés desactivar este vehículo?'
            : '¿Seguro que querés activar este vehículo?';
        if (!window.confirm(mensaje)) return;

        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/vehiculos/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Localiza la fila por data-vehiculo-id; si no está (caso
                // raro), recarga para reflejar el estado real del servidor.
                if (!fila) { window.location.reload(); return; }

                var activo = !!(data.activo);

                // Estado (Activo/Inactivo): se pinta el texto nuevo.
                fila.children[4].innerHTML = activo
                    ? '<span class="estado-activo">Activo</span>'
                    : '<span class="estado-inactivo">Inactivo</span>';

                // El botón pasa a ofrecer la acción inversa.
                btn.disabled = false;
                btn.className = 'btn btn-sm ' + (activo ? 'btn-outline-warning' : 'btn-outline-success');
                btn.title = activo ? 'Desactivar vehículo' : 'Activar vehículo';
                btn.onclick = function () { ElyraVehiculos.toggle(id, btn); };
            })
            .catch(function () {
                btn.disabled = false;
                window.alert('No se pudo cambiar el estado del vehículo. Intentá de nuevo.');
            });
    }

    // API pública: los onclick inline del listado llaman a estas funciones.
    window.ElyraVehiculos = {
        toggle: toggle
    };
})();