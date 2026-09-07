<?php

declare(strict_types=1);

/**
 * EncuestaController: panel del dashboard del módulo de encuestas.
 * Métodos estáticos + vistas HTML con marcadores {{}} y esquema normalizado:
 * las opciones de una pregunta viven en pregunta_opcion y cada respuesta es
 * una fila por pregunta respondida, agrupada en sesión anónima por sesion_token.
 *
 * Cubre el ABM del panel: listado (con toggle activa/inactiva), creación y
 * edición con preguntas dinámicas. Los resultados viven en
 * EncuestaResultadosController y la página pública (responder sin login) en
 * EncuestaPublicaController; la capa de datos compartida está en EncuestaData.
 */
final class EncuestaController
{
    use EncuestaData;

    /**
     * Enrutador interno del módulo; index.php delega aquí toda ruta /encuestas.
     * La página pública (/publico/encuesta) la enruta index.php directamente a
     * EncuestaPublicaController.
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
            $path === '/encuestas/resultados' && $method === 'GET' => EncuestaResultadosController::resultados(),
            default => pagina_404(),
        };
    }

    // ------------------------------------------------------------------
    // LISTADO
    // ------------------------------------------------------------------

    /**
     * Panel de encuestas (GET a /encuestas): tabla con título, cantidad de
     * preguntas y respuestas, switch activa/inactiva y acciones. La vista
     * arma el HTML de filas y estado vacío a partir de las filas que acá se
     * pasan (la plantilla repite el bloque {{#encuestas}} por cada una y
     * {{^hay_encuestas}} muestra el mensaje cuando no hay).
     */
    public static function listar(): void
    {
        requerir_login();
        $pdo = db_connect();

        // Una fila por encuesta; subconsultas cuentan preguntas y respuestas.
        $stmt = $pdo->query("
            SELECT e.id, e.titulo, e.descripcion, e.activa, e.created_at,
                   (SELECT COUNT(*) FROM pregunta p WHERE p.encuesta_id = e.id) AS cant_preguntas,
                   (SELECT COUNT(DISTINCT rt.sesion_token)
                      FROM respuesta rt
                     WHERE rt.encuesta_id = e.id) AS cant_respuestas
            FROM encuesta e
            ORDER BY e.created_at DESC
        ");

        // Cada fila se escapa acá; la vista solo la ubica en su lugar.
        $filas = [];
        foreach ($stmt->fetchAll() as $fila) {
            $filas[] = [
                'id' => (int) $fila['id'],
                'titulo' => htmlspecialchars((string) $fila['titulo']),
                'descripcion' => htmlspecialchars((string) ($fila['descripcion'] ?? '')),
                'cant_preguntas' => (string) (int) $fila['cant_preguntas'],
                'cant_respuestas' => (string) (int) $fila['cant_respuestas'],
                'creada' => htmlspecialchars(date('d/m/Y', (int) strtotime((string) $fila['created_at']))),
                'activa' => (bool) $fila['activa'] ? ['1'] : [],
            ];
        }

        // Aviso de éxito tras crear o guardar cambios.
        $aviso = isset($_GET['creada']) || isset($_GET['editada'])
            ? '<div class="alert alert-success py-2 alert-chico"><i class="bi bi-check-lg me-1"></i>'
                . (isset($_GET['creada']) ? 'Encuesta creada correctamente.' : 'Cambios guardados.')
                . '</div>'
            : '';

        render_dashboard('encuestas', 'Encuestas generales', 'encuestas', [
            'encuestas' => $filas,
            'hay_encuestas' => $filas === [] ? [] : ['1'],
            'aviso_encuestas' => $aviso,
        ]);
    }

    // ------------------------------------------------------------------
    // CREACIÓN
    // ------------------------------------------------------------------

    /** Formulario vacío para crear una encuesta (GET a /encuestas/crear). */
    public static function formulario(): void
    {
        requerir_login();
        render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', ['error' => '']);
    }

    /**
     * Guarda la encuesta con sus preguntas y opciones (POST a /encuestas/crear),
     * escribiendo sobre el esquema normalizado en una sola transacción.
     */
    public static function crear(): void
    {
        requerir_login();
        $pdo = db_connect();

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $preguntasInput = (array) ($_POST['preguntas'] ?? []);

        $validado = self::normalizarFormulario($titulo, $preguntasInput, 'Agregá al menos una pregunta válida.');
        if ($validado['errores'] !== []) {
            render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', [
                'error' => self::htmlErrores($validado['errores']),
            ]);
            return;
        }

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

            foreach ($validado['datos'] as $orden => $pd) {
                $stmtPreg->execute([$encuestaId, $pd['tipo'], $pd['texto'], $orden]);
                self::insertarOpciones($stmtOpc, (int) $pdo->lastInsertId(), $pd['opciones']);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            self::rollbackSiActivo($pdo);
            render_dashboard('encuestas_crear', 'Nueva encuesta', 'encuestas', [
                'error' => '<div class="alert alert-danger py-2 alert-chico">Error al guardar la encuesta.</div>',
            ]);
            return;
        }

        header('Location: ' . base_path() . '/encuestas?creada=1');
        exit;
    }

    // ------------------------------------------------------------------
    // EDICIÓN
    // ------------------------------------------------------------------

    /**
     * Formulario de edición (GET a /encuestas/editar?id=N). Precarga título,
     * descripción y preguntas con sus opciones para el editor dinámico.
     */
    public static function formularioEditar(): void
    {
        requerir_login();
        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        $encuesta = self::obtenerEncuesta($pdo, $id);
        if ($encuesta === null) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        self::renderFormularioEdicion($pdo, $id, [
            'valor_titulo' => htmlspecialchars((string) $encuesta['titulo']),
            'valor_descripcion' => htmlspecialchars((string) ($encuesta['descripcion'] ?? '')),
        ]);
    }

    /**
     * Guarda los cambios de una encuesta (POST a /encuestas/editar). En una
     * única transacción sincroniza título/descripción, preguntas existentes
     * (texto/tipo/orden y opciones), nuevas y eliminadas (el FK en cascada de
     * pregunta_opcion se lleva las opciones; respuesta usa ON DELETE SET NULL
     * para preservar el historial).
     */
    public static function editar(): void
    {
        requerir_login();
        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if (self::obtenerEncuesta($pdo, $id) === null) {
            header('Location: ' . base_path() . '/encuestas');
            exit;
        }

        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $preguntasInput = (array) ($_POST['preguntas'] ?? []);

        $validado = self::normalizarFormulario($titulo, $preguntasInput, 'La encuesta debe tener al menos una pregunta válida.');
        if ($validado['errores'] !== []) {
            self::renderFormularioEdicion($pdo, $id, [
                'error' => self::htmlErrores($validado['errores']),
                'valor_titulo' => htmlspecialchars($titulo),
                'valor_descripcion' => htmlspecialchars($descripcion),
            ]);
            return;
        }

        try {
            $pdo->beginTransaction();

            $stmtUpdEnc = $pdo->prepare('UPDATE encuesta SET titulo = ?, descripcion = ? WHERE id = ?');
            $stmtUpdEnc->execute([$titulo, $descripcion !== '' ? $descripcion : null, $id]);

            // Preguntas actuales en la BD: las que no lleguen del formulario se borran al final.
            $stmtEx = $pdo->prepare('SELECT id FROM pregunta WHERE encuesta_id = ?');
            $stmtEx->execute([$id]);
            $existentes = array_fill_keys(array_map('intval', $stmtEx->fetchAll(PDO::FETCH_COLUMN)), true);

            $stmtUpdPreg = $pdo->prepare(
                'UPDATE pregunta SET tipo = ?, texto = ?, `orden` = ? WHERE id = ? AND encuesta_id = ?'
            );
            $stmtInsPreg = $pdo->prepare(
                'INSERT INTO pregunta (encuesta_id, tipo, texto, requerida, `orden`) VALUES (?, ?, ?, 1, ?)'
            );
            $stmtInsOpc = $pdo->prepare(
                'INSERT INTO pregunta_opcion (pregunta_id, texto, `orden`) VALUES (?, ?, ?)'
            );
            $stmtDelOpc = $pdo->prepare('DELETE FROM pregunta_opcion WHERE pregunta_id = ?');

            $mantenidas = [];
            foreach ($validado['datos'] as $orden => $pd) {
                $pidExistente = (int) ($pd['id'] ?? 0);

                if ($pidExistente > 0 && isset($existentes[$pidExistente])) {
                    // Pregunta existente: se actualiza y sus opciones se reemplazan.
                    $stmtUpdPreg->execute([$pd['tipo'], $pd['texto'], $orden, $pidExistente, $id]);
                    $stmtDelOpc->execute([$pidExistente]);
                    self::insertarOpciones($stmtInsOpc, $pidExistente, $pd['opciones']);
                    $mantenidas[] = $pidExistente;
                } else {
                    // Pregunta nueva: se ignora cualquier id recibido.
                    $stmtInsPreg->execute([$id, $pd['tipo'], $pd['texto'], $orden]);
                    self::insertarOpciones($stmtInsOpc, (int) $pdo->lastInsertId(), $pd['opciones']);
                }
            }

            // OJO: array_diff preserva las claves del array izquierdo, así que se
            // iteran los VALORES (los ids), no array_keys().
            $aBorrar = array_diff(array_keys($existentes), $mantenidas);
            $stmtDelPreg = $pdo->prepare('DELETE FROM pregunta WHERE id = ?');
            foreach ($aBorrar as $borrarId) {
                $stmtDelPreg->execute([(int) $borrarId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            self::rollbackSiActivo($pdo);
            self::renderFormularioEdicion($pdo, $id, [
                'error' => '<div class="alert alert-danger py-2 alert-chico">Error al guardar los cambios.</div>',
            ]);
            return;
        }

        header('Location: ' . base_path() . '/encuestas?editada=1');
        exit;
    }

    /**
     * Vista de edición recargando las preguntas actuales de la BD (así un error
     * de validación no deja el editor desincronizado) y aplicando los overrides
     * opcionales (título/descripción/error).
     */
    private static function renderFormularioEdicion(PDO $pdo, int $id, array $overrides): void
    {
        $preguntas = self::preguntasConOpciones($pdo, $id);

        // El editor JS espera las opciones como lista de textos.
        foreach ($preguntas as &$p) {
            $p['opciones'] = array_values($p['opciones']);
        }
        unset($p);

        render_dashboard('encuestas_editar', 'Editar encuesta', 'encuestas', [
            'error' => $overrides['error'] ?? '',
            'encuesta_id' => (string) $id,
            'valor_titulo' => $overrides['valor_titulo'] ?? '',
            'valor_descripcion' => $overrides['valor_descripcion'] ?? '',
            'preguntas_json' => json_encode($preguntas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    // ------------------------------------------------------------------
    // PUBLICAR / DESPUBLICAR
    // ------------------------------------------------------------------

    /** Activa o desactiva una encuesta (POST a /encuestas/toggle). */
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
}