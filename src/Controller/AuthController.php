<?php

declare(strict_types=1);

/** AuthController: login, registro y logout usando la clase Auth. */
final class AuthController
{
    /** Enrutador interno; index.php delega acá los flujos de autenticación. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/' || $path === '' => self::home(),
            $path === '/login' && $method === 'POST' => self::loginPost(),
            $path === '/login' => self::login(),
            $path === '/registro' && $method === 'POST' => self::registroPost(),
            $path === '/registro' => self::registro(),
            $path === '/logout' && $method === 'POST' => self::logout(),
            default => pagina_404(),
        };
    }

    /** Portada pública. Si hay sesión iniciada, muestra banner con el nombre. */
    public static function home(): void
    {
        if (Auth::estaAutenticado()) {
            render_vista(__DIR__ . '/../../views/publico/home.html', [
                'sesion_iniciada' => ['1'],
                'usuario_nombre'  => htmlspecialchars((string) ($_SESSION['usuario_nombre'] ?? 'Usuario')),
                'es_paciente'     => rol_usuario() === 'paciente' ? ['1'] : [],
            ]);
            return;
        }
        render_vista(__DIR__ . '/../../views/publico/home.html', []);
    }

    /** Procesa login: ok → redirige (paciente a portada, resto al panel); si no, vuelve al formulario. */
    public static function loginPost(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));

        // Anti fuerza bruta: límite por IP y por IP+usuario. Al superarlo se
        // responde 429 y se registra el evento en la bitácora.
        $claveIp = 'login-ip:' . ip_usuario();
        $claveUsuario = 'login-user:' . ip_usuario() . ':' . mb_strtolower($username);
        if (!rate_limit_permitido($claveIp, 20, 900)
            || !rate_limit_permitido($claveUsuario, 5, 900)
        ) {
            log_seguridad('login_bloqueado', ['username' => $username]);
            http_response_code(429);
            render_vista(__DIR__ . '/../../views/auth/login.html', [
                'hay_error' => ['1'],
                'error' => 'Demasiados intentos fallidos. Esperá unos minutos e intentá de nuevo.',
            ]);
            return;
        }

        $resultado = Auth::login($username, $_POST['password'] ?? '');
        if ($resultado['success']) {
            rate_limit_reset($claveUsuario);
            log_seguridad('login_ok', ['username' => $username]);
            $destino = rol_usuario() === 'paciente' ? '/' : '/dashboard';
            header('Location: ' . base_path() . $destino);
            exit;
        }

        log_seguridad('login_fallido', ['username' => $username]);
        render_vista(__DIR__ . '/../../views/auth/login.html', [
            'hay_error' => ['1'],
            'error' => (string) ($resultado['error'] ?? 'Usuario o contraseña incorrectos.'),
        ]);
    }

    public static function login(): void
    {
        render_vista(__DIR__ . '/../../views/auth/login.html', []);
    }

    /** Registro: ok → al login con aviso; si no, vuelve al formulario. */
    public static function registroPost(): void
    {
        $resultado = Auth::registrar($_POST);
        if ($resultado['success']) {
            header('Location: ' . base_path() . '/login?registrado=1');
            exit;
        }
        render_vista(__DIR__ . '/../../views/auth/registro.html', []);
    }

    public static function registro(): void
    {
        render_vista(__DIR__ . '/../../views/auth/registro.html', []);
    }

    public static function logout(): void
    {
        log_seguridad('logout');
        Auth::logout();
        header('Location: ' . base_path() . '/');
        exit;
    }
}