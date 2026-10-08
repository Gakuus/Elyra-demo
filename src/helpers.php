<?php

declare(strict_types=1);

/**
 * helpers.php: funciones globales compartidas por los controladores.
 */

/**
 * Ruta base según la que se está sirviendo la app ('' en la raíz,
 * '/carpeta' si cuelga de una subcarpeta). Los enlaces y redirecciones
 * dependen de esto.
 *
 * APP_URL declara el prefijo con el que se publica la app (en el servidor son
 * '/proyectos/elyra'). Para que ese mismo .env también sirva en el servidor
 * local, que la publica en la raíz, el prefijo declarado se usa SOLO si la
 * petición viene por debajo de él; en cualquier otro caso se asume raíz. Así
 * cambiar APP_URL para el despliegue no rompe el desarrollo local.
 */
function base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $appUrlPath = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_PATH);
    $declarado = rtrim(is_string($appUrlPath) ? $appUrlPath : '', '/');

    // La ruta de la petición, sin query ni dominio.
    $ruta = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $ruta = is_string($ruta) ? $ruta : '';

    $basePath = ($declarado !== '' && ($ruta === $declarado || str_starts_with($ruta, $declarado . '/')))
        ? $declarado
        : '';

    return $basePath;
}

/**
 * Token anti-CSRF de la sesión. Se genera una sola vez por sesión y se
 * reutiliza en todas las páginas: el navegador lo reenvía en cada POST y el
 * servidor comprueba que coincida con el que tiene guardado.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida el token recibido. Acepta el campo oculto _csrf de los formularios y
 * la cabecera X-CSRF-Token de las peticiones fetch. La comparación es en
 * tiempo constante (hash_equals) para no poder deducir el valor a fuerza de
 * intentos.
 */
function csrf_valido(): bool
{
    $esperado = (string) ($_SESSION['csrf_token'] ?? '');
    if ($esperado === '') {
        return false;
    }

    $recibido = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($recibido) || $recibido === '') {
        return false;
    }

    return hash_equals($esperado, $recibido);
}

/** Guard de páginas privadas: sin sesión, redirige a /login y corta. */
function requerir_login(): void
{
    if (!Auth::estaAutenticado()) {
        header('Location: ' . base_path() . '/login');
        exit;
    }
}

/** Rol del usuario logueado, o '' si no hay sesión. */
function rol_usuario(): string
{
    return (string) ($_SESSION['usuario_rol'] ?? '');
}

/** True si el rol del usuario está dentro de la lista dada. */
function es_rol(string ...$roles): bool
{
    return in_array(rol_usuario(), $roles, true);
}

/** True si el usuario es admin o superadmin (perfil de gestión). */
function es_gestion(): bool
{
    return es_rol('admin', 'superadmin');
}

/** Responde 403 en JSON y corta. Lo usan los guards para las mutaciones AJAX. */
function denegar(): void
{
    log_seguridad('acceso_denegado');
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permiso denegado.']);
    exit;
}

/**
 * Guard de páginas que exigen un rol además de la sesión. Sin sesión → /login;
 * con rol insuficiente: GET redirige al dashboard, POST responde 403 JSON.
 */
function requerir_roles(array $roles): void
{
    requerir_login();
    if (!in_array(rol_usuario(), $roles, true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            denegar();
        }
        header('Location: ' . base_path() . '/dashboard');
        exit;
    }
}

/** Guard de gestión: solo admin/superadmin. */
function requerir_gestion(): void
{
    requerir_roles(['admin', 'superadmin']);
}

// ==========================================================================
// Seguridad
// ==========================================================================

/**
 * Clave secreta de la aplicación, para firmar datos (tokens de documentos).
 * Sale del .env; si falta, se deriva de la contraseña de la BD para no quedar
 * sin secreto. En producción SIEMPRE conviene definir APP_SECRET.
 */
function secreto_app(): string
{
    $secreto = (string) ($_ENV['APP_SECRET'] ?? '');
    if ($secreto !== '') {
        return $secreto;
    }
    return hash('sha256', 'elyra::' . (string) ($_ENV['DB_PASSWORD'] ?? 'elyra'));
}

/**
 * Token público de un documento: un HMAC de su id. No hace falta guardarlo en
 * la base de datos y no se puede adivinar sin el secreto, así que /publico/doc
 * ya no se puede recorrer probando ids (1, 2, 3...).
 */
function token_documento(int $id): string
{
    return substr(hash_hmac('sha256', 'documento:' . $id, secreto_app()), 0, 40);
}

/** Comprueba el token de un documento en tiempo constante. */
function token_documento_valido(int $id, ?string $token): bool
{
    if ($id <= 0 || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals(token_documento($id), $token);
}

/**
 * Valida que una ruta de PDF esté realmente dentro de storage/docs y devuelve
 * su ruta real (o null). Evita que un archivo_path manipulado en la BD haga
 * que el servidor lea/entregue cualquier archivo del disco.
 */
function ruta_pdf_segura(?string $ruta): ?string
{
    if ($ruta === null || $ruta === '') {
        return null;
    }
    $base = realpath(__DIR__ . '/../storage/docs');
    $real = realpath($ruta);
    if ($base === false || $real === false || $real === $base) {
        return null;
    }
    return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
}

/**
 * Escapa los comodines de LIKE (% y _) y la barra invertida de un texto de
 * búsqueda, para que el usuario no pueda inyectar patrones.
 */
function like_escapar(string $texto): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
}

/** IP del cliente que hizo la petición. */
function ip_usuario(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Limitador de intentos por ventana de tiempo (rate limiting), respaldado en
 * un archivo con lock. Devuelve true si la acción está permitida. Se usa para
 * frenar la fuerza bruta contra el login y el abuso de la encuesta pública.
 */
function rate_limit_permitido(string $clave, int $max = 5, int $ventanaSeg = 900): bool
{
    $dir = __DIR__ . '/../storage/rate-limit';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return true; // sin almacenamiento no bloqueamos el servicio
    }

    $archivo = $dir . '/' . hash('sha256', $clave) . '.json';
    $fp = @fopen($archivo, 'c+');
    if ($fp === false) {
        return true;
    }

    flock($fp, LOCK_EX);
    $contenido = stream_get_contents($fp);
    $datos = json_decode((string) $contenido, true);
    $ahora = time();

    if (!is_array($datos) || (int) ($datos['reset'] ?? 0) <= $ahora) {
        $datos = ['count' => 0, 'reset' => $ahora + $ventanaSeg];
    }

    $datos['count'] = (int) ($datos['count'] ?? 0) + 1;
    $permitido = $datos['count'] <= $max;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($datos));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $permitido;
}

/** Borra el contador de intentos de una clave (p. ej. tras un login correcto). */
function rate_limit_reset(string $clave): void
{
    $archivo = __DIR__ . '/../storage/rate-limit/' . hash('sha256', $clave) . '.json';
    if (is_file($archivo)) {
        @unlink($archivo);
    }
}

/**
 * Bitácora de seguridad: una línea JSON por evento en storage/logs. Guarda
 * logins fallidos, bloqueos por fuerza bruta, CSRF inválido y accesos
 * denegados. No registra contraseñas ni tokens.
 */
function log_seguridad(string $evento, array $contexto = []): void
{
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return;
    }

    $registro = [
        'ts'      => date('c'),
        'evento'  => $evento,
        'ip'      => ip_usuario(),
        'usuario' => (string) ($_SESSION['usuario_username'] ?? ''),
        'metodo'  => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'ruta'    => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    ] + $contexto;

    @file_put_contents(
        $dir . '/seguridad-' . date('Y-m-d') . '.log',
        json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Envía las cabeceras de seguridad de la respuesta. La CSP es permisiva a
 * propósito: el proyecto usa CDNs (Bootstrap, Leaflet) e inline handlers, así
 * que permite 'unsafe-inline' y esos hosts, pero igual cierra default-src,
 * object-src, frame-ancestors y form-action.
 */
function cabeceras_seguridad(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    // HSTS solo tiene sentido (y solo se envía) cuando la conexión es HTTPS.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'self'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self' https:;"
    );
}

/**
 * Motor de plantillas: expande marcadores y bucles en un HTML.
 *
 *   {{clave}}             → valor escalar.
 *   {{#clave}}…{{/clave}} → se repite una vez por fila de $datos['clave'];
 *                           dentro del bloque, cada campo es {{fila.campo}}.
 *   {{^clave}}…{{/clave}} → se muestra una vez cuando $datos['clave'] está vacío.
 */
function reemplazar_marcadores(string $html, array $datos): string
{
    foreach ($datos as $clave => $valor) {
        $rx = preg_quote($clave, '/');

        if (!is_array($valor)) {
            // El raw va PRIMERO que el escape: {{{clave}}} contiene {{clave}},
            // asi que si se escapa antes, el escapado deja llaves sueltas.
            $html = str_replace('{{{' . $clave . '}}}', (string) $valor, $html);

            // {{clave}} ESCAPA siempre. doble_encode=false evita romper los
            // controladores que ya escapan con htmlspecialchars() por su cuenta
            // (no se vuelven a escapar las entidades).
            $html = str_replace(
                '{{' . $clave . '}}',
                htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8', false),
                $html
            );
            continue;
        }

        // Bloque positivo {{#clave}}: una repetición por fila.
        $html = preg_replace_callback(
            '/\{\{\#' . $rx . '\}\}(.*?)\{\{\/' . $rx . '\}\}/s',
            function (array $m) use ($datos, $clave, $valor): string {
                $salida = '';
                foreach ($valor as $fila) {
                    // Cada fila hereda el contexto del padre y expone sus campos.
                    $ctx = $datos;
                    unset($ctx[$clave]);
                    if (!is_array($fila)) {
                        // Fila escalar: se referencia como {{fila}}.
                        $ctx['fila'] = $fila;
                        $salida .= reemplazar_marcadores($m[1], $ctx);
                        continue;
                    }
                    foreach ($fila as $campo => $v) {
                        $ctx['fila.' . $campo] = $v;
                    }
                    $salida .= reemplazar_marcadores($m[1], $ctx);
                }
                return $salida;
            },
            $html
        );

        // Bloque vacío {{^clave}}: solo se muestra cuando no hay filas.
        $html = preg_replace_callback(
            '/\{\{\^' . $rx . '\}\}(.*?)\{\{\/' . $rx . '\}\}/s',
            fn(array $m): string => $valor === [] ? $m[1] : '',
            $html
        );
    }

    // Defensa: se borran marcadores y bloques de filas/claves que no vinieron
    // en $datos, para que jamás queden literales en pantalla.
    $html = preg_replace('/\{\{(\^|#)fila\.[a-zA-Z0-9_.]+\}\}.*?\{\{\/fila\.[a-zA-Z0-9_.]+\}\}/s', '', $html);
    $html = preg_replace('/\{\{fila\.[a-zA-Z0-9_.]+\}\}/', '', $html);

    // Un {{{clave}}} sin dato se borra entero; si no, el {{clave}} de adentro
    // lo consumiría el cleanup de abajo y dejaría "{}" en pantalla.
    $html = preg_replace('/\{\{\{[a-zA-Z0-9_.]+\}\}\}/', '', $html);

    $claves = array_keys($datos);
    $html = preg_replace_callback(
        '/\{\{([#^])([a-zA-Z0-9_.]+)\}\}.*?\{\{\/\2\}\}/s',
        fn(array $m): string => in_array($m[2], $claves, true) ? $m[0] : '',
        $html
    );

    return preg_replace_callback(
        '/\{\{([a-zA-Z0-9_.]+)\}\}/',
        // base_path lo inyecta siempre render_vista por su cuenta.
        fn(array $m): string => in_array($m[1], $claves, true) || $m[1] === 'base_path' ? $m[0] : '',
        $html
    );
}

/**
 * Arma la cabecera Content-Disposition de forma segura.
 *
 * El nombre viene del archivo que subió el usuario, así que puede traer
 * comillas, barras o caracteres de control. Meterlo crudo entre comillas
 * rompería la cabecera. Se arma según RFC 6266: un "filename" ASCII de
 * respaldo para navegadores viejos y "filename*" con el nombre real
 * codificado, que es donde corresponde el nombre con tildes.
 *
 * disposicion: 'inline' para mostrar, 'attachment' para forzar la descarga.
 * nombre:      el nombre del archivo (sin ruta; ya viene con basename()).
 */
function content_disposition(string $disposicion, string $nombre): string
{
    // Respaldo ASCII: se descarta todo lo que no sea imprimible (incluye CR y
    // LF, que romperían la cabecera) y luego las comillas, la barra y el punto
    // y coma, que algunos navegadores antiguos toman como separador aunque
    // el valor esté entre comillas.
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $nombre) ?? '';
    $ascii = str_replace(['"', '\\', ';'], '_', $ascii);
    if (trim($ascii) === '' || $ascii === '.pdf') {
        $ascii = 'documento.pdf';
    }

    return 'Content-Disposition: ' . $disposicion
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($nombre);
}

/**
 * Pinta una vista reemplazando marcadores y bucles por sus valores.
 */
function render_vista(string $archivo, array $datos): void
{
    $html = file_get_contents($archivo);
    if ($html === false) {
        http_response_code(500);
        echo 'Vista no encontrada: ' . htmlspecialchars($archivo);
        return;
    }
    // base_path y csrf_token se inyectan siempre: todas las vistas los usan.
    $datos = ['base_path' => base_path(), 'csrf_token' => csrf_token()] + $datos;
    echo reemplazar_marcadores($html, $datos);
}

/**
 * Renderiza un fragmento del dashboard a una CADENA (sin layout). Lo usan los
 * endpoints AJAX para devolver el HTML expandido dentro del JSON.
 */
function render_contenido(string $vista, array $datos): string
{
    $html = file_get_contents(__DIR__ . '/../views/dashboard/fragmentos/' . $vista . '.html');
    return $html !== false ? reemplazar_marcadores($html, $datos) : '';
}

/** Página de error 404 del sistema. */
function pagina_404(): void
{
    http_response_code(404);
    $html = '<!DOCTYPE html><html lang="es"><head>'
        . '<meta charset="UTF-8">'
        . '<base href="' . htmlspecialchars(base_path() . '/') . '">'
        . '<title>404 — Página no encontrada</title>'
        . '<link href="css/base.css?v=' . time() . '" rel="stylesheet">'
        . '</head><body>'
        . '<h1 class="pagina-404">404 — Página no encontrada</h1>'
        . '</body></html>';
    echo $html;
}

/**
 * Pinta una página del dashboard dentro del layout compartido. Cada vista solo
 * aporta su bloque central; el layout lo arma render_vista.
 */
function render_dashboard(string $vista, string $titulo, string $seccion, array $datos): void
{
    $base = __DIR__ . '/../views/dashboard/';

    $usuario = htmlspecialchars((string) ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    $contenido = file_get_contents($base . $vista . '.html');
    if ($contenido === false) {
        pagina_404();
        return;
    }

    // El token de CSRF va ACÁ también, no solo en el layout: la vista interna
    // se renderiza antes de armarse el layout, y si csrf_token no está en sus
    // datos, el cleanup de reemplazar_marcadores() borra el {{csrf_token}} de
    // los formularios de la vista y los deja con value="" — o sea, todos los
    // <form method="post"> del dashboard rebotaban con 403.
    $contenido = reemplazar_marcadores($contenido, [
        'usuario'    => $usuario,
        'csrf_token' => csrf_token(),
    ] + $datos);

    // Enlace activo del menú según la sección en la que el usuario está parado.
    $activo = [
        'inicio'          => ['activo_inicio' => ' active', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => '', 'activo_insumos' => '', 'activo_mapa' => ''],
        'mapa'            => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => '', 'activo_insumos' => '', 'activo_mapa' => ' active'],
        'encuestas'       => ['activo_inicio' => '', 'activo_encuestas' => ' active', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => '', 'activo_insumos' => '', 'activo_mapa' => ''],
        'documentos'      => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => ' active', 'activo_vehiculos' => '', 'activo_usuarios' => '', 'activo_insumos' => '', 'activo_mapa' => ''],
        'vehiculos'       => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => ' active', 'activo_usuarios' => '', 'activo_insumos' => '', 'activo_mapa' => ''],
        'usuarios'        => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => ' active', 'activo_insumos' => '', 'activo_mapa' => ''],
        'insumos'         => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => '', 'activo_insumos' => ' active', 'activo_mapa' => ''],
    ][$seccion] ?? [];

    // Visibilidad del menú según el rol: los bloques {{#es_gestion}} y
    // {{#es_vehiculo}} del layout solo se muestran cuando corresponda.
    // es_paciente apaga "Inicio": el paciente no tiene panel, su /dashboard lo
    // manda al mapa, así que el enlace solo lo haría rebotar.
    $menu = [
        'es_gestion'  => es_gestion() ? ['1'] : [],
        'es_vehiculo' => es_rol('admin', 'superadmin', 'conductor') ? ['1'] : [],
        'es_paciente' => es_rol('paciente') ? ['1'] : [],
    ];

    render_vista($base . 'layout.html', array_merge([
        'titulo'    => $titulo,
        'usuario'   => $usuario,
        'contenido' => $contenido,
        'base_path' => base_path(),
    ], $activo, $menu));
}