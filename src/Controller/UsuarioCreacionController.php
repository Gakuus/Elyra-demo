<?php

declare(strict_types=1);

/**
 * UsuarioCreacionController: alta de personas desde el panel de gestión.
 *
 * A diferencia del registro público (Auth::registrar), acá un admin/superadmin
 * elige si la cuenta es de funcionario (con rol) o de paciente, y el sistema
 * genera la contraseña temporal en lugar de que la escriba la persona. Las
 * credenciales se envían por correo; si el envío falla, se muestran una sola
 * vez en pantalla para que el administrador pueda compartirlas.
 */
final class UsuarioCreacionController
{
    /** Roles válidos de funcionario, con su etiqueta legible. */
    private const ROLES = [
        'admin' => 'Administrador',
        'superadmin' => 'Superadministrador',
        'conductor' => 'Conductor',
        'copiloto' => 'Copiloto',
    ];

    /** GET /usuarios/crear: formulario vacío. */
    public static function formularioCrear(): void
    {
        requerir_gestion();
        self::renderFormulario();
    }

    /** POST /usuarios/crear: valida, crea la cuenta y envía las credenciales. */
    public static function crear(): void
    {
        requerir_gestion();

        $pdo = db_connect();

        $tipo = trim((string) ($_POST['tipo'] ?? ''));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $apellido = trim((string) ($_POST['apellido'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $documento = trim((string) ($_POST['documento'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $rol = trim((string) ($_POST['rol'] ?? 'conductor'));

        // La cédula se guarda normalizada (solo dígitos) para que no entren
        // duplicados del estilo "1.234.567-8" y "12345678".
        $documentoLimpio = preg_replace('/\D/', '', $documento);

        $valores = [
            'valor_tipo' => $tipo,
            'valor_nombre' => $nombre,
            'valor_apellido' => $apellido,
            'valor_email' => $email,
            'valor_cedula' => $documento,
            'valor_telefono' => $telefono,
            'valor_username' => $username,
            'valor_rol' => $rol,
        ];

        // ------------------------------------------------------------------
        // Validación
        // ------------------------------------------------------------------
        $error = null;
        if ($tipo !== 'funcionario' && $tipo !== 'paciente') {
            $error = 'Elegí el tipo de cuenta.';
        } elseif (mb_strlen($nombre) < 2) {
            $error = 'Ingrese un nombre válido.';
        } elseif (mb_strlen($apellido) < 2) {
            $error = 'Ingrese un apellido válido.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingrese un email válido (se envían ahí las credenciales).';
        } elseif (!preg_match('/^\d{8}$/', (string) $documentoLimpio)) {
            $error = 'La cédula debe tener 8 dígitos.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $error = 'El usuario debe tener entre 3 y 50 caracteres (letras, números, punto, guion o guion bajo).';
        } elseif ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) {
            $error = 'El teléfono debe tener 8 o 9 dígitos.';
        } elseif ($tipo === 'funcionario' && !array_key_exists($rol, self::ROLES)) {
            $error = 'Elegí un rol válido.';
        }

        if ($error === null) {
            $error = self::validarUnicos($pdo, $username, $email, (string) $documentoLimpio);
        }

        if ($error !== null) {
            self::renderFormulario(array_merge($valores, [
                'hay_error' => ['1'],
                'error' => $error,
            ]));
            return;
        }

        // ------------------------------------------------------------------
        // Alta (transacción: usuario + funcionario/paciente)
        // ------------------------------------------------------------------
        $password = self::generarPassword();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // Token anónimo del paciente (se usa para su QR/documento).
        $token = bin2hex(random_bytes(16));

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO usuario (tipo, nombre, apellido, email, documento_identidad)
                 VALUES (:tipo, :nombre, :apellido, :email, :documento)'
            );
            $stmt->execute([
                'tipo' => $tipo,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'email' => $email,
                'documento' => $documentoLimpio,
            ]);
            $id = (int) $pdo->lastInsertId();

            if ($tipo === 'funcionario') {
                $stmt = $pdo->prepare(
                    'INSERT INTO funcionario (id, username, password_hash, telefono, activo, rol)
                     VALUES (:id, :username, :hash, :telefono, 1, :rol)'
                );
                $stmt->execute([
                    'id' => $id,
                    'username' => $username,
                    'hash' => $hash,
                    'telefono' => $telefono !== '' ? $telefono : null,
                    'rol' => $rol,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO paciente (id, token_acceso, username, password_hash, telefono, activo)
                     VALUES (:id, :token, :username, :hash, :telefono, 1)'
                );
                $stmt->execute([
                    'id' => $id,
                    'token' => $token,
                    'username' => $username,
                    'hash' => $hash,
                    'telefono' => $telefono !== '' ? $telefono : null,
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::renderFormulario(array_merge($valores, [
                'hay_error' => ['1'],
                'error' => 'No se pudo crear la cuenta. Verificá que el usuario, la cédula y el email no estén ya registrados.',
            ]));
            return;
        }

        log_seguridad('usuario_creado', ['id' => $id, 'tipo' => $tipo]);

        // Correo con usuario y contraseña temporal.
        $enviado = MailService::enviarCredenciales(
            $email,
            trim($nombre . ' ' . $apellido),
            $username,
            $password
        );

        if ($enviado) {
            header('Location: ' . base_path() . '/usuarios/ver?id=' . $id . '&creado=1');
            exit;
        }

        // Fallback: el correo no salió. Mostrar las credenciales una única vez
        // para que el administrador las comparta a mano.
        self::renderFormulario([
            'hay_credenciales' => ['1'],
            'usuario_generado' => htmlspecialchars($username),
            'clave_generada' => htmlspecialchars($password),
            'id_creado' => (string) $id,
        ]);
    }

    /** Comprueba duplicados de usuario (funcionario+paciente), email y cédula. */
    private static function validarUnicos(PDO $pdo, string $username, string $email, string $documento): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM funcionario WHERE username = :u1
             UNION SELECT 1 FROM paciente WHERE username = :u2
             LIMIT 1"
        );
        $stmt->execute(['u1' => $username, 'u2' => $username]);
        if ($stmt->fetchColumn()) {
            return 'Ese nombre de usuario ya está en uso.';
        }

        $stmt = $pdo->prepare('SELECT 1 FROM usuario WHERE email = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        if ($stmt->fetchColumn()) {
            return 'Ese email ya está registrado.';
        }

        $stmt = $pdo->prepare('SELECT 1 FROM usuario WHERE documento_identidad = :d LIMIT 1');
        $stmt->execute(['d' => $documento]);
        if ($stmt->fetchColumn()) {
            return 'Esa cédula ya está registrada.';
        }

        return null;
    }

    /** Contraseña temporal legible, sin caracteres ambiguos (0/O, 1/l/I). */
    private static function generarPassword(int $largo = 12): string
    {
        $alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alfabeto) - 1;

        $out = '';
        for ($i = 0; $i < $largo; $i++) {
            $out .= $alfabeto[random_int(0, $max)];
        }
        return $out;
    }

    /** Pinta el formulario con valores y mensajes; completa los "selected". */
    private static function renderFormulario(array $datos = []): void
    {
        $datos = array_merge([
            'hay_error' => [],
            'error' => '',
            'hay_credenciales' => [],
            'usuario_generado' => '',
            'clave_generada' => '',
            'id_creado' => '',
            'valor_tipo' => 'funcionario',
            'valor_nombre' => '',
            'valor_apellido' => '',
            'valor_email' => '',
            'valor_cedula' => '',
            'valor_telefono' => '',
            'valor_username' => '',
            'valor_rol' => 'conductor',
        ], $datos);

        $datos['valor_tipo'] = htmlspecialchars((string) $datos['valor_tipo']);
        $datos['valor_nombre'] = htmlspecialchars((string) $datos['valor_nombre']);
        $datos['valor_apellido'] = htmlspecialchars((string) $datos['valor_apellido']);
        $datos['valor_email'] = htmlspecialchars((string) $datos['valor_email']);
        $datos['valor_cedula'] = htmlspecialchars((string) $datos['valor_cedula']);
        $datos['valor_telefono'] = htmlspecialchars((string) $datos['valor_telefono']);
        $datos['valor_username'] = htmlspecialchars((string) $datos['valor_username']);

        $datos['tipo_funcionario'] = $datos['valor_tipo'] === 'funcionario' ? ['1'] : [];
        $datos['tipo_paciente'] = $datos['valor_tipo'] === 'paciente' ? ['1'] : [];

        foreach (array_keys(self::ROLES) as $clave) {
            $datos['rol_' . $clave] = ($datos['valor_rol'] === $clave) ? ['1'] : [];
        }

        render_dashboard('usuario_crear', 'Nuevo usuario', 'usuarios', $datos);
    }
}
