-- ============================================================================
-- MIGRACION INCREMENTAL: APROBADORES CONFIGURABLES PARA EXCESOS DE RENDICIONES
-- Fecha: 2026-08-26
-- Base objetivo: bd_modulo_cobranzas
-- Importar este archivo completo desde phpMyAdmin.
-- Migracion aditiva e idempotente: no contiene DROP, TRUNCATE ni DELETE.
-- ============================================================================

USE bd_modulo_cobranzas;

CREATE TABLE IF NOT EXISTS aprobadores_rendiciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden TINYINT UNSIGNED NOT NULL COMMENT 'Posicion configurable: 1 o 2',
  nombre VARCHAR(150) NOT NULL,
  cargo VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  actualizado_por INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aprobador_rendicion_orden (orden),
  UNIQUE KEY uq_aprobador_rendicion_email (email),
  KEY idx_aprobador_rendicion_activo (activo, orden),
  KEY idx_aprobador_rendicion_usuario (actualizado_por),
  CONSTRAINT chk_aprobador_rendicion_orden CHECK (orden IN (1, 2)),
  CONSTRAINT fk_aprobador_rendicion_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'aprobadores_rendiciones' AND CONSTRAINT_NAME = 'chk_aprobador_rendicion_orden') = 0, 'ALTER TABLE aprobadores_rendiciones ADD CONSTRAINT chk_aprobador_rendicion_orden CHECK (orden IN (1, 2))', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'aprobador_solicitado_id') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN aprobador_solicitado_id INT NULL AFTER aprobado_exceso_por', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'aprobador_nombre_snapshot') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN aprobador_nombre_snapshot VARCHAR(150) NULL AFTER aprobador_solicitado_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'aprobador_cargo_snapshot') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN aprobador_cargo_snapshot VARCHAR(120) NULL AFTER aprobador_nombre_snapshot', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'aprobador_email_snapshot') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN aprobador_email_snapshot VARCHAR(190) NULL AFTER aprobador_cargo_snapshot', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'solicitud_exceso_enviada_at') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN solicitud_exceso_enviada_at DATETIME NULL AFTER aprobador_email_snapshot', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'solicitud_exceso_enviada_por') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN solicitud_exceso_enviada_por INT NULL AFTER solicitud_exceso_enviada_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND INDEX_NAME = 'idx_rendicion_aprobador') = 0, 'ALTER TABLE rendiciones_gastos ADD KEY idx_rendicion_aprobador (aprobador_solicitado_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND INDEX_NAME = 'idx_rendicion_solicitud_usuario') = 0, 'ALTER TABLE rendiciones_gastos ADD KEY idx_rendicion_solicitud_usuario (solicitud_exceso_enviada_por)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND CONSTRAINT_NAME = 'fk_rendicion_aprobador') = 0, 'ALTER TABLE rendiciones_gastos ADD CONSTRAINT fk_rendicion_aprobador FOREIGN KEY (aprobador_solicitado_id) REFERENCES aprobadores_rendiciones(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND CONSTRAINT_NAME = 'fk_rendicion_solicitud_usuario') = 0, 'ALTER TABLE rendiciones_gastos ADD CONSTRAINT fk_rendicion_solicitud_usuario FOREIGN KEY (solicitud_exceso_enviada_por) REFERENCES usuarios(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verificacion esperada: 2 filas activas deben configurarse posteriormente desde el portal.
SELECT id, orden, nombre, cargo, email, activo, updated_at
FROM aprobadores_rendiciones
ORDER BY orden ASC;
