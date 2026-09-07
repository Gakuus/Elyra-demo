-- ============================================================
-- Elyra - Módulo de insumos médicos
-- Fecha: 2026-09-07
-- Base: elyra (MariaDB 11.8)
--
-- Cambio:
--   Nueva tabla insumo: almacena el stock de insumos médicos de la
--   institución. Cada fila es un insumo con su nombre (único),
--   descripción, stock disponible y estado (activo/inactivo). No
--   se eliminan filas: la baja se hace desactivando (activo = 0)
--   para conservar el historial.
--
-- Uso:
--   mysql -u elyra -pelyra_pass elyra < database/migraciones/2026_09_07_crear_tabla_insumo.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS insumo (
  id INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(150) NOT NULL COMMENT 'Nombre del insumo (único)',
  descripcion TEXT NULL COMMENT 'Descripción opcional del insumo',
  stock INT NOT NULL DEFAULT 0 COMMENT 'Cantidad disponible en stock',
  activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = desactivado (baja lógica)',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_insumo_nombre (nombre),
  KEY idx_insumo_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Insumos médicos del hospital';