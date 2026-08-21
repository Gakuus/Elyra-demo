<?php

declare(strict_types=1);

/**
 * helpers.php: funciones auxiliares compartidas por todos los controladores.
 * Son globales (no están dentro de una clase) para que se puedan llamar desde
 * cualquier controlador sin importar nada más.
 */

/**
 * Devuelve el "path base" de la aplicación según APP_URL.
 * Ejemplo: si APP_URL = http://localhost:8000, devuelve ''.
 *          si APP_URL = http://localhost:8000/elyra, devuelve '/elyra'.
 *
 * Sirve para construir rutas absolutas correctas aunque la app viva en
 * una subcarpeta. El resultado se guarda en una variable estática para
 * calcularlo solo una vez (mejora de rendimiento).
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
 * Protege una página: si el usuario no tiene sesión iniciada, lo redirige
 * a /login y detiene la ejecución. Es el "guard" de las páginas privadas.
 */
function requerir_login(): void
{
    if (!Auth::estaAutenticado()) {
        header('Location: ' . base_path() . '/login');
        exit; // Detiene la ejecución, el controlador no sigue.
    }
}

/**
 * Devuelve la URL completa de la aplicación (ej: http://localhost:8000).
 * Se usa por ejemplo para construir el enlace que lleva el código QR.
 */
function app_url(): string
{
    return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
}

/**
 * Renderiza una vista HTML reemplazando los marcadores {{clave}} por valores.
 *
 * El sistema de plantillas es muy simple: las vistas son archivos HTML puro
 * con marcadores como {{titulo}}. Este helper lee el archivo y cambia cada
 * {{clave}} por el valor correspondiente del array $datos.
 *
 * @param string $archivo Ruta al archivo .html de la vista.
 * @param array  $datos   Pares clave => valor que reemplazan los {{clave}}.
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
 * Muestra una página de error 404.
 */
function pagina_404(): void
{
    http_response_code(404);
    echo '<h1 style="font-family:tahoma;padding:40px;text-align:center;">404 — Página no encontrada</h1>';
}

/**
 * Renderiza una vista del dashboard envuelta en el layout compartido
 * (encabezado, barra lateral, pie, modal QR y scripts).
 *
 * Así evitamos repetir el mismo HTML en todas las páginas del dashboard:
 * el layout se escribe UNA vez en views/dashboard/layout.html y cada vista
 * solo aporta su contenido principal.
 *
 * @param string $vista   Nombre de la vista en views/dashboard/ (sin extensión).
 * @param string $titulo  Título para el <title>.
 * @param string $seccion Sección activa del menú: 'inicio' | 'encuestas' | 'documentos'.
 * @param array  $datos   Marcadores {{clave}} para la vista.
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

    // Tabla que indica qué enlace del menú debe verse como "activo".
    // La sección pasada elige el array correcto: el enlace correspondiente
    // lleva la clase ' active' (que lo resalta) y los demás van vacíos.
    $activo = [
        'inicio'     => ['activo_inicio' => ' active', 'activo_encuestas' => '', 'activo_documentos' => ''],
        'encuestas'  => ['activo_inicio' => '', 'activo_encuestas' => ' active', 'activo_documentos' => ''],
        'documentos' => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => ' active'],
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
