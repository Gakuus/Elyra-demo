<?php

declare(strict_types=1);

/**
 * InsumoController: stock de insumos. Listado, alta, edición y
 * activación/desactivación (baja lógica). Solo admin/superadmin.
 */
final class InsumoController
{
    use InsumoData;

    /** Enrutador interno: index.php delega acá las rutas que empiezan con /insumos. */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            $path === '/insumos' && $method === 'GET' => self::listar(),
            $path === '/insumos/agregar' && $method === 'POST' => self::agregar(),
            $path === '/insumos/agregar' && $method === 'GET' => self::formularioAgregar(),
            $path === '/insumos/editar' && $method === 'POST' => self::editar(),
            $path === '/insumos/editar' && $method === 'GET' => self::formularioEditar(),
            $path === '/insumos/toggle' && $method === 'POST' => self::toggle(),
            default => pagina_404(),
        };
    }

    /** Listado con búsqueda (?q=) y filtro de estado (?estado=...). */
    public static function listar(): void
    {
        requerir_gestion();

        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = (string) ($_GET['estado'] ?? 'activos');
        if (!in_array($estado, ['todos', 'activos', 'inactivos'], true)) {
            $estado = 'activos';
        }

        $insumos = self::buscarInsumos($q, $estado);

        $filas = [];
        foreach ($insumos as $ins) {
            $filas[] = self::mostrarInsumo($ins);
        }

        // Aviso tras agregar/editar (?agregado=1 / ?editado=1).
        $aviso = isset($_GET['agregado']) ? 'Insumo agregado correctamente.'
            : (isset($_GET['editado']) ? 'Cambios guardados.' : '');

        render_dashboard('insumos', 'Insumos', 'insumos', [
            'hay_aviso' => $aviso !== '' ? ['1'] : [],
            'aviso' => htmlspecialchars($aviso),
            'q' => htmlspecialchars($q),
            'q_url' => urlencode($q),
            'estado_sel' => htmlspecialchars($estado),
            'estado_activos' => $estado === 'activos' ? ' active' : '',
            'estado_inactivos' => $estado === 'inactivos' ? ' active' : '',
            'estado_todos' => $estado === 'todos' ? ' active' : '',
            'insumos' => $filas,
        ]);
    }

    /** Alta: valida y registra; con errores, vuelve al formulario con lo cargado. */
    public static function agregar(): void
    {
        requerir_gestion();

        $pdo = db_connect();

        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $stock = trim((string) ($_POST['stock'] ?? '0'));

        $error = self::validar($pdo, $nombre, $descripcion, $stock, null);

        if ($error === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO insumo (nombre, descripcion, stock) VALUES (:nombre, :descripcion, :stock)'
            );
            $stmt->execute([
                'nombre' => $nombre,
                'descripcion' => $descripcion !== '' ? $descripcion : null,
                'stock' => (int) $stock,
            ]);
            header('Location: ' . base_path() . '/insumos?agregado=1');
            exit;
        }

        render_dashboard('insumos_agregar', 'Agregar insumo', 'insumos', [
            'hay_error' => ['1'],
            'error' => $error,
            'valor_nombre' => htmlspecialchars($nombre),
            'valor_descripcion' => htmlspecialchars($descripcion),
            'valor_stock' => htmlspecialchars($stock),
        ]);
    }

    public static function formularioAgregar(): void
    {
        requerir_gestion();

        render_dashboard('insumos_agregar', 'Agregar insumo', 'insumos', [
            'hay_error' => [],
            'error' => '',
            'valor_nombre' => '',
            'valor_descripcion' => '',
            'valor_stock' => '0',
        ]);
    }

    public static function formularioEditar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT id, nombre, descripcion, stock FROM insumo WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $ins = $stmt->fetch();

        if (!$ins) {
            header('Location: ' . base_path() . '/insumos');
            exit;
        }

        render_dashboard('insumos_editar', 'Editar insumo', 'insumos', [
            'hay_error' => [],
            'error' => '',
            'id' => (string) $id,
            'valor_nombre' => htmlspecialchars((string) $ins['nombre']),
            'valor_descripcion' => htmlspecialchars((string) ($ins['descripcion'] ?? '')),
            'valor_stock' => (string) (int) $ins['stock'],
        ]);
    }

    /** Guarda los cambios de la edición; al verificar duplicado se excluye la propia fila. */
    public static function editar(): void
    {
        requerir_gestion();

        $pdo = db_connect();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            header('Location: ' . base_path() . '/insumos');
            exit;
        }

        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $stock = trim((string) ($_POST['stock'] ?? '0'));

        $error = self::validar($pdo, $nombre, $descripcion, $stock, $id);

        if ($error !== null) {
            render_dashboard('insumos_editar', 'Editar insumo', 'insumos', [
                'hay_error' => ['1'],
                'error' => $error,
                'id' => (string) $id,
                'valor_nombre' => htmlspecialchars($nombre),
                'valor_descripcion' => htmlspecialchars($descripcion),
                'valor_stock' => htmlspecialchars($stock),
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE insumo SET nombre = :nombre, descripcion = :descripcion, stock = :stock WHERE id = :id'
        );
        $stmt->execute([
            'nombre' => $nombre,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'stock' => (int) $stock,
            'id' => $id,
        ]);

        header('Location: ' . base_path() . '/insumos?editado=1');
        exit;
    }

    /** Baja lógica: cambia activo, responde JSON para actualizar sin recargar. */
    public static function toggle(): void
    {
        requerir_gestion();

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            self::respondeJson(['ok' => false, 'error' => 'ID inválido.']);
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare(
            'UPDATE insumo SET activo = IF(activo = 1, 0, 1) WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $stmt = $pdo->prepare('SELECT activo FROM insumo WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $activo = (bool) $stmt->fetchColumn();

        self::respondeJson(['ok' => true, 'activo' => $activo]);
    }

    /** Valida los campos (común a alta y edición); devuelve el primer error o null. */
    private static function validar(PDO $pdo, string $nombre, string $descripcion, string $stock, ?int $excluirId): ?string
    {
        if ($nombre === '') {
            return 'El nombre es obligatorio.';
        }
        if (mb_strlen($nombre) > 150) {
            return 'El nombre no puede superar los 150 caracteres.';
        }
        if (mb_strlen($descripcion) > 5000) {
            return 'La descripción es demasiado larga.';
        }
        if ($stock === '' || !ctype_digit($stock)) {
            return 'El stock debe ser un número entero.';
        }
        if ((int) $stock > 100000000) {
            return 'El stock no puede superar los 100.000.000.';
        }

        // Nombre duplicado (columna UNIQUE); al editar se ignora la propia fila.
        $sql = 'SELECT COUNT(*) FROM insumo WHERE nombre = :nombre';
        $params = ['nombre' => $nombre];
        if ($excluirId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excluirId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Ya existe un insumo con ese nombre.';
        }

        return null;
    }
}