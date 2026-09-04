<?php

declare(strict_types=1);

// Carga la función db_connect() (config/database.php) para poder conectar a MySQL.
require_once __DIR__ . '/../config/database.php';

/**
 * Clase Auth: autenticación del sistema del Hospital de Clínicas.
 * Hay dos perfiles que entran por acá: el funcionario (admin o personal
 * del hospital) y el paciente. Ambos comparten la tabla "usuario" y cada
 * uno tiene sus credenciales en su propia tabla (funcionario / paciente).
 * Los métodos son estáticos, así que se llaman sin instanciar:
 * Auth::login(...), Auth::registrar(...), etc.
 */
final class Auth
{
    /**
     * Garantiza que $_SESSION exista al arrancar cada petición.
     * Lo llamamos una sola vez desde index.php, antes de que cualquier
     * controlador se fije si hay un funcionario o paciente logueado.
     */
    public static function iniciarSesion(): void
    {
        // session_status() devuelve PHP_SESSION_NONE cuando no hay sesión activa.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * True si quien está navegando ya inició sesión (funcionario o paciente).
     * En el login dejamos 'usuario_id' en $_SESSION, así que con comprobar
     * que esa variable exista y no esté vacía alcanza.
     */
    public static function estaAutenticado(): bool
    {
        return !empty($_SESSION['usuario_id']);
    }

    /**
     * Acceso interno del hospital: entra un funcionario (admin, conductor,
     * etc.) o un paciente con su usuario y contraseña.
     *
     * La contraseña llega en texto plano y se compara contra el hash que
     * guardamos con password_hash() en el registro. Devuelve
     * ['success' => true] si entró, o ['success' => false, 'error' => ...].
     */
    public static function login(string $username, string $password): array
    {
        // Validación básica: no aceptar campos vacíos.
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Ingrese usuario y contraseña'];
        }

        // Buscamos al que pide entrar; la consulta une funcionario y paciente.
        $pdo = db_connect();
        $usuario = self::buscarUsuario($pdo, $username);

        // Si no existe, está desactivado, o la contraseña no coincide, se
        // rechaza igual para no revelar cuál de los casos fue.
        if ($usuario === null || !password_verify($password, $usuario['password_hash'])) {
            return ['success' => false, 'error' => 'Credenciales inválidas'];
        }

        // Login correcto: guardamos en la sesión lo que las vistas van a
        // necesitar (nombre para saludar, rol para el menú, etc.).
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nombre'] = trim($usuario['nombre'] . ' ' . $usuario['apellido']);
        $_SESSION['usuario_rol'] = $usuario['rol'];
        $_SESSION['usuario_username'] = $username;

        return ['success' => true];
    }

    /**
     * Alta de un paciente. Es el registro desde la portada pública del
     * hospital; el paciente queda con su usuario y, al iniciar sesión, podrá
     * descargar sus documentos clínicos y ver el QR de su encuesta.
     *
     * $datos es el array del formulario ($_POST). Devuelve
     * ['success' => true] si se creó, o ['success' => false, 'error' => ...].
     */
    public static function registrar(array $datos): array
    {
        // Lee cada campo del formulario. El operador ?? evita errores si
        // el campo no llegó, y trim() elimina espacios en blanco alrededor.
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $apellido = trim((string) ($datos['apellido'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $documento = trim((string) ($datos['documento'] ?? ''));
        $username = trim((string) ($datos['username'] ?? ''));
        $telefono = trim((string) ($datos['telefono'] ?? ''));
        $password = (string) ($datos['password'] ?? '');
        $password2 = (string) ($datos['password2'] ?? '');
        // Código de funcionario (opcional): si viene un código válido, la cuenta
        // se crea como funcionario con el rol que trae el código en vez de paciente.
        $codigoFuncionario = strtoupper(trim((string) ($datos['codigo_funcionario'] ?? '')));

        // Cada campo se valida antes de tocar la base. Un dato mal formado
        // corta acá con su mensaje, y el formulario lo vuelve a mostrar.
        if (mb_strlen($nombre) < 2) return ['success' => false, 'error' => 'Ingrese un nombre válido'];
        if (mb_strlen($apellido) < 2) return ['success' => false, 'error' => 'Ingrese un apellido válido'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Ingrese un email válido'];
        if (!preg_match('/^\d{8}$/', $documento)) return ['success' => false, 'error' => 'La cédula debe tener 8 dígitos'];
        if (mb_strlen($username) < 3) return ['success' => false, 'error' => 'El usuario debe tener al menos 3 caracteres'];
        if (strlen($password) < 8) return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        if ($password !== $password2) return ['success' => false, 'error' => 'Las contraseñas no coinciden'];
        if ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) return ['success' => false, 'error' => 'El teléfono debe tener 8 o 9 dígitos'];

        $pdo = db_connect();

        // El usuario y el email son únicos en todo el sistema (un paciente no
        // puede pisar el nombre de usuario de un funcionario ni viceversa).
        if (self::buscarUsuario($pdo, $username) !== null) {
            return ['success' => false, 'error' => 'El nombre de usuario ya está registrado'];
        }

        $emailExiste = $pdo->prepare("SELECT id FROM usuario WHERE email = ?");
        $emailExiste->execute([$email]);
        if ($emailExiste->fetch()) {
            return ['success' => false, 'error' => 'El email ya está registrado'];
        }

        // La contraseña se guarda SIEMPRE como hash, nunca en texto plano.
        // 'cost' => 12 es el factor de trabajo de bcrypt (más alto = más seguro).
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // Token anónimo para el paciente: es lo que después se imprime como
        // QR junto a su documento, y con lo que se abre su encuesta de
        // satisfacción sin tener que pedirle usuario.
        $token = bin2hex(random_bytes(16));

        // Cuando llega un código de funcionario, la cuenta alta con ese rol;
        // si no, se registra el paciente tradicional.
        $esFuncionario = $codigoFuncionario !== '';

        try {
            // Si falla un paso, deshacemos todo para no dejar una cuenta a
            // medias (usuario sin credenciales) ni un código marcado por error.
            $pdo->beginTransaction();

            if ($esFuncionario) {
                // FOR UPDATE bloquea la fila del código mientras registramos:
                // dos personas que manden el formulario a la vez no pueden
                // usar el mismo código.
                $stmtCod = $pdo->prepare(
                    "SELECT id, rol, activo, usado
                     FROM codigo_funcionario
                     WHERE codigo = :codigo
                     FOR UPDATE"
                );
                $stmtCod->execute(['codigo' => $codigoFuncionario]);
                $codigo = $stmtCod->fetch();

                if (!$codigo || !$codigo['activo'] || $codigo['usado']) {
                    $pdo->rollBack();
                    return ['success' => false, 'error' => 'El código de funcionario no es válido o ya fue utilizado'];
                }

                // 1) La fila base en "usuario", ahora con tipo 'funcionario'.
                $stmt = $pdo->prepare("
                    INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                    VALUES ('funcionario', ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $documento]);
                $id = (int) $pdo->lastInsertId();

                // 2) Las credenciales en "funcionario", con el rol del código.
                $stmt = $pdo->prepare("
                    INSERT INTO funcionario (id, username, password_hash, telefono, activo, rol)
                    VALUES (?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$id, $username, $hash, $telefono, $codigo['rol']]);

                // 3) Consumir el código: queda marcado como usado y nadie más
                //    puede registrarse con él.
                $stmt = $pdo->prepare(
                    "UPDATE codigo_funcionario
                     SET usado = 1, usado_en = NOW(), usado_por = :id
                     WHERE id = :cid"
                );
                $stmt->execute(['id' => $id, 'cid' => $codigo['id']]);

                $rol = $codigo['rol'];
            } else {
                // 1) La fila base en "usuario", con su tipo 'paciente'.
                $stmt = $pdo->prepare("
                    INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                    VALUES ('paciente', ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $documento]);
                // lastInsertId() nos da el id recién creado.
                $id = (int) $pdo->lastInsertId();

                // 2) Las credenciales en "paciente", con el MISMO id
                //    (relación 1 a 1 entre usuario y paciente).
                $stmt = $pdo->prepare("
                    INSERT INTO paciente (id, token_acceso, username, password_hash, telefono, activo)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$id, $token, $username, $hash, $telefono]);

                $rol = 'paciente';
            }

            // Confirmamos ambos INSERT.
            $pdo->commit();

            // Registro exitoso: lo dejamos adentro directamente para que no
            // tenga que volver a escribir usuario y contraseña recién creados.
            $_SESSION['usuario_id'] = $id;
            $_SESSION['usuario_nombre'] = $nombre . ' ' . $apellido;
            $_SESSION['usuario_rol'] = $rol;
            $_SESSION['usuario_username'] = $username;

            return ['success' => true];
        } catch (PDOException $e) {
            // Cualquier error (p. ej. un duplicado que se escapó) revierte y avisa.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Error al registrar. Verificá que los datos no estén duplicados.'];
        }
    }

    /**
     * Sale del sistema. Limpia la sesión y la cookie del navegador para que
     * quien usó la pc del consultorio no quede con una sesión abierta.
     */
    public static function logout(): void
    {
        // Vacía por completo la variable de sesión.
        $_SESSION = [];

        // Borra la cookie de sesión del navegador (para que no quede guardada).
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        // Destruye la sesión en el servidor.
        session_destroy();
    }

    /**
     * Localiza a una persona por su nombre de usuario. Como funcionario y
     * paciente viven en tablas distintas, une las tres y deja que el tipo
     * de "usuario" diga cuál corresponde.
     *
     * $pdo: conexión activa a la base de datos.
     * $username: nombre de usuario a buscar.
     * Devuelve los datos de la persona, o null si no existe o está inactivo.
     */
    private static function buscarUsuario(PDO $pdo, string $username): ?array
    {
        // Una sola consulta que une las 3 tablas:
        // - usuario: datos comunes (nombre, apellido, email, tipo).
        // - funcionario: credenciales de empleados (username, password, rol).
        // - paciente: credenciales de pacientes (username, password).
        // LEFT JOIN porque un usuario es solo una de las dos cosas.
        // COALESCE(f.activo, p.activo) toma el "activo" de la tabla que
        // corresponda (la otra tiene NULL por el LEFT JOIN).
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.apellido, u.email, u.tipo,
                   f.username, f.password_hash, f.rol, COALESCE(f.activo, p.activo) AS activo
            FROM usuario u
            LEFT JOIN funcionario f ON f.id = u.id
            LEFT JOIN paciente p ON p.id = u.id
            WHERE f.username = :u1 OR p.username = :u2
            LIMIT 1
        ");
        // :u1 y :u2 reciben lo mismo (el nombre de usuario buscado).
        $stmt->execute(['u1' => $username, 'u2' => $username]);
        $row = $stmt->fetch();

        // Sin fila o usuario dado de baja (activo=0) no cuenta como válido.
        if ($row === false || !$row['activo']) return null;

        // El rol depende del tipo: los funcionarios traen el suyo (admin,
        // conductor...); un paciente siempre es 'paciente'.
        $row['rol'] = $row['tipo'] === 'funcionario' ? ($row['rol'] ?? 'funcionario') : 'paciente';
        return $row;
    }
}
