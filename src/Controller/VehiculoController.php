<?php

declare(strict_types=1);

/**
 * VehiculoController: controlador del módulo de vehículos.
 * Gestiona el ABM (alta, baja y modificación) de los vehículos de la
 * institución. La tabla vehiculo guarda patente, modelo y año; es la
 * misma estructura que la entidad Vehiculo del proyecto original.
 *
 * Todas las páginas requieren sesión iniciada (requerir_login).
 */
final class VehiculoController
{
    /**
     * Enrutador interno del módulo de vehículos.
     * index.php delega acá cualquier ruta que empiece con /vehiculos y este
     * método decide qué acción ejecutar según la ruta exacta y el método HTTP.
     *
     * @param string $path   Ruta (ej: '/vehiculos/agregar').
     * @param string $method Método HTTP en mayúsculas ('GET' o 'POST').
     */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            // Listado de vehículos.
            $path === '/vehiculos' && $method === 'GET' => self::listar(),
            // Alta: POST procesa el formulario, GET muestra el formulario vacío.
            $path === '/vehiculos/agregar' && $method === 'POST' => self::agregar(),
            $path === '/vehiculos/agregar' && $method === 'GET' => self::formularioAgregar(),
            // Edición: POST guarda, GET muestra el formulario con los datos.
            $path === '/vehiculos/editar' && $method === 'POST' => self::editar(),
            $path === '/vehiculos/editar' && $method === 'GET' => self::formularioEditar(),
            // Baja (eliminación real de la fila, no hay borrado lógico acá).
            $path === '/vehiculos/eliminar' && $method === 'POST' => self::eliminar(),
            // Ninguna condición coincidió → página 404.
            default => pagina_404(),
        };
    }

    /**
     * Listado de vehículos (GET a /vehiculos).
     * Muestra la tabla con todos los vehículos ordenados por patente,
     * con un campo de búsqueda por texto (patente o modelo).
     */
    public static function listar(): void
    {
        // Guard: solo usuarios autenticados.
        requerir_login();

        $pdo = db_connect();

        // Texto de búsqueda opcional (?q=texto).
        $q = trim((string) ($_GET['q'] ?? ''));

        // Consulta base, ordenada por patente (como en el repo original).
        $sql = 'SELECT id, patente, modelo, anio, created_at FROM vehiculo';
        $params = [];
        if ($q !== '') {
            // LIKE con %...% busca en patente o modelo (mayúsculas/minúsculas indistinto).
            $sql .= ' WHERE patente LIKE :q OR modelo LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY patente';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vehiculos = $stmt->fetchAll();

        // Convierte cada vehículo en una fila <tr> de la tabla.
        $filas = '';
        foreach ($vehiculos as $veh) {
            $filas .= self::filaVehiculo($veh);
        }

        $contenido = $filas === ''
            ? '<div class="text-center text-muted p-3" style="font-size:15px;">'
                . '<i class="bi bi-truck d-block mb-2" style="font-size:28px;"></i>No hay veh&iacute;culos cargados.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>Patente</th><th>Modelo</th><th>A&ntilde;o</th><th>Registrado</th>'
                . '<th style="width:120px;">Acciones</th></tr></thead><tbody>' . $filas . '</tbody></table></div>';

        render_dashboard('vehiculos', 'Vehículos', 'vehiculos', [
            'q' => htmlspecialchars($q),
            'contenido_vehiculos' => $contenido,
        ]);
    }

    /**
     * Procesa el alta de un vehículo (POST a /vehiculos/agregar).
     * Valida los datos y registra la fila. Si hay errores, vuelve al
     * formulario conservando lo cargado y mostrando el mensaje.
     */
    public static function agregar(): void
    {
        requerir_login();

        $pdo = db_connect();

        // Lee los campos del formulario y normaliza la patente (mayúsculas).
        $patente = strtoupper(trim((string) ($_POST['patente'] ?? '')));
        $modelo = trim((string) ($_POST['modelo'] ?? ''));
        $anio = trim((string) ($_POST['anio'] ?? ''));

        $error = self::validar($pdo, $patente, $modelo, $anio, null);

        if ($error === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO vehiculo (patente, modelo, anio) VALUES (:patente, :modelo, :anio)'
            );
            $stmt->execute([
                'patente' => $patente,
                'modelo' => $modelo !== '' ? $modelo : null,
                'anio' => $anio !== '' ? $anio : null,
            ]);
            header('Location: ' . base_path() . '/vehiculos?agregado=1');
            exit;
        }

        render_dashboard('vehiculos_agregar', 'Agregar vehículo', 'vehiculos', [
            'mensaje_error' => self::errorHtml($error),
            'valor_patente' => htmlspecialchars($patente),
            'valor_modelo' => htmlspecialchars($modelo),
            'valor_anio' => htmlspecialchars($anio),
        ]);
    }

    /**
     * Muestra el formulario vacío de alta (GET a /vehiculos/agregar).
     */
    public static function formularioAgregar(): void
    {
        requerir_login();

        render_dashboard('vehiculos_agregar', 'Agregar vehículo', 'vehiculos', [
            'mensaje_error' => '',
            'valor_patente' => '',
            'valor_modelo' => '',
            'valor_anio' => '',
        ]);
    }

    /**
     * Formulario de edición (GET a /vehiculos/editar?id=N).
     * Muestra los datos actuales del vehículo para modificarlos.
     */
    public static function formularioEditar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT id, patente, modelo, anio FROM vehiculo WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $veh = $stmt->fetch();

        // Si no existe, vuelve al listado.
        if (!$veh) {
            header('Location: ' . base_path() . '/vehiculos');
            exit;
        }

        render_dashboard('vehiculos_editar', 'Editar vehículo', 'vehiculos', [
            'mensaje_error' => '',
            'id' => (string) $id,
            'valor_patente' => htmlspecialchars((string) $veh['patente']),
            'valor_modelo' => htmlspecialchars((string) ($veh['modelo'] ?? '')),
            'valor_anio' => htmlspecialchars((string) ($veh['anio'] ?? '')),
        ]);
    }

    /**
     * Guarda los cambios del formulario de edición (POST a /vehiculos/editar).
     * Usa las mismas reglas de validación que en el alta; al editar se
     * excluye de la verificación de patente duplicada al propio vehículo.
     */
    public static function editar(): void
    {
        requerir_login();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/vehiculos');
            exit;
        }

        $patente = strtoupper(trim((string) ($_POST['patente'] ?? '')));
        $modelo = trim((string) ($_POST['modelo'] ?? ''));
        $anio = trim((string) ($_POST['anio'] ?? ''));

        $error = self::validar($pdo, $patente, $modelo, $anio, $id);

        if ($error !== null) {
            render_dashboard('vehiculos_editar', 'Editar vehículo', 'vehiculos', [
                'mensaje_error' => self::errorHtml($error),
                'id' => (string) $id,
                'valor_patente' => htmlspecialchars($patente),
                'valor_modelo' => htmlspecialchars($modelo),
                'valor_anio' => htmlspecialchars($anio),
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE vehiculo SET patente = :patente, modelo = :modelo, anio = :anio WHERE id = :id'
        );
        $stmt->execute([
            'patente' => $patente,
            'modelo' => $modelo !== '' ? $modelo : null,
            'anio' => $anio !== '' ? $anio : null,
            'id' => $id,
        ]);

        header('Location: ' . base_path() . '/vehiculos?editado=1');
        exit;
    }

    /**
     * Elimina un vehículo (POST a /vehiculos/eliminar).
     * Es una baja fisica: borra la fila de la tabla. Se ejecuta por POST
     * para evitar borrados accidentales y responde en JSON.
     */
    public static function eliminar(): void
    {
        requerir_login();

        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            $pdo = db_connect();
            $stmt = $pdo->prepare('DELETE FROM vehiculo WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Valida los campos de un vehículo (común a alta y edición).
     * Se detiene en el primer error y devuelve el mensaje; si todo está
     * bien devuelve null.
     *
     * @param PDO      $pdo        Conexión activa.
     * @param string   $patente    Patente (ya normalizada a mayúsculas).
     * @param string   $modelo     Modelo del vehículo.
     * @param string   $anio       Año (texto del formulario).
     * @param int|null $excluirId  Id a excluir de la verificación de patente
     *                             duplicada (al editar), null en el alta.
     */
    private static function validar(PDO $pdo, string $patente, string $modelo, string $anio, ?int $excluirId): ?string
    {
        // Formato de patente: Mercosur (ABC123 o AB123) o patente vieja (AB123CD).
        if (!preg_match('/^([A-Z]{2}[0-9]{3}[A-Z]{2}|[A-Z]{2,3}[0-9]{3})$/i', $patente)) {
            return 'La patente no tiene un formato v&aacute;lido.';
        }
        if (strlen($modelo) > 100) {
            return 'El modelo no puede superar los 100 caracteres.';
        }
        if ($anio !== '' && (!ctype_digit($anio) || (int) $anio < 1900 || (int) $anio > 2100)) {
            return 'El a&ntilde;o debe ser un n&uacute;mero entre 1900 y 2100.';
        }

        // Patente duplicada (la columna patente es UNIQUE en la base).
        // En la edición se ignora la propia fila ($excluirId).
        $sql = 'SELECT COUNT(*) FROM vehiculo WHERE patente = :patente';
        $params = ['patente' => $patente];
        if ($excluirId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excluirId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Ya existe un veh&iacute;culo con esa patente.';
        }

        return null;
    }

    /**
     * Convierte un mensaje de error en el bloque HTML con la clase
     * .mensaje-error (misma presentación que el resto de los módulos).
     */
    private static function errorHtml(string $error): string
    {
        return '<div class="mensaje-error">' . $error . '</div>';
    }

    /**
     * Construye la fila <tr> de un vehículo para la tabla del listado.
     * Incluye patente, modelo, año, fecha de registro y botones de
     * editar / eliminar.
     *
     * @param array $veh Fila de vehículo devuelta por la consulta.
     */
    private static function filaVehiculo(array $veh): string
    {
        $id = (int) $veh['id'];
        $patente = htmlspecialchars((string) $veh['patente']);
        $modelo = htmlspecialchars((string) ($veh['modelo'] ?? ''));
        $anio = htmlspecialchars((string) ($veh['anio'] ?? ''));
        $fecha = date('d/m/Y', (int) strtotime((string) $veh['created_at']));

        // El botón eliminar pide confirmación en el navegador antes de
        // llamar a ElyraVehiculos.eliminar(id, this) definido en vehiculos.js.
        return '<tr data-vehiculo-id="' . $id . '">'
            . '<td class="fw-semibold">' . $patente . '</td>'
            . '<td>' . ($modelo !== '' ? $modelo : '<span class="text-muted">—</span>') . '</td>'
            . '<td>' . ($anio !== '' ? $anio : '<span class="text-muted">—</span>') . '</td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '<td><div class="d-flex gap-1">'
            . '<a href="vehiculos/editar?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>'
            . '<button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"'
            . ' onclick="ElyraVehiculos.eliminar(' . $id . ', this)"><i class="bi bi-trash"></i></button>'
            . '</div></td>'
            . '</tr>';
    }
}
