<?php

declare(strict_types=1);

/**
 * DocumentoController: documentos clínicos del Hospital de Clínicas.
 *
 * Este controlador cubre la parte INTERNA (con sesión) del módulo: la subida
 * de nuevos PDF, el formulario de subida, la edición de metadatos y la ficha
 * con el visor. El archivado con filtros (listar), el form-Editar y el
 * activar/desactivar viven en DocumentoArchivoController; las vistas
 * públicas que el paciente abre desde el QR (sin sesión) en
 * DocumentoPublicoController. La capa compartida (tipos de documento, filas
 * de tabla y encuesta pública) está en el trait DocumentoData. El HTML de
 * filas, tablas y <option> lo arman las vistas, no este archivo.
 */
final class DocumentoController
{
    use DocumentoData;

    /**
     * Enrutador del módulo de documentos. index.php manda acá cualquier ruta
     * que arranque con /documentos, /publico/doc o /publico/archivo, y este
     * método decide con qué acción responder según la ruta y el método HTTP,
     * delegando en los controladores hermanos.
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
            $path === '/documentos' && $method === 'GET' => DocumentoArchivoController::listar(),
            $path === '/documentos/subir' && $method === 'POST' => self::subir(),
            $path === '/documentos/subir' && $method === 'GET' => self::formulario(),
            $path === '/documentos/editar' && $method === 'GET' => DocumentoArchivoController::formularioEditar(),
            $path === '/documentos/editar' && $method === 'POST' => self::editar(),
            // Activa/desactiva un documento (borrado lógico: no se elimina
            // la fila ni el archivo, solo deja de mostrarse al público).
            $path === '/documentos/estado' && $method === 'POST' => DocumentoArchivoController::estado(),
            $path === '/documentos/ver' && $method === 'GET' => self::ver(),
            $path === '/documentos/archivo' && $method === 'GET' => self::archivo(),
            $path === '/publico/doc' && $method === 'GET' => DocumentoPublicoController::publicoDoc(),
            $path === '/publico/archivo' && $method === 'GET' => DocumentoPublicoController::publicoArchivo(),
            // Ninguna condición coincidió → página 404.
            default => pagina_404(),
        };
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
        $destPath = '';

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
            'hay_error' => $error !== null ? ['1'] : [],
            'error' => $error ?? '',
            'valor_titulo' => htmlspecialchars($titulo),
            'valor_descripcion' => htmlspecialchars($descripcion),
            'opciones_tipo_subir' => self::tiposTipo($tipoId > 0 ? $tipoId : null),
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
            'hay_error' => [],
            'error' => '',
            'valor_titulo' => '',
            'valor_descripcion' => '',
            'opciones_tipo_subir' => self::tiposTipo(),
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
                'hay_error' => ['1'],
                'error' => $error,
                'id' => (string) $id,
                'valor_titulo' => htmlspecialchars($titulo),
                'valor_descripcion' => htmlspecialchars($descripcion),
                'opciones_tipo_editar' => self::tiposTipo($tipoId > 0 ? $tipoId : null),
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

        // La descripción se muestra solo si hay una; la vista usa el flag.
        $descripcion = (string) ($doc['descripcion'] ?? '');
        $hayDescripcion = $descripcion !== '' ? ['1'] : [];

        // Renderiza el detalle con los datos del documento (nada de HTML acá).
        render_dashboard('documento_ver', 'Documento', 'documentos', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion' => $descripcion,
            'hay_descripcion' => $hayDescripcion,
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