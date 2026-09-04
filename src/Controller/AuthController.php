<?php

declare(strict_types=1);

/**
 * AuthController: controlador de autenticación.
 * Recibe las peticiones relacionadas con login, registro y logout
 * (decididas en index.php) y las resuelve usando la clase Auth.
 */
final class AuthController
{
    /**
     * Página de inicio pública (la portada antes de iniciar sesión).
     * Simplemente muestra la vista home.html sin más lógica.
     */
    public static function home(): void
    {
        // Renderiza la vista pública (reemplaza {{base_path}} para las URLs).
        render_vista(__DIR__ . '/../../views/publico/home.html', []);
    }

    /**
     * Procesa el formulario de login (envío por POST a /login).
     * Toma lo que el usuario escribió, lo valida con Auth::login y según el
     * resultado redirige al dashboard o vuelve a mostrar el formulario.
     */
    public static function loginPost(): void
    {
        // Auth::login devuelve ['success' => true] o ['success' => false, ...].
        // $_POST['username'] ?? '' devuelve '' si el campo no llegó (evita errores).
        $resultado = Auth::login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($resultado['success']) {
            // Entró bien: redirige al panel principal. exit corta la ejecución
            // para que no se siga mostrando HTML después del header.
            header('Location: ' . base_path() . '/dashboard');
            exit;
        }
        // Si falló, muestra el formulario de login otra vez.
        render_vista(__DIR__ . '/../../views/auth/login.html', []);
    }

    /**
     * Muestra el formulario de login (GET a /login).
     */
    public static function login(): void
    {
        render_vista(__DIR__ . '/../../views/auth/login.html', []);
    }

    /**
     * Procesa el formulario de registro (envío por POST a /registro).
     * Registra un nuevo paciente y, si todo va bien, lo redirige al login
     * avisando que el registro fue exitoso.
     */
    public static function registroPost(): void
    {
        // Auth::registrar recibe todos los campos del formulario ($_POST).
        $resultado = Auth::registrar($_POST);
        if ($resultado['success']) {
            // Registro OK: redirige al login con ?registrado=1 (para mostrar aviso).
            header('Location: ' . base_path() . '/login?registrado=1');
            exit;
        }
        // Si falló, vuelve a mostrar el formulario de registro.
        render_vista(__DIR__ . '/../../views/auth/registro.html', []);
    }

    /**
     * Muestra el formulario de registro (GET a /registro).
     */
    public static function registro(): void
    {
        render_vista(__DIR__ . '/../../views/auth/registro.html', []);
    }

    /**
     * Cierra la sesión (envío por POST a /logout) y vuelve a la portada.
     */
    public static function logout(): void
    {
        // Auth::logout destruye la sesión y borra la cookie.
        Auth::logout();
        header('Location: ' . base_path() . '/');
        exit;
    }
}
