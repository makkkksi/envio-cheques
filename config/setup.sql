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
  empresa_id INT NOT NULL,
  vendedor_id INT NULL,
  vendedor_nombre VARCHAR(100) NULL,
  numero_factura VARCHAR(50) NOT NULL,
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
(1, 'Sistema', 'sistema@app.local', '$2y$10$tZ2cQvYl6L.CstnIexkFve99i5/p2vD/w7/Z6V8Bq8g9qKj6zFjKu', 'ADMINISTRADOR', 1),
(2, 'Vendedor de Prueba', 'vendedor@app.local', '$2y$10$wH2vD5M9c/pX76d1vXG3yeJc7F/4t8M9pZ2r9k6zFjKuwH2vD5M9c', 'VENDEDOR', 1),
(3, 'Tesorero de Prueba', 'tesoreria@app.local', '$2y$10$K9pZ2r9k6zFjKuwH2vD5M9c/pX76d1vXG3yeJc7F/4t8M9pZ2r9k', 'TESORERIA', 1)
ON DUPLICATE KEY UPDATE 
  nombre=VALUES(nombre),
  email=VALUES(email),
  password_hash=VALUES(password_hash),
  rol=VALUES(rol),
  activo=VALUES(activo);
