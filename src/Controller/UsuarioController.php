<?php

declare(strict_types=1);

/**
 * UsuarioController: directorio y ficha de personas (funcionarios y
 * pacientes). La edición vive en UsuarioEdicionController y los códigos de
 * funcionario en UsuarioCodigosController; la capa compartida en UsuarioData.
 */
final class UsuarioController
{
    use UsuarioData;

    /** Enrutador del módulo: index.php delega acá las rutas /usuarios. */
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

    /** Directorio: solo buscador y contenedor; los resultados llegan por AJAX. */
    public static function listar(): void
    {
        requerir_gestion();

        render_dashboard('usuarios', 'Usuarios', 'usuarios', [
            'es_gestion' => es_gestion() ? ['1'] : [],
        ]);
    }

    /** Búsqueda en vivo (AJAX): devuelve el HTML del fragmento y el total. */
    public static function buscarAjax(): void
    {
        requerir_gestion();

        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = in_array($_GET['estado'] ?? '', ['activos', 'inactivos'], true)
            ? (string) $_GET['estado']
            : 'todos';

        // Sin texto y sin filtro: no hay nada que buscar.
        if ($q === '' && $estado === 'todos') {
            self::respondeJson([
                'ok' => true,
                'html' => '',
                'total' => 0,
            ]);
            return;
        }

        $personas = self::buscarPersonas($q, $estado);

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

    /** Ficha de una persona: datos de identidad, rol/licencia/token y sus documentos. */
    public static function ver(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Une las tres tablas para traer los datos de la persona.
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

        // Solo los documentos asignados a esta persona (los generales no aparecen).
        $stmtDocs = $pdo->prepare(
            "SELECT d.id, d.titulo, d.activo, d.archivo_nombre, d.created_at, t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.paciente_id = :pid
             ORDER BY d.created_at DESC"
        );
        $stmtDocs->execute(['pid' => $id]);
        $docs = $stmtDocs->fetchAll();

        $filasDocs = [];
        foreach ($docs as $doc) {
            $filasDocs[] = [
                'titulo' => htmlspecialchars((string) $doc['titulo']),
                'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
                'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
                'activo' => (bool) $doc['activo'] ? ['1'] : [],
            ];
        }

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