-- =============================================================================
-- MIGRACIÓN: Reutilización Controlada de Boletas Rechazadas y Descartadas
-- Archivo: config/migrations/2026_09_02_reutilizacion_documentos_rechazados.sql
-- Motor: MySQL 8.0+ / 8.4+ (Compatible con MySQL 8.4.3 InnoDB)
-- =============================================================================
--
-- PROPÓSITO:
-- Actualmente, la columna `document_hash` posee el índice único `uq_rendicion_document_hash`,
-- el cual bloquea permanentemente cualquier comprobante registrado en la historia,
-- incluso si la rendición fue rechazada por Tesorería o el ítem fue rechazado/descartado.
--
-- REGLA DE NEGOCIO DEFINITIVA:
-- - BORRADOR: Bloquea el mismo comprobante para que no aparezca dos veces en la bolsa.
-- - PENDIENTE: Bloquea el mismo comprobante porque ya pertenece a una rendición enviada.
-- - APROBADO: Bloquea permanentemente el mismo comprobante (incluyendo rendiciones PAGADAS).
-- - RECHAZADO: No bloquea. El vendedor puede volver a presentar la boleta en una nueva rendición.
-- - DESCARTADO: No bloquea. El vendedor puede volver a cargarla.
-- - La protección opera cross-vendedor y cross-empresa en todo el holding.
--
-- SOLUCIÓN:
-- Reemplazar el índice único estático por una columna generada STORED nullable
-- (`document_hash_bloqueante`) que solo conserve el hash cuando el documento se encuentre
-- en un estado bloqueante ('BORRADOR', 'PENDIENTE', 'APROBADO') y `activo = 1`.
-- En caso contrario retorna NULL. InnoDB admite múltiples valores NULL en índices UNIQUE,
-- pero impone unicidad estricta e inviolable a nivel de motor para valores no nulos.
-- =============================================================================

USE `bd_modulo_cobranzas`;

-- 1. Eliminar el índice anterior uq_rendicion_document_hash únicamente si existe
SET @old_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND INDEX_NAME = 'uq_rendicion_document_hash'
);
SET @sql_drop_old_idx = IF(
    @old_idx_exists > 0,
    'ALTER TABLE rendicion_documentos DROP INDEX uq_rendicion_document_hash',
    'SELECT 1'
);
PREPARE stmt_drop_old_idx FROM @sql_drop_old_idx; EXECUTE stmt_drop_old_idx; DEALLOCATE PREPARE stmt_drop_old_idx;

-- 2. Crear columna generada document_hash_bloqueante únicamente si no existe
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND COLUMN_NAME = 'document_hash_bloqueante'
);
SET @sql_add_col = IF(
    @col_exists = 0,
    'ALTER TABLE rendicion_documentos ADD COLUMN document_hash_bloqueante CHAR(64) GENERATED ALWAYS AS (CASE WHEN activo = 1 AND estado_item IN (''BORRADOR'', ''PENDIENTE'', ''APROBADO'') THEN document_hash ELSE NULL END) STORED AFTER document_hash',
    'SELECT 1'
);
PREPARE stmt_add_col FROM @sql_add_col; EXECUTE stmt_add_col; DEALLOCATE PREPARE stmt_add_col;

-- 3. Crear índice único uq_rendicion_document_hash_bloqueante únicamente si no existe
SET @new_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rendicion_documentos'
      AND INDEX_NAME = 'uq_rendicion_document_hash_bloqueante'
);
SET @sql_add_new_idx = IF(
    @new_idx_exists = 0,
    'ALTER TABLE rendicion_documentos ADD UNIQUE KEY uq_rendicion_document_hash_bloqueante (document_hash_bloqueante)',
    'SELECT 1'
);
PREPARE stmt_add_new_idx FROM @sql_add_new_idx; EXECUTE stmt_add_new_idx; DEALLOCATE PREPARE stmt_add_new_idx;

-- =============================================================================
-- CONSULTAS DE VERIFICACIÓN POSTERIOR (EJECUTAR PARA CONFIRMAR)
-- =============================================================================

-- 2. Confirmar que la columna generada existe y es STORED:
-- SELECT COLUMN_NAME, COLUMN_TYPE, GENERATION_EXPRESSION, EXTRA
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'rendicion_documentos'
--   AND COLUMN_NAME = 'document_hash_bloqueante';

-- 3. Confirmar que existe el nuevo índice único:
-- SHOW INDEX FROM rendicion_documentos WHERE Key_name = 'uq_rendicion_document_hash_bloqueante';

-- 4. Confirmar que desapareció el índice anterior:
-- SHOW INDEX FROM rendicion_documentos WHERE Key_name = 'uq_rendicion_document_hash';

-- =============================================================================
-- INSTRUCCIONES DE ROLLBACK DOCUMENTADAS (NO EJECUTAR SALVO CONTINGENCIA)
-- =============================================================================
-- En caso de requerir revertir este cambio a la unicidad permanente anterior:
--
-- ALTER TABLE rendicion_documentos
--     DROP INDEX uq_rendicion_document_hash_bloqueante,
--     DROP COLUMN document_hash_bloqueante,
--     ADD UNIQUE KEY uq_rendicion_document_hash (document_hash);
-- =============================================================================
