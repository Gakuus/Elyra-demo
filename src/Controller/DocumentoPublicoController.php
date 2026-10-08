<?php

declare(strict_types=1);

/**
 * DocumentoPublicoController: vistas públicas de un documento (lo que el
 * paciente abre desde el QR). No requieren sesión.
 */
final class DocumentoPublicoController
{
    use DocumentoData;

    /** Página pública del documento (/publico/doc). Solo activos y generales. */
    public static function publicoDoc(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);
        $token = isset($_GET['t']) ? (string) $_GET['t'] : '';

        // El enlace público viaja firmado (HMAC del id). Sin token válido no se
        // revela nada, ni siquiera si el documento existe: así no se puede
        // recorrer el repositorio probando ids (1, 2, 3...).
        if (!token_documento_valido($id, $token)) {
            pagina_404();
            return;
        }

        // Solo documentos activos y sin paciente: si no, 404.
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.created_at, d.encuesta_id,
                    t.nombre AS tipo_nombre
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

        // Encuesta vinculada o la primera activa como respaldo; null = sin botón.
        $encuestaId = self::resolverEncuestaPublica($pdo, $doc['encuesta_id'] !== null ? (int) $doc['encuesta_id'] : null);

        $hayEncuesta = $encuestaId !== null ? ['1'] : [];
        $enlaceEncuesta = $encuestaId !== null
            ? base_path() . '/publico/encuesta?id=' . $encuestaId
            : '';

        $descripcion = (string) ($doc['descripcion'] ?? '');

        render_vista(__DIR__ . '/../../views/publico/documento.html', [
            'titulo' => (string) $doc['titulo'],
            'tipo' => (string) ($doc['tipo_nombre'] ?? ''),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion' => $descripcion,
            'hay_descripcion' => $descripcion !== '' ? ['1'] : [],
            'id' => (string) $id,
            'token' => $token,
            'hay_encuesta' => $hayEncuesta,
            'enlace_encuesta' => $enlaceEncuesta,
        ]);
    }

    /** Sirve el PDF al público (/publico/archivo). Sin sesión; solo activos y generales. */
    public static function publicoArchivo(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);
        $token = isset($_GET['t']) ? (string) $_GET['t'] : '';

        if (token_documento_valido($id, $token)) {
            self::servirPdfPublico($pdo, $id, !empty($_GET['descargar']));
        } else {
            http_response_code(404);
        }
    }

    /** Entrega el PDF por la ruta pública (misma lógica que la interna, sin sesión). */
    private static function servirPdfPublico(PDO $pdo, int $id, bool $descargar): void
    {
        $sql = 'SELECT id, archivo_path, archivo_contenido, archivo_nombre, activo, paciente_id FROM documento WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Inactivo o de paciente no se sirve de forma pública.
        if (!$doc || !$doc['activo'] || $doc['paciente_id'] !== null) {
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
        // El nombre viene del archivo que subió el usuario: se sanea para no
        // romper la cabecera con comillas o caracteres de control.
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