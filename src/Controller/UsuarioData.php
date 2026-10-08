<?php

declare(strict_types=1);

/**
 * UsuarioData: capa compartida del módulo de usuarios (JSON y búsqueda de
 * personas). Al usarse desde una clase, sus métodos pasan a ser privados de esa clase.
 */
trait UsuarioData
{
    private static function respondeJson(array $datos): void
    {
        header('Content-Type: application/json');
        echo json_encode($datos);
        exit;
    }

    /**
     * Busca personas por cédula (prefijo) o texto parcial en nombre/apellido,
     * con filtro de estado. Lógica compartida entre la vista y el AJAX.
     */
    private static function buscarPersonas(string $q, string $estado): array
    {
        $pdo = db_connect();

        // Una persona es funcionario O paciente; el LEFT JOIN lee rol y activo en una pasada.
        $sql = "SELECT u.id, u.tipo, u.nombre, u.apellido, u.email, u.documento_identidad,
                       f.licencia, f.telefono AS telefono_func, f.username AS username_func, f.rol,
                       p.token_acceso, p.telefono AS telefono_pac, p.username AS username_pac,
                       COALESCE(f.activo, p.activo) AS activo
                FROM usuario u
                LEFT JOIN funcionario f ON f.id = u.id
                LEFT JOIN paciente p ON p.id = u.id";

        $params = [];

        // La cédula se compara por PREFIJO y sin separadores (1.234.567-8 → 12345678);
        // el nombre y el apellido con texto parcial.
        if ($q !== '') {
            $qLimpio = preg_replace('/[^\d]/', '', $q);
            $qLike = like_escapar($q);

            $sql .= ' WHERE (';
            $sql .= 'REPLACE(REPLACE(REPLACE(u.documento_identidad, ".", ""), "-", ""), " ", "") LIKE :cedula';
            $params['cedula'] = ($qLimpio !== '' ? $qLimpio : $qLike) . '%';
            $sql .= ' OR u.nombre LIKE :nombre OR u.apellido LIKE :apellido)';
            $params['nombre'] = '%' . $qLike . '%';
            $params['apellido'] = '%' . $qLike . '%';
        }

        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 0';
        }

        // Las últimas altas primero; top 200.
        $sql .= ' ORDER BY u.created_at DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}