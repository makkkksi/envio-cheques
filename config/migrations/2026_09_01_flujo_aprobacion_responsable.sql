-- ============================================================================
-- MIGRACION: FLUJO DE APROBACION POR RESPONSABLE, PLANILLA PDF Y EDICION DE MONTOS
-- Fecha: 2026-09-01
-- Base objetivo: bd_modulo_cobranzas
-- Aditiva e idempotente: no contiene DROP TABLE ni DELETE de datos.
-- ============================================================================

USE bd_modulo_cobranzas;

-- 1. Ampliar ENUM de estado en rendiciones_gastos
ALTER TABLE rendiciones_gastos
MODIFY COLUMN estado ENUM(
  'BORRADOR',
  'ENVIADA',
  'PENDIENTE_APROBACION_EXCESO',
  'EN_REVISION_TESORERIA',
  'PENDIENTE_APROBACION_RESPONSABLE',
  'DOCUMENTOS_FISICOS_RECIBIDOS',
  'APROBADA',
  'APROBADA_PARCIAL',
  'RECHAZADA',
  'PAGADA'
) NOT NULL DEFAULT 'ENVIADA';

-- 2. Nuevas columnas de verificación y PDF en rendiciones_gastos
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'verificado_tesoreria_at') = 0,
  'ALTER TABLE rendiciones_gastos ADD COLUMN verificado_tesoreria_at DATETIME NULL AFTER estado',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'verificado_tesoreria_por') = 0,
  'ALTER TABLE rendiciones_gastos ADD COLUMN verificado_tesoreria_por INT NULL AFTER verificado_tesoreria_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'pdf_planilla_url') = 0,
  'ALTER TABLE rendiciones_gastos ADD COLUMN pdf_planilla_url VARCHAR(255) NULL AFTER verificado_tesoreria_por',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Columnas de edición manual de montos en rendicion_documentos
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendicion_documentos' AND COLUMN_NAME = 'monto_original') = 0,
  'ALTER TABLE rendicion_documentos ADD COLUMN monto_original DECIMAL(12,2) NULL AFTER monto',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendicion_documentos' AND COLUMN_NAME = 'editado_por') = 0,
  'ALTER TABLE rendicion_documentos ADD COLUMN editado_por INT NULL AFTER motivo_rechazo',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendicion_documentos' AND COLUMN_NAME = 'editado_at') = 0,
  'ALTER TABLE rendicion_documentos ADD COLUMN editado_at DATETIME NULL AFTER editado_por',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendicion_documentos' AND COLUMN_NAME = 'motivo_edicion') = 0,
  'ALTER TABLE rendicion_documentos ADD COLUMN motivo_edicion VARCHAR(255) NULL AFTER editado_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Ampliar ENUM tipo_solicitud en solicitudes_aprobacion
ALTER TABLE solicitudes_aprobacion
MODIFY COLUMN tipo_solicitud ENUM('GIRA', 'EXCEPCION_MENSUAL', 'APROBACION_RENDICION') NOT NULL;

-- 5. Actualizar el constraint de comprobación chk_solicitud_objetivo
ALTER TABLE solicitudes_aprobacion DROP CHECK chk_solicitud_objetivo;
ALTER TABLE solicitudes_aprobacion ADD CONSTRAINT chk_solicitud_objetivo CHECK (
  (tipo_solicitud = 'GIRA' AND presupuesto_id IS NOT NULL AND rendicion_id IS NULL)
  OR (tipo_solicitud IN ('EXCEPCION_MENSUAL', 'APROBACION_RENDICION') AND presupuesto_id IS NULL AND rendicion_id IS NOT NULL)
);
