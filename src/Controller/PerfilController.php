<?php

declare(strict_types=1);

require_once __DIR__ . '/../Auth.php';

/**
 * PerfilController: el propio perfil del usuario logueado (/perfil). Se llega
 * desde el menú del header (donde figura el nombre). Permite ver la ficha,
 * editar los datos de contacto y cambiar la contraseña.
 *
 * A diferencia del módulo de usuarios (que administra admin/superadmin), acá
 * cualquier rol entra: funcionario o paciente. Siempre opera sobre el id de la
 * sesión, nunca sobre un id que venga por la URL.
 */
final class PerfilController
{
    /** Etiquetas legibles de los roles, iguales a las del módulo de usuarios. */
    private const ETIQUETAS_ROL = [
        'admin'      => 'Administrador',
        'superadmin' => 'Superadministrador',
        'conductor'  => 'Conductor',
        'copiloto'   => 'Copiloto',
        'paciente'   => 'Paciente',
    ];

    /** Enrutador interno; index.php delega acá las rutas /perfil. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/perfil/datos' && $method === 'POST' => self::actualizarPost(),
            $path === '/perfil/password' && $method === 'POST' => self::cambiarPasswordPost(),
            $path === '/perfil' => self::mostrar(),
            default => pagina_404(),
        };
    }

    /** GET /perfil: ficha + formularios de edición y cambio de contraseña. */
    public static function mostrar(): void
    {
        requerir_login();

        $perfil = Auth::obtenerPerfil((int) $_SESSION['usuario_id']);
        if ($perfil === null) {
            // La cuenta de la sesión ya no existe: se cierra la sesión.
            Auth::logout();
            header('Location: ' . base_path() . '/login');
            exit;
        }

        self::render($perfil, []);
    }

    /** POST /perfil/datos: guarda nombre, apellido, email y teléfono. */
    public static function actualizarPost(): void
    {
        requerir_login();

        $id = (int) $_SESSION['usuario_id'];
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $apellido = trim((string) ($_POST['apellido'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $telefono = trim((string) ($_POST['telefono'] ?? ''));

        $resultado = Auth::actualizarPerfil($id, $nombre, $apellido, $email, $telefono);

        $perfil = Auth::obtenerPerfil($id);
        if ($perfil === null) {
            pagina_404();
            return;
        }

        if ($resultado['success']) {
            log_seguridad('perfil_actualizado');
            self::render($perfil, [
                'hay_exito' => ['1'],
                'exito'     => 'Tus datos se guardaron correctamente.',
            ]);
            return;
        }

        // Se conserva lo que escribió para que no tenga que tipearlo de nuevo.
        $perfil['nombre'] = $nombre;
        $perfil['apellido'] = $apellido;
        $perfil['email'] = $email;
        $perfil['telefono'] = $telefono;

        self::render($perfil, [
            'hay_error' => ['1'],
            'error'     => (string) ($resultado['error'] ?? 'No se pudieron guardar los cambios.'),
        ]);
    }

    /** POST /perfil/password: cambia la contraseña pidiendo la actual. */
    public static function cambiarPasswordPost(): void
    {
        requerir_login();

        $id = (int) $_SESSION['usuario_id'];
        $resultado = Auth::cambiarPassword(
            $id,
            (string) ($_POST['password_actual'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password2'] ?? '')
        );

        $perfil = Auth::obtenerPerfil($id);
        if ($perfil === null) {
            pagina_404();
            return;
        }

        if ($resultado['success']) {
            log_seguridad('password_cambiada');
            self::render($perfil, [
                'hay_exito' => ['1'],
                'exito'     => 'Tu contraseña se cambió correctamente.',
            ]);
            return;
        }

        self::render($perfil, [
            'hay_error' => ['1'],
            'error'     => (string) ($resultado['error'] ?? 'No se pudo cambiar la contraseña.'),
        ]);
    }

    /**
     * Arma los datos de la vista y la pinta dentro del layout. Los datos de
     * identidad se muestran; nombre/apellido/email/teléfono van a los inputs.
     *
     * @param array<string,mixed> $perfil
     * @param array<string,mixed> $estado mensajes de éxito/error
     */
    private static function render(array $perfil, array $estado): void
    {
        $rol = (string) ($perfil['rol'] ?? 'paciente');

        render_dashboard('perfil', 'Mi perfil', 'perfil', array_merge([
            'nombre'         => (string) ($perfil['nombre'] ?? ''),
            'apellido'       => (string) ($perfil['apellido'] ?? ''),
            'email'          => (string) ($perfil['email'] ?? ''),
            'telefono'       => (string) ($perfil['telefono'] ?? ''),
            'cedula'         => (string) ($perfil['documento_identidad'] ?? '') !== '' ? (string) $perfil['documento_identidad'] : '—',
            'username'       => (string) ($perfil['username'] ?? '') !== '' ? (string) $perfil['username'] : '—',
            'rol'            => self::ETIQUETAS_ROL[$rol] ?? ucfirst($rol),
            'licencia'       => (string) ($perfil['licencia'] ?? '') !== '' ? (string) $perfil['licencia'] : '—',
            'es_funcionario' => ($perfil['tipo'] ?? '') === 'funcionario' ? ['1'] : [],
            'fecha_registro' => !empty($perfil['created_at'])
                ? date('d/m/Y', (int) strtotime((string) $perfil['created_at']))
                : '—',
        ], $estado));
    }
}
