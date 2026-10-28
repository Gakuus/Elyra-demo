<?php

declare(strict_types=1);

/**
 * ChatController: endpoint JSON del chatbot de Elyra.
 *
 * POST /api/chat  { "mensaje": "...", "historial": [{rol, texto}, ...] }
 *
 * El token CSRF global ya se valida en index.php para todo POST. Acá además
 * se limita la cantidad de consultas por IP y se responde siempre JSON, para
 * que el widget pueda mostrar un error claro si algo falla.
 */
final class ChatController
{
    /** Consultas permitidas por IP en la ventana (5 minutos). */
    private const MAX_CONSULTAS = 40;
    private const VENTANA_SEG = 300;

    public static function api(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::responder(405, ['ok' => false, 'error' => I18n::t('Método no permitido.')]);
            return;
        }

        if (!rate_limit_permitido('chat:' . ip_usuario(), self::MAX_CONSULTAS, self::VENTANA_SEG)) {
            self::responder(429, [
                'ok' => false,
                'error' => I18n::t('Demasiadas preguntas seguidas. Esperá unos minutos y volvé a intentar.'),
            ]);
            return;
        }

        $cuerpo = file_get_contents('php://input');
        $datos = json_decode((string) $cuerpo, true);

        if (!is_array($datos)) {
            self::responder(400, ['ok' => false, 'error' => I18n::t('Pedido inválido.')]);
            return;
        }

        $mensaje = $datos['mensaje'] ?? '';
        if (!is_string($mensaje)) {
            self::responder(400, ['ok' => false, 'error' => I18n::t('Pedido inválido.')]);
            return;
        }

        $historial = is_array($datos['historial'] ?? null) ? $datos['historial'] : [];

        $resultado = Chatbot::responder($mensaje, $historial);

        if ($resultado['ok']) {
            self::responder(200, ['ok' => true, 'respuesta' => $resultado['respuesta']]);
            return;
        }

        self::responder((int) ($resultado['codigo'] ?? 500), [
            'ok' => false,
            'error' => (string) ($resultado['error'] ?? I18n::t('No pude responder en este momento.')),
        ]);
    }

    /** Envía el JSON y corta. */
    private static function responder(int $codigo, array $datos): void
    {
        http_response_code($codigo);
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
