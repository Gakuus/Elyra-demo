-- ============================================================
-- Elyra - Códigos de acceso para registrarse como funcionario
-- Fecha: 2026-09-04
-- Base: elyra (MariaDB 11.8)
--
-- Cambio:
--   Nueva tabla codigo_funcionario: almacena códigos únicos que un
--   admin/superadmin genera y entrega en mano. Quien se registra en la
--   portada con uno de estos códigos pasa a ser FUNCIONARIO con el rol
--   que trae el código, en lugar de paciente.
--
-- Uso:
--   mysql -u elyra -pelyra_pass elyra < database/migraciones/2026_09_04_codigo_funcionario.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS codigo_funcionario (
  id INT NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(32) NOT NULL COMMENT 'Código único de invitación (EL Y + hex)',
  rol ENUM('admin','superadmin','conductor','copiloto') NOT NULL DEFAULT 'conductor'
    COMMENT 'Rol que se le otorga a quien se registre con este código',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  creado_por INT DEFAULT NULL COMMENT 'Funcionario que generó el código',
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  usado_en DATETIME DEFAULT NULL,
  usado_por INT DEFAULT NULL COMMENT 'ID del usuario que consumió el código',
  PRIMARY KEY (id),
  UNIQUE KEY uk_codigo_funcionario_codigo (codigo),
  KEY idx_codigo_funcionario_estado (activo, usado),
  CONSTRAINT fk_codigo_funcionario_creador FOREIGN KEY (creado_por)
    REFERENCES funcionario (id) ON DELETE SET NULL,
  CONSTRAINT fk_codigo_funcionario_usado_por FOREIGN KEY (usado_por)
    REFERENCES usuario (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;