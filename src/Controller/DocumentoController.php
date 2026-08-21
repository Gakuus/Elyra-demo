<?php

declare(strict_types=1);

/**
 * DocumentoController: controlador de documentos generales.
 * Gestiona la subida, listado, visualización y descarga de documentos (PDF)
 * con código QR. También expone las vistas públicas (las que abren los
 * pacientes escaneando el QR, sin necesidad de iniciar sesión).
 */
final class DocumentoController
{
    /**
     * Enrutador interno del módulo de documentos.
     * index.php delega acá cualquier ruta que empiece con /documentos,
     * /publico/doc o /publico/archivo, y este método decide qué acción
     * ejecutar según la ruta exacta y el método HTTP.
     *
     * @param string $path   Ruta (ej: '/documentos/subir').
     * @param string $method Método HTTP en mayúsculas ('GET' o 'POST').
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
            $path === '/documentos/ver' && $method === 'GET' => self::ver(),
            $path === '/documentos/archivo' && $method === 'GET' => self::archivo(),
            $path === '/publico/doc' && $method === 'GET' => self::publicoDoc(),
            $path === '/publico/archivo' && $method === 'GET' => self::publicoArchivo(),
            // Ninguna condición coincidió → página 404.
            default => pagina_404(),
        };
    }

    /**
     * Listado de documentos generales (GET a /documentos).
     * Muestra la tabla con todos los documentos, con filtros opcionales
     * por tipo de documento y por búsqueda de texto en el título.
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
            ? '<div class="text-center text-muted p-3" style="font-size:15px;">'
                . '<i class="bi bi-inbox d-block mb-2" style="font-size:28px;"></i>No hay documentos generales.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th style="width:50px;">QR</th><th>T&iacute;tulo</th><th>Tipo</th><th>Estado</th><th>Subido</th>'
                . '<th style="width:90px;">Acciones</th></tr></thead><tbody>' . $filas . '</tbody></table></div>';

        // Renderiza la vista con: opciones del selector de tipo, texto de
        // búsqueda conservado y el HTML de la tabla ya armado.
        render_dashboard('documentos', 'Documentos generales', 'documentos', [
            'opciones_tipo' => self::opcionesTipo($tipo, true),
            'q' => htmlspecialchars($q),
            'contenido_documentos' => $contenido,
        ]);
    }

    /**
     * Procesa la subida de un documento (POST a /documentos/subir).
     * Valida los datos, guarda el PDF en disco, registra el documento en la
     * base de datos y redirige al listado. Si hay errores, vuelve al
     * formulario con los valores cargados y el mensaje de error.
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
            $error = 'El t&iacute;tulo debe tener entre 3 y 200 caracteres.';
        } elseif ($tipoId <= 0) {
            $error = 'Seleccion&aacute; un tipo de documento.';
        } elseif (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            // UPLOAD_ERR_OK (0) significa que el archivo llegó sin problemas.
            $error = 'Seleccion&aacute; un archivo PDF para subir.';
        } else {
            $archivo = $_FILES['archivo'];

            // Detecta el tipo real del archivo (no confía en la extensión).
            $mime = @mime_content_type($archivo['tmp_name']);
            if ($mime !== 'application/pdf') {
                $error = 'El archivo debe ser un PDF v&aacute;lido.';
            } elseif ($archivo['size'] > 10 * 1024 * 1024) {
                // Límite de 10 MB (10 * 1024 * 1024 bytes).
                $error = 'El archivo supera el tama&ntilde;o m&aacute;ximo de 10 MB.';
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
                    $error = 'Error al guardar el archivo. Verific&aacute; los permisos del servidor.';
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
     * Muestra el formulario vacío de subida (GET a /documentos/subir).
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
     * Página de detalle de un documento (GET a /documentos/ver?id=N).
     * Muestra el título, tipo, fecha, descripción y el PDF embebido.
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
     * Sirve el PDF de un documento autenticado (GET a /documentos/archivo?id=N).
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
     * Vista pública de un documento (GET a /publico/doc?id=N).
     * Es lo que ve el paciente al escanear el QR: no requiere sesión.
     * Solo se muestran documentos ACTIVOS y generales.
     */
    public static function publicoDoc(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Misma consulta que ver(), pero exigiendo activo = 1 (seguridad:
        // un documento desactivado no se puede abrir por QR).
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.activo, d.created_at, t.nombre AS tipo_nombre
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

        // Renderiza la vista pública (sin el layout del dashboard).
        render_vista(__DIR__ . '/../../views/publico/documento.html', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion_bloque' => $doc['descripcion'] !== null && $doc['descripcion'] !== ''
                ? '<p class="text-muted mt-2">' . htmlspecialchars((string) $doc['descripcion']) . '</p>'
                : '',
            'id' => (string) $id,
        ]);
    }

    /**
     * Sirve el PDF desde la vista pública (GET a /publico/archivo?id=N).
     * Sin sesión, pero solo para documentos activos y generales.
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
     * Genera las <option> del selector de tipo de documento.
     * Es un HTML reutilizable para el filtro del listado y el formulario.
     *
     * @param int|null  $seleccionado Id del tipo que debe quedar marcado.
     * @param bool      $conTodos     Si true, incluye la opción "Todos los tipos".
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
     * Construye la fila <tr> de un documento para la tabla del listado.
     * Incluye botón de QR, título (con aviso si está inactivo), tipo,
     * estado, fecha y acción de ver detalle.
     *
     * @param array $doc Fila de documento devuelta por la consulta.
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

        // El botón QR llama a ElyraDoc.verQR(id) definido en documentos.js.
        return '<tr>'
            . '<td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="ElyraDoc.verQR(' . $id . ')" title="Ver QR"><i class="bi bi-qr-code"></i></button></td>'
            . '<td class="fw-semibold">' . $titulo . $inactivo . '</td>'
            . '<td><span class="insignia">' . $tipo . '</span></td>'
            . '<td><span class="' . $clase . '">' . $estado . '</span></td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '<td><div class="d-flex gap-1">'
            . '<a href="documentos/ver?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Ver detalle"><i class="bi bi-eye"></i></a>'
            . '</div></td>'
            . '</tr>';
    }

    /**
     * Envía el PDF de un documento al navegador.
     * Es la función compartida por las rutas autenticada y pública.
     *
     * @param PDO  $pdo          Conexión activa.
     * @param int  $id           Id del documento.
     * @param bool $requiereAuth Si true, exige sesión y bloquea documentos de pacientes.
     * @param bool $descargar    Si true, fuerza la descarga (attachment); si no, inline (embed).
     */
    private static function servirPdf(PDO $pdo, int $id, bool $requiereAuth, bool $descargar): void
    {
        // Trae solo los datos necesarios para servir el archivo.
        $sql = 'SELECT id, archivo_path, archivo_nombre, activo, paciente_id FROM documento WHERE id = :id';
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
