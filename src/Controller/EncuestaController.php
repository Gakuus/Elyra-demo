<?php

declare(strict_types=1);

/**
 * EncuestaController: controlador del módulo de encuestas generales.
 * Adaptado a la arquitectura simple de esta demo (métodos estáticos +
 * vistas HTML con marcadores {{}}) y al esquema normalizado de la base
 * de datos, donde las opciones de una pregunta viven en la tabla
 * pregunta_opcion y las respuestas se guardan en respuesta_sesion +
 * respuesta_pregunta.
 *
 * Funcionalidad:
 *   - Listado de encuestas (con toggle activa/inactiva y acciones).
 *   - Creación con preguntas dinámicas (opción múltiple, escala 1-5, texto).
 *   - Publicar / despublicar (activar/desactivar).
 *   - Resultados con estadísticas y gráficos (Chart.js).
 *   - Página pública para responder (la que abren los pacientes por QR/enlace).
 */
final class EncuestaController
{
    /**
     * Enrutador interno del módulo de encuestas.
     * index.php delega acá toda ruta que empiece con /encuestas o
     * /publico/encuesta.
     *
     * @param string $path   Ruta (ej: '/encuestas/crear').
     * @param string $method Método HTTP ('GET' o 'POST').
     */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/encuestas' && $method === 'GET' => self::listar(),
            $path === '/encuestas/crear' && $method === 'GET' => self::formulario(),
            $path === '/encuestas/crear' && $method === 'POST' => self::crear(),
            $path === '/encuestas/editar' && $method === 'GET' => self::formularioEditar(),
            $path === '/encuestas/editar' && $method === 'POST' => self::editar(),
            $path === '/encuestas/toggle' && $method === 'POST' => self::toggle(),
            $path === '/encuestas/resultados' && $method === 'GET' => self::resultados(),
            $path === '/publico/encuesta' && $method === 'GET' => self::publica(),
            $path === '/publico/encuesta' && $method === 'POST' => self::publicaResponder(),
            default => pagina_404(),
        };
    }

    // ================================================================
    // LISTADO
    // ================================================================

    /**
     * Listado de encuestas (GET a /encuestas). Muestra tabla con título,
     * cantidad de preguntas, switch de estado y acciones (resultados,
     * copiar enlace público).
     */
    public static function listar(): void
    {
        requerir_login();

        $pdo = db_connect();

        // Una fila por encuesta; subconsultas cuentan preguntas y respuestas.
        $stmt = $pdo->query("
            SELECT e.id, e.titulo, e.descripcion, e.activa, e.created_at,
                   (SELECT COUNT(*) FROM pregunta p WHERE p.encuesta_id = e.id) AS cant_preguntas,
                   (SELECT COUNT(DISTINCT rs.id)
                      FROM respuesta_sesion rs
                     WHERE rs.encuesta_id = e.id) AS cant_respuestas
            FROM encuesta e
            ORDER BY e.created_at DESC
        ");
        $encuestas = $stmt->fetchAll();

        $filas = '';
        foreach ($encuestas as $fila) {
            $id = (int) $fila['id'];
            $activa = (bool) $fila['activa'];
            $creada = date('d/m/Y', (int) strtotime($fila['created_at']));
            $titulo = htmlspecialchars($fila['titulo']);
            $descripcion = htmlspecialchars($fila['descripcion'] ?? '');

            // Switch para activar/despublicar la encuesta (solo staff).
            $switch = '<div class="form-check form-switch mb-0">'
                . '<input class="form-check-input" type="checkbox" role="switch"'
                . ' id="toggle-' . $id . '"' . ($activa ? ' checked' : '')
                . ' data-encuesta-id="' . $id . '">'
                . '<label class="form-check-label small" for="toggle-' . $id . '">'
                . ($activa ? 'Activa' : 'Inactiva') . '</label></div>';

            // El enlace público se arma en el navegador (ver encuestas.js):
            // usar APP_URL del .env acá daba URLs con host/puerto equivocado
            // y por eso los enlaces copiados "no abrían".
            $filas .= '<tr>'
                . '<td><div class="fw-semibold">' . $titulo . '</div>'
                . '<small class="text-muted">' . $descripcion . '</small></td>'
                . '<td>' . (int) $fila['cant_preguntas'] . '</td>'
                . '<td>' . (int) $fila['cant_respuestas'] . '</td>'
                . '<td>' . $switch . '</td>'
                . '<td>' . htmlspecialchars($creada) . '</td>'
                . '<td style="white-space:nowrap;">'
                . '<button type="button" class="btn btn-sm" title="Ver QR p&uacute;blico"'
                . ' onclick="ElyraEnc.verQR(' . $id . ')"><i class="bi bi-qr-code"></i></button> '
                . '<a href="' . base_path() . '/encuestas/resultados?id=' . $id . '"'
                . ' class="btn btn-sm" title="Ver resultados"><i class="bi bi-bar-chart"></i></a> '
                . '<a href="' . base_path() . '/encuestas/editar?id=' . $id . '"'
                . ' class="btn btn-sm" title="Editar"><i class="bi bi-pencil"></i></a> '
                . '<button type="button" class="btn btn-sm' . ($activa ? '' : ' text-success') . '"'
                . ' title="' . ($activa ? 'Desactivar' : 'Reactivar') . '"'
                . ' onclick="ElyraEnc.cambiarEstado(' . $id . ', ' . ($activa ? '0' : '1') . ', this)">'
                . '<i class="bi bi-' . ($activa ? 'toggle-off' : 'arrow-counterclockwise') . '"></i></button> '
                . '<button type="button" class="btn btn-sm" title="Copiar enlace p&uacute;blico"'
                . ' onclick="ElyraEnc.copiarEnlace(' . $id . ', this)"><i class="bi bi-link-45deg"></i></button>'
                . '</td></tr>';
        }

        $contenido = $filas === ''
            ? '<div class="text-center text-muted p-4" style="font-size:15px;">'
                . '<i class="bi bi-bar-chart d-block mb-2" style="font-size:32px;"></i>No hay encuestas.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>T&iacute;tulo</th><th>Preguntas</th><th>Respuestas</th><th>Estado</th>'
                . '<th>Creada</th><th style="width:170px;">Acciones</th>'
                . '</tr></thead><tbody>' . $filas . '</tbody></table></div>';

        // Avisos de éxito tras crear o guardar cambios.
        $aviso = '';
        if (isset($_GET['creada'])) {
            $aviso = '<div class="alert alert-success py-2" style="font-size:13px;">'
                . '<i class="bi bi-check-lg me-1"></i>Encuesta creada correctamente.</div>';
        } elseif (isset($_GET['editada'])) {
            $aviso = '<div class="alert alert-success py-2" style="font-size:13px;">'
                . '<i class="bi bi-check-lg me-1"></i>Cambios guardados.</div>';
        }

        render_dashboard('encuestas', 'Encuestas generales', 'encuestas', [
            'contenido_encuestas' => $contenido,
            'aviso_encuestas' => $aviso,
        ]);
    }

    // ================================================================
    // CREACIÓN
    // ================================================================

    /**
     * Formulario de creación (GET a /encuestas/crear).
     */
    public static function formulario(): void
    {
        requerir_login();
        render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', [
            'error' => '',
        ]);
    }

    /**
     * Guarda la encuesta con sus preguntas y opciones (POST a /encuestas/crear),
     * con consultas PDO directas sobre el esquema normalizado
     * (pregunta_opcion como tabla aparte).
     */
    public static function crear(): void
    {
        requerir_login();

        $pdo = db_connect();

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        /** @var array<int, array<string, mixed>> $preguntasInput */
        $preguntasInput = (array) ($_POST['preguntas'] ?? []);

        // ==== Validaciones: título dentro de rango y preguntas bien formadas ====
        $errores = [];
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $errores[] = 'El t&iacute;tulo debe tener entre 3 y 200 caracteres.';
        }

        $normalizado = self::normalizarPreguntas($preguntasInput);
        $preguntasData = $normalizado['datos'];
        $errores = array_merge($errores, $normalizado['errores']);

        if ($preguntasData === []) {
            $errores[] = 'Agreg&aacute; al menos una pregunta v&aacute;lida.';
        }

        if ($errores !== []) {
            render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', [
                'error' => '<div class="alert alert-danger py-2" style="font-size:13px;"><ul class="mb-0 ps-3">'
                    . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errores))
                    . '</ul></div>',
            ]);
            return;
        }

        // ==== Inserción transaccional: encuesta → preguntas → opciones ====
        try {
            $pdo->beginTransaction();

            $stmtEnc = $pdo->prepare(
                'INSERT INTO encuesta (titulo, descripcion, activa, creada_por)
                 VALUES (?, ?, 1, ?)'
            );
            $stmtEnc->execute([$titulo, $descripcion !== '' ? $descripcion : null, (int) ($_SESSION['usuario_id'] ?? 0)]);
            $encuestaId = (int) $pdo->lastInsertId();

            $stmtPreg = $pdo->prepare(
                'INSERT INTO pregunta (encuesta_id, tipo, texto, requerida, `orden`)
                 VALUES (?, ?, ?, 1, ?)'
            );
            $stmtOpc = $pdo->prepare(
                'INSERT INTO pregunta_opcion (pregunta_id, texto, `orden`) VALUES (?, ?, ?)'
            );

            foreach ($preguntasData as $orden => $pd) {
                $stmtPreg->execute([$encuestaId, $pd['tipo'], $pd['texto'], $orden]);
                $preguntaId = (int) $pdo->lastInsertId();

                if ($pd['opciones'] !== null) {
                    foreach ($pd['opciones'] as $iOp => $opTexto) {
                        $stmtOpc->execute([$preguntaId, $opTexto, $iOp]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', [
                'error' => '<div class="alert alert-danger py-2" style="font-size:13px;">Error al guardar la encuesta.</div>',
            ]);
            return;
        }

        header('Location: ' . base_path() . '/encuestas?creada=1');
        exit;
    }

    // ================================================================
    // EDICIÓN
    // ================================================================

    /**
     * Formulario de edición (GET a /encuestas/editar?id=N).
     * Precarga título, descripción y preguntas con sus opciones; el editor
     * dinámico (encuesta-formulario.js) arranca desde esos datos.
     */
    public static function formularioEditar(): void
    {
        requerir_login();

        $id = (int) ($_GET['id'] ?? 0);
        $pdo = db_connect();

        $stmtE = $pdo->prepare('SELECT * FROM encuesta WHERE id = ?');
        $stmtE->execute([$id]);
        $encuesta = $stmtE->fetch();
        if (!$encuesta) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        self::renderFormularioEdicion($pdo, $id, [
            'valor_titulo' => htmlspecialchars((string) $encuesta['titulo']),
            'valor_descripcion' => htmlspecialchars((string) ($encuesta['descripcion'] ?? '')),
        ]);
    }

    /**
     * Guarda los cambios de una encuesta (POST a /encuestas/editar).
     * Sincroniza transaccionalmente:
     *   - título y descripción;
     *   - preguntas existentes (texto/tipo/orden y sus opciones);
     *   - preguntas nuevas;
     *   - preguntas eliminadas (el FK en cascada borra sus respuestas).
     */
    public static function editar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        $stmtE = $pdo->prepare('SELECT * FROM encuesta WHERE id = ?');
        $stmtE->execute([$id]);
        $encuesta = $stmtE->fetch();
        if (!$encuesta) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        /** @var array<int, array<string, mixed>> $preguntasInput */
        $preguntasInput = (array) ($_POST['preguntas'] ?? []);

        $errores = [];
        if (strlen($titulo) < 3 || strlen($titulo) > 200) {
            $errores[] = 'El t&iacute;tulo debe tener entre 3 y 200 caracteres.';
        }

        $normalizado = self::normalizarPreguntas($preguntasInput);
        $preguntasData = $normalizado['datos'];
        $errores = array_merge($errores, $normalizado['errores']);

        if ($preguntasData === []) {
            $errores[] = 'La encuesta debe tener al menos una pregunta v&aacute;lida.';
        }

        if ($errores !== []) {
            self::renderFormularioEdicion($pdo, $id, [
                'error' => '<div class="alert alert-danger py-2" style="font-size:13px;"><ul class="mb-0 ps-3">'
                    . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errores))
                    . '</ul></div>',
                'valor_titulo' => htmlspecialchars($titulo),
                'valor_descripcion' => htmlspecialchars($descripcion),
            ]);
            return;
        }

        // ==== Sincronización transaccional ====
        try {
            $pdo->beginTransaction();

            $stmtUpdEnc = $pdo->prepare('UPDATE encuesta SET titulo = ?, descripcion = ? WHERE id = ?');
            $stmtUpdEnc->execute([$titulo, $descripcion !== '' ? $descripcion : null, $id]);

            // Preguntas actuales de la encuesta (las que llegaron del formulario
            // que NO estén acá se consideran eliminadas).
            $existentes = [];
            $stmtEx = $pdo->prepare('SELECT id FROM pregunta WHERE encuesta_id = ?');
            $stmtEx->execute([$id]);
            foreach ($stmtEx->fetchAll(PDO::FETCH_COLUMN) as $pidExistente) {
                $existentes[(int) $pidExistente] = true;
            }

            $stmtUpdPreg = $pdo->prepare(
                'UPDATE pregunta SET tipo = ?, texto = ?, `orden` = ? WHERE id = ? AND encuesta_id = ?'
            );
            $stmtDelOpc = $pdo->prepare('DELETE FROM pregunta_opcion WHERE pregunta_id = ?');
            $stmtInsPreg = $pdo->prepare(
                'INSERT INTO pregunta (encuesta_id, tipo, texto, requerida, `orden`) VALUES (?, ?, ?, 1, ?)'
            );
            $stmtInsOpc = $pdo->prepare(
                'INSERT INTO pregunta_opcion (pregunta_id, texto, `orden`) VALUES (?, ?, ?)'
            );

            $mantenidas = [];

            foreach ($preguntasData as $orden => $pd) {
                $pidExistente = (int) ($pd['id'] ?? 0);

                if ($pidExistente > 0 && isset($existentes[$pidExistente])) {
                    // Pregunta ya existente: actualizar texto/tipo/orden.
                    $stmtUpdPreg->execute([$pd['tipo'], $pd['texto'], $orden, $pidExistente, $id]);

                    // Opciones: se reemplazan por completo. Las respuestas viejas
                    // que apuntaban a opciones borradas quedan con valor_opcion NULL
                    // (FK ON DELETE SET NULL), preservando el historial.
                    $stmtDelOpc->execute([$pidExistente]);
                    if ($pd['opciones'] !== null) {
                        foreach ($pd['opciones'] as $iOp => $opTexto) {
                            $stmtInsOpc->execute([$pidExistente, $opTexto, $iOp]);
                        }
                    }

                    $mantenidas[] = $pidExistente;

                } else {
                    // Pregunta nueva: ignorar cualquier id recibido.
                    $stmtInsPreg->execute([$id, $pd['tipo'], $pd['texto'], $orden]);
                    $nuevaId = (int) $pdo->lastInsertId();

                    if ($pd['opciones'] !== null) {
                        foreach ($pd['opciones'] as $iOp => $opTexto) {
                            $stmtInsOpc->execute([$nuevaId, $opTexto, $iOp]);
                        }
                    }
                }
            }

            // Eliminar las preguntas que el formulario ya no incluye.
            // El FK respuesta_pregunta.pregunta_id ON DELETE CASCADE borra
            // también sus respuestas registradas.
            // OJO: array_diff preserva las claves del array izquierdo, así que
            // hay que iterar los VALORES (los ids), no array_keys().
            $aBorrar = array_diff(array_keys($existentes), $mantenidas);
            $stmtDelPreg = $pdo->prepare('DELETE FROM pregunta WHERE id = ?');
            foreach ($aBorrar as $borrarId) {
                $stmtDelPreg->execute([(int) $borrarId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::renderFormularioEdicion($pdo, $id, [
                'error' => '<div class="alert alert-danger py-2" style="font-size:13px;">'
                    . 'Error al guardar los cambios.</div>',
            ]);
            return;
        }

        header('Location: ' . base_path() . '/encuestas?editada=1');
        exit;
    }

    /**
     * Renderiza la vista de edición recargando las preguntas actuales de la BD
     * (así un error de validación no deja el editor desincronizado) y aplicando
     * overrides opcionales para título/descripción/error.
     *
     * @param array<string, string> $overrides
     */
    private static function renderFormularioEdicion(PDO $pdo, int $id, array $overrides): void
    {
        // Preguntas actuales agrupadas con sus opciones (para precargar el JS).
        $stmtP = $pdo->prepare(
            'SELECT p.id, p.tipo, p.texto, po.texto AS opcion_texto
             FROM pregunta p
             LEFT JOIN pregunta_opcion po ON po.pregunta_id = p.id
             WHERE p.encuesta_id = ?
             ORDER BY p.`orden`, po.`orden`'
        );
        $stmtP->execute([$id]);

        $lista = [];
        foreach ($stmtP->fetchAll() as $f) {
            $pid = (int) $f['id'];
            if (!isset($lista[$pid])) {
                $lista[$pid] = ['id' => $pid, 'tipo' => $f['tipo'], 'texto' => $f['texto'], 'opciones' => []];
            }
            if ($f['opcion_texto'] !== null) {
                $lista[$pid]['opciones'][] = $f['opcion_texto'];
            }
        }

        render_dashboard('encuestas_editar', 'Editar encuesta', 'encuestas', [
            'error' => $overrides['error'] ?? '',
            'encuesta_id' => (string) $id,
            'valor_titulo' => $overrides['valor_titulo'] ?? '',
            'valor_descripcion' => $overrides['valor_descripcion'] ?? '',
            'preguntas_json' => json_encode(array_values($lista), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /**
     * Valida y normaliza las preguntas recibidas del formulario dinámico.
     * Devuelve ['datos' => [...], 'errores' => [...]]. Cada dato conserva el
     * 'id' cuando viene de una pregunta existente (edición).
     *
     * @param array<int, array<string, mixed>> $preguntasInput
     * @return array{datos: list<array{id?: int, tipo: string, texto: string, opciones: list<string>|null}>, errores: list<string>}
     */
    private static function normalizarPreguntas(array $preguntasInput): array
    {
        $datos = [];
        $errores = [];

        foreach (array_values($preguntasInput) as $i => $p) {
            $texto = trim((string) ($p['texto'] ?? ''));
            $tipo = trim((string) (($p['tipo'] ?? 'texto_libre')));
            $idRecibido = (int) ($p['id'] ?? 0);

            if (strlen($texto) < 3) {
                $errores[] = 'La pregunta ' . ($i + 1) . ' debe tener al menos 3 caracteres.';
                continue;
            }
            if (!in_array($tipo, ['multiple_choice', 'escala', 'texto_libre'], true)) {
                $errores[] = 'Tipo inv&aacute;lido en la pregunta ' . ($i + 1) . '.';
                continue;
            }

            $opciones = null;
            if ($tipo === 'multiple_choice') {
                $raw = array_map(fn($o) => trim((string) $o), (array) ($p['opciones'] ?? []));
                $opciones = array_values(array_filter($raw, fn($o) => $o !== ''));
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

        return ['datos' => $datos, 'errores' => $errores];
    }

    // ================================================================
    // PUBLICAR / DESPUBLICAR
    // ================================================================

    /**
     * Activa o desactiva una encuesta (POST a /encuestas/toggle):
     * publicarla para que los pacientes puedan responderla o sacarla
     * del aire sin borrar nada.
     */
    public static function toggle(): void
    {
        requerir_login();

        $id = (int) ($_POST['id'] ?? 0);
        $activa = (($_POST['activa'] ?? '') === '1');

        if ($id > 0) {
            $pdo = db_connect();
            $stmt = $pdo->prepare('UPDATE encuesta SET activa = ? WHERE id = ?');
            $stmt->execute([$activa ? 1 : 0, $id]);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ================================================================
    // RESULTADOS
    // ================================================================

    /**
     * Resultados de una encuesta (GET a /encuestas/resultados?id=N).
     * Calcula estadísticas por pregunta (conteo por opción, promedio de
     * escala, textos libres) y las expone al JS de Chart.js.
     */
    public static function resultados(): void
    {
        requerir_login();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        $pdo = db_connect();

        $stmtE = $pdo->prepare('SELECT * FROM encuesta WHERE id = ?');
        $stmtE->execute([$id]);
        $encuesta = $stmtE->fetch();
        if (!$encuesta) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        // Preguntas ordenadas con todas sus opciones en una sola consulta.
        $stmtP = $pdo->prepare(
            'SELECT p.id, p.tipo, p.texto, po.id AS opcion_id, po.texto AS opcion_texto, po.orden AS opcion_orden
             FROM pregunta p
             LEFT JOIN pregunta_opcion po ON po.pregunta_id = p.id
             WHERE p.encuesta_id = ?
             ORDER BY p.`orden`, po.`orden`'
        );
        $stmtP->execute([$id]);
        $filas = $stmtP->fetchAll();

        // Arma el array de preguntas agrupando las opciones por pregunta.
        $preguntas = [];
        foreach ($filas as $f) {
            $pid = (int) $f['id'];
            if (!isset($preguntas[$pid])) {
                $preguntas[$pid] = [
                    'id' => $pid,
                    'tipo' => $f['tipo'],
                    'texto' => $f['texto'],
                    'opciones' => [], // mapa opcion_id => texto
                ];
            }
            if ($f['opcion_id'] !== null) {
                $preguntas[$pid]['opciones'][(int) $f['opcion_id']] = $f['opcion_texto'];
            }
        }
        $preguntas = array_values($preguntas);

        // Total de personas que respondieron (sesiones únicas de esta encuesta).
        $stmtT = $pdo->prepare('SELECT COUNT(*) FROM respuesta_sesion WHERE encuesta_id = ?');
        $stmtT->execute([$id]);
        $totalRespuestas = (int) $stmtT->fetchColumn();

        // Estadísticas por pregunta.
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
                // Conteo por opción usando los IDs reales de pregunta_opcion.
                $stat['conteo'] = array_fill_keys(array_values($p['opciones']), 0);
                $stmtC = $pdo->prepare(
                    'SELECT po.texto AS opcion, COUNT(*) AS cant
                     FROM respuesta_pregunta rp
                     JOIN pregunta_opcion po ON po.id = rp.valor_opcion
                     WHERE rp.pregunta_id = ?
                     GROUP BY po.id, po.texto'
                );
                $stmtC->execute([$p['id']]);
                foreach ($stmtC->fetchAll() as $c) {
                    $stat['conteo'][$c['opcion']] = (int) $c['cant'];
                }
                $stat['total'] = array_sum($stat['conteo']);

            } elseif ($p['tipo'] === 'escala') {
                $stat['conteo'] = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
                $stmtC = $pdo->prepare(
                    'SELECT valor_numerico AS val, COUNT(*) AS cant
                     FROM respuesta_pregunta
                     WHERE pregunta_id = ? AND valor_numerico IS NOT NULL
                     GROUP BY valor_numerico'
                );
                $stmtC->execute([$p['id']]);
                $suma = 0;
                foreach ($stmtC->fetchAll() as $c) {
                    $val = (string) (int) $c['val'];
                    if (isset($stat['conteo'][$val])) {
                        $stat['conteo'][$val] = (int) $c['cant'];
                        $suma += (int) $val * (int) $c['cant'];
                        $stat['total'] += (int) $c['cant'];
                    }
                }
                $stat['promedio'] = $stat['total'] > 0 ? round($suma / $stat['total'], 1) : 0;

            } else { // texto_libre
                $stmtC = $pdo->prepare(
                    'SELECT valor_texto
                     FROM respuesta_pregunta
                     WHERE pregunta_id = ? AND valor_texto IS NOT NULL AND TRIM(valor_texto) <> ""
                     ORDER BY created_at'
                );
                $stmtC->execute([$p['id']]);
                $stat['textosLibres'] = array_map(fn($r) => $r['valor_texto'], $stmtC->fetchAll());
                $stat['total'] = count($stat['textosLibres']);
            }

            $stats[] = $stat;
        }

        render_dashboard('encuestas_resultados', 'Resultados — ' . $encuesta['titulo'], 'encuestas', [
            'encuesta_titulo' => htmlspecialchars($encuesta['titulo']),
            'encuesta_descripcion' => htmlspecialchars($encuesta['descripcion'] ?? ''),
            'cant_preguntas' => (string) count($preguntas),
            'total_respuestas' => (string) $totalRespuestas,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'bloques_resultados' => self::bloquesResultados($stats),
        ]);
    }

    /**
     * Genera el HTML de cada bloque de resultado (uno por pregunta).
     * Los gráficos de barras/torta los dibuja Chart.js sobre <canvas>.
     *
     * @param array<int, array<string, mixed>> $stats
     */
    private static function bloquesResultados(array $stats): string
    {
        if ($stats === []) {
            return '';
        }

        $html = '';
        foreach ($stats as $i => $s) {
            $num = $i + 1;
            $html .= '<div class="panel mb-3"><div class="cuerpo-panel">';
            $html .= '<h6 class="fw-semibold mb-3">' . $num . '. ' . htmlspecialchars((string) $s['texto']) . '</h6>';

            if ($s['tipo'] === 'multiple_choice') {
                $alto = max(200, count($s['opciones']) * 50);
                $html .= '<div style="position:relative;height:' . $alto . 'px;">'
                    . '<canvas id="chart-' . $i . '"></canvas></div>';

            } elseif ($s['tipo'] === 'escala') {
                $html .= '<div class="row g-3"><div class="col-md-8">'
                    . '<div style="position:relative;height:220px;"><canvas id="chart-' . $i . '"></canvas></div>'
                    . '</div><div class="col-md-4 d-flex flex-column justify-content-center">'
                    . '<div class="text-center p-3 bg-light rounded-3">'
                    . '<div class="display-5 fw-bold text-primary">' . $s['promedio'] . '</div>'
                    . '<div class="text-muted small">Promedio (1-5)</div></div>'
                    . '<div class="mt-2 text-center small text-muted">';
                foreach ($s['conteo'] as $val => $cant) {
                    $html .= '<span class="me-2">' . $val . ': ' . $cant . '</span>';
                }
                $html .= '</div></div></div>';

            } else { // texto_libre
                if ($s['textosLibres'] === []) {
                    $html .= '<p class="text-muted small mb-0">Sin respuestas de texto.</p>';
                } else {
                    $html .= '<ul class="list-group list-group-flush">';
                    foreach ($s['textosLibres'] as $t) {
                        $html .= '<li class="list-group-item py-2 px-0 border-0 border-bottom">'
                            . '<i class="bi bi-quote text-muted me-1"></i>' . htmlspecialchars((string) $t)
                            . '</li>';
                    }
                    $html .= '</ul>';
                }
            }

            $html .= '</div></div>';
        }
        return $html;
    }

    // ================================================================
    // ENCUESTA PÚBLICA (pacientes, sin login)
    // ================================================================

    /**
     * Muestra la encuesta activa para responder (GET a /publico/encuesta?id=N).
     * No requiere sesión: es la página que abren escaneando el QR o el enlace.
     */
    public static function publica(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $resultado = $id > 0 ? self::cargarEncuestaPublica($id) : null;

        if ($resultado === null) {
            http_response_code(404);
            render_vista(__DIR__ . '/../../views/publico/encuesta.html', [
                'titulo'      => 'Encuesta',
                'estado'      => '404',
                'contenido_publico' => '<div class="panel"><div class="cuerpo-panel text-center py-5">'
                    . '<div style="font-size:40px;color:#98A6C4;"><i class="bi bi-question-circle"></i></div>'
                    . '<h4 class="fw-semibold">Encuesta no encontrada</h4>'
                    . '<p class="text-muted">La encuesta no existe o no est&aacute; disponible.</p>'
                    . '</div></div>',
            ]);
            return;
        }

        render_vista(__DIR__ . '/../../views/publico/encuesta.html', [
            'titulo'      => $resultado['titulo'],
            'estado'      => 'formulario',
            'contenido_publico' => self::htmlFormularioPublico($resultado),
        ]);
    }

    /**
     * Guarda las respuestas enviadas (POST a /publico/encuesta?id=N).
     * Crea una sesión de respuesta (respuesta_sesion) y guarda cada valor en
     * respuesta_pregunta.
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
        /** @var array<string, mixed> $respuestasInput */
        $respuestasInput = (array) ($_POST['respuestas'] ?? []);
        // Índice posicional: respuestas[i] corresponde a la i-ésima pregunta
        // en orden.
        $respuestasPosicionales = array_values($respuestasInput);

        $erroresValidacion = [];

        try {
            $pdo->beginTransaction();

            // Sesión anónima: token aleatorio único por envío.
            $tokenSesion = bin2hex(random_bytes(16));
            $stmtSesion = $pdo->prepare(
                'INSERT INTO respuesta_sesion (encuesta_id, sesion_token) VALUES (?, ?)'
            );
            $stmtSesion->execute([$id, $tokenSesion]);
            $sesionId = (int) $pdo->lastInsertId();

            $stmtResp = $pdo->prepare(
                'INSERT INTO respuesta_pregunta
                 (respuesta_id, pregunta_id, valor_opcion, valor_texto, valor_numerico)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($resultado['preguntas'] as $i => $p) {
                $valorRaw = trim((string) ($respuestasPosicionales[$i] ?? ''));

                if ($valorRaw === '') {
                    if ($p['requerida']) {
                        $erroresValidacion[] = $p['texto'];
                    }
                    continue;
                }

                $valorOpcion = null;
                $valorTexto = null;
                $valorNumerico = null;

                if ($p['tipo'] === 'multiple_choice') {
                    // El formulario manda el ID real de pregunta_opcion.
                    $valorOpcion = ctype_digit($valorRaw) && isset($p['opciones'][$valorRaw])
                        ? (int) $valorRaw
                        : null;
                    if ($valorOpcion === null) {
                        throw new RuntimeException('Opción inválida.');
                    }
                } elseif ($p['tipo'] === 'escala') {
                    $valorNumerico = (int) $valorRaw;
                    if ($valorNumerico < 1 || $valorNumerico > 5) {
                        throw new RuntimeException('Valor de escala fuera de rango.');
                    }
                } else { // texto_libre
                    $valorTexto = mb_substr($valorRaw, 0, 500);
                }

                $stmtResp->execute([$sesionId, $p['id'], $valorOpcion, $valorTexto, $valorNumerico]);
            }

            // Faltan preguntas requeridas → deshace todo y vuelve al formulario.
            if ($erroresValidacion !== []) {
                throw new InvalidArgumentException('Faltan preguntas requeridas.');
            }

            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            render_vista(__DIR__ . '/../../views/publico/encuesta.html', [
                'titulo'      => $resultado['titulo'],
                'estado'      => 'formulario',
                'contenido_publico' => self::htmlFormularioPublico($resultado, $e->getMessage()),
            ]);
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            render_vista(__DIR__ . '/../../views/publico/encuesta.html', [
                'titulo'      => $resultado['titulo'],
                'estado'      => 'formulario',
                'contenido_publico' => self::htmlFormularioPublico($resultado, 'Ocurri&oacute; un error al guardar tus respuestas. Prob&aacute; de nuevo.'),
            ]);
            return;
        }

        // Envío correcto → pantalla de agradecimiento.
        render_vista(__DIR__ . '/../../views/publico/encuesta.html', [
            'titulo'      => $resultado['titulo'],
            'estado'      => 'gracias',
            'contenido_publico' => '<div class="panel text-center py-5 px-4">'
                . '<div style="font-size:36px;color:#198754;margin-bottom:10px;"><i class="bi bi-check-circle-fill"></i></div>'
                . '<h4 class="fw-semibold">&iexcl;Gracias por tu opini&oacute;n!</h4>'
                . '<p class="text-muted mb-0">Tu respuesta ha sido registrada correctamente.</p>'
                . '</div>',
        ]);
    }

    // ================================================================
    // HELPERS PRIVADOS
    // ================================================================

    /**
     * Carga una encuesta ACTIVA con sus preguntas y opciones, lista para
     * mostrar el formulario público. Devuelve null si no existe o no está
     * activa.
     *
     * @return array{titulo: string, descripcion: string, preguntas: list<array{id: int, tipo: string, texto: string, requerida: bool, opciones: array<int, string>}>}|null
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

        $stmtP = $pdo->prepare(
            'SELECT p.id, p.tipo, p.texto, p.requerida, po.id AS opcion_id, po.texto AS opcion_texto
             FROM pregunta p
             LEFT JOIN pregunta_opcion po ON po.pregunta_id = p.id
             WHERE p.encuesta_id = ?
             ORDER BY p.`orden`, po.`orden`'
        );
        $stmtP->execute([$id]);

        $preguntas = [];
        foreach ($stmtP->fetchAll() as $f) {
            $pid = (int) $f['id'];
            if (!isset($preguntas[$pid])) {
                $preguntas[$pid] = [
                    'id'       => $pid,
                    'tipo'     => $f['tipo'],
                    'texto'    => $f['texto'],
                    'requerida' => (bool) $f['requerida'],
                    'opciones' => [],
                ];
            }
            if ($f['opcion_id'] !== null) {
                $preguntas[$pid]['opciones'][(int) $f['opcion_id']] = $f['opcion_texto'];
            }
        }

        return [
            'titulo'      => $encuesta['titulo'],
            'descripcion' => (string) ($encuesta['descripcion'] ?? ''),
            'preguntas'   => array_values($preguntas),
        ];
    }

    /**
     * Arma el HTML del formulario público de respuestas.
     *
     * @param array{titulo: string, descripcion: string, preguntas: list<array{id: int, tipo: string, texto: string, requerida: bool, opciones: array<int, string>}>} $resultado
     */
    private static function htmlFormularioPublico(array $resultado, string $error = ''): string
    {
        $html = '';

        if ($error !== '') {
            $html .= '<div class="alert alert-danger py-2" style="font-size:13px;">'
                . '<i class="bi bi-exclamation-triangle-fill me-1"></i>' . $error . '</div>';
        }

        $html .= '<div class="text-center mb-4">'
            . '<i class="bi bi-bar-chart" style="font-size:30px;color:#3B5998;"></i>'
            . '<h4 class="fw-semibold mt-2 mb-1">' . htmlspecialchars($resultado['titulo']) . '</h4>';
        if ($resultado['descripcion'] !== '') {
            $html .= '<p class="text-muted mb-0">' . htmlspecialchars($resultado['descripcion']) . '</p>';
        }
        $html .= '</div>';

        if ($resultado['preguntas'] === []) {
            return $html . '<p class="text-muted text-center mb-0">Esta encuesta no tiene preguntas.</p>';
        }

        $html .= '<form method="post" id="encuestaRespForm" novalidate>'
            . '<input type="hidden" name="encuesta_id" value="' . (int) ($_GET['id'] ?? 0) . '">';

        foreach ($resultado['preguntas'] as $i => $p) {
            $req = $p['requerida'] ? ' required' : '';
            $html .= '<div class="pregunta-publica mb-4 pb-3 border-bottom">';
            $html .= '<p class="fw-semibold mb-2">' . ($i + 1) . '. ' . htmlspecialchars($p['texto'])
                . (!$p['requerida'] ? ' <small class="text-muted fw-normal">(opcional)</small>' : '') . '</p>';

            if ($p['tipo'] === 'escala') {
                $html .= '<div class="d-flex gap-2 flex-wrap" role="group" aria-label="Escala de 1 a 5">';
                for ($v = 1; $v <= 5; $v++) {
                    $html .= '<label class="escala-label">'
                        . '<input type="radio" name="respuestas[' . $i . ']" value="' . $v . '"' . $req . '>'
                        . '<span>' . $v . '</span></label>';
                }
                $html .= '</div><small class="text-muted">1 = Muy malo &middot; 5 = Excelente</small>';

            } elseif ($p['tipo'] === 'multiple_choice' && $p['opciones'] !== []) {
                $html .= '<div class="d-flex flex-column gap-2">';
                foreach ($p['opciones'] as $opcionId => $opcionTexto) {
                    $html .= '<label class="mc-label d-flex align-items-center gap-2 p-2 rounded border">'
                        . '<input type="radio" name="respuestas[' . $i . ']" value="' . $opcionId . '"' . $req . '>'
                        . '<span>' . htmlspecialchars($opcionTexto) . '</span></label>';
                }
                $html .= '</div>';

            } else { // texto_libre
                $html .= '<textarea name="respuestas[' . $i . ']" rows="3" maxlength="500"'
                    . ' placeholder="Escrib&iacute; tu respuesta..."' . $req
                    . ' class="entrada-formulario" style="width:100%;"></textarea>';
            }

            $html .= '</div>';
        }

        $html .= '<button type="submit" class="btn btn-primary btn-lg w-100 mt-2">'
            . '<i class="bi bi-send me-1"></i> Enviar respuestas</button>';
        $html .= '</form>';

        return $html;
    }
}
