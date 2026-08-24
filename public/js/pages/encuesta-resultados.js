/**
 * encuesta-resultados.js: dibuja los gráficos de la página "Resultados" con
 * Chart.js (cargado por CDN desde la vista encuestas_resultados.html).
 *
 * La vista inyecta antes el objeto window-level ENCUESTA_STATS, generado por
 * EncuestaController::resultados() — un array con una entrada por pregunta:
 *   {
 *     texto: '...', tipo: 'multiple_choice'|'escala'|'texto_libre',
 *     opciones: [...], conteo: {etiqueta: cantidad}, promedio: 0..5,
 *     textosLibres: [...], total: N
 *   }
 *
 * Por cada pregunta busca su <canvas id="chart-i"> (mismo índice que los
 * bloques HTML) y dibuja según el tipo:
 *   - multiple_choice → barras horizontales (una por opción).
 *   - escala          → torta con la distribución de notas 1 a 5.
 *   - texto_libre     → sin gráfico: las respuestas se listan como texto
 *                       en el HTML que ya genera el controlador.
 */
(function () {
    'use strict';

    if (typeof ENCUESTA_STATS === 'undefined') return;

    // Paleta fija para datasets; se recorta según la cantidad de valores.
    var colores = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14', '#0dcaf0', '#6610f2', '#d63384'];

    ENCUESTA_STATS.forEach(function (s, i) {
        var lienzo = document.getElementById('chart-' + i);
        // Sin canvas (texto libre) o sin Chart.js (CDN caído) → nada que hacer.
        if (!lienzo || typeof Chart === 'undefined') return;

        if (s.tipo === 'multiple_choice') {
            // Gráfico de barras horizontales: una barra por opción.
            // 'conteo' es un mapa {textoOpcion: cantidad} — keys y values
            // quedan alineados porque Object.keys conserva el orden de inserción.
            var etiquetas = Object.keys(s.conteo);
            var valores = etiquetas.map(function (k) { return s.conteo[k]; });
            new Chart(lienzo, {
                type: 'bar',
                data: {
                    labels: etiquetas,
                    datasets: [{ data: valores, backgroundColor: colores.slice(0, etiquetas.length), borderRadius: 6 }]
                },
                options: {
                    indexAxis: 'y',               // barras horizontales
                    responsive: true,
                    maintainAspectRatio: false,   // el alto lo maneja el contenedor
                    plugins: { legend: { display: false } }, // las labels ya son las opciones
                    scales: {
                        x: { beginAtZero: true, ticks: { stepSize: 1 } }, // conteos enteros
                        y: { ticks: { font: { size: 13 } } }
                    }
                }
            });

        } else if (s.tipo === 'escala') {
            // Torta con la distribución de notas 1 a 5.
            var etiquetasEscala = ['1', '2', '3', '4', '5'];
            var valoresEscala = etiquetasEscala.map(function (l) { return s.conteo[l] || 0; });
            new Chart(lienzo, {
                type: 'doughnut',
                data: {
                    labels: etiquetasEscala,
                    datasets: [{ data: valoresEscala, backgroundColor: colores.slice(0, 5), borderWidth: 2 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: { size: 13 } } } }
                }
            });
        }
        // texto_libre: no lleva gráfico (ver encabezado).
    });
})();
