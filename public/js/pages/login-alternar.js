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
