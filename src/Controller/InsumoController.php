<?php

declare(strict_types=1);

/**
 * InsumoController: controlador del módulo de insumos médicos.
 *
 * Gestiona el stock de insumos de la institución: listado con búsqueda y
 * filtro de estado, alta, edición y activación/desactivación (baja lógica,
 * nunca se eliminan filas para conservar el historial). Solo accesible para
 * admin/superadmin (guard esGestion en cada acción).
 */
final class InsumoController
{
    use InsumoData;

    /**
     * Enrutador interno del módulo de insumos.
     * index.php delega acá cualquier ruta que empiece con /insumos y este
     * método decide qué acción ejecutar según la ruta exacta y el método HTTP.
     *
     * @param string $path   Ruta (ej: '/insumos/agregar').
     * @param string $method Método HTTP en mayúsculas ('GET' o 'POST').
     */
    public static function dispatch(string $path, string $method): void
    {
        match (true) {
            // Listado de insumos.
            $path === '/insumos' && $method === 'GET' => self::listar(),
            // Alta: POST procesa el formulario, GET muestra el formulario vacío.
            $path === '/insumos/agregar' && $method === 'POST' => self::agregar(),
            $path === '/insumos/agregar' && $method === 'GET' => self::formularioAgregar(),
            // Edición: POST guarda, GET muestra el formulario con los datos.
            $path === '/insumos/editar' && $method === 'POST' => self::editar(),
            $path === '/insumos/editar' && $method === 'GET' => self::formularioEditar(),
            // Activar/desactivar un insumo (baja lógica, responde JSON).
            $path === '/insumos/toggle' && $method === 'POST' => self::toggle(),
            // Ninguna condición coincidió → página 404.
            default => pagina_404(),
        };
    }

    /**
     * Guard de gestión: solo admin/superadmin. Si no, manda al dashboard
     * (que exige sesión pero no gestión) y detiene la ejecución.
     */
    private static function guardarGestion(): void
    {
        requerir_login();
        if (!self::esGestion()) {
            header('Location: ' . base_path() . '/dashboard');
            exit;
        }
    }

    /**
     * Listado de insumos (GET a /insumos).
     * Muestra la tabla con todos los insumos ordenados por nombre, con
     * buscador por texto (nombre o descripción) y filtro de estado.
     */
    public static function listar(): void
    {
        self::guardarGestion();

        // Texto de búsqueda (?q=) y estado (?estado=activos|inactivos|todos).
        $q = trim((string) ($_GET['q'] ?? ''));
        $estado = (string) ($_GET['estado'] ?? 'activos');
        if (!in_array($estado, ['todos', 'activos', 'inactivos'], true)) {
            $estado = 'activos';
        }

        $insumos = self::buscarInsumos($q, $estado);

        // Una fila por insumo con sus campos preparados para la vista.
        $filas = [];
        foreach ($insumos as $ins) {
            $filas[] = self::mostrarInsumo($ins);
        }

        // Aviso de éxito tras agregar o guardar cambios (?agregado=1 / ?editado=1).
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

    /**
     * Procesa el alta de un insumo (POST a /insumos/agregar).
     * Valida los datos y registra la fila. Si hay errores, vuelve al
     * formulario conservando lo cargado y mostrando el mensaje.
     */
    public static function agregar(): void
    {
        self::guardarGestion();

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

    /**
     * Muestra el formulario vacío de alta (GET a /insumos/agregar).
     */
    public static function formularioAgregar(): void
    {
        self::guardarGestion();

        render_dashboard('insumos_agregar', 'Agregar insumo', 'insumos', [
            'hay_error' => [],
            'error' => '',
            'valor_nombre' => '',
            'valor_descripcion' => '',
            'valor_stock' => '0',
        ]);
    }

    /**
     * Formulario de edición (GET a /insumos/editar?id=N).
     * Muestra los datos actuales del insumo para modificarlos.
     */
    public static function formularioEditar(): void
    {
        self::guardarGestion();

        $pdo = db_connect();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT id, nombre, descripcion, stock FROM insumo WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $ins = $stmt->fetch();

        // Si no existe, vuelve al listado.
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

    /**
     * Guarda los cambios del formulario de edición (POST a /insumos/editar).
     * Usa las mismas reglas de validación que en el alta; al editar se
     * excluye de la verificación de nombre duplicado al propio insumo.
     */
    public static function editar(): void
    {
        self::guardarGestion();

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

    /**
     * Activa o desactiva un insumo (POST a /insumos/toggle).
     * Es una baja lógica: cambia el campo activo, no borra la fila.
     * Responde en JSON para que el listado lo actualice sin recargar.
     */
    public static function toggle(): void
    {
        self::guardarGestion();

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            self::respondeJson(['ok' => false, 'error' => 'ID inválido.']);
        }

        $pdo = db_connect();
        $stmt = $pdo->prepare(
            'UPDATE insumo SET activo = IF(activo = 1, 0, 1) WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        // Devuelve el estado nuevo para que la fila se pueda pintar al toque.
        $stmt = $pdo->prepare('SELECT activo FROM insumo WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $activo = (bool) $stmt->fetchColumn();

        self::respondeJson(['ok' => true, 'activo' => $activo]);
    }

    /**
     * Valida los campos de un insumo (común a alta y edición).
     * Se detiene en el primer error y devuelve el mensaje; si todo está
     * bien devuelve null.
     *
     * @param PDO      $pdo        Conexión activa.
     * @param string   $nombre     Nombre del insumo.
     * @param string   $descripcion Descripción opcional.
     * @param string   $stock      Stock (texto del formulario).
     * @param int|null $excluirId  Id a excluir de la verificación de nombre
     *                             duplicado (al editar), null en el alta.
     */
    private static function validar(PDO $pdo, string $nombre, string $descripcion, string $stock, ?int $excluirId): ?string
    {
        if ($nombre === '') {
            return 'El nombre es obligatorio.';
        }
        if (mb_strlen($nombre) > 150) {
            return 'El nombre no puede superar los 150 caracteres.';
        }
        if (mb_strlen($descripcion) > 5000) {
            return 'La descripci&oacute;n es demasiado larga.';
        }
        if ($stock === '' || !ctype_digit($stock)) {
            return 'El stock debe ser un n&uacute;mero entero.';
        }
        if ((int) $stock > 100000000) {
            return 'El stock no puede superar los 100.000.000.';
        }

        // Nombre duplicado (la columna nombre es UNIQUE en la base).
        // En la edición se ignora la propia fila ($excluirId).
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