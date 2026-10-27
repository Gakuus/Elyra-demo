<?php

declare(strict_types=1);

/**
 * I18n: internacionalización de la interfaz (español / inglés).
 *
 * El español es el idioma de origen: los textos de las vistas y de los
 * controladores se escriben directamente en español y, cuando el idioma
 * activo es inglés, se traducen con el diccionario src/lang/en.php.
 *
 * El idioma se guarda en sesión y en una cookie para que se mantenga entre
 * visitas. Si no hay preferencia guardada se detecta el idioma del navegador.
 */
final class I18n
{
    /** Idiomas soportados. */
    private const DISPONIBLES = ['es', 'en'];

    /** Idioma por defecto si no hay preferencia ni detección posible. */
    private const POR_DEFECTO = 'es';

    /** Diccionario cargado una sola vez por petición. */
    private static ?array $diccionario = null;

    /** Idioma activo de la petición. */
    private static ?string $idioma = null;

    /**
     * Inicia el idioma de la petición. Se llama una sola vez desde index.php,
     * después de abrir la sesión y antes de cualquier salida.
     */
    public static function iniciar(): void
    {
        $guardado = $_SESSION['idioma'] ?? $_COOKIE['elyra_idioma'] ?? null;

        if (!is_string($guardado) || !self::valido($guardado)) {
            $guardado = self::detectarNavegador();
        }

        self::establecer($guardado, false);
    }

    /** Código del idioma activo: 'es' o 'en'. */
    public static function idioma(): string
    {
        return self::$idioma ?? self::POR_DEFECTO;
    }

    /** True si el idioma activo es inglés. */
    public static function esIngles(): bool
    {
        return self::idioma() === 'en';
    }

    /** True si el código recibido es un idioma soportado. */
    public static function valido(?string $idioma): bool
    {
        return is_string($idioma) && in_array($idioma, self::DISPONIBLES, true);
    }

    /** Lista de idiomas soportados. */
    public static function disponibles(): array
    {
        return self::DISPONIBLES;
    }

    /**
     * Fija el idioma activo. Por defecto también persiste la cookie; en el
     * arranque de la petición se llama con $persistir = false para no reenviar
     * la cookie en cada respuesta.
     */
    public static function establecer(string $idioma, bool $persistir = true): void
    {
        if (!self::valido($idioma)) {
            $idioma = self::POR_DEFECTO;
        }

        self::$idioma = $idioma;
        $_SESSION['idioma'] = $idioma;

        if ($persistir && !headers_sent()) {
            $ruta = base_path();
            setcookie('elyra_idioma', $idioma, [
                'expires'  => time() + 31536000, // 1 año
                'path'     => $ruta !== '' ? $ruta : '/',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /** Cambia el idioma y devuelve true si el código era válido. */
    public static function cambiar(string $idioma): bool
    {
        if (!self::valido($idioma)) {
            return false;
        }

        self::establecer($idioma, true);
        return true;
    }

    /**
     * Traduce un texto. El español se devuelve tal cual; el inglés se busca en
     * el diccionario y, si falta la entrada, cae al español para no romper la
     * interfaz. Los parámetros se reemplazan con :nombre o {nombre}.
     */
    public static function t(string $texto, array $parametros = []): string
    {
        $traducido = $texto;

        if (self::esIngles()) {
            $diccionario = self::diccionario();
            if (isset($diccionario[$texto])) {
                $traducido = $diccionario[$texto];
            }
        }

        foreach ($parametros as $clave => $valor) {
            $traducido = str_replace(
                [':' . $clave, '{' . $clave . '}'],
                (string) $valor,
                $traducido
            );
        }

        return $traducido;
    }

    /** Diccionario español → inglés. */
    public static function diccionario(): array
    {
        if (self::$diccionario === null) {
            $archivo = __DIR__ . '/lang/en.php';
            self::$diccionario = is_file($archivo) ? (array) require $archivo : [];
        }

        return self::$diccionario;
    }

    /**
     * Datos que se inyectan en todas las vistas: idioma activo, selector y
     * script con el diccionario para el contenido dinámico del navegador.
     */
    public static function datos(): array
    {
        return [
            'idioma'          => self::idioma(),
            'selector_idioma' => self::selector('flotante'),
            'idioma_script'   => self::script(),
        ];
    }

    /**
     * Selector ES | EN. La variante "dashboard" se integra al encabezado y la
     * "flotante" se usa en login, registro y páginas públicas.
     */
    public static function selector(string $variante = 'flotante'): string
    {
        $actual = self::idioma();
        $volver = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $clase = $variante === 'dashboard'
            ? 'selector-idioma selector-idioma-dashboard'
            : 'selector-idioma selector-idioma-flotante';

        $enlaces = '';
        foreach (['es' => 'ES', 'en' => 'EN'] as $codigo => $etiqueta) {
            $activo = $codigo === $actual ? ' active' : '';
            $href = base_path() . '/idioma?lang=' . $codigo . '&volver=' . urlencode($volver);
            $enlaces .= '<a class="selector-idioma-opcion' . $activo . '"'
                . ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                . ' hreflang="' . $codigo . '"'
                . ' title="' . ($codigo === 'es' ? 'Español' : 'English') . '">'
                . $etiqueta . '</a>';
        }

        return '<div class="' . $clase . '" role="group" aria-label="Idioma / Language">'
            . $enlaces . '</div>';
    }

    /**
     * Script de configuración + traductor para el contenido que se genera en
     * el navegador (mensajes de confirmación, listados AJAX, etc.).
     */
    public static function script(): string
    {
        $mapa = self::esIngles() ? self::diccionario() : [];
        $json = json_encode(
            $mapa,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return '<script>window.ElyraIdiomaActual=' . json_encode(self::idioma()) . ';'
            . 'window.ElyraTraducciones=' . ($json !== false ? $json : '{}') . ';</script>'
            . '<script src="public/js/comun/idioma.js?v=1" defer></script>';
    }

    /**
     * Traduce el HTML ya renderizado. Se usa para no tener que envolver a mano
     * cada texto de las vistas existentes: reemplaza nodos de texto y algunos
     * atributos visibles cuando coinciden exactamente con una entrada del
     * diccionario. No toca <script> ni <style>.
     */
    public static function traducirHtml(string $html): string
    {
        if (!self::esIngles()) {
            return $html;
        }

        $diccionario = self::diccionario();
        if ($diccionario === []) {
            return $html;
        }

        // Separa los bloques que no se deben traducir (JS y CSS embebidos).
        $partes = preg_split(
            '/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>)/is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($partes === false) {
            return $html;
        }

        foreach ($partes as $indice => $parte) {
            if (preg_match('/^<(script|style)\b/i', $parte)) {
                continue;
            }

            // Nodos de texto: >texto<
            $parte = preg_replace_callback(
                '/>([^<>]+)</s',
                static function (array $m) use ($diccionario): string {
                    $texto = $m[1];
                    $recortado = trim($texto);
                    if ($recortado === '') {
                        return $m[0];
                    }

                    $clave = preg_replace('/\s+/u', ' ', $recortado);
                    if ($clave === null || !isset($diccionario[$clave])) {
                        return $m[0];
                    }

                    $inicio = substr($texto, 0, strspn($texto, " \t\r\n"));
                    $fin = substr($texto, strlen(rtrim($texto)));

                    return '>' . $inicio . $diccionario[$clave] . $fin . '<';
                },
                $parte
            );

            // Atributos que muestran texto al usuario. No se toca "value":
            // muchos formularios mandan identificadores internos ("paciente",
            // "insumo", etc.) que también existen como palabras del diccionario
            // y traducirlos rompería el envío al servidor.
            $parte = preg_replace_callback(
                '/\b(placeholder|title|aria-label|alt)\s*=\s*(["\'])(.*?)\2/is',
                static function (array $m) use ($diccionario): string {
                    $clave = preg_replace('/\s+/u', ' ', trim($m[3]));
                    if ($clave === null || !isset($diccionario[$clave])) {
                        return $m[0];
                    }

                    return $m[1] . '=' . $m[2] . $diccionario[$clave] . $m[2];
                },
                $parte
            );

            $partes[$indice] = $parte;
        }

        return implode('', $partes);
    }

    /** Detecta el idioma del navegador (Accept-Language) en la primera visita. */
    private static function detectarNavegador(): string
    {
        $cabecera = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($cabecera === '') {
            return self::POR_DEFECTO;
        }

        // Ej: "en-US,en;q=0.9,es;q=0.8" → toma el primer idioma con peso.
        $primero = strtolower(trim(explode(',', $cabecera)[0]));
        if (str_starts_with($primero, 'en')) {
            return 'en';
        }

        return self::POR_DEFECTO;
    }
}
