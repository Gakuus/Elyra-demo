document.addEventListener('DOMContentLoaded', function() {
    var btnMenu = document.getElementById('btnMenuPublico');
    var enlacesNav = document.getElementById('enlacesNavegacion');
    if (!btnMenu || !enlacesNav) return;
    btnMenu.addEventListener('click', function() {
        enlacesNav.classList.toggle('open');
    });
    enlacesNav.querySelectorAll('a').forEach(function(a) {
        a.addEventListener('click', function() { enlacesNav.classList.remove('open'); });
    });
});
