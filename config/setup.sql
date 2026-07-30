CREATE DATABASE IF NOT EXISTS bd_modulo_cobranzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bd_modulo_cobranzas;

-- 1. Mapeo de Bases de Datos de las Empresas del Holding
CREATE TABLE IF NOT EXISTS empresas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  nombre_bd VARCHAR(100) NOT NULL UNIQUE,
  email_tesoreria_defecto VARCHAR(150) NOT NULL,
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
  rol ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR') DEFAULT 'TESORERIA',
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
  banco VARCHAR(100) NOT NULL,
  numero_cheque VARCHAR(50) NOT NULL,
  monto DECIMAL(12,0) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  foto_cheque_url VARCHAR(255) NOT NULL,
  comentario TEXT NULL,
  numero_papeleta_deposito VARCHAR(50) NULL,
  fecha_deposito_real TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cheques_cobranza (cobranza_id),
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE
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
(3, 'Tesorero Automarco', 'tesoreria@automarco.cl', '$2y$12$qsi4wIpGJ36qocYfN7Muvujd/GpKw36PGdkyqSmrGjW.K0MktMqzC', 'TESORERIA', 1)
ON DUPLICATE KEY UPDATE 
  nombre=VALUES(nombre),
  email=VALUES(email),
  password_hash=VALUES(password_hash),
  rol=VALUES(rol),
  activo=VALUES(activo);
