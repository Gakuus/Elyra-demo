<?php

declare(strict_types=1);

/**
 * DocumentoPublicoController: vistas públicas de un documento.
 *
 * Es lo que el paciente abre al escanear el QR (la ruta /publico/doc) y el
 * PDF propiamente dicho (ruta /publico/archivo). NO requieren sesión: son la
 * cara pública del QR que el centro entrega. Si una encuesta de satisfacción
 * está marcada para el documento, se muestra también el botón para votar con
 * el enlace a la encuesta. Nada de HTML acá: las vistas lo arman.
 */
final class DocumentoPublicoController
{
    use DocumentoData;

    /**
     * Página pública de un documento (GET a /publico/doc?id=N).
     * Muestra título, tipo, descripción y enlaces para ver o descargar el
     * PDF. No hace falta estar logueado, pero el documento debe estar activo
     * y no pertenecer a la historia de un paciente (privacidad).
     */
    public static function publicoDoc(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        // Solo documentos ACTIVOS y GENERALES (sin paciente): si no, 404.
        $stmt = $pdo->prepare(
            'SELECT d.id, d.titulo, d.descripcion, d.created_at, d.encuesta_id,
                    t.nombre AS tipo_nombre
             FROM documento d
             LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
             WHERE d.id = :id AND d.activo = 1 AND d.paciente_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Si no existe (o fue desactivado) → página 404.
        if (!$doc) {
            pagina_404();
            return;
        }

        // Decide si la encuesta de satisfacción debe aparecer: usa la que el
        // documento tenga vinculada (si sigue activa) o la primera encuesta
        // activa del sistema como respaldo. Null = no mostrar botón.
        $encuestaId = self::resolverEncuestaPublica($pdo, $doc['encuesta_id'] !== null ? (int) $doc['encuesta_id'] : null);

        // Si hay encuesta, la vista muestra el botón con el enlace directo.
        $hayEncuesta = $encuestaId !== null ? ['1'] : [];
        $enlaceEncuesta = $encuestaId !== null
            ? base_path() . '/publico/encuesta?id=' . $encuestaId
            : '';

        $descripcion = (string) ($doc['descripcion'] ?? '');

        render_vista(__DIR__ . '/../../views/publico/documento.html', [
            'titulo' => htmlspecialchars((string) $doc['titulo']),
            'tipo' => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
            'fecha' => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
            'descripcion' => $descripcion,
            'hay_descripcion' => $descripcion !== '' ? ['1'] : [],
            'id' => (string) $id,
            // La vista decide con {{#hay_encuesta}} si muestra el botón.
            'hay_encuesta' => $hayEncuesta,
            'enlace_encuesta' => $enlaceEncuesta,
        ]);
    }

    /**
     * Sirve el PDF de un documento al público (GET a /publico/archivo?id=N).
     * Igual que la ruta interna pero SIN pedir sesión y solo para
     * documentos activos y generales. Un documento inactivo da 404.
     */
    public static function publicoArchivo(): void
    {
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            // requiereAuth=false: el público puede verlo (más la regla de
            // activo/general que aplica servirPdf igual).
            self::servirPdfPublico($pdo, $id, !empty($_GET['descargar']));
        } else {
            http_response_code(404);
        }
    }

    /**
     * Entrega el PDF al navegador para la ruta pública. Es la misma idea de
     * servirPdf() del controlador interno, pero sin pedir sesión.
     *
     * $pdo:       conexión activa.
     * $id:        id del documento.
     * $descargar: true fuerza descarga (attachment); false lo muestra inline.
     */
    private static function servirPdfPublico(PDO $pdo, int $id, bool $descargar): void
    {
        // Trae solo lo necesario para servir el archivo.
        $sql = 'SELECT id, archivo_path, archivo_contenido, archivo_nombre, activo, paciente_id FROM documento WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $doc = $stmt->fetch();

        // Un documento inactivo o de paciente NO se sirve de forma pública.
        if (!$doc || !$doc['activo'] || $doc['paciente_id'] !== null) {
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
        header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');

        if ($content !== null) {
            header('Content-Length: ' . strlen($content));
            echo $content;
        } else {
            header('Content-Length: ' . filesize($path));
            readfile($path);
        }
    }
}