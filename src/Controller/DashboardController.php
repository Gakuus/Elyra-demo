<?php

declare(strict_types=1);

/**
 * DashboardController: controlador del panel principal.
 * Maneja la página interna de inicio del panel. El listado de encuestas
 * vive en EncuestaController (mismo patrón que el módulo de documentos).
 * Todas las páginas de este controlador requieren sesión iniciada.
 */
final class DashboardController
{
    /**
     * Segundos sin reportar posición tras los cuales el conductor se dibuja
     * como "sin señal" (gris) en vez de como ubicación confiable.
     */
    private const MINUTOS_SIN_SENAL = 180;

    /**
     * Exactitud máxima (metros) que se acepta del navegador. El cliente ya
     * descarta lo que pase de 50 m; este es el techo duro del servidor para
     * que un fix inútil (dentro de un túnel, GPS sin señal) no pise la última
     * posición buena que había.
     */
    private const PRECISION_MAXIMA_M = 200;

    /** Antigüedad máxima (segundos) de un fix para considerarlo actual. */
    private const FIX_MAXIMO_SEGUNDOS = 120;

    /**
     * Stock por debajo del cual un insumo se marca como "stock bajo" en el
     * inicio del panel. No hay columna de mínimo por insumo todavía, así que es
     * un único umbral y está acá para que moverlo sea de una línea.
     */
    private const STOCK_BAJO = 5;

    /** Cambios de estado válidos para un traslado (de: [permitidos]). */
    private const TRANSICIONES = [
        'pendiente'   => ['en_curso', 'cancelado'],
        'en_curso'    => ['en_destino', 'cancelado'],
        'en_destino'  => ['en_retorno', 'cancelado'],
        'en_retorno'  => ['completado', 'cancelado'],
        'completado'  => [],
        'cancelado'   => [],
    ];

    /**
     * Página de inicio del panel (GET a /dashboard).
     * Solo pide que esté logueado y muestra la vista de bienvenida.
     */
    public static function inicio(): void
    {
        // Guard: si no hay sesión, redirige a /login y detiene la ejecución.
        requerir_login();

        // El paciente no tiene panel de gestión: su pantalla de inicio es el
        // mapa, donde ve los traslados en los que participa. La portada pública
        // sigue siendo alcanzable por URL (/publico/doc, /publico/encuesta).
        if (rol_usuario() === 'paciente') {
            header('Location: ' . base_path() . '/mapa');
            exit;
        }

        // render_dashboard envuelve la vista en el layout común (menu, header...).
        // Parámetros: (nombre de vista, título de pestaña, sección activa, datos).
        render_dashboard('inicio', 'Panel', 'inicio', self::datosInicio());
    }

    /**
     * Arma todo lo que muestra la portada: saludo, conteos del pipeline,
     * traslados activos agrupados por estado, flota que reporta, actividad
     * reciente y avisos. Se renderiza del lado del servidor para que la página
     * se vea aun sin JS; public/js/pages/inicio.js la vuelve a pintar con el
     * mismo payload de /api/mapa para que quede en vivo.
     */
    private static function datosInicio(): array
    {
        $pdo = db_connect();
        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $esGestion = es_gestion();
        $esOperacion = es_rol('conductor', 'copiloto');

        $ahora = new DateTimeImmutable('now');
        $saludo = ((int) $ahora->format('G')) < 12
            ? 'Buenos días'
            : (((int) $ahora->format('G')) < 20 ? 'Buenas tardes' : 'Buenas noches');

        // --- Pipeline: cuántos traslados hay en cada etapa ----------------
        $conteos = ['pendiente' => 0, 'en_curso' => 0, 'en_destino' => 0, 'en_retorno' => 0];
        foreach ($pdo->query(
            "SELECT estado, COUNT(*) AS c FROM traslado
             WHERE estado NOT IN ('completado', 'cancelado')
             GROUP BY estado"
        ) as $fila) {
            if (isset($conteos[$fila['estado']])) {
                $conteos[$fila['estado']] = (int) $fila['c'];
            }
        }

        $completadosHoy = (int) $pdo->query(
            "SELECT COUNT(*) FROM traslado
             WHERE estado = 'completado' AND hora_llegada_hospital >= CURDATE()"
        )->fetchColumn();

        $enLinea = (int) $pdo->query(
            'SELECT COUNT(*) FROM ubicacion_conductor
             WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) <= ' . self::FIX_MAXIMO_SEGUNDOS
        )->fetchColumn();

        $vehiculos = $pdo->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(activo = 1), 0) AS activos FROM vehiculo'
        )->fetch();

        $encuestasActivas = (int) $pdo->query(
            'SELECT COUNT(*) FROM encuesta WHERE activa = 1'
        )->fetchColumn();

        $insumosBajos = $pdo->query(
            'SELECT nombre, stock FROM insumo
             WHERE activo = 1 AND stock <= ' . self::STOCK_BAJO . '
             ORDER BY stock ASC, nombre ASC
             LIMIT 6'
        )->fetchAll();

        // --- Traslados activos: el pipeline y, para el chofer, el suyo ----
        $traslados = $pdo->query(
            "SELECT t.codigo, t.origen, t.destino, t.estado, t.created_at, t.conductor_id,
                    t.hora_salida_efectiva, t.hora_llegada_destino, t.hora_inicio_retorno,
                    CONCAT(cu.nombre, ' ', cu.apellido) AS conductor,
                    v.patente AS vehiculo
             FROM traslado t
             LEFT JOIN funcionario c ON c.id = t.conductor_id
             LEFT JOIN usuario cu ON cu.id = c.id
             LEFT JOIN vehiculo v ON v.id = t.vehiculo_id
             WHERE t.estado NOT IN ('completado', 'cancelado')
             ORDER BY FIELD(t.estado, 'en_curso', 'en_destino', 'en_retorno', 'pendiente'),
                      t.created_at DESC"
        )->fetchAll();

        $columnas = ['pendiente' => [], 'en_curso' => [], 'en_destino' => [], 'en_retorno' => []];
        $miTraslado = [];
        foreach ($traslados as $t) {
            $tarjeta = self::tarjetaTraslado($t);
            if (isset($columnas[$t['estado']])) {
                $columnas[$t['estado']][] = $tarjeta;
            }
            if ((int) $t['conductor_id'] === $usuarioId && $miTraslado === []) {
                $miTraslado[] = $tarjeta;
            }
        }

        // --- Flota: quién reporta ahora y qué está haciendo ---------------
        $flota = [];
        foreach ($pdo->query(
            "SELECT CONCAT(u.nombre, ' ', u.apellido) AS nombre,
                    uc.latitud, uc.longitud, uc.updated_at, uc.velocidad,
                    (TIMESTAMPDIFF(SECOND, uc.updated_at, NOW()) > " . self::MINUTOS_SIN_SENAL . ") AS sin_senal,
                    tr.codigo AS traslado_codigo,
                    tr.estado AS traslado_estado
             FROM ubicacion_conductor uc
             JOIN funcionario f ON f.id = uc.conductor_id
             JOIN usuario u ON u.id = f.id
             LEFT JOIN traslado tr ON tr.id = uc.traslado_id
             ORDER BY sin_senal ASC, u.nombre ASC"
        ) as $c) {
            $sinSenal = (bool) $c['sin_senal'];
            $codigo = (string) ($c['traslado_codigo'] ?? '');
            $detalle = $sinSenal
                ? 'Sin señal · ' . self::haceCuanto((string) $c['updated_at'])
                : ($codigo !== ''
                    ? 'Llevando ' . $codigo
                    : 'Disponible · ' . self::haceCuanto((string) $c['updated_at']));

            $flota[] = [
                'nombre'    => (string) $c['nombre'],
                'iniciales' => self::iniciales((string) $c['nombre']),
                'detalle'   => $detalle,
                'clase'     => $sinSenal ? 'flota-sin-senal' : 'flota-en-linea',
                'lat'       => (float) $c['latitud'],
                'lng'       => (float) $c['longitud'],
            ];
        }

        $conductoresTotal = (int) $pdo->query(
            "SELECT COUNT(*) FROM funcionario WHERE rol = 'conductor' AND activo = 1"
        )->fetchColumn();

        // --- Actividad: los últimos cambios de estado reales --------------
        $actividad = [];
        foreach ($pdo->query(
            "SELECT h.estado_nuevo, h.created_at, t.codigo,
                    CONCAT(u.nombre, ' ', u.apellido) AS usuario
             FROM historial_estado h
             JOIN traslado t ON t.id = h.traslado_id
             LEFT JOIN usuario u ON u.id = h.actualizado_por
             ORDER BY h.id DESC
             LIMIT 8"
        ) as $a) {
            $actividad[] = [
                'codigo' => (string) $a['codigo'],
                'estado' => self::etiquetaEstado((string) $a['estado_nuevo']),
                'clase'  => (string) $a['estado_nuevo'],
                'usuario'=> (string) $a['usuario'],
                'hace'   => self::haceCuanto((string) $a['created_at']),
            ];
        }

        return [
            'saludo'           => $saludo,
            'fecha_larga'      => self::fechaLarga($ahora),
            'es_gestion'       => $esGestion ? ['1'] : [],
            'es_operacion'     => $esOperacion ? ['1'] : [],
            'es_paciente'      => [],
            'activos_total'    => array_sum($conteos),
            'cnt_pendiente'    => $conteos['pendiente'],
            'cnt_curso'        => $conteos['en_curso'],
            'cnt_destino'      => $conteos['en_destino'],
            'cnt_retorno'      => $conteos['en_retorno'],
            'completados_hoy'  => $completadosHoy,
            'en_linea'         => $enLinea,
            'conductores_total'=> $conductoresTotal,
            'vehiculos_activos'=> (int) $vehiculos['activos'],
            'vehiculos_total'  => (int) $vehiculos['total'],
            'encuestas_activas'=> $encuestasActivas,
            'insumos_bajos'    => $insumosBajos,
            'col_pendiente'    => $columnas['pendiente'],
            'col_curso'        => $columnas['en_curso'],
            'col_destino'      => $columnas['en_destino'],
            'col_retorno'      => $columnas['en_retorno'],
            'mi_traslado'      => $miTraslado,
            'flota'            => $flota,
            'actividad'        => $actividad,
            'usuario_id'       => $usuarioId,
        ];
    }

    /**
     * Convierte una fila de traslado en los campos que usa la tarjeta del
     * tablero, ya calculados (nombre del conductor, tiempo de la etapa). Va por
     * separado porque lo usan tanto el pipeline como la tarjeta del chofer.
     */
    private static function tarjetaTraslado(array $t): array
    {
        return [
            'codigo'    => (string) $t['codigo'],
            'origen'    => (string) $t['origen'],
            'destino'   => (string) $t['destino'],
            'conductor' => trim((string) $t['conductor']) !== '' ? (string) $t['conductor'] : 'Sin asignar',
            'vehiculo'  => (string) ($t['vehiculo'] ?? ''),
            'estado'    => (string) $t['estado'],
            'estado_etiqueta' => self::etiquetaEstado((string) $t['estado']),
            'tiempo'    => self::haceCuanto(self::marcaDeEtapa($t)),
        ];
    }

    /**Momento en que el traslado entró en su etapa actual (para el "hace X"). */
    private static function marcaDeEtapa(array $t): ?string
    {
        switch ($t['estado']) {
            case 'en_curso':   return $t['hora_salida_efectiva'] ?: $t['created_at'];
            case 'en_destino': return $t['hora_llegada_destino'] ?: $t['created_at'];
            case 'en_retorno': return $t['hora_inicio_retorno'] ?: $t['created_at'];
            default:           return $t['created_at'];
        }
    }

    /** "hace 5 min", "hace 2 h", "hace 3 d" a partir de una fecha SQL. */
    private static function haceCuanto(?string $marca): string
    {
        if ($marca === null || $marca === '') {
            return '';
        }
        $t = strtotime($marca);
        if ($t === false) {
            return '';
        }

        $segundos = max(0, time() - $t);
        if ($segundos < 60) {
            return 'recién';
        }
        $minutos = intdiv($segundos, 60);
        if ($minutos < 60) {
            return 'hace ' . $minutos . ' min';
        }
        $horas = intdiv($minutos, 60);
        if ($horas < 24) {
            return 'hace ' . $horas . ' h';
        }
        return 'hace ' . intdiv($horas, 24) . ' d';
    }

    /** Etiqueta legible de un estado de traslado. */
    private static function etiquetaEstado(string $estado): string
    {
        return [
            'pendiente'  => 'Pendiente',
            'en_curso'   => 'En curso',
            'en_destino' => 'En destino',
            'en_retorno' => 'En retorno',
            'completado' => 'Completado',
            'cancelado'  => 'Cancelado',
        ][$estado] ?? '';
    }

    /** Iniciales (hasta dos) del nombre, para el avatar de la flota. */
    private static function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        $ini = '';
        foreach (array_slice($partes, 0, 2) as $p) {
            if ($p !== '') {
                $ini .= mb_strtoupper(mb_substr($p, 0, 1));
            }
        }
        return $ini !== '' ? $ini : '?';
    }

    /** Fecha en castellano: "lunes 7 de octubre de 2026". */
    private static function fechaLarga(DateTimeImmutable $d): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
            'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $dias[(int) $d->format('w')] . ' ' . (int) $d->format('j')
            . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    /**
     * Mapa interactivo en vivo.
     * Requiere sesión iniciada. La vista arma el mapa y el JS consulta
     * /api/mapa para dibujar hospitales, traslados y posiciones de conductores
     * desde la base de datos, actualizando cada pocos segundos.
     *
     * A la vista se le pasa el rol y el id del usuario para que el JS sepa si
     * tiene que activar el reporte de geolocalización (conductor), si solo
     * mostrar posiciones (resto del personal) o si es un paciente, que no
     * registra nada y no reporta posición.
     *
     * El contenido de la lista depende del rol, pero no se decide acá: el
     * recorte real de traslados y conductores va en obtenerDatosMapa(), que es
     * el único lugar donde se lee la sesión para armar el WHERE.
     */
    public static function mapa(): void
    {
        requerir_login();

        // El paciente entra acá también, pero a otra versión de la página: ve
        // los traslados en los que participa (los que va en su nombre) y el
        // recorrido de los que están en curso. Los datos se leen igual desde
        // /api/mapa, así que el filtro real está en obtenerDatosMapa().
        $esPaciente = es_rol('paciente');

        // Registrar un traslado es del personal de la operación, nunca del
        // paciente. Los catálogos del formulario (conductores, vehículos,
        // insumos, rutas, lugares) solo se cargan si el modal va a existir:
        // si no, el HTML del mapa no le lleva al paciente ninguno de esos
        // listados y el template recibe directamente los vacíos.
        $puedeRegistrar = !$esPaciente;
        $formulario = $puedeRegistrar
            ? self::datosFormularioTraslado()
            : self::formularioVacio();

        render_dashboard('mapa', 'Mapa', 'mapa', [
            'rol'          => rol_usuario(),
            'usuario_id'   => (int) ($_SESSION['usuario_id'] ?? 0),
            'es_conductor' => es_rol('conductor') ? ['1'] : [],
            // "es_chofer" agrupa a quien maneja: registra su propio traslado y
            // por eso el modal le muestra el conductor ya fijado.
            'es_chofer'    => es_rol('conductor', 'copiloto') ? ['1'] : [],
            'es_paciente'  => $esPaciente ? ['1'] : [],
            'nombre_usuario' => (string) ($_SESSION['usuario_nombre'] ?? ''),
            'puede_registrar' => $puedeRegistrar ? ['1'] : [],

            // El catálogo también viaja como JSON para el JS del modal, que lo
            // usa para pintar los lugares conocidos sobre el mapa. Los flags
            // HEX_TAG/AMP/APOS/QUOT evitan que un nombre con < o & rompa el
            // <script> donde se inyecta.
            'catalogo_json' => json_encode(
                $formulario['catalogo_crudo'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?: '[]',
        ] + $formulario);
    }

    /**
     * Los mismos marcadores de datosFormularioTraslado() pero todos vacíos, para
     * los roles que no registran traslados. El template los consume con
     * {{#conductores}}, {{#vehiculos}}, etc., y un grupo inexistente y un grupo
     * vacío se dibujan igual (o sea, no alcanza con no pasar la clave).
     */
    private static function formularioVacio(): array
    {
        return [
            'conductores'    => [],
            'copilotos'      => [],
            'vehiculos'      => [],
            'insumos'        => [],
            'equipamientos'  => [],
            'organos'        => [],
            'rutas'          => [],
            'catalogo'       => [],
            'catalogo_crudo' => [],
        ];
    }

    /**
     * Listados que necesita el formulario de registro de traslado, ya sea en
     * la página /traslados/nuevo o dentro del modal del mapa.
     *
     * Viene aparte de nuevoTraslado() porque desde el mapa el alta se hace en
     * un modal sobre el propio mapa, no navegando a otra página.
     */
    private static function datosFormularioTraslado(): array
    {
        $pdo = db_connect();

        $conductores = $pdo->query(
            "SELECT f.id, CONCAT(u.nombre, ' ', u.apellido) AS nombre
             FROM funcionario f
             JOIN usuario u ON u.id = f.id
             WHERE f.rol = 'conductor' AND f.activo = 1
             ORDER BY u.nombre, u.apellido"
        )->fetchAll();

        $copilotos = $pdo->query(
            "SELECT f.id, CONCAT(u.nombre, ' ', u.apellido) AS nombre
             FROM funcionario f
             JOIN usuario u ON u.id = f.id
             WHERE f.rol = 'copiloto' AND f.activo = 1
             ORDER BY u.nombre, u.apellido"
        )->fetchAll();

        $vehiculos = $pdo->query(
            'SELECT id, patente, modelo FROM vehiculo WHERE activo = 1 ORDER BY patente'
        )->fetchAll();

        // Los pacientes NO se consultan acá a propósito: se buscan contra
        // /api/pacientes/buscar desde el modal, para que la lista completa no
        // quede escrita en el HTML que descarga el conductor o el copiloto.

        $insumos = $pdo->query('SELECT id, nombre FROM insumo WHERE activo = 1 ORDER BY nombre')->fetchAll();
        $equipamientos = $pdo->query('SELECT id, nombre FROM equipamiento WHERE activo = 1 ORDER BY nombre')->fetchAll();
        $organos = $pdo->query('SELECT id, nombre FROM organo WHERE activo = 1 ORDER BY nombre')->fetchAll();

        $rutas = $pdo->query(
            'SELECT id, nombre, origen, destino, distancia_km FROM ruta ORDER BY nombre'
        )->fetchAll();

        // Catálogo de ubicaciones: es lo que se ofrece en "Elegir del catálogo"
        // del origen y del destino, y lo que ya conoce el sistema.
        $catalogo = $pdo->query(
            'SELECT nombre, latitud, longitud FROM ubicacion WHERE activo = 1 ORDER BY nombre'
        )->fetchAll();

        $limpiarNombres = function (array $filas): array {
            $salida = [];
            foreach ($filas as $f) {
                $salida[] = [
                    'id'    => (int) $f['id'],
                    'nombre' => htmlspecialchars((string) $f['nombre']),
                ];
            }
            return $salida;
        };

        $limpiarVehiculos = function (array $filas): array {
            $salida = [];
            foreach ($filas as $v) {
                $patente = (string) $v['patente'];
                $modelo = trim((string) ($v['modelo'] ?? ''));
                $salida[] = [
                    'id'    => (int) $v['id'],
                    'nombre' => $modelo !== ''
                        ? htmlspecialchars($patente . ' — ' . $modelo)
                        : htmlspecialchars($patente),
                ];
            }
            return $salida;
        };

        $limpiarRutas = function (array $filas): array {
            $salida = [];
            foreach ($filas as $r) {
                $origen = (string) $r['origen'];
                $destino = (string) $r['destino'];
                $distancia = $r['distancia_km'] !== null ? trim((string) $r['distancia_km']) : '';
                $salida[] = [
                    'id'    => (int) $r['id'],
                    'origen'   => htmlspecialchars($origen),
                    'destino'  => htmlspecialchars($destino),
                    'distancia' => htmlspecialchars($distancia),
                    'ruta_label' => htmlspecialchars(
                        trim((string) $r['nombre']) . ' (' . $origen . ' → ' . $destino . ')'
                    ),
                ];
            }
            return $salida;
        };

        // Dos versiones del catálogo a propósito. La escapada va para los
        // <option> del HTML (el template de este proyecto no escapa solo), y
        // la cruda para el JSON que lee el JS: si se mandara la escapada, el
        // navegador mostraría bien el <option> pero el JS compararía
        // "Peluquería &amp; Spa" contra el input y no encontraría coincidencia.
        $limpiarCatalogo = function (array $filas): array {
            $salida = [];
            foreach ($filas as $u) {
                $nombre = trim((string) $u['nombre']);
                if ($nombre === '') continue;
                $salida[] = [
                    'nombre' => $nombre,
                    'latitud'  => (float) $u['latitud'],
                    'longitud' => (float) $u['longitud'],
                ];
            }
            return $salida;
        };

        $catalogoCrudo = $limpiarCatalogo($catalogo);
        $catalogoHtml = array_map(
            fn(array $u): array => $u + ['nombre' => htmlspecialchars($u['nombre'])],
            $catalogoCrudo
        );

        return [
            'conductores'   => $limpiarNombres($conductores),
            'copilotos'     => $limpiarNombres($copilotos),
            'vehiculos'     => $limpiarVehiculos($vehiculos),
            'insumos'       => $limpiarNombres($insumos),
            'equipamientos' => $limpiarNombres($equipamientos),
            'organos'       => $limpiarNombres($organos),
            'rutas'         => $limpiarRutas($rutas),
            'catalogo'      => $catalogoHtml,
            'catalogo_crudo' => $catalogoCrudo,
        ];
    }

    /**
     * Datos del mapa en JSON (GET a /api/mapa).
     * Requiere sesión iniciada. Devuelve las ubicaciones/hospitales, los
     * traslados y la última posición de cada conductor (ubicacion_conductor).
     *
     * Qué ve cada rol NO se decide acá sino en obtenerDatosMapa(), que arma el
     * WHERE según la sesión: el personal de la operación ve la flota completa
     * y el paciente solo los traslados en los que participa. Este endpoint solo
     * exige sesión porque es el mismo mapa para los dos.
     */
    public static function mapaDatos(): void
    {
        requerir_login();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(self::obtenerDatosMapa(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Reporte de posición del conductor (POST a /api/ubicacion).
     *
     * Lo llama el mapa de la página del conductor: navigator.geolocation
     * devuelve la posición del dispositivo y el JS la manda por acá cada
     * pocos segundos. El servidor no confía en el traslado_id que manda el
     * cliente — lo resuelve él mismo con el traslado activo del conductor —
     * y descarta fixes inútiles (precisión %) o viejos (más de 2 min).
     *
     * Responde JSON. El CSRF ya lo valida index.php antes de llegar acá.
     */
    public static function guardarUbicacionConductor(): void
    {
        $responder = function (bool $ok, string $mensaje, array $extra = [], int $codigoHttp = 200): void {
            header('Content-Type: application/json; charset=utf-8');
            if (!$ok) {
                http_response_code($codigoHttp);
            }
            echo json_encode(['ok' => $ok, 'error' => $ok ? null : $mensaje, 'mensaje' => $ok ? $mensaje : null] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        };

        // A diferencia de requerir_login(), acá NO se redirige: un endpoint
        // tiene que responder 401 en JSON, no mandar al conductor a la
        // pantalla de login en medio de un reporte.
        if (!Auth::estaAutenticado()) {
            $responder(false, 'Sesión no válida. Volvé a iniciar sesión.', [], 401);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);

        // El reporte de ubicación es solo del rol conductor: ni un admin ni
        // un paciente tienen por qué escribir posiciones de otra persona.
        if (!es_rol('conductor')) {
            $responder(false, 'Solo los conductores pueden reportar su ubicación.', [], 403);
        }

        $pdo = db_connect();

        // Verifica que el usuario sea efectivamente un funcionario conductor
        // activo. Sin esto, el id de sesión se usaría contra la tabla de
        // ubicaciones sin comprobar que corresponda.
        $stmt = $pdo->prepare("SELECT id FROM funcionario WHERE id = ? AND rol = 'conductor' AND activo = 1");
        $stmt->execute([$usuarioId]);
        $conductorId = $stmt->fetchColumn();
        if ($conductorId === false) {
            $responder(false, 'Tu usuario no está habilitado como conductor.', [], 403);
        }
        $conductorId = (int) $conductorId;

        // ------------------------------------------------------------------
        // Lectura y validación del fix
        // ------------------------------------------------------------------
        $latitud = filter_var($_POST['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitud = filter_var($_POST['longitud'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($latitud === false || $longitud === false
            || $latitud < -90 || $latitud > 90 || $longitud < -180 || $longitud > 180
        ) {
            $responder(false, 'Coordenadas inválidas.', [], 400);
        }

        // Exactitud en metros. Sin valor no se filtra (el navegador a veces no
        // la da); con valor, se descarta si es demasiado mala.
        $exactitudRaw = $_POST['exactitud'] ?? null;
        $exactitud = ($exactitudRaw === null || $exactitudRaw === '')
            ? null
            : filter_var($exactitudRaw, FILTER_VALIDATE_FLOAT);

        if ($exactitud !== null && $exactitud !== false) {
            if ($exactitud > self::PRECISION_MAXIMA_M) {
                $responder(false, 'Señal GPS demasiado imprecisa (' . round($exactitud) . ' m).', [
                    'exactitud' => round($exactitud),
                ], 422);
            }
        } else {
            $exactitud = null;
        }

        // Antigüedad del fix (epoch en ms). El navegador puede devolver una
        // posición cacheada: si es vieja, escribirla movería la ambulancia
        // hacia atrás en el mapa.
        $tsRaw = filter_var($_POST['ts'] ?? null, FILTER_VALIDATE_INT);
        if ($tsRaw !== false && $tsRaw !== null) {
            $antiguedad = time() - (int) round($tsRaw / 1000);
            if ($antiguedad > self::FIX_MAXIMO_SEGUNDOS) {
                $responder(false, 'Posición desactualizada del navegador.', [], 422);
            }
        }

        // Rumbo en grados (0-360) y velocidad en km/h. Opcionales: el
        // navegador no siempre los provee.
        $headingRaw = $_POST['heading'] ?? null;
        $heading = ($headingRaw === null || $headingRaw === '')
            ? null
            : filter_var($headingRaw, FILTER_VALIDATE_FLOAT);
        if ($heading === false || $heading === null || $heading < 0 || $heading > 360) {
            $heading = null;
        } else {
            $heading = (int) round($heading);
        }

        $velocidadRaw = $_POST['velocidad'] ?? null;
        $velocidad = ($velocidadRaw === null || $velocidadRaw === '')
            ? null
            : filter_var($velocidadRaw, FILTER_VALIDATE_FLOAT);
        if ($velocidad === false || $velocidad === null || $velocidad < 0 || $velocidad > 999) {
            $velocidad = null;
        }

        // ------------------------------------------------------------------
        // Traslado activo: lo resuelve el servidor, no el cliente.
        //
        // 'pendiente' entra en la lista a propósito. La unidad arranca a mover
        // en cuanto el conductor sale —y con la simulación, apenas se la
        // prende— pero el despacho puede tardar unos segundos en tocar
        // "Iniciar". Si acá no se aceptara 'pendiente', esas posiciones se
        // guardarían con traslado_id NULL y la ambulancia aparecería en el mapa
        // sin traslado asociado: invisible para el paciente (que se los ve
        // por esa columna) y sin código al lado del ícono.
        // ------------------------------------------------------------------
        $stmt = $pdo->prepare(
            "SELECT id FROM traslado
             WHERE conductor_id = ?
               AND estado IN ('pendiente', 'en_curso', 'en_destino', 'en_retorno')
             ORDER BY FIELD(estado, 'en_curso', 'en_destino', 'en_retorno', 'pendiente'), updated_at DESC
             LIMIT 1"
        );
        $stmt->execute([$conductorId]);
        $trasladoId = $stmt->fetchColumn();
        $trasladoId = $trasladoId === false ? null : (int) $trasladoId;

        // ------------------------------------------------------------------
        // Upsert: una fila por conductor (uk_conductor), se sobreescribe.
        // ------------------------------------------------------------------
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO ubicacion_conductor (conductor_id, traslado_id, latitud, longitud, heading, velocidad)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     traslado_id = VALUES(traslado_id),
                     latitud     = VALUES(latitud),
                     longitud    = VALUES(longitud),
                     heading     = VALUES(heading),
                     velocidad   = VALUES(velocidad),
                     updated_at  = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                $conductorId,
                $trasladoId,
                $latitud,
                $longitud,
                $heading,
                $velocidad,
            ]);
        } catch (Throwable $e) {
            $responder(false, 'No se pudo guardar la ubicación. Intentá de nuevo.', [], 500);
        }

        $responder(true, 'Ubicación actualizada.', [
            'traslado_id' => $trasladoId,
            'exactitud'   => $exactitud !== null ? round($exactitud) : null,
        ]);
    }

    /** Reúne la información que consume el mapa en vivo. */
    private static function obtenerDatosMapa(): array
    {
        $pdo = db_connect();

        $ubicaciones = $pdo->query(
            "SELECT nombre, latitud, longitud
             FROM ubicacion
             WHERE activo = 1
             ORDER BY nombre"
        )->fetchAll();

        $rol = rol_usuario();
        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $puedeGestionar = $rol === 'admin' || $rol === 'superadmin';
        $esPaciente = $rol === 'paciente';

        // ------------------------------------------------------------------
        // Traslados
        //
        // El personal de la operación (cualquier rol, incluido el conductor)
        // ve la flota completa en curso: coordinar una ambulancia exige saber
        // qué están haciendo las demás, no solo la propia unidad.
        //
        // El paciente ve lo contrario: solo los traslados en los que participa,
        // y además los ya terminados. El histórico importa —"¿ya me/nodejs
        // trasladaron?"— así que acá NO se filtra por estado como para el
        // personal. La visibilidad sale del INNER JOIN con paciente_traslado:
        // si el traslado no lo tiene, no existe para ese paciente. OJO: el id
        // de la sesión es el de usuario, y paciente.id === usuario.id (la tabla
        // paciente comparte la clave primaria con usuario), por eso el mismo
        // $usuarioId sirve para las dos cosas.
        // ------------------------------------------------------------------
        $stmtTraslados = $pdo->prepare(
            "SELECT t.id, t.codigo, t.origen, t.destino, t.estado,
                    t.conductor_id, t.origen_lat, t.origen_lng, t.destino_lat, t.destino_lng,
                    t.hora_salida_estimada, t.hora_salida_efectiva, t.hora_llegada_destino,
                    t.hora_inicio_retorno, t.created_at, t.ruta_id,
                    t.distancia_km, t.duracion_min,
                    CONCAT(cu.nombre, ' ', cu.apellido) AS conductor_nombre,
                    CONCAT(copu.nombre, ' ', copu.apellido) AS copiloto_nombre,
                    v.patente AS vehiculo_patente
             FROM traslado t
             LEFT JOIN funcionario c ON c.id = t.conductor_id
             LEFT JOIN usuario cu ON cu.id = c.id
             LEFT JOIN funcionario cop ON cop.id = t.copiloto_id
             LEFT JOIN usuario copu ON copu.id = cop.id
             LEFT JOIN vehiculo v ON v.id = t.vehiculo_id"
            . ($esPaciente
                // INNER JOIN (no LEFT) a propósito: es el filtro de seguridad.
                // Un traslado ajeno tiene cero filas y nunca llega al JSON.
                ? ' INNER JOIN paciente_traslado pt ON pt.traslado_id = t.id AND pt.paciente_id = ?'
                // El personal no ve el archivo histórico en el mapa en vivo: solo
                // lo que está pasando ahora.
                : " WHERE t.estado NOT IN ('completado', 'cancelado')")
            . ' ORDER BY t.created_at DESC'
        );
        $stmtTraslados->execute($esPaciente ? [$usuarioId] : []);
        $traslados = $stmtTraslados->fetchAll();

        // ------------------------------------------------------------------
        // Posiciones de los conductores
        //
        // Para el paciente el filtro es sobre ubicacion_conductor.traslado_id
        // (el traslado que la unidad está haciendo AHORA), no sobre el
        // historial: si su traslado terminó, la fila ya se borró al cambiar el
        // estado (ver cambiarEstadoTraslado()) y no queda ninguna posición
        // vieja dando vueltas. Así el paciente ve la ambulancia que lo lleva y
        // ni una más — tampoco las que están de guardia ni las que llevan a
        // otros pacientes.
        // ------------------------------------------------------------------
        $stmtConductores = $pdo->prepare(
            "SELECT u.id AS conductor_id,
                    CONCAT(u.nombre, ' ', u.apellido) AS conductor_nombre,
                    uc.latitud, uc.longitud, uc.heading, uc.velocidad,
                    uc.updated_at,
                    (TIMESTAMPDIFF(SECOND, uc.updated_at, NOW()) > ?) AS sin_senal,
                    tr.codigo AS traslado_codigo,
                    tr.estado AS traslado_estado,
                    tr.origen AS traslado_origen,
                    tr.destino AS traslado_destino
             FROM ubicacion_conductor uc
             JOIN funcionario f ON f.id = uc.conductor_id
             JOIN usuario u ON u.id = f.id
             LEFT JOIN traslado tr ON tr.id = uc.traslado_id"
            . ($esPaciente
                ? ' INNER JOIN paciente_traslado pt ON pt.traslado_id = uc.traslado_id AND pt.paciente_id = ?'
                : '')
            . ' ORDER BY u.nombre'
        );
        $params = [self::MINUTOS_SIN_SENAL];
        if ($esPaciente) {
            $params[] = $usuarioId;
        }
        $stmtConductores->execute($params);
        $conductores = $stmtConductores->fetchAll();

        $num = fn($v) => $v === null ? null : (float) $v;

        $ubicaciones = array_map(static function (array $u): array {
            return [
                'nombre'   => (string) $u['nombre'],
                'latitud'  => (float) $u['latitud'],
                'longitud' => (float) $u['longitud'],
            ];
        }, $ubicaciones);

        $traslados = array_map(static function (array $t) use ($num, $puedeGestionar, $usuarioId, $esPaciente): array {
            $estado = (string) $t['estado'];
            return [
                'id'                   => (int) $t['id'],
                'codigo'               => (string) $t['codigo'],
                'origen'               => (string) $t['origen'],
                'destino'              => (string) $t['destino'],
                'estado'               => $estado,
                'origen_lat'           => $num($t['origen_lat']),
                'origen_lng'           => $num($t['origen_lng']),
                'destino_lat'          => $num($t['destino_lat']),
                'destino_lng'          => $num($t['destino_lng']),
                'hora_salida_estimada' => (string) $t['hora_salida_estimada'],
                'hora_salida_efectiva' => (string) $t['hora_salida_efectiva'],
                'hora_llegada_estimada' => (string) ($t['hora_llegada_destino'] ?? ''),
                'hora_inicio_retorno'  => (string) ($t['hora_inicio_retorno'] ?? ''),
                'created_at'           => (string) ($t['created_at'] ?? ''),
                'ruta_id'              => $t['ruta_id'] !== null ? (int) $t['ruta_id'] : null,
                'distancia_km'         => $num($t['distancia_km']),
                'duracion_min'         => $num($t['duracion_min']),
                'conductor_nombre'     => (string) $t['conductor_nombre'],
                'copiloto_nombre'      => (string) ($t['copiloto_nombre'] ?? ''),
                'vehiculo_patente'     => (string) ($t['vehiculo_patente'] ?? ''),
                'conductor_id'         => (int) $t['conductor_id'],
                // El paciente no avanza estados ni aunque el id de su usuario coincidiera con
                // un conductor: es una comprobación de identidad de roles, no de
                // números. El botón no se dibuja y cambiarEstadoTraslado()
                // también lo rechaza.
                'puede_cambiar_estado' => !$esPaciente
                    && ($puedeGestionar || (int) $t['conductor_id'] === $usuarioId),
                'transiciones'         => self::TRANSICIONES[$estado] ?? [],
            ];
        }, $traslados);

        $conductores = array_map(static function (array $c) use ($num): array {
            return [
                'conductor_id'      => (int) $c['conductor_id'],
                'conductor_nombre'  => (string) $c['conductor_nombre'],
                'latitud'           => (float) $c['latitud'],
                'longitud'          => (float) $c['longitud'],
                'heading'           => $c['heading'] !== null ? (int) $c['heading'] : null,
                'velocidad'         => $c['velocidad'] !== null ? (float) $c['velocidad'] : null,
                'updated_at'        => (string) $c['updated_at'],
                'sin_senal'         => (bool) $c['sin_senal'],
                'traslado_codigo'   => (string) ($c['traslado_codigo'] ?? ''),
                'traslado_estado'   => (string) ($c['traslado_estado'] ?? ''),
                'traslado_origen'   => (string) ($c['traslado_origen'] ?? ''),
                'traslado_destino'  => (string) ($c['traslado_destino'] ?? ''),
            ];
        }, $conductores);

        return [
            'ubicaciones' => $ubicaciones,
            'traslados'   => $traslados,
            'conductores' => $conductores,
        ];
    }

    /**
     * Ruta real (por calles) entre dos coordenadas (GET a /api/ruta/real).
     * Requiere sesión iniciada. Usa OSRM con caché; si el proveedor no
     * responde devuelve la línea recta entre origen y destino con fallback=1.
     */
    public static function rutaReal(): void
    {
        // Entra el paciente porque es el mapa quien pide este endpoint para
        // dibujar la línea de cada traslado, y sin él su traslado se vería
        // como un segmento recto entre el origen y el destino en vez de por las
        // calles. Lo que devuelve es geometría de calles y no datos de personas,
        // así que no se expone información clínica ni de terceros: las
        // coordenadas que se le piden son las de sus propios traslados, que
        // /api/mapa ya le devolvió.
        requerir_login();

        $origenLat = filter_input(INPUT_GET, 'origen_lat', FILTER_VALIDATE_FLOAT);
        $origenLng = filter_input(INPUT_GET, 'origen_lng', FILTER_VALIDATE_FLOAT);
        $destinoLat = filter_input(INPUT_GET, 'destino_lat', FILTER_VALIDATE_FLOAT);
        $destinoLng = filter_input(INPUT_GET, 'destino_lng', FILTER_VALIDATE_FLOAT);

        // Reutiliza el mismo chequeo que /api/geocodificar: si estas coordenadas
        // no son reales, el proveedor externo recibe basura.
        $coordenadasOk = static fn(mixed $lat, mixed $lng): bool => $lat !== false && $lng !== false
            && GeocodeService::coordenadaValida($lat === false ? null : (float) $lat, $lng === false ? null : (float) $lng);

        if (!$coordenadasOk($origenLat, $origenLng) || !$coordenadasOk($destinoLat, $destinoLng)) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'Coordenadas inválidas'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');

        $ruta = RutaService::obtenerRutaReal((float) $origenLat, (float) $origenLng, (float) $destinoLat, (float) $destinoLng);

        if ($ruta === null) {
            // Respaldo: trazado recto entre los dos puntos para no dejar el
            // mapa mudo cuando el servicio de rutas no está disponible.
            echo json_encode([
                'coordinates' => [[(float) $origenLat, (float) $origenLng], [(float) $destinoLat, (float) $destinoLng]],
                'distance_km' => 0,
                'duration_min' => 0,
                'fallback' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode($ruta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Geocodificación (GET a /api/geocodificar). Es lo que hace que el buscador
     * de destinos del traslado funcione como en Google Maps: se escribe un
     * texto y se devuelven los lugares que coinciden, ya con coordenadas.
     *
     * Dos modos según lo que venga en la query:
     *   ?q=texto       → búsqueda por texto (autocompletado del destino).
     *   ?lat=..&lng=.. → dirección de un punto, para nombrar el origen cuando
     *                     el usuario toca "Usar mi ubicación".
     *
     * Requiere sesión iniciada y no dejar entrar a pacientes, igual que
     * rutaReal(). Devuelve siempre JSON con la misma forma:
     *   {"ok":true,"resultados":[{label,lat,lng,categoria}]}
     *   {"ok":true,"resultados":[{label,lat,lng}]}   (modo reversa: uno solo)
     */
    /**
     * Un punto del Uruguay para ordenar las sugerencias cuando nadie pasó uno.
     *
     * Sale del catálogo de ubicaciones, que ya tiene los pabellones del
     * hospital. Si el catálogo está vacío devuelve null y la búsqueda sigue
     * funcionando sin referencia, que es como venía antes.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function puntoDeReferencia(): ?array
    {
        static $cache = false;

        if ($cache !== false) {
            return $cache;
        }

        $cache = null;

        try {
            $fila = db_connect()->query(
                'SELECT latitud, longitud FROM ubicacion
                 WHERE activo = 1
                   AND latitud IS NOT NULL
                   AND longitud IS NOT NULL
                 ORDER BY id
                 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);

            if ($fila !== false) {
                $lat = (float) $fila['latitud'];
                $lng = (float) $fila['longitud'];
                if (GeocodeService::coordenadaValida($lat, $lng)) {
                    $cache = [$lat, $lng];
                }
            }
        } catch (Throwable $e) {
            $cache = null;
        }

        return $cache;
    }

    public static function geocodificar(): void
    {
        requerir_login();

        if (es_rol('paciente')) {
            denegar();
        }

        header('Content-Type: application/json; charset=utf-8');

        $lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
        $lng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);

        // Modo reversa: dirección de un punto concreto.
        if ($lat !== false && $lng !== false && $lat !== null && $lng !== null) {
            if (!GeocodeService::coordenadaValida((float) $lat, (float) $lng)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Coordenadas fuera de rango'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $direccion = GeocodeService::direccionDe((float) $lat, (float) $lng);

            // Si Nominatim no responde, el origen igual se puede usar: el
            // frontend cae en mostrar las coordenadas crudas como etiqueta.
            echo json_encode([
                'ok'         => true,
                'resultados' => $direccion === null ? [] : [$direccion],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Modo búsqueda por texto.
        $texto = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($texto) < 3) {
            echo json_encode(['ok' => true, 'resultados' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Techo de caracteres: evita mandar 4 KB de basura a Nominatim.
        $texto = mb_substr($texto, 0, 120);

        // El bias de proximidad es una mejora de orden, no un dato: si viene
        // mal, se ignora y la búsqueda sigue funcionando sin bias.
        $proxLat = filter_input(INPUT_GET, 'prox_lat', FILTER_VALIDATE_FLOAT);
        $proxLng = filter_input(INPUT_GET, 'prox_lng', FILTER_VALIDATE_FLOAT);
        if (!GeocodeService::coordenadaValida(
            $proxLat === false ? null : $proxLat,
            $proxLng === false ? null : $proxLng
        )) {
            $proxLat = null;
            $proxLng = null;
        }

        // Si el cliente no mandó referencia, se usa el hospital. Sin esto el
        // orden lo decide Geoapify y "avenida italia" arrancaba con la avenida
        // de Paysandú, a 330 km, en vez de la de Montevideo. El navegador solo
        // manda el bias cuando ya hay un origen elegido o el mapa está listo,
        // que es justo el momento en que más se está tipeando.
        if ($proxLat === null || $proxLng === null) {
            $referencia = self::puntoDeReferencia();
            if ($referencia !== null) {
                $proxLat = $referencia[0];
                $proxLng = $referencia[1];
            }
        }

        echo json_encode([
            'ok'         => true,
            'resultados' => GeocodeService::buscar($texto, 8, $proxLat, $proxLng),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Búsqueda de pacientes para el modal de alta (GET /api/pacientes/buscar?q=).
     *
     * Devuelve solo lo que coincide con lo que se escribió, nunca la lista
     * completa. Eso es lo que permite que el conductor y el copiloto registren
     * traslados sin que la lista de pacientes del hospital llegue a su HTML.
     * La búsqueda va por nombre, apellido o documento, que es como se conoce a
     * un paciente en la guardia.
     */
    public static function buscarPacientes(): void
    {
        requerir_login();

        if (es_rol('paciente')) {
            denegar();
        }

        header('Content-Type: application/json; charset=utf-8');

        $texto = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($texto) < 2) {
            echo json_encode(['ok' => true, 'resultados' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $texto = mb_substr($texto, 0, 80);
        $pdo = db_connect();

        // El comodín % se escapa para que un "%" pegado en el buscador no
        // devuelva la tabla entera.
        $patron = '%' . like_escapar($texto) . '%';

        $stmt = $pdo->prepare(
            'SELECT p.id, u.nombre, u.apellido, u.documento_identidad
               FROM paciente p
               JOIN usuario u ON u.id = p.id
              WHERE p.activo = 1
                AND (u.nombre LIKE ? OR u.apellido LIKE ? OR u.documento_identidad LIKE ?)
              ORDER BY u.nombre, u.apellido
              LIMIT 10'
        );
        $stmt->execute([$patron, $patron, $patron]);

        $resultados = [];
        foreach ($stmt->fetchAll() as $fila) {
            $resultados[] = [
                'id' => (int) $fila['id'],
                'label' => trim($fila['nombre'] . ' ' . $fila['apellido'])
                    . ($fila['documento_identidad'] ? ' — ' . $fila['documento_identidad'] : ''),
            ];
        }

        echo json_encode(['ok' => true, 'resultados' => $resultados], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Formulario para registrar un traslado (GET a /traslados/nuevo).
     *
     * El alta se hace en un modal sobre el mapa del dashboard, con el origen
     * y el destino elegidos en el mapa mismo (GPS, buscador de direcciones o
     * arrastrando el pin). Esta ruta queda como atajo: redirige al mapa con el
     * formulario ya abierto, así los links viejos y el acceso directo siguen
     * funcionando.
     */
    public static function nuevoTraslado(): void
    {
        requerir_login();

        // Los pacientes no pueden ver el mapa, y esta ruta era la única forma
        // de que llegaran al formulario de traslados.
        if (es_rol('paciente')) {
            header('Location: ' . base_path() . '/');
            exit;
        }

        header('Location: ' . base_path() . '/mapa?nuevo=1');
        exit;
    }

    /**
     * Guarda un traslado nuevo (POST a /traslados/nuevo).
     * Valida lo que manda el formulario, resuelve las coordenadas de origen y
     * destino desde la tabla de ubicaciones y registra el traslado en estado
     * pendiente con su historial y el vínculo al paciente/elemento. Responde
     * JSON para que el frontend muestre el resultado sin recargar.
     */
    public static function guardarTraslado(): void
    {
        // Registrar un traslado lo puede hacer cualquiera de la operación salvo
        // el paciente. La lista de pacientes NO viaja en el HTML para estos
        // roles: se busca contra /api/pacientes/buscar recién en el modal, así
        // que un conductor nunca recibe la lista completa del hospital.
        requerir_roles(['admin', 'superadmin', 'conductor', 'copiloto']);

        $pdo = db_connect();

        $responder = function (bool $ok, string $mensaje, array $extra = [], int $codigoHttp = 200): void {
            header('Content-Type: application/json; charset=utf-8');
            if (!$ok) {
                http_response_code($codigoHttp);
            }
            echo json_encode(['ok' => $ok, 'error' => $ok ? null : $mensaje, 'mensaje' => $ok ? $mensaje : null] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        };

        // ------------------------------------------------------------------
        // Lectura y limpieza de los datos del formulario
        // ------------------------------------------------------------------
        $conductorId = (int) ($_POST['conductor_id'] ?? 0);
        $copilotoIdRaw = (string) ($_POST['copiloto_id'] ?? '');
        $vehiculoId = (int) ($_POST['vehiculo_id'] ?? 0);
        $rutaIdRaw = (string) ($_POST['ruta_id'] ?? '');
        $tipo = trim((string) ($_POST['tipo'] ?? ''));
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        $catalogoId = (int) ($_POST['catalogo_elemento_id'] ?? 0);

        // El conductor y el copiloto registran su propio traslado: se lo
        // adjudican a sí mismos. No se confía en el id que venga por POST (el
        // select va deshabilitado en el modal, pero un POST a mano lo podría
        // mandar igual), así que acá se pisa con el de la sesión. Si no se
        // hiciera, un conductor podía crear traslados a nombre de otro y los
        // de ese otro no le aparecían en su lista ni podía simularlos.
        // El copiloto sí se deja elegir: un chofer puede registrar con quién
        // viaja, y a ese otro le tiene que aparecer el traslado también.
        $conductorEsUsuario = es_rol('conductor', 'copiloto');
        if ($conductorEsUsuario) {
            $conductorId = (int) ($_SESSION['usuario_id'] ?? 0);
        }
        // Origen y destino son texto libre: pueden venir de una ruta
        // predefinida, del catálogo de ubicaciones o de una dirección elegida
        // en el mapa (que devuelve Nominatim y puede ser larga). La columna es
        // varchar(200), así que se recorta acá en vez de dejar que reviente el
        // INSERT con un error genérico.
        $origen = mb_substr(trim((string) ($_POST['origen'] ?? '')), 0, 200);
        $destino = mb_substr(trim((string) ($_POST['destino'] ?? '')), 0, 200);
        $fecha = trim((string) ($_POST['fecha_salida'] ?? ''));
        $hora = trim((string) ($_POST['hora_salida'] ?? ''));
        $horaLlegada = trim((string) ($_POST['hora_llegada'] ?? ''));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

        // ------------------------------------------------------------------
        // Validaciones
        // ------------------------------------------------------------------
        if ($conductorId <= 0) {
            $responder(false, 'Seleccioná un conductor válido.', [], 400);
        }

        if (!in_array($tipo, ['paciente', 'insumo', 'equipamiento', 'organo'], true)) {
            $responder(false, 'Seleccioná un tipo de elemento válido.', [], 400);
        }

        if ($origen === '' || $destino === '') {
            $responder(false, 'Seleccioná origen y destino.', [], 400);
        }
        if ($origen === $destino) {
            $responder(false, 'Origen y destino deben ser distintos.', [], 400);
        }
        if ($fecha === '' || $hora === '') {
            $responder(false, 'Completá fecha y hora de salida.', [], 400);
        }

        // Elemento a trasladar según el tipo elegido.
        if ($tipo === 'paciente') {
            if ($pacienteId <= 0) {
                $responder(false, 'Seleccioná un paciente.', [], 400);
            }
        } elseif ($catalogoId <= 0) {
            $responder(false, 'Seleccioná un elemento del catálogo.', [], 400);
        }

        // ------------------------------------------------------------------
        // Existencias (para errores claros antes de tocar FKs)
        // ------------------------------------------------------------------
        // OJO: PDOStatement::execute() devuelve true siempre que la consulta
        // no reviente, haya fila o no. Para saber si el registro existe hay
        // que leer la primera fila con fetchColumn(); con el execute() solo,
        // estas validaciones no filtraban nada y el error terminaba reventando
        // la llave foránea del INSERT (500) en vez de un mensaje claro.
        $existe = function (string $sql, int $id) use ($pdo): bool {
            if ($id <= 0) {
                return false;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchColumn() !== false;
        };

        if ($conductorEsUsuario) {
            // Se valida que sea un funcionario activo de operación. El rol exacto
            // puede ser 'conductor' o 'copiloto': los dos registran su propio
            // traslado, y exigir 'conductor' dejaría afuera a los copilotos.
            if (!$existe("SELECT 1 FROM funcionario f WHERE f.id = ? AND f.rol IN ('conductor', 'copiloto') AND f.activo = 1", $conductorId)) {
                $responder(false, 'Tu usuario no está habilitado para manejar traslados.', [], 400);
            }
        } elseif (!$existe("SELECT 1 FROM funcionario f WHERE f.id = ? AND f.rol = 'conductor' AND f.activo = 1", $conductorId)) {
            $responder(false, 'El conductor seleccionado no está disponible.', [], 400);
        }

        // El vehículo es obligatorio y su id se valida acá: sin este chequeo
        // un id inexistente revienta la llave foránea y el usuario recibe un
        // "no se pudo guardar" genérico en vez de saber qué corregir.
        if (!$existe('SELECT 1 FROM vehiculo WHERE id = ? AND activo = 1', $vehiculoId)) {
            $responder(false, 'El vehículo seleccionado no está disponible.', [], 400);
        }

        $copilotoId = $copilotoIdRaw !== '' ? (int) $copilotoIdRaw : null;
        if ($copilotoId !== null && !$existe("SELECT 1 FROM funcionario f WHERE f.id = ? AND f.rol = 'copiloto' AND f.activo = 1", $copilotoId)) {
            $responder(false, 'El copiloto seleccionado no está disponible.', [], 400);
        }

        $rutaId = $rutaIdRaw !== '' ? (int) $rutaIdRaw : null;
        if ($rutaId !== null && !$existe('SELECT 1 FROM ruta WHERE id = ?', $rutaId)) {
            $responder(false, 'La ruta seleccionada no existe.', [], 400);
        }

        if ($tipo === 'paciente') {
            if (!$existe('SELECT 1 FROM paciente p WHERE p.id = ? AND p.activo = 1', $pacienteId)) {
                $responder(false, 'El paciente seleccionado no está disponible.', [], 400);
            }
        } else {
            $tablaPorTipo = ['insumo' => 'insumo', 'equipamiento' => 'equipamiento', 'organo' => 'organo'];
            $tabla = $tablaPorTipo[$tipo];
            if (!$existe("SELECT 1 FROM {$tabla} WHERE id = ? AND activo = 1", $catalogoId)) {
                $responder(false, 'El elemento seleccionado no está disponible.', [], 400);
            }
        }

        // ------------------------------------------------------------------
        // Coordenadas de origen y destino
        //
        // El formulario puede mandar las coordenadas ya elegidas en el mapa
        // (origen_lat/origen_lng/destino_lat/destino_lng), que es lo que
        // hace el modal del mapa. Si no vienen, se mantiene el comportamiento
        // de siempre: se buscan por nombre en el catálogo de ubicaciones.
        // ------------------------------------------------------------------
        $coordenadaDe = function (string $lugar) use ($pdo): ?array {
            $stmt = $pdo->prepare('SELECT latitud, longitud FROM ubicacion WHERE nombre = ? AND activo = 1');
            $stmt->execute([$lugar]);
            $fila = $stmt->fetch();
            return $fila ? [(float) $fila['latitud'], (float) $fila['longitud']] : null;
        };

        $origenCoord = self::coordenadaDelFormulario($_POST, 'origen', $coordenadaDe);
        $destinoCoord = self::coordenadaDelFormulario($_POST, 'destino', $coordenadaDe);

        // Sin coordenadas no hay ruta que dibujar ni distancia que mostrar, así
        // que el traslado no se puede registrar: antes se guardaba a ciegas y
        // el mapa después no tenía nada que pintar.
        if ($origenCoord === null || $destinoCoord === null) {
            $responder(false, 'No pude ubicar el recorrido. Elegí el origen y el destino en el mapa.', [], 400);
        }

        // ------------------------------------------------------------------
        // Distancia y duración reales por calles (OSRM, con caché de 30 días).
        // Se calculan UNA vez acá y quedan guardadas en el traslado: /api/mapa
        // se consulta cada 5 segundos y no puede pegarle a OSRM en cada poll.
        // ------------------------------------------------------------------
        $ruta = RutaService::obtenerRutaReal($origenCoord[0], $origenCoord[1], $destinoCoord[0], $destinoCoord[1]);
        $distanciaKm = $ruta !== null ? (float) $ruta['distance_km'] : null;
        $duracionMin = $ruta !== null ? (float) $ruta['duration_min'] : null;

        // ------------------------------------------------------------------
        // Código del traslado: TR-<año><secuencial> según el mayor registrado.
        // ------------------------------------------------------------------
        $anio = date('y');
        $sec = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 6) AS UNSIGNED)), 0) FROM traslado WHERE codigo LIKE 'TR-{$anio}%'")->fetchColumn();
        $codigo = 'TR-' . $anio . str_pad((string) ((int) $sec + 1), 3, '0', STR_PAD_LEFT);

        // ------------------------------------------------------------------
        // Guardado en una transacción
        // ------------------------------------------------------------------
        $horaSalidaEstimada = $fecha . ' ' . $hora . ':00';

        // La llegada se deriva del tiempo real de la ruta cuando se conoce;
        // si el proveedor de rutas no respondió, se respeta la que calculó el
        // frontend a velocidad promedio.
        $horaLlegadaDestino = null;
        if ($duracionMin !== null) {
            $horaLlegadaDestino = date(
                'Y-m-d H:i:s',
                strtotime($horaSalidaEstimada) + (int) round($duracionMin * 60)
            );
        } elseif ($horaLlegada !== '') {
            $horaLlegadaDestino = $fecha . ' ' . $horaLlegada . ':00';
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO traslado
                   (codigo, conductor_id, copiloto_id, vehiculo_id, ruta_id,
                    origen, origen_lat, origen_lng, destino, destino_lat, destino_lng,
                    distancia_km, duracion_min,
                    hora_salida_estimada, hora_llegada_destino, estado, registrado_por, observaciones)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)"
            );
            $stmt->execute([
                $codigo,
                $conductorId,
                $copilotoId,
                $vehiculoId > 0 ? $vehiculoId : null,
                $rutaId,
                $origen,
                $origenCoord[0],
                $origenCoord[1],
                $destino,
                $destinoCoord[0],
                $destinoCoord[1],
                $distanciaKm,
                $duracionMin,
                $horaSalidaEstimada,
                $horaLlegadaDestino,
                (int) $_SESSION['usuario_id'],
                $observaciones !== '' ? $observaciones : null,
            ]);
            $trasladoId = (int) $pdo->lastInsertId();

            // Catálogo de ubicaciones: si el origen o el destino son una
            // dirección nueva (elegida en el mapa y no cargada antes), se
            // guardan con sus coordenadas para que la próxima vez salgan en el
            // selector sin volver a escribirlas. Si el nombre ya existía, se
            // actualizan las coordenadas: el mapa manda sobre el catálogo viejo.
            self::registrarEnCatalogo($pdo, $origen, $origenCoord);
            self::registrarEnCatalogo($pdo, $destino, $destinoCoord);

            if ($tipo === 'paciente') {
                $pdo->prepare('INSERT INTO paciente_traslado (traslado_id, paciente_id) VALUES (?, ?)')
                    ->execute([$trasladoId, $pacienteId]);
            } else {
                $tabla = $tablaPorTipo[$tipo];
                $pdo->prepare("UPDATE {$tabla} SET traslado_id = ? WHERE id = ?")
                    ->execute([$trasladoId, $catalogoId]);
            }

            $pdo->prepare(
                "INSERT INTO historial_estado (traslado_id, estado_anterior, estado_nuevo, observacion, actualizado_por)
                 VALUES (?, NULL, 'pendiente', 'Traslado registrado', ?)"
            )->execute([$trasladoId, (int) $_SESSION['usuario_id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $responder(false, 'No se pudo guardar el traslado. Intentalo de nuevo.', [], 500);
        }

        $responder(true, 'Traslado ' . $codigo . ' registrado.', [
            'traslado_id' => $trasladoId,
            'codigo'      => $codigo,
            'distancia_km' => $distanciaKm,
            'duracion_min' => $duracionMin,
        ]);
    }

    /**
     * Resuelve las coordenadas de un extremo del recorrido (origen o destino).
     *
     * Prioridad:
     *   1. Lo que manda el formulario (origen_lat/origen_lng): es lo que elige
     *      el usuario en el mapa, y es válido solo si el par está en rango.
     *   2. Lo que ya estaba en el catálogo de ubicaciones con ese nombre, para
     *      que el formulario de siempre siga funcionando sin tocar el mapa.
     *
     * Devuelve [lat, lng] o null si no hay ninguna de las dos.
     */
    private static function coordenadaDelFormulario(array $post, string $campo, callable $porNombre): ?array
    {
        $lat = filter_var($post[$campo . '_lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($post[$campo . '_lng'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($lat !== false && $lng !== false && GeocodeService::coordenadaValida((float) $lat, (float) $lng)) {
            return [(float) $lat, (float) $lng];
        }

        $porCatálogo = $porNombre(trim((string) ($post[$campo] ?? '')));

        return is_array($porCatálogo) ? $porCatálogo : null;
    }

    /**
     * Guarda un lugar en el catálogo de ubicaciones o, si el nombre ya existe,
     * le actualiza las coordenadas. Se llama dentro de la transacción del
     * traslado, así que si después algo falla, el catálogo tampoco queda con
     * el lugar a medio agregar.
     *
     * El catálogo es una lista curada de lugares del hospital (pabellones,
     * clínicas, centros). Por eso SOLO admin y superadmin lo escriben: antes
     * lo escribía cualquier conductor o copiloto al registrar un traslado, y
     * el mapa terminaba con un marcador por cada "Mi ubicación" y cada
     * dirección que alguien Buscó una vez —incluidas las de Montevideo, que
     * quedaban al lado de los lugares de Paysandú sin explicación.
     *
     * Un traslado nunca necesita escribir acá: guarda sus propias coordenadas
     * en la tabla traslado. El catálogo es para reutilizar lugares, no para
     * registrar direcciones.
     */
    private static function registrarEnCatalogo(PDO $pdo, string $nombre, array $coord): void
    {
        if (!in_array(rol_usuario(), ['admin', 'superadmin'], true)) {
            return;
        }

        if ($nombre === '') {
            return;
        }

        // "Mi ubicación" y los nombres de dos letras no son lugares: son
        // pruebas o de la posición GPS del chofer, y como marcador en el mapa
        // no significan nada.
        if (self::nombreBasuraEnCatalogo($nombre)) {
            return;
        }

        $pdo->prepare(
            'INSERT INTO ubicacion (nombre, latitud, longitud) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE latitud = VALUES(latitud), longitud = VALUES(longitud)'
        )->execute([$nombre, $coord[0], $coord[1]]);
    }

    /**
     * ¿El nombre no es un lugar real y por lo tanto no va al catálogo?
     *
     * Filtra lo que se cuela por el autocompletado: la posición del GPS del
     * chofer ("Mi ubicación"), nombres demasiado cortos para identificar un
     * lugar ("A", "B") y los que en realidad son solo una dirección suelta.
     */
    private static function nombreBasuraEnCatalogo(string $nombre): bool
    {
        $nombre = trim($nombre);

        // La posición del chofer o un pin soltado a mano.
        if (preg_match('/^mi\s+ubicaci[oó]n$/iu', $nombre)) {
            return true;
        }

        // "Mi ubicación en Montevideo", "Punto en el mapa", etc.
        if (preg_match('/^mi\s+ubicaci[oó]n\b/iu', $nombre) || preg_match('/^punto\s+en\s+el\s+mapa$/iu', $nombre)) {
            return true;
        }

        // Menos de 3 letras: "A", "B", "CL", no identifican un lugar.
        if (mb_strlen($nombre) < 3) {
            return true;
        }

        // Solo un número decimal: un par de coordenadas pegado como nombre.
        if (preg_match('/^-?\d+([.,]\d+)?$/', $nombre)) {
            return true;
        }

        return false;
    }

    /**
     * Cambia el estado de un traslado (POST a /traslados/estado).
     * Solo admin/superadmin o el conductor asignado. Respeta las transiciones
     * válidas definidas en TRANSICIONES: cancelar exige motivo, y al pasar a
     * en_curso el conductor no puede tener otro traslado activo. Al avanzar se
     * registran los horarios (salida efectiva, llegada a destino, retorno,
     * llegada al hospital) y queda todo apuntado en el historial. Respuesta JSON.
     */
    public static function cambiarEstadoTraslado(): void
    {
        requerir_login();

        $responder = function (bool $ok, string $mensaje, array $extra = [], int $codigoHttp = 200): void {
            header('Content-Type: application/json; charset=utf-8');
            if (!$ok) {
                http_response_code($codigoHttp);
            }
            echo json_encode(['ok' => $ok, 'error' => $ok ? null : $mensaje, 'mensaje' => $ok ? $mensaje : null] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        };

        $trasladoId = (int) ($_POST['traslado_id'] ?? 0);
        $nuevoEstado = trim((string) ($_POST['estado'] ?? ''));
        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if ($trasladoId <= 0) {
            $responder(false, 'Traslado inválido.', [], 400);
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare('SELECT * FROM traslado WHERE id = ?');
        $stmt->execute([$trasladoId]);
        $traslado = $stmt->fetch();
        if (!$traslado) {
            $responder(false, 'Traslado no encontrado.', [], 404);
        }

        $rol = rol_usuario();
        $usuarioId = (int) $_SESSION['usuario_id'];

        // El paciente aparece en el mapa (ve los traslados en los que
        // participa) pero jamás los mueve: el estado lo cambia el despacho o
        // el conductor. La comparación de $rol contra admin/superadmin ya lo
        // dejaba afuera, pero se corta explícitamente para que la intención
        // quede escrita y no dependa de que conductor_id nunca coincida con el
        // id de un paciente.
        if ($rol === 'paciente') {
            $responder(false, 'No tenés permiso para modificar un traslado.', [], 403);
        }

        if ($rol !== 'admin' && $rol !== 'superadmin' && (int) $traslado['conductor_id'] !== $usuarioId) {
            $responder(false, 'No tenés permiso para modificar este traslado.', [], 403);
        }

        $actual = (string) $traslado['estado'];
        if (!in_array($nuevoEstado, self::TRANSICIONES[$actual] ?? [], true)) {
            $responder(false, 'No se puede pasar de ' . $actual . ' a ' . $nuevoEstado . '.', [], 400);
        }

        if ($nuevoEstado === 'cancelado' && $motivo === '') {
            $responder(false, 'Para cancelar el traslado tenés que indicar el motivo.', [], 400);
        }

        if ($nuevoEstado === 'en_curso') {
            $activos = $pdo->prepare(
                "SELECT id FROM traslado
                 WHERE conductor_id = ? AND estado IN ('en_curso', 'en_destino', 'en_retorno') AND id <> ?"
            );
            $activos->execute([$traslado['conductor_id'], $trasladoId]);
            if ($activos->fetch()) {
                $responder(false, 'El conductor ya tiene un traslado activo.', [], 400);
            }
        }

        $now = date('Y-m-d H:i:s');
        $setTimestamps = '';
        if ($nuevoEstado === 'en_curso') {
            $setTimestamps = 'hora_salida_efectiva = ?,';
        } elseif ($nuevoEstado === 'en_destino') {
            $setTimestamps = 'hora_llegada_destino = ?,';
        } elseif ($nuevoEstado === 'en_retorno') {
            $setTimestamps = 'hora_inicio_retorno = ?,';
        } elseif ($nuevoEstado === 'completado') {
            $setTimestamps = 'hora_llegada_hospital = ?,';
        }

        $motivoCancelacion = $nuevoEstado === 'cancelado' ? $motivo : null;
        $observacionHistorial = $nuevoEstado === 'cancelado' ? 'Cancelado: ' . $motivo : null;

        try {
            $pdo->beginTransaction();

            if ($setTimestamps !== '') {
                $pdo->prepare("UPDATE traslado SET " . $setTimestamps . " estado = ?, motivo_cancelacion = ? WHERE id = ?")
                    ->execute([$now, $nuevoEstado, $motivoCancelacion, $trasladoId]);
            } else {
                $pdo->prepare('UPDATE traslado SET estado = ?, motivo_cancelacion = ? WHERE id = ?')
                    ->execute([$nuevoEstado, $motivoCancelacion, $trasladoId]);
            }

            $pdo->prepare(
                "INSERT INTO historial_estado (traslado_id, estado_anterior, estado_nuevo, observacion, actualizado_por)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$trasladoId, $actual, $nuevoEstado, $observacionHistorial, $usuarioId]);

            // Si el traslado terminó (o se canceló), la última posición del
            // conductor deja de servir: se borra para que el mapa no deje una
            // ambulancia clavada para siempre en el hospital.
            if ($nuevoEstado === 'completado' || $nuevoEstado === 'cancelado') {
                $pdo->prepare('DELETE FROM ubicacion_conductor WHERE conductor_id = ?')
                    ->execute([(int) $traslado['conductor_id']]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $responder(false, 'No se pudo actualizar el estado del traslado.', [], 500);
        }

        $responder(true, 'Traslado ' . (string) $traslado['codigo'] . ' actualizado.',
            ['estado' => $nuevoEstado, 'traslado_id' => $trasladoId]);
    }
}
