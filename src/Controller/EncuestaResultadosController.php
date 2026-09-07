<?php

declare(strict_types=1);

/**
 * EncuestaResultadosController: estadísticas de una encuesta (lo invoca
 * EncuestaController::dispatch para GET a /encuestas/resultados?id=N). Trae
 * todas las respuestas en una sola consulta, calcula los agregados por tipo
 * de pregunta y expone los datos al JS de Chart.js ({{stats}} de la vista +
 * ENCUESTA_STATS). La capa de datos compartida con los demás controladores
 * del módulo está en el trait EncuestaData.
 */
final class EncuestaResultadosController
{
    use EncuestaData;

    /**
     * Resultados de una encuesta (GET a /encuestas/resultados?id=N). Trae todas
     * las respuestas en una sola consulta, calcula estadísticas por pregunta y
     * expone los datos al JS de Chart.js. La vista arma el HTML de cada bloque
     * según el tipo con {{#fila.es_multiple}}, {{#fila.es_escala}} o
     * {{#fila.es_texto}}.
     */
    public static function resultados(): void
    {
        requerir_login();
        $id = (int) ($_GET['id'] ?? 0);
        $pdo = db_connect();

        $encuesta = self::obtenerEncuesta($pdo, $id);
        if ($id <= 0 || $encuesta === null) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        $preguntas = self::preguntasConOpciones($pdo, $id);
        $resultados = self::calcularResultados($pdo, $id, $preguntas);

        // Una fila por pregunta para los bloques de la vista. El id del canvas
        // usa el índice 0-based (chart-$i), coincidiendo con ENCUESTA_STATS.
        $filasStats = [];
        foreach (array_values($resultados['stats']) as $i => $s) {
            $esMultiple = $s['tipo'] === 'multiple_choice';
            $esEscala = $s['tipo'] === 'escala';
            $esTexto = $s['tipo'] === 'texto_libre';
            $filasStats[] = [
                'indice' => $i + 1,
                'texto' => htmlspecialchars((string) $s['texto']),
                'chart_id' => 'chart-' . $i,
                'es_multiple' => $esMultiple ? ['1'] : [],
                'alto' => max(200, count($s['opciones']) * 50),
                'es_escala' => $esEscala ? ['1'] : [],
                'promedio' => (string) $s['promedio'],
                'conteos' => array_map(
                    fn($val, $cant) => ['val' => $val, 'cant' => $cant],
                    array_keys($s['conteo']),
                    array_values($s['conteo'])
                ),
                'es_texto' => $esTexto ? ['1'] : [],
                'tiene_textos' => $s['textosLibres'] === [] ? [] : ['1'],
                'textos' => array_map(
                    fn($t) => ['respuesta' => htmlspecialchars((string) $t)],
                    $s['textosLibres']
                ),
            ];
        }

        render_dashboard('encuestas_resultados', 'Resultados — ' . $encuesta['titulo'], 'encuestas', [
            'encuesta_titulo' => htmlspecialchars($encuesta['titulo']),
            'encuesta_descripcion' => htmlspecialchars($encuesta['descripcion'] ?? ''),
            'cant_preguntas' => (string) count($preguntas),
            'total_respuestas' => (string) $resultados['total_sesiones'],
            'stats_json' => json_encode($resultados['stats'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'stats' => $filasStats,
        ]);
    }

    /**
     * Estadísticas por pregunta a partir de TODAS las respuestas de la encuesta
     * (una sola consulta en vez de una por pregunta). Devuelve también el total
     * de sesiones únicas que respondieron. Cada tipo de pregunta agrega distinto:
     * múltiple → conteo por opción, escala → conteo 1-5 + promedio, texto → listado.
     */
    private static function calcularResultados(PDO $pdo, int $encuestaId, array $preguntas): array
    {
        $stmt = $pdo->prepare(
            'SELECT rt.sesion_token, rt.pregunta_id, rt.valor_opcion, rt.valor_texto, rt.valor_numerico,
                    po.texto AS opcion_texto
             FROM respuesta rt
             LEFT JOIN pregunta_opcion po ON po.id = rt.valor_opcion
             WHERE rt.encuesta_id = ?
             ORDER BY rt.created_at'
        );
        $stmt->execute([$encuestaId]);
        $respuestas = $stmt->fetchAll();

        $totalSesiones = count(array_unique(array_column($respuestas, 'sesion_token')));

        $stats = [];
        foreach ($preguntas as $p) {
            $stat = [
                'preguntaId' => $p['id'],
                'texto' => $p['texto'],
                'tipo' => $p['tipo'],
                'opciones' => array_values($p['opciones']),
                'conteo' => [],
                'textosLibres' => [],
                'promedio' => 0,
                'total' => 0,
            ];

            if ($p['tipo'] === 'multiple_choice') {
                $stat['conteo'] = array_fill_keys(array_values($p['opciones']), 0);
                foreach ($respuestas as $r) {
                    if ((int) $r['pregunta_id'] === $p['id'] && $r['valor_opcion'] !== null) {
                        $opcion = (string) $r['opcion_texto'];
                        if (isset($stat['conteo'][$opcion])) {
                            $stat['conteo'][$opcion]++;
                        }
                    }
                }
                $stat['total'] = array_sum($stat['conteo']);

            } elseif ($p['tipo'] === 'escala') {
                $stat['conteo'] = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
                $suma = 0;
                foreach ($respuestas as $r) {
                    if ((int) $r['pregunta_id'] !== $p['id'] || $r['valor_numerico'] === null) {
                        continue;
                    }
                    $val = (int) $r['valor_numerico'];
                    if ($val < 1 || $val > 5) {
                        continue;
                    }
                    $stat['conteo'][(string) $val]++;
                    $suma += $val;
                    $stat['total']++;
                }
                $stat['promedio'] = $stat['total'] > 0 ? round($suma / $stat['total'], 1) : 0;

            } else { // texto_libre
                foreach ($respuestas as $r) {
                    if ((int) $r['pregunta_id'] === $p['id'] && trim((string) $r['valor_texto']) !== '') {
                        $stat['textosLibres'][] = $r['valor_texto'];
                    }
                }
                $stat['total'] = count($stat['textosLibres']);
            }

            $stats[] = $stat;
        }
        return ['stats' => $stats, 'total_sesiones' => $totalSesiones];
    }
}