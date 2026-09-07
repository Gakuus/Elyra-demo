<?php

declare(strict_types=1);

/**
 * InsumoData: capa de datos compartida del módulo de insumos médicos.
 *
 * El controlador del módulo la usa con `use InsumoData` para no duplicar
 * consultas y comportamiento común (el permiso de gestión admin/superadmin,
 * el envío de respuestas JSON, la búsqueda de insumos y el armado de las
 * filas para la vista). Son métodos estáticos privados que, al usarse desde
 * una clase, pasan a ser privados de esa clase.
 */
trait InsumoData
{
    /** True si el usuario logueado es admin o superadmin (gestión). */
    private static function esGestion(): bool
    {
        return in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true);
    }

    /** Envía una respuesta JSON y corta la ejecución. */
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
    }

    /**
     * Consulta los insumos que coinciden con un criterio de búsqueda
     * (texto parcial en nombre o descripción) y un filtro de estado.
     * Es la lógica compartida entre la vista y el filtrado de listado.
     *
     * @param string $q      Texto a buscar ('' = sin filtro de texto).
     * @param string $estado 'todos' | 'activos' | 'inactivos'.
     * @return array Lista de filas de insumos.
     */
    private static function buscarInsumos(string $q, string $estado): array
    {
        $pdo = db_connect();

        $sql = 'SELECT id, nombre, descripcion, stock, activo, created_at FROM insumo';
        $params = [];

        if ($q !== '') {
            $sql .= ' WHERE (nombre LIKE :qNombre OR descripcion LIKE :qDesc)';
            $params['qNombre'] = '%' . $q . '%';
            $params['qDesc'] = '%' . $q . '%';
        }

        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 0';
        }

        // Los más recientes primero.
        $sql .= ' ORDER BY nombre ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Convierte una fila de insumo en los campos que espera la vista del
     * listado: texto escapado (XSS), stock en formato legible, fecha en
     * d/m/Y y el estado como bloque que alimenta {{#activo}} / {{#inactivo}}.
     */
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