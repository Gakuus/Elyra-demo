<?php

declare(strict_types=1);

/**
 * helpers.php: funciones que comparten todos los controladores.
 * Son globales (no viven en una clase) para poder llamarlas desde cualquier
 * controlador sin importar nada: base_path(), requerir_login(), etc.
 */

/**
 * Ruta base de la aplicación según APP_URL, sin dominio.
 * Con APP_URL = http://localhost:8000 devuelve ''; si la app cuelga de una
 * subcarpeta (/elyra) devuelve '/elyra'. Así los enlaces y redirecciones
 * quedan bien sin depender de dónde se haya montado el sistema.
 */
function base_path(): string
{
    static $basePath = null;
    if ($basePath === null) {
        // Extrae solo la parte de "ruta" de la URL (ignora protocolo y dominio).
        $appUrlPath = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_PATH);
        $basePath = rtrim(is_string($appUrlPath) ? $appUrlPath : '', '/');
    }
    return $basePath;
}

/**
 * Guard de las páginas privadas del hospital: si quien entra no tiene sesión,
 * lo manda a /login y corta la ejecución para que no siga procesando nada.
 */
function requerir_login(): void
{
    if (!Auth::estaAutenticado()) {
        header('Location: ' . base_path() . '/login');
        exit; // Detiene la ejecución, el controlador no sigue.
    }
}

/**
 * URL completa de la aplicación (ej: http://localhost:8000). La usamos por
 * ejemplo para armar el enlace que se imprime en el código QR del paciente.
 */
function app_url(): string
{
    return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
}

/**
 * Pinta una vista reemplazando los marcadores {{clave}} por sus valores.
 *
 * El sistema de plantillas es deliberadamente simple: las vistas son HTML
 * plano con marcadores tipo {{titulo}}; acá se lee el archivo y se cambia
 * cada marcador por el valor correspondiente de $datos.
 *
 * $archivo: ruta al .html de la vista.
 * $datos:   pares clave => valor que reemplazan los {{clave}}.
 */
function render_vista(string $archivo, array $datos): void
{
    // Lee el contenido completo del archivo de la vista.
    $html = file_get_contents($archivo);
    if ($html === false) {
        // Si el archivo no existe, devuelve un error 500 en vez de crashear.
        http_response_code(500);
        echo 'Vista no encontrada: ' . htmlspecialchars($archivo);
        return;
    }
    // Recorre cada dato y reemplaza su marcador correspondiente en el HTML.
    foreach ($datos as $clave => $valor) {
        $html = str_replace('{{' . $clave . '}}', (string) $valor, $html);
    }
    // Envía el HTML final al navegador.
    echo $html;
}

/**
 * Página de error 404 del sistema.
 */
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
 * Pinta una página del dashboard dentro del layout compartido (encabezado,
 * barra lateral, pie, modal del QR y scripts).
 *
 * Así el armado de todo el panel no se repite en cada controlador: el layout
 * se escribe una sola vez en views/dashboard/layout.html y cada vista solo
 * aporta su bloque central.
 *
 * $vista:   nombre de la vista en views/dashboard/ (sin extensión).
 * $titulo:  texto para el <title>.
 * $seccion: sección activa del menú: 'inicio' | 'encuestas' | 'documentos' | 'usuarios'.
 * $datos:   marcadores {{clave}} para la vista.
 */
function render_dashboard(string $vista, string $titulo, string $seccion, array $datos): void
{
    // Carpeta donde viven las vistas del dashboard.
    $base = __DIR__ . '/../views/dashboard/';

    // Nombre del usuario logueado (con htmlspecialchars para evitar XSS).
    $usuario = htmlspecialchars((string) ($_SESSION['usuario_nombre'] ?? 'Usuario'));

    // Lee el archivo HTML de la vista pedida (su contenido).
    $contenido = file_get_contents($base . $vista . '.html');
    if ($contenido === false) {
        pagina_404();
        return;
    }

    // Reemplaza los marcadores del contenido. 'usuario' se agrega siempre,
    // los demás vienen en $datos. El operador + une arrays sin pisar $datos.
    foreach (['usuario' => $usuario] + $datos as $clave => $valor) {
        $contenido = str_replace('{{' . $clave . '}}', (string) $valor, $contenido);
    }

    // Resalta en el menú la sección en la que el usuario está parado: según
    // $seccion, un enlace lleva ' active' y los demás van con cadena vacía.
    $activo = [
        'inicio'     => ['activo_inicio' => ' active', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_usuarios' => ''],
        'encuestas'  => ['activo_inicio' => '', 'activo_encuestas' => ' active', 'activo_documentos' => '', 'activo_usuarios' => ''],
        'documentos' => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => ' active', 'activo_usuarios' => ''],
        'usuarios'   => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_usuarios' => ' active'],
    ][$seccion] ?? [];

    // Renderiza el layout con: título de pestaña, usuario, contenido ya
    // procesado, base_path (para el QR) y el enlace activo del menú.
    render_vista($base . 'layout.html', array_merge([
        'titulo'    => $titulo,
        'usuario'   => $usuario,
        'contenido' => $contenido,
        'base_path' => base_path(),
    ], $activo));
}
