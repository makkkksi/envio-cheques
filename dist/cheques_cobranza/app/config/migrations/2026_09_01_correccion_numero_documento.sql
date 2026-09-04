-- Migración: 2026_09_01_correccion_numero_documento.sql
-- Agrega numero_documento_original para auditaría de corrección de N° boleta por Tesorería.
-- El campo numero_documento ya existe; al corregirlo, el original del vendedor se guarda aquí.

USE `bd_modulo_cobranzas`;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND COLUMN_NAME = 'numero_documento_original'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE rendicion_documentos ADD COLUMN numero_documento_original VARCHAR(50) NULL COMMENT ''Número de documento digitado originalmente por el vendedor antes de corrección de Tesorería'' AFTER numero_documento',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

