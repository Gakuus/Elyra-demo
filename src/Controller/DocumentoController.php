<?php

declare(strict_types=1);

/**
 * DocumentoController: documentos clínicos del Hospital de Clínicas.
 * Maneja la subida, listado, detalle y descarga de los PDF (protocolos,
 * informes) que quedan archivados, y las vistas públicas que el paciente
 * abre desde el QR impreso en su papel — esas no piden sesión.
 */
final class DocumentoController
{
    /**
     * Enrutador del módulo de documentos. index.php manda acá cualquier ruta
     * que arranque con /documentos, /publico/doc o /publico/archivo, y este
     * método decide con qué acción responder según la ruta y el método HTTP.
     *
     * $path:   ruta (ej: '/documentos/subir').
     * $method: método HTTP en mayúsculas ('GET' o 'POST').
     */
    public static function dispatch(string $path, string $method): void
    {
        // match(true) funciona como un switch de condiciones:
        // evalúa cada caso y ejecuta el primero que dé verdadero.
        match (true) {
            // Mismo path, distinto método → distintas acciones (GET = mostrar,
            // POST = procesar el formulario).
            $path === '/documentos' && $method === 'GET' => self::listar(),
            $path === '/documentos/subir' && $method === 'POST' => self::subir(),
            $path === '/documentos/subir' && $method === 'GET' => self::formulario(),
            $path === '/documentos/editar' && $method === 'GET' => self::formularioEditar(),
            $path === '/documentos/editar' && $method === 'POST' => self::editar(),
            // Activa/desactiva un documento (borrado lógico: no se elimina
            // la fila ni el archivo, solo deja de mostrarse al público).
            $path === '/documentos/estado' && $method === 'POST' => self::estado(),
            $path === '/documentos/ver' && $method === 'GET' => self::ver(),
            $path === '/documentos/archivo' && $method === 'GET' => self::archivo(),
            $path === '/publico/doc' && $method === 'GET' => self::publicoDoc(),
            $path === '/publico/archivo' && $method === 'GET' => self::publicoArchivo(),
            // Ninguna condición coincidió → página 404.
            default => pagina_404(),
        };
    }

    /**
     * Archivado de documentos generales (GET a /documentos).
     * Muestra la tabla con todos los documentos que no pertenecen a un
     * paciente concreto, y permite filtrar por tipo y por texto en el título.
     */
    public static function listar(): void
    {
        // Guard: solo usuarios autenticados.
        requerir_login();

        $pdo = db_connect();

        // Lee los filtros de la URL (?tipo=2&q=protocolo).
        // tipo: solo si viene un valor válido; si no, null (sin filtro).
        $tipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? (int) $_GET['tipo'] : null;
        // q: el texto de búsqueda, recortado de espacios.
        $q = trim((string) ($_GET['q'] ?? ''));

        // Consulta base: trae documentos "generales" (sin paciente asignado).
        // LEFT JOIN trae el nombre del tipo aunque falte la relación.
        $sql = 'SELECT d.id, d.titulo, d.activo, d.created_at, t.nombre AS tipo_nombre
                FROM documento d
                LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
                WHERE d.paciente_id IS NULL';

        // La consulta se arma por partes según los filtros presentes.
        $params = [];
        if ($tipo !== null) {
            $sql .= ' AND d.tipo_documento_id = :tipo';
            $params['tipo'] = $tipo;
        }
        if ($q !== '') {
            // LIKE con %...% busca el texto en cualquier parte del título.
            $sql .= ' AND d.titulo LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY d.created_at DESC';

        // Consulta preparada: los valores van por separado (seguro contra SQLi).
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        // Convierte cada documento en una fila <tr> de la tabla.
        $filas = '';
        foreach ($docs as $doc) {
            $filas .= self::filaDocumento($doc);
        }

        // Si no hay resultados, mensaje vacío; si hay, arma la tabla completa.
        $contenido = $filas === ''
            ? '<div class="text-center text-muted p-3 mensaje-vacio">'
                . '<i class="bi bi-inbox d-block mb-2 icono-vacio"></i>No hay documentos generales.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th class="th-qr">QR</th><th>Título</th><th>Tipo</th><th>Estado</th><th>Subido</th>'
                . '<th class="th-acciones-sm">Acciones</th></tr></thead><tbody>' . $filas . '</tbody></table></div>';

        // Renderiza la vista con: opciones del selector de tipo, texto de
        // búsqueda conservado y el HTML de la tabla ya armado.
        render_dashboard('documentos', 'Documentos generales', 'documentos', [
            'opciones_tipo' => self::opcionesTipo($tipo, true),
            'q' => htmlspecialchars($q),
            'contenido_documentos' => $contenido,
        ]);
    }

    /**
     * Registra un documento nuevo (POST a /documentos/subir). Valida los
     * datos, guarda el PDF en el disco y anota el documento en MySQL; si algo
     * falla, devuelve al formulario con lo cargado para no perder el trabajo.
     */
    public static function subir(): void
    {
        requerir_login();

        $pdo = db_connect();

        // Lee los campos del formulario enviados por POST.
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $tipoId = (int) ($_POST['tipo'] ?? 0);

        // Variables de estado: $error guarda el primer problema, $subido
        // indica si se completó la subida con éxito.
        $error = null;
        $subido = false;

        // Validaciones en cadena (if/elseif): se detiene en el primer error.
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $error = 'El título debe tener entre 3 y 200 caracteres.';
        } elseif ($tipoId <= 0) {
            $error = 'Seleccioná un tipo de documento.';
        } elseif (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            // UPLOAD_ERR_OK (0) significa que el archivo llegó sin problemas.
            $error = 'Seleccioná un archivo PDF para subir.';
        } else {
            $archivo = $_FILES['archivo'];

            // Detecta el tipo real del archivo (no confía en la extensión).
            $mime = @mime_content_type($archivo['tmp_name']);
            if ($mime !== 'application/pdf') {
                $error = 'El archivo debe ser un PDF válido.';
            } elseif ($archivo['size'] > 10 * 1024 * 1024) {
                // Límite de 10 MB (10 * 1024 * 1024 bytes).
                $error = 'El archivo supera el tamaño máximo de 10 MB.';
            } else {
                // Construye un nombre seguro para el archivo en disco:
                // elimina caracteres raros del nombre original (solo letras,
                // números, guiones y guiones bajos) y le agrega timestamp.
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string) $archivo['name'], PATHINFO_FILENAME));
                $filename = mb_substr((string) $safeName, 0, 80) . '_' . time() . '.pdf';

                // Carpeta donde se guardan los PDF (storage/docs).
                $storageDir = __DIR__ . '/../../storage/docs';
                if (!is_dir($storageDir)) {
                    // La crea si no existe (0775 permisos, recursivo).
                    mkdir($storageDir, 0775, true);
                }
                $destPath = $storageDir . '/' . $filename;

                // move_uploaded_file mueve el archivo temporal al destino final.
                if (!move_uploaded_file($archivo['tmp_name'], $destPath)) {
                    $error = 'Error al guardar el archivo. Verificá los permisos del servidor.';
                } else {
                    // Archivo guardado en disco → registra los datos en MySQL.
                    // Se guarda la RUTA del archivo, no el archivo en sí.
                    $stmt = $pdo->prepare(
                        'INSERT INTO documento (titulo, descripcion, archivo_path, archivo_nombre, tipo_documento_id, subido_por, activo)
                         VALUES (:titulo, :descripcion, :path, :nombre, :tipo, :usuario, 1)'
                    );
                    $stmt->execute([
                        'titulo' => $titulo,
                        'descripcion' => $descripcion !== '' ? $descripcion : null,
                        'path' => $destPath,
                        'nombre' => $filename,
                        'tipo' => $tipoId,
                        'usuario' => (int) ($_SESSION['usuario_id'] ?? 0),
                    ]);
                    $subido = true;
                }
            }
        }

        // Subida exitosa → redirige al listado con aviso ?subido=1.
        if ($subido) {
            header('Location: ' . base_path() . '/documentos?subido=1');
            exit;
        }

        // Hubo error → vuelve a mostrar el formulario con los valores que ya
        // cargó el usuario (para que no los pierda) y el mensaje de error.
        render_dashboard('documentos_subir', 'Subir documento', 'documentos', [
            'mensaje_error' => $error !== null ? '<div class="mensaje-error">' . $error . '</div>' : '',
            'valor_titulo' => htmlspecialchars($titulo),
            'valor_descripcion' => htmlspecialchars($descripcion),
            'opciones_tipo_subir' => self::opcionesTipo($tipoId > 0 ? $tipoId : null),
        ]);
    }

    /**
     * Formulario en blanco para subir (GET a /documentos/subir).
     */
    public static function formulario(): void
    {
        requerir_login();

        // Formulario en blanco: sin error, sin valores previos.
        render_dashboard('documentos_subir', 'Subir documento', 'documentos', [
            'mensaje_error' => '',
            'valor_titulo' => '',
            'valor_descripcion' => '',
            'opciones_tipo_subir' => self::opcionesTipo(),
        ]);
    }

    /**
     * Formulario de edición (GET a /documentos/editar?id=N). Carga los datos
     * actuales del documento para corregirlos. El PDF en sí NO se reemplaza:
     * solo se tocan título, tipo y descripción.
     */
    public static function formularioEditar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Busca el documento general; si no existe vuelve al listado.
        $stmt = $pdo->prepare(
            'SELECT id, titulo, descripcion, tipo_documento_id
             FROM documento
             WHERE id = :id AND paciente_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            header('Location: ' . base_path() . '/documentos');
            exit;
        }

        render_dashboard('documentos_editar', 'Editar documento', 'documentos', [
            'mensaje_error' => '',
            'id' => (string) $id,
            'valor_titulo' => htmlspecialchars((string) $doc['titulo']),
            'valor_descripcion' => htmlspecialchars((string) ($doc['descripcion'] ?? '')),
            'opciones_tipo_editar' => self::opcionesTipo((int) $doc['tipo_documento_id']),
        ]);
    }

    /**
     * Guarda los cambios del formulario de edición (POST a /documentos/editar).
     * Actualiza título, descripción y tipo; el archivo, el QR y el resto de
     * las relaciones quedan como estaban.
     */
    public static function editar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/documentos');
            exit;
        }

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $tipoId = (int) ($_POST['tipo'] ?? 0);

        // Mismas reglas de validación que en la subida.
        $error = null;
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $error = 'El título debe tener entre 3 y 200 caracteres.';
        } elseif ($tipoId <= 0) {
            $error = 'Seleccioná un tipo de documento.';
        } else {
            // Verifica que el tipo exista (evita romper la clave foránea).
            $stmtT = $pdo->prepare('SELECT COUNT(*) FROM tipo_documento WHERE id = :id');
            $stmtT->execute(['id' => $tipoId]);
            if ((int) $stmtT->fetchColumn() === 0) {
                $error = 'El tipo de documento seleccionado no existe.';
            }
        }

        if ($error !== null) {
            // Vuelve al formulario conservando lo escrito por el usuario.
            render_dashboard('documentos_editar', 'Editar documento', 'documentos', [
                'mensaje_error' => '<div class="mensaje-error">' . $error . '</div>',
                'id' => (string) $id,
                'valor_titulo' => htmlspecialchars($titulo),
                'valor_descripcion' => htmlspecialchars($descripcion),
                'opciones_tipo_editar' => self::opcionesTipo($tipoId > 0 ? $tipoId : null),
            ]);
            return;
        }

        // UPDATE acotado: solo metadatos. Nunca toca archivo ni estado.
        $stmt = $pdo->prepare(
            'UPDATE documento
             SET titulo = :titulo, descripcion = :descripcion, tipo_documento_id = :tipo
             WHERE id = :id AND paciente_id IS NULL'
        );
        $stmt->execute([
            'titulo' => $titulo,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'tipo' => $tipoId,
            'id' => $id,
        ]);

        header('Location: ' . base_path() . '/documentos?editado=1');
        exit;
    }

    /**
     * Activa o desactiva un documento (POST a /documentos/estado). Es el
     * "desactivar" del archivo: baja un documento sin borrar el PDF. Uno
     * inactivo deja de abrirse por el QR (404) pero conserva archivo y datos.
     */
    public static function estado(): void
    {
        requerir_login();

        $id = (int) ($_POST['id'] ?? 0);
        $activo = (($_POST['activo'] ?? '') === '1');

        if ($id > 0) {
            $pdo = db_connect();
            $stmt = $pdo->prepare(
                'UPDATE documento SET activo = :activo WHERE id = :id AND paciente_id IS NULL'
            );
            $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $id]);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Detalle de un documento del archivo (GET a /documentos/ver?id=N).
     * Muestra título, tipo, fecha, descripción y el PDF embebido en la página.
     */
    public static function ver(): void
    {
        requerir_login();

        $pdo = db_connect();

        // Lee el id desde la URL y lo fuerza a entero (evita inyección).
        $id = (int) ($_GET['id'] ?? 0);

        // Busca el documento SOLO si es general (paciente_id IS NULL).
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.activo, d.created_at, t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.id = :id AND d.paciente_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Si no existe, 404 y listo.
        if (!$doc) {
            pagina_404();
            return;
        }

        // Renderiza el detalle con los datos del documento.
        // descripcion_bloque: muestra la descripción solo si hay una.
        render_dashboard('documento_ver', 'Documento', 'documentos', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion_bloque' => $doc['descripcion'] !== null && $doc['descripcion'] !== ''
                ? '<p class="text-muted mb-3">' . htmlspecialchars((string) $doc['descripcion']) . '</p>'
                : '',
            'id' => (string) $id,
        ]);
    }

    /**
     * Entrega el PDF pedido desde una sesión interna (GET a /documentos/archivo).
     * Es la ruta que usa el <embed> del detalle y el botón de descarga.
     */
    public static function archivo(): void
    {
        // Guard: requiere sesión iniciada.
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            // requiereAuth=true (solo usuarios logueados) y descarga según ?descargar=1.
            self::servirPdf($pdo, $id, true, !empty($_GET['descargar']));
        } else {
            http_response_code(404);
        }
    }

    /**
     * Página que ve el paciente al escanear el QR (GET a /publico/doc?id=N).
     * No pide sesión: es la entrega de su documento en el servicio. Solo se
     * sirven documentos ACTIVOS y generales, y de paso se le ofrece la
     * encuesta de satisfacción del servicio (la vinculada al documento o, si
     * no tiene, la primera activa disponible).
     */
    public static function publicoDoc(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Misma consulta que ver(), pero exigiendo activo = 1 (seguridad:
        // un documento desactivado no se puede abrir por QR).
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.activo, d.created_at,
                    d.encuesta_id, t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.id = :id AND d.activo = 1 AND d.paciente_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            pagina_404();
            return;
        }

        // Resuelve la encuesta de satisfacción a mostrar:
        // 1) la vinculada al documento (si existe y está activa);
        // 2) si no, la primera encuesta activa;
        // 3) si no hay ninguna, no se muestra el botón.
        $encuestaId = self::resolverEncuestaPublica($pdo, $doc['encuesta_id'] !== null ? (int) $doc['encuesta_id'] : null);

        // Renderiza la vista pública (sin el layout del dashboard).
        render_vista(__DIR__ . '/../../views/publico/documento.html', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion_bloque' => $doc['descripcion'] !== null && $doc['descripcion'] !== ''
                ? '<p class="text-muted mt-2">' . htmlspecialchars((string) $doc['descripcion']) . '</p>'
                : '',
            'id' => (string) $id,
            'bloque_encuesta' => $encuestaId !== null
                ? '<a href="' . base_path() . '/publico/encuesta?id=' . $encuestaId . '" class="btn btn-info">'
                    . '<i class="bi bi-chat-square-text me-1"></i> Encuesta de satisfacción</a>'
                : '',
        ]);
    }

    /**
     * Elige qué encuesta ofrecer en la vista pública del documento: usa la
     * que esté vinculada al documento si sigue activa, y si no cae a la
     * primera encuesta activa. Devuelve null cuando no hay nada que ofrecer.
     */
    private static function resolverEncuestaPublica(PDO $pdo, ?int $vinculadaId): ?int
    {
        if ($vinculadaId !== null && $vinculadaId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM encuesta WHERE id = :id AND activa = 1');
            $stmt->execute(['id' => $vinculadaId]);
            $encontrada = $stmt->fetchColumn();
            if ($encontrada !== false) {
                return (int) $encontrada;
            }
        }

        // Fallback: primera encuesta activa (la "de satisfacción general").
        $stmt = $pdo->query('SELECT id FROM encuesta WHERE activa = 1 ORDER BY id LIMIT 1');
        $primera = $stmt->fetchColumn();
        return $primera !== false ? (int) $primera : null;
    }

    /**
     * Descarga del PDF desde la vista pública (GET a /publico/archivo?id=N).
     * Sin sesión, con las mismas restricciones: solo documentos activos y
     * generales.
     */
    public static function publicoArchivo(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            // requiereAuth=false (no hace falta sesión).
            self::servirPdf($pdo, $id, false, !empty($_GET['descargar']));
        } else {
            http_response_code(404);
        }
    }

    /**
     * Genera las <option> del selector de tipo de documento. Es el mismo
     * HTML reutilizado en el filtro del archivo y en el formulario, para no
     * duplicar el armado.
     *
     * $seleccionado: id del tipo que debe quedar marcado (o null).
     * $conTodos:     si true, incluye la opción "Todos los tipos" (para el filtro).
     */
    private static function opcionesTipo(?int $seleccionado = null, bool $conTodos = false): string
    {
        $pdo = db_connect();

        // Primera opción según el uso (filtro vs formulario).
        $opciones = $conTodos
            ? '<option value="">Todos los tipos</option>'
            : '<option value="">Seleccionar tipo...</option>';

        // Trae todos los tipos ordenados por nombre.
        $stmt = $pdo->query('SELECT id, nombre FROM tipo_documento ORDER BY nombre');
        foreach ($stmt->fetchAll() as $tipo) {
            // Agrega el atributo 'selected' si este tipo es el elegido.
            $sel = $seleccionado !== null && (int) $tipo['id'] === $seleccionado ? ' selected' : '';
            $opciones .= '<option value="' . (int) $tipo['id'] . '"' . $sel . '>'
                . htmlspecialchars((string) $tipo['nombre']) . '</option>';
        }
        return $opciones;
    }

    /**
     * Arma la fila <tr> de un documento para la tabla del archivo: botón de
     * QR, título (con aviso si está inactivo), tipo, estado, fecha y la
     * acción de ver el detalle.
     *
     * $doc: fila de documento devuelta por la consulta.
     */
    private static function filaDocumento(array $doc): string
    {
        // Escapa todo texto proveniente de la base (XSS-safe).
        $titulo = htmlspecialchars((string) $doc['titulo']);
        $tipo = htmlspecialchars((string) ($doc['tipo_nombre'] ?? ''));
        $fecha = date('d/m/Y', (int) strtotime((string) $doc['created_at']));
        // Badge extra cuando el documento está inactivo.
        $inactivo = !$doc['activo'] ? ' <span class="badge bg-secondary ms-1">Inactivo</span>' : '';
        $estado = $doc['activo'] ? 'Activo' : 'Inactivo';
        $clase = $doc['activo'] ? 'estado-activo' : 'estado-inactivo';
        $id = (int) $doc['id'];

        // Botón de estado: cambia de texto según si está activo o no.
        // Llama a ElyraDoc.cambiarEstado(id, activo) definido en documentos.js.
        $btnEstado = $doc['activo']
            ? '<button type="button" class="btn btn-sm btn-outline-secondary"'
                . ' title="Desactivar documento"'
                . ' onclick="ElyraDoc.cambiarEstado(' . $id . ', 0, this)">'
                . '<i class="bi bi-slash-circle"></i></button>'
            : '<button type="button" class="btn btn-sm btn-outline-success"'
                . ' title="Reactivar documento"'
                . ' onclick="ElyraDoc.cambiarEstado(' . $id . ', 1, this)">'
                . '<i class="bi bi-arrow-counterclockwise"></i></button>';

        // El botón QR llama a ElyraDoc.verQR(id) definido en documentos.js.
        return '<tr data-doc-id="' . $id . '" data-doc-activo="' . ($doc['activo'] ? '1' : '0') . '">'
            . '<td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="ElyraDoc.verQR(' . $id . ')" title="Ver QR"><i class="bi bi-qr-code"></i></button></td>'
            . '<td class="fw-semibold">' . $titulo . $inactivo . '</td>'
            . '<td><span class="insignia">' . $tipo . '</span></td>'
            . '<td><span class="' . $clase . '">' . $estado . '</span></td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '<td><div class="d-flex gap-1">'
            . '<a href="documentos/ver?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Ver detalle"><i class="bi bi-eye"></i></a>'
            . '<a href="documentos/editar?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>'
            . $btnEstado
            . '</div></td>'
            . '</tr>';
    }

    /**
     * Entrega el PDF de un documento al navegador. Es la rutina compartida
     * por la descarga interna y la pública.
     *
     * $pdo:          conexión activa.
     * $id:           id del documento.
     * $requiereAuth: si true, exige sesión y además bloquea documentos de pacientes.
     * $descargar:    si true, fuerza la descarga (attachment); si no, inline (embed).
     */
    private static function servirPdf(PDO $pdo, int $id, bool $requiereAuth, bool $descargar): void
    {
        // Trae solo los datos necesarios para servir el archivo.
        $sql = 'SELECT id, archivo_path, archivo_contenido, archivo_nombre, activo, paciente_id FROM documento WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Reglas de bloqueo: documento inexistente o inactivo, o si pide auth
        // y es un documento de paciente (privado), se niega.
        $bloqueado = !$doc || !$doc['activo'];
        if ($requiereAuth && $doc && $doc['paciente_id'] !== null) {
            $bloqueado = true;
        }
        if ($bloqueado) {
            pagina_404();
            return;
        }

        // El PDF puede vivir en disco (archivo_path) o en la base (contenido).
        $path = (string) $doc['archivo_path'];
        $content = $doc['archivo_contenido'] ?? null;
        if (!is_file($path) && $content === null) {
            pagina_404();
            return;
        }

        // Nombre de descarga limpio (sin rutas).
        $nombre = basename((string) $doc['archivo_nombre']);

        // Cabeceras HTTP para servir el PDF correctamente.
        header('Content-Type: application/pdf');
        // inline = se muestra en el navegador; attachment = fuerza descarga.
        header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');

        // Envía el contenido: desde la base o leyendo el archivo del disco.
        if ($content !== null) {
            header('Content-Length: ' . strlen($content));
            echo $content;
        } else {
            header('Content-Length: ' . filesize($path));
            readfile($path);
        }
    }
}
