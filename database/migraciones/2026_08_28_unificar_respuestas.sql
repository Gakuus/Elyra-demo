-- ============================================================
-- Elyra - Unificar respuestas de encuestas
-- Fecha: 2026-08-28
-- Base: elyra (MariaDB 11.8)
--
-- Cambio:
--   respuesta_sesion + respuesta_pregunta -> respuesta
--
-- Motivo: separar en dos tablas la sesión anónima y la respuesta de
-- cada pregunta obligaba a un INSERT doble y a un JOIN para cualquier
-- conteo. Una sola tabla alcanza: la sesión se identifica por
-- sesion_token (único por envío) y cada fila es la respuesta de una
-- pregunta con sus datos de sesión. Se conserva pregunta_opcion,
-- que es la definición de las opciones de una pregunta.
--
-- Datos migrados: se conservan los 4 sesiones / 8 respuestas actuales.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS respuesta (
  id INT NOT NULL AUTO_INCREMENT,
  encuesta_id INT NOT NULL,
  sesion_token VARCHAR(64) NOT NULL,
  token_paciente VARCHAR(64) DEFAULT NULL,
  pregunta_id INT NOT NULL,
  valor_opcion INT DEFAULT NULL,
  valor_texto TEXT DEFAULT NULL,
  valor_numerico INT DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_respuesta_sesion_pregunta (sesion_token, pregunta_id),
  KEY idx_respuesta_encuesta (encuesta_id),
  KEY idx_respuesta_pregunta (pregunta_id),
  CONSTRAINT fk_respuesta_encuesta FOREIGN KEY (encuesta_id)
    REFERENCES encuesta (id) ON DELETE CASCADE,
  CONSTRAINT fk_respuesta_pregunta FOREIGN KEY (pregunta_id)
    REFERENCES pregunta (id) ON DELETE CASCADE,
  CONSTRAINT fk_respuesta_opcion FOREIGN KEY (valor_opcion)
    REFERENCES pregunta_opcion (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO respuesta
  (encuesta_id, sesion_token, token_paciente, pregunta_id,
   valor_opcion, valor_texto, valor_numerico, created_at)
SELECT rs.encuesta_id,
       rs.sesion_token,
       rs.token_paciente,
       rp.pregunta_id,
       rp.valor_opcion,
       rp.valor_texto,
       rp.valor_numerico,
       rp.created_at
FROM respuesta_pregunta rp
JOIN respuesta_sesion rs ON rs.id = rp.respuesta_id;

DROP TABLE IF EXISTS respuesta_pregunta;
DROP TABLE IF EXISTS respuesta_sesion;

SET FOREIGN_KEY_CHECKS = 1;