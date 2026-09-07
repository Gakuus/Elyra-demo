<?php

declare(strict_types=1);

/**
 * UsuarioEdicionController: edición y estado de las personas del Hospital de
 * Clínicas. Viven acá, separadas del directorio (UsuarioController) para que
 * cada archivo tenga un único tema: el formulario de edición, el guardado y
 * el activar/desactivar. La capa compartida (JSON y permiso de gestión) está
 * en el trait UsuarioData; el HTML lo arman las vistas, no este archivo.
 */
final class UsuarioEdicionController
{
    use UsuarioData;

    /**
     * Formulario de edición (GET a /usuarios/editar?id=N). Precarga los
     * datos actuales de la persona. El teléfono y el login viven en la tabla
     * de credenciales, así que según el tipo se lee de funcionario o de
     * paciente.
     */
    public static function formularioEditar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Si el id no existe (borrado o mal escrito), volvemos al listado
        // en vez de mostrar un formulario roto.
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

    /**
     * Guarda los cambios de una persona (POST a /usuarios/editar).
     * Actualiza los datos comunes en "usuario" (nombre, apellido, email,
     * cédula) y el teléfono en la tabla de credenciales que le toque según
     * el tipo. Todo va en una transacción: si algo choca (por ejemplo, ya
     * existe otra persona con esa misma cédula), se deshace y se informa.
     */
    public static function editar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        // Sin id no hay nada que editar; vuelve al directorio.
        if ($id <= 0) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $apellido = trim((string) ($_POST['apellido'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $cedula = trim((string) ($_POST['documento'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));

        // Mismas reglas que al registrar: nombre/apellido mínimos, email y
        // cédula con formato, teléfono de 8-9 dígitos (el celular uruguayo
        // lleva el 9 adelante).
        $error = null;
        if (mb_strlen($nombre) < 2) $error = 'Ingrese un nombre válido.';
        elseif (mb_strlen($apellido) < 2) $error = 'Ingrese un apellido válido.';
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Ingrese un email válido.';
        elseif ($cedula !== '' && !preg_match('/^[\d.\- ]{6,20}$/', $cedula)) $error = 'La cédula no es válida.';
        elseif ($telefono !== '' && !preg_match('/^\d{8,9}$/', $telefono)) $error = 'El teléfono debe tener 8 o 9 dígitos.';

        // Necesitamos saber el tipo para decidir dónde cae el teléfono.
        $stmt = $pdo->prepare('SELECT tipo FROM usuario WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $tipoRow = $stmt->fetch();
        if (!$tipoRow) $error = 'El usuario no existe.';
        $tipo = $tipoRow['tipo'] ?? 'paciente';

        // Si alguna validación falló, redibuja el formulario con el error
        // y los valores que el usuario ya había escrito (para no perderlos).
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
            // Todo o nada: si el UPDATE de la cédula choca con otra persona,
            // la transacción se deshace y no queda el teléfono a medio guardar.
            $pdo->beginTransaction();

            // Primero los datos comunes de identidad, que viven en "usuario".
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

            // Y el teléfono, en la tabla de credenciales del tipo.
            if ($tipo === 'funcionario') {
                $stmt = $pdo->prepare('UPDATE funcionario SET telefono = :t WHERE id = :id');
            } else {
                $stmt = $pdo->prepare('UPDATE paciente SET telefono = :t WHERE id = :id');
            }
            $stmt->execute(['t' => $telefono !== '' ? $telefono : null, 'id' => $id]);

            $pdo->commit();
        } catch (PDOException $e) {
            // Un duplicado de email o cédula lanza un error 23000.
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

        // Todo bien → vuelve a la ficha.
        header('Location: ' . base_path() . '/usuarios/ver?id=' . $id . '&editado=1');
        exit;
    }

    /**
     * Desactiva o reactiva a una persona (POST a /usuarios/estado). Es un
     * borrado lógico: se cambia el flag "activo" en la tabla de credenciales
     * que le toque (funcionario o paciente) pero la fila y sus documentos se
     * conservan. Al desactivar, la persona ya no puede entrar al panel y su
     * QR deja de entregar documentos. Devuelve JSON para que el JS actualice
     * la fila sin recargar.
     */
    public static function estado(): void
    {
        requerir_login();

        $id = (int) ($_POST['id'] ?? 0);
        $activo = (($_POST['activo'] ?? '') === '1');

        if ($id > 0) {
            $pdo = db_connect();

            // Determina en qué tabla vive el flag "activo".
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