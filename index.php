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
require_once __DIR__ . '/src/Controller/UsuarioData.php';                 // Trait de datos del módulo de usuarios.
require_once __DIR__ . '/src/Controller/UsuarioController.php';
require_once __DIR__ . '/src/Controller/UsuarioEdicionController.php';
require_once __DIR__ . '/src/Controller/UsuarioCodigosController.php';

// ------------------------------------------------------------------
// 4) Sesión
// ------------------------------------------------------------------
// Inicia (o reanuda) la sesión para que $_SESSION esté disponible en todo.
Auth::iniciarSesion();

// ------------------------------------------------------------------
// 5) Rutas
// ------------------------------------------------------------------
// Datos de la petición actual: método HTTP (GET/POST...) y ruta.
$method = $_SERVER['REQUEST_METHOD'];
$path = $staticRel;

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
