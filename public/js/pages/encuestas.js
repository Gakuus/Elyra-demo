/**
 * encuestas.js: comportamiento de la página "Encuestas generales" del
 * dashboard. Se carga con el layout, así que queda disponible en todo el
 * panel vía window.ElyraEnc (los botones y switches que arma el controlador
 * la invocan). Maneja el QR público de cada encuesta, copiar su enlace y
 * publicar/despublicar sin recargar.
 */
(function () {
    'use strict';

    function urlPublica(id) {
        return window.Elyra.util.urlAbsoluta('/publico/encuesta?id=' + id);
    }

    /**
     * verQR: abre el modal de QR público de una encuesta. Reutiliza el
     * MISMO #qrModal del layout que usa documentos.js, pero con ids propios
     * (#qrcodeEnc, #copiarQrEnc...) para no chocar si ambos módulos están
     * cargados. Reconstruye el contenido en cada apertura porque depende de
     * la encuesta elegida.
     */
    function verQR(id) {
        var modal = document.getElementById('qrModal');
        if (!modal) return;
        var body = document.getElementById('qrModalBody');
        var url = urlPublica(id);

        body.innerHTML = '<div class="mb-3"><div id="qrcodeEnc"></div></div>'
            + '<p class="small text-muted mb-2">Escanear para responder la encuesta</p>'
            + '<button class="btn btn-sm btn-primary me-1" id="copiarQrEnc"><i class="bi bi-clipboard me-1"></i>Copiar enlace</button>'
            + '<button class="btn btn-sm btn-outline-secondary me-1" id="imprimirQrEnc"><i class="bi bi-printer me-1"></i>Imprimir</button>'
            + '<a class="btn btn-sm btn-outline-secondary" href="' + url + '" target="_blank" rel="noopener">Abrir</a>';

        modal.classList.add('open');

        document.getElementById('copiarQrEnc').addEventListener('click', function () {
            copiarEnlace(url, this);
        });
        document.getElementById('imprimirQrEnc').addEventListener('click', function () {
            window.Elyra.util.imprimeQR(url);
        });
        window.Elyra.util.generaQR(document.getElementById('qrcodeEnc'), url, 180);
    }

    // Copiar enlace: comparte la utilidad con documentos y agrega el check.
    function copiarEnlace(texto, btn) {
        window.Elyra.util.copiaAlPortapapeles(texto, function () {
            var icono = btn.querySelector('i');
            if (!icono) return;
            var anterior = icono.className;
            icono.className = 'bi bi-check-lg';
            setTimeout(function () { icono.className = anterior; }, 1500);
        });
    }

    // Desactivar / reactivar desde el botón de acciones (además del switch).
    // Al confirmarse, sincroniza el switch y el botón de la fila.
    function cambiarEstado(id, activa, btn) {
        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/encuestas/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id) + '&activa=' + (activa ? '1' : '0')
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Sincroniza el switch de la misma fila (queda marcado igual
                // que el estado recién guardado).
                var fila = btn.closest('tr');
                if (fila) {
                    var sw = fila.querySelector('.form-check-input[data-encuesta-id]');
                    var etiqueta = fila.querySelector('.form-check-label');
                    if (sw) sw.checked = !!activa;
                    if (etiqueta) etiqueta.textContent = activa ? 'Activa' : 'Inactiva';
                }

                // El botón pasa a ofrecer la acción inversa.
                btn.innerHTML = '<i class="bi bi-' + (activa ? 'toggle-off' : 'arrow-counterclockwise') + '"></i>';
                btn.title = activa ? 'Desactivar' : 'Reactivar';
                btn.classList.toggle('text-success', !activa);
                btn.disabled = false;
            })
            .catch(function () { btn.disabled = false; });
    }

    // Exponemos lo que usan los onclick del listado. copiarEnlace recibe el
    // ID (no la URL) y resuelve la URL pública acá adentro, así el HTML que
    // genera PHP no tiene que saber nada de hosts ni puertos.
    window.ElyraEnc = {
        verQR: verQR,
        copiarEnlace: function (id, btn) { copiarEnlace(urlPublica(id), btn); },
        imprimirQR: function (url) { window.Elyra.util.imprimeQR(url); },
        cambiarEstado: cambiarEstado
    };

    // Switch activa/inactiva: publica o despublica la encuesta vía fetch.
    // Usa el mismo endpoint que cambiarEstado (/encuestas/toggle); la
    // diferencia es que acá el origen del cambio ES el switch, así que ante
    // un error se revierte su estado visual para que no mienta.
    document.querySelectorAll('.form-check-input[data-encuesta-id]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            var activa = toggle.checked ? '1' : '0';
            var etiqueta = toggle.parentElement.querySelector('label');
            toggle.disabled = true; // evita cambios mientras viaja el request

            fetch((window.BASE_PATH || '') + '/encuestas/toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(toggle.dataset.encuestaId) + '&activa=' + activa
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.ok !== true) throw new Error('error');
                    if (etiqueta) etiqueta.textContent = toggle.checked ? 'Activa' : 'Inactiva';
                    toggle.disabled = false;
                })
                .catch(function () {
                    toggle.checked = !toggle.checked; // revierte al estado previo
                    toggle.disabled = false;
                });
        });
    });
})();
