-- =============================================================================
-- MIGRACIÓN: 2026_09_02_indices_y_fks_auditoria.sql
-- Base de datos: bd_modulo_cobranzas
-- Motor: MySQL 8.0+ / 8.4.3 InnoDB
-- Propósito: Agregar numero_documento_original, índices y claves foráneas
--            para verificado_tesoreria_por y editado_por de forma idempotente.
-- =============================================================================

USE `bd_modulo_cobranzas`;

-- 1. Columna numero_documento_original en rendicion_documentos
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND COLUMN_NAME = 'numero_documento_original'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE rendicion_documentos ADD COLUMN numero_documento_original VARCHAR(50) NULL COMMENT ''Número digitado originalmente por el vendedor antes de corrección'' AFTER numero_documento',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Índice idx_rendicion_verificado_por en rendiciones_gastos
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendiciones_gastos'
      AND INDEX_NAME = 'idx_rendicion_verificado_por'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE rendiciones_gastos ADD KEY idx_rendicion_verificado_por (verificado_tesoreria_por)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Diagnóstico preventivo de IDs huérfanos antes de crear clave foránea en rendiciones_gastos
DROP PROCEDURE IF EXISTS `preflight_check_orphans_rendiciones`;
DELIMITER $$
CREATE PROCEDURE `preflight_check_orphans_rendiciones`()
BEGIN
    DECLARE orphan_count INT DEFAULT 0;
    SELECT COUNT(*) INTO orphan_count
    FROM rendiciones_gastos
    WHERE verificado_tesoreria_por IS NOT NULL
      AND verificado_tesoreria_por NOT IN (SELECT id FROM usuarios);

    IF orphan_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Migracion abortada: existen IDs huerfanos en rendiciones_gastos.verificado_tesoreria_por. Identificar con: SELECT id, verificado_tesoreria_por FROM rendiciones_gastos WHERE verificado_tesoreria_por IS NOT NULL AND verificado_tesoreria_por NOT IN (SELECT id FROM usuarios);';
    END IF;
END$$
DELIMITER ;
CALL `preflight_check_orphans_rendiciones`();
DROP PROCEDURE IF EXISTS `preflight_check_orphans_rendiciones`;

-- 4. Clave foránea fk_rendicion_verificado_usuario en rendiciones_gastos
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendiciones_gastos'
      AND CONSTRAINT_NAME = 'fk_rendicion_verificado_usuario'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE rendiciones_gastos ADD CONSTRAINT fk_rendicion_verificado_usuario FOREIGN KEY (verificado_tesoreria_por) REFERENCES usuarios(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Índice idx_documento_editado_por en rendicion_documentos
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND INDEX_NAME = 'idx_documento_editado_por'
);
SET @sql = IF(
    @idx_exists = 0,
    'ALTER TABLE rendicion_documentos ADD KEY idx_documento_editado_por (editado_por)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Diagnóstico preventivo de IDs huérfanos antes de crear clave foránea en rendicion_documentos
DROP PROCEDURE IF EXISTS `preflight_check_orphans_documentos`;
DELIMITER $$
CREATE PROCEDURE `preflight_check_orphans_documentos`()
BEGIN
    DECLARE orphan_count INT DEFAULT 0;
    SELECT COUNT(*) INTO orphan_count
    FROM rendicion_documentos
    WHERE editado_por IS NOT NULL
      AND editado_por NOT IN (SELECT id FROM usuarios);

    IF orphan_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Migracion abortada: existen IDs huerfanos en rendicion_documentos.editado_por. Identificar con: SELECT id, editado_por FROM rendicion_documentos WHERE editado_por IS NOT NULL AND editado_por NOT IN (SELECT id FROM usuarios);';
    END IF;
END$$
DELIMITER ;
CALL `preflight_check_orphans_documentos`();
DROP PROCEDURE IF EXISTS `preflight_check_orphans_documentos`;

-- 7. Clave foránea fk_documento_editado_usuario en rendicion_documentos
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND CONSTRAINT_NAME = 'fk_documento_editado_usuario'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE rendicion_documentos ADD CONSTRAINT fk_documento_editado_usuario FOREIGN KEY (editado_por) REFERENCES usuarios(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
