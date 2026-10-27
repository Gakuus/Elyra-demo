<?php

declare(strict_types=1);

/**
 * I18nController: cambio de idioma de la interfaz.
 *
 *   GET  /idioma?lang=en&volver=/mapa  → cambia y redirige a la página previa.
 *   GET  /api/idioma                   → consulta el idioma activo (JSON).
 *   POST /api/idioma  (lang=en)        → cambia el idioma y responde JSON.
 *
 * El POST pasa por el CSRF global de index.php. El GET /idioma es un cambio de
 * preferencia sin efectos sensibles, por eso puede usarse como enlace normal.
 */
final class I18nController
{
    /** Cambia el idioma desde el selector y vuelve a la página de origen. */
    public static function cambiar(): void
    {
        $idioma = (string) ($_GET['lang'] ?? '');

        if (I18n::valido($idioma)) {
            I18n::cambiar($idioma);
        }

        header('Location: ' . self::destinoSeguro((string) ($_GET['volver'] ?? '')), true, 302);
        exit;
    }

    /** API JSON para consultar o cambiar el idioma. */
    public static function api(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            echo json_encode([
                'ok'          => true,
                'idioma'      => I18n::idioma(),
                'disponibles' => I18n::disponibles(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $idioma = (string) ($_POST['lang'] ?? '');

        if (!I18n::valido($idioma)) {
            http_response_code(422);
            echo json_encode([
                'ok'    => false,
                'error' => I18n::t('Idioma no soportado.'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        I18n::cambiar($idioma);

        echo json_encode([
            'ok'     => true,
            'idioma' => I18n::idioma(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Devuelve solo una ruta local (path + query) para evitar redirecciones
     * abiertas. Si no hay destino válido, vuelve al panel (que a su vez manda
     * al login si no hay sesión).
     */
    private static function destinoSeguro(string $destino): string
    {
        $porDefecto = base_path() . '/dashboard';

        if ($destino === '') {
            $destino = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($destino === '') {
            return $porDefecto;
        }

        $partes = parse_url($destino);
        if ($partes === false) {
            return $porDefecto;
        }

        // Solo rutas locales: nada de esquema, host ni rutas protocol-relative.
        if (isset($partes['scheme']) || isset($partes['host'])) {
            return $porDefecto;
        }

        // Los navegadores tratan "\" como "/": se normaliza antes de validar
        // para que "/\evil.com" no se convierta en una redirección externa.
        $ruta = str_replace('\\', '/', (string) ($partes['path'] ?? ''));
        if ($ruta === '' || !str_starts_with($ruta, '/') || str_starts_with($ruta, '//')) {
            return $porDefecto;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $ruta)) {
            return $porDefecto;
        }

        $consulta = isset($partes['query']) ? '?' . $partes['query'] : '';
        return $ruta . $consulta;
    }
}
