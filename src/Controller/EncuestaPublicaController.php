<?php

declare(strict_types=1);

/**
 * EncuestaPublicaController: encuesta pública sin login (/publico/encuesta).
 * La vista decide el estado con flags {{#es_404}}, {{#es_formulario}}, {{#es_gracias}}.
 */
final class EncuestaPublicaController
{
    use EncuestaData;

    /** Enrutador interno: index.php delega acá /publico/encuesta. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/publico/encuesta' && $method === 'GET' => self::publica(),
            $path === '/publico/encuesta' && $method === 'POST' => self::publicaResponder(),
            default => pagina_404(),
        };
    }

    /** Muestra la encuesta activa para responder. */
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

    /** Guarda las respuestas: una fila por pregunta, todas con el mismo sesion_token. */
    public static function publicaResponder(): void
    {
        $id = (int) ($_POST['encuesta_id'] ?? 0);
        $resultado = $id > 0 ? self::cargarEncuestaPublica($id) : null;

        if ($resultado === null) {
            http_response_code(404);
            pagina_404();
            return;
        }

        // Anti abuso: la encuesta es anónima y pública; se limita por IP para
        // que nadie la llene en masa (no hay captcha).
        if (!rate_limit_permitido('encuesta:' . ip_usuario(), 20, 3600)) {
            log_seguridad('encuesta_bloqueada', ['encuesta_id' => $id]);
            http_response_code(429);
            self::renderVistaPublica(self::datosVistaFormulario($resultado, 'Recibimos demasiadas respuestas desde tu conexión. Probá más tarde.'));
            return;
        }

        $pdo = db_connect();
        $respuestasInput = (array) ($_POST['respuestas'] ?? []);
        // respuestas[i] corresponde a la i-ésima pregunta.
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

            // Faltan requeridas → deshace todo y vuelve al formulario.
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

    private static function renderVistaPublica(array $datos): void
    {
        render_vista(__DIR__ . '/../../views/publico/encuesta.html', $datos + ['titulo' => 'Encuesta']);
    }

    /** Datos de la vista en estado formulario: una fila por pregunta con sus flags. */
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

    /** Carga una encuesta ACTIVA con preguntas y opciones; null si no existe o está inactiva. */
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