-- ============================================================================
-- MIGRACION PRODUCTIVA: MODULO 3 - RENDICIONES DE GASTOS Y VIATICOS
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
  periodo_clave VARCHAR(190) NOT NULL COMMENT 'Clave canonica para impedir presupuestos duplicados',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_presupuesto_periodo_clave (periodo_clave),
  KEY idx_presupuesto_vendedor (empresa_id, vendedor_id, tipo_presupuesto, activo),
  KEY idx_presupuesto_periodo (periodo_mes, activo),
  KEY idx_presupuesto_creado_por (creado_por),
  CONSTRAINT fk_presupuesto_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_presupuesto_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
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
  monto_presupuesto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  saldo_disponible_al_enviar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_exceso DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  requiere_aprobacion_exceso TINYINT(1) NOT NULL DEFAULT 0,
  token_aprobacion_exceso_hash CHAR(64) NULL,
  token_exceso_expira DATETIME NULL,
  token_exceso_usado_at DATETIME NULL,
  decision_exceso ENUM('APROBADO', 'RECHAZADO') NULL,
  aprobado_exceso_at DATETIME NULL,
  aprobado_exceso_por VARCHAR(150) NULL,
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
  CONSTRAINT fk_rendicion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_recepcion_usuario FOREIGN KEY (recibido_fisico_por) REFERENCES usuarios(id) ON DELETE RESTRICT
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

-- Verificacion posterior para phpMyAdmin:
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME IN (
    'presupuestos_vendedores',
    'rendiciones_gastos',
    'rendicion_documentos',
    'rendicion_historial_estados'
  )
ORDER BY TABLE_NAME;
