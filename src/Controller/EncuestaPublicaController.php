<?php

declare(strict_types=1);

/**
 * EncuestaPublicaController: página pública para responder una encuesta sin
 * login (GET y POST a /publico/encuesta?id=N). La vista decide el estado con
 * flags: {{#es_404}}, {{#es_formulario}} o {{#es_gracias}}. La capa de datos
 * compartida con los demás controladores del módulo está en el trait
 * EncuestaData.
 */
final class EncuestaPublicaController
{
    use EncuestaData;

    /** Enrutador interno; index.php delega aquí toda ruta /publico/encuesta. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/publico/encuesta' && $method === 'GET' => self::publica(),
            $path === '/publico/encuesta' && $method === 'POST' => self::publicaResponder(),
            default => pagina_404(),
        };
    }

    /**
     * Muestra la encuesta activa para responder (GET a /publico/encuesta?id=N).
     * La vista decide el estado con flags: {{#es_404}}, {{#es_formulario}} o
     * {{#es_gracias}}.
     */
    public static function publica(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $resultado = $id > 0 ? self::cargarEncuestaPublica($id) : null;

        if ($resultado === null) {
            http_response_code(404);
            self::renderVistaPublica(['es_404' => ['1']]);
            return;
        }
        self::renderVistaPublica(self::datosVistaFormulario($resultado));
    }

    /**
     * Guarda las respuestas enviadas (POST a /publico/encuesta?id=N). Inserta
     * una fila en respuesta por cada pregunta respondida; todas comparten el
     * mismo sesion_token (la sesión anónima del envío).
     */
    public static function publicaResponder(): void
    {
        $id = (int) ($_POST['encuesta_id'] ?? 0);
        $resultado = $id > 0 ? self::cargarEncuestaPublica($id) : null;

        if ($resultado === null) {
            http_response_code(404);
            pagina_404();
            return;
        }

        $pdo = db_connect();
        $respuestasInput = (array) ($_POST['respuestas'] ?? []);
        // Índice posicional: respuestas[i] corresponde a la i-ésima pregunta.
        $respuestasPosicionales = array_values($respuestasInput);

        $faltanRequeridas = false;
        try {
            $pdo->beginTransaction();

            $tokenSesion = bin2hex(random_bytes(16));

            $stmtResp = $pdo->prepare(
                'INSERT INTO respuesta
                 (encuesta_id, sesion_token, pregunta_id, valor_opcion, valor_texto, valor_numerico)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($resultado['preguntas'] as $i => $p) {
                $valorRaw = trim((string) ($respuestasPosicionales[$i] ?? ''));

                if ($valorRaw === '') {
                    if ($p['requerida']) {
                        $faltanRequeridas = true;
                    }
                    continue;
                }

                $valorOpcion = null;
                $valorTexto = null;
                $valorNumerico = null;

                if ($p['tipo'] === 'multiple_choice') {
                    // El formulario manda el ID real de pregunta_opcion.
                    $valorOpcion = ctype_digit($valorRaw) && isset($p['opciones'][$valorRaw]) ? (int) $valorRaw : null;
                    if ($valorOpcion === null) {
                        throw new RuntimeException('Seleccionó una opción válida.');
                    }
                } elseif ($p['tipo'] === 'escala') {
                    $valorNumerico = (int) $valorRaw;
                    if ($valorNumerico < 1 || $valorNumerico > 5) {
                        throw new RuntimeException('La valoración debe estar entre 1 y 5.');
                    }
                } else { // texto_libre
                    $valorTexto = mb_substr($valorRaw, 0, 500);
                }

                $stmtResp->execute([$id, $tokenSesion, $p['id'], $valorOpcion, $valorTexto, $valorNumerico]);
            }

            // Faltan preguntas requeridas → deshace todo y vuelve al formulario.
            if ($faltanRequeridas) {
                throw new InvalidArgumentException('Faltan responder algunas preguntas obligatorias.');
            }

            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            self::rollbackSiActivo($pdo);
            self::renderVistaPublica(self::datosVistaFormulario($resultado, $e->getMessage()));
            return;
        } catch (Throwable $e) {
            self::rollbackSiActivo($pdo);
            self::renderVistaPublica(self::datosVistaFormulario($resultado, 'Ocurrió un error al guardar tus respuestas. Probá de nuevo.'));
            return;
        }

        self::renderVistaPublica(['es_gracias' => ['1']]);
    }

    /** Renderiza la vista pública con los datos del estado correspondiente. */
    private static function renderVistaPublica(array $datos): void
    {
        render_vista(__DIR__ . '/../../views/publico/encuesta.html', $datos + ['titulo' => 'Encuesta']);
    }

    /**
     * Arma los datos de la vista pública en estado formulario: una fila por
     * pregunta con sus flags de tipo ({{#fila.escala}}, {{#fila.multiple}},
     * {{#fila.es_texto}}) ya escapados. $error llega del envío fallido.
     */
    private static function datosVistaFormulario(array $resultado, string $error = ''): array
    {
        $preguntas = [];
        foreach (array_values($resultado['preguntas']) as $i => $p) {
            $item = [
                'indice' => $i,
                'numero' => $i + 1,
                'texto' => htmlspecialchars((string) $p['texto']),
                'opcional_html' => $p['requerida'] ? '' : ' <small class="text-muted fw-normal">(opcional)</small>',
                'req' => $p['requerida'] ? ' required' : '',
                'escala' => [],
                'valores' => [],
                'multiple' => [],
                'opciones' => [],
                'es_texto' => [],
            ];

            if ($p['tipo'] === 'escala') {
                $item['escala'] = ['1'];
                $item['valores'] = array_map(fn($v) => ['val' => $v], range(1, 5));
            } elseif ($p['tipo'] === 'multiple_choice') {
                // El formulario manda el ID real de pregunta_opcion.
                $item['multiple'] = ['1'];
                $item['opciones'] = array_map(
                    fn($oid, $otexto) => ['oid' => (string) $oid, 'otexto' => htmlspecialchars((string) $otexto)],
                    array_keys($p['opciones']),
                    array_values($p['opciones'])
                );
            } else { // texto_libre
                $item['es_texto'] = ['1'];
            }

            $preguntas[] = $item;
        }

        return [
            'es_404' => [],
            'es_gracias' => [],
            'es_formulario' => ['1'],
            'hay_error' => $error !== '' ? ['1'] : [],
            'error' => htmlspecialchars($error),
            'titulo' => htmlspecialchars((string) $resultado['titulo']),
            'descripcion' => htmlspecialchars((string) $resultado['descripcion']),
            'tiene_descripcion' => $resultado['descripcion'] !== '' ? ['1'] : [],
            'tiene_preguntas' => $preguntas === [] ? [] : ['1'],
            'encuesta_id' => (string) ($_GET['id'] ?? 0),
            'preguntas' => $preguntas,
        ];
    }

    /**
     * Carga una encuesta ACTIVA con sus preguntas y opciones, lista para el
     * formulario público. Devuelve null si no existe o no está activa.
     */
    private static function cargarEncuestaPublica(int $id): ?array
    {
        $pdo = db_connect();

        $stmtE = $pdo->prepare('SELECT titulo, descripcion FROM encuesta WHERE id = ? AND activa = 1');
        $stmtE->execute([$id]);
        $encuesta = $stmtE->fetch();
        if (!$encuesta) {
            return null;
        }

        return [
            'titulo'      => $encuesta['titulo'],
            'descripcion' => (string) ($encuesta['descripcion'] ?? ''),
            'preguntas'   => self::preguntasConOpciones($pdo, $id, true),
        ];
    }
}