CREATE DATABASE IF NOT EXISTS `elyra` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `elyra`;

CREATE TABLE `usuario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('funcionario','paciente') NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `documento_identidad` varchar(20) DEFAULT NULL,
  `foto` longblob DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `documento_identidad` (`documento_identidad`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `codigo_qr` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tipo_documento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tipo_documento_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ruta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `origen` varchar(200) NOT NULL,
  `destino` varchar(200) NOT NULL,
  `distancia_km` decimal(10,2) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehiculo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patente` varchar(20) NOT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `anio` year(4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `patente` (`patente`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `funcionario` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `licencia` varchar(50) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `rol` enum('admin','superadmin','conductor','copiloto') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_funcionario_rol` (`rol`),
  KEY `idx_funcionario_activo` (`activo`),
  CONSTRAINT `funcionario_ibfk_1` FOREIGN KEY (`id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `encuesta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  `creada_por` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `creada_por` (`creada_por`),
  CONSTRAINT `encuesta_ibfk_1` FOREIGN KEY (`creada_por`) REFERENCES `funcionario` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `paciente` (
  `id` int(11) NOT NULL,
  `token_acceso` varchar(64) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `codigo_qr_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_acceso` (`token_acceso`),
  UNIQUE KEY `username` (`username`),
  KEY `codigo_qr_id` (`codigo_qr_id`),
  CONSTRAINT `paciente_ibfk_1` FOREIGN KEY (`id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE,
  CONSTRAINT `paciente_ibfk_2` FOREIGN KEY (`codigo_qr_id`) REFERENCES `codigo_qr` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(200) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `archivo_path` varchar(255) NOT NULL,
  `archivo_nombre` varchar(100) NOT NULL,
  `archivo_contenido` longblob DEFAULT NULL,
  `codigo_qr_id` int(11) DEFAULT NULL,
  `qr_path` varchar(255) DEFAULT NULL,
  `tipo_documento_id` int(11) NOT NULL,
  `encuesta_id` int(11) DEFAULT NULL,
  `paciente_id` int(11) DEFAULT NULL,
  `subido_por` int(11) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `encuesta_id` (`encuesta_id`),
  KEY `codigo_qr_id` (`codigo_qr_id`),
  KEY `subido_por` (`subido_por`),
  KEY `idx_documento_paciente` (`paciente_id`),
  KEY `idx_documento_tipo_documento` (`tipo_documento_id`),
  CONSTRAINT `documento_ibfk_3` FOREIGN KEY (`encuesta_id`) REFERENCES `encuesta` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documento_ibfk_4` FOREIGN KEY (`subido_por`) REFERENCES `funcionario` (`id`),
  CONSTRAINT `documento_ibfk_5` FOREIGN KEY (`paciente_id`) REFERENCES `paciente` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documento_tipo_documento` FOREIGN KEY (`tipo_documento_id`) REFERENCES `tipo_documento` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `traslado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL,
  `conductor_id` int(11) NOT NULL,
  `copiloto_id` int(11) DEFAULT NULL,
  `vehiculo_id` int(11) DEFAULT NULL,
  `ruta_id` int(11) DEFAULT NULL,
  `origen` varchar(200) NOT NULL,
  `origen_lat` decimal(10,7) DEFAULT NULL,
  `origen_lng` decimal(10,7) DEFAULT NULL,
  `destino` varchar(200) NOT NULL,
  `destino_lat` decimal(10,7) DEFAULT NULL,
  `destino_lng` decimal(10,7) DEFAULT NULL,
  `hora_salida_estimada` datetime DEFAULT NULL,
  `hora_salida_efectiva` datetime DEFAULT NULL,
  `hora_llegada_destino` datetime DEFAULT NULL,
  `hora_inicio_retorno` datetime DEFAULT NULL,
  `hora_llegada_hospital` datetime DEFAULT NULL,
  `estado` enum('pendiente','en_curso','en_destino','en_retorno','completado','cancelado') DEFAULT 'pendiente',
  `motivo_cancelacion` text DEFAULT NULL,
  `registrado_por` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `conductor_id` (`conductor_id`),
  KEY `copiloto_id` (`copiloto_id`),
  KEY `vehiculo_id` (`vehiculo_id`),
  KEY `ruta_id` (`ruta_id`),
  KEY `registrado_por` (`registrado_por`),
  CONSTRAINT `traslado_ibfk_1` FOREIGN KEY (`conductor_id`) REFERENCES `funcionario` (`id`),
  CONSTRAINT `traslado_ibfk_2` FOREIGN KEY (`copiloto_id`) REFERENCES `funcionario` (`id`) ON DELETE SET NULL,
  CONSTRAINT `traslado_ibfk_3` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculo` (`id`) ON DELETE SET NULL,
  CONSTRAINT `traslado_ibfk_4` FOREIGN KEY (`ruta_id`) REFERENCES `ruta` (`id`) ON DELETE SET NULL,
  CONSTRAINT `traslado_ibfk_5` FOREIGN KEY (`registrado_por`) REFERENCES `funcionario` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `historial_estado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `traslado_id` int(11) NOT NULL,
  `estado_anterior` varchar(20) DEFAULT NULL,
  `estado_nuevo` varchar(20) NOT NULL,
  `observacion` text DEFAULT NULL,
  `actualizado_por` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `traslado_id` (`traslado_id`),
  KEY `actualizado_por` (`actualizado_por`),
  CONSTRAINT `historial_estado_ibfk_1` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE CASCADE,
  CONSTRAINT `historial_estado_ibfk_2` FOREIGN KEY (`actualizado_por`) REFERENCES `funcionario` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ubicacion_conductor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conductor_id` int(11) NOT NULL,
  `traslado_id` int(11) DEFAULT NULL,
  `latitud` decimal(10,7) NOT NULL,
  `longitud` decimal(10,7) NOT NULL,
  `heading` smallint(6) DEFAULT NULL,
  `velocidad` decimal(5,1) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conductor` (`conductor_id`),
  KEY `traslado_id` (`traslado_id`),
  CONSTRAINT `ubicacion_conductor_ibfk_1` FOREIGN KEY (`conductor_id`) REFERENCES `funcionario` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ubicacion_conductor_ibfk_2` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `paciente_traslado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `traslado_id` int(11) NOT NULL,
  `paciente_id` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_paciente_traslado` (`traslado_id`,`paciente_id`),
  KEY `fk_paciente_traslado_paciente` (`paciente_id`),
  CONSTRAINT `fk_paciente_traslado_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `paciente` (`id`),
  CONSTRAINT `fk_paciente_traslado_traslado` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `insumo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `traslado_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_insumo_nombre` (`nombre`),
  KEY `idx_insumo_activo` (`activo`),
  KEY `idx_insumo_traslado` (`traslado_id`),
  CONSTRAINT `fk_insumo_traslado` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `traslado_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_organo_nombre` (`nombre`),
  KEY `idx_organo_activo` (`activo`),
  KEY `idx_organo_traslado` (`traslado_id`),
  CONSTRAINT `fk_organo_traslado` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `equipamiento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `traslado_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_equipamiento_nombre` (`nombre`),
  KEY `idx_equipamiento_activo` (`activo`),
  KEY `idx_equipamiento_traslado` (`traslado_id`),
  CONSTRAINT `fk_equipamiento_traslado` FOREIGN KEY (`traslado_id`) REFERENCES `traslado` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pregunta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `encuesta_id` int(11) NOT NULL,
  `tipo` enum('multiple_choice','escala','texto_libre') NOT NULL,
  `texto` varchar(500) NOT NULL,
  `requerida` tinyint(1) DEFAULT 1,
  `orden` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `encuesta_id` (`encuesta_id`),
  CONSTRAINT `pregunta_ibfk_1` FOREIGN KEY (`encuesta_id`) REFERENCES `encuesta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pregunta_opcion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pregunta_id` int(11) NOT NULL,
  `texto` varchar(500) NOT NULL,
  `orden` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pregunta_opcion_orden` (`pregunta_id`,`orden`),
  KEY `idx_pregunta_opcion_pregunta` (`pregunta_id`),
  CONSTRAINT `fk_pregunta_opcion_pregunta` FOREIGN KEY (`pregunta_id`) REFERENCES `pregunta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `respuesta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `encuesta_id` int(11) NOT NULL,
  `sesion_token` varchar(64) NOT NULL,
  `token_paciente` varchar(64) DEFAULT NULL,
  `pregunta_id` int(11) NOT NULL,
  `valor_opcion` int(11) DEFAULT NULL,
  `valor_texto` text DEFAULT NULL,
  `valor_numerico` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_respuesta_sesion_pregunta` (`sesion_token`,`pregunta_id`),
  KEY `idx_respuesta_encuesta` (`encuesta_id`),
  KEY `idx_respuesta_pregunta` (`pregunta_id`),
  KEY `fk_respuesta_opcion` (`valor_opcion`),
  CONSTRAINT `fk_respuesta_encuesta` FOREIGN KEY (`encuesta_id`) REFERENCES `encuesta` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_respuesta_opcion` FOREIGN KEY (`valor_opcion`) REFERENCES `pregunta_opcion` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_respuesta_pregunta` FOREIGN KEY (`pregunta_id`) REFERENCES `pregunta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `codigo_funcionario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(32) NOT NULL,
  `rol` enum('admin','superadmin','conductor','copiloto') NOT NULL DEFAULT 'conductor',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `usado` tinyint(1) NOT NULL DEFAULT 0,
  `creado_por` int(11) DEFAULT NULL,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  `usado_en` datetime DEFAULT NULL,
  `usado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codigo_funcionario_codigo` (`codigo`),
  KEY `idx_codigo_funcionario_estado` (`activo`,`usado`),
  KEY `fk_codigo_funcionario_creador` (`creado_por`),
  KEY `fk_codigo_funcionario_usado_por` (`usado_por`),
  CONSTRAINT `fk_codigo_funcionario_creador` FOREIGN KEY (`creado_por`) REFERENCES `funcionario` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_codigo_funcionario_usado_por` FOREIGN KEY (`usado_por`) REFERENCES `usuario` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
