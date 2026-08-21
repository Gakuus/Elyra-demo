(function () {
    'use strict';

    // Filtro de entrada: solo permite valores que pasen inputFilter
    window.Elyra = {
        setInputFilter: function (textbox, inputFilter, errMsg) {
            ['input', 'keydown', 'keyup', 'mousedown', 'mouseup', 'select', 'contextmenu', 'drop', 'focusout'].forEach(function (event) {
                textbox.addEventListener(event, function (e) {
                    if (inputFilter(this.value)) {
                        if (['keydown', 'mousedown', 'focusout'].indexOf(e.type) >= 0) {
                            this.classList.remove('input-error');
                            this.setCustomValidity('');
                        }
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty('oldValue')) {
                        this.classList.add('input-error');
                        this.setCustomValidity(errMsg);
                        this.reportValidity();
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    } else {
                        this.value = '';
                    }
                });
            });
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-numeric]').forEach(function (el) {
            window.Elyra.setInputFilter(el, function (value) {
                return /^\d*$/.test(value);
            }, 'Solo se permiten números');
        });
    });
})();
