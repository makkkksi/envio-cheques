USE bd_modulo_cobranzas;

-- Alinea una BD local ya creada con el flujo dividido de cobranzas.
-- No toca las bases ERP. En el entorno actual no hay datos reales que preservar.

ALTER TABLE cobranzas
  MODIFY COLUMN tipo_entrega ENUM('CHILEXPRESS', 'PRESENCIAL_SANTIAGO') NULL,
  MODIFY COLUMN estado ENUM(
    'INGRESADO',
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NOT NULL DEFAULT 'PENDIENTE_ENVIO';

ALTER TABLE historial_estados
  MODIFY COLUMN estado_anterior ENUM(
    'INGRESADO',
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NULL,
  MODIFY COLUMN estado_nuevo ENUM(
    'INGRESADO',
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NOT NULL;

UPDATE cobranzas
SET estado = 'PENDIENTE_ENVIO'
WHERE estado = 'INGRESADO';

UPDATE historial_estados
SET estado_anterior = 'PENDIENTE_ENVIO'
WHERE estado_anterior = 'INGRESADO';

UPDATE historial_estados
SET estado_nuevo = 'PENDIENTE_ENVIO'
WHERE estado_nuevo = 'INGRESADO';

ALTER TABLE cobranzas
  MODIFY COLUMN estado ENUM(
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NOT NULL DEFAULT 'PENDIENTE_ENVIO';

ALTER TABLE historial_estados
  MODIFY COLUMN estado_anterior ENUM(
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NULL,
  MODIFY COLUMN estado_nuevo ENUM(
    'PENDIENTE_ENVIO',
    'EN_TRANSITO',
    'ENTREGADO_SANTIAGO',
    'RECIBIDO_TESORERIA',
    'DEPOSITADO',
    'RECHAZADO'
  ) NOT NULL;

SET @idx_cobranzas_estado = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cobranzas'
    AND INDEX_NAME = 'idx_cobranzas_estado'
);
SET @sql_idx_cobranzas_estado = IF(
  @idx_cobranzas_estado = 0,
  'CREATE INDEX idx_cobranzas_estado ON cobranzas(estado)',
  'DO 0'
);
PREPARE stmt_idx_cobranzas_estado FROM @sql_idx_cobranzas_estado;
EXECUTE stmt_idx_cobranzas_estado;
DEALLOCATE PREPARE stmt_idx_cobranzas_estado;

SET @idx_cobranzas_created = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cobranzas'
    AND INDEX_NAME = 'idx_cobranzas_created'
);
SET @sql_idx_cobranzas_created = IF(
  @idx_cobranzas_created = 0,
  'CREATE INDEX idx_cobranzas_created ON cobranzas(created_at, estado)',
  'DO 0'
);
PREPARE stmt_idx_cobranzas_created FROM @sql_idx_cobranzas_created;
EXECUTE stmt_idx_cobranzas_created;
DEALLOCATE PREPARE stmt_idx_cobranzas_created;
