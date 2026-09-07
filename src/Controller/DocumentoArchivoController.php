<?php

declare(strict_types=1);

/**
 * DocumentoArchivoController: archivo de documentos generales del hospital.
 *
 * Cubre la parte interna del módulo que no es ni subida ni ficha: el listado
 * con filtros por tipo y texto, el formulario de edición (GET para precargar)
 * y el activar/desactivar (borrado lógico). La capa compartida (filas de
 * tabla y tipos de documento) está en el trait DocumentoData y el HTML lo
 * arman las vistas, no este archivo.
 */
final class DocumentoArchivoController
{
    use DocumentoData;

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

        // Convierte cada documento en una fila de datos; la vista hace el <tr>.
        $filas = self::filasDocumento($docs);

        // Renderiza la vista con: opciones del selector de tipo, texto de
        // búsqueda conservado y las filas ya transformadas (nada de HTML acá).
        render_dashboard('documentos', 'Documentos generales', 'documentos', [
            'tipos' => self::tiposTipo($tipo, true),
            'q' => htmlspecialchars($q),
            'documentos' => $filas,
            'hay_documentos' => $filas === [] ? [] : ['1'],
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
            'hay_error' => [],
            'error' => '',
            'id' => (string) $id,
            'valor_titulo' => htmlspecialchars((string) $doc['titulo']),
            'valor_descripcion' => htmlspecialchars((string) ($doc['descripcion'] ?? '')),
            'opciones_tipo_editar' => self::tiposTipo((int) $doc['tipo_documento_id']),
        ]);
    }

    /**
     * Activa o desactiva un documento (POST a /documentos/estado). Es el
     * "desactivar" del archivo: baja un documento sin borrar el PDF. Uno
     * inactivo deja de abrirse por el QR (404) pero conserva archivo y datos.
     * Devuelve JSON para que el JS actualice la fila sin recargar.
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
}