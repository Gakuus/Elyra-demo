/**
 * documentos.js: comportamiento de la página "Documentos" del dashboard.
 * Se carga con el layout del dashboard, así que sus funciones quedan
 * disponibles en todo el panel vía window.ElyraDoc (los onclick que arma el
 * controlador las invocan).
 *
 * Se encarga del modal de QR público de cada documento (ver/copiar/imprimir)
 * y de activar/desactivar documentos sin recargar la página.
 */
(function () {
    'use strict';

    // El QR necesita la URL COMPLETA (protocolo + dominio + ruta). Antes se
    // generaba con una ruta relativa, por lo que al escanear no abría nada.
    function urlPublica(id) {
        return window.Elyra.util.urlAbsoluta('/publico/doc?id=' + id);
    }

    /**
     * abrirModal: abre el modal de QR público de un documento. Lo llama
     * ElyraDoc.verQR(id) desde el botón QR de cada fila. Se reconstruye el
     * contenido en cada apertura porque el QR y los botones dependen del
     * documento elegido, y los listeners se cuelgan recién acá (el modal es
     * HTML estático del layout).
     */
    function abrirModal(id) {
        var modal = document.getElementById('qrModal');
        if (!modal) return;
        var body = document.getElementById('qrModalBody');
        var url = urlPublica(id);

        // Contenido: el QR chico (vista previa) + acciones.
        body.innerHTML = '<div class="mb-3"><div id="qrcode"></div></div>'
            + '<p class="small text-muted mb-2">Escanear para ver el documento</p>'
            + '<button class="btn btn-sm btn-primary me-1" id="copiarQr"><i class="bi bi-clipboard me-1"></i>Copiar enlace</button>'
            + '<button class="btn btn-sm btn-outline-secondary me-1" id="imprimirQr"><i class="bi bi-printer me-1"></i>Imprimir</button>'
            + '<a class="btn btn-sm btn-outline-secondary" href="' + url + '" target="_blank" rel="noopener">Abrir</a>';

        modal.classList.add('open');

        var copiar = document.getElementById('copiarQr');
        if (copiar) {
            copiar.addEventListener('click', function () { copiarEnlace(url, copiar); });
        }
        var imprimir = document.getElementById('imprimirQr');
        if (imprimir) {
            imprimir.addEventListener('click', function () { window.Elyra.util.imprimeQR(url); });
        }
        window.Elyra.util.generaQR(document.getElementById('qrcode'), url, 180);
    }

    // Activar / desactivar documento (POST /documentos/estado).
    // Actualiza la fila de la tabla sin recargar la página.
    function cambiarEstado(id, activo, btn) {
        btn.disabled = true;

        fetch((window.BASE_PATH || '') + '/documentos/estado', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id) + '&activo=' + encodeURIComponent(activo)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('error');

                // Localiza la fila por data-doc-id; si no está (caso raro),
                // recarga para reflejar el estado real del servidor.
                var fila = document.querySelector('tr[data-doc-id="' + id + '"]');
                if (!fila) { window.location.reload(); return; }

                // Badge "Inactivo" junto al título.
                var celdaTitulo = fila.children[1];
                var badge = celdaTitulo.querySelector('.badge');
                if (String(activo) === '0' && !badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge bg-secondary ms-1';
                    badge.textContent = 'Inactivo';
                    celdaTitulo.appendChild(badge);
                } else if (String(activo) === '1' && badge) {
                    badge.remove();
                }

                // Estado (Activo/Inactivo).
                fila.children[3].innerHTML = '<span class="' + (activo ? 'estado-activo' : 'estado-inactivo') + '">'
                    + (activo ? 'Activo' : 'Inactivo') + '</span>';

                // El propio botón pasa a ofrecer la acción inversa.
                btn.disabled = false;
                if (String(activo) === '0') {
                    btn.className = 'btn btn-sm btn-outline-success';
                    btn.title = 'Reactivar documento';
                    btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>';
                    btn.onclick = function () { ElyraDoc.cambiarEstado(id, 1, btn); };
                } else {
                    btn.className = 'btn btn-sm btn-outline-secondary';
                    btn.title = 'Desactivar documento';
                    btn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                    btn.onclick = function () { ElyraDoc.cambiarEstado(id, 0, btn); };
                }
            })
            .catch(function () { btn.disabled = false; });
    }

    // Copiar al portapapeles: usa la utilidad compartida (Elyra.util) y solo
    // agrega el feedback visual del botón (cambia a "Copiado" un momento).
    function copiarEnlace(texto, btn) {
        window.Elyra.util.copiaAlPortapapeles(texto, function () {
            var anterior = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Copiado';
            setTimeout(function () { btn.innerHTML = anterior; }, 1800);
        });
    }

    // Expone lo que usan los onclick del listado generado por DocumentoController.
    window.ElyraDoc = {
        verQR: abrirModal,
        copiarEnlace: copiarEnlace,
        cambiarEstado: cambiarEstado
    };
})();
