<?php

declare(strict_types=1);

// Carga la función db_connect() (config/database.php) para poder conectar a MySQL.
require_once __DIR__ . '/../config/database.php';

/**
 * Clase Auth: maneja toda la autenticación del sistema.
 * Todas las funciones son estáticas, por lo que se llaman sin crear una
 * instancia: Auth::login(...), Auth::estaAutenticado(), etc.
 */
final class Auth
{
    /**
     * Inicia la sesión de PHP si todavía no está iniciada.
     * Se llama al comienzo de cada petición (desde index.php) para que
     * $_SESSION esté disponible en todos los controladores.
     */
    public static function iniciarSesion(): void
    {
        // session_status() devuelve PHP_SESSION_NONE cuando no hay sesión activa.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Indica si el usuario actual está autenticado (es decir, si ya inició sesión).
     * Al hacer login se guarda 'usuario_id' en $_SESSION, así que basta con
     * comprobar que esa variable exista y no esté vacía.
     */
    public static function estaAutenticado(): bool
    {
        return !empty($_SESSION['usuario_id']);
    }

    /**
     * Autentica al usuario con su nombre de usuario y contraseña.
     *
     * @param string $username Nombre de usuario ingresado.
     * @param string $password Contraseña ingresada (sin cifrar).
     * @return array ['success' => true] si entró bien, o
     *               ['success' => false, 'error' => 'mensaje'] si falló.
     */
    public static function login(string $username, string $password): array
    {
        // Validación básica: no aceptar campos vacíos.
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Ingrese usuario y contraseña'];
        }

        // Conecta a la base de datos y busca el usuario por su nombre de usuario.
        $pdo = db_connect();
        $usuario = self::buscarUsuario($pdo, $username);

        // Si el usuario no existe O la contraseña no coincide, falla.
        // password_verify() compara la contraseña escrita con el hash guardado.
        if ($usuario === null || !password_verify($password, $usuario['password_hash'])) {
            return ['success' => false, 'error' => 'Credenciales inválidas'];
        }

        // Login correcto: guardamos los datos del usuario en la sesión.
        // Estos datos se usarán luego en las vistas (p. ej. mostrar su nombre).
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nombre'] = trim($usuario['nombre'] . ' ' . $usuario['apellido']);
        $_SESSION['usuario_rol'] = $usuario['rol'];
        $_SESSION['usuario_username'] = $username;

        return ['success' => true];
    }

    /**
     * Registra un nuevo paciente en el sistema.
     *
     * @param array $datos Datos del formulario de registro ($_POST).
     * @return array ['success' => true] si se creó, o
     *               ['success' => false, 'error' => 'mensaje'] si hubo error.
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

        // Validaciones del lado del servidor. Cada una devuelve un mensaje de
        // error si el dato no cumple el formato esperado.
        if (mb_strlen($nombre) < 2) return ['success' => false, 'error' => 'Ingrese un nombre válido'];
        if (mb_strlen($apellido) < 2) return ['success' => false, 'error' => 'Ingrese un apellido válido'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Ingrese un email válido'];
        if (!preg_match('/^\d{8}$/', $documento)) return ['success' => false, 'error' => 'La cédula debe tener 8 dígitos'];
        if (mb_strlen($username) < 3) return ['success' => false, 'error' => 'El usuario debe tener al menos 3 caracteres'];
        if (strlen($password) < 8) return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        if ($password !== $password2) return ['success' => false, 'error' => 'Las contraseñas no coinciden'];
        if ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) return ['success' => false, 'error' => 'El teléfono debe tener 8 o 9 dígitos'];

        $pdo = db_connect();

        // Evita nombres de usuario duplicados (misma búsqueda que en el login).
        if (self::buscarUsuario($pdo, $username) !== null) {
            return ['success' => false, 'error' => 'El nombre de usuario ya está registrado'];
        }

        // Evita correos electrónicos duplicados en la tabla usuario.
        $emailExiste = $pdo->prepare("SELECT id FROM usuario WHERE email = ?");
        $emailExiste->execute([$email]);
        if ($emailExiste->fetch()) {
            return ['success' => false, 'error' => 'El email ya está registrado'];
        }

        // Cifra la contraseña. NUNCA se guarda la contraseña en texto plano.
        // 'cost' => 12 es el factor de trabajo de bcrypt (más alto = más seguro).
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // Genera un token aleatorio de acceso para el paciente (útil para el QR).
        $token = bin2hex(random_bytes(16));

        try {
            // Transacción: si falla un INSERT, no se queda a medias la operación.
            $pdo->beginTransaction();

            // 1) Crea la fila base en "usuario" con el tipo 'paciente'.
            $stmt = $pdo->prepare("
                INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                VALUES ('paciente', ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $apellido, $email, $documento]);
            // lastInsertId() nos da el id recién creado.
            $id = (int) $pdo->lastInsertId();

            // 2) Crea la fila de credenciales en "paciente" con el MISMO id
            //    (relación 1 a 1 entre usuario y paciente).
            $stmt = $pdo->prepare("
                INSERT INTO paciente (id, token_acceso, username, password_hash, telefono, activo)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$id, $token, $username, $hash, $telefono]);

            // Confirma ambos INSERT.
            $pdo->commit();

            // Registro exitoso: iniciamos sesión automáticamente con el paciente.
            $_SESSION['usuario_id'] = $id;
            $_SESSION['usuario_nombre'] = $nombre . ' ' . $apellido;
            $_SESSION['usuario_rol'] = 'paciente';
            $_SESSION['usuario_username'] = $username;

            return ['success' => true];
        } catch (PDOException $e) {
            // Si algo falló (p. ej. un duplicado), deshace todo y avisa.
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Error al registrar. Verificá que los datos no estén duplicados.'];
        }
    }

    /**
     * Cierra la sesión del usuario actual de forma segura.
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
     * Busca al usuario por su nombre de usuario en funcionario o paciente
     * (usuario.tipo indica dónde viven sus credenciales).
     *
     * @param PDO    $pdo      Conexión activa a la base de datos.
     * @param string $username Nombre de usuario a buscar.
     * @return array|null Los datos del usuario, o null si no existe / está inactivo.
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

        // Si no hay fila, o el usuario está desactivado, no se considera válido.
        if ($row === false || !$row['activo']) return null;

        // Normaliza el rol: para funcionarios se usa su rol real (admin, etc.);
        // para pacientes, el rol es siempre 'paciente'.
        $row['rol'] = $row['tipo'] === 'funcionario' ? ($row['rol'] ?? 'funcionario') : 'paciente';
        return $row;
    }
}
