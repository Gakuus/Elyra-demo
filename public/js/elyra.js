/**
 * elyra.js: utilidades globales del sitio, disponibles como window.Elyra.
 * Se carga en el layout del dashboard y en las vistas públicas/auth.
 * Agrupa el filtrado de campos numéricos, la generación/impresión de QR y
 * el copiado al portapapeles, que antes estaban duplicados en cada módulo.
 */
(function () {
    'use strict';

    /**
     * setInputFilter: restringe lo que se puede escribir en un campo.
     * Escucha varios eventos (teclear, mouse, pegar/drop, foco) y, si el
     * valor actual no pasa el filtro, revierte al último que sí era válido
     * y muestra el mensaje de error del navegador. Si nunca hubo un valor
     * válido (p. ej. pegó texto con el campo vacío), solo lo vacía.
     *
     * textbox:      el <input> a filtrar.
     * inputFilter:  recibe el valor y devuelve true/false.
     * errMsg:       mensaje de validez que muestra el navegador.
     */
    function setInputFilter(textbox, inputFilter, errMsg) {
        // Eventos que cubren tipeo, click, arrastrar/soltar y pérdida de foco.
        ['input', 'keydown', 'keyup', 'mousedown', 'mouseup', 'select', 'contextmenu', 'drop', 'focusout'].forEach(function (event) {
            textbox.addEventListener(event, function (e) {
                if (inputFilter(this.value)) {
                    // Valor válido: solo limpia el estado de error en los
                    // eventos "finales" (no en cada tecla intermedia).
                    if (['keydown', 'mousedown', 'focusout'].indexOf(e.type) >= 0) {
                        this.classList.remove('input-error');
                        this.setCustomValidity('');
                    }
                    // Guarda el valor y la selección como último estado bueno.
                    this.oldValue = this.value;
                    this.oldSelectionStart = this.selectionStart;
                    this.oldSelectionEnd = this.selectionEnd;
                } else if (this.hasOwnProperty('oldValue')) {
                    // Valor inválido pero había uno válido antes → restaurar.
                    this.classList.add('input-error');
                    this.setCustomValidity(errMsg);
                    this.reportValidity();
                    this.value = this.oldValue;
                    this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                } else {
                    // Inválido y sin valor previo conocido → vaciar.
                    this.value = '';
                }
            });
        });
    }

    /**
     * Devuelve la URL pública absoluta (protocolo + dominio real + ruta).
     * Se arma con window.location.origin para usar el host/puerto REAL desde
     * donde se abre la app: APP_URL del .env puede apuntar a otro puerto y
     * entonces el enlace copiado o el QR "no abren".
     *
     * ruta: relativa y empezando con '/', ej: '/publico/doc?id=1'.
     */
    function urlAbsoluta(ruta) {
        return window.location.origin + (window.BASE_PATH || '') + ruta;
    }

    /**
     * generaQR: dibuja un código QR en el contenedor. La primera vez carga la
     * librería qrcodejs desde el CDN; después reutiliza el objeto global.
     */
    function generaQR(contenedor, texto, tamano) {
        contenedor.innerHTML = '';
        var dibujar = function () {
            new window.QRCode(contenedor, { text: texto, width: tamano, height: tamano });
        };
        if (typeof window.QRCode !== 'undefined') {
            dibujar();
            return;
        }
        // Primera vez en la sesión: se inyecta el <script> desde CDN.
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
        script.onload = dibujar;
        script.onerror = function () {
            contenedor.innerHTML = '<p class="text-muted small mb-0">No se pudo generar el código QR.</p>';
        };
        document.head.appendChild(script);
    }

    /**
     * imprimeQR: genera un QR GRANDE en #qrPrintArea y oculta el resto de la
     * página vía @media print, de modo que la hoja sale solo con el código.
     */
    function imprimeQR(url) {
        var area = document.getElementById('qrPrintArea');
        if (!area) { window.print(); return; }

        area.innerHTML = '';
        var caja = document.createElement('div');
        caja.className = 'qr-imprimible';
        area.appendChild(caja);

        // Se genera directamente en grande (mejor calidad que agrandar el chico).
        generaQR(caja, url, 480);

        // La clase .imprimiendo-qr activa el CSS @media print.
        document.body.classList.add('imprimiendo-qr');

        // Sale del modo impresión al terminar (afterprint), con respaldo a los
        // 8s por si el navegador nunca dispara el evento.
        var limpiar = function () {
            document.body.classList.remove('imprimiendo-qr');
            window.removeEventListener('afterprint', limpiar);
        };
        window.addEventListener('afterprint', limpiar);
        setTimeout(limpiar, 8000);

        // Pequeña espera para que el QR termine de renderizarse antes de imprimir.
        setTimeout(function () { window.print(); }, 80);
    }

    /**
     * copiaAlPortapapeles: copia un texto. Usa la API moderna
     * (navigator.clipboard) y, si falla o no está disponible (navegadores
     * viejos, http), cae al método viejo con execCommand. Al terminar llama
     * a onHecho.
     */
    function copiaAlPortapapeles(texto, onHecho) {
        var fallback = function () {
            // textarea temporal fuera de pantalla + comando copy del navegador.
            var aux = document.createElement('textarea');
            aux.value = texto;
            document.body.appendChild(aux);
            aux.select();
            try {
                document.execCommand('copy');
                onHecho();
            } catch (e) { /* sin feedback si el navegador niega el portapapeles */ }
            document.body.removeChild(aux);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(onHecho).catch(fallback);
        } else {
            fallback();
        }
    }

    /**
     * togglePassword (global): botón del "ojito" que muestra/oculta la
     * contraseña cambiando type="password" <-> "text" y el ícono de ojo.
     * Acepta params para reutilizarse en cualquier formulario; si se omiten,
     * usa los del login (campo #password, ícono #pwIcon).
     */
    window.togglePassword = function (id, iconId) {
        var pw = document.getElementById(id || 'password');
        var icon = document.getElementById(iconId || 'pwIcon');
        if (!pw || !icon) return;
        if (pw.type === 'password') {
            pw.type = 'text';
            icon.className = 'bi bi-eye';
        } else {
            pw.type = 'password';
            icon.className = 'bi bi-eye-slash';
        }
    };

    window.Elyra = {
        setInputFilter: setInputFilter,
        util: {
            urlAbsoluta: urlAbsoluta,
            generaQR: generaQR,
            imprimeQR: imprimeQR,
            copiaAlPortapapeles: copiaAlPortapapeles
        }
    };

    /**
     * Al cargar la página, todo elemento marcado con data-numeric queda
     * limitado a dígitos (0-9). Lo usan los formularios con campos numéricos.
     */
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-numeric]').forEach(function (el) {
            window.Elyra.setInputFilter(el, function (value) {
                return /^\d*$/.test(value);
            }, 'Solo se permiten números');
        });
    });
})();
