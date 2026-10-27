<?php

declare(strict_types=1);

/** OrganoData: capa de datos compartida del módulo de órganos. */
trait OrganoData
{
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
    }

    /** Busca órganos por texto (nombre/descripción) y filtro de estado. */
    private static function buscarOrganos(string $q, string $estado): array
    {
        $pdo = db_connect();

        $sql = 'SELECT id, nombre, descripcion, stock, activo, created_at FROM organo';
        $params = [];

        if ($q !== '') {
            $qLike = like_escapar($q);
            $sql .= ' WHERE (nombre LIKE :qNombre OR descripcion LIKE :qDesc)';
            $params['qNombre'] = '%' . $qLike . '%';
            $params['qDesc'] = '%' . $qLike . '%';
        }

        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 0';
        }

        $sql .= ' ORDER BY nombre ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Campos de un órgano para la vista (escapados, con los flags de estado). */
    private static function mostrarOrgano(array $organo): array
    {
        $activo = (bool) $organo['activo'];
        return [
            'id' => (int) $organo['id'],
            'nombre' => htmlspecialchars((string) $organo['nombre']),
            'descripcion' => htmlspecialchars((string) ($organo['descripcion'] ?? '')),
            'stock' => (int) $organo['stock'],
            'fecha' => date('d/m/Y', (int) strtotime((string) $organo['created_at'])),
            'activo' => $activo ? ['1'] : [],
            'inactivo' => $activo ? [] : ['1'],
        ];
    }
}
