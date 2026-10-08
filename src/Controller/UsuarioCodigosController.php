<?php

declare(strict_types=1);

/**
 * UsuarioCodigosController: códigos de invitación para registrarse como
 * funcionario (el rol del código determina el rol de la cuenta).
 */
final class UsuarioCodigosController
{
    use UsuarioData;

    /** Panel de códigos: formulario para generar uno nuevo y listado con estado. */
    public static function codigos(): void
    {
        requerir_gestion();

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

        // El código recién generado llega por ?nuevo=CODIGO.
        $nuevo = trim((string) ($_GET['nuevo'] ?? ''));

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

    /** Genera un código nuevo y redirige al panel mostrándolo. */
    public static function generarCodigo(): void
    {
        requerir_gestion();

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

    /** Código único "ELY-XXXXXXXXXX"; reintenta si choca (columna UNIQUE). */
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