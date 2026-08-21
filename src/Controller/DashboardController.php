<?php

declare(strict_types=1);

/**
 * DashboardController: controlador del panel principal.
 * Maneja las páginas internas de gestión: el inicio y el listado de encuestas.
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

    /**
     * Listado de encuestas generales (GET a /encuestas).
     * Consulta todas las encuestas de la base y arma una tabla HTML para
     * mostrarlas dentro de la vista encuestas.html.
     */
    public static function encuestas(): void
    {
        // Guard: solo usuarios autenticados pueden ver esto.
        requerir_login();

        // Conexión a MySQL.
        $pdo = db_connect();

        // Trae todas las encuestas, ordenadas de más nueva a más antigua.
        $stmt = $pdo->query("
            SELECT id, titulo, descripcion, activa, created_at
            FROM encuesta
            ORDER BY created_at DESC
        ");

        // Construye una fila <tr> por cada encuesta (HTML puro concatenado).
        $filas = '';
        foreach ($stmt->fetchAll() as $fila) {
            // Estado legible y clase CSS según si está activa o no.
            $estado = $fila['activa'] ? 'Activa' : 'Inactiva';
            $clase = $fila['activa'] ? 'estado-activo' : 'estado-inactivo';
            // Convierte la fecha SQL (Y-m-d) al formato uruguayo (dd/mm/aaaa).
            $creada = date('d/m/Y', (int) strtotime($fila['created_at']));

            // htmlspecialchars evita inyección XSS: cualquier texto de la base
            // se muestra como texto plano, nunca como código HTML.
            $filas .= '<tr>'
                . '<td class="fw-semibold">' . htmlspecialchars($fila['titulo']) . '</td>'
                . '<td>' . htmlspecialchars($fila['descripcion'] ?? '') . '</td>'
                . '<td><span class="' . $clase . '">' . $estado . '</span></td>'
                . '<td>' . htmlspecialchars($creada) . '</td>'
                . '</tr>';
        }

        // Si no hay filas, muestra un mensaje vacío; si hay, arma la tabla completa.
        $contenido = $filas === ''
            ? '<div class="text-center text-muted p-3" style="font-size:15px;">'
                . '<i class="bi bi-bar-chart d-block mb-2" style="font-size:28px;"></i>No hay encuestas.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>T&iacute;tulo</th><th>Descripci&oacute;n</th><th>Estado</th><th>Creada</th>'
                . '</tr></thead><tbody>' . $filas . '</tbody></table></div>';

        // Renderiza la vista encuestas.html dentro del layout, pasándole el
        // HTML ya armado en el marcador {{contenido_encuestas}}.
        render_dashboard('encuestas', 'Encuestas generales', 'encuestas', [
            'contenido_encuestas' => $contenido,
        ]);
    }
}
