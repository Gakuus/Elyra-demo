-- ============================================================
-- Elyra - Optimización: limpieza de tablas sin uso
-- Fecha: 2026-08-24
-- Base: elyra (MariaDB 11.8)
--
-- Respaldo previo: database/backups/elyra_antes_optimizacion_20260824.sql
--
-- Cambios:
--   DROP noticias            (0 filas, sin referencias en código)
--   DROP catalogo_elemento   (0 filas, sin referencias en código)
--   DROP historial_ubicacion (duplicada de ubicacion_conductor;
--                            se conserva la posición ACTUAL del conductor,
--                            el histórico no se usa en el programa)
--
-- No se tocan: usuario, funcionario, paciente.
--
-- Notas post-verificación:
--   - FKs íntegras tras los borrados (0 referencias huérfanas).
--   - codigo_qr se conserva: paciente.codigo_qr_id tiene FK hacia ella.
--   - traslado / elemento_traslado / historial_estado / ruta / vehiculo /
--     ubicacion_conductor: estructura validada, forman parte del dominio
--     de traslados.
-- ============================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS noticias;
DROP TABLE IF EXISTS catalogo_elemento;
DROP TABLE IF EXISTS historial_ubicacion;

-- ============================================================
-- Integridad de elemento_traslado:
--   si tipo='paciente', paciente_id es obligatorio.
--
-- MariaDB NO permite CHECK sobre columnas con FOREIGN KEY
-- (error 1901), por eso se implementa con triggers.
-- Requiere privilegios elevados (log_bin activo):
--   mysql -u root -p < database/migraciones/2026_08_24_optimizacion_limpiar_tablas.sql --force
-- ============================================================

DELIMITER //

CREATE TRIGGER trg_elem_traslado_paciente_ins
BEFORE INSERT ON elemento_traslado
FOR EACH ROW
BEGIN
  IF NEW.tipo = 'paciente' AND NEW.paciente_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'elemento_traslado: si tipo=paciente, paciente_id es obligatorio';
  END IF;
END//

CREATE TRIGGER trg_elem_traslado_paciente_upd
BEFORE UPDATE ON elemento_traslado
FOR EACH ROW
BEGIN
  IF NEW.tipo = 'paciente' AND NEW.paciente_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'elemento_traslado: si tipo=paciente, paciente_id es obligatorio';
  END IF;
END//

DELIMITER ;
