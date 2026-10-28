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
            $path === '/recuperar' && $method === 'POST' => self::recuperarPost(),
            $path === '/recuperar' => self::recuperar(),
            $path === '/restablecer' && $method === 'POST' => self::restablecerPost(),
            $path === '/restablecer' => self::restablecer(),
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
            // Todos los roles entran al panel. El paciente tiene su propia
            // portada (DashboardController::inicio) con sus traslados, sus
            // datos y las encuestas abiertas; antes caía en la portada pública
            // y no tenía forma directa de llegar a su vista.
            header('Location: ' . base_path() . '/dashboard');
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
        // Mensajes de confirmación al volver desde registro o restablecimiento.
        $datos = [];
        if (($_GET['registrado'] ?? '') === '1') {
            $datos = ['hay_exito' => ['1'], 'exito' => 'Cuenta creada con éxito. Ya podés iniciar sesión.'];
        } elseif (($_GET['restablecido'] ?? '') === '1') {
            $datos = ['hay_exito' => ['1'], 'exito' => 'Contraseña actualizada. Iniciá sesión con tu nueva contraseña.'];
        }
        render_vista(__DIR__ . '/../../views/auth/login.html', $datos);
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

    /** Formulario "olvidé mi contraseña". */
    public static function recuperar(): void
    {
        render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
            'hay_error' => [],
            'hay_exito' => [],
        ]);
    }

    /**
     * Procesa la solicitud de recuperación. Si el correo existe, genera el
     * token y envía el enlace por Gmail (PHPMailer); si no está registrado o
     * el envío falla, se avisa explícitamente en la pantalla.
     */
    public static function recuperarPost(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));

        // Freno de fuerza bruta: por IP y por correo.
        $claveIp = 'recuperar_ip:' . ip_usuario();
        $claveEmail = 'recuperar_email:' . mb_strtolower($email);
        if (!rate_limit_permitido($claveIp, 10, 900)
            || !rate_limit_permitido($claveEmail, 3, 3600)
        ) {
            log_seguridad('password_reset_bloqueado', ['email' => $email]);
            http_response_code(429);
            render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
                'hay_error' => ['1'],
                'hay_exito' => [],
                'error' => 'Demasiadas solicitudes. Esperá unos minutos e intentá de nuevo.',
            ]);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            log_seguridad('password_reset_email_invalido');
            render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
                'hay_error' => ['1'],
                'hay_exito' => [],
                'error' => 'Ingresá un correo electrónico válido.',
            ]);
            return;
        }

        $datos = Auth::generarTokenReset($email);

        if ($datos === null) {
            // El correo no está registrado: se avisa explícitamente.
            log_seguridad('password_reset_email_inexistente');
            render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
                'hay_error' => ['1'],
                'hay_exito' => [],
                'error' => 'Ese correo no está registrado en el sistema.',
            ]);
            return;
        }

        $enlace = self::enlaceAbsoluto('/restablecer?token=' . urlencode($datos['token']));
        $enviado = MailService::enviarRecuperacion($datos['email'], $datos['nombre'], $enlace);
        log_seguridad($enviado ? 'password_reset_solicitado' : 'password_reset_mail_fallido', ['email' => $email]);

        if (!$enviado) {
            render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
                'hay_error' => ['1'],
                'hay_exito' => [],
                'error' => 'No pudimos enviar el correo. Intentá de nuevo en unos minutos.',
            ]);
            return;
        }

        render_vista(__DIR__ . '/../../views/auth/recuperar.html', [
            'hay_exito' => ['1'],
            'exito' => I18n::t(
                'Te enviamos un enlace a :email para restablecer la contraseña. Revisá tu bandeja de entrada (y la carpeta de spam).',
                ['email' => $email]
            ),
        ]);
    }

    /** Formulario para elegir la nueva contraseña (llega con el token en la URL). */
    public static function restablecer(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $valido = $token !== '';
        render_vista(__DIR__ . '/../../views/auth/restablecer.html', [
            'token'     => $token,
            'hay_token' => $valido ? ['1'] : [],
            'hay_error' => $valido ? [] : ['1'],
            'error'     => $valido ? '' : 'El enlace es inválido o está incompleto. Pedí uno nuevo.',
        ]);
    }

    /** Guarda la nueva contraseña si el token es válido y no venció. */
    public static function restablecerPost(): void
    {
        $token = (string) ($_POST['token'] ?? '');
        $resultado = Auth::restablecerPassword(
            $token,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password2'] ?? '')
        );

        if ($resultado['success']) {
            log_seguridad('password_reset_ok');
            header('Location: ' . base_path() . '/login?restablecido=1');
            exit;
        }

        log_seguridad('password_reset_fallido');
        render_vista(__DIR__ . '/../../views/auth/restablecer.html', [
            'token'     => $token,
            'hay_token' => $token !== '' ? ['1'] : [],
            'hay_error' => ['1'],
            'error'     => (string) ($resultado['error'] ?? 'No se pudo restablecer la contraseña.'),
        ]);
    }

    public static function logout(): void
    {
        log_seguridad('logout');
        Auth::logout();
        header('Location: ' . base_path() . '/');
        exit;
    }

    /**
     * Arma la URL absoluta de un enlace interno para el correo. Usa APP_URL
     * (el mismo prefijo que se publica en el servidor) y, si no está definida,
     * cae al host de la petición.
     */
    private static function enlaceAbsoluto(string $ruta): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');

        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') === '443');
            $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
        }

        return $base . $ruta;
    }
}