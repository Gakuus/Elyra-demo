<?php

declare(strict_types=1);

/** InsumoData: capa de datos compartida del módulo de insumos. */
trait InsumoData
{
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
    }

    /** Busca insumos por texto (nombre/descripción) y filtro de estado. */
    private static function buscarInsumos(string $q, string $estado): array
    {
        $pdo = db_connect();

        $sql = 'SELECT id, nombre, descripcion, stock, activo, created_at FROM insumo';
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

    /** Campos de un insumo para la vista (escapados, con los flags de estado). */
    private static function mostrarInsumo(array $ins): array
    {
        $activo = (bool) $ins['activo'];
        return [
            'id' => (int) $ins['id'],
            'nombre' => htmlspecialchars((string) $ins['nombre']),
            'descripcion' => htmlspecialchars((string) ($ins['descripcion'] ?? '')),
            'stock' => (int) $ins['stock'],
            'fecha' => date('d/m/Y', (int) strtotime((string) $ins['created_at'])),
            'activo' => $activo ? ['1'] : [],
            'inactivo' => $activo ? [] : ['1'],
        ];
    }
}