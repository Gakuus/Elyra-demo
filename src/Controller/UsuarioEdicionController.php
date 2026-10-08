<?php

declare(strict_types=1);

/**
 * UsuarioEdicionController: edición y activar/desactivar de personas.
 * La capa compartida está en el trait UsuarioData.
 */
final class UsuarioEdicionController
{
    use UsuarioData;

    /** Formulario de edición (GET). El teléfono se lee de la tabla según el tipo. */
    public static function formularioEditar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $pdo->prepare(
            "SELECT u.id, u.tipo, u.nombre, u.apellido, u.email, u.documento_identidad,
                    f.telefono AS telefono_func, p.telefono AS telefono_pac
             FROM usuario u
             LEFT JOIN funcionario f ON f.id = u.id
             LEFT JOIN paciente p ON p.id = u.id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $u = $stmt->fetch();

        if (!$u) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $telefono = (string) ($u['tipo'] === 'funcionario' ? $u['telefono_func'] : $u['telefono_pac']);

        render_dashboard('usuario_editar', 'Editar usuario', 'usuarios', [
            'hay_error' => [],
            'id' => (string) $id,
            'valor_nombre' => htmlspecialchars((string) $u['nombre']),
            'valor_apellido' => htmlspecialchars((string) $u['apellido']),
            'valor_email' => htmlspecialchars((string) ($u['email'] ?? '')),
            'valor_cedula' => htmlspecialchars((string) ($u['documento_identidad'] ?? '')),
            'valor_telefono' => htmlspecialchars($telefono),
        ]);
    }

    /** Guarda datos comunes en "usuario" y teléfono en la tabla del tipo, en transacción. */
    public static function editar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $apellido = trim((string) ($_POST['apellido'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $cedula = trim((string) ($_POST['documento'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));

        $error = null;
        if (mb_strlen($nombre) < 2) $error = 'Ingrese un nombre válido.';
        elseif (mb_strlen($apellido) < 2) $error = 'Ingrese un apellido válido.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Ingrese un email válido.';
        elseif ($cedula !== '' && !preg_match('/^[\d.\- ]{6,20}$/', $cedula)) $error = 'La cédula no es válida.';
        elseif ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) $error = 'El teléfono debe tener 8 o 9 dígitos.';

        // Se necesita el tipo para decidir dónde cae el teléfono.
        $stmt = $pdo->prepare('SELECT tipo FROM usuario WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $tipoRow = $stmt->fetch();
        if (!$tipoRow) $error = 'El usuario no existe.';
        $tipo = $tipoRow['tipo'] ?? 'paciente';

        // Con error, vuelve al formulario conservando lo escrito.
        if ($error !== null) {
            render_dashboard('usuario_editar', 'Editar usuario', 'usuarios', [
                'hay_error' => ['1'],
                'error' => $error,
                'id' => (string) $id,
                'valor_nombre' => htmlspecialchars($nombre),
                'valor_apellido' => htmlspecialchars($apellido),
                'valor_email' => htmlspecialchars($email),
                'valor_cedula' => htmlspecialchars($cedula),
                'valor_telefono' => htmlspecialchars($telefono),
            ]);
            return;
        }

        try {
            // Todo o nada: si la cédula choca con otra persona, se deshace.
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE usuario SET nombre = :nombre, apellido = :apellido, email = :email,
                     documento_identidad = :documento
                 WHERE id = :id'
            );
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'email' => $email !== '' ? $email : null,
                'documento' => $cedula !== '' ? $cedula : null,
                'id' => $id,
            ]);

            if ($tipo === 'funcionario') {
                $stmt = $pdo->prepare('UPDATE funcionario SET telefono = :t WHERE id = :id');
            } else {
                $stmt = $pdo->prepare('UPDATE paciente SET telefono = :t WHERE id = :id');
            }
            $stmt->execute(['t' => $telefono !== '' ? $telefono : null, 'id' => $id]);

            $pdo->commit();
        } catch (PDOException $e) {
            // Duplicado de email o cédula (error 23000).
            $pdo->rollBack();
            render_dashboard('usuario_editar', 'Editar usuario', 'usuarios', [
                'hay_error' => ['1'],
                'error' => 'No se pudo guardar. Verificá que la cédula y el email no estén ya registrados.',
                'id' => (string) $id,
                'valor_nombre' => htmlspecialchars($nombre),
                'valor_apellido' => htmlspecialchars($apellido),
                'valor_email' => htmlspecialchars($email),
                'valor_cedula' => htmlspecialchars($cedula),
                'valor_telefono' => htmlspecialchars($telefono),
            ]);
            return;
        }

        header('Location: ' . base_path() . '/usuarios/ver?id=' . $id . '&editado=1');
        exit;
    }

    /** Baja lógica: cambia el flag activo en la tabla del tipo. Responde JSON. */
    public static function estado(): void
    {
        requerir_gestion();

        $id = (int) ($_POST['id'] ?? 0);
        $activo = (($_POST['activo'] ?? '') === '1');

        if ($id > 0) {
            $pdo = db_connect();

            $stmt = $pdo->prepare('SELECT tipo FROM usuario WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $tipo = $stmt->fetchColumn();

            if ($tipo === 'funcionario') {
                $stmt = $pdo->prepare('UPDATE funcionario SET activo = :activo WHERE id = :id');
            } elseif ($tipo === 'paciente') {
                $stmt = $pdo->prepare('UPDATE paciente SET activo = :activo WHERE id = :id');
            }
            if (isset($stmt)) {
                $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $id]);
            }
        }

        self::respondeJson(['ok' => true]);
    }
}