<?php

declare(strict_types=1);

/**
 * VehiculoController: ABM de vehículos. La desactivación es una baja lógica
 * (no se borran filas). Requiere rol admin/superadmin/conductor.
 */
final class VehiculoController
{
    /** Enrutador interno: index.php delega acá las rutas que empiezan con /vehiculos. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/vehiculos' && $method === 'GET' => self::listar(),
            $path === '/vehiculos/agregar' && $method === 'POST' => self::agregar(),
            $path === '/vehiculos/agregar' && $method === 'GET' => self::formularioAgregar(),
            $path === '/vehiculos/editar' && $method === 'POST' => self::editar(),
            $path === '/vehiculos/editar' && $method === 'GET' => self::formularioEditar(),
            $path === '/vehiculos/toggle' && $method === 'POST' => self::toggle(),
            default => pagina_404(),
        };
    }

    public static function listar(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

        $pdo = db_connect();

        // Búsqueda por texto (?q=) y filtro de estado (?estado=...).
        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = (string) ($_GET['estado'] ?? 'activos');
        if (!in_array($estado, ['todos', 'activos', 'inactivos'], true)) {
            $estado = 'activos';
        }

        // La patente se guarda sin espacios (AAA1234); la búsqueda compara ambas variantes.
        $sql = 'SELECT id, patente, modelo, anio, activo, created_at FROM vehiculo';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE REPLACE(patente, " ", "") LIKE :qPatente OR modelo LIKE :qModelo';
            $params['qPatente'] = '%' . str_replace(' ', '', $q) . '%';
            $params['qModelo'] = '%' . $q . '%';
        }

        if ($estado === 'activos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 1';
        } elseif ($estado === 'inactivos') {
            $sql .= ($params ? ' AND' : ' WHERE') . ' activo = 0';
        }

        $sql .= ' ORDER BY patente';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vehiculos = $stmt->fetchAll();

        $filas = '';
        foreach ($vehiculos as $veh) {
            $filas .= self::filaVehiculo($veh);
        }

        $contenido = $filas === ''
            ? '<div class="text-center text-muted p-3" style="font-size:15px;">'
                . '<img src="public/img/silk/lorry.png" alt="" class="d-block mx-auto mb-2" style="width:48px;height:48px;">No hay vehículos para mostrar.</div>'
            : '<div class="table-responsive"><table class="tabla-panel"><thead><tr>'
                . '<th>Patente</th><th>Modelo</th><th>Año</th><th>Registrado</th>'
                . '<th style="width:100px;">Estado</th>'
                . '<th style="width:120px;">Acciones</th></tr></thead><tbody>' . $filas . '</tbody></table></div>';

        render_dashboard('vehiculos', 'Vehículos', 'vehiculos', [
            'q' => htmlspecialchars($q),
            'q_url' => urlencode($q),
            'estado_sel' => htmlspecialchars($estado),
            'estado_activos' => $estado === 'activos' ? ' active' : '',
            'estado_inactivos' => $estado === 'inactivos' ? ' active' : '',
            'estado_todos' => $estado === 'todos' ? ' active' : '',
            'contenido_vehiculos' => $contenido,
        ]);
    }

    /** Alta: valida y registra. Si hay errores, vuelve al formulario con el mensaje. */
    public static function agregar(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

        $pdo = db_connect();

        // Patente normalizada (mayúsculas y sin espacio: ABC 1234 -> ABC1234).
        $patente = strtoupper(str_replace(' ', '', trim((string) ($_POST['patente'] ?? ''))));
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

    public static function formularioAgregar(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

        render_dashboard('vehiculos_agregar', 'Agregar vehículo', 'vehiculos', [
            'mensaje_error' => '',
            'valor_patente' => '',
            'valor_modelo' => '',
            'valor_anio' => '',
        ]);
    }

    public static function formularioEditar(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

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

    /** Guarda los cambios de la edición; al verificar duplicado se excluye la propia fila. */
    public static function editar(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/vehiculos');
            exit;
        }

        $patente = strtoupper(str_replace(' ', '', trim((string) ($_POST['patente'] ?? ''))));
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

    /** Baja lógica: cambia el campo activo, no borra la fila. Responde JSON. */
    public static function toggle(): void
    {
        requerir_roles(['admin', 'superadmin', 'conductor']);

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
            exit;
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare(
            'UPDATE vehiculo SET activo = IF(activo = 1, 0, 1) WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        // Devuelve el estado nuevo para actualizar la fila sin recargar.
        $stmt = $pdo->prepare('SELECT activo FROM vehiculo WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $activo = (bool) $stmt->fetchColumn();

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'activo' => $activo]);
        exit;
    }

    /** Valida los campos (común a alta y edición); devuelve el primer error o null. */
    private static function validar(PDO $pdo, string $patente, string $modelo, string $anio, ?int $excluirId): ?string
    {
        // Patente uruguaya: 3 letras + 4 números (ej: ABC 1234).
        if (!preg_match('/^[A-Z]{3}[0-9]{4}$/', $patente)) {
            return 'La patente debe usar el formato uruguayo AAA 1234 (3 letras y 4 números).';
        }
        if (strlen($modelo) > 100) {
            return 'El modelo no puede superar los 100 caracteres.';
        }
        if ($anio !== '' && (!ctype_digit($anio) || (int) $anio < 1900 || (int) $anio > 2100)) {
            return 'El año debe ser un número entre 1900 y 2100.';
        }

        // Patente duplicada (columna UNIQUE); al editar se ignora la propia fila.
        $sql = 'SELECT COUNT(*) FROM vehiculo WHERE patente = :patente';
        $params = ['patente' => $patente];
        if ($excluirId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excluirId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Ya existe un vehículo con esa patente.';
        }

        return null;
    }

    private static function errorHtml(string $error): string
    {
        return '<div class="mensaje-error">' . $error . '</div>';
    }

    /** Construye la fila <tr> de un vehículo para el listado. */
    private static function filaVehiculo(array $veh): string
    {
        $id = (int) $veh['id'];
        $patente = htmlspecialchars((string) $veh['patente']);
        $modelo = htmlspecialchars((string) ($veh['modelo'] ?? ''));
        $anio = htmlspecialchars((string) ($veh['anio'] ?? ''));
        $fecha = date('d/m/Y', (int) strtotime((string) $veh['created_at']));
        $activo = (bool) ($veh['activo'] ?? true);

        $estado = $activo
            ? '<span class="estado-activo">Activo</span>'
            : '<span class="estado-inactivo">Inactivo</span>';

        return '<tr data-vehiculo-id="' . $id . '">'
            . '<td class="fw-semibold">' . $patente . '</td>'
            . '<td>' . ($modelo !== '' ? $modelo : '<span class="text-muted">—</span>') . '</td>'
            . '<td>' . ($anio !== '' ? $anio : '<span class="text-muted">—</span>') . '</td>'
            . '<td class="text-muted small">' . $fecha . '</td>'
            . '<td>' . $estado . '</td>'
            . '<td><div class="d-flex gap-1">'
            . '<a href="vehiculos/editar?id=' . $id . '" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>'
            . '<button type="button" class="btn btn-sm ' . ($activo ? 'btn-outline-warning' : 'btn-outline-success') . '"'
            . ' title="' . ($activo ? 'Desactivar vehículo' : 'Activar vehículo') . '"'
            . ' onclick="ElyraVehiculos.toggle(' . $id . ', this)"><i class="bi bi-power"></i></button>'
            . '</div></td>'
            . '</tr>';
    }
}