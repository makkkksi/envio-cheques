CREATE DATABASE IF NOT EXISTS bd_modulo_cobranzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bd_modulo_cobranzas;

-- 1. Mapeo de Bases de Datos de las Empresas del Holding
CREATE TABLE IF NOT EXISTS empresas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  nombre_bd VARCHAR(100) NOT NULL UNIQUE,
  email_tesoreria_defecto VARCHAR(150) NOT NULL,
  google_sheet_id VARCHAR(255) NULL,
  dias_maximos_envio INT DEFAULT 3,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Usuarios del Sistema
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  api_token VARCHAR(64) NULL,
  token_expires_at TIMESTAMP NULL,
  rol ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR', 'SUPERVISORA_CC') DEFAULT 'TESORERIA',
  dias_alerta_personalizado INT NULL,
  activo BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Cabecera del Registro de Cobranza
CREATE TABLE IF NOT EXISTS cobranzas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NULL,
  vendedor_id INT NULL,
  vendedor_nombre VARCHAR(100) NULL,
  numero_factura VARCHAR(50) NULL,
  rut_cliente VARCHAR(20) NOT NULL,
  razon_social_cliente VARCHAR(200) NULL,
  monto_total_factura DECIMAL(12,0) NULL,
  email_cliente VARCHAR(150) NULL,
  email_tesoreria VARCHAR(150) NULL,
  tipo_entrega ENUM('CHILEXPRESS', 'PRESENCIAL_SANTIAGO') NULL,
  numero_seguimiento VARCHAR(100) NULL,
  comprobante_url VARCHAR(255) NULL,
  comprobante_purgado_at TIMESTAMP NULL,
  justificacion_descuadre TEXT NULL,
  estado ENUM('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') NOT NULL DEFAULT 'PENDIENTE_ENVIO',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cobranzas_estado (estado),
  KEY idx_cobranzas_created (created_at, estado),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.1 Detalle de Facturas Canceladas (Soporta múltiples facturas cross-empresa por cobranza)
CREATE TABLE IF NOT EXISTS cobranza_facturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id INT NOT NULL,
  empresa_id INT NOT NULL,
  codigo_empresa VARCHAR(20) NOT NULL, -- EMP01, EMP03, EMP06, EMP10
  numero_factura VARCHAR(50) NOT NULL,
  cuota_label VARCHAR(20) NULL,
  total_cuota DECIMAL(12,0) NOT NULL,
  saldo_cuota DECIMAL(12,0) NOT NULL,
  monto_cubierto DECIMAL(12,0) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cobranza_facturas_cobranza (cobranza_id),
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Detalle de Cheques (Soporta múltiples cheques por cobranza)
CREATE TABLE IF NOT EXISTS cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id INT NOT NULL,
  banco VARCHAR(100) NULL,
  numero_cheque VARCHAR(50) NULL,
  cuenta_corriente VARCHAR(50) NULL,
  monto DECIMAL(12,0) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  emitido_a VARCHAR(200) NULL,
  foto_cheque_url VARCHAR(255) NULL,
  foto_purgada_at TIMESTAMP NULL,
  comentario TEXT NULL,
  numero_papeleta_deposito VARCHAR(50) NULL,
  fecha_deposito_real TIMESTAMP NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Activo, 0 = Dado de baja / descartado',
  descartado_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Fecha y hora en que fue dado de baja',
  descartado_por INT NULL DEFAULT NULL COMMENT 'ID del usuario que descarto el cheque',
  motivo_descarte VARCHAR(255) NULL DEFAULT NULL COMMENT 'Motivo o contexto del descarte',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cheques_cobranza (cobranza_id),
  KEY idx_cheques_vencimiento_purga (fecha_vencimiento, foto_purgada_at),
  KEY idx_cheques_activo (activo),
  KEY idx_cheques_descartado_por (descartado_por),
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE,
  CONSTRAINT fk_cheques_descartado_usuario FOREIGN KEY (descartado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Bitácora e Historial de Auditoría (Trazabilidad Inmutable)
CREATE TABLE IF NOT EXISTS historial_estados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id INT NOT NULL,
  usuario_id INT NOT NULL,
  estado_anterior ENUM('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') NULL,
  estado_nuevo ENUM('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') NOT NULL,
  comentario TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_historial_cobranza (cobranza_id),
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Intentos de Login (Rate-Limiting y Control de Fuerza Bruta)
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  email VARCHAR(150) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_attempts_ip_email (ip_address, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Registro de Auditoría General
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  email VARCHAR(150) NOT NULL,
  accion VARCHAR(100) NOT NULL,
  detalles TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_logs_user (usuario_id),
  KEY idx_audit_logs_action (accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Bitácora de Envíos de Informes (Garantía de No Pérdida de Cheques)
CREATE TABLE IF NOT EXISTS log_envios_informes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NULL,
  tipo_informe ENUM('INDIVIDUAL_TESORERIA', 'RESUMEN_DIARIO_16HRS', 'ALERTA_DEMORA') NOT NULL,
  destinatario VARCHAR(150) NOT NULL,
  copia_cc VARCHAR(150) NULL,
  asunto VARCHAR(255) NOT NULL,
  estado_envio ENUM('ENVIADO', 'FALLIDO') NOT NULL DEFAULT 'ENVIADO',
  error_mensaje TEXT NULL,
  cantidad_cobranzas INT DEFAULT 1,
  payload_json LONGTEXT NULL,
  fecha_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_envios_empresa (empresa_id),
  KEY idx_log_envios_estado (estado_envio),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Configuraciones Globales del Sistema
CREATE TABLE IF NOT EXISTS configuraciones_sistema (
  clave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL,
  descripcion VARCHAR(255) NULL,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Presupuestos de Vendedores para Rendiciones (Mensuales y Giras)
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

-- 11. Responsables configurables para aprobar excesos
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

-- 12. Cabeceras de Rendiciones de Gastos y Viaticos
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
  estado ENUM('BORRADOR', 'ENVIADA', 'PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA', 'PENDIENTE_APROBACION_RESPONSABLE', 'DOCUMENTOS_FISICOS_RECIBIDOS', 'APROBADA', 'APROBADA_PARCIAL', 'RECHAZADA', 'PAGADA') NOT NULL DEFAULT 'ENVIADA',
  verificado_tesoreria_at DATETIME NULL,
  verificado_tesoreria_por INT NULL,
  pdf_planilla_url VARCHAR(255) NULL,
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
  KEY idx_rendicion_verificado_por (verificado_tesoreria_por),
  CONSTRAINT fk_rendicion_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_recepcion_usuario FOREIGN KEY (recibido_fisico_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_aprobador FOREIGN KEY (aprobador_solicitado_id) REFERENCES aprobadores_rendiciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_solicitud_usuario FOREIGN KEY (solicitud_exceso_enviada_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rendicion_verificado_usuario FOREIGN KEY (verificado_tesoreria_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Bolsa y Detalle de Documentos de Rendicion
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
  numero_documento_original VARCHAR(50) NULL COMMENT 'Número digitado originalmente por el vendedor antes de corrección',
  fecha_emision DATE NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  monto_original DECIMAL(12,2) NULL,
  monto_validado DECIMAL(12,2) NULL,
  descripcion VARCHAR(500) NULL,
  foto_documento_url VARCHAR(255) NOT NULL,
  document_hash CHAR(64) NOT NULL,
  document_hash_bloqueante CHAR(64) GENERATED ALWAYS AS (
    CASE
      WHEN activo = 1 AND estado_item IN ('BORRADOR', 'PENDIENTE', 'APROBADO')
      THEN document_hash
      ELSE NULL
    END
  ) STORED,
  cliente_invitado_nombre VARCHAR(150) NULL,
  cliente_invitado_rut VARCHAR(20) NULL,
  cliente_invitado_empresa VARCHAR(150) NULL,
  cliente_invitado_cargo VARCHAR(100) NULL,
  proposito_comercial TEXT NULL,
  estado_item ENUM('BORRADOR', 'PENDIENTE', 'APROBADO', 'RECHAZADO', 'DESCARTADO') NOT NULL DEFAULT 'BORRADOR',
  motivo_rechazo VARCHAR(500) NULL,
  editado_por INT NULL,
  editado_at DATETIME NULL,
  motivo_edicion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  descartado_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rendicion_document_hash_bloqueante (document_hash_bloqueante),
  KEY idx_documento_bolsa (empresa_id, vendedor_id, estado_item, activo),
  KEY idx_documento_rendicion (rendicion_id, estado_item),
  KEY idx_documento_fecha (fecha_emision),
  KEY idx_documento_editado_por (editado_por),
  CONSTRAINT fk_documento_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_documento_rendicion FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_documento_editado_usuario FOREIGN KEY (editado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Historial Inmutable de Rendiciones y Documentos
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

-- 15. Solicitudes versionadas de aprobación (giras y excepciones mensuales)
CREATE TABLE IF NOT EXISTS solicitudes_aprobacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_solicitud ENUM('GIRA', 'EXCEPCION_MENSUAL', 'APROBACION_RENDICION') NOT NULL,
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
  decision ENUM('APROBADA', 'RECHAZADA', 'APROBADA_TOPE') NULL,
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
  CONSTRAINT chk_solicitud_objetivo CHECK ((tipo_solicitud = 'GIRA' AND presupuesto_id IS NOT NULL AND rendicion_id IS NULL) OR (tipo_solicitud IN ('EXCEPCION_MENSUAL', 'APROBACION_RENDICION') AND presupuesto_id IS NULL AND rendicion_id IS NOT NULL)),
  CONSTRAINT chk_solicitud_monto CHECK (monto_solicitado > 0 AND monto_base_aprobable >= 0),
  CONSTRAINT fk_solicitud_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_rendicion FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_aprobador FOREIGN KEY (aprobador_id) REFERENCES aprobadores_rendiciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_solicitante FOREIGN KEY (solicitado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_cancelador FOREIGN KEY (cancelado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Auditoría inmutable del ciclo de cada solicitud
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

-- Datos Semilla (Seeders)
INSERT INTO empresas (id, nombre, nombre_bd, email_tesoreria_defecto) VALUES
(1, 'Automarco LTDA', 'automarc_automarco', 'tesoreria@automarco.cl'),
(2, 'HD Automarco S.A', 'autohd_automarcohd', 'tesoreria@hdautomarco.cl'),
(3, 'Autotec S.A', 'autotec_ecom', 'tesoreria@autotec.cl'),
(4, 'Gabtec S.A', 'gabteccl_sitbdd1978', 'tesoreria@gabtec.cl')
ON DUPLICATE KEY UPDATE 
  nombre=VALUES(nombre),
  nombre_bd=VALUES(nombre_bd),
  email_tesoreria_defecto=VALUES(email_tesoreria_defecto);

-- Usuario Semilla Obligatorio y Test (Claves: sistema123, vendedor123, tesoreria123)
INSERT INTO usuarios (id, nombre, email, password_hash, rol, activo) VALUES
(1, 'Sistema', 'sistema@app.local', '$2y$12$PX7jyHQUa8WEcCP08gCZbeRGv2CH7chHo3zNJGLIFGpIa3eOKdK5q', 'ADMINISTRADOR', 1),
(2, 'Vendedor de Prueba', 'vendedor@app.local', '$2y$12$KICHjGYdMzIcxiPU0yik7eeJ4K45m.z/DknlSRXQ3jjVx4GjBJBY', 'VENDEDOR', 1),
(3, 'Tesorero Automarco', 'tesoreria@automarco.cl', '$2y$12$qsi4wIpGJ36qocYfN7Muvujd/GpKw36PGdkyqSmrGjW.K0MktMqzC', 'TESORERIA', 1),
(4, 'Supervisora Cuentas Corrientes', 'cuentascorrientes@automarco.cl', '$2y$12$qsi4wIpGJ36qocYfN7Muvujd/GpKw36PGdkyqSmrGjW.K0MktMqzC', 'SUPERVISORA_CC', 1)
ON DUPLICATE KEY UPDATE 
  nombre=VALUES(nombre),
  email=VALUES(email),
  password_hash=VALUES(password_hash),
  rol=VALUES(rol),
  activo=VALUES(activo);

-- Migraciones seguras para bases de datos existentes (Soporte Purga Fotos Cheques Vencidos)
-- 1. Permitir foto_cheque_url NULL en cheques
ALTER TABLE cheques MODIFY foto_cheque_url VARCHAR(255) NULL;
-- 2. Agregar columnas de fecha de purga si no existen
SET @col_exists_chq = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques' AND COLUMN_NAME = 'foto_purgada_at');
SET @sql_chq = IF(@col_exists_chq = 0, 'ALTER TABLE cheques ADD COLUMN foto_purgada_at TIMESTAMP NULL AFTER foto_cheque_url', 'SELECT 1');
PREPARE stmt_chq FROM @sql_chq;
EXECUTE stmt_chq;
DEALLOCATE PREPARE stmt_chq;

SET @col_exists_cob = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobranzas' AND COLUMN_NAME = 'comprobante_purgado_at');
SET @sql_cob = IF(@col_exists_cob = 0, 'ALTER TABLE cobranzas ADD COLUMN comprobante_purgado_at TIMESTAMP NULL AFTER comprobante_url', 'SELECT 1');
PREPARE stmt_cob FROM @sql_cob;
EXECUTE stmt_cob;
DEALLOCATE PREPARE stmt_cob;

-- 3. Nota general enviada por el vendedor en rendiciones (migración aditiva)
SET @col_exists_rendicion_nota = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME = 'nota_vendedor');
SET @sql_rendicion_nota = IF(@col_exists_rendicion_nota = 0, 'ALTER TABLE rendiciones_gastos ADD COLUMN nota_vendedor TEXT NULL COMMENT ''Observacion general enviada por el vendedor a Tesoreria'' AFTER vendedor_email', 'SELECT 1');
PREPARE stmt_rendicion_nota FROM @sql_rendicion_nota;
EXECUTE stmt_rendicion_nota;
DEALLOCATE PREPARE stmt_rendicion_nota;
