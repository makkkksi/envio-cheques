-- ============================================================================
-- ESQUEMA PRODUCTIVO: MODULO 3 - RENDICIONES DE GASTOS Y VIATICOS
-- Fecha: 2026-08-21
-- Base objetivo: bd_modulo_cobranzas
-- Ejecucion: importar este archivo completo desde phpMyAdmin.
-- Seguridad: migracion aditiva e idempotente; no contiene DROP, TRUNCATE ni DELETE.
-- ============================================================================

USE bd_modulo_cobranzas;

CREATE TABLE IF NOT EXISTS presupuestos_vendedores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  vendedor_id BIGINT NOT NULL COMMENT 'Codigo vend_cod/cli_vendedor del ERP de la empresa',
  vendedor_nombre VARCHAR(150) NULL,
  vendedor_email VARCHAR(150) NULL,
  tipo_presupuesto ENUM('MENSUAL', 'GIRA') NOT NULL DEFAULT 'MENSUAL',
  nombre_gira VARCHAR(100) NULL,
  periodo_mes CHAR(7) NOT NULL COMMENT 'Formato YYYY-MM',
  fecha_inicio DATE NULL,
  fecha_fin DATE NULL,
  monto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_utilizado DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto comprometido por rendiciones activas',
  estado_aprobacion ENUM('NO_APLICA', 'PENDIENTE', 'APROBADA', 'RECHAZADA') NOT NULL DEFAULT 'NO_APLICA',
  justificacion_gira VARCHAR(500) NULL,
  solicitud_aprobacion_id INT NULL,
  aprobado_at DATETIME NULL,
  periodo_clave VARCHAR(190) NOT NULL COMMENT 'Clave canonica para impedir presupuestos duplicados',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_presupuesto_periodo_clave (periodo_clave),
  KEY idx_presupuesto_vendedor (empresa_id, vendedor_id, tipo_presupuesto, activo),
  KEY idx_presupuesto_periodo (periodo_mes, activo),
  KEY idx_presupuesto_estado_aprobacion (tipo_presupuesto, estado_aprobacion, activo),
  KEY idx_presupuesto_solicitud (solicitud_aprobacion_id),
  KEY idx_presupuesto_creado_por (creado_por),
  CONSTRAINT fk_presupuesto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_presupuesto_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS rendiciones_gastos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_rendicion VARCHAR(24) NOT NULL,
  empresa_id INT NOT NULL,
  vendedor_id BIGINT NOT NULL COMMENT 'Codigo vend_cod/cli_vendedor del ERP de la empresa',
  vendedor_nombre VARCHAR(150) NULL,
  vendedor_email VARCHAR(150) NULL,
  nota_vendedor TEXT NULL COMMENT 'Observacion general enviada por el vendedor a Tesoreria',
  presupuesto_id INT NOT NULL,
  periodo_mes CHAR(7) NOT NULL,
  tipo_rendicion ENUM('MENSUAL', 'GIRA') NOT NULL DEFAULT 'MENSUAL',
  monto_total_rendido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_total_aprobado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_maximo_aprobable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_presupuesto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  saldo_disponible_al_enviar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_exceso DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_exceso_no_reembolsable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  aplico_tope_presupuestario TINYINT(1) NOT NULL DEFAULT 0,
  solicitud_excepcion_id INT NULL,
  requiere_aprobacion_exceso TINYINT(1) NOT NULL DEFAULT 0,
  token_aprobacion_exceso_hash CHAR(64) NULL,
  token_exceso_expira DATETIME NULL,
  token_exceso_usado_at DATETIME NULL,
  decision_exceso ENUM('APROBADO', 'RECHAZADO') NULL,
  aprobado_exceso_at DATETIME NULL,
  aprobado_exceso_por VARCHAR(150) NULL,
  aprobador_solicitado_id INT NULL,
  aprobador_nombre_snapshot VARCHAR(150) NULL,
  aprobador_cargo_snapshot VARCHAR(120) NULL,
  aprobador_email_snapshot VARCHAR(190) NULL,
  solicitud_exceso_enviada_at DATETIME NULL,
  solicitud_exceso_enviada_por INT NULL,
  notificacion_exceso_estado ENUM('NO_APLICA', 'PENDIENTE', 'ENVIADA', 'FALLIDA') NOT NULL DEFAULT 'NO_APLICA',
  estado ENUM(
    'BORRADOR',
    'ENVIADA',
    'PENDIENTE_APROBACION_EXCESO',
    'EN_REVISION_TESORERIA',
    'DOCUMENTOS_FISICOS_RECIBIDOS',
    'APROBADA',
    'APROBADA_PARCIAL',
    'RECHAZADA',
    'PAGADA'
  ) NOT NULL DEFAULT 'ENVIADA',
  documentos_fisicos_recibidos TINYINT(1) NOT NULL DEFAULT 0,
  fecha_recepcion_fisica DATETIME NULL,
  recibido_fisico_por INT NULL,
  motivo_rechazo VARCHAR(500) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  enviada_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rendicion_codigo (codigo_rendicion),
  UNIQUE KEY uq_rendicion_token_hash (token_aprobacion_exceso_hash),
  KEY idx_rendicion_bandeja (estado, periodo_mes, empresa_id),
  KEY idx_rendicion_vendedor (empresa_id, vendedor_id, created_at),
  KEY idx_rendicion_presupuesto (presupuesto_id),
  KEY idx_rendicion_recepcion_usuario (recibido_fisico_por),
  KEY idx_rendicion_aprobador (aprobador_solicitado_id),
  KEY idx_rendicion_solicitud_usuario (solicitud_exceso_enviada_por),
  KEY idx_rendicion_solicitud_excepcion (solicitud_excepcion_id),
  CONSTRAINT fk_rendicion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_recepcion_usuario FOREIGN KEY (recibido_fisico_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_aprobador FOREIGN KEY (aprobador_solicitado_id) REFERENCES aprobadores_rendiciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_solicitud_usuario FOREIGN KEY (solicitud_exceso_enviada_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migración incremental para instalaciones que ya tenían las cuatro tablas.
SET @col_exists_rendicion_nota = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'nota_vendedor');
SET @sql_rendicion_nota = IF(@col_exists_rendicion_nota = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN nota_vendedor TEXT NULL COMMENT ''Observacion general enviada por el vendedor a Tesoreria'' AFTER vendedor_email', 'SELECT 1');
PREPARE stmt_rendicion_nota FROM @sql_rendicion_nota;
EXECUTE stmt_rendicion_nota;
DEALLOCATE PREPARE stmt_rendicion_nota;

CREATE TABLE IF NOT EXISTS rendicion_documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  vendedor_id BIGINT NOT NULL COMMENT 'Codigo vend_cod/cli_vendedor del ERP de la empresa',
  vendedor_nombre VARCHAR(150) NULL,
  vendedor_email VARCHAR(150) NULL,
  rendicion_id INT NULL COMMENT 'NULL mientras el documento permanece en la bolsa de borradores',
  tipo_documento ENUM('BOLETA_ELECTRONICA', 'FACTURA_ELECTRONICA', 'PEAJE', 'PASAJES', 'OTRO') NOT NULL,
  categoria_gasto ENUM('BENCINA', 'COLACION', 'HOSPEDAJE', 'PEAJES', 'ESTACIONAMIENTO', 'CENA_CLIENTE', 'OTROS') NOT NULL DEFAULT 'OTROS',
  rut_proveedor VARCHAR(20) NULL,
  razon_social_proveedor VARCHAR(150) NULL,
  numero_documento VARCHAR(50) NULL,
  fecha_emision DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  monto_validado DECIMAL(12,2) NULL,
  descripcion VARCHAR(500) NULL,
  foto_documento_url VARCHAR(255) NOT NULL,
  document_hash CHAR(64) NOT NULL,
  cliente_invitado_nombre VARCHAR(150) NULL,
  cliente_invitado_rut VARCHAR(20) NULL,
  cliente_invitado_empresa VARCHAR(150) NULL,
  cliente_invitado_cargo VARCHAR(100) NULL,
  proposito_comercial TEXT NULL,
  estado_item ENUM('BORRADOR', 'PENDIENTE', 'APROBADO', 'RECHAZADO', 'DESCARTADO') NOT NULL DEFAULT 'BORRADOR',
  motivo_rechazo VARCHAR(500) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  descartado_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rendicion_document_hash (document_hash),
  KEY idx_documento_bolsa (empresa_id, vendedor_id, estado_item, activo),
  KEY idx_documento_rendicion (rendicion_id, estado_item),
  KEY idx_documento_fecha (fecha_emision),
  CONSTRAINT fk_documento_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_documento_rendicion FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rendicion_historial_estados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rendicion_id INT NOT NULL,
  documento_id INT NULL,
  usuario_id INT NULL,
  actor_tipo ENUM('VENDEDOR', 'TESORERIA', 'JEFATURA', 'SISTEMA') NOT NULL,
  actor_nombre VARCHAR(150) NOT NULL,
  actor_email VARCHAR(150) NULL,
  accion VARCHAR(80) NOT NULL,
  estado_anterior VARCHAR(50) NULL,
  estado_nuevo VARCHAR(50) NOT NULL,
  comentario TEXT NULL,
  metadata_json LONGTEXT NULL,
  ip_origen VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rendicion_historial (rendicion_id, created_at),
  KEY idx_documento_historial (documento_id, created_at),
  KEY idx_usuario_historial (usuario_id),
  CONSTRAINT fk_historial_rendicion FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_historial_documento FOREIGN KEY (documento_id) REFERENCES rendicion_documentos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_historial_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migración incremental de topes para instalaciones que ya tenían el módulo.
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

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND INDEX_NAME = 'idx_presupuesto_estado_aprobacion') = 0, 'ALTER TABLE presupuestos_vendedores ADD KEY idx_presupuesto_estado_aprobacion (tipo_presupuesto, estado_aprobacion, activo)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND INDEX_NAME = 'idx_presupuesto_solicitud') = 0, 'ALTER TABLE presupuestos_vendedores ADD KEY idx_presupuesto_solicitud (solicitud_aprobacion_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND INDEX_NAME = 'idx_rendicion_solicitud_excepcion') = 0, 'ALTER TABLE rendiciones_gastos ADD KEY idx_rendicion_solicitud_excepcion (solicitud_excepcion_id)', 'SELECT 1');
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
  CONSTRAINT chk_solicitud_objetivo CHECK ((tipo_solicitud = 'GIRA' AND presupuesto_id IS NOT NULL AND rendicion_id IS NULL) OR (tipo_solicitud = 'EXCEPCION_MENSUAL' AND presupuesto_id IS NULL AND rendicion_id IS NOT NULL)),
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

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'presupuestos_vendedores' AND CONSTRAINT_NAME = 'fk_presupuesto_solicitud_aprobacion') = 0, 'ALTER TABLE presupuestos_vendedores ADD CONSTRAINT fk_presupuesto_solicitud_aprobacion FOREIGN KEY (solicitud_aprobacion_id) REFERENCES solicitudes_aprobacion(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND CONSTRAINT_NAME = 'fk_rendicion_solicitud_excepcion') = 0, 'ALTER TABLE rendiciones_gastos ADD CONSTRAINT fk_rendicion_solicitud_excepcion FOREIGN KEY (solicitud_excepcion_id) REFERENCES solicitudes_aprobacion(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verificacion posterior para phpMyAdmin:
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME IN (
    'presupuestos_vendedores',
    'aprobadores_rendiciones',
    'rendiciones_gastos',
    'rendicion_documentos',
    'rendicion_historial_estados',
    'solicitudes_aprobacion',
    'solicitud_aprobacion_historial'
  )
ORDER BY TABLE_NAME;
