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
 * Reemplaza marcadores y bucles dentro de un template HTML.
 *
 * El sistema de plantillas sigue siendo deliberadamente simple: las vistas
 * son HTML plano con marcadores, y acá se expanden. Marcadores soportados:
 *
 *   {{clave}}             → el valor escalar se sustituye tal cual.
 *   {{#clave}}…{{/clave}} → el bloque se repite una vez por fila del array
 *                           $datos['clave'] (o no se muestra si está vacío);
 *                           dentro del bloque los campos de cada fila se
 *                           referencian como {{fila.campo}}.
 *   {{^clave}}…{{/clave}} → el bloque se muestra una sola vez cuando el
 *                           array $datos['clave'] está vacío.
 *
 * Así los controladores pasan datos (filas ya escapadas) y las vistas arman
 * el HTML, en vez de que cada controlador concatene tablas y formularios a
 * mano. Las filas pueden a su vez traer sub-listas ({{#fila.otra}}…{{/fila.otra}})
 * para bucles anidados.
 */
function reemplazar_marcadores(string $html, array $datos): string
{
    foreach ($datos as $clave => $valor) {
        $rx = preg_quote($clave, '/');

        if (!is_array($valor)) {
            $html = str_replace('{{' . $clave . '}}', (string) $valor, $html);
            continue;
        }

        // Bloque positivo {{#clave}}: se repite una vez por fila.
        $html = preg_replace_callback(
            '/\{\{\#' . $rx . '\}\}(.*?)\{\{\/' . $rx . '\}\}/s',
            function (array $m) use ($datos, $clave, $valor): string {
                $salida = '';
                foreach ($valor as $fila) {
                    // Cada fila hereda el contexto del padre y expone sus campos
                    // como {{fila.campo}} (un campo que es array vuelve a ser
                    // un bloque {{#fila.campo}}, permitiendo anidar).
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

        // Bloque vacío {{^clave}}: se muestra solo cuando no hay filas.
        $html = preg_replace_callback(
            '/\{\{\^' . $rx . '\}\}(.*?)\{\{\/' . $rx . '\}\}/s',
            fn(array $m): string => $valor === [] ? $m[1] : '',
            $html
        );
    }

    // Defensa: si un campo de fila no llegó, que no quede el marcador ni su
    // bloque en pantalla (los templates del proyecto siempre traen todos).
    $html = preg_replace('/\{\{(\^|#)fila\.[a-zA-Z0-9_.]+\}\}.*?\{\{\/fila\.[a-zA-Z0-9_.]+\}\}/s', '', $html);
    return preg_replace('/\{\{fila\.[a-zA-Z0-9_.]+\}\}/', '', $html);
}

/**
 * Pinta una vista reemplazando los marcadores {{clave}} y bucles {{#clave}}
 * por sus valores.
 *
 * $archivo: ruta al .html de la vista.
 * $datos:   pares clave => valor (los que son arrays alimentan bucles).
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
    // El path base se inyecta siempre: el layout de las vistas públicas lo
    // necesita para resolver URLs de recursos/enlaces en cualquier entorno.
    $datos = ['base_path' => base_path()] + $datos;
    // Expande los marcadores y bucles y envía el HTML final al navegador.
    echo reemplazar_marcadores($html, $datos);
}

/**
 * Renderiza un fragmento del dashboard a una CADENA (sin layout). Lo usan los
 * endpoints AJAX: el controlador pasa solo datos y acá se devuelve el HTML ya
 * expandido para incluirlo en el JSON; así los controladores no arman HTML.
 *
 * $vista: nombre del fragmento en views/dashboard/fragmentos/ (sin .html).
 * $datos: marcadores {{clave}} y bucles {{#clave}} para el fragmento.
 */
function render_contenido(string $vista, array $datos): string
{
    $html = file_get_contents(__DIR__ . '/../views/dashboard/fragmentos/' . $vista . '.html');
    return $html !== false ? reemplazar_marcadores($html, $datos) : '';
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

    // Reemplaza los marcadores y bucles del contenido. 'usuario' se agrega
    // siempre; los demás vienen en $datos (los que son arrays de filas
    // alimentan bucles {{#clave}}). El operador + une arrays sin pisar $datos.
    $contenido = reemplazar_marcadores($contenido, ['usuario' => $usuario] + $datos);

    // Resalta en el menú la sección en la que el usuario está parado: según
    // $seccion, un enlace lleva ' active' y los demás van con cadena vacía.
    $activo = [
        'inicio'     => ['activo_inicio' => ' active', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => ''],
        'encuestas'  => ['activo_inicio' => '', 'activo_encuestas' => ' active', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => ''],
        'documentos' => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => ' active', 'activo_vehiculos' => '', 'activo_usuarios' => ''],
        'vehiculos'  => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => ' active', 'activo_usuarios' => ''],
        'usuarios'   => ['activo_inicio' => '', 'activo_encuestas' => '', 'activo_documentos' => '', 'activo_vehiculos' => '', 'activo_usuarios' => ' active'],
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
