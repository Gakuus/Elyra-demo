-- ============================================================
-- Migración: eliminar tabla especialidad
-- Fecha: 2026-08-25
-- ============================================================
-- especialidad existía del proyecto original pero la demo nunca
-- la usó: 0 filas, ningún PHP o HTML la referenciaba.
-- ============================================================

-- Quitamos primero la FK y la columna que la referenciaba
ALTER TABLE documento
  DROP FOREIGN KEY IF EXISTS fk_documento_especialidad,
  DROP INDEX IF EXISTS fk_documento_especialidad,
  DROP COLUMN IF EXISTS especialidad_id;

-- Tabla vacía, sin referencias
DROP TABLE IF EXISTS especialidad;
