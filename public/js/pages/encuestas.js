/**
 * encuestas.js: comportamiento de la página "Encuestas generales" del
 * dashboard. Se carga con el layout, así que queda disponible en todas las
 * páginas del panel; su API pública vive en window.ElyraEnc y los botones
 * del listado (generado por EncuestaController) la invocan vía onclick.
 *
 * Responsabilidades:
 *   - Modal de código QR público de cada encuesta (ver/copiar/imprimir).
 *   - Copiar el enlace público al portapapeles.
 *   - Activar / desactivar (publicar / despublicar) sin recargar:
 *     desde el botón de acciones (cambiarEstado) o desde el switch de la
 *     columna Estado (handler al final del archivo).
 */
(function () {
    'use strict';

    // ================================================================
    // URL pública absoluta de una encuesta.
    // Se arma con window.location.origin para usar el host/puerto REAL
    // desde donde se abre la app (APP_URL del .env puede apuntar a otro
    // puerto y entonces el enlace copiado o el QR "no abren").
    // ================================================================
    function urlPublica(id) {
        return window.location.origin + (window.BASE_PATH || '') + '/publico/encuesta?id=' + id;
    }

    // ================================================================
    // Generación del QR (misma técnica que documentos.js):
    // dibuja el código con qrcodejs en el contenedor indicado; si la
    // librería todavía no está cargada, la trae del CDN bajo demanda.
    // ================================================================
    function generarEn(contenedor, texto, tamano) {
        contenedor.innerHTML = '';
        if (typeof window.QRCode !== 'undefined') {
            new window.QRCode(contenedor, { text: texto, width: tamano, height: tamano });
            return;
        }
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
     * verQR: abre el modal de QR público de una encuesta.
     * Reutiliza el MISMO #qrModal del layout que usa documentos.js, pero con
     * ids propios (#qrcodeEnc, #copiarQrEnc...) para no chocar si ambos
     * módulos están cargados. Reconstruye el contenido en cada apertura
     * porque depende de la encuesta elegida.
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
            imprimirQR(url);
        });
        generarEn(document.getElementById('qrcodeEnc'), url, 180);
    }

    // ================================================================
    // Impresión exclusiva del QR grande (usa #qrPrintArea del layout):
    // genera un QR de 480px en un área dedicada, oculta el resto de la
    // página con la clase .imprimiendo-qr (@media print) e imprime.
    // ================================================================
    function imprimirQR(url) {
        var area = document.getElementById('qrPrintArea');
        if (!area) { window.print(); return; }

        area.innerHTML = '';
        var caja = document.createElement('div');
        caja.className = 'qr-imprimible';
        area.appendChild(caja);

        // Directo en grande: mejor calidad que escalar el QR chico del modal.
        generarEn(caja, url, 480);

        document.body.classList.add('imprimiendo-qr');

        // Sale del modo impresión al terminar (afterprint), con respaldo a los
        // 8s por si el navegador nunca dispara el evento.
        var limpiar = function () {
            document.body.classList.remove('imprimiendo-qr');
            window.removeEventListener('afterprint', limpiar);
        };
        window.addEventListener('afterprint', limpiar);
        setTimeout(limpiar, 8000);

        // Espera mínima para que el QR termine de renderizarse antes de imprimir.
        setTimeout(function () { window.print(); }, 80);
    }

    // ================================================================
    // Copiar enlace público.
    // API moderna (navigator.clipboard) con fallback a execCommand.
    // Acá el feedback cambia solo el ícono dentro del botón (a un check)
    // porque estos botones son solo-icono.
    // ================================================================
    function copiarEnlace(texto, btn) {
        var hecho = function () {
            var icono = btn.querySelector('i');
            if (!icono) return;
            var anterior = icono.className;
            icono.className = 'bi bi-check-lg';
            setTimeout(function () { icono.className = anterior; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(hecho).catch(function () { caer(texto, hecho); });
        } else {
            caer(texto, hecho);
        }
    }

    /**
     * Fallback de copiado: textarea temporal + comando copy del navegador.
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
    // Desactivar / reactivar desde el botón de acciones (además del
    // switch). Al confirmarse actualiza el switch y el botón en la fila.
    // ================================================================
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

    // ================================================================
    // API global: window.ElyraEnc
    // Los onclick inline del listado llaman a estas funciones. copiarEnlace
    // recibe el ID (no la URL) y resuelve la URL pública acá adentro,
    // así el HTML generado por PHP no necesita saber nada de hosts/puertos.
    // ================================================================
    window.ElyraEnc = {
        verQR: verQR,
        copiarEnlace: function (id, btn) { copiarEnlace(urlPublica(id), btn); },
        imprimirQR: imprimirQR,
        cambiarEstado: cambiarEstado
    };

    // ================================================================
    // Switch activa/inactiva: publica o despublica la encuesta vía fetch.
    // Mismo endpoint que cambiarEstado (/encuestas/toggle); la diferencia
    // es que acá el origen del cambio ES el switch, así que ante un error
    // se revierte su estado visual para que no mienta.
    // ================================================================
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
