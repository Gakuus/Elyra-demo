<?php

declare(strict_types=1);

/**
 * DocumentoController: documentos clínicos. Gestión interna con sesión
 * (subida, edición, visor). El listado/activación vive en
 * DocumentoArchivoController y las vistas públicas en DocumentoPublicoController.
 */
final class DocumentoController
{
    use DocumentoData;

    /** Enrutador del módulo: index.php delega acá /documentos y /publico. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/documentos' && $method === 'GET' => DocumentoArchivoController::listar(),
            $path === '/documentos/subir' && $method === 'POST' => self::subir(),
            $path === '/documentos/subir' && $method === 'GET' => self::formulario(),
            $path === '/documentos/editar' && $method === 'GET' => DocumentoArchivoController::formularioEditar(),
            $path === '/documentos/editar' && $method === 'POST' => self::editar(),
            $path === '/documentos/estado' && $method === 'POST' => DocumentoArchivoController::estado(),
            $path === '/documentos/ver' && $method === 'GET' => self::ver(),
            $path === '/documentos/archivo' && $method === 'GET' => self::archivo(),
            $path === '/publico/doc' && $method === 'GET' => DocumentoPublicoController::publicoDoc(),
            $path === '/publico/archivo' && $method === 'GET' => DocumentoPublicoController::publicoArchivo(),
            default => pagina_404(),
        };
    }

    /** Alta de documento: valida, guarda el PDF en disco y registra en MySQL. */
    public static function subir(): void
    {
        requerir_gestion();

        $pdo = db_connect();

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $tipoId = (int) ($_POST['tipo'] ?? 0);

        $error = null;
        $subido = false;
        $destPath = '';

        // Validaciones en cadena: se detiene en el primer error.
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $error = 'El título debe tener entre 3 y 200 caracteres.';
        } elseif ($tipoId <= 0) {
            $error = 'Seleccioná un tipo de documento.';
        } elseif (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Seleccioná un archivo PDF para subir.';
        } else {
            $archivo = $_FILES['archivo'];

            // Se detecta el tipo real, no se confía en la extensión.
            $mime = @mime_content_type($archivo['tmp_name']);
            if ($mime !== 'application/pdf') {
                $error = 'El archivo debe ser un PDF válido.';
            } elseif ($archivo['size'] > 10 * 1024 * 1024) {
                $error = 'El archivo supera el tamaño máximo de 10 MB.';
            } else {
                // Nombre seguro (solo letras, números, guiones) + timestamp.
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string) $archivo['name'], PATHINFO_FILENAME));
                $filename = mb_substr((string) $safeName, 0, 80) . '_' . time() . '.pdf';

                $storageDir = __DIR__ . '/../../storage/docs';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0775, true);
                }
                $destPath = $storageDir . '/' . $filename;

                if (!move_uploaded_file($archivo['tmp_name'], $destPath)) {
                    $error = 'Error al guardar el archivo. Verificá los permisos del servidor.';
                } else {
                    // Archivo en disco → se registra la RUTA, no el archivo.
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

        if ($subido) {
            header('Location: ' . base_path() . '/documentos?subido=1');
            exit;
        }

        // Error → vuelve al formulario conservando lo cargado.
        render_dashboard('documentos_subir', 'Subir documento', 'documentos', [
            'hay_error' => $error !== null ? ['1'] : [],
            'error' => $error ?? '',
            'valor_titulo' => htmlspecialchars($titulo),
            'valor_descripcion' => htmlspecialchars($descripcion),
            'opciones_tipo_subir' => self::tiposTipo($tipoId > 0 ? $tipoId : null),
        ]);
    }

    public static function formulario(): void
    {
        requerir_gestion();

        render_dashboard('documentos_subir', 'Subir documento', 'documentos', [
            'hay_error' => [],
            'error' => '',
            'valor_titulo' => '',
            'valor_descripcion' => '',
            'opciones_tipo_subir' => self::tiposTipo(),
        ]);
    }

    /** Edición de metadatos (título, descripción, tipo); archivo y estado quedan intactos. */
    public static function editar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/documentos');
            exit;
        }

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $tipoId = (int) ($_POST['tipo'] ?? 0);

        $error = null;
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $error = 'El título debe tener entre 3 y 200 caracteres.';
        } elseif ($tipoId <= 0) {
            $error = 'Seleccioná un tipo de documento.';
        } else {
            // El tipo debe existir (evita romper la clave foránea).
            $stmtT = $pdo->prepare('SELECT COUNT(*) FROM tipo_documento WHERE id = :id');
            $stmtT->execute(['id' => $tipoId]);
            if ((int) $stmtT->fetchColumn() === 0) {
                $error = 'El tipo de documento seleccionado no existe.';
            }
        }

        if ($error !== null) {
            render_dashboard('documentos_editar', 'Editar documento', 'documentos', [
                'hay_error' => ['1'],
                'error' => $error,
                'id' => (string) $id,
                'valor_titulo' => htmlspecialchars($titulo),
                'valor_descripcion' => htmlspecialchars($descripcion),
                'opciones_tipo_editar' => self::tiposTipo($tipoId > 0 ? $tipoId : null),
            ]);
            return;
        }

        // UPDATE acotado: solo metadatos, nunca archivo ni estado.
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

    /** Detalle de un documento general: muestra el PDF embebido. */
    public static function ver(): void
    {
        requerir_gestion();

        $pdo = db_connect();

        $id = (int) ($_GET['id'] ?? 0);

        // Solo documentos generales (paciente_id IS NULL).
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.activo, d.created_at, t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.id = :id AND d.paciente_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            pagina_404();
            return;
        }

        $descripcion = (string) ($doc['descripcion'] ?? '');
        $hayDescripcion = $descripcion !== '' ? ['1'] : [];

        render_dashboard('documento_ver', 'Documento', 'documentos', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion' => $descripcion,
            'hay_descripcion' => $hayDescripcion,
            'id' => (string) $id,
        ]);
    }

    /** Entrega el PDF con sesión interna (lo usa el <embed> y la descarga). */
    public static function archivo(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            self::servirPdf($pdo, $id, true, !empty($_GET['descargar']));
        } else {
            http_response_code(404);
        }
    }

    /** Rutina compartida que sirve el PDF (interna y pública). */
    private static function servirPdf(PDO $pdo, int $id, bool $requiereAuth, bool $descargar): void
    {
        $sql = 'SELECT id, archivo_path, archivo_contenido, archivo_nombre, activo, paciente_id FROM documento WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Inexistente/inactivo, o documento de paciente si se exige auth → se niega.
        $bloqueado = !$doc || !$doc['activo'];
        if ($requiereAuth && $doc && $doc['paciente_id'] !== null) {
            $bloqueado = true;
        }
        if ($bloqueado) {
            pagina_404();
            return;
        }

        // El PDF vive en disco (archivo_path) o en la base (archivo_contenido).
        // La ruta de disco se valida contra storage/docs: si un archivo_path
        // manipulado apunta a otra parte del disco, se ignora.
        $content = $doc['archivo_contenido'] ?? null;
        $path = $content === null ? ruta_pdf_segura((string) $doc['archivo_path']) : null;
        if ($content === null && $path === null) {
            pagina_404();
            return;
        }

        $nombre = basename((string) $doc['archivo_nombre']);

        header('Content-Type: application/pdf');
        // inline = se muestra; attachment = fuerza descarga. El nombre se
        // sanea porque lo eligió el usuario al subir el archivo.
        header(content_disposition($descargar ? 'attachment' : 'inline', $nombre));

        if ($content !== null) {
            header('Content-Length: ' . strlen($content));
            echo $content;
        } else {
            header('Content-Length: ' . filesize((string) $path));
            readfile((string) $path);
        }
    }
}