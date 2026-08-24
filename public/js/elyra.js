/**
 * elyra.js: utilidades globales del sitio, disponibles como window.Elyra.
 * Se carga en todas las páginas (layout del dashboard y vistas públicas).
 *
 * Hoy expone una única utilidad:
 *   - Elyra.setInputFilter(): restringe lo que el usuario puede escribir en
 *     un campo de texto según una función de validación.
 */
(function () {
    'use strict';

    /**
     * setInputFilter: filtro de entrada para campos de texto.
     * Escucha un conjunto amplio de eventos (teclado, mouse, pegar/drop,
     * foco) y, si el valor actual no pasa el filtro, revierte al último
     * valor válido mostrando el mensaje de error.
     *
     * Funciona así:
     *   1. Si inputFilter(valor) es true → guarda ese valor como "último
     *      válido" (junto con la posición del cursor para poder restaurarla).
     *   2. Si es false y ya había un valor guardado → marca el campo con la
     *      clase .input-error, muestra errMsg y restaura valor + cursor.
     *   3. Si es false y no hay valor previo (ej: pegó texto con el campo
     *      vacío) → simplemente vacía el campo.
     *
     * @param {HTMLElement} textbox     Campo <input> a filtrar.
     * @param {Function}    inputFilter Recibe el valor y devuelve true/false.
     * @param {string}      errMsg      Mensaje de validez nativo del browser.
     */
    window.Elyra = {
        setInputFilter: function (textbox, inputFilter, errMsg) {
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
