<?php

declare(strict_types=1);

/**
 * MailService: envío de correos del sistema mediante PHPMailer sobre SMTP.
 *
 * PHPMailer se incluye vendorizado en vendor/PHPMailer (el proyecto no usa
 * Composer/autoload), así que se requieren sus tres archivos base a mano: con
 * eso alcanza para usar la clase sin un autoloader.
 *
 * La configuración sale del .env (MAIL_*). Para Gmail hay que usar una
 * "contraseña de aplicación" generada en la cuenta de Google, no la contraseña
 * normal: con verificación en dos pasos activa, Google no acepta la contraseña
 * de la cuenta para SMTP.
 */

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class MailService
{
    /** True si hay credenciales SMTP mínimas configuradas en el .env. */
    public static function configurado(): bool
    {
        return (string) ($_ENV['MAIL_USERNAME'] ?? '') !== ''
            && (string) ($_ENV['MAIL_PASSWORD'] ?? '') !== '';
    }

    /**
     * Envía el correo con el enlace de restablecimiento. Devuelve true si el
     * servidor SMTP lo aceptó. Nunca lanza: ante cualquier fallo registra el
     * error en la bitácora y devuelve false.
     */
    public static function enviarRecuperacion(string $destinatario, string $nombre, string $enlace): bool
    {
        $mail = self::prepararMailer($destinatario, $nombre);
        if ($mail === null) {
            return false;
        }

        try {
            $mail->isHTML(true);
            $mail->Subject = 'Restablecé tu contraseña — Elyra';
            $mail->Body = self::plantillaHtml($nombre, $enlace);
            $mail->AltBody = self::plantillaTexto($nombre, $enlace);

            $mail->send();
            return true;
        } catch (Exception $e) {
            // ErrorInfo puede traer detalles del servidor; no incluye la
            // contraseña ni el cuerpo completo del mensaje.
            log_seguridad('mail_error', [
                'email'  => $destinatario,
                'detalle' => $mail->ErrorInfo,
            ]);
            return false;
        }
    }

    /**
     * Envía las credenciales de acceso de una cuenta recién creada por un
     * administrador: nombre de usuario y contraseña temporal. Devuelve true si
     * el servidor SMTP la aceptó. Nunca lanza (igual que enviarRecuperacion).
     */
    public static function enviarCredenciales(
        string $destinatario,
        string $nombre,
        string $username,
        string $password
    ): bool {
        $mail = self::prepararMailer($destinatario, $nombre);
        if ($mail === null) {
            return false;
        }

        try {
            $mail->isHTML(true);
            $mail->Subject = 'Tu cuenta en Elyra';
            $mail->Body = self::plantillaCredencialesHtml($nombre, $username, $password);
            $mail->AltBody = self::plantillaCredencialesTexto($nombre, $username, $password);

            $mail->send();
            return true;
        } catch (Exception $e) {
            log_seguridad('mail_error', [
                'email'  => $destinatario,
                'detalle' => $mail->ErrorInfo,
            ]);
            return false;
        }
    }

    /**
     * Crea y configura el transporte SMTP común a todos los correos. Devuelve
     * null (y registra el motivo) si faltan credenciales o la configuración.
     */
    private static function prepararMailer(string $destinatario, string $nombre): ?PHPMailer
    {
        if (!self::configurado()) {
            log_seguridad('mail_no_configurado', ['email' => $destinatario]);
            return null;
        }

        $mail = new PHPMailer(true);

        $host = (string) ($_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
        $port = (int) ($_ENV['MAIL_PORT'] ?? 587);

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = (string) $_ENV['MAIL_USERNAME'];
        $mail->Password = (string) $_ENV['MAIL_PASSWORD'];

        // Gmail usa STARTTLS en el puerto 587 (o SMTPS en el 465).
        $mail->SMTPSecure = ($port === 465)
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->CharSet = 'UTF-8';

        // En Gmail el remitente debe coincidir con la cuenta autenticada.
        $from = trim((string) ($_ENV['MAIL_FROM'] ?? ''));
        if ($from === '') {
            $from = (string) $_ENV['MAIL_USERNAME'];
        }
        $fromNombre = (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Elyra');
        $mail->setFrom($from, $fromNombre);
        $mail->addAddress($destinatario, $nombre);

        return $mail;
    }

    /** Cuerpo HTML del correo. */
    private static function plantillaHtml(string $nombre, string $enlace): string
    {
        $nombreHtml = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $enlaceHtml = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="max-width:520px;margin:0 auto;padding:24px;">'
            . '<div style="background:#3B5998;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;">'
            . '<h1 style="margin:0;font-size:20px;">Elyra</h1>'
            . '<p style="margin:4px 0 0;font-size:13px;opacity:.9;">Hospital de Clínicas</p>'
            . '</div>'
            . '<div style="background:#fff;padding:24px;border:1px solid #e3e6ea;border-top:none;border-radius:0 0 12px 12px;">'
            . '<p style="margin:0 0 12px;font-size:15px;color:#222;">Hola ' . $nombreHtml . ',</p>'
            . '<p style="margin:0 0 20px;font-size:14px;color:#444;line-height:1.5;">'
            . 'Recibimos una solicitud para restablecer la contraseña de tu cuenta en Elyra. '
            . 'Hacé clic en el botón para elegir una nueva. El enlace vence en <strong>60 minutos</strong> y solo puede usarse una vez.</p>'
            . '<p style="text-align:center;margin:0 0 20px;">'
            . '<a href="' . $enlaceHtml . '" style="display:inline-block;background:#3B5998;color:#fff;text-decoration:none;'
            . 'padding:12px 28px;border-radius:8px;font-size:15px;font-weight:bold;">Restablecer contraseña</a>'
            . '</p>'
            . '<p style="margin:0 0 8px;font-size:13px;color:#666;line-height:1.5;">'
            . 'Si el botón no funciona, copiá y pegá este enlace en el navegador:</p>'
            . '<p style="margin:0 0 20px;font-size:12px;word-break:break-all;">'
            . '<a href="' . $enlaceHtml . '" style="color:#3B5998;">' . $enlaceHtml . '</a></p>'
            . '<p style="margin:0;font-size:12px;color:#888;line-height:1.5;">'
            . 'Si vos no pediste este cambio, ignorá este correo: tu contraseña actual sigue siendo válida.</p>'
            . '</div>'
            . '<p style="text-align:center;font-size:11px;color:#9aa0a6;margin-top:16px;">Elyra v1.0 — No respondas a este correo.</p>'
            . '</div>'
            . '</body></html>';
    }

    /** Versión de texto plano (clientes que no renderizan HTML). */
    private static function plantillaTexto(string $nombre, string $enlace): string
    {
        return "Hola {$nombre},\n\n"
            . "Recibimos una solicitud para restablecer la contraseña de tu cuenta en Elyra.\n"
            . "Abrí este enlace para elegir una nueva (vence en 1 hora):\n\n"
            . $enlace . "\n\n"
            . "Si no pediste esto, ignorá este correo.\n\n"
            . "Elyra — Hospital de Clínicas";
    }

    /** Correo HTML con las credenciales de una cuenta nueva. */
    private static function plantillaCredencialesHtml(string $nombre, string $username, string $password): string
    {
        $nombreHtml = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $userHtml = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $passHtml = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="max-width:520px;margin:0 auto;padding:24px;">'
            . '<div style="background:#3B5998;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;">'
            . '<h1 style="margin:0;font-size:20px;">Elyra</h1>'
            . '<p style="margin:4px 0 0;font-size:13px;opacity:.9;">Hospital de Clínicas</p>'
            . '</div>'
            . '<div style="background:#fff;padding:24px;border:1px solid #e3e6ea;border-top:none;border-radius:0 0 12px 12px;">'
            . '<p style="margin:0 0 12px;font-size:15px;color:#222;">Hola ' . $nombreHtml . ',</p>'
            . '<p style="margin:0 0 20px;font-size:14px;color:#444;line-height:1.5;">'
            . 'Se creó una cuenta para vos en el sistema Elyra del Hospital de Clínicas. '
            . 'Estos son tus datos de acceso:</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">'
            . '<tr><td style="padding:10px 12px;background:#f4f5f7;border:1px solid #e3e6ea;font-size:13px;color:#666;width:150px;">Usuario</td>'
            . '<td style="padding:10px 12px;border:1px solid #e3e6ea;font-size:15px;font-weight:bold;color:#222;font-family:monospace;">' . $userHtml . '</td></tr>'
            . '<tr><td style="padding:10px 12px;background:#f4f5f7;border:1px solid #e3e6ea;font-size:13px;color:#666;">Contraseña temporal</td>'
            . '<td style="padding:10px 12px;border:1px solid #e3e6ea;font-size:15px;font-weight:bold;color:#222;font-family:monospace;">' . $passHtml . '</td></tr>'
            . '</div>'
            . '<p style="margin:0 0 20px;font-size:13px;color:#666;line-height:1.5;">'
            . 'Por seguridad, al ingresar te recomendamos cambiar la contraseña. '
            . 'Si tenés dudas, comunicate con el área de gestión del hospital.</p>'
            . '</div>'
            . '<p style="text-align:center;font-size:11px;color:#9aa0a6;margin-top:16px;">Elyra v1.0 — No respondas a este correo.</p>'
            . '</div>'
            . '</body></html>';
    }

    /** Versión de texto plano del correo de credenciales. */
    private static function plantillaCredencialesTexto(string $nombre, string $username, string $password): string
    {
        return "Hola {$nombre},\n\n"
            . "Se creó una cuenta para vos en Elyra (Hospital de Clínicas).\n\n"
            . "Usuario: {$username}\n"
            . "Contraseña temporal: {$password}\n\n"
            . "Por seguridad, al ingresar te recomendamos cambiar la contraseña.\n\n"
            . "Elyra — Hospital de Clínicas";
    }
}