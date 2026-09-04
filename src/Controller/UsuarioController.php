<?php

declare(strict_types=1);

/**
 * UsuarioController: personas del Hospital de Clínicas.
 *
 * Acá se junta todo el personal del sistema: los funcionarios (admin,
 * conductores de ambulancia, etc.) y los pacientes. Cada uno guarda sus
 * credenciales en su propia tabla (funcionario / paciente), pero la ficha
 * que vemos acá une ambas para buscar por cédula, ver los documentos que
 * tiene el paciente, editar sus datos y desactivar la cuenta (borrado
 * lógico: la fila nunca se borra).
 */
final class UsuarioController
{
    /**
     * Enrutador del módulo de usuarios. index.php manda acá cualquier ruta
     * que arranque con /usuarios y este método decide qué hacer según la
     * ruta y el método HTTP.
     *
     * $path:   ruta (ej: '/usuarios/ver').
     * $method: método HTTP en mayúsculas ('GET' o 'POST').
     */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/usuarios' && $method === 'GET' => self::listar(),
            $path === '/usuarios/buscar' && $method === 'GET' => self::buscarAjax(),
            $path === '/usuarios/ver' && $method === 'GET' => self::ver(),
            $path === '/usuarios/editar' && $method === 'GET' => self::formularioEditar(),
            $path === '/usuarios/editar' && $method === 'POST' => self::editar(),
            $path === '/usuarios/estado' && $method === 'POST' => self::estado(),
            $path === '/usuarios/codigos' && $method === 'GET' => self::codigos(),
            $path === '/usuarios/codigos' && $method === 'POST' => self::generarCodigo(),
            default => pagina_404(),
        };
    }

    /**
     * Página del directorio de personas (GET a /usuarios).
     * Muestra ÚNICAMENTE el buscador y un contenedor de resultados vacío.
     * NO lista todos los usuarios: los resultados se cargan en vivo a medida
     * que el usuario escribe (ver usuarios.js → buscarAjax).
     */
    public static function listar(): void
    {
        requerir_login();

        // Renderiza la vista con el buscador vacío; los resultados llegan
        // después por AJAX al endpoint /usuarios/buscar. Solo admin/superadmin
        // ven el acceso directo a la gestión de códigos de funcionario.
        $bloqueCodigos = self::esGestion()
            ? '<a href="usuarios/codigos" class="btn btn-outline-secondary btn-sm">'
                . '<i class="bi bi-person-plus me-1"></i> Códigos de funcionario</a>'
            : '';

        render_dashboard('usuarios', 'Usuarios', 'usuarios', ['bloque_codigos' => $bloqueCodigos]);
    }

    /**
     * Endpoint AJAX de búsqueda en vivo (GET a /usuarios/buscar?q=TEXTO).
     * Devuelve JSON con las filas HTML de la tabla y el contador de
     * resultados. Lo consume la función buscar() de usuarios.js.
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

        // Arma las filas de la tabla a partir de los resultados.
        $filas = '';
        foreach ($personas as $p) {
            $filas .= self::filaPersona($p);
        }

        $html = $filas === ''
            ? '<div class="text-center text-muted p-3 mensaje-vacio">'
                . '<i class="bi bi-search d-block mb-2 icono-vacio"></i>'
                . 'No se encontraron personas con ese criterio.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>Cédula</th><th>Nombre</th><th>Categoría</th><th>Rol</th>'
                . '<th>Estado</th><th class="th-acciones-min">Acciones</th></tr></thead><tbody>'
                . $filas . '</tbody></table></div>';

        self::respondeJson([
            'ok' => true,
            'html' => $html,
            'total' => count($personas),
        ]);
    }

    /**
     * Consulta las personas que coinciden con un criterio de búsqueda
     * (cédula exacta o texto parcial en nombre/apellido) y un filtro de
     * estado. Es la lógica compartida entre la vista y el AJAX.
     *
     * @param string $q      Texto a buscar ('' = sin filtro de texto).
     * @param string $estado 'todos' | 'activos' | 'inactivos'.
     * @return array Lista de filas de personas (usuario + funcionario/paciente).
     */
    private static function buscarPersonas(string $q, string $estado): array
    {
        $pdo = db_connect();

        // Una persona es un funcionario O un paciente; unimos las dos tablas
        // para leer en una sola pasada su rol y si está activo. La coincidencia
        // es por id (la relación 1 a 1 que se crea en el alta de Auth).
        $sql = "SELECT u.id, u.tipo, u.nombre, u.apellido, u.email, u.documento_identidad,
                       f.licencia, f.telefono AS telefono_func, f.username AS username_func, f.rol,
                       p.token_acceso, p.telefono AS telefono_pac, p.username AS username_pac,
                       COALESCE(f.activo, p.activo) AS activo
                FROM usuario u
                LEFT JOIN funcionario f ON f.id = u.id
                LEFT JOIN paciente p ON p.id = u.id";

        $params = [];

        // Búsqueda progreva: la cédula se compara por PREFIJO (no exacta),
        // de modo que al ir escribiendo "1", "11", "111"... van apareciendo
        // las cédulas que empiezan con esos dígitos. Para que funcione también
        // con el formato uruguayo (1.234.567-8) se eliminan puntos y guiones
        // de ambos lados con REPLACE y se compara contra el texto sin esos
        // separadores.
        if ($q !== '') {
            // Texto "normalizado" para la cédula: solo dígitos.
            $qLimpio = preg_replace('/[^\d]/', '', $q);

            $sql .= ' WHERE (';
            // Prefijo sobre la cédula limpia: si escribís "111" y hay una
            // cédula "1.111.111-1", su forma limpia "11111111" empieza con 111.
            $sql .= 'REPLACE(REPLACE(REPLACE(u.documento_identidad, ".", ""), "-", ""), " ", "") LIKE :cedula';
            $params['cedula'] = ($qLimpio !== '' ? $qLimpio : $q) . '%';
            // Y además texto parcial en nombre o apellido.
            $sql .= ' OR u.nombre LIKE :nombre OR u.apellido LIKE :apellido)';
            $params['nombre'] = '%' . $q . '%';
            $params['apellido'] = '%' . $q . '%';
        }

        // Filtra por estado usando el mismo alias COALESCE de arriba.
        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 0';
        }

        // Las últimas dadas de alta primero; top 200 para no volcar todo.
        $sql .= ' ORDER BY u.created_at DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Envía una respuesta JSON y corta la ejecución.
     *
     * @param array $datos Datos a serializar como JSON.
     */
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
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

        $filasDocs = '';
        foreach ($docs as $doc) {
            $filasDocs .= self::filaDocumento($doc);
        }

        $bloqueDocs = $filasDocs === ''
            ? '<div class="text-center text-muted p-3 mensaje-vacio-sm">'
                . '<i class="bi bi-file-earmark-text d-block mb-2 icono-vacio-sm"></i>'
                . 'Esta persona no tiene documentos asociados.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>Título</th><th>Tipo</th><th>Estado</th><th>Fecha</th></tr></thead>'
                . '<tbody>' . $filasDocs . '</tbody></table></div>';

        // Datos legibles para la ficha.
        $categoria = $u['tipo'] === 'funcionario' ? 'Funcionario' : 'Paciente';
        $rol = '';
        if ($u['tipo'] === 'funcionario') {
            $rol = htmlspecialchars(ucfirst((string) $u['rol']));
        }
        $username = $u['tipo'] === 'funcionario' ? $u['username_func'] : $u['username_pac'];
        $telefono = $u['tipo'] === 'funcionario' ? $u['telefono_func'] : $u['telefono_pac'];

        $estadoTexto = $u['activo'] ? 'Activo' : 'Inactivo';
        $claseEstado = $u['activo'] ? 'estado-activo' : 'estado-inactivo';

        // Botón de desactivar/reactivar.
        $btnEstado = $u['activo']
            ? '<button type="button" class="btn btn-outline-danger"'
                . ' onclick="ElyraUsuario.cambiarEstado(' . $id . ', 0, this)">'
                . '<i class="bi bi-slash-circle me-1"></i> Desactivar</button>'
            : '<button type="button" class="btn btn-outline-success"'
                . ' onclick="ElyraUsuario.cambiarEstado(' . $id . ', 1, this)">'
                . '<i class="bi bi-arrow-counterclockwise me-1"></i> Reactivar</button>';

        render_dashboard('usuario_ver', 'Ficha de usuario', 'usuarios', [
            'id' => (string) $id,
            'cedula' => htmlspecialchars((string) ($u['documento_identidad'] ?? '—')),
            'nombre_completo' => htmlspecialchars(trim($u['nombre'] . ' ' . $u['apellido'])),
            'email' => htmlspecialchars((string) ($u['email'] ?? '—')),
            'categoria' => $categoria,
            'rol_html' => $rol !== '' ? '<span class="insignia">' . $rol . '</span>' : '',
            'username' => htmlspecialchars((string) ($username ?? '—')),
            'telefono' => htmlspecialchars((string) ($telefono ?? '—')),
            'licencia' => htmlspecialchars((string) ($u['licencia'] ?? '—')),
            'fecha_registro' => date('d/m/Y', (int) strtotime((string) $u['created_at'])),
            'estado_html' => '<span class="' . $claseEstado . '">' . $estadoTexto . '</span>',
            'bloque_documentos' => $bloqueDocs,
            'btn_estado' => $btnEstado,
        ]);
    }

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
            'mensaje_error' => '',
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
                'mensaje_error' => '<div class="mensaje-error">' . $error . '</div>',
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
                'mensaje_error' => '<div class="mensaje-error">No se pudo guardar. Verificá que la cédula y el email no estén ya registrados.</div>',
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

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Arma la fila <tr> de una persona para el listado. Acá la columna
     * "Categoría" distingue si es personal del hospital (Funcionario) o un
     * paciente, y el rol solo se muestra para funcionarios (los pacientes no
     * tienen rol). Si la persona está inactiva se agrega el badge al lado
     * del nombre.
     *
     * $p: fila de la persona devuelta por la consulta.
     */
    private static function filaPersona(array $p): string
    {
        $id = (int) $p['id'];
        $cedula = htmlspecialchars((string) ($p['documento_identidad'] ?? '—'));
        $nombre = htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido']));
        $categoria = $p['tipo'] === 'funcionario' ? 'Funcionario' : 'Paciente';

        // Rol para funcionarios; para pacientes queda vacío.
        $rol = $p['tipo'] === 'funcionario' ? htmlspecialchars(ucfirst((string) ($p['rol'] ?? ''))) : '—';

        // Estado con su clase y badge extra si está inactivo.
        $activo = (bool) $p['activo'];
        $clase = $activo ? 'estado-activo' : 'estado-inactivo';
        $estadoTexto = $activo ? 'Activo' : 'Inactivo';
        $inactivo = !$activo ? ' <span class="badge bg-secondary ms-1">Inactivo</span>' : '';

        return '<tr data-usuario-id="' . $id . '">'
            . '<td class="fw-semibold">' . $cedula . '</td>'
            . '<td>' . $nombre . $inactivo . '</td>'
            . '<td><span class="insignia">' . $categoria . '</span></td>'
            . '<td class="text-muted small">' . $rol . '</td>'
            . '<td><span class="' . $clase . '">' . $estadoTexto . '</span></td>'
            . '<td><div class="d-flex gap-1">'
            . '<a href="usuarios/ver?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Ver ficha"><i class="bi bi-eye"></i></a>'
            . '<a href="usuarios/editar?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>'
            . '</div></td>'
            . '</tr>';
    }

    /**
     * Construye la fila <tr> de un documento en la ficha del usuario.
     *
     * $doc: fila de documento devuelta por la consulta.
     */
    private static function filaDocumento(array $doc): string
    {
        $titulo = htmlspecialchars((string) $doc['titulo']);
        $tipo = htmlspecialchars((string) ($doc['tipo_nombre'] ?? ''));
        $fecha = date('d/m/Y', (int) strtotime((string) $doc['created_at']));
        $id = (int) $doc['id'];
        $clase = $doc['activo'] ? 'estado-activo' : 'estado-inactivo';
        $estado = $doc['activo'] ? 'Activo' : 'Inactivo';

        return '<tr>'
            . '<td class="fw-semibold">' . $titulo . '</td>'
            . '<td><span class="insignia">' . $tipo . '</span></td>'
            . '<td><span class="' . $clase . '">' . $estado . '</span></td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '</tr>';
    }

    /**
     * True si el usuario logueado es admin o superadmin. Aunque el control de
     * permisos fino (sprint 4) todavía no está desarrollado, generar códigos
     * de funcionario queda reservado a la gestión: un conductor no debería
     * poder crear códigos de admin.
     */
    private static function esGestion(): bool
    {
        return in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true);
    }

    /**
     * Panel de códigos de funcionario (GET a /usuarios/codigos).
     * Muestra el formulario para generar un código nuevo (con el rol que va a
     * otorgar) y el listado de códigos generados con su estado. Solo accesible
     * para admin/superadmin.
     */
    public static function codigos(): void
    {
        requerir_login();
        if (!self::esGestion()) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $pdo = db_connect();
        $stmt = $pdo->query(
            "SELECT c.id, c.codigo, c.rol, c.activo, c.usado, c.creado_en, c.usado_en,
                    f.username AS usado_por_username
             FROM codigo_funcionario c
             LEFT JOIN funcionario f ON f.id = c.usado_por
             ORDER BY c.creado_en DESC
             LIMIT 100"
        );
        $codigos = $stmt->fetchAll();

        // El código que acabamos de generar llega por ?nuevo=CODIGO.
        $nuevo = trim((string) ($_GET['nuevo'] ?? ''));
        $avisoNuevo = $nuevo !== ''
            ? '<div class="mensaje-exito"><strong>Código generado:</strong> '
                . '<span class="codigo-generado">' . htmlspecialchars($nuevo) . '</span>'
                . '<div class="texto-pequeno text-muted mt-1">Entregáselo a la persona. Al registrarse con él, su cuenta se creará como funcionario.</div></div>'
            : '';

        $filas = '';
        foreach ($codigos as $c) {
            $filas .= self::filaCodigo($c);
        }

        $bloqueTabla = $codigos === []
            ? '<div class="text-center text-muted p-3 mensaje-vacio">'
                . '<i class="bi bi-person-plus d-block mb-2 icono-vacio"></i>'
                . 'Todavía no se generaron códigos de funcionario.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>Código</th><th>Rol</th><th>Estado</th><th>Generado</th></tr></thead>'
                . '<tbody>' . $filas . '</tbody></table></div>';

        render_dashboard('usuarios_codigos', 'Códigos de funcionario', 'usuarios', [
            'aviso_nuevo' => $avisoNuevo,
            'bloque_tabla' => $bloqueTabla,
        ]);
    }

    /**
     * Genera un código de funcionario nuevo (POST a /usuarios/codigos).
     * Elige el rol que otorgará, lo guarda como "disponible" y redirige al
     * panel mostrando el código generado para que el admin lo entregue.
     */
    public static function generarCodigo(): void
    {
        requerir_login();
        if (!self::esGestion()) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $rol = (string) ($_POST['rol'] ?? '');
        if (!in_array($rol, ['admin', 'superadmin', 'conductor', 'copiloto'], true)) {
            $rol = 'conductor';
        }

        $pdo = db_connect();
        $creadoPor = (int) ($_SESSION['usuario_id'] ?? 0);

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO codigo_funcionario (codigo, rol, creado_por)
                 VALUES (:codigo, :rol, :creado_por)'
            );
            $stmt->execute([
                'codigo' => self::nuevoCodigo($pdo),
                'rol' => $rol,
                'creado_por' => $creadoPor !== 0 ? $creadoPor : null,
            ]);

            // Recupera el código guardado para mostrarlo en el panel.
            $codigo = (string) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT codigo FROM codigo_funcionario WHERE id = :id');
            $stmt->execute(['id' => $codigo]);
            $codigo = (string) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $codigo = '';
        }

        header('Location: ' . base_path() . '/usuarios/codigos' . ($codigo !== '' ? '?nuevo=' . urlencode($codigo) : ''));
        exit;
    }

    /**
     * Genera un código único "ELY-XXXXXXXXXX" que aún no exista en la tabla.
     * Como la columna codigo es UNIQUE, reintenta si por azar choca (muy raro).
     */
    private static function nuevoCodigo(PDO $pdo): string
    {
        do {
            $codigo = 'ELY-' . strtoupper(bin2hex(random_bytes(5)));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM codigo_funcionario WHERE codigo = :codigo');
            $stmt->execute(['codigo' => $codigo]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $codigo;
    }

    /**
     * Construye la fila <tr> de un código de funcionario en el panel.
     *
     * $c: fila devuelta por el SELECT de codigos().
     */
    private static function filaCodigo(array $c): string
    {
        $codigo = htmlspecialchars((string) $c['codigo']);
        $rol = htmlspecialchars(ucfirst((string) $c['rol']));
        $fecha = date('d/m/Y H:i', (int) strtotime((string) $c['creado_en']));

        if ($c['usado']) {
            $idF = $c['usado_por_username'] ?? '—';
            $estado = '<span class="estado-inactivo">Usado por ' . htmlspecialchars($idF) . '</span>';
        } elseif (!$c['activo']) {
            $estado = '<span class="estado-inactivo">Desactivado</span>';
        } else {
            $estado = '<span class="estado-activo">Disponible</span>';
        }

        return '<tr>'
            . '<td class="fw-semibold codigo-generado">' . $codigo . '</td>'
            . '<td><span class="insignia">' . $rol . '</span></td>'
            . '<td>' . $estado . '</td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '</tr>';
    }
}