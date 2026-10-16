<?php

declare(strict_types=1);

/** EquipamientoData: capa de datos compartida del módulo de equipamiento médico. */
trait EquipamientoData
{
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
    }

    /** Busca equipamiento por texto (nombre/descripción) y filtro de estado. */
    private static function buscarEquipamiento(string $q, string $estado): array
    {
        $pdo = db_connect();

        $sql = 'SELECT id, nombre, descripcion, stock, activo, created_at FROM equipamiento';
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

    /** Campos de un equipo para la vista (escapados, con los flags de estado). */
    private static function mostrarEquipamiento(array $eq): array
    {
        $activo = (bool) $eq['activo'];
        return [
            'id' => (int) $eq['id'],
            'nombre' => htmlspecialchars((string) $eq['nombre']),
            'descripcion' => htmlspecialchars((string) ($eq['descripcion'] ?? '')),
            'stock' => (int) $eq['stock'],
            'fecha' => date('d/m/Y', (int) strtotime((string) $eq['created_at'])),
            'activo' => $activo ? ['1'] : [],
            'inactivo' => $activo ? [] : ['1'],
        ];
    }
}
