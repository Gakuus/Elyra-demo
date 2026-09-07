<?php

declare(strict_types=1);

/**
 * UsuarioController: directorio y ficha de las personas del Hospital de
 * Clínicas. Acá se junta todo el personal del sistema: los funcionarios (admin,
 * conductores de ambulancia, etc.) y los pacientes. Cada uno guarda sus
 * credenciales en su propia tabla (funcionario / paciente), pero la ficha que
 * vemos acá une ambas para buscar por cédula, ver los documentos que tiene el
 * paciente, editar sus datos y desactivar la cuenta (borrado lógico: la fila
 * nunca se borra).
 *
 * Cubre el listado con búsqueda en vivo (AJAX) y la ficha. La edición y el
 * activar/desactivar viven en UsuarioEdicionController y la gestión de códigos
 * de funcionario en UsuarioCodigosController; la capa compartida (búsqueda de
 * personas, JSON y permiso de gestión) está en el trait UsuarioData. El HTML
 * de filas y tablas lo arman las vistas, no este archivo.
 */
final class UsuarioController
{
    use UsuarioData;

    /**
     * Enrutador del módulo de usuarios. index.php manda acá cualquier ruta
     * que arranque con /usuarios y este método decide qué hacer según la
     * ruta y el método HTTP, delegando en los controladores hermanos.
     */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/usuarios' && $method === 'GET' => self::listar(),
            $path === '/usuarios/buscar' && $method === 'GET' => self::buscarAjax(),
            $path === '/usuarios/ver' && $method === 'GET' => self::ver(),
            $path === '/usuarios/editar' && $method === 'GET' => UsuarioEdicionController::formularioEditar(),
            $path === '/usuarios/editar' && $method === 'POST' => UsuarioEdicionController::editar(),
            $path === '/usuarios/estado' && $method === 'POST' => UsuarioEdicionController::estado(),
            $path === '/usuarios/codigos' && $method === 'GET' => UsuarioCodigosController::codigos(),
            $path === '/usuarios/codigos' && $method === 'POST' => UsuarioCodigosController::generarCodigo(),
            default => pagina_404(),
        };
    }

    /**
     * Página del directorio de personas (GET a /usuarios).
     * Muestra ÚNICAMENTE el buscador y un contenedor de resultados vacío.
     * NO lista todos los usuarios: los resultados se cargan en vivo a medida
     * que el usuario escribe (ver usuarios.js → buscarAjax). Solo admin/superadmin
     * ven el acceso directo a la gestión de códigos de funcionario.
     */
    public static function listar(): void
    {
        requerir_login();

        render_dashboard('usuarios', 'Usuarios', 'usuarios', [
            'es_gestion' => self::esGestion() ? ['1'] : [],
        ]);
    }

    /**
     * Endpoint AJAX de búsqueda en vivo (GET a /usuarios/buscar?q=TEXTO).
     * Devuelve JSON con el HTML del fragmento de resultados ({{#personas}}
     * en views/dashboard/fragmentos/) y el contador. Lo consume la función
     * buscar() de usuarios.js.
     */
    public static function buscarAjax(): void
    {
        requerir_login();

        // Lee el término escrito y el filtro de estado.
        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = in_array($_GET['estado'] ?? '', ['activos', 'inactivos'], true)
            ? (string) $_GET['estado']
            : 'todos';

        // Sin texto y sin filtro no hacemos nada: contamos 0 resultados.
        if ($q === '' && $estado === 'todos') {
            self::respondeJson([
                'ok' => true,
                'html' => '',
                'total' => 0,
            ]);
            return;
        }

        $personas = self::buscarPersonas($q, $estado);

        // Una fila por persona (ya escapada); la vista arma la tabla/badge.
        $filas = [];
        foreach ($personas as $p) {
            $activo = (bool) $p['activo'];
            $filas[] = [
                'id' => (string) (int) $p['id'],
                'cedula' => htmlspecialchars((string) ($p['documento_identidad'] ?? '—')),
                'nombre' => htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido'])),
                'categoria' => $p['tipo'] === 'funcionario' ? 'Funcionario' : 'Paciente',
                'rol' => $p['tipo'] === 'funcionario' ? htmlspecialchars(ucfirst((string) ($p['rol'] ?? ''))) : '—',
                'activa' => $activo ? ['1'] : [],
            ];
        }

        self::respondeJson([
            'ok' => true,
            'html' => render_contenido('usuarios_resultados', [
                'personas' => $filas,
                'hay_personas' => $filas === [] ? [] : ['1'],
            ]),
            'total' => count($personas),
        ]);
    }

    /**
     * Ficha completa de una persona (GET a /usuarios/ver?id=N). Es la vista
     * que usa el administrativo cuando entra desde el listado: junta los
     * datos de identidad de "usuario" con el rol y licencia del funcionario
     * (o el token del QR del paciente) y abajo lista los documentos que esa
     * persona tiene asignados — los que le cargó DocumentoController al darle
     * de alta un PDF clínico.
     */
    public static function ver(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Busca la persona uniendo las tres tablas.
        $stmt = $pdo->prepare(
            "SELECT u.id, u.tipo, u.nombre, u.apellido, u.email, u.documento_identidad, u.created_at,
                    f.licencia, f.telefono AS telefono_func, f.username AS username_func, f.rol,
                    p.token_acceso, p.telefono AS telefono_pac, p.username AS username_pac,
                    COALESCE(f.activo, p.activo) AS activo
             FROM usuario u
             LEFT JOIN funcionario f ON f.id = u.id
             LEFT JOIN paciente p ON p.id = u.id
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $u = $stmt->fetch();

        if (!$u) {
            pagina_404();
            return;
        }

        // Solo los documentos que esta persona tiene asignados. Los generales
        // del hospital (paciente_id IS NULL, los que carga el panel) son de
        // todos y no aparecen en la ficha individual.
        $stmtDocs = $pdo->prepare(
            "SELECT d.id, d.titulo, d.activo, d.archivo_nombre, d.created_at, t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.paciente_id = :pid
             ORDER BY d.created_at DESC"
        );
        $stmtDocs->execute(['pid' => $id]);
        $docs = $stmtDocs->fetchAll();

        // Una fila por documento; la vista arma {{#documentos}} y el badge.
        $filasDocs = [];
        foreach ($docs as $doc) {
            $filasDocs[] = [
                'titulo' => htmlspecialchars((string) $doc['titulo']),
                'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
                'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
                'activo' => (bool) $doc['activo'] ? ['1'] : [],
            ];
        }

        // Datos legibles para la ficha.
        $categoria = $u['tipo'] === 'funcionario' ? 'Funcionario' : 'Paciente';
        $rol = $u['tipo'] === 'funcionario' ? htmlspecialchars(ucfirst((string) $u['rol'])) : '';
        $username = $u['tipo'] === 'funcionario' ? $u['username_func'] : $u['username_pac'];
        $telefono = $u['tipo'] === 'funcionario' ? $u['telefono_func'] : $u['telefono_pac'];
        $activo = (bool) $u['activo'];

        render_dashboard('usuario_ver', 'Ficha de usuario', 'usuarios', [
            'id' => (string) $id,
            'cedula' => htmlspecialchars((string) ($u['documento_identidad'] ?? '—')),
            'nombre_completo' => htmlspecialchars(trim($u['nombre'] . ' ' . $u['apellido'])),
            'email' => htmlspecialchars((string) ($u['email'] ?? '—')),
            'categoria' => $categoria,
            'tiene_rol' => $rol !== '' ? ['1'] : [],
            'rol' => $rol,
            'username' => htmlspecialchars((string) ($username ?? '—')),
            'telefono' => htmlspecialchars((string) ($telefono ?? '—')),
            'licencia' => htmlspecialchars((string) ($u['licencia'] ?? '—')),
            'fecha_registro' => date('d/m/Y', (int) strtotime((string) $u['created_at'])),
            'activo' => $activo ? ['1'] : [],
            'documentos' => $filasDocs,
            'hay_documentos' => $filasDocs === [] ? [] : ['1'],
        ]);
    }
}