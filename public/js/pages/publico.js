/**
 * publico.js: comportamiento del menú de navegación en las vistas públicas
 * (las que ve el paciente sin login: documento por QR, encuesta, etc.).
 *
 * En pantallas angostas el menú se colapsa detrás de un botón hamburguesa
 * (#btnMenuPublico). Este script alterna la clase .open del contenedor de
 * enlaces (#enlacesNavegacion) para mostrarlo/ocultarlo.
 */
document.addEventListener('DOMContentLoaded', function () {
    var btnMenu = document.getElementById('btnMenuPublico');
    var enlacesNav = document.getElementById('enlacesNavegacion');

    // Si la página no tiene menú colapsable, no hay nada que hacer.
    if (!btnMenu || !enlacesNav) return;

    // Click en el hamburguesa → abre o cierra el menú.
    btnMenu.addEventListener('click', function () {
        enlacesNav.classList.toggle('open');
    });

    // Al tocar cualquier enlace del menú, este se cierra solo (comportamiento
    // esperado en mobile para no tapar el contenido de destino).
    enlacesNav.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { enlacesNav.classList.remove('open'); });
    });
});
