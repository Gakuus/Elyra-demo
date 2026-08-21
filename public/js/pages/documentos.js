(function () {
    'use strict';

    function abrirModal(id) {
        var modal = document.getElementById('qrModal');
        if (!modal) return;
        var body = document.getElementById('qrModalBody');
        var url = (window.BASE_PATH || '') + '/publico/doc?id=' + id;
        body.innerHTML = '<div class="mb-3"><div id="qrcode"></div></div>'
            + '<p class="small text-muted mb-2">Escanear para ver el documento</p>'
            + '<button class="btn btn-sm btn-primary me-1" id="copiarQr"><i class="bi bi-clipboard me-1"></i>Copiar enlace</button>'
            + '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>';
        modal.classList.add('open');
        var copiar = document.getElementById('copiarQr');
        if (copiar) {
            copiar.addEventListener('click', function () { copiarEnlace(url, copiar); });
        }
        cargarQR(url);
    }

    function cargarQR(url) {
        var el = document.getElementById('qrcode');
        if (!el) return;
        el.innerHTML = '';
        if (typeof window.QRCode !== 'undefined') {
            new window.QRCode(el, { text: url, width: 180, height: 180 });
            return;
        }
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
        script.onload = function () { new window.QRCode(el, { text: url, width: 180, height: 180 }); };
        script.onerror = function () {
            el.innerHTML = '<p class="text-muted small mb-0">No se pudo generar el c&oacute;digo QR.</p>';
        };
        document.head.appendChild(script);
    }

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

    function caer(texto, hecho) {
        var aux = document.createElement('textarea');
        aux.value = texto;
        document.body.appendChild(aux);
        aux.select();
        try { document.execCommand('copy'); hecho(); } catch (e) { /* noop */ }
        document.body.removeChild(aux);
    }

    window.ElyraDoc = {
        verQR: abrirModal,
        copiarEnlace: copiarEnlace
    };
})();
