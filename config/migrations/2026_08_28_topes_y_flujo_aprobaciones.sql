-- ============================================================================
-- MIGRACION: TOPES Y FLUJO UNIFICADO DE APROBACIONES DE RENDICIONES
-- Fecha: 2026-08-28
-- Base objetivo: bd_modulo_cobranzas
-- Importar este archivo completo desde phpMyAdmin.
-- Aditiva e idempotente: no contiene DROP, TRUNCATE ni DELETE.
-- ============================================================================

USE bd_modulo_cobranzas;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND COLUMN_NAME = 'estado_aprobacion') = 0, 'ALTER TABLE presupuestos_vendedores ADD COLUMN estado_aprobacion ENUM(''NO_APLICA'', ''PENDIENTE'', ''APROBADA'', ''RECHAZADA'') NOT NULL DEFAULT ''NO_APLICA'' AFTER monto_utilizado', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND COLUMN_NAME = 'justificacion_gira') = 0, 'ALTER TABLE presupuestos_vendedores ADD COLUMN justificacion_gira VARCHAR(500) NULL AFTER estado_aprobacion', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND COLUMN_NAME = 'solicitud_aprobacion_id') = 0, 'ALTER TABLE presupuestos_vendedores ADD COLUMN solicitud_aprobacion_id INT NULL AFTER justificacion_gira', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND COLUMN_NAME = 'aprobado_at') = 0, 'ALTER TABLE presupuestos_vendedores ADD COLUMN aprobado_at DATETIME NULL AFTER solicitud_aprobacion_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'monto_maximo_aprobable') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN monto_maximo_aprobable DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monto_total_aprobado', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'monto_exceso_no_reembolsable') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN monto_exceso_no_reembolsable DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monto_exceso', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'aplico_tope_presupuestario') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN aplico_tope_presupuestario TINYINT(1) NOT NULL DEFAULT 0 AFTER monto_exceso_no_reembolsable', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'solicitud_excepcion_id') = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN solicitud_excepcion_id INT NULL AFTER aplico_tope_presupuestario', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS solicitudes_aprobacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_solicitud ENUM('GIRA', 'EXCEPCION_MENSUAL') NOT NULL,
  presupuesto_id INT NULL,
  rendicion_id INT NULL,
  solicitud_version INT UNSIGNED NOT NULL DEFAULT 1,
  token_version INT UNSIGNED NOT NULL DEFAULT 1,
  aprobador_id INT NOT NULL,
  aprobador_nombre_snapshot VARCHAR(150) NOT NULL,
  aprobador_cargo_snapshot VARCHAR(120) NOT NULL,
  aprobador_email_snapshot VARCHAR(190) NOT NULL,
  monto_base_aprobable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_solicitado DECIMAL(12,2) NOT NULL,
  justificacion VARCHAR(500) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_expira_at DATETIME NOT NULL,
  token_usado_at DATETIME NULL,
  estado ENUM('PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA', 'APROBADA', 'RECHAZADA', 'CANCELADA') NOT NULL DEFAULT 'PENDIENTE_ENVIO',
  decision ENUM('APROBADA', 'RECHAZADA') NULL,
  comentario_decision VARCHAR(500) NULL,
  correo_enviado_at DATETIME NULL,
  motivo_envio_fallido VARCHAR(500) NULL,
  solicitado_por INT NOT NULL,
  resuelto_at DATETIME NULL,
  cancelado_at DATETIME NULL,
  cancelado_por INT NULL,
  motivo_cancelacion VARCHAR(500) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_solicitud_token_hash (token_hash),
  UNIQUE KEY uq_solicitud_presupuesto_version (tipo_solicitud, presupuesto_id, solicitud_version),
  UNIQUE KEY uq_solicitud_rendicion_version (tipo_solicitud, rendicion_id, solicitud_version),
  KEY idx_solicitud_estado (estado, tipo_solicitud, created_at),
  KEY idx_solicitud_aprobador (aprobador_id, estado),
  KEY idx_solicitud_solicitante (solicitado_por),
  KEY idx_solicitud_cancelador (cancelado_por),
  CONSTRAINT chk_solicitud_objetivo CHECK (
    (tipo_solicitud = 'GIRA' AND presupuesto_id IS NOT NULL AND rendicion_id IS NULL)
    OR (tipo_solicitud = 'EXCEPCION_MENSUAL' AND presupuesto_id IS NULL AND rendicion_id IS NOT NULL)
  ),
  CONSTRAINT chk_solicitud_monto CHECK (monto_solicitado > 0 AND monto_base_aprobable >= 0),
  CONSTRAINT fk_solicitud_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_rendicion FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_aprobador FOREIGN KEY (aprobador_id) REFERENCES aprobadores_rendiciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_solicitante FOREIGN KEY (solicitado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_cancelador FOREIGN KEY (cancelado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitud_aprobacion_historial (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  solicitud_id INT NOT NULL,
  actor_tipo ENUM('TESORERIA', 'RESPONSABLE', 'SISTEMA') NOT NULL,
  actor_id INT NULL,
  actor_nombre VARCHAR(150) NOT NULL,
  actor_email VARCHAR(190) NULL,
  accion VARCHAR(80) NOT NULL,
  estado_anterior VARCHAR(40) NULL,
  estado_nuevo VARCHAR(40) NOT NULL,
  comentario VARCHAR(500) NULL,
  metadata_json LONGTEXT NULL,
  ip_origen VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_solicitud_historial (solicitud_id, created_at),
  CONSTRAINT fk_solicitud_historial FOREIGN KEY (solicitud_id) REFERENCES solicitudes_aprobacion(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND INDEX_NAME = 'idx_presupuesto_estado_aprobacion') = 0, 'ALTER TABLE presupuestos_vendedores ADD KEY idx_presupuesto_estado_aprobacion (tipo_presupuesto, estado_aprobacion, activo)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND INDEX_NAME = 'idx_presupuesto_solicitud') = 0, 'ALTER TABLE presupuestos_vendedores ADD KEY idx_presupuesto_solicitud (solicitud_aprobacion_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND INDEX_NAME = 'idx_rendicion_solicitud_excepcion') = 0, 'ALTER TABLE rendiciones_gastos ADD KEY idx_rendicion_solicitud_excepcion (solicitud_excepcion_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND CONSTRAINT_NAME = 'fk_presupuesto_solicitud_aprobacion') = 0, 'ALTER TABLE presupuestos_vendedores ADD CONSTRAINT fk_presupuesto_solicitud_aprobacion FOREIGN KEY (solicitud_aprobacion_id) REFERENCES solicitudes_aprobacion(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND CONSTRAINT_NAME = 'fk_rendicion_solicitud_excepcion') = 0, 'ALTER TABLE rendiciones_gastos ADD CONSTRAINT fk_rendicion_solicitud_excepcion FOREIGN KEY (solicitud_excepcion_id) REFERENCES solicitudes_aprobacion(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Compatibilidad local: los presupuestos mensuales no requieren aprobación.
UPDATE presupuestos_vendedores
SET estado_aprobacion = 'NO_APLICA'
WHERE tipo_presupuesto = 'MENSUAL'
  AND estado_aprobacion <> 'NO_APLICA';

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('solicitudes_aprobacion', 'solicitud_aprobacion_historial')
ORDER BY TABLE_NAME;

