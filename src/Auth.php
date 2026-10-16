<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Auth: autenticación del sistema. Funcionarios y pacientes comparten la
 * tabla "usuario" y tienen sus credenciales en su propia tabla.
 */
final class Auth
{
    public static function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Parámetros de la cookie de sesión. Van ANTES de session_start(),
            // que es cuando PHP los necesita para emitir la cookie.
            //
            // httponly: el JavaScript de la página no puede leer el id de
            //   sesión. Si algún día aparece un XSS, no puede robar la sesión.
            // samesite=Lax: el navegador NO manda la cookie en peticiones que
            //   vienen de otro sitio. Es una segunda barrera contra CSRF, por
            //   encima del token. Se usa Lax y no Strict a propósito: con
            //   Strict un paciente que entra escaneando el QR desde otra
            //   página llegaría sin cookie y aparecería deslogueado.
            // secure: solo viaja por HTTPS. Se activa si la petición actual es
            //   https, para que en el desarrollo por http siga funcionando.
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') === '443');

            $base = function_exists('base_path') ? base_path() : '';

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => $base !== '' ? $base . '/' : '/',
                'domain'   => '',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }
    }

    /**
     * Renueva el identificador de sesión y la vacía.
     *
     * Se llama en cada cambio de privilegios (login y registro) para evitar el
     * session fixation: si alguien logra fijar el PHPSESSID de la víctima
     * antes de que entre (por ejemplo, inyectándole una cookie desde un sitio
     * propio), sin esto seguiría con la misma sesión ya autenticada.
     */
    private static function renovarSesion(): void
    {
        // true = borra el archivo de sesión anterior, así el id viejo ya no
        // sirve para nada.
        session_regenerate_id(true);
        $_SESSION = [];
    }

    public static function estaAutenticado(): bool
    {
        return !empty($_SESSION['usuario_id']);
    }

    public static function login(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'Ingrese usuario y contraseña'];
        }

        $pdo = db_connect();
        $usuario = self::buscarUsuario($pdo, $username, false);

        // Mensajes distintos: usuario inexistente, cuenta desactivada o
        // contraseña incorrecta.
        if ($usuario === null) {
            return ['success' => false, 'error' => 'El usuario no existe'];
        }
        if (!$usuario['activo']) {
            return ['success' => false, 'error' => 'Tu cuenta está desactivada. Contactá al administrador'];
        }
        if (!password_verify($password, (string) $usuario['password_hash'])) {
            return ['success' => false, 'error' => 'Usuario o contraseña incorrectos'];
        }

        // Login correcto: se renueva la sesión (por si alguien había fijado el
        // id de sesión antes) y se guarda lo que las vistas necesitan.
        self::renovarSesion();
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nombre'] = trim($usuario['nombre'] . ' ' . $usuario['apellido']);
        $_SESSION['usuario_rol'] = $usuario['rol'];
        $_SESSION['usuario_username'] = $username;

        return ['success' => true];
    }

    public static function registrar(array $datos): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $apellido = trim((string) ($datos['apellido'] ?? ''));
        $email = trim((string) ($datos['email'] ?? ''));
        $documento = trim((string) ($datos['documento'] ?? ''));
        $username = trim((string) ($datos['username'] ?? ''));
        $telefono = trim((string) ($datos['telefono'] ?? ''));
        $password = (string) ($datos['password'] ?? '');
        $password2 = (string) ($datos['password2'] ?? '');
        // Si viene un código válido, la cuenta se crea como funcionario.
        $codigoFuncionario = strtoupper(trim((string) ($datos['codigo_funcionario'] ?? '')));

        // Validación de cada campo; el primero que falle corta con su mensaje.
        if (mb_strlen($nombre) < 2) return ['success' => false, 'error' => 'Ingrese un nombre válido'];
        if (mb_strlen($apellido) < 2) return ['success' => false, 'error' => 'Ingrese un apellido válido'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Ingrese un email válido'];
        if (!preg_match('/^\d{8}$/', $documento)) return ['success' => false, 'error' => 'La cédula debe tener 8 dígitos'];
        if (mb_strlen($username) < 3) return ['success' => false, 'error' => 'El usuario debe tener al menos 3 caracteres'];
        if (strlen($password) < 8) return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        if ($password !== $password2) return ['success' => false, 'error' => 'Las contraseñas no coinciden'];
        if ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) return ['success' => false, 'error' => 'El teléfono debe tener 8 o 9 dígitos'];

        $pdo = db_connect();

        // Usuario y email únicos en todo el sistema.
        if (self::buscarUsuario($pdo, $username) !== null) {
            return ['success' => false, 'error' => 'El nombre de usuario ya está registrado'];
        }

        $emailExiste = $pdo->prepare("SELECT id FROM usuario WHERE email = ?");
        $emailExiste->execute([$email]);
        if ($emailExiste->fetch()) {
            return ['success' => false, 'error' => 'El email ya está registrado'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // Token anónimo que se imprime como QR junto al documento del paciente.
        $token = bin2hex(random_bytes(16));

        $esFuncionario = $codigoFuncionario !== '';

        try {
            // Transacción: si algo falla, no queda una cuenta a medias.
            $pdo->beginTransaction();

            if ($esFuncionario) {
                // FOR UPDATE evita que dos registros usen el mismo código.
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

                $stmt = $pdo->prepare("
                    INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                    VALUES ('funcionario', ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $documento]);
                $id = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO funcionario (id, username, password_hash, telefono, activo, rol)
                    VALUES (?, ?, ?, ?, 1, ?)
                ");
                $stmt->execute([$id, $username, $hash, $telefono, $codigo['rol']]);

                // Se consume el código: queda marcado como usado.
                $stmt = $pdo->prepare(
                    "UPDATE codigo_funcionario
                     SET usado = 1, usado_en = NOW(), usado_por = :id
                     WHERE id = :cid"
                );
                $stmt->execute(['id' => $id, 'cid' => $codigo['id']]);

                $rol = $codigo['rol'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                    VALUES ('paciente', ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $apellido, $email, $documento]);
                $id = (int) $pdo->lastInsertId();

                // Las credenciales van en "paciente" con el MISMO id (1 a 1).
                $stmt = $pdo->prepare("
                    INSERT INTO paciente (id, token_acceso, username, password_hash, telefono, activo)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$id, $token, $username, $hash, $telefono]);

                $rol = 'paciente';
            }

            $pdo->commit();

            // Entra directo: no tiene que volver a escribir las credenciales.
            // Se renueva la sesión igual que en el login.
            self::renovarSesion();
            $_SESSION['usuario_id'] = $id;
            $_SESSION['usuario_nombre'] = $nombre . ' ' . $apellido;
            $_SESSION['usuario_rol'] = $rol;
            $_SESSION['usuario_username'] = $username;

            return ['success' => true];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Error al registrar. Verificá que los datos no estén duplicados.'];
        }
    }

    /**
     * Genera un token de restablecimiento para el email indicado. Devuelve los
     * datos necesarios para armar el correo, o null si no hay cuenta con ese
     * email (el controlador responde igual para no revelar qué correos existen).
     *
     * El token se guarda como hash SHA-256 (64 caracteres hex, justo el ancho
     * de la columna) en usuario.reset_token, así que aunque alguien lea la base
     * no puede usar el token. Vence a los 60 minutos.
     *
     * @return array{token:string,nombre:string,email:string}|null
     */
    public static function generarTokenReset(string $email): ?array
    {
        $pdo = db_connect();
        $stmt = $pdo->prepare("SELECT id, nombre FROM usuario WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario === false) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $upd = $pdo->prepare(
            "UPDATE usuario
             SET reset_token = :hash,
                 reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE)
             WHERE id = :id"
        );
        $upd->execute(['hash' => $hash, 'id' => $usuario['id']]);

        return [
            'token'  => $token,
            'nombre' => trim((string) $usuario['nombre']),
            'email'  => $email,
        ];
    }

    /**
     * Restablece la contraseña a partir de un token válido y sin vencer.
     * El token es de un solo uso: se limpia siempre, salga bien o mal el cambio.
     *
     * @return array{success: bool, error?: string}
     */
    public static function restablecerPassword(string $token, string $password, string $password2): array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return ['success' => false, 'error' => 'El enlace no es válido o ya venció'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        }
        if ($password !== $password2) {
            return ['success' => false, 'error' => 'Las contraseñas no coinciden'];
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare(
            "SELECT id, tipo
             FROM usuario
             WHERE reset_token = ? AND reset_token_expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $usuario = $stmt->fetch();

        if ($usuario === false) {
            return ['success' => false, 'error' => 'El enlace no es válido o ya venció'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $tabla = $usuario['tipo'] === 'funcionario' ? 'funcionario' : 'paciente';

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("UPDATE {$tabla} SET password_hash = ? WHERE id = ?");
            $upd->execute([$hash, $usuario['id']]);

            $limpiar = $pdo->prepare(
                "UPDATE usuario SET reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?"
            );
            $limpiar->execute([$usuario['id']]);

            $pdo->commit();
            return ['success' => true];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'No se pudo restablecer la contraseña. Intentá de nuevo.'];
        }
    }

    /**
     * Devuelve los datos de perfil de una cuenta por id: identidad (usuario),
     * credenciales/rol/teléfono (funcionario o paciente) y la fecha de alta.
     * Se arma con un LEFT JOIN a las dos tablas, igual que buscarUsuario().
     *
     * @return array<string,mixed>|null
     */
    public static function obtenerPerfil(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.apellido, u.email, u.tipo,
                   u.documento_identidad, u.created_at,
                   COALESCE(f.username, p.username) AS username,
                   COALESCE(f.rol, 'paciente') AS rol,
                   COALESCE(f.licencia, '') AS licencia,
                   COALESCE(f.telefono, p.telefono) AS telefono,
                   COALESCE(f.password_hash, p.password_hash) AS password_hash,
                   COALESCE(f.activo, p.activo) AS activo
            FROM usuario u
            LEFT JOIN funcionario f ON f.id = u.id
            LEFT JOIN paciente p ON p.id = u.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Actualiza los datos editables del propio perfil: nombre, apellido, email
     * y teléfono. La cédula, el usuario y el rol no se cambian desde acá.
     *
     * @return array{success: bool, error?: string}
     */
    public static function actualizarPerfil(
        int $id,
        string $nombre,
        string $apellido,
        string $email,
        string $telefono
    ): array {
        if (mb_strlen($nombre) < 2) {
            return ['success' => false, 'error' => 'Ingresá un nombre válido'];
        }
        if (mb_strlen($apellido) < 2) {
            return ['success' => false, 'error' => 'Ingresá un apellido válido'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Ingresá un correo electrónico válido'];
        }
        if ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) {
            return ['success' => false, 'error' => 'El teléfono debe tener 8 o 9 dígitos'];
        }

        $perfil = self::obtenerPerfil($id);
        if ($perfil === null) {
            return ['success' => false, 'error' => 'No se encontró la cuenta'];
        }

        $pdo = db_connect();

        // El email es único en todo el sistema: se descarta la propia fila.
        $existe = $pdo->prepare("SELECT id FROM usuario WHERE email = ? AND id <> ? LIMIT 1");
        $existe->execute([$email, $id]);
        if ($existe->fetch()) {
            return ['success' => false, 'error' => 'Ese correo ya está registrado por otra cuenta'];
        }

        $tabla = $perfil['tipo'] === 'funcionario' ? 'funcionario' : 'paciente';

        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("UPDATE usuario SET nombre = ?, apellido = ?, email = ? WHERE id = ?");
            $upd->execute([$nombre, $apellido, $email, $id]);

            $updC = $pdo->prepare("UPDATE {$tabla} SET telefono = ? WHERE id = ?");
            $updC->execute([$telefono, $id]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'No se pudieron guardar los cambios. Intentá de nuevo.'];
        }

        // El header del dashboard muestra el nombre desde la sesión.
        $_SESSION['usuario_nombre'] = trim($nombre . ' ' . $apellido);

        return ['success' => true];
    }

    /**
     * Cambia la contraseña del propio perfil. Exige la contraseña actual: sin
     * eso, alguien con la sesión abierta podría cambiarla sin conocerla.
     *
     * @return array{success: bool, error?: string}
     */
    public static function cambiarPassword(
        int $id,
        string $passwordActual,
        string $passwordNueva,
        string $passwordNueva2
    ): array {
        if (strlen($passwordNueva) < 8) {
            return ['success' => false, 'error' => 'La contraseña nueva debe tener al menos 8 caracteres'];
        }
        if ($passwordNueva !== $passwordNueva2) {
            return ['success' => false, 'error' => 'Las contraseñas nuevas no coinciden'];
        }

        $perfil = self::obtenerPerfil($id);
        if ($perfil === null) {
            return ['success' => false, 'error' => 'No se encontró la cuenta'];
        }
        if (!password_verify($passwordActual, (string) $perfil['password_hash'])) {
            return ['success' => false, 'error' => 'La contraseña actual no es correcta'];
        }

        $hash = password_hash($passwordNueva, PASSWORD_BCRYPT, ['cost' => 12]);
        $tabla = $perfil['tipo'] === 'funcionario' ? 'funcionario' : 'paciente';

        try {
            $pdo = db_connect();
            $upd = $pdo->prepare("UPDATE {$tabla} SET password_hash = ? WHERE id = ?");
            $upd->execute([$hash, $id]);
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'No se pudo cambiar la contraseña. Intentá de nuevo.'];
        }

        return ['success' => true];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Busca a una persona por usuario. Lee de la tabla que corresponde
     * (funcionario o paciente) a través del LEFT JOIN y de COALESCE.
     */
    private static function buscarUsuario(PDO $pdo, string $username, bool $soloActivos = true): ?array
    {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.apellido, u.email, u.tipo,
                   COALESCE(f.username, p.username) AS username,
                   COALESCE(f.password_hash, p.password_hash) AS password_hash,
                   COALESCE(f.rol, 'paciente') AS rol,
                   COALESCE(f.activo, p.activo) AS activo
            FROM usuario u
            LEFT JOIN funcionario f ON f.id = u.id
            LEFT JOIN paciente p ON p.id = u.id
            WHERE f.username = :u1 OR p.username = :u2
            LIMIT 1
        ");
        $stmt->execute(['u1' => $username, 'u2' => $username]);
        $row = $stmt->fetch();

        // Sin fila no existe; con $soloActivos también se descarta el dado de baja.
        if ($row === false) return null;
        if ($soloActivos && !$row['activo']) return null;

        $row['rol'] = $row['tipo'] === 'funcionario' ? ($row['rol'] ?? 'funcionario') : 'paciente';
        return $row;
    }
}