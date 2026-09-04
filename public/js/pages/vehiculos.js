/**
 * vehiculos.js: comportamiento de la página "Vehículos" del dashboard.
 * Se carga con el layout del dashboard, así que su API vive en window.ElyraVehiculos
 * y se invoca desde los atributos onclick del HTML que genera VehiculoController.
 *
 * Responsabilidad:
 *   - Eliminar un vehículo (baja física) con confirmación previa.
 */
(function () {
    'use strict';

    /**
     * eliminar: borra un vehículo (POST /vehiculos/eliminar).
     * Pide confirmación primero y, si el servidor responde ok, quita la
     * fila de la tabla sin recargar la página.
     */
    function eliminar(id, btn) {
        var mensaje = '¿Seguro que querés eliminar este vehículo?';
        if (!window.confirm(mensaje)) return;

        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/vehiculos/eliminar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Localiza la fila por data-vehiculo-id y la quita de la tabla.
                var fila = document.querySelector('tr[data-vehiculo-id="' + id + '"]');
                if (!fila) { window.location.reload(); return; }
                fila.remove();
            })
            .catch(function () {
                btn.disabled = false;
                window.alert('No se pudo eliminar el vehículo. Intentá de nuevo.');
            });
    }

    // API pública: los onclick inline del listado llaman a estas funciones.
    window.ElyraVehiculos = {
        eliminar: eliminar
    };
})();
