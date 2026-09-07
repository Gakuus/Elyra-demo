<?php

declare(strict_types=1);

/**
 * UsuarioData: capa compartida del módulo de usuarios.
 *
 * Los tres controladores del módulo la usan con `use UsuarioData` para no
 * duplicar consultas y comportamiento común (el registro del rol de gestión,
 * el envío de respuestas JSON y la consulta de búsqueda de personas).
 * Son métodos estáticos privados que, al usarse desde una clase, pasan a ser
 * privados de esa clase (misma visibilidad que cuando vivían en un único
 * UsuarioController).
 */
trait UsuarioData
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
     * Consulta las personas que coinciden con un criterio de búsqueda
     * (cédula exacta o texto parcial en nombre/apellido) y un filtro de
     * estado. Es la lógica compartida entre la vista y el AJAX.
     *
     * @param string $q      Texto a buscar ('' = sin filtro de texto).
     * @param string $estado 'todos' | 'activos' | 'inactivos'.
     * @return array Lista de filas de personas (usuario + funcionario/paciente).
     */
    private static function buscarPersonas(string $q, string $estado): array
    {
        $pdo = db_connect();

        // Una persona es un funcionario O un paciente; unimos las dos tablas
        // para leer en una sola pasada su rol y si está activo. La coincidencia
        // es por id (la relación 1 a 1 que se crea en el alta de Auth).
        $sql = "SELECT u.id, u.tipo, u.nombre, u.apellido, u.email, u.documento_identidad,
                       f.licencia, f.telefono AS telefono_func, f.username AS username_func, f.rol,
                       p.token_acceso, p.telefono AS telefono_pac, p.username AS username_pac,
                       COALESCE(f.activo, p.activo) AS activo
                FROM usuario u
                LEFT JOIN funcionario f ON f.id = u.id
                LEFT JOIN paciente p ON p.id = u.id";

        $params = [];

        // Búsqueda progreva: la cédula se compara por PREFIJO (no exacta),
        // de modo que al ir escribiendo "1", "11", "111"... van apareciendo
        // las cédulas que empiezan con esos dígitos. Para que funcione también
        // con el formato uruguayo (1.234.567-8) se eliminan puntos y guiones
        // de ambos lados con REPLACE y se compara contra el texto sin esos
        // separadores.
        if ($q !== '') {
            // Texto "normalizado" para la cédula: solo dígitos.
            $qLimpio = preg_replace('/[^\d]/', '', $q);

            $sql .= ' WHERE (';
            // Prefijo sobre la cédula limpia: si escribís "111" y hay una
            // cédula "1.111.111-1", su forma limpia "11111111" empieza con 111.
            $sql .= 'REPLACE(REPLACE(REPLACE(u.documento_identidad, ".", ""), "-", ""), " ", "") LIKE :cedula';
            $params['cedula'] = ($qLimpio !== '' ? $qLimpio : $q) . '%';
            // Y además texto parcial en nombre o apellido.
            $sql .= ' OR u.nombre LIKE :nombre OR u.apellido LIKE :apellido)';
            $params['nombre'] = '%' . $q . '%';
            $params['apellido'] = '%' . $q . '%';
        }

        // Filtra por estado usando el mismo alias COALESCE de arriba.
        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' COALESCE(f.activo, p.activo) = 0';
        }

        // Las últimas dadas de alta primero; top 200 para no volcar todo.
        $sql .= ' ORDER BY u.created_at DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}