-- ============================================================
-- AUTOTEC E-COMMERCE - Setup Base de Datos
-- Base de datos principal: autotec_ecom
-- Las tablas de productos/clientes/etc vienen de autotec_ecom
-- (mismo servidor, acceso directo sin FEDERATED ni link)
-- ============================================================

-- Usar la base de datos del e-commerce
-- Asumimos que "autotec_ecom" es la BD que ya tiene los datos
-- (tbl_clientes, tbl_productos, tbl_modelos_marcas, etc.)
-- Solo agregamos las tablas nuevas para el sitio web.

USE autotec_ecom;

-- ============================================================
-- TABLA: Usuarios web (login del sitio)
-- ============================================================
CREATE TABLE IF NOT EXISTS web_usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,          -- bcrypt hash
    nombre      VARCHAR(200),
    email       VARCHAR(200),
    rol         ENUM('admin','vendedor','cliente') DEFAULT 'cliente',
    activo      TINYINT(1) DEFAULT 1,
    cli_rut     VARCHAR(20),                    -- vinculo opcional con tbl_clientes
    cli_sec     VARCHAR(10),
    creado_en   DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimo_login DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario administrador por defecto (password: autotec2024)
INSERT IGNORE INTO web_usuarios (usuario, password, nombre, rol)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uodXSlUi6', 'Administrador', 'admin');

-- ============================================================
-- TABLA: Sesiones web
-- ============================================================
CREATE TABLE IF NOT EXISTS web_sesiones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    token       VARCHAR(128) NOT NULL UNIQUE,
    ip          VARCHAR(45),
    user_agent  VARCHAR(500),
    expira_en   DATETIME NOT NULL,
    creado_en   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES web_usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLA: Carro de compras (cabecera)
-- Equivale a tbl_cabecera de la app Android pero para web
-- ============================================================
CREATE TABLE IF NOT EXISTS web_carro_cabecera (
    pedi_id             INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id          INT NOT NULL,
    cli_rut             VARCHAR(20),
    cli_sec             VARCHAR(10),
    emp_id              VARCHAR(10) DEFAULT '1',
    cond_id             VARCHAR(20),
    pedi_porc_iva       DECIMAL(5,2) DEFAULT 19.00,
    pedi_total_neto     DECIMAL(12,0) DEFAULT 0,
    pedi_total_iva      DECIMAL(12,0) DEFAULT 0,
    pedi_total          DECIMAL(12,0) DEFAULT 0,
    pedi_fecha          DATETIME DEFAULT CURRENT_TIMESTAMP,
    pedi_estado         ENUM('borrador','enviado','procesado','anulado') DEFAULT 'borrador',
    tran_id             VARCHAR(10),
    pedi_observaciones  TEXT,
    pedi_orden_compra   VARCHAR(100),
    pedi_fecha_oc       DATE,
    pedi_forma_pago     VARCHAR(50),
    pedi_vendedor       VARCHAR(20),
    FOREIGN KEY (usuario_id) REFERENCES web_usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLA: Carro de compras (detalle)
-- Equivale a tbl_detalle de la app Android
-- ============================================================
CREATE TABLE IF NOT EXISTS web_carro_detalle (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    pedi_id             INT NOT NULL,
    prod_id             VARCHAR(50) NOT NULL,
    ped_prod_cantidad   INT DEFAULT 1,
    ped_prod_neto       DECIMAL(12,0) DEFAULT 0,
    ped_prod_dscto      DECIMAL(5,2) DEFAULT 0,
    ped_prod_flagP      TINYINT(1) DEFAULT 0,
    agregado_en         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedi_id) REFERENCES web_carro_cabecera(pedi_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLA: Log de pedidos enviados (historial)
-- ============================================================
CREATE TABLE IF NOT EXISTS web_pedidos_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pedi_id     INT NOT NULL,
    usuario_id  INT NOT NULL,
    accion      VARCHAR(100),
    detalle     TEXT,
    fecha       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ÍNDICES para mejorar rendimiento en búsquedas
-- (Las tablas de productos ya existen en autotec_ecom)
-- ============================================================
-- Si las tablas de productos no tienen índices, agregarlos:
CREATE INDEX IF NOT EXISTS idx_prod_nombre ON tbl_productos(prod_nombre);
CREATE INDEX IF NOT EXISTS idx_prod_cla ON tbl_productos(cla_id);
CREATE INDEX IF NOT EXISTS idx_prod_marca ON tbl_productos(marca_id);
CREATE INDEX IF NOT EXISTS idx_prod_mod_prod ON tbl_productos_modelos(prod_id);
CREATE INDEX IF NOT EXISTS idx_prod_mod_mod ON tbl_productos_modelos(mod_id);
CREATE INDEX IF NOT EXISTS idx_mod_marcas_marca ON tbl_modelos_marcas(marca_id);

-- ============================================================
-- VERIFICACIÓN final
-- ============================================================
SELECT 'Setup completado correctamente' AS status;
SHOW TABLES LIKE 'web_%';
