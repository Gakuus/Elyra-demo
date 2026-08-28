<?php

declare(strict_types=1);

/**
 * DashboardController: controlador del panel principal.
 * Maneja la página interna de inicio del panel. El listado de encuestas
 * vive en EncuestaController (mismo patrón que el módulo de documentos).
 * Todas las páginas de este controlador requieren sesión iniciada.
 */
final class DashboardController
{
    /**
     * Página de inicio del panel (GET a /dashboard).
     * Solo pide que esté logueado y muestra la vista de bienvenida.
     */
    public static function inicio(): void
    {
        // Guard: si no hay sesión, redirige a /login y detiene la ejecución.
        requerir_login();

        // render_dashboard envuelve la vista en el layout común (menu, header...).
        // Parámetros: (nombre de vista, título de pestaña, sección activa, datos).
        render_dashboard('inicio', 'Panel', 'inicio', []);
    }
}
