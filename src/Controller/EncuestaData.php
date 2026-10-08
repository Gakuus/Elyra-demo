<?php

declare(strict_types=1);

/** EncuestaData: capa de datos compartida del módulo de encuestas. */
trait EncuestaData
{
    private static function obtenerEncuesta(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM encuesta WHERE id = ?');
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        return $fila !== false ? $fila : null;
    }

    /** Preguntas de la encuesta con opciones agrupadas [id => texto], en orden. */
    private static function preguntasConOpciones(PDO $pdo, int $encuestaId, bool $conRequerida = false): array
    {
        $seleccion = 'p.id, p.tipo, p.texto, po.id AS opcion_id, po.texto AS opcion_texto'
            . ($conRequerida ? ', p.requerida' : '');
        $stmt = $pdo->prepare(
            "SELECT $seleccion
             FROM pregunta p
             LEFT JOIN pregunta_opcion po ON po.pregunta_id = p.id
             WHERE p.encuesta_id = ?
             ORDER BY p.`orden`, po.`orden`"
        );
        $stmt->execute([$encuestaId]);

        $preguntas = [];
        foreach ($stmt->fetchAll() as $f) {
            $pid = (int) $f['id'];
            if (!isset($preguntas[$pid])) {
                $preguntas[$pid] = ['id' => $pid, 'tipo' => $f['tipo'], 'texto' => $f['texto'], 'opciones' => []];
                if ($conRequerida) {
                    $preguntas[$pid]['requerida'] = (bool) $f['requerida'];
                }
            }
            if ($f['opcion_id'] !== null) {
                $preguntas[$pid]['opciones'][(int) $f['opcion_id']] = $f['opcion_texto'];
            }
        }
        return array_values($preguntas);
    }

    /** Valida y normaliza el formulario (título + preguntas dinámicas). */
    private static function normalizarFormulario(string $titulo, array $preguntasInput, string $mensajeSinPreguntas): array
    {
        $errores = strlen($titulo) < 3 || strlen($titulo) > 200
            ? ['El título debe tener entre 3 y 200 caracteres.']
            : [];

        $datos = [];
        foreach (array_values($preguntasInput) as $i => $p) {
            $texto = trim((string) ($p['texto'] ?? ''));
            $tipo = trim((string) ($p['tipo'] ?? 'texto_libre'));
            $idRecibido = (int) ($p['id'] ?? 0);

            if (strlen($texto) < 3) {
                $errores[] = 'La pregunta ' . ($i + 1) . ' debe tener al menos 3 caracteres.';
                continue;
            }
            if (!in_array($tipo, ['multiple_choice', 'escala', 'texto_libre'], true)) {
                $errores[] = 'Tipo inválido en la pregunta ' . ($i + 1) . '.';
                continue;
            }

            $opciones = null;
            if ($tipo === 'multiple_choice') {
                $opciones = array_values(array_filter(
                    array_map(fn($o) => trim((string) $o), (array) ($p['opciones'] ?? [])),
                    fn($o) => $o !== ''
                ));
                if (count($opciones) < 2) {
                    $errores[] = 'La pregunta ' . ($i + 1) . ' necesita al menos 2 opciones.';
                    continue;
                }
            }

            $dato = ['tipo' => $tipo, 'texto' => $texto, 'opciones' => $opciones];
            if ($idRecibido > 0) {
                $dato['id'] = $idRecibido;
            }
            $datos[] = $dato;
        }

        if ($datos === []) {
            $errores[] = $mensajeSinPreguntas;
        }
        return ['datos' => $datos, 'errores' => $errores];
    }

    private static function htmlErrores(array $errores): string
    {
        return '<div class="alert alert-danger py-2 alert-chico"><ul class="mb-0 ps-3">'
            . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errores))
            . '</ul></div>';
    }

    private static function insertarOpciones(PDOStatement $stmt, int $preguntaId, ?array $opciones): void
    {
        if ($opciones === null) {
            return;
        }
        foreach ($opciones as $orden => $texto) {
            $stmt->execute([$preguntaId, $texto, $orden]);
        }
    }

    private static function rollbackSiActivo(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}