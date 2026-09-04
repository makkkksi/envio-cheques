-- =============================================================================
-- MIGRACIÓN: 2026_09_04_baja_logica_cheques.sql
-- Base de datos: bd_modulo_cobranzas
-- Motor: MySQL 8.0+ / 8.4.3 InnoDB
-- Propósito: Implementar Zero Delete en la tabla cheques mediante columnas
--            de baja lógica (activo, descartado_at, descartado_por, motivo_descarte),
--            índices y clave foránea de auditoría de forma aditiva e idempotente.
-- =============================================================================

USE `bd_modulo_cobranzas`;

-- 1. Columna 'activo' (1 = Activo, 0 = Dado de baja)
SET @col_activo_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND COLUMN_NAME = 'activo'
);
SET @sql_activo = IF(
    @col_activo_exists = 0,
    'ALTER TABLE cheques ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = Activo, 0 = Dado de baja / descartado'' AFTER fecha_deposito_real',
    'SELECT 1'
);
PREPARE stmt_activo FROM @sql_activo; EXECUTE stmt_activo; DEALLOCATE PREPARE stmt_activo;

-- 2. Columna 'descartado_at'
SET @col_desc_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND COLUMN_NAME = 'descartado_at'
);
SET @sql_desc_at = IF(
    @col_desc_at_exists = 0,
    'ALTER TABLE cheques ADD COLUMN descartado_at TIMESTAMP NULL DEFAULT NULL COMMENT ''Fecha y hora en que fue dado de baja'' AFTER activo',
    'SELECT 1'
);
PREPARE stmt_desc_at FROM @sql_desc_at; EXECUTE stmt_desc_at; DEALLOCATE PREPARE stmt_desc_at;

-- 3. Columna 'descartado_por'
SET @col_desc_por_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND COLUMN_NAME = 'descartado_por'
);
SET @sql_desc_por = IF(
    @col_desc_por_exists = 0,
    'ALTER TABLE cheques ADD COLUMN descartado_por INT NULL DEFAULT NULL COMMENT ''ID del usuario que descarto el cheque'' AFTER descartado_at',
    'SELECT 1'
);
PREPARE stmt_desc_por FROM @sql_desc_por; EXECUTE stmt_desc_por; DEALLOCATE PREPARE stmt_desc_por;

-- 4. Columna 'motivo_descarte'
SET @col_motivo_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND COLUMN_NAME = 'motivo_descarte'
);
SET @sql_motivo = IF(
    @col_motivo_exists = 0,
    'ALTER TABLE cheques ADD COLUMN motivo_descarte VARCHAR(255) NULL DEFAULT NULL COMMENT ''Motivo o contexto del descarte'' AFTER descartado_por',
    'SELECT 1'
);
PREPARE stmt_motivo FROM @sql_motivo; EXECUTE stmt_motivo; DEALLOCATE PREPARE stmt_motivo;

-- 5. Índice idx_cheques_activo
SET @idx_activo_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND INDEX_NAME = 'idx_cheques_activo'
);
SET @sql_idx_activo = IF(
    @idx_activo_exists = 0,
    'ALTER TABLE cheques ADD KEY idx_cheques_activo (activo)',
    'SELECT 1'
);
PREPARE stmt_idx_activo FROM @sql_idx_activo; EXECUTE stmt_idx_activo; DEALLOCATE PREPARE stmt_idx_activo;

-- 6. Índice idx_cheques_descartado_por
SET @idx_desc_por_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND INDEX_NAME = 'idx_cheques_descartado_por'
);
SET @sql_idx_desc_por = IF(
    @idx_desc_por_exists = 0,
    'ALTER TABLE cheques ADD KEY idx_cheques_descartado_por (descartado_por)',
    'SELECT 1'
);
PREPARE stmt_idx_desc_por FROM @sql_idx_desc_por; EXECUTE stmt_idx_desc_por; DEALLOCATE PREPARE stmt_idx_desc_por;

-- 7. Clave foránea fk_cheques_descartado_usuario
SET @fk_desc_user_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cheques'
      AND CONSTRAINT_NAME = 'fk_cheques_descartado_usuario'
);
SET @sql_fk_desc_user = IF(
    @fk_desc_user_exists = 0,
    'ALTER TABLE cheques ADD CONSTRAINT fk_cheques_descartado_usuario FOREIGN KEY (descartado_por) REFERENCES usuarios(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt_fk_desc_user FROM @sql_fk_desc_user; EXECUTE stmt_fk_desc_user; DEALLOCATE PREPARE stmt_fk_desc_user;

-- 8. Verificación final de columnas añadidas
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cheques'
  AND COLUMN_NAME IN ('activo', 'descartado_at', 'descartado_por', 'motivo_descarte')
ORDER BY ORDINAL_POSITION;
