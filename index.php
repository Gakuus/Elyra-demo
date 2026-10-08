<?php

declare(strict_types=1);

/**
 * index.php: PUNTO DE ENTRADA ÚNICO de la aplicación (front controller).
 *
 * Todas las peticiones pasan por acá (el servidor dev PHP redirige todo a
 * este archivo). En orden, hace: carga el .env, sirve los estáticos de
 * public/, carga las clases del proyecto, inicia la sesión y por último
 * mira la URL para llamar al controlador que corresponda.
 */

// ------------------------------------------------------------------
// 1) Carga de .env
// ------------------------------------------------------------------
// El archivo .env tiene las configuraciones (URL, datos de la base de datos).
// Se lee línea por línea y cada variable queda disponible en $_ENV.
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora líneas de comentario (empiezan con #).
        if (str_starts_with(trim($line), '#')) continue;
        // Cada línea con '=' es una variable: NOMBRE=VALOR.
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2); // Parte en 2 por el primer '='.
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// ------------------------------------------------------------------
// 1.b) Zona horaria
// ------------------------------------------------------------------
// PHP por defecto usa UTC, pero el sistema (y por lo tanto MySQL) está en
// hora local de Uruguay. Sin esto, el saludo y los "hace X" quedaban 3 horas
// adelantados (a las 17:00 decía "Buenas noches"). Se puede cambiar con la
// variable APP_TIMEZONE del .env.
date_default_timezone_set((string) ($_ENV['APP_TIMEZONE'] ?? 'America/Montevideo'));

// ------------------------------------------------------------------
// 2) Archivos estáticos (public/)
// ------------------------------------------------------------------
// URL pedida por el navegador, solo la parte de ruta (sin dominio ni query).
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si la app vive en una subcarpeta (ej: /elyra), hay que restarle ese
// prefijo a la URL para resolver archivos dentro de public/.
$appUrlPath = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_PATH);
$basePath = rtrim(is_string($appUrlPath) ? $appUrlPath : '', '/');
$staticRel = $basePath !== '' && str_starts_with($uri, $basePath) ? substr($uri, strlen($basePath)) : $uri;
$staticRel = $staticRel === '' ? '/' : $staticRel;

// Los archivos estáticos viven dentro de public/. Las URL se escriben con el
// prefijo public/ (ej: /public/css/base.css), tanto en Linux como en Windows.
// Normaliza la ruta por si viene con o sin ese segmento y la resuelve dentro
// de la carpeta public/ del proyecto (portable a cualquier docroot).
if ($staticRel !== '/' && !str_contains($staticRel, '.php')) {
    // Si la ruta ya empieza con /public, lo quitamos para buscar dentro de
    // la carpeta public/ (el prefijo es solo de URL, no del filesystem).
    $rel = str_starts_with($staticRel, '/public')
        ? substr($staticRel, strlen('/public'))
        : $staticRel;
    $file = __DIR__ . '/public' . $rel;
    // Descarta rutas con subidas de directorio (../): solo se sirven
    // archivos dentro de public/ (por seguridad).
    if (str_contains($rel, '..')) {
        $file = '';
    }
    if ($file !== '' && is_file($file)) {
        // Detecta el tipo MIME según la extensión del archivo.
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => null, // Extensión desconocida → no se sirve aquí.
        };
        if ($mime !== null) {
            // Envía el archivo con su tipo y caché por 1 hora (rendimiento).
            // Content-Length evita la transferencia por trozos y mejora la caché.
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($file));
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            exit; // Termina la petición: no se procesa ninguna ruta PHP.
        }
    }
}

// ------------------------------------------------------------------
// 3) Carga de dependencias
// ------------------------------------------------------------------
// Los require_once incluyen cada archivo una sola vez (evita redefiniciones).
require_once __DIR__ . '/config/database.php';      // Función db_connect().
require_once __DIR__ . '/src/Auth.php';             // Clase Auth (login, registro).
require_once __DIR__ . '/src/helpers.php';          // Funciones auxiliares (base_path, render...).
require_once __DIR__ . '/src/GeoapifyService.php';    // Geoapify (mapa/rutas) con caída a OSRM.
require_once __DIR__ . '/src/InterseccionService.php'; // "calle A y calle B" -> punto de cruce (Overpass).
require_once __DIR__ . '/src/RutaService.php';      // Rutas reales por calles (OSRM) con caché.
require_once __DIR__ . '/src/GeocodeService.php';    // Texto <-> coordenadas (Nominatim) con caché.
require_once __DIR__ . '/src/Controller/AuthController.php';
require_once __DIR__ . '/src/Controller/DashboardController.php';
require_once __DIR__ . '/src/Controller/DocumentoData.php';             // Trait de datos del módulo de documentos.
require_once __DIR__ . '/src/Controller/DocumentoArchivoController.php';
require_once __DIR__ . '/src/Controller/DocumentoPublicoController.php';
require_once __DIR__ . '/src/Controller/DocumentoController.php';
require_once __DIR__ . '/src/Controller/EncuestaData.php';              // Trait de datos del módulo de encuestas.
require_once __DIR__ . '/src/Controller/EncuestaController.php';
require_once __DIR__ . '/src/Controller/EncuestaResultadosController.php';
require_once __DIR__ . '/src/Controller/EncuestaPublicaController.php';
require_once __DIR__ . '/src/Controller/VehiculoController.php';
require_once __DIR__ . '/src/Controller/InsumoData.php';                 // Trait de datos del módulo de insumos.
require_once __DIR__ . '/src/Controller/InsumoController.php';
require_once __DIR__ . '/src/Controller/UsuarioData.php';                 // Trait de datos del módulo de usuarios.
require_once __DIR__ . '/src/Controller/UsuarioController.php';
require_once __DIR__ . '/src/Controller/UsuarioEdicionController.php';
require_once __DIR__ . '/src/Controller/UsuarioCodigosController.php';

// ------------------------------------------------------------------
// 4) Cabeceras de seguridad
// ------------------------------------------------------------------
// Van antes de cualquier salida: CSP, anti-sniffing, anti-framing, HSTS
// (solo sobre HTTPS) y política de referencia. cubre todas las rutas.
cabeceras_seguridad();

// ------------------------------------------------------------------
// 5) Sesión
// ------------------------------------------------------------------
// Inicia (o reanuda) la sesión para que $_SESSION esté disponible en todo.
Auth::iniciarSesion();

// ------------------------------------------------------------------
// 6) Rutas
// ------------------------------------------------------------------
// Datos de la petición actual: método HTTP (GET/POST...) y ruta.
$method = $_SERVER['REQUEST_METHOD'];
$path = $staticRel;

// ------------------------------------------------------------------
// CSRF: todo POST tiene que traer el token de la sesión.
// Se comprueba UNA sola vez acá, antes de que cualquier controlador vea la
// petición, así no hay que repetir el chequeo en cada una de las 18 rutas
// POST. Sin esto, un formulario de otra página podía hacer que el navegador
// de un usuario logueado envíe un POST a Elyra (crear Surveys, desactivar
// cuentas, subir documentos) sin que él lo haya pedido.
// ------------------------------------------------------------------
if ($method === 'POST' && !csrf_valido()) {
    log_seguridad('csrf_invalido');
    http_response_code(403);
    exit('403 — Token de seguridad inválido. Recargá la página e intentá de nuevo.');
}

// switch(true) es un "switch de condiciones": evalúa cada case en orden y
// ejecuta el primero que sea verdadero. Cada case llama a un controlador.
switch (true) {
    // Flujos de autenticación (portada, login, registro y logout): delega en
    // el dispatch interno del controlador, que decide la acción según la ruta
    // exacta y el método. Páginas públicas (sin sesión).
    case $path === '/' || $path === ''
        || $path === '/login' || $path === '/registro' || $path === '/logout':
        AuthController::dispatch($path, $method);
        break;

    // Panel de gestión.
    case $path === '/dashboard':
        DashboardController::inicio();
        break;

    // Mapa interactivo del panel: página y datos JSON que consume el mapa.
    case $path === '/mapa':
        DashboardController::mapa();
        break;

    case $path === '/api/mapa':
        DashboardController::mapaDatos();
        break;

    case $path === '/api/ruta/real':
        DashboardController::rutaReal();
        break;

    // Buscador de lugares del mapa: convierte el texto que escribe el usuario
    // en coordenadas (Nominatim) y, al revés, le pone nombre a un punto. Es lo
    // que alimenta el autocompletado de destinos del nuevo traslado.
    case $path === '/api/geocodificar':
        DashboardController::geocodificar();
        break;

    // Búsqueda de pacientes para el modal de alta (GET /api/pacientes/buscar).
    case $path === '/api/pacientes/buscar':
        DashboardController::buscarPacientes();
        break;

    // Reporte de posición del conductor (POST a /api/ubicacion). Lo dispara
    // el mapa cuando el usuario tiene rol 'conductor': toma la posición con
    // navigator.geolocation y la manda cada pocos segundos. El CSRF global de
    // arriba ya lo cubre.
    case $path === '/api/ubicacion':
        DashboardController::guardarUbicacionConductor();
        break;

    // Formulario para registrar un traslado: GET muestra el formulario y el
    // POST guarda el traslado en la base de datos (respuesta JSON).
    case $path === '/traslados/nuevo':
        if ($method === 'POST') {
            DashboardController::guardarTraslado();
        } else {
            DashboardController::nuevoTraslado();
        }
        break;

    // Avance de estado de un traslado (POST a /traslados/estado).
    case $path === '/traslados/estado':
        DashboardController::cambiarEstadoTraslado();
        break;

    // Módulo de encuestas (panel del dashboard): le pasamos la ruta y el
    // método al dispatch del controlador (listado, crear, editar, toggle y
    // resultados, que este delega a EncuestaResultadosController). Requiere
    // sesión (guards internos).
    case str_starts_with($path, '/encuestas'):
        EncuestaController::dispatch($path, $method);
        break;

    // Página pública para responder encuestas (sin login).
    case str_starts_with($path, '/publico/encuesta'):
        EncuestaPublicaController::dispatch($path, $method);
        break;

    // Módulo de vehículos: delega en el dispatch interno del controlador
    // (listado, alta, baja y edición). Requiere sesión iniciada.
    case str_starts_with($path, '/vehiculos'):
        VehiculoController::dispatch($path, $method);
        break;

    // Módulo de insumos médicos: delega en el dispatch interno del
    // controlador (listado, alta, edición y activar/desactivar). Solo
    // accesible para admin/superadmin (guards internos).
    case str_starts_with($path, '/insumos'):
        InsumoController::dispatch($path, $method);
        break;

    // Módulo de documentos: delega en el dispatch interno del controlador,
    // que decide la acción según la ruta exacta y el método.
    case str_starts_with($path, '/documentos') || str_starts_with($path, '/publico/doc') || str_starts_with($path, '/publico/archivo'):
        DocumentoController::dispatch($path, $method);
        break;

    // Módulo de usuarios (personas): búsqueda por cédula/nombre, ficha,
    // edición y desactivación. Requiere sesión (guards internos).
    case str_starts_with($path, '/usuarios'):
        UsuarioController::dispatch($path, $method);
        break;

    // Ninguna ruta coincidió → página 404.
    default:
        pagina_404();
        break;
}
