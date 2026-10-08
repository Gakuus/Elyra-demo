<?php

declare(strict_types=1);

/**
 * DocumentoArchivoController: listado con filtros, formulario de edición y
 * activar/desactivar de documentos generales. La capa compartida está en
 * DocumentoData.
 */
final class DocumentoArchivoController
{
    use DocumentoData;

    /** Archivado de documentos generales, con filtros por tipo y texto. */
    public static function listar(): void
    {
        requerir_gestion();

        $pdo = db_connect();

        // Filtros de la URL (?tipo=2&q=protocolo).
        $tipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? (int) $_GET['tipo'] : null;
        $q = trim((string) ($_GET['q'] ?? ''));

        $sql = 'SELECT d.id, d.titulo, d.activo, d.created_at, t.nombre AS tipo_nombre
                FROM documento d
                LEFT JOIN tipo_documento t ON t.id = d.tipo_documento_id
                WHERE d.paciente_id IS NULL';

        $params = [];
        if ($tipo !== null) {
            $sql .= ' AND d.tipo_documento_id = :tipo';
            $params['tipo'] = $tipo;
        }
        if ($q !== '') {
            $sql .= ' AND d.titulo LIKE :q';
            $params['q'] = '%' . like_escapar($q) . '%';
        }
        $sql .= ' ORDER BY d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        $filas = self::filasDocumento($docs);

        render_dashboard('documentos', 'Documentos generales', 'documentos', [
            'tipos' => self::tiposTipo($tipo, true),
            'q' => htmlspecialchars($q),
            'documentos' => $filas,
            'hay_documentos' => $filas === [] ? [] : ['1'],
        ]);
    }

    /** Formulario de edición: solo se tocan título, tipo y descripción (no el PDF). */
    public static function formularioEditar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

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

    /** Baja lógica: inactivo deja de abrirse por QR pero conserva archivo y datos. Responde JSON. */
    public static function estado(): void
    {
        requerir_gestion();

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