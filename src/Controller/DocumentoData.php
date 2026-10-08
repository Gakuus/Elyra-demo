<?php

declare(strict_types=1);

/**
 * DocumentoData: capa compartida del módulo de documentos. Sus métodos
 * pasan a ser privados de la clase que use el trait.
 */
trait DocumentoData
{
    /** Fila de datos para la tabla de documentos generales (escapada, lista para la vista). */
    private static function filasDocumento(array $docs): array
    {
        $filas = [];
        foreach ($docs as $doc) {
            $filas[] = [
                'id'       => (string) (int) $doc['id'],
                'titulo'   => htmlspecialchars((string) $doc['titulo']),
                'tipo'     => htmlspecialchars((string) ($doc['tipo_nombre'] ?? '')),
                'fecha'    => date('d/m/Y', (int) strtotime((string) $doc['created_at'])),
                'inactivo' => !$doc['activo'] ? ['1'] : [],
                // Token firmado para el enlace/QR público (evita enumerar por id).
                'token'    => token_documento((int) $doc['id']),
            ];
        }
        return $filas;
    }

    /** Opciones para el <select> de tipo de documento (con la seleccionada marcada). */
    private static function tiposTipo(?int $seleccionado = null, bool $conTodos = false): array
    {
        $pdo = db_connect();
        $stmt = $pdo->query('SELECT id, nombre FROM tipo_documento ORDER BY nombre');

        $tipos = [];
        $tipos[] = [
            'id' => '',
            'nombre' => $conTodos ? 'Todos los tipos' : 'Seleccionar tipo...',
            'seleccionado' => [],
        ];
        foreach ($stmt->fetchAll() as $t) {
            $tipos[] = [
                'id'            => (string) (int) $t['id'],
                'nombre'        => htmlspecialchars((string) $t['nombre']),
                'seleccionado'  => $seleccionado !== null && (int) $t['id'] === $seleccionado ? ['1'] : [],
            ];
        }
        return $tipos;
    }

    /** Encuesta a ofrecer en la vista pública: la vinculada si está activa, si no la primera activa. */
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

        $stmt = $pdo->query('SELECT id FROM encuesta WHERE activa = 1 ORDER BY id LIMIT 1');
        $primera = $stmt->fetchColumn();
        return $primera !== false ? (int) $primera : null;
    }
}