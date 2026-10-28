<?php

declare(strict_types=1);

/**
 * Recaptcha: verificación de Google reCAPTCHA v2 para el login.
 *
 * La clave de sitio es pública (viaja al HTML para dibujar el widget); la
 * secreta vive solo en .env / config/env.php y nunca se imprime ni se registra.
 *
 * Si faltan las dos claves, el captcha queda desactivado para no romper el
 * login en desarrollo o en una demo sin configuración. Con las claves puestas,
 * la verificación falla cerrada: si Google no responde, no se permite entrar.
 */
final class Recaptcha
{
    /** Endpoint oficial de verificación (server-side, con la clave secreta). */
    private const URL_VERIFICACION = 'https://www.google.com/recaptcha/api/siteverify';

    /** Segundos máximos de espera contra Google. */
    private const TIEMPO_MAXIMO = 8;

    /** true si el login debe exigir captcha. */
    public static function configurado(): bool
    {
        return self::claveSitio() !== '' && self::claveSecreta() !== '';
    }

    /** Clave pública del widget (segura de incluir en el HTML). */
    public static function claveSitio(): string
    {
        return trim((string) ($_ENV['RECAPTCHA_SITE_KEY'] ?? ''));
    }

    /** Clave secreta: solo se usa del lado del servidor. */
    private static function claveSecreta(): string
    {
        return trim((string) ($_ENV['RECAPTCHA_SECRET_KEY'] ?? ''));
    }

    /**
     * Valida el token que envía el widget.
     *
     * Devuelve ['success' => bool, 'error' => string]. Si el captcha no está
     * configurado, devuelve success para que el login siga funcionando.
     *
     * @return array{success: bool, error: string}
     */
    public static function verificar(string $respuesta): array
    {
        if (!self::configurado()) {
            return ['success' => true, 'error' => ''];
        }

        $respuesta = trim($respuesta);
        if ($respuesta === '') {
            return [
                'success' => false,
                'error'   => 'Completá la verificación de seguridad.',
            ];
        }

        $cuerpo = self::pedirAVerificacion(http_build_query([
            'secret'   => self::claveSecreta(),
            'response' => $respuesta,
            'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]));

        if ($cuerpo === null) {
            return [
                'success' => false,
                'error'   => 'No pudimos verificar la seguridad. Intentá de nuevo.',
            ];
        }

        $datos = json_decode($cuerpo, true);
        if (!is_array($datos) || empty($datos['success'])) {
            return [
                'success' => false,
                'error'   => 'La verificación de seguridad no es válida. Intentá de nuevo.',
            ];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * POST al endpoint de Google. Devuelve el cuerpo si respondió 200 o null
     * si hubo error de red, timeout o un código distinto de 200.
     */
    private static function pedirAVerificacion(string $datos): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init(self::URL_VERIFICACION);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $datos,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIEMPO_MAXIMO,
                CURLOPT_CONNECTTIMEOUT => self::TIEMPO_MAXIMO,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $respuesta = curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return is_string($respuesta) && $codigo === 200 ? $respuesta : null;
        }

        // Sin extensión cURL: se usa el wrapper HTTP de PHP.
        $contexto = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $datos,
                'timeout' => self::TIEMPO_MAXIMO,
            ],
        ]);

        $respuesta = @file_get_contents(self::URL_VERIFICACION, false, $contexto);
        return is_string($respuesta) ? $respuesta : null;
    }
}
