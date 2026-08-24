/**
 * encuesta-formulario.js: editor dinámico de preguntas de una encuesta.
 *
 * Lo comparten DOS vistas del dashboard:
 *   - encuestas_crear.html  → arranca con un bloque vacío.
 *   - encuestas_editar.html → la vista inyecta antes window.ENCUESTA_INICIAL,
 *     un array JSON con las preguntas actuales:
 *         [{ id: 5, tipo: 'multiple_choice', texto: '...', opciones: ['a','b'] }, ...]
 *
 * Cada pregunta se arma como un "bloque" (.question-item) cuyos inputs usan
 * nombres de array PHP:
 *   preguntas[idx][texto], preguntas[idx][tipo],
 *   preguntas[idx][opciones][]   (solo multiple_choice)
 *   preguntas[idx][id]           (hidden, SOLO en edición)
 * Así PHP recibe $_POST['preguntas'] ya estructurado y sabe qué preguntas
 * actualizar (las que traen id) y cuáles crear.
 */
(function () {
    'use strict';

    // Contador global de bloques: garantiza índices únicos en los name[],
    // sin importar el orden en que se agregan o precargan preguntas.
    var indicePregunta = 0;

    var contenedor = document.getElementById('questionsContainer');
    var btnAgregar = document.getElementById('addQuestion');
    var mensajeVacio = document.getElementById('noQuestions');

    // Si la página no es el formulario de encuestas, no hay nada que hacer.
    if (!contenedor || !btnAgregar) return;

    // Tipos de pregunta soportados (deben coincidir con el ENUM 'tipo' de la
    // tabla pregunta en la base de datos).
    var tipos = [
        { value: 'multiple_choice', label: 'Opci\u00f3n m\u00faltiple' },
        { value: 'escala', label: 'Escala (1-5)' },
        { value: 'texto_libre', label: 'Texto libre' }
    ];

    /**
     * agregarPregunta: agrega un bloque de pregunta al formulario.
     *
     * @param {object|null} data Datos para precargar el bloque
     *                           {id?, tipo?, texto?, opciones?}.
     *                           null/undefined → bloque vacío.
     */
    function agregarPregunta(data) {
        data = data || {};
        var idx = indicePregunta++;
        var div = document.createElement('div');
        div.className = 'question-item border rounded p-3 mb-3';
        div.dataset.index = String(idx);

        // <select> de tipos armado desde el array 'tipos'.
        var opcionesTipo = tipos.map(function (t) {
            return '<option value="' + t.value + '">' + t.label + '</option>';
        }).join('');

        div.innerHTML =
            '<div class="mb-2">'
            + '<input type="text" name="preguntas[' + idx + '][texto]" class="entrada-formulario"'
            + ' placeholder="Escrib\u00ed la pregunta..." required minlength="3" maxlength="500">'
            + '</div>'
            + '<div class="row g-2 align-items-center">'
            + '<div class="col-auto">'
            + '<select name="preguntas[' + idx + '][tipo]" class="form-select form-select-sm tipo-select" data-idx="' + idx + '">'
            + opcionesTipo
            + '</select></div>'
            + '<div class="col"><div class="opciones-container" id="opciones-' + idx + '"></div></div>'
            + '<div class="col-auto">'
            + '<button type="button" class="btn btn-sm btn-outline-danger remove-question" title="Quitar pregunta">'
            + '<i class="bi bi-trash"></i></button>'
            + '</div></div>';

        contenedor.appendChild(div);

        // Pregunta existente (solo edición): marca su id para el backend.
        if (data.id) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'preguntas[' + idx + '][id]';
            hidden.value = String(data.id);
            div.appendChild(hidden);
        }

        // Aplica texto y tipo iniciales si vinieron (precarga de edición).
        var inputTexto = div.querySelector('input[name$="[texto]"]');
        var selectTipo = div.querySelector('.tipo-select');
        var tipo = data.tipo || 'multiple_choice';
        if (!tipos.some(function (t) { return t.value === tipo; })) tipo = 'multiple_choice';

        if (inputTexto && data.texto) inputTexto.value = data.texto;
        if (selectTipo) selectTipo.value = tipo;

        if (mensajeVacio) mensajeVacio.style.display = 'none';
        actualizarOpciones(idx, tipo, data.opciones);
    }

    /**
     * actualizarOpciones: llena (o vacía) la zona de opciones de una pregunta
     * según su tipo. Solo multiple_choice muestra editor de opciones; para
     * escala y texto_libre lo limpia porque no aplican.
     *
     * @param {number}     idx               Índice del bloque.
     * @param {string}     tipo              Tipo seleccionado.
     * @param {array|null} opcionesIniciales Textos a precargar (edición);
     *                                       null → dos opciones vacías.
     */
    function actualizarOpciones(idx, tipo, opcionesIniciales) {
        var cont = document.getElementById('opciones-' + idx);
        if (!cont) return;
        cont.innerHTML = '';
        if (tipo !== 'multiple_choice') return;

        // En edición vienen las opciones reales; al crear, dos vacías listas.
        var lista = (Array.isArray(opcionesIniciales) && opcionesIniciales.length > 0)
            ? opcionesIniciales
            : [null, null];

        var envoltorio = document.createElement('div');
        envoltorio.className = 'd-flex flex-wrap align-items-center gap-1';

        /**
         * agregarOpcion: crea un chip "input + x". El input usa el nombre
         * de array preguntas[idx][opciones][] para que PHP junte todas.
         */
        function agregarOpcion(textoInicial) {
            var item = document.createElement('span');
            item.className = 'd-inline-flex align-items-center input-group input-group-sm';
            item.style.width = 'auto';
            item.innerHTML =
                '<input type="text" name="preguntas[' + idx + '][opciones][]"'
                + ' class="form-control" placeholder="Opci\u00f3n" style="width:140px" required maxlength="200">'
                + '<button type="button" class="btn btn-outline-secondary opcion-remove" title="Quitar opci\u00f3n">'
                + '<i class="bi bi-x"></i></button>';
            item.querySelector('.opcion-remove').addEventListener('click', function () {
                item.remove();
            });
            if (textoInicial) item.querySelector('input').value = textoInicial;
            envoltorio.insertBefore(item, btnOpcion); // siempre antes del "+"
        }

        var btnOpcion = document.createElement('button');
        btnOpcion.type = 'button';
        btnOpcion.className = 'btn btn-outline-primary btn-sm';
        btnOpcion.innerHTML = '<i class="bi bi-plus"></i>';
        btnOpcion.title = 'Agregar opci\u00f3n';
        btnOpcion.addEventListener('click', function () { agregarOpcion(null); });

        lista.forEach(agregarOpcion);
        envoltorio.appendChild(btnOpcion);
        cont.appendChild(envoltorio);
    }

    // ================================================================
    // Delegación de eventos dentro del contenedor (los bloques se crean
    // y destruyen dinámicamente, así que los listeners van en el padre).
    // ================================================================

    // Cambiar el tipo de pregunta reconstruye la zona de opciones.
    contenedor.addEventListener('change', function (e) {
        var target = e.target;
        if (target && target.classList && target.classList.contains('tipo-select')) {
            actualizarOpciones(parseInt(target.dataset.idx, 10), target.value, null);
        }
    });

    // Botón basurita: quita el bloque completo de la pregunta.
    contenedor.addEventListener('click', function (e) {
        if (e.target.closest('.remove-question')) {
            e.target.closest('.question-item').remove();
            // Si se quedó sin preguntas vuelve el mensaje inicial.
            if (!contenedor.querySelector('.question-item') && mensajeVacio) {
                mensajeVacio.style.display = '';
            }
        }
    });

    btnAgregar.addEventListener('click', function () { agregarPregunta(null); });

    // ================================================================
    // Arranque según la vista:
    //   - Edición: precarga cada pregunta existente (con su id).
    //   - Creación: deja un bloque vacío listo para completar.
    // ================================================================
    var inicial = (typeof window.ENCUESTA_INICIAL !== 'undefined' && window.ENCUESTA_INICIAL)
        ? window.ENCUESTA_INICIAL
        : [];
    if (inicial.length > 0) {
        inicial.forEach(function (p) { agregarPregunta(p); });
    } else {
        agregarPregunta(null);
    }
})();
