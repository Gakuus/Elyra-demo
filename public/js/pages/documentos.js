/**
 * documentos.js: comportamiento de la página "Documentos generales" del
 * dashboard. Se carga con el layout del dashboard, así que también está
 * disponible en el resto de las páginas (por eso su API vive en window.ElyraDoc
 * y se invoca desde atributos onclick del HTML que genera el controlador).
 *
 * Responsabilidades:
 *   - Modal de código QR público de cada documento (ver/copiar/imprimir).
 *   - Activar / desactivar documentos (borrado lógico) sin recargar.
 *   - Copiar enlaces públicos al portapapeles.
 */
(function () {
    'use strict';

    // ================================================================
    // URL pública absoluta
    // El QR necesita la URL COMPLETA (protocolo + dominio + ruta). Antes se
    // generaba con una ruta relativa ("/publico/doc?id=N"), por lo que al
    // escanearlo no abría nada y el enlace copiado tampoco funcionaba.
    // ================================================================
    function urlPublica(id) {
        return window.location.origin + (window.BASE_PATH || '') + '/publico/doc?id=' + id;
    }

    // ================================================================
    // Generación del QR
    // Carga la librería qrcodejs bajo demanda y dibuja el código en el
    // contenedor indicado, con el tamaño pedido.
    // ================================================================
    function generarEn(contenedor, texto, tamano) {
        contenedor.innerHTML = '';
        if (typeof window.QRCode !== 'undefined') {
            new window.QRCode(contenedor, { text: texto, width: tamano, height: tamano });
            return;
        }
        // La librería todavía no está cargada (primera vez en la sesión):
        // se inyecta el <script> desde CDN y se dibuja cuando termine.
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
        script.onload = function () {
            new window.QRCode(contenedor, { text: texto, width: tamano, height: tamano });
        };
        script.onerror = function () {
            contenedor.innerHTML = '<p class="text-muted small mb-0">No se pudo generar el c&oacute;digo QR.</p>';
        };
        document.head.appendChild(script);
    }

    /**
     * abrirModal: abre el modal de QR público de un documento.
     * Llamado por ElyraDoc.verQR(id) desde el botón QR de cada fila.
     *
     * Reconstruye el contenido del modal en cada apertura porque el QR y los
     * botones dependen del documento elegido, y les cuelga los listeners
     * recién acá (el modal es HTML estático del layout).
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
            imprimir.addEventListener('click', function () { imprimirQR(url); });
        }
        generarEn(document.getElementById('qrcode'), url, 180);
    }

    // ================================================================
    // Impresión del QR
    // Dibuja un QR GRANDE en un área exclusiva de impresión (#qrPrintArea)
    // y oculta todo lo demás vía @media print: la hoja sale solo con el QR.
    // ================================================================
    function imprimirQR(url) {
        var area = document.getElementById('qrPrintArea');
        if (!area) { window.print(); return; }

        area.innerHTML = '';
        var caja = document.createElement('div');
        caja.className = 'qr-imprimible';
        area.appendChild(caja);

        // Se genera directamente en grande (mejor calidad que agrandar el chico).
        generarEn(caja, url, 480);

        // La clase .imprimiendo-qr activa el CSS @media print que oculta
        // todo excepto #qrPrintArea.
        document.body.classList.add('imprimiendo-qr');

        // Limpia el modo impresión cuando termina (afterprint) o como
        // respaldo a los 8 segundos por si el navegador no dispara el evento.
        var limpiar = function () {
            document.body.classList.remove('imprimiendo-qr');
            window.removeEventListener('afterprint', limpiar);
        };
        window.addEventListener('afterprint', limpiar);
        setTimeout(limpiar, 8000);

        // Pequeña espera para que el QR termine de renderizarse antes de imprimir.
        setTimeout(function () { window.print(); }, 80);
    }

    // ================================================================
    // Activar / desactivar documento (POST /documentos/estado)
    // Actualiza la fila de la tabla sin recargar la página.
    // ================================================================
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

    // ================================================================
    // Copiar al portapapeles
    // Usa la API moderna (navigator.clipboard) y cae a execCommand para
    // navegadores viejos o contextos sin permiso (ej: http simple).
    // ================================================================
    function copiarEnlace(texto, btn) {
        var hecho = function () {
            var anterior = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Copiado';
            setTimeout(function () { btn.innerHTML = anterior; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(hecho).catch(function () { caer(texto, hecho); });
        } else {
            caer(texto, hecho);
        }
    }

    /**
     * Fallback de copiado: crea un <textarea> fuera de pantalla, selecciona
     * su contenido y ejecuta el comando copy del navegador.
     */
    function caer(texto, hecho) {
        var aux = document.createElement('textarea');
        aux.value = texto;
        document.body.appendChild(aux);
        aux.select();
        try { document.execCommand('copy'); hecho(); } catch (e) { /* noop */ }
        document.body.removeChild(aux);
    }

    // ================================================================
    // API pública: window.ElyraDoc
    // Los onclick inline del listado (generado por DocumentoController)
    // llaman a estas funciones.
    // ================================================================
    window.ElyraDoc = {
        verQR: abrirModal,
        copiarEnlace: copiarEnlace,
        cambiarEstado: cambiarEstado
    };
})();
