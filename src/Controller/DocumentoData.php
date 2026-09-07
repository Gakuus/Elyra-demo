<?php

declare(strict_types=1);

/**
 * DocumentoData: capa compartida del módulo de documentos.
 *
 * Los tres controladores del módulo la usan con `use DocumentoData` para no
 * duplicar las consultas y el armado de datos: las opciones del selector de
 * tipo de documento, la conversión de filas a datos de tabla (con su estado
 * y flag de inactivo) y la resolución de la encuesta de satisfacción que se
 * ofrece en la vista pública. Son métodos estáticos que, al usarse desde
 * cada clase, pasan a ser privados de esa clase.
 */
trait DocumentoData
{
    /**
     * Devuelve las filas de datos para la tabla de documentos generales,
     * con los campos ya escapados y el flag de inactivo para el badge.
     * La vista arma el <tr> y el botón QR/estado; acá no hay HTML.
     *
     * @param array $docs Filas del SELECT de documentos.
     * @return array Lista con 'id','titulo','tipo','fecha','inactivo'.
     */
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
            ];
        }
        return $filas;
    }

    /**
     * Devuelve las filas de datos para el <select> de tipo de documento.
     * $seleccionado es el id del tipo que debe quedar marcado (o null);
     * $conTodos agrega la opción "Todos los tipos" (para el filtro) en vez
     * de "Seleccionar tipo...". Nada de HTML: solo datos (id, nombre y el
     * flag seleccionado para que la vista ponga el atributo como un bloque).
     *
     * @return array Lista de ['id','nombre','seleccionado' => ['1']|[]].
     */
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

    /**
     * Elige qué encuesta ofrecer en la vista pública del documento: usa la
     * que esté vinculada al documento si sigue activa, y si no cae a la
     * primera encuesta activa. Devuelve null cuando no hay nada que ofrecer.
     */
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

        // Fallback: primera encuesta activa (la "de satisfacción general").
        $stmt = $pdo->query('SELECT id FROM encuesta WHERE activa = 1 ORDER BY id LIMIT 1');
        $primera = $stmt->fetchColumn();
        return $primera !== false ? (int) $primera : null;
    }
}