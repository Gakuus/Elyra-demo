<?php

declare(strict_types=1);

/**
 * UsuarioCodigosController: panel de códigos de funcionario.
 *
 * Son códigos de invitación que un admin/superadmin genera para que una
 * persona se registre como funcionario (el rol del código determina el rol de
 * la cuenta). Viven acá, separados del directorio (UsuarioController); la
 * capa compartida (permiso de gestión) está en el trait UsuarioData y el
 * HTML de filas y tablas lo arman las vistas, no este archivo.
 */
final class UsuarioCodigosController
{
    use UsuarioData;

    /**
     * Panel de códigos de funcionario (GET a /usuarios/codigos).
     * Muestra el formulario para generar un código nuevo (con el rol que va a
     * otorgar) y el listado de códigos generados con su estado. Solo accesible
     * para admin/superadmin.
     */
    public static function codigos(): void
    {
        requerir_login();
        if (!self::esGestion()) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $pdo = db_connect();
        $stmt = $pdo->query(
            "SELECT c.id, c.codigo, c.rol, c.activo, c.usado, c.creado_en, c.usado_en,
                    f.username AS usado_por_username
             FROM codigo_funcionario c
             LEFT JOIN funcionario f ON f.id = c.usado_por
             ORDER BY c.creado_en DESC
             LIMIT 100"
        );
        $codigos = $stmt->fetchAll();

        // El código que acabamos de generar llega por ?nuevo=CODIGO.
        $nuevo = trim((string) ($_GET['nuevo'] ?? ''));

        // Una fila por código; la vista arma {{#codigos}} con sus 3 estados.
        $filas = [];
        foreach ($codigos as $c) {
            $filas[] = [
                'codigo' => htmlspecialchars((string) $c['codigo']),
                'rol' => htmlspecialchars(ucfirst((string) $c['rol'])),
                'fecha' => date('d/m/Y H:i', (int) strtotime((string) $c['creado_en'])),
                'usado' => (bool) $c['usado'] ? ['1'] : [],
                'activo' => (bool) $c['activo'] ? ['1'] : [],
                'usado_por' => htmlspecialchars((string) ($c['usado_por_username'] ?? '—')),
            ];
        }

        render_dashboard('usuarios_codigos', 'Códigos de funcionario', 'usuarios', [
            'hay_nuevo' => $nuevo !== '' ? ['1'] : [],
            'nuevo' => htmlspecialchars($nuevo),
            'codigos' => $filas,
            'hay_codigos' => $filas === [] ? [] : ['1'],
        ]);
    }

    /**
     * Genera un código de funcionario nuevo (POST a /usuarios/codigos).
     * Elige el rol que otorgará, lo guarda como "disponible" y redirige al
     * panel mostrando el código generado para que el admin lo entregue.
     */
    public static function generarCodigo(): void
    {
        requerir_login();
        if (!self::esGestion()) {
            header('Location: ' . base_path() . '/usuarios');
            exit;
        }

        $rol = (string) ($_POST['rol'] ?? '');
        if (!in_array($rol, ['admin', 'superadmin', 'conductor', 'copiloto'], true)) {
            $rol = 'conductor';
        }

        $pdo = db_connect();
        $creadoPor = (int) ($_SESSION['usuario_id'] ?? 0);

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO codigo_funcionario (codigo, rol, creado_por)
                 VALUES (:codigo, :rol, :creado_por)'
            );
            $stmt->execute([
                'codigo' => self::nuevoCodigo($pdo),
                'rol' => $rol,
                'creado_por' => $creadoPor !== 0 ? $creadoPor : null,
            ]);

            // Recupera el código guardado para mostrarlo en el panel.
            $codigo = (string) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT codigo FROM codigo_funcionario WHERE id = :id');
            $stmt->execute(['id' => $codigo]);
            $codigo = (string) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $codigo = '';
        }

        header('Location: ' . base_path() . '/usuarios/codigos' . ($codigo !== '' ? '?nuevo=' . urlencode($codigo) : ''));
        exit;
    }

    /**
     * Genera un código único "ELY-XXXXXXXXXX" que aún no exista en la tabla.
     * Como la columna codigo es UNIQUE, reintenta si por azar choca (muy raro).
     */
    private static function nuevoCodigo(PDO $pdo): string
    {
        do {
            $codigo = 'ELY-' . strtoupper(bin2hex(random_bytes(5)));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM codigo_funcionario WHERE codigo = :codigo');
            $stmt->execute(['codigo' => $codigo]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $codigo;
    }
}