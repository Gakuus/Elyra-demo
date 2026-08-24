/**
 * login-alternar.js: botón del ojito en el formulario de login.
 *
 * Alterna la visibilidad de la contraseña entre type="password" (oculta,
 * con puntos) y type="text" (legible), actualizando el ícono de Bootstrap
 * Icons para reflejar el estado:
 *   - Contraseña oculta → se muestra bi-eye      (click para revelar)
 *   - Contraseña visible → se muestra bi-eye-slash (click para ocultar)
 *
 * Es una función global (no IIFE) porque el HTML del login la invoca
 * directamente con onclick="togglePassword()".
 */
function togglePassword() {
    var pw = document.getElementById('password');
    var icon = document.getElementById('pwIcon');
    if (pw.type === 'password') {
        pw.type = 'text';
        icon.className = 'bi bi-eye';
    } else {
        pw.type = 'password';
        icon.className = 'bi bi-eye-slash';
    }
}
